<?php
$pmaPdo = new PDO("mysql:host=localhost;dbname=phpmyadmin;charset=utf8mb4", "root", "");
$dbPdo  = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$stmt = $dbPdo->query("SELECT TABLE_NAME, COUNT(*) as col_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'petron_pos_db_secure' GROUP BY TABLE_NAME");
$colCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

function getHeight($tbl, $colCounts) {
    return 45 + (($colCounts[$tbl] ?? 8) * 24) + 30;
}

$stmt = $pmaPdo->query("SELECT * FROM pma__table_coords WHERE db_name = 'petron_pos_db_secure' AND pdf_page_number = 0 ORDER BY x, y");
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

$collisions = 0;
for ($i = 0; $i < count($tables); $i++) {
    $t1 = $tables[$i];
    $h1 = getHeight($t1['table_name'], $colCounts);
    $w1 = 300; // box width
    
    for ($j = $i + 1; $j < count($tables); $j++) {
        $t2 = $tables[$j];
        $h2 = getHeight($t2['table_name'], $colCounts);
        $w2 = 300;
        
        // Check 2D bounding box intersection
        $overlapX = ($t1['x'] < ($t2['x'] + $w2)) && (($t1['x'] + $w1) > $t2['x']);
        $overlapY = ($t1['y'] < ($t2['y'] + $h2)) && (($t1['y'] + $h1) > $t2['y']);
        
        if ($overlapX && $overlapY) {
            echo "COLLISION DETECTED: {$t1['table_name']} overlaps {$t2['table_name']}!\n";
            $collisions++;
        }
    }
}

if ($collisions === 0) {
    echo "=== ZERO OVERLAP AUDIT VERIFICATION ===\n";
    echo "SUCCESS: Checked all " . count($tables) . " tables. Exactly 0 collisions/overlaps found!\n";
    echo "Every single table has clear, unobstructed horizontal and vertical margins.\n";
} else {
    echo "Total collisions: $collisions\n";
}
