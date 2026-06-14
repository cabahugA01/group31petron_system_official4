<?php
require_once __DIR__ . '/../public/db_connect.php';
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
