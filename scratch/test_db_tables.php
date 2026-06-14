<?php
require_once __DIR__ . '/../public/db_connect.php';

$tables_needed = [
    'database_backups',
    'database_restores',
    'schema_migrations',
    'system_config',
    'activity_logs',
    'stations'
];

echo "=== EXISTING TABLES ===\n";
$stmt = $pdo->query("SHOW TABLES");
$existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
print_r($existing_tables);

echo "\n=== CHECKING TABLES STRUCTURE ===\n";
foreach ($tables_needed as $t) {
    if (in_array($t, $existing_tables)) {
        echo "\nTable: $t\n";
        try {
            $stmt2 = $pdo->query("DESCRIBE `$t`");
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                echo "  - {$row['Field']} ({$row['Type']})\n";
            }
        } catch (Exception $e) {
            echo "  Error describing $t: " . $e->getMessage() . "\n";
        }
    } else {
        echo "\nTable: $t (MISSING)\n";
    }
}
