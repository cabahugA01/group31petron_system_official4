<?php
require 'public/db_connect.php';
print_r($pdo->query("SHOW COLUMNS FROM purchase_orders")->fetchAll(PDO::FETCH_ASSOC));
print_r($pdo->query("SELECT * FROM purchase_orders LIMIT 1")->fetch(PDO::FETCH_ASSOC));
