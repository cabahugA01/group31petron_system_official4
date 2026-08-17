<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "=== TABLES RELATED TO INVENTORY ===\n";
$stmt = $pdo->query("SHOW TABLES LIKE '%inventory%'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo "  - " . $row[0] . "\n";
}

$stmt = $pdo->query("SHOW TABLES LIKE '%movement%'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo "  - " . $row[0] . "\n";
}

$stmt = $pdo->query("SHOW TABLES LIKE '%stock%'");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo "  - " . $row[0] . "\n";
}
