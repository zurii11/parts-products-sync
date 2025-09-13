<?php
require_once __DIR__ . '/../../../wp-load.php';
require_once 'batch-fetcher.php';
require_once 'sync-normalizer.php';

define('BATCH_SIZE', 5);
define('PACK_SIZE', 500);

// Configure your special sale category here:
define('SALE_CATEGORY_SLUG', 'on-sale');
define('SALE_CATEGORY_NAME', 'On Sale');

$logger = new ZLogger("logs.log", true);
$logger->zlog("Starting sync");

// Helper: ensure the special sale category exists and return its term_id
function ensure_sale_category_term_id(): int {
	$term = get_term_by('slug', SALE_CATEGORY_SLUG, 'product_cat');
	if ($term && !is_wp_error($term)) {
		return (int)$term->term_id;
	}
	$res = wp_insert_term(SALE_CATEGORY_NAME, 'product_cat', ['slug' => SALE_CATEGORY_SLUG]);
	if (is_wp_error($res)) {
		throw new \RuntimeException('Failed to create sale category: ' . $res->get_error_message());
	}
	return (int)$res['term_id'];
}

// Helper: toggle sale category on product based on $hasSale
function apply_sale_category(\WC_Product $product, bool $hasSale): void {
	$saleTermId = ensure_sale_category_term_id();
	$current = $product->get_category_ids();
	$current = is_array($current) ? $current : [];

	if ($hasSale) {
		if (!in_array($saleTermId, $current, true)) {
			$current[] = $saleTermId;
		}
	} else {
		$current = array_values(array_filter($current, fn($id) => (int)$id !== $saleTermId));
	}

	$product->set_category_ids($current);
}

// Fetch Items
$itemsFetcher = new ItemsFetcher(PACK_SIZE, $logger);
$itemsRunner = new BatchRunner($itemsFetcher, BATCH_SIZE, $logger);
$items = $itemsRunner->run();
$logger->zlog("Items fetched: " . count($items));

// Fetch ItemPricing documents
$itemPricingFetcher = new ItemPricingFetcher(100, $logger);
$itemPricingRunner = new BatchRunner($itemPricingFetcher, BATCH_SIZE, $logger);
$itemPricingDocuments = $itemPricingRunner->run();
$logger->zlog("ItemPricing fetched: " . count($itemPricingDocuments));

// 3) Normalize source (API) products into map: InternalArticle => Product
$normalizer = new SyncNormalizer($logger);
$sourceMap = $normalizer->normalizeProducts($items, $itemPricingDocuments);
$logger->zlog("Source normalized products: " . count($sourceMap));

// 4) Load WooCommerce products
if (!function_exists('wc_get_products')) {
	throw new \RuntimeException("WooCommerce functions not available. Make sure WooCommerce is active.");
}
$logger->zlog("Loading WooCommerce products...");
$wcProducts = wc_get_products([
	'limit'  => -1,
	'status' => 'publish',
	'return' => 'objects',
]);

// 5) Normalize WooCommerce products into map: InternalArticle (SKU) => Product
$targetMap = $normalizer->normalizeWooProducts($wcProducts);
$logger->zlog("Target normalized (Woo) products: " . count($targetMap));

// 6) Hash maps (hash => InternalArticle)
$sourceHashMap = $normalizer->buildHashMap($sourceMap);
$targetHashMap = $normalizer->buildHashMap($targetMap);

// 7) Compare and apply changes
$updates = 0;
$inserts = 0;

// 7.a) For products present in both by InternalArticle but with different hashes -> update
foreach ($sourceMap as $internal => $srcProd) {
	if (!isset($targetMap[$internal])) continue;

	$srcHash = $normalizer->computeHash($srcProd);
	$tarHash = $normalizer->computeHash($targetMap[$internal]);

	if ($srcHash !== $tarHash) {
		// Update existing Woo product by SKU (InternalArticle)
		$product_id = wc_get_product_id_by_sku($internal);
		if ($product_id) {
			$wcProduct = wc_get_product($product_id);
            // Build a normalized Woo array for debugging comparison
            if ($wcProduct instanceof \WC_Product) {
                $wcArr = $normalizer->normalizeWooProduct($wcProduct);
                if ($wcArr !== null) {
                    $normalizer->debugDiff($srcProd, $wcArr);
                }
				// Apply Woo-like constraints before saving:
				$reg = $srcProd['Price'] ?? null;
				$sale = $srcProd['SalesPrice'] ?? null;

				// Threshold: treat sale < 0.10 as non-existent
				if ($sale !== null && $sale !== '' && (float)$sale < 0.10) {
					$sale = null;
				}

				// If only sale provided, promote to regular
				if (($reg === null || $reg === '') && ($sale !== null && $sale !== '')) {
					$reg = $sale;
					$sale = null;
				}
				// If sale >= regular, clear sale
				if ($reg !== null && $sale !== null && (float)$sale >= (float)$reg) {
					$sale = null;
				}

				// Categories: add/remove special sale category based on $sale presence
				$hasSale = ($sale !== null && $sale !== '' && (float)$sale >= 0.10);
				apply_sale_category($wcProduct, $hasSale);

				$wcProduct->set_name($srcProd['FullName'] ?? $wcProduct->get_name());
				$wcProduct->set_regular_price($reg === null ? '' : (string)$reg);
				$wcProduct->set_sale_price($sale === null ? '' : (string)$sale);

				$wcProduct->save();
				$updates++;
				$logger->zlog("Updated Woo product SKU={$internal}");
            }
		}
	}
}

// 7.b) For products in source that aren't in target -> insert
foreach ($sourceMap as $internal => $srcProd) {
	if (isset($targetMap[$internal])) continue;

	// Apply the same price constraints on insert
	$reg = $srcProd['Price'] ?? null;
	$sale = $srcProd['SalesPrice'] ?? null;

	// Threshold: treat sale < 0.10 as non-existent
	if ($sale !== null && $sale !== '' && (float)$sale < 0.10) {
		$sale = null;
	}

	// If only sale provided, promote to regular
	if (($reg === null || $reg === '') && ($sale !== null && $sale !== '')) {
		$reg = $sale;
		$sale = null;
	}
	// If sale >= regular, clear sale
	if ($reg !== null && $sale !== null && (float)$sale >= (float)$reg) {
		$sale = null;
	}

	$wcProduct = new \WC_Product_Simple();
	$wcProduct->set_sku($internal);
	$wcProduct->set_name($srcProd['FullName'] ?? $internal);
	$wcProduct->set_regular_price($reg === null ? '' : (string)$reg);
	$wcProduct->set_sale_price($sale === null ? '' : (string)$sale);
	$wcProduct->set_status('draft');

	// Categories: add/remove special sale category based on $sale presence
	$hasSale = ($sale !== null && $sale !== '' && (float)$sale >= 0.10);
	apply_sale_category($wcProduct, $hasSale);

	$new_id = $wcProduct->save();
	if ($new_id) {
		$inserts++;
		$logger->zlog("Inserted Woo product SKU={$internal} ID={$new_id}");
	}
}

$logger->zlog("Sync complete. Updates: {$updates}, Inserts: {$inserts}");
