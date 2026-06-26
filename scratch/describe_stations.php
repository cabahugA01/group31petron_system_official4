<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "=== Stations Table DESCRIBE ===\n";
foreach ($pdo->query("DESCRIBE stations")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['Field']} - {$r['Type']}\n";
}
echo "\n=== Station Data ===\n";
foreach ($pdo->query("SELECT * FROM stations LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo json_encode($row, JSON_PRETTY_PRINT) . "\n---\n";
}
