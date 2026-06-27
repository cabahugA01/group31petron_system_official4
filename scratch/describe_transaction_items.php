<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== Describe merchandise_transaction_items ===\n";
print_r($pdo->query("DESCRIBE merchandise_transaction_items")->fetchAll(PDO::FETCH_ASSOC));
