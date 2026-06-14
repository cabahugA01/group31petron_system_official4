<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    // 1. Add setting_group if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM system_settings LIKE 'setting_group'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN setting_group VARCHAR(50) NOT NULL DEFAULT 'general'");
        echo "Added setting_group column to system_settings\n";
    }

    // 2. Add station_id if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM system_settings LIKE 'station_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN station_id INT NOT NULL DEFAULT 0");
        echo "Added station_id column to system_settings\n";
    }

    // 3. Drop unique index unique_setting_key and add composite unique index
    try {
        $pdo->exec("ALTER TABLE system_settings DROP INDEX unique_setting_key");
        echo "Dropped index unique_setting_key\n";
    } catch (Exception $e) {
        // Might already be dropped or named differently
        try {
            $pdo->exec("ALTER TABLE system_settings DROP INDEX setting_key");
            echo "Dropped index setting_key\n";
        } catch (Exception $ex) {
            echo "Could not drop index setting_key: " . $ex->getMessage() . "\n";
        }
    }

    // 4. Create composite unique index (setting_key, station_id)
    try {
        $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY unique_setting_station (setting_key, station_id)");
        echo "Added composite unique index (setting_key, station_id)\n";
    } catch (Exception $e) {
        echo "Could not add composite unique index: " . $e->getMessage() . "\n";
    }

    // 5. Add station_id to audit table
    $stmt = $pdo->query("SHOW COLUMNS FROM system_settings_audit LIKE 'station_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE system_settings_audit ADD COLUMN station_id INT NOT NULL DEFAULT 0");
        echo "Added station_id column to system_settings_audit\n";
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
