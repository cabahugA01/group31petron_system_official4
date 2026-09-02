<?php
$dbPdo  = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$pmaPdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");

$dbName = 'petron_pos_db_secure';

// 1. Get column counts for all tables
$stmt = $dbPdo->query("
    SELECT TABLE_NAME, COUNT(*) as col_count 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = '$dbName' 
    GROUP BY TABLE_NAME
");
$colCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

echo "Table column count sample:\n";
print_r(array_slice($colCounts, 0, 10, true));
