<?php
require_once __DIR__ . '/../public/db_connect.php';

$stmt = $pdo->query("SELECT * FROM payment_method_config WHERE is_active = 1 ORDER BY sort_order");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
