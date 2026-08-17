<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("SELECT * FROM inventory_logs ORDER BY id DESC LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "inventory_logs rows:\n";
print_r($rows);
