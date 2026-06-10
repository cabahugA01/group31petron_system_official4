<?php
require_once __DIR__ . '/db_connect.php';
print_r($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
