<?php
/**
 * Module 3: Business, Branch, and Department Setup Validation
 * Validates that all Module 3 files exist and are properly structured.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 3: Business, Branch, and Department Setup Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/company_functions.php',
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
    'views/company.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check controller
$controllerFile = 'company.php';
$controllerExists = file_exists($controllerFile);
$results['company.php'] = $controllerExists;
$status = $controllerExists ? "✓" : "✗";
echo "  $status company.php\n";

// Check schema migration
$schemaFile = 'db/schema_module3.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module3.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module3.sql\n";

// Check that Module 1-2 foundation files still exist
$foundationFiles = [
    'config/database.php',
    'db/schema.sql',
    'db/schema_module2.sql',
    'includes/auth_functions.php',
    'includes/auth_helpers.php',
    'assets/css/foundation.css',
    'assets/js/foundation.js',
];

echo "\nChecking Module 1-2 foundation still intact:\n";
foreach ($foundationFiles as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status " . basename($file) . "\n";
}

// Check Module 3 schema content
echo "\nChecking Module 3 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    
    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $branches = substr_count($schemaContent, 'branches');
    $departments = substr_count($schemaContent, 'departments');
    $inserts = substr_count($schemaContent, 'INSERT IGNORE INTO');
    
    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  branches references: $branches\n";
    echo "  departments table: $departments\n";
    echo "  INSERT statements: $inserts\n";
    
    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');
    
    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 3 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 3 READY" : "✗ Module 3 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 3 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module3.sql\n";
    echo "  2. Access company management at /company\n";
    echo "  3. Test creating/editing companies, branches, and departments\n";
    echo "  4. Verify multi-organization data isolation works\n";
    exit(0);
} else {
    echo "Some Module 3 files need attention before proceeding.\n";
    exit(1);
}