<?php
require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Collection;

// if (!class_exists(Collection::class)) {
// 	// Minimal fallback Collection if illuminate/support is not installed.
// 	// For production, prefer composer require illuminate/support.
// 	class Collection implements \ArrayAccess, \IteratorAggregate, \Countable {
// 		private array $items;
// 		public function __construct($items = []) { $this->items = is_array($items) ? $items : iterator_to_array($items); }
// 		public function map(callable $cb) { return new self(array_map($cb, $this->items)); }
// 		public function filter(callable $cb = null) { return new self(array_values(array_filter($this->items, $cb ?: fn($v) => (bool)$v))); }
// 		public function reduce(callable $cb, $init = null) { return array_reduce($this->items, $cb, $init); }
// 		public function groupBy($key) {
// 			$out = [];
// 			foreach ($this->items as $it) {
// 				$k = is_callable($key) ? $key($it) : ($it[$key] ?? null);
// 				$out[$k][] = $it;
// 			}
// 			return new self($out);
// 		}
// 		public function sortByDesc($key) {
// 			$items = $this->items;
// 			usort($items, function ($a, $b) use ($key) {
// 				$av = is_callable($key) ? $key($a) : ($a[$key] ?? null);
// 				$bv = is_callable($key) ? $key($b) : ($b[$key] ?? null);
// 				return $bv <=> $av;
// 			});
// 			return new self($items);
// 		}
// 		public function keyBy($key) {
// 			$out = [];
// 			foreach ($this->items as $it) {
// 				$k = is_callable($key) ? $key($it) : ($it[$key] ?? null);
// 				$out[$k] = $it;
// 			}
// 			return new self($out);
// 		}
// 		public function pluck($key, $valueKey = null) {
// 			$out = [];
// 			foreach ($this->items as $k => $it) {
// 				$val = is_callable($key) ? $key($it, $k) : ($it[$key] ?? null);
// 				if ($valueKey !== null) {
// 					$k = is_callable($valueKey) ? $valueKey($it) : ($it[$valueKey] ?? $k);
// 				}
// 				$out[$k] = $val;
// 			}
// 			return new self($out);
// 		}
// 		public function first(callable $cb = null) {
// 			if ($cb === null) return $this->items[0] ?? null;
// 			foreach ($this->items as $it) if ($cb($it)) return $it;
// 			return null;
// 		}
// 		public function each(callable $cb) { foreach ($this->items as $k => $v) $cb($v, $k); return $this; }
// 		public function toArray() { return $this->items; }
// 		public function values() { return new self(array_values($this->items)); }
// 		public function getIterator(): Traversable { return new ArrayIterator($this->items); }
// 		public function offsetExists($offset): bool { return isset($this->items[$offset]); }
// 		public function offsetGet($offset): mixed { return $this->items[$offset]; }
// 		public function offsetSet($offset, $value): void { $offset === null ? $this->items[] = $value : $this->items[$offset] = $value; }
// 		public function offsetUnset($offset): void { unset($this->items[$offset]); }
// 		public function count(): int { return count($this->items); }
// 	}
// }

class SyncNormalizer
{
	private ZLogger $logger;

	// Target price types
	private string $PRICE_TYPE_PRICE = "605e52e7-e822-11ed-80e4-000c29409daa";
	private string $PRICE_TYPE_SALES = "f64e3772-4b14-11ee-80eb-000c29409daa";

	public function __construct(ZLogger $logger) {
		$this->logger = $logger;
	}

	// Canonicalize product name: unslash, remove stray backslashes, decode entities, trim + collapse whitespace
	private function canonName(?string $name): ?string {
		if ($name === null) return null;
		// Unslash (handles magic quotes or escaped JSON)
		if (function_exists('wp_unslash')) {
			$name = wp_unslash($name);
		} else {
			$name = stripslashes($name);
		}
		// Remove literal backslashes (e.g., "l\m" -> "lm")
		$name = str_replace('\\', '', $name);
		// Decode HTML entities (&amp; -> &)
		if (function_exists('wp_specialchars_decode')) {
			$name = wp_specialchars_decode($name, ENT_QUOTES);
		} else {
			$name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
		}
		// Trim and collapse whitespace
		$name = trim($name);
		$name = preg_replace('/\s+/u', ' ', $name);

		// Optional Unicode normalization if intl extension is available
		if (class_exists('Normalizer')) {
			$name = \Normalizer::normalize($name, \Normalizer::FORM_C);
		}

		return $name;
	}

