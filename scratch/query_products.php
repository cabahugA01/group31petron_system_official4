<?php
require_once __DIR__ . '/../public/db_connect.php';
$items = $pdo->query("SELECT id, product_name, category, supplier, size, sku FROM inventory_products LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
print_r($items);
