<?php
require_once __DIR__ . '/../public/db_connect.php';

$r = $pdo->query("SHOW TABLES LIKE '%adjust%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Adjust tables: "; print_r($r);

$r2 = $pdo->query("SHOW TABLES LIKE '%inventory%'")->fetchAll(PDO::FETCH_COLUMN);
echo "Inventory tables: "; print_r($r2);

$acts = $pdo->query("SELECT DISTINCT action FROM inventory_logs")->fetchAll(PDO::FETCH_COLUMN);
echo "Log actions: "; print_r($acts);

$p = $pdo->query("SELECT DISTINCT brand FROM products WHERE station_id=1253 AND brand IS NOT NULL AND brand != '' LIMIT 15")->fetchAll(PDO::FETCH_COLUMN);
echo "Brands: "; print_r($p);

$c = $pdo->query("SELECT DISTINCT p.category_id, pc.name FROM products p LEFT JOIN product_categories pc ON p.category_id=pc.id WHERE p.station_id=1253 LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
echo "Categories: "; print_r($c);

// Count inventory_logs
$cnt = $pdo->query("SELECT COUNT(*) FROM inventory_logs WHERE station_id=1253")->fetchColumn();
echo "Inventory logs count for station 1253: $cnt\n";

// Check if there's a physical_count or adjustment action
$acts2 = $pdo->query("SELECT DISTINCT action, reference_type FROM inventory_logs LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
echo "All log action+reftype: "; print_r($acts2);
