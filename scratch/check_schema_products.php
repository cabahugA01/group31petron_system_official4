<?php
require_once __DIR__ . '/../public/db_connect.php';
$cols = $pdo->query("DESCRIBE inventory_products")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "{$col['Field']} - {$col['Type']}\n";
}
