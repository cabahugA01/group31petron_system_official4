<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

echo "=== FUEL INVENTORY ===\n";
try {
    $s = $pdo->query("SELECT * FROM fuel_inventory");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        print_r($r);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== FUEL TYPES ===\n";
try {
    $s = $pdo->query("SELECT * FROM fuel_types");
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        print_r($r);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
