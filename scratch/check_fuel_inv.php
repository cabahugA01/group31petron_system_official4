<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== fuel_inventory columns ===\n";
$cols = $pdo->query("DESCRIBE fuel_inventory")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) echo "  {$c['Field']} | {$c['Type']} | default:{$c['Default']}\n";

echo "\n=== Sample rows ===\n";
$rows = $pdo->query("SELECT fi.*, ft.name as ft_name FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id=ft.id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) print_r($r);
