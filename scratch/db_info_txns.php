<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables = [
    'fuel_transactions',
    'merchandise_transactions',
];

foreach ($tables as $table) {
    echo "=== Table: $table ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  {$row['Field']} - {$row['Type']} (Null: {$row['Null']}, Key: {$row['Key']})\n";
        }
    } catch (Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
