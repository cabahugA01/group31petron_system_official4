<?php
/**
 * Fuel API Diagnostic v2 — no HTTP loopback, tests directly
 * Visit: http://localhost/group31petron_system_official4/public/test_fuel_api.php
 * DELETE after debugging.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$session_user = $_SESSION['user'] ?? null;
$uid = $session_user['id'] ?? 0;

echo "<!DOCTYPE html><html><head><title>Fuel API Test v2</title>
<style>
body{font-family:monospace;padding:20px;background:#f8fafc;font-size:13px;}
pre{background:#1e293b;color:#e2e8f0;padding:14px;border-radius:8px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;}
.ok{color:#22c55e;font-weight:bold;} .err{color:#ef4444;font-weight:bold;} .warn{color:#f59e0b;font-weight:bold;}
h2{color:#00264D;margin-top:0;} .box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:12px 0;}
</style></head><body><h2>🔍 Fuel API Diagnostic v2</h2>";

// ── 1. Session ──
echo "<div class='box'><b>1. Session</b><br>";
echo "User: " . ($session_user ? "<span class='ok'>✓ id={$uid}, role={$session_user['role']}, station={$session_user['station_id']}</span>" : "<span class='err'>✗ NOT SET — you need to log in first</span>") . "<br>";
echo "Session ID: <code>" . session_id() . "</code><br>";
echo "</div>";

if (!$session_user) {
    echo "<div class='box'><span class='err'>Stop here — log in first, then revisit this page.</span></div></body></html>";
    exit;
}

// ── 2. Check PHP error log for recent errors ──
echo "<div class='box'><b>2. PHP Error Log (last 20 lines)</b><br>";
$log_paths = [
    'C:/xampp/php/logs/php_error_log',
    'C:/xampp/apache/logs/error.log',
    ini_get('error_log'),
];
$found_log = false;
foreach ($log_paths as $lp) {
    if ($lp && file_exists($lp)) {
        $lines = file($lp);
        $last = array_slice($lines, -20);
        // Filter for lines mentioning api_fuel_readings
        $relevant = array_filter($last, fn($l) => stripos($l, 'api_fuel') !== false || stripos($l, 'Fatal') !== false || stripos($l, 'Parse error') !== false);
        if ($relevant) {
            echo "<span class='warn'>Found relevant errors in {$lp}:</span><br>";
            echo "<pre>" . htmlspecialchars(implode('', $relevant)) . "</pre>";
        } else {
            echo "Log: <code>{$lp}</code> — no api_fuel_readings errors found<br>";
        }
        $found_log = true;
        break;
    }
}
if (!$found_log) echo "<span class='warn'>No PHP error log found at standard paths</span><br>";
echo "</div>";

// ── 3. Syntax check api_fuel_readings.php ──
echo "<div class='box'><b>3. PHP Syntax Check</b><br>";
$api_file = __DIR__ . '/api_fuel_readings.php';
$php_bin = 'C:/xampp/php/php.exe';
if (file_exists($php_bin)) {
    $output = shell_exec('"' . $php_bin . '" -l "' . $api_file . '" 2>&1');
    if (strpos($output, 'No syntax errors') !== false) {
        echo "<span class='ok'>✓ No syntax errors</span><br>";
    } else {
        echo "<span class='err'>✗ SYNTAX ERROR:</span><br>";
        echo "<pre>" . htmlspecialchars($output) . "</pre>";
    }
} else {
    // Try via exec
    $output = shell_exec('php -l "' . $api_file . '" 2>&1');
    echo "php -l output: <pre>" . htmlspecialchars($output ?: '(no output — php not in PATH)') . "</pre>";
}
echo "</div>";

// ── 4. Simulate the encode_reading POST directly ──
echo "<div class='box'><b>4. Direct encode_reading simulation</b><br>";

// Capture output of the API by including it with $_POST set
$_POST = [
    'action'         => 'encode_reading',
    'auth_user_id'   => $uid,
    'fuel_type'      => 'Diesel',
    'present_reading'=> '99999.99',
    'calibration'    => '0',
    'notes'          => 'TEST - DELETE',
    'shift_period'   => 'test',
    'shift_name'     => 'Test Shift',
    'reading_date'   => date('Y-m-d'),
];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET['action'] = '';

ob_start();
try {
    // We can't include the file (it calls exit), so we test the DB connection and role check manually
    require_once __DIR__ . '/db_connect.php';
    require_once __DIR__ . '/../backend/lib.php';

    // Check user from DB
    $u = $pdo->prepare("SELECT `user_id`, name, role, station_id, status FROM users WHERE user_id = ? LIMIT 1");
    $u->execute([$uid]);
    $db_user = $u->fetch(PDO::FETCH_ASSOC);
    echo "DB user lookup: " . ($db_user ? "<span class='ok'>✓ Found: {$db_user['name']}, role={$db_user['role']}, station={$db_user['station_id']}, status={$db_user['status']}</span>" : "<span class='err'>✗ NOT FOUND in DB</span>") . "<br>";

    if ($db_user) {
        $role_key = role_key($db_user['role']);
        echo "role_key('{$db_user['role']}') = <code>{$role_key}</code><br>";
        $allowed = in_array($role_key, ['staff', 'cashier', 'pump_attendant']);
        echo "Allowed to encode: " . ($allowed ? "<span class='ok'>✓ YES</span>" : "<span class='err'>✗ NO — role '{$role_key}' not in allowed list</span>") . "<br>";

        // Check fuel_transactions table
        $cols = $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_COLUMN);
        echo "fuel_transactions columns: <code>" . implode(', ', $cols) . "</code><br>";

        // Check station_id
        $sid = $db_user['station_id'];
        echo "station_id: <code>" . ($sid ?: "<span class='err'>NULL/0 — user has no station assigned</span>") . "</code><br>";

        // Check fuel_inventory for this station
        $fi = $pdo->prepare("SELECT fuel_type, current_level, current_stock, price_per_liter FROM fuel_inventory WHERE station_id = ?");
        $fi->execute([$sid]);
        $fuels = $fi->fetchAll(PDO::FETCH_ASSOC);
        echo "Fuel inventory rows for station {$sid}: <code>" . count($fuels) . "</code><br>";
        foreach ($fuels as $f) {
            echo "&nbsp;&nbsp;→ {$f['fuel_type']}: level={$f['current_level']}, stock={$f['current_stock']}, price={$f['price_per_liter']}<br>";
        }
    }
} catch (Exception $e) {
    echo "<span class='err'>Exception: " . htmlspecialchars($e->getMessage()) . "</span><br>";
}
$captured = ob_get_clean();
echo $captured;
echo "</div>";

// ── 5. Check what the API actually outputs for a GET request ──
echo "<div class='box'><b>5. API output for ?action=debug_auth (direct file read test)</b><br>";
echo "Visit this URL while logged in: <a href='api_fuel_readings.php?action=debug_auth' target='_blank'>api_fuel_readings.php?action=debug_auth</a><br>";
echo "Expected: <code>{\"success\":true,\"message\":\"Auth OK\",...}</code><br>";
echo "If you see HTML or empty — the file has a PHP error<br>";
echo "</div>";

echo "</body></html>";
