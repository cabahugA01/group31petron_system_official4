<?php
// Test the full page for runtime errors by simulating session auth
session_start();
$_SESSION['user'] = ['id'=>3, 'name'=>'Edgar Eslit', 'username'=>'Edgar', 'role'=>'manager', 'station_id'=>1253];

$_GET['tab'] = 'pending_requests';

// Capture output and check for errors
ob_start();
try {
    include __DIR__ . '/../public/manager_stock_request_review.php';
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine();
}
$html = ob_get_clean();

// Check for errors
$error_patterns = ['Fatal error', 'Parse error', 'Uncaught Error', 'Undefined variable', 'Call to undefined', 'SQLSTATE'];
$found_errors = [];
foreach ($error_patterns as $pattern) {
    if (stripos($html, $pattern) !== false) {
        preg_match_all('/' . preg_quote($pattern, '/') . '[^\n]{0,200}/i', $html, $matches);
        $found_errors = array_merge($found_errors, $matches[0]);
    }
}

if (empty($found_errors)) {
    echo "PAGE OK — No fatal errors detected\n";
    echo "Page length: " . strlen($html) . " chars\n";
    // Check key elements present
    if (strpos($html, 'Pending Requests') !== false) echo "✓ Tab: Pending Requests found\n";
    if (strpos($html, 'Waiting Delivery') !== false) echo "✓ Tab: Waiting Delivery found\n";
    if (strpos($html, 'Pending Stock-In') !== false) echo "✓ Tab: Pending Stock-In found\n";
    if (strpos($html, 'Completed') !== false) echo "✓ Tab: Completed found\n";
    if (strpos($html, 'Generate Purchase Request') !== false) echo "✓ Generate Purchase Request button found\n";
} else {
    echo "ERRORS FOUND:\n";
    foreach (array_unique($found_errors) as $err) {
        echo "  - " . $err . "\n";
    }
}
