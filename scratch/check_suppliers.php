<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("SELECT id, name FROM suppliers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
