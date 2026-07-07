<?php
require_once __DIR__ . '/../public/db_connect.php';

$adjustments = [  ['transaction_id' => 'MERCH2026062616435012539615'],  ['transaction_id' => 'MERCH2026062615400912537903']
];

$adj_txn_ids = array_unique(array_column($adjustments, 'transaction_id'));

// Method 1: Quoting with pdo->quote
$adj_txn_ids_str_1 = implode("','", array_map(function($id) use ($pdo) {  return $pdo->quote($id); 
}, $adj_txn_ids));
$sql_1 = "SELECT mt.transaction_id FROM merchandise_transactions mt WHERE mt.transaction_id IN ('$adj_txn_ids_str_1')";

// Method 2: Str_replace single quotes
$adj_txn_ids_str_2 = implode("','", array_map(function($id) {  return str_replace("'", "''", $id); 
}, $adj_txn_ids));
$sql_2 = "SELECT mt.transaction_id FROM merchandise_transactions mt WHERE mt.transaction_id IN ('$adj_txn_ids_str_2')";

echo "SQL 1: " . $sql_1 . "\n";
try {  $stmt1 = $pdo->query($sql_1);  echo "SQL 1 results count: " . count($stmt1->fetchAll()) . "\n";
} catch (Exception $e) {  echo "SQL 1 Error: " . $e->getMessage() . "\n";
}

echo "SQL 2: " . $sql_2 . "\n";
try {  $stmt2 = $pdo->query($sql_2);  echo "SQL 2 results count: " . count($stmt2->fetchAll()) . "\n";
} catch (Exception $e) {  echo "SQL 2 Error: " . $e->getMessage() . "\n";
}
?>
