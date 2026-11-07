<?php
/**
 * Module 4: Inventory and Product Catalogue Validation
 * Validates that all Module 4 files exist and are properly structured.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 4: Inventory and Product Catalogue Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/inventory_functions.php',
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
    'views/inventory.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check controller
$controllerFile = 'inventory.php';
$controllerExists = file_exists($controllerFile);
$results['inventory.php'] = $controllerExists;
$status = $controllerExists ? "✓" : "✗";
echo "  $status inventory.php\n";

// Check schema migration
$schemaFile = 'db/schema_module4.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module4.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module4.sql\n";

// Check that Module 1-3 foundation files still exist
$foundationFiles = [
    'config/database.php',
    'db/schema.sql',
    'db/schema_module2.sql',
    'db/schema_module3.sql',
    'includes/auth_functions.php',
    'includes/auth_helpers.php',
    'includes/company_functions.php',
    'assets/css/foundation.css',
    'assets/js/foundation.js',
];

echo "\nChecking Module 1-3 foundation still intact:\n";
foreach ($foundationFiles as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status " . basename($file) . "\n";
}

// Check Module 4 schema content
echo "\nChecking Module 4 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    
    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $products = substr_count($schemaContent, 'products');
    $locations = substr_count($schemaContent, 'product_locations');
    $transactions = substr_count($schemaContent, 'inventory_transactions');
    $inserts = substr_count($schemaContent, 'INSERT IGNORE INTO');
    
    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  products table: $products references\n";
    echo "  product_locations table: $locations references\n";
    echo "  inventory_transactions table: $transactions references\n";
    echo "  INSERT statements: $inserts\n";
    
    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');
    
    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 4 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 4 READY" : "✗ Module 4 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 4 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module4.sql\n";
    echo "  2. Access inventory at /inventory\n";
    echo "  3. Test creating products and recording stock transactions\n";
    echo "  4. Verify FIFO costing works correctly\n";
    echo "  5. Test low stock alerts\n";
    exit(0);
} else {
    echo "Some Module 4 files need attention before proceeding.\n";
    exit(1);
}