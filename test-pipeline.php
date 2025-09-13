<?php
// Test pipeline for product sync without calling the real API or touching WooCommerce.
// Usage examples:
// - Browser: /wp-content/plugins/product-sync/sync-test.php?items=items.json&pricing=pricing.json&woo=woo.json
// - CLI: php sync-test.php items=items.json pricing=pricing.json woo=woo.json apply=1

require_once __DIR__ . '/../../../wp-load.php';
require_once 'zlogger.php';
require_once 'sync-normalizer.php';

// Read argv-style query when run via CLI
if (php_sapi_name() === 'cli' && isset($argv) && count($argv) > 1) {
	foreach (array_slice($argv, 1) as $arg) {
		if (strpos($arg, '=') !== false) {
			[$k, $v] = explode('=', $arg, 2);
			$_GET[$k] = $v;
		}
	}
}

$logger = new ZLogger(true);
$normalizer = new SyncNormalizer($logger);

$itemsPath   = $_GET['items']   ?? null;
$pricingPath = $_GET['pricing'] ?? null;
$wooPath     = $_GET['woo']     ?? null;
$apply       = isset($_GET['apply']) && (string)$_GET['apply'] === '1';

// Helpers
$readJson = function (?string $path, $default = []) use ($logger) {
	if (!$path) return $default;
	$resolved = $path;
	if (!preg_match('/^([a-zA-Z]:\\\\|\/)/', $path)) {
		// Not absolute; resolve relative to this file dir
		$resolved = __DIR__ . '/' . ltrim($path, '/\\');
	}
	if (!file_exists($resolved)) {
		$logger->zlog("Fixture not found: $resolved");
		return $default;
	}
	$raw = file_get_contents($resolved);
	$decoded = json_decode($raw, true);
	if (!is_array($decoded)) {
		$logger->zlog("Invalid JSON in: $resolved");
		return $default;
	}
	$logger->zlog("Loaded fixture: $resolved (" . strlen($raw) . " bytes)");
	return $decoded;
};

// Sample defaults if no fixtures provided
$sampleItems = [
	[
		'uid' => 'item-1',
		'FullName' => 'Pump 12V, 6.1 l\\m',
		'InternalArticle' => 'SKU-001',
	],
	[
		'uid' => 'item-2',
		'FullName' => 'Transmission Oil MF Full Synthetic SAE 50 Final Drive & Axle Oil 3,78L',
		'InternalArticle' => 'SKU-002',
	],
    [
        'uid' => 'item-3',
        'FullName' => 'Pump 12V, 6.1 l\\m',
        'InternalArticle' => 'SKU-003',
    ]
];

$samplePricingDocs = [
	[
		'uid' => 'doc-1',
		'Number' => '0001',
		'Date' => '2024-01-01T10:00:00',
		'PriceType' => '605e52e7-e822-11ed-80e4-000c29409daa', // Price
		'Items' => [
			['Item' => 'item-1', 'Price' => '120'],
			['Item' => 'item-2', 'Price' => '200'],
			['Item' => 'item-3', 'Price' => '220'],
		],
	],
	[
		'uid' => 'doc-2',
		'Number' => '0002',
		'Date' => '2024-02-01T10:00:00',
		'PriceType' => 'f64e3772-4b14-11ee-80eb-000c29409daa', // SalesPrice
		'Items' => [
			['Item' => 'item-1', 'Price' => '100'], // valid sale < regular
			['Item' => 'item-2', 'Price' => '220'], // invalid sale >= regular; should be cleared
			['Item' => 'item-3', 'Price' => '0.09'],
		],
	],
];

$sampleWooMap = [
	// Existing Woo for SKU-001 with old name and no sale price
	'SKU-001' => [
		'FullName' => 'Pump 12V, 6.1 lm',
		'InternalArticle' => 'SKU-001',
		'Price' => '120',
		'SalesPrice' => null,
	],
	'SKU-003' => [
    		'FullName' => 'Pump 12V, 6.1 lm',
    		'InternalArticle' => 'SKU-003',
    		'Price' => '220',
    		'SalesPrice' => '0.1',
    	]
	// SKU-002 does not exist yet -> should be an insert
];

// Load fixtures or use samples
$items = $readJson($itemsPath, $sampleItems);
$pricingDocs = $readJson($pricingPath, $samplePricingDocs);
$targetMap = $readJson($wooPath, $sampleWooMap); // normalized map expected (InternalArticle => product)

// 1) Build source map from fixtures
$sourceMap = $normalizer->normalizeProducts($items, $pricingDocs);
$logger->zlog("Source normalized products: " . count($sourceMap));

// 2) Ensure target map is normalized (if you passed raw WC product JSON, adapt here; we expect normalized map)
$logger->zlog("Target normalized (Woo) products (from fixture): " . count($targetMap));

// 3) Hash maps
$sourceHashMap = $normalizer->buildHashMap($sourceMap);
$targetHashMap = $normalizer->buildHashMap($targetMap);

// 4) Compare and collect actions
$updates = [];
$inserts = [];

// Updates: same key exists but hashes differ
foreach ($sourceMap as $internal => $srcProd) {
	if (!isset($targetMap[$internal])) continue;

	$srcHash = $normalizer->computeHash($srcProd);
	$tarHash = $normalizer->computeHash($targetMap[$internal]);

	if ($srcHash !== $tarHash) {
		$updates[$internal] = [
			'before' => $targetMap[$internal],
			'after'  => $srcProd,
		];
	}
}

// Inserts: source not in target
foreach ($sourceMap as $internal => $srcProd) {
	if (!isset($targetMap[$internal])) {
		$inserts[$internal] = $srcProd;
	}
}

$logger->zlog("Planned actions: updates=" . count($updates) . ", inserts=" . count($inserts));

// 5) Dry-run logs
foreach ($updates as $sku => $pair) {
	$logger->zlog("UPDATE SKU={$sku}");
	$normalizer->debugDiff($pair['after'], $pair['before']);
}
foreach ($inserts as $sku => $prod) {
	$logger->zlog("INSERT SKU={$sku}: " . json_encode($prod, JSON_UNESCAPED_UNICODE));
}

// 6) Optional apply: mutate the in-memory target map to reflect changes
if ($apply) {
	foreach ($updates as $sku => $pair) {
		$targetMap[$sku] = $pair['after'];
	}
	foreach ($inserts as $sku => $prod) {
		$targetMap[$sku] = $prod;
	}
	$logger->zlog("Applied changes in-memory. Target count now: " . count($targetMap));

	// Show new hashes so you can verify idempotency on next run
	$newTargetHashMap = $normalizer->buildHashMap($targetMap);
	$logger->zlog("New distinct hashes in target: " . count($newTargetHashMap));
}

// 7) Exit status and optional output
//header('Content-Type: application/json; charset=utf-8');
echo json_encode([
	'source_count' => count($sourceMap),
	'target_count' => count($targetMap),
	'updates' => array_keys($updates),
	'inserts' => array_keys($inserts),
	'applied' => $apply,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
