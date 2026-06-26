<?php
require __DIR__ . '/../public/db_connect.php';
$s = $pdo->query("SELECT action, COUNT(*) as c FROM inventory_logs GROUP BY action");
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['action'] . ": " . $r['c'] . "\n";
}
echo "--- Reference Types ---\n";
$s = $pdo->query("SELECT reference_type, COUNT(*) as c FROM inventory_logs GROUP BY reference_type");
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['reference_type'] . ": " . $r['c'] . "\n";
}
