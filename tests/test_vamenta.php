<?php
require_once __DIR__ . '/../public/db_connect.php';
header('Content-Type: text/plain');

$s1253 = $pdo->query("SELECT * FROM stations WHERE id = 1253 OR name LIKE '%Vamenta%' OR address LIKE '%Vamenta%'")->fetchAll(PDO::FETCH_ASSOC);
echo "=== VAMENTA / 1253 STATIONS ===\n";
print_r($s1253);

$judy = $pdo->query("SELECT * FROM users WHERE name LIKE '%Judy%'")->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== JUDY ===\n";
print_r($judy);
