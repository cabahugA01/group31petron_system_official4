<?php
session_start();
require_once __DIR__ . '/db_connect.php';

// Fetch user ID 4 (pepito)
$st = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
$st->execute(['pepito']);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "User pepito not found!\n";
    exit;
}

$_SESSION['user'] = $user;
$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = $user['role'];

// Simulate GET parameters if needed
// $_GET['date_from'] = '2025-07-19';
// $_GET['date_to'] = '2026-07-19';

// Run admin_all_transactions.php
ob_start();
include __DIR__ . '/admin_all_transactions.php';
$html = ob_get_clean();

// Check if "No transactions found" is in the HTML
if (strpos($html, 'No transactions found') !== false) {
    echo "FOUND: 'No transactions found'\n";
} else {
    echo "NOT FOUND: 'No transactions found' (Transactions are visible!)\n";
}

// Find rows
preg_match_all('/<tr><td>(OR-\d{4}-\d+)<\/td>/i', $html, $matches);
echo "Matched OR Numbers: " . print_r($matches[1], true) . "\n";

// Output some of the HTML around the table to see
$table_start = strpos($html, '<table class="t">');
if ($table_start !== false) {
    echo "Table HTML snippet:\n" . substr($html, $table_start, 1000) . "\n";
} else {
    echo "Table not found in HTML!\n";
}
