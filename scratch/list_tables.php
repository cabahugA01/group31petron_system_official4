<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== Listing all tables in database ===\n";
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "- " . $t . "\n";
}
