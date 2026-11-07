<?php
$pdo = new PDO('mysql:host=localhost;dbname=inventory', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Checking payment_received table:\n";
$result = $pdo->query("SHOW TABLES LIKE 'payment_received'");
if ($result->fetchColumn()) {
    echo "  Table EXISTS\n";
    $result2 = $pdo->query("DESCRIBE payment_received");
    while ($row = $result2->fetch(PDO::FETCH_ASSOC)) {
        echo "  Column: " . $row['Field'] . " Type: " . $row['Type'] . "\n";
    }
    // Try to drop and recreate
    echo "  Dropping table...\n";
    $pdo->query("DROP TABLE IF EXISTS payment_received");
    echo "  Table dropped.\n";
} else {
    echo "  Table does NOT exist\n";
}

// Check all tables
echo "\nAll tables in inventory database:\n";
$result3 = $pdo->query("SHOW TABLES");
while ($row = $result3->fetch(PDO::FETCH_NUM)) {
    echo "  - " . $row[0] . "\n";
}