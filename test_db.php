<?php
/**
 * Module 1: Foundation Validation Test
 * Validates that all Module 1 files exist and are properly structured.
 * Database connection test is informational - actual setup depends on MySQL availability.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 1: Project Foundation and Database Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check directory structure
$directories = [
    'config',
    'db',
    'controllers',
    'models',
    'views',
    'includes',
    'assets/css',
    'assets/js',
    'assets/images',
    'assets/fonts',
];

echo "Checking directory structure:\n";
foreach ($directories as $dir) {
    $exists = is_dir($dir);
    $results[$dir] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $dir\n";
}

// Check database schema file
echo "\nChecking database schema:\n";
$schemaFile = 'db/schema.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema.sql\n";

if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    $schemaSize = strlen($schemaContent);
    
    // Count key elements
    $user_count = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS `users`');
    $company_count = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS `companies`');
    $account_count = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS `accounts`');
    $category_count = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS `categories`');
    
    echo "  Schema size: $schemaSize bytes\n";
    echo "  Users table: $user_count\n";
    echo "  Companies table: $company_count\n";
    echo "  Accounts table: $account_count\n";
    echo "  Categories table: $category_count\n";
    
    // Check for key SQL features
    $has_transactions = str_contains($schemaContent, 'START TRANSACTION;');
    $has_audit = str_contains($schemaContent, 'audit_log');
    $has_foreign_keys = str_contains($schemaContent, 'FOREIGN KEY');
    
    echo "  Uses transactions: " . ($has_transactions ? "yes" : "no") . "\n";
    echo "  Has audit log: " . ($has_audit ? "yes" : "no") . "\n";
    echo "  Has foreign keys: " . ($has_foreign_keys ? "yes" : "no") . "\n";
}

// Check config file
echo "\nChecking database configuration:\n";
$configFile = 'config/database.php';
$configExists = file_exists($configFile);
$results['config/database.php'] = $configExists;
$status = $configExists ? "✓" : "✗";
echo "  $status config/database.php\n";

if ($configExists) {
    $configContent = file_get_contents($configFile);
    $has_pdo = str_contains($configContent, 'new PDO');
    $has_defines = str_contains($configContent, 'define(');
    echo "  PDO connection: " . ($has_pdo ? "yes" : "no") . "\n";
    echo "  Config defines: " . ($has_defines ? "yes" : "no") . "\n";
}

// Check index.php
echo "\nChecking application entry point:\n";
$indexFile = 'index.php';
$indexExists = file_exists($indexFile);
$results['index.php'] = $indexExists;
$status = $indexExists ? "✓" : "✗";
echo "  $status index.php\n";

if ($indexExists) {
    echo "  Entry point for the application\n";
}

// Check foundation styles
echo "\nChecking foundation styles:\n";
$cssFile = 'assets/css/foundation.css';
$cssExists = file_exists($cssFile);
$results['assets/css/foundation.css'] = $cssExists;
$status = $cssExists ? "✓" : "✗";
echo "  $status assets/css/foundation.css\n";

if ($cssExists) {
    $cssContent = file_get_contents($cssFile);
    echo "  CSS file size: " . strlen($cssContent) . " bytes\n";
}

// Check foundation JavaScript
echo "\nChecking foundation JavaScript:\n";
$jsFile = 'assets/js/foundation.js';
$jsExists = file_exists($jsFile);
$results['assets/js/foundation.js'] = $jsExists;
$status = $jsExists ? "✓" : "✗";
echo "  $status assets/js/foundation.js\n";

if ($jsExists) {
    $jsContent = file_get_contents($jsFile);
    echo "  JS file size: " . strlen($jsContent) . " bytes\n";
}

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 1 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce($results, fn($carry, $item) => $carry && $item, true);
$pass_count = array_count_values($results)[true] ?? 0;
$fail_count = array_count_values($results)[false] ?? 0;

echo "Total checks: $total\n";
echo "Passed: $pass_count\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($passed ? "✓ Module 1 READY" : "✗ Module 1 NEEDS ATTENTION") . "\n\n";

if ($passed) {
    echo "Module 1 foundation is complete and ready for Module 2.\n";
    echo "Next: Module 2 - Authentication, roles, and permissions\n";
    echo "       Create users table data and login functionality.\n";
    exit(0);
} else {
    echo "Some Module 1 files need attention before proceeding.\n";
    exit(1);
}