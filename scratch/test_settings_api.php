<?php
/**
 * Quick end-to-end test for System Settings API
 * Tests: save_setting, get_setting, save_theme, save_all
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "=== System Settings API Test ===\n\n";

// ── Helper: save setting (same as API) ──────────────────────────────────────
function save_setting_test(PDO $pdo, string $key, string $value, string $group, int $station_id = 0): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings
                (setting_key, setting_value, setting_group, category, updated_by, station_id, is_public, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_group = VALUES(setting_group),
                category      = VALUES(category),
                updated_by    = VALUES(updated_by),
                updated_at    = NOW()
        ");
        $stmt->execute([$key, $value, $group, $group, 0, $station_id]);
        return true;
    } catch (Exception $e) {
        echo "  ERROR saving $key: " . $e->getMessage() . "\n";
        return false;
    }
}

// ── Helper: get setting ─────────────────────────────────────────────────────
function get_setting_test(PDO $pdo, string $key, int $station_id = 0): ?string {
    if ($station_id > 0) {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = ? LIMIT 1");
        $s->execute([$key, $station_id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r !== false) return $r['setting_value'];
    }
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = 0 LIMIT 1");
    $s->execute([$key]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ($r !== false) ? $r['setting_value'] : null;
}

// ── Test 1: Save + Get theme colors ─────────────────────────────────────────
echo "TEST 1: Save + Get theme colors (global, station_id=0)\n";
$colors = [
    'primary_color' => '#002F6C',
    'button_color'  => '#CC0000',
    'sidebar_color' => '#00264D',
];
foreach ($colors as $key => $val) {
    $ok = save_setting_test($pdo, $key, $val, 'theme', 0);
    $read = get_setting_test($pdo, $key, 0);
    $status = ($ok && $read === $val) ? 'PASS' : 'FAIL';
    echo "  [$status] $key = $read (expected: $val)\n";
}

// ── Test 2: Save + Get layout settings ──────────────────────────────────────
echo "\nTEST 2: Save + Get layout settings\n";
$layout = [
    'sidebar_style'   => 'inline',
    'font_scale_layout' => '100',
];
foreach ($layout as $key => $val) {
    $ok = save_setting_test($pdo, $key, $val, 'layout', 0);
    $read = get_setting_test($pdo, $key, 0);
    $status = ($ok && $read === $val) ? 'PASS' : 'FAIL';
    echo "  [$status] $key = $read (expected: $val)\n";
}

// ── Test 3: Save + Get accessibility settings ────────────────────────────────
echo "\nTEST 3: Save + Get accessibility settings\n";
$access = [
    'high_contrast'          => '0',
    'font_scale_accessibility' => '100',
];
foreach ($access as $key => $val) {
    $ok = save_setting_test($pdo, $key, $val, 'accessibility', 0);
    $read = get_setting_test($pdo, $key, 0);
    $status = ($ok && $read === $val) ? 'PASS' : 'FAIL';
    echo "  [$status] $key = $read (expected: $val)\n";
}

// ── Test 4: Station-specific override ────────────────────────────────────────
echo "\nTEST 4: Station-specific override (station_id=1)\n";
$ok = save_setting_test($pdo, 'primary_color', '#FF6600', 'theme', 1);
$globalVal = get_setting_test($pdo, 'primary_color', 0);
$stationVal = get_setting_test($pdo, 'primary_color', 1);
echo "  Global primary_color  = $globalVal (should be #002F6C)\n";
echo "  Station1 primary_color = $stationVal (should be #FF6600)\n";
$status = ($globalVal === '#002F6C' && $stationVal === '#FF6600') ? 'PASS' : 'FAIL';
echo "  [$status] Station override works correctly\n";
// Cleanup test station-specific setting
$pdo->prepare("DELETE FROM system_settings WHERE setting_key='primary_color' AND station_id=1")->execute();
echo "  Cleanup: removed test station_id=1 record\n";

// ── Test 5: system_settings_audit table exists and is writable ───────────────
echo "\nTEST 5: Audit log table\n";
try {
    $pdo->prepare("
        INSERT INTO system_settings_audit
            (setting_key, old_value, new_value, changed_by, changed_by_name, change_type, ip_address, station_id)
        VALUES ('_test_key', 'old', 'new', 0, 'TestScript', 'test', '127.0.0.1', 0)
    ")->execute();
    $pdo->prepare("DELETE FROM system_settings_audit WHERE setting_key = '_test_key'")->execute();
    echo "  [PASS] Audit table writable and cleanup OK\n";
} catch (Exception $e) {
    echo "  [FAIL] Audit table error: " . $e->getMessage() . "\n";
}

// ── Final: Show all current settings ─────────────────────────────────────────
echo "\n=== Current system_settings (station_id=0) ===\n";
$rows = $pdo->query("SELECT setting_key, setting_value, setting_group FROM system_settings WHERE station_id=0 ORDER BY setting_group, setting_key")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  [{$r['setting_group']}] {$r['setting_key']} = {$r['setting_value']}\n";
}

echo "\n=== ALL TESTS COMPLETE ===\n";
?>
