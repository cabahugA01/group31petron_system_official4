<?php
$pmaPdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");
$dbPdo  = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$dbName = 'petron_pos_db_secure';

// Get all foreign keys
$stmt = $dbPdo->query("
    SELECT 
        TABLE_NAME, 
        COLUMN_NAME, 
        REFERENCED_TABLE_NAME, 
        REFERENCED_COLUMN_NAME 
    FROM information_schema.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = '$dbName' 
      AND REFERENCED_TABLE_NAME IS NOT NULL
");
$fks = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($fks) . " InnoDB foreign key relationships.\n";

$pmaPdo->prepare("DELETE FROM pma__relation WHERE master_db = ?")->execute([$dbName]);

$ins = $pmaPdo->prepare("
    INSERT IGNORE INTO pma__relation 
    (master_db, master_table, master_field, foreign_db, foreign_table, foreign_field)
    VALUES (?, ?, ?, ?, ?, ?)
");

$count = 0;
foreach ($fks as $fk) {
    $ins->execute([
        $dbName,
        $fk['TABLE_NAME'],
        $fk['COLUMN_NAME'],
        $dbName,
        $fk['REFERENCED_TABLE_NAME'],
        $fk['REFERENCED_COLUMN_NAME']
    ]);
    $count++;
}

echo "Successfully populated $count relations in pma__relation for phpMyAdmin Designer!\n";
