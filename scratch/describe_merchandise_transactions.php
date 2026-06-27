<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== Describe merchandise_transactions ===\n";
print_r($pdo->query("DESCRIBE merchandise_transactions")->fetchAll(PDO::FETCH_ASSOC));
