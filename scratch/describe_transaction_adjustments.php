<?php
require __DIR__ . '/../public/db_connect.php';

echo "=== Describe transaction_adjustments ===\n";
print_r($pdo->query("DESCRIBE transaction_adjustments")->fetchAll(PDO::FETCH_ASSOC));
