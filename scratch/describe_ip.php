<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "--- inventory_products columns ---\n";
$cols = $pdo->query("DESCRIBE inventory_products")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";
