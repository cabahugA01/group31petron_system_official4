<?php
require __DIR__ . '/../public/db_connect.php';
$s = $pdo->query("SHOW COLUMNS FROM purchase_orders");
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . " (" . $r['Type'] . ")\n";
}