	// Canonicalize price: null => null; numeric to consistent string
	// Uses WooCommerce store decimals if available, else defaults to 2.
	private function canonPrice($value): ?string {
		if ($value === null || $value === '') return null;
		// Normalize incoming numeric string to decimal
		$decimals = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;
		if (function_exists('wc_format_decimal')) {
			$norm = wc_format_decimal($value, $decimals, false);
		} else {
			$norm = number_format((float)$value, $decimals, '.', '');
		}
		// Optional: strip insignificant trailing zeros for stability across sources
		$norm = rtrim(rtrim($norm, '0'), '.');
		// If it becomes empty (e.g., "0.00" -> ""), keep "0"
		if ($norm === '') $norm = '0';
		return $norm;
	}

	// Apply Woo-like constraints plus sale threshold:
	// - If only SalesPrice is present, promote to Price and clear SalesPrice.
	// - If SalesPrice >= Price, clear SalesPrice.
	// - If SalesPrice < 0.10, clear SalesPrice.
	private function normalizePricePair(?string $regular, ?string $sale): array {
		$r = $this->canonPrice($regular);
		$s = $this->canonPrice($sale);

		// Threshold: treat sale < 0.10 as non-existent
		if ($s !== null && (float)$s < 0.10) {
			$s = null;
		}

		if ($r === null && $s !== null) {
			$r = $s;
			$s = null;
		}
		if ($r !== null && $s !== null) {
			if ((float)$s >= (float)$r) {
				$s = null;
			}
		}
		return [$r, $s];
	}

	/**
	 * Normalize API Items and ItemPricing docs into a map: InternalArticle => Product.
	 * Only includes products that have at least one price (Price or SalesPrice not null).
	 *
	 * Product shape:
	 * - FullName
	 * - InternalArticle
	 * - Price
	 * - SalesPrice
	 *
	 * @param array $items Array of item records (expects keys: uid, FullName, InternalArticle)
	 * @param array $pricingDocuments Array of ItemPricing documents
	 * @return array<string, array>
	 */
	public function normalizeProducts(array $items, array $pricingDocuments): array
	{
		$itemsCol = new Collection($items);
		$docsCol = new Collection($pricingDocuments);

		$latestPriceMapFor = function (string $targetPriceType) use ($docsCol): array {
			$filtered = $docsCol
				->filter(fn($d) => isset($d['PriceType']) && $d['PriceType'] === $targetPriceType)
				->map(function ($d) {
					$d['__ts'] = isset($d['Date']) ? strtotime($d['Date']) : null;
					return $d;
				})
				->sortByDesc('__ts');

			$priceByItem = [];
			$filtered->each(function ($doc) use (&$priceByItem) {
				if (!isset($doc['Items']) || !is_array($doc['Items'])) return;
				foreach ($doc['Items'] as $line) {
					$itemId = $line['Item'] ?? null;
					$price = $line['Price'] ?? null;
					if (!$itemId || $price === null) continue;
					if (!array_key_exists($itemId, $priceByItem)) {
						$priceByItem[$itemId] = $price;
					}
				}
			});

			return $priceByItem;
		};

		$priceMap = $latestPriceMapFor($this->PRICE_TYPE_PRICE);
		$salesMap = $latestPriceMapFor($this->PRICE_TYPE_SALES);

		// Build normalized products and keep only those with at least one price
		$productsAssoc = [];

		$itemsCol->each(function ($it) use (&$productsAssoc, $priceMap, $salesMap) {
			$uid = $it['uid'] ?? $it['Uid'] ?? $it['UID'] ?? null;
			$internal = $it['InternalArticle'] ?? $it['Article'] ?? null;
			if (!$uid || !$internal) return;

			[$priceNorm, $salesPriceNorm] = $this->normalizePricePair(
				array_key_exists($uid, $priceMap) ? $priceMap[$uid] : null,
				array_key_exists($uid, $salesMap) ? $salesMap[$uid] : null
			);

			$product = [
				'FullName'        => $this->canonName($it['FullName'] ?? $it['Name'] ?? null),
				'InternalArticle' => $internal,
				'Price'           => $priceNorm,
				'SalesPrice'      => $salesPriceNorm,
			];

			if ($product['Price'] !== null || $product['SalesPrice'] !== null) {
				$productsAssoc[$internal] = $product;
			}
		});

		$this->logger->zlog("Normalization complete. Products with prices: " . count($productsAssoc));
		return $productsAssoc;
	}

