<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

// 1. Check Engines
$stmt = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'petron_pos_db_secure'");
$engines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$non_innodb = [];
foreach ($engines as $tbl => $eng) {
    if (strtoupper($eng) !== 'INNODB') {
        $non_innodb[] = "$tbl ($eng)";
    }
}
echo "=== TABLE ENGINES ===\n";
if (empty($non_innodb)) {
    echo "All " . count($engines) . " tables are using InnoDB engine! (Supports full FK relations & transactions)\n";
} else {
    echo "Non-InnoDB tables found:\n" . implode("\n", $non_innodb) . "\n";
}

// 2. Check Tables without Primary Key
$stmt = $pdo->query("
    SELECT t.TABLE_NAME 
    FROM information_schema.TABLES t
    LEFT JOIN information_schema.KEY_COLUMN_USAGE k 
      ON t.TABLE_SCHEMA = k.TABLE_SCHEMA 
     AND t.TABLE_NAME = k.TABLE_NAME 
     AND k.CONSTRAINT_NAME = 'PRIMARY'
    WHERE t.TABLE_SCHEMA = 'petron_pos_db_secure' 
      AND k.COLUMN_NAME IS NULL
");
$no_pks = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "\n=== TABLES WITHOUT PRIMARY KEY ===\n";
if (empty($no_pks)) {
    echo "All tables have a PRIMARY KEY defined! (1NF compliant)\n";
} else {
    echo "Tables without PK: " . implode(', ', $no_pks) . "\n";
}
