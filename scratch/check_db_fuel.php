<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL TYPES ===\n";
try {
    $s = $pdo->query('SELECT * FROM fuel_types');
    print_r($s->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage(); }

echo "=== FUEL INVENTORY ===\n";
try {
    $s = $pdo->query('SELECT * FROM fuel_inventory');
    print_r($s->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage(); }
