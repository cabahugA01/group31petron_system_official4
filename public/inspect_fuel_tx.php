<?php
require_once __DIR__ . '/db_connect.php';
try {
    $r = $pdo->query('DESCRIBE fuel_transactions')->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>fuel_transactions columns:\n";
    foreach($r as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    // Also let's inspect the customers table columns
    $c = $pdo->query('DESCRIBE customers')->fetchAll(PDO::FETCH_ASSOC);
    echo "\ncustomers columns:\n";
    foreach($c as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    echo "</pre>";
} catch(Exception $e) {
    echo $e->getMessage();
}
?>
