<?php
session_start();
require_once __DIR__ . '/../public/db_connect.php';

// Find a user with 'admin' or 'superadmin' role
$stmt = $pdo->query("SELECT * FROM users WHERE role IN ('admin', 'superadmin') LIMIT 1");
$admin_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin_user) {
    echo "No admin user found in database. Using dummy user.\n";
    $_SESSION['user'] = [
        'id' => 1,
        'username' => 'admin_test',
        'role' => 'admin',
        'station_id' => 1253
    ];
} else {
    echo "Found admin user: " . $admin_user['username'] . " (Station ID: " . $admin_user['station_id'] . ")\n";
    $_SESSION['user'] = $admin_user;
}

$_SERVER['SCRIPT_NAME'] = '/public/admin_purchase_orders.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Turn on all error reporting and display them in buffer
ini_set('display_errors', 1);
error_reporting(E_ALL);

ob_start();
include __DIR__ . '/../public/admin_purchase_orders.php';
$html = ob_get_clean();

// Check for PHP warnings or errors in output (excluding CSS variables)
// Standard PHP warnings in HTML look like: <br /><b>Warning</b>:  ...
// or in CLI: Warning: ...
$has_error = false;
if (preg_match('/<b\s*>Warning<\/b>:/i', $html) || preg_match('/<b\s*>Notice<\/b>:/i', $html) || preg_match('/<b\s*>Fatal error<\/b>:/i', $html)) {
    $has_error = true;
}
if (preg_match('/\bPHP\s+(Warning|Notice|Fatal error|Deprecated|Parse error):/i', $html)) {
    $has_error = true;
}

if ($has_error) {
    echo "Found PHP Warning/Error in output!\n";
    // Find where the warning is
    if (preg_match_all('/(<br\s*\/?>\s*<b>(?:Warning|Notice|Fatal error|Deprecated)<\/b>:.*?)(?:<br\s*\/?>|$)/i', $html, $matches)) {
        foreach ($matches[1] as $m) {
            echo strip_tags($m) . "\n";
        }
    } else {
        // Fallback: print match
        echo "Please check manual output.\n";
    }
} else {
    echo "Page rendered with zero PHP warnings or errors!\n";
}

// Print some stats about the rendered page
$rows_count = substr_count($html, 'class="po-row"');
echo "Number of PO rows rendered: " . $rows_count . "\n";
