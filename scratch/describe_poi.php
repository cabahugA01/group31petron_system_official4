<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "--- purchase_order_items columns ---\n";
$cols = $pdo->query("DESCRIBE purchase_order_items")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

echo "\n--- sample rows ---\n";
$rows = $pdo->query("SELECT * FROM purchase_order_items LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
