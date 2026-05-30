<?php
require_once __DIR__ . '/public/db_connect.php';
header('Content-Type: text/plain');

try {
    $c1 = $pdo->query("SELECT COUNT(*) FROM accounts_receivable")->fetchColumn();
    $c2 = $pdo->query("SELECT COUNT(*) FROM fuel_variance_reports")->fetchColumn();
    echo "accounts_receivable count: $c1\n";
    echo "fuel_variance_reports count: $c2\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
