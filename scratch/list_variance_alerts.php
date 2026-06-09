<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Listing variance_alerts ===\n";
try {
    $rows = $pdo->query("SELECT * FROM variance_alerts LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        print_r($r);
    }
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}
