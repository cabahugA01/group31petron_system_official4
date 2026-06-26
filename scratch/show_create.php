<?php
require_once __DIR__ . '/../public/db_connect.php';
$r = $pdo->query("SHOW CREATE TABLE stock_requests")->fetch(PDO::FETCH_ASSOC);
echo $r['Create Table'] . "\n";
