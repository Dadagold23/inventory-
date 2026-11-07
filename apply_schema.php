<?php
/**
 * Apply MariaDB schema migration for Inventory Management System
 * Runs all schema files in dependency order
 */

$host = 'localhost';
$dbname = 'inventory';
$username = 'root';
$password = '';

echo "=== Inventory Management System - Schema Migration ===\n";
echo "Target database: $dbname on $host\n\n";

// Step 1: Apply foundation schema
echo "Step 1: Applying foundation schema (schema.sql)...\n";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $sql = file_get_contents("db/schema.sql");
    // Execute multiple statements
    $statements = explode(";\n", $sql);
    foreach ($statements as $statement) {
        if (trim($statement) !== "") {
            $pdo->exec($statement . ";");
        }
    }
    echo "  ✓ schema.sql applied successfully\n";
} catch (PDOException $e) {
    echo "  ✗ schema.sql failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Apply modules 1-21 in order
$moduleFiles = [];
for ($i = 1; $i <= 21; $i++) {
    $moduleFiles[] = "db/schema_module" . $i . ".sql";
}

echo "\nStep 2: Applying modules 1-21...\n";
foreach ($moduleFiles as $index => $moduleFile) {
    if (!file_exists($moduleFile)) {
        echo "  ✗ $moduleFile not found, skipping\n";
        continue;
    }
    
    $moduleNum = $index + 1;
    echo "  Applying module $moduleNum ($moduleFile)...\n";
    try {
        $sql = file_get_contents($moduleFile);
        $statements = explode(";\n", $sql);
        foreach ($statements as $statement) {
            if (trim($statement) !== "") {
                $pdo->exec($statement . ";");
            }
        }
        echo "    ✓ Module $moduleNum applied successfully\n";
    } catch (PDOException $e) {
        echo "    ✗ Module $moduleNum failed: " . substr($e->getMessage(), 0, 200) . "\n";
        echo "  Continuing with next module...\n";
    }
}

echo "\n=== Migration Complete ===\n";

// Verify tables
try {
    $result = $pdo->query("SELECT COUNT(*) AS total_tables FROM information_schema.tables WHERE table_schema='inventory'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "Total tables in inventory database: " . $row['total_tables'] . "\n";
    
    // Verify critical tables
    $criticalTables = ['users', 'companies', 'categories', 'products', 'system_settings', 'customer_communications'];
    echo "\nCritical tables verification:\n";
    foreach ($criticalTables as $table) {
        $result2 = $pdo->query("SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema='inventory' AND table_name='$table'");
        $row2 = $result2->fetch(PDO::FETCH_ASSOC);
        echo "  $table: " . $row2['cnt'] . " (" . ($row2['cnt'] > 0 ? '✓' : '✗') . ")\n";
    }
} catch (PDOException $e) {
    echo "Verification failed: " . $e->getMessage() . "\n";
}

echo "\nMigration process completed.\n";