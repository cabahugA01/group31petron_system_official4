<?php
require_once __DIR__ . '/../public/db_connect.php';
$rows = $pdo->query("SELECT poi.id, poi.po_id, poi.item_name, poi.quantity, poi.product_id FROM purchase_order_items poi WHERE poi.po_id IN (10,11,12,13,14,15,16)")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
