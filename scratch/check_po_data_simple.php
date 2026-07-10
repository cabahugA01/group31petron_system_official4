<?php
require_once __DIR__ . '/../public/db_connect.php';

$pos = $pdo->query("SELECT id, po_number, product_name, quantity, unit_price, total_amount, status FROM purchase_orders WHERE type='merch'")->fetchAll(PDO::FETCH_ASSOC);
print_r($pos);
