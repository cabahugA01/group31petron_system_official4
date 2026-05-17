<?php
require_once __DIR__ . '/db_connect.php';
$cols = $pdo->query("SHOW COLUMNS FROM merchandise_transactions")->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $cols);
