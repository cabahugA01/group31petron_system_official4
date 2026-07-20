<?php
require_once __DIR__ . '/db_connect.php';
echo "<pre>";

$rows = $pdo->query("SELECT id, transaction_id, station_id, transaction_date, created_at FROM merchandise_transactions WHERE station_id=1253 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "Transactions for station 1253:\n";
foreach ($rows as $r) {
    $eff = ($r['transaction_date'] && $r['transaction_date'] > '2000-01-01') ? $r['transaction_date'] : $r['created_at'];
    echo "  ID={$r['id']} txn={$r['transaction_id']} txn_date={$r['transaction_date']} created_at={$r['created_at']} effective={$eff}\n";
}

// Test the exact WHERE clause
$date_from = date('Y-m-d', strtotime('-365 days'));
$date_to   = date('Y-m-d');
$mt_date   = "CASE WHEN mt.transaction_date > '2000-01-01' THEN mt.transaction_date ELSE mt.created_at END";

$cnt = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.station_id=? AND DATE($mt_date) BETWEEN ? AND ?");
$cnt->execute([1253, $date_from, $date_to]);
echo "\nCount with date filter ($date_from to $date_to): " . $cnt->fetchColumn() . "\n";

$cnt2 = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.station_id=?");
$cnt2->execute([1253]);
echo "Count without date filter: " . $cnt2->fetchColumn() . "\n";
echo "</pre>";
