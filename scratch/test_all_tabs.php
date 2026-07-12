<?php
// Test all 4 tabs
session_start();
$_SESSION['user'] = ['id'=>3, 'name'=>'Edgar Eslit', 'username'=>'Edgar', 'role'=>'manager', 'station_id'=>1253];

$tabs = ['pending_requests', 'waiting_delivery', 'pending_stock_in', 'completed'];
$error_patterns = ['Fatal error', 'Parse error', 'Uncaught Error', 'SQLSTATE['];

foreach ($tabs as $tab) {
    $_GET['tab'] = $tab;
    ob_start();
    try {
        include __DIR__ . '/../public/manager_stock_request_review.php';
    } catch (Throwable $e) {
        echo "FATAL: " . $e->getMessage();
    }
    $html = ob_get_clean();

    $found = false;
    foreach ($error_patterns as $pattern) {
        if (stripos($html, $pattern) !== false) {
            preg_match('/' . preg_quote($pattern, '/') . '[^\n]{0,200}/i', $html, $m);
            echo "  ❌ TAB[$tab]: " . ($m[0] ?? $pattern) . "\n";
            $found = true;
        }
    }
    if (!$found) {
        echo "✅ TAB[$tab]: OK (" . strlen($html) . " chars)\n";
    }
}
