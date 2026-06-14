<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Distinct Module Keys in DB ===\n";
try {
    $stmt = $pdo->query("SELECT DISTINCT module_key FROM station_modules");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- " . $row['module_key'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== All Station Module configurations ===\n";
try {
    $stmt = $pdo->query("SELECT station_id, module_key, is_enabled FROM station_modules ORDER BY station_id, module_key");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Station: " . $row['station_id'] . " | Module: " . $row['module_key'] . " | Enabled: " . $row['is_enabled'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
