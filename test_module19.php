<?php
/** 
 * Module 19: System Settings Validation 
 * Validates that all Module 19 files exist and are properly structured. 
 */ 

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 19: System Settings Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/system_settings_functions.php',
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
    'views/system_settings.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check controller
$controllerFile = 'system_settings.php';
$controllerExists = file_exists($controllerFile);
$results['system_settings.php'] = $controllerExists;
$status = $controllerExists ? "✓" : "✗";
echo "  $status system_settings.php\n";

// Check schema migration
$schemaFile = 'db/schema_module19.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module19.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module19.sql\n";

// Check that Module 1-18 foundation files still exist
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
    'db/schema_module11.sql',
    'db/schema_module12.sql',
    'db/schema_module13.sql',
    'db/schema_module14.sql',
    'db/schema_module15.sql',
    'db/schema_module16.sql',
    'db/schema_module17.sql',
    'db/schema_module18.sql',
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

echo "\nChecking Module 1-18 foundation still intact:\n";
foreach ($foundationFiles as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status " . basename($file) . "\n";
}

// Check Module 19 schema content
echo "\nChecking Module 19 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);

    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $settings = substr_count($schemaContent, 'system_settings');
    $inserts = substr_count($schemaContent, 'INSERT IGNORE INTO');

    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  system_settings table: $settings references\n";
    echo "  INSERT statements: $inserts\n";

    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');

    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 19 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 19 READY" : "✗ Module 19 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 19 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module19.sql\n";
    echo "  2. Access system settings at /system_settings\n";
    echo "  3. Test creating and updating system settings\n";
    echo "  4. Test getting settings by key\n";
    echo "  5. View and manage system configuration\n";
    exit(0);
} else {
    echo "Some Module 19 files need attention before proceeding.\n";
    exit(1);
}