	/**
	 * Normalize WooCommerce products to the same Product shape, keyed by InternalArticle (SKU).
	 * Only includes entries that have SKU and at least one price.
	 *
	 * @param array|\WC_Product[] $wcProducts
	 * @return array<string, array>
	 */
	public function normalizeWooProducts(array $wcProducts): array
	{
		$out = [];
		foreach ($wcProducts as $p) {
			if (!($p instanceof \WC_Product)) continue;
			$sku = $p->get_sku();
			if (!$sku) continue;

			[$regular, $sale] = $this->normalizePricePair($p->get_regular_price(), $p->get_sale_price());

			if ($regular === null && $sale === null) continue;

			$out[$sku] = [
				'FullName'        => $this->canonName($p->get_name()),
				'InternalArticle' => $sku,
				'Price'           => $regular,
				'SalesPrice'      => $sale,
			];
		}
		$this->logger->zlog("Woo normalization complete. Products with prices: " . count($out));
		return $out;
	}

	/**
	 * Convert a single WooCommerce product into the normalized Product array shape.
	 * Returns null if product has no SKU or no prices.
	 */
	public function normalizeWooProduct(\WC_Product $p): ?array
	{
		$sku = $p->get_sku();
		if (!$sku) return null;

		[$regular, $sale] = $this->normalizePricePair($p->get_regular_price(), $p->get_sale_price());

		if ($regular === null && $sale === null) return null;

		return [
			'FullName'        => $this->canonName($p->get_name()),
			'InternalArticle' => $sku,
			'Price'           => $regular,
			'SalesPrice'      => $sale,
		];
	}

	/**
	 * Produce a deterministic hash for a normalized Product object.
	 * @param array $product
	 * @return string sha1 hash
	 */
	public function computeHash(array $product): string
	{
		$stable = [
			'FullName'        => (string)($this->canonName($product['FullName'] ?? '')),
			'InternalArticle' => (string)($product['InternalArticle'] ?? ''),
			'Price'           => $this->canonPrice($product['Price'] ?? null),
			'SalesPrice'      => $this->canonPrice($product['SalesPrice'] ?? null),
		];
		return sha1(json_encode($stable, JSON_UNESCAPED_UNICODE));
	}

	/**
	 * Build a map of hash => InternalArticle from normalized product map (InternalArticle => product).
	 * @param array<string, array> $normalizedMap
	 * @return array<string, string> hash => InternalArticle
	 */
	public function buildHashMap(array $normalizedMap): array
	{
		$hashMap = [];
		foreach ($normalizedMap as $internal => $prod) {
			$hashMap[$this->computeHash($prod)] = $internal;
		}
		return $hashMap;
	}

	// Optional helper to log field-level differences for a product
	public function debugDiff(array $src, array $dst): void {
		$fields = ['FullName', 'InternalArticle', 'Price', 'SalesPrice'];
		$diffs = [];
		foreach ($fields as $f) {
			$lv = $src[$f] ?? null;
			$rv = $dst[$f] ?? null;
			if ($f === 'FullName') { $lv = $this->canonName($lv); $rv = $this->canonName($rv); }
			if ($f === 'Price' || $f === 'SalesPrice') { $lv = $this->canonPrice($lv); $rv = $this->canonPrice($rv); }
			if ($lv !== $rv) $diffs[$f] = ['src' => $lv, 'dst' => $rv];
		}
		if (!empty($diffs)) {
			$this->logger->zlog("Diff: " . json_encode($diffs, JSON_UNESCAPED_UNICODE));
		}
	}
}
