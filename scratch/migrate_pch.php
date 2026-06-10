<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    $pdo->exec("ALTER TABLE pump_calibration_history ADD COLUMN reason VARCHAR(255) DEFAULT NULL");
    echo "Successfully added column 'reason' to pump_calibration_history!\n";
} catch (Exception $e) {
    echo "Column might already exist: " . $e->getMessage() . "\n";
}
