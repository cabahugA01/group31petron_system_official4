<?php
$pdo = new PDO("mysql:host=localhost;charset=utf8mb4", "root", "");
$stmt = $pdo->query("SHOW DATABASES");
$dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Databases:\n";
print_r($dbs);

// Check phpmyadmin tables
if (in_array('phpmyadmin', $dbs)) {
    $stmt = $pdo->query("SHOW TABLES FROM phpmyadmin");
    $pma_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "\nphpmyadmin tables:\n";
    print_r($pma_tables);
}

// Check petron_pos_db_secure tables
$stmt = $pdo->query("SHOW TABLES FROM petron_pos_db_secure");
$petron_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\npetron_pos_db_secure tables (" . count($petron_tables) . " tables):\n";
print_r($petron_tables);
