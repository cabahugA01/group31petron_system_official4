<?php
require __DIR__ . '/../public/db_connect.php';
echo "=== voided_transactions columns ===\n";
foreach ($pdo->query("DESCRIBE voided_transactions")->fetchAll(PDO::FETCH_ASSOC) as $col) {
    echo $col['Field'] . " (" . $col['Type'] . ")\n";
}
echo "\n=== Sample row ===\n";
$row = $pdo->query("SELECT * FROM voided_transactions LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    foreach ($row as $k => $v) echo "$k: $v\n";
} else {
    echo "No rows found.\n";
}
