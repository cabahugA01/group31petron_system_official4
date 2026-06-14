<?php
require_once __DIR__ . '/../public/db_connect.php';
try {
    // Check table structure
    echo "=== system_settings table ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']}: {$c['Type']} | Key: {$c['Key']} | Default: {$c['Default']}\n";
    }

    echo "\n=== UNIQUE KEYS on system_settings ===\n";
    $keys = $pdo->query("SHOW INDEX FROM system_settings WHERE Non_unique = 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($keys as $k) {
        echo "  {$k['Key_name']}: {$k['Column_name']}\n";
    }
    
    echo "\n=== system_settings_audit table ===\n";
    $cols2 = $pdo->query("SHOW COLUMNS FROM system_settings_audit")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols2 as $c) {
        echo "  {$c['Field']}: {$c['Type']} | Key: {$c['Key']} | Default: {$c['Default']}\n";
    }
    
    echo "\n=== Current settings in DB ===\n";
    $rows = $pdo->query("SELECT id, setting_key, setting_value, station_id, updated_by FROM system_settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "  [{$r['id']}] {$r['setting_key']} = {$r['setting_value']} (station_id={$r['station_id']})\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
