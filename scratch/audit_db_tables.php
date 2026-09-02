<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

// Get all tables and row counts
$stmt = $pdo->query("SHOW TABLES");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Total tables: " . count($tables) . "\n\n";

$table_info = [];
foreach ($tables as $t) {
    $cStmt = $pdo->query("SELECT COUNT(*) FROM `$t`");
    $rowCount = $cStmt->fetchColumn();
    
    // Get foreign keys
    $fkStmt = $pdo->query("
        SELECT 
            COLUMN_NAME, 
            REFERENCED_TABLE_NAME, 
            REFERENCED_COLUMN_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'petron_pos_db_secure' 
          AND TABLE_NAME = '$t' 
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $fks = $fkStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get primary keys
    $pkStmt = $pdo->query("
        SELECT COLUMN_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = 'petron_pos_db_secure' 
          AND TABLE_NAME = '$t' 
          AND CONSTRAINT_NAME = 'PRIMARY'
    ");
    $pks = $pkStmt->fetchAll(PDO::FETCH_COLUMN);

    $table_info[$t] = [
        'rows' => $rowCount,
        'pks' => $pks,
        'fks' => $fks
    ];
}

echo "=== TABLES AND FOREIGN KEYS AUDIT ===\n";
foreach ($table_info as $t => $info) {
    echo "Table: $t (Rows: {$info['rows']}, PK: " . implode(',', $info['pks']) . ")\n";
    if (!empty($info['fks'])) {
        foreach ($info['fks'] as $fk) {
            echo "   -> FK: {$fk['COLUMN_NAME']} references {$fk['REFERENCED_TABLE_NAME']}({$fk['REFERENCED_COLUMN_NAME']})\n";
        }
    }
}
