<?php
/**
 * Module 5: Sales and Point of Sale Validation
 * Validates that all Module 5 files exist and are properly structured.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 5: Sales and Point of Sale Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/sales_functions.php',
];

echo "Checking includes:\n";
foreach ($includes as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check views
$views = [
    'views/sales.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check controller
$controllerFile = 'sales.php';
$controllerExists = file_exists($controllerFile);
$results['sales.php'] = $controllerExists;
$status = $controllerExists ? "✓" : "✗";
echo "  $status sales.php\n";

// Check schema migration
$schemaFile = 'db/schema_module5.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module5.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module5.sql\n";

// Check that Module 1-4 foundation files still exist
$foundationFiles = [
    'config/database.php',
    'db/schema.sql',
    'db/schema_module2.sql',
    'db/schema_module3.sql',
    'db/schema_module4.sql',
    'includes/auth_functions.php',
    'includes/auth_helpers.php',
    'includes/company_functions.php',
    'includes/inventory_functions.php',
    'assets/css/foundation.css',
    'assets/js/foundation.js',
];

echo "\nChecking Module 1-4 foundation still intact:\n";
foreach ($foundationFiles as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status " . basename($file) . "\n";
}

// Check Module 5 schema content
echo "\nChecking Module 5 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    
    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $customers = substr_count($schemaContent, 'customers');
    $quotes = substr_count($schemaContent, 'quotes');
    $saleLines = substr_count($schemaContent, 'sale_lines');
    $payments = substr_count($schemaContent, 'payments_received');
    $inserts = substr_count($schemaContent, 'INSERT IGNORE INTO');
    
    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  customers table: $customers references\n";
    echo "  quotes table: $quotes references\n";
    echo "  sale_lines table: $saleLines references\n";
    echo "  payments_received table: $payments references\n";
    echo "  INSERT statements: $inserts\n";
    
    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');
    
    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 5 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 5 READY" : "✗ Module 5 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 5 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module5.sql\n";
    echo "  2. Access sales at /sales?action=customers\n";
    echo "  3. Test creating customers, quotes, and sales\n";
    echo "  4. Test POS functionality\n";
    echo "  5. Verify payment tracking and customer balances\n";
    exit(0);
} else {
    echo "Some Module 5 files need attention before proceeding.\n";
    exit(1);
}