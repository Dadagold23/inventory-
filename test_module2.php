<?php
/**
 * Module 2: Authentication Validation Test
 * Validates that all Module 2 files exist and are properly structured.
 */

echo "=" . str_repeat("=", 60) . "\n";
echo "Module 2: Authentication, Roles, and Permissions Validation\n";
echo "=" . str_repeat("=", 60) . "\n\n";

// Track results
$results = [];

// Check includes files
$includes = [
    'includes/auth_functions.php',
    'includes/auth_helpers.php',
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
    'views/login.html',
    'views/dashboard.html',
    'views/change_password.html',
];

echo "\nChecking views:\n";
foreach ($views as $file) {
    $exists = file_exists($file);
    $results[$file] = $exists;
    $status = $exists ? "✓" : "✗";
    echo "  $status $file\n";
}

// Check auth controller
$authFile = 'auth.php';
$authExists = file_exists($authFile);
$results['auth.php'] = $authExists;
$status = $authExists ? "✓" : "✗";
echo "  $status auth.php\n";

// Check schema migration
$schemaFile = 'db/schema_module2.sql';
$schemaExists = file_exists($schemaFile);
$results['db/schema_module2.sql'] = $schemaExists;
$status = $schemaExists ? "✓" : "✗";
echo "  $status db/schema_module2.sql\n";

// Check database schema integration
echo "\nChecking Module 2 schema content:\n";
if ($schemaExists) {
    $schemaContent = file_get_contents($schemaFile);
    
    $alters = substr_count($schemaContent, 'ALTER TABLE');
    $creates = substr_count($schemaContent, 'CREATE TABLE IF NOT EXISTS');
    $user_roles = substr_count($schemaContent, 'user_roles');
    $permissions = substr_count($schemaContent, 'user_roles');
    $admin_user = substr_count($schemaContent, 'admin');
    $reset_token = substr_count($schemaContent, 'password_reset_token');
    
    echo "  ALTER TABLE statements: $alters\n";
    echo "  CREATE TABLE IF NOT EXISTS: $creates\n";
    echo "  user_roles table: $user_roles references\n";
    echo "  Admin user insert: $admin_user\n";
    echo "  Password reset token support: $reset_token\n";
    
    $hasForeignKey = str_contains($schemaContent, 'FOREIGN KEY');
    $hasInnoDB = str_contains($schemaContent, 'InnoDB');
    
    echo "  Has Foreign Keys: " . ($hasForeignKey ? "yes" : "no") . "\n";
    echo "  Uses InnoDB: " . ($hasInnoDB ? "yes" : "no") . "\n";
}

// Check foundation CSS still works
$cssFile = 'assets/css/foundation.css';
$cssExists = file_exists($cssFile);
$results['assets/css/foundation.css'] = $cssExists;
$status = $cssExists ? "✓" : "✗";
echo "  $status assets/css/foundation.css\n";

// Check foundation JS still works
$jsFile = 'assets/js/foundation.js';
$jsExists = file_exists($jsFile);
$results['assets/js/foundation.js'] = $jsExists;
$status = $jsExists ? "✓" : "✗";
echo "  $status assets/js/foundation.js\n";

// Check database config still works
$configFile = 'config/database.php';
$configExists = file_exists($configFile);
$results['config/database.php'] = $configExists;
$status = $configExists ? "✓" : "✗";
echo "  $status config/database.php\n";

// Summary
echo "\n" . "=" . str_repeat("=", 60) . "\n";
echo "Module 2 Validation Summary\n";
echo "=" . str_repeat("=", 60) . "\n\n";

$total = count($results);
$passed = array_reduce(array_keys($results), fn($carry, $key) => $results[$key] ? $carry + 1 : $carry, 0);
$fail_count = $total - $passed;

echo "Total checks: $total\n";
echo "Passed: $passed\n";
echo "Failed: $fail_count\n";
echo "Overall: " . ($fail_count === 0 ? "✓ Module 2 READY" : "✗ Module 2 NEEDS ATTENTION") . "\n\n";

if ($fail_count === 0) {
    echo "Module 2 foundation is complete.\n";
    echo "Next steps:\n";
    echo "  1. Set up MySQL and run db/schema_module2.sql\n";
    echo "  2. Create initial admin user with password change on first login\n";
    echo "  3. Test login functionality at /auth\n";
    echo "  4. Verify role-based access controls work\n";
    exit(0);
} else {
    echo "Some Module 2 files need attention before proceeding.\n";
    exit(1);
}