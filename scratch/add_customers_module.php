<?php
// Add 'customers' module to all active stations in station_modules
require_once __DIR__ . '/../public/db_connect.php';

$pdo->beginTransaction();
try {
    // Insert customers for all active stations that don't have it yet
    $stmt = $pdo->prepare("
        INSERT INTO station_modules (station_id, module_key, is_enabled, updated_by, updated_at)
        SELECT s.id, 'customers', 1, NULL, NOW()
        FROM stations s
        WHERE s.status = 'Active'
          AND NOT EXISTS (
              SELECT 1 FROM station_modules sm
              WHERE sm.station_id = s.id AND sm.module_key = 'customers'
          )
    ");
    $stmt->execute();
    $inserted = $stmt->rowCount();

    $pdo->commit();
    echo "SUCCESS: Inserted 'customers' module for $inserted stations." . PHP_EOL;

    // Verify
    $cnt = $pdo->query("SELECT COUNT(*) FROM station_modules WHERE module_key = 'customers'")->fetchColumn();
    echo "Total station_modules rows with 'customers': $cnt" . PHP_EOL;
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
