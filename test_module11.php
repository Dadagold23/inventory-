<?php
/**
 * Module 11: Cash, Bank, and Payment Management Validation
 * Validates that all Module 11 files exist and are properly structured.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 11: Cash, Bank, and Payment Management Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/bank_functions.php',
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
    'views/bank.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check controller
$controllerFile = 'bank.php';
$controllerExists = file_exists($controllerFile);
$results['bank.php'] = $controllerExists;
$status = $controllerExists ? "✓" : "✗";
echo "  $status bank.php\n";

// Check schema migration
$schemaFile = 'db/schema_module11.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module11.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module11.sql\n";

// Check that Module 1-10 foundation files still exist
$foundationFiles = [
    'config/database.php',
    'db/schema.sql',
    'db/schema_module2.sql',
    'db/schema_module3.sql',
    'db/schema_module4.sql',
    'db/schema_module5.sql',
    'db/schema_module6.sql',
    'db/schema_module7.sql',
    'db/schema_module8.sql',
    'db/schema_module9.sql',
    'db/schema_module10.sql',
    'includes/auth_functions.php',
    'includes/auth_helpers.php',
    'includes/company_functions.php',
    'includes/inventory_functions.php',
    'includes/sales_functions.php',
    'includes/ap_functions.php',
    'includes/customer_receivables_functions.php',
    'includes/income_functions.php',
    'includes/expense_functions.php',
    'assets/css/foundation.css',
    'assets/js/foundation.js',
];

echo "\nChecking Module 1-10 foundation still intact:\n";
foreach ($foundationFiles as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status " . basename($file) . "\n";
}

// Check Module 11 schema content
echo "\nChecking Module 11 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    
    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $banks = substr_count($schemaContent, 'banks');
    $transactions = substr_count($schemaContent, 'bank_transactions');
    $inserts = substr_count($schemaContent, 'INSERT IGNORE INTO');
    
    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  banks table: $banks references\n";
    echo "  bank_transactions table: $transactions references\n";
    echo "  INSERT statements: $inserts\n";
    
    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');
    
    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 11 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 11 READY" : "✗ Module 11 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 11 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module11.sql\n";
    echo "  2. Access bank management at /bank\n";
    echo "  3. Test creating banks and recording transactions\n";
    echo "  4. Verify cash summary functionality\n";
    exit(0);
} else {
    echo "Some Module 11 files need attention before proceeding.\n";
    exit(1);
}