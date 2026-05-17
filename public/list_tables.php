<?php
require_once __DIR__ . '/db_connect.php';
print_r($pdo->query('SHOW COLUMNS FROM fuel_transactions')->fetchAll(PDO::FETCH_COLUMN));
