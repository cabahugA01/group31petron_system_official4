<?php
require_once __DIR__ . '/../public/db_connect.php';

// Test NULL updated_by
try {
    $s = $pdo->prepare("
        INSERT INTO system_settings 
            (setting_key, setting_value, setting_group, category, updated_by, station_id, is_public, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()
    ");
    $s->execute(['_test_null', '#CC0000', 'theme', 'theme', null, 0]);
    echo "NULL updated_by: OK\n";
} catch (Exception $e) {
    echo "NULL updated_by FAILED: " . $e->getMessage() . "\n";
}

// Test valid user_id = 1
try {
    $s = $pdo->prepare("
        INSERT INTO system_settings 
            (setting_key, setting_value, setting_group, category, updated_by, station_id, is_public, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()
    ");
    $s->execute(['_test_uid1', '#002F6C', 'theme', 'theme', 1, 0]);
    echo "user_id=1: OK\n";
} catch (Exception $e) {
    echo "user_id=1 FAILED: " . $e->getMessage() . "\n";
}

// Cleanup
$pdo->exec("DELETE FROM system_settings WHERE setting_key IN ('_test_null','_test_uid1')");
echo "Cleanup done.\n";

// Check if user_id=1 exists
$u = $pdo->query("SELECT id, username FROM users WHERE id=1 LIMIT 1")->fetch();
echo "User id=1: " . ($u ? $u['username'] : 'NOT FOUND') . "\n";

// List first 3 users
$users = $pdo->query("SELECT id, username, role FROM users LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
echo "First 3 users:\n";
foreach ($users as $u) {
    echo "  id={$u['id']} username={$u['username']} role={$u['role']}\n";
}
?>
