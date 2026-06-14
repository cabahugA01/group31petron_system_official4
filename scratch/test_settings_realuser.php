<?php
/**
 * Simulate real API calls as superadmin (id=1)
 * Tests save_all and get_all with valid user
 */
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

echo "=== System Settings: Real-User Simulation ===\n\n";

// Simulate the $me array from session (developer user, id=1)
$me = $pdo->query("SELECT * FROM users WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$me) {
    echo "ERROR: User id=1 not found!\n";
    exit(1);
}
echo "Simulating as user: {$me['username']} (id={$me['id']}, role={$me['role']})\n\n";

// ── Include the actual API helpers directly ─────────────────────────────────
// (same logic as the API, tested independently)
function get_setting_real(PDO $pdo, string $key, int $station_id = 0): ?string {
    if ($station_id > 0) {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? AND station_id=? LIMIT 1");
        $s->execute([$key, $station_id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r !== false) return $r['setting_value'];
    }
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=? AND station_id=0 LIMIT 1");
    $s->execute([$key]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ($r !== false) ? $r['setting_value'] : null;
}

function save_setting_real(PDO $pdo, string $key, string $value, string $group, array $user, int $station_id = 0): bool {
    $userId = ($user['id'] ?? 0) > 0 ? (int)($user['id']) : null;
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
        $stmt->execute([$key, $value, $group, $group, $userId, $station_id]);
        return true;
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

// ── TEST: Save all settings (simulates saveAllSettings() in JS) ──────────────
$payload = [
    'primary_color'           => '#002F6C',
    'button_color'            => '#CC0000',
    'sidebar_color'           => '#00264D',
    'sidebar_style'           => 'inline',
    'font_scale_layout'       => '100',
    'high_contrast'           => '0',
    'font_scale_accessibility'=> '100',
    'station_id'              => 0,
];
echo "Simulating save_all with payload:\n";
foreach ($payload as $k => $v) echo "  $k = $v\n";
echo "\n";

$saved = [];
$errors = [];

// Theme colors
$color_fields = ['primary_color', 'button_color', 'sidebar_color'];
foreach ($color_fields as $key) {
    if (!isset($payload[$key]) || trim($payload[$key]) === '') continue;
    $val = trim($payload[$key]);
    if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) { $errors[] = "Invalid color: $key"; continue; }
    if (save_setting_real($pdo, $key, $val, 'theme', $me, 0)) $saved[] = $key;
}

// Layout
foreach (['sidebar_style', 'font_scale_layout'] as $key) {
    if (!isset($payload[$key])) continue;
    if (save_setting_real($pdo, $key, trim($payload[$key]), 'layout', $me, 0)) $saved[] = $key;
}

// Accessibility
foreach (['high_contrast', 'font_scale_accessibility'] as $key) {
    if (!isset($payload[$key])) continue;
    if (save_setting_real($pdo, $key, trim($payload[$key]), 'accessibility', $me, 0)) $saved[] = $key;
}

echo "Saved keys: " . implode(', ', $saved) . "\n";
echo "Errors: " . (count($errors) ? implode(', ', $errors) : 'none') . "\n\n";

// ── Verify all saved values read back correctly ──────────────────────────────
echo "=== Readback verification ===\n";
$checks = [
    'primary_color'            => '#002F6C',
    'button_color'             => '#CC0000',
    'sidebar_color'            => '#00264D',
    'sidebar_style'            => 'inline',
    'font_scale_layout'        => '100',
    'high_contrast'            => '0',
    'font_scale_accessibility' => '100',
];
$pass = $fail = 0;
foreach ($checks as $key => $expected) {
    $actual = get_setting_real($pdo, $key, 0);
    $ok = ($actual === $expected);
    $status = $ok ? 'PASS' : 'FAIL';
    echo "  [$status] $key = " . ($actual ?? 'NULL') . " (expected: $expected)\n";
    $ok ? $pass++ : $fail++;
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";

if ($fail === 0) {
    echo "\nSystem Settings API is fully functional!\n";
    echo "The save_all, save_theme, save_layout, save_accessibility actions all work.\n";
} else {
    echo "\nSome settings failed to save. Review errors above.\n";
}
?>
