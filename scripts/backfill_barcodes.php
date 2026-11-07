<?php
// Backfills in-store barcodes for products that have none (idempotent).
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/barcode_functions.php';

$pdo = get_db_connection();
$rows = $pdo->query('SELECT product_id, company_id FROM products WHERE barcode IS NULL OR barcode = "" ORDER BY product_id')->fetchAll(PDO::FETCH_ASSOC);

$done = 0;
foreach ($rows as $row) {
    $code = bcEnsureProductBarcode($pdo, (int) $row['product_id'], (int) $row['company_id']);
    echo $row['product_id'] . ' -> ' . $code . PHP_EOL;
    $done++;
}
echo "backfilled {$done} product(s)" . PHP_EOL;
echo 'missing now: ' . $pdo->query('SELECT COUNT(*) FROM products WHERE barcode IS NULL OR barcode = ""')->fetchColumn() . PHP_EOL;
