<?php
require_once __DIR__ . '/../public/db_connect.php';
$rows = $pdo->query("SELECT id, po_number, product_name, quantity, status, type, supplier_id FROM purchase_orders WHERE id IN (10,11,12,13,14,15,16)")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
