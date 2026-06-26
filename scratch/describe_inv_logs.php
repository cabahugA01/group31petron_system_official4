<?php
require_once __DIR__ . '/../public/db_connect.php';
$r = $pdo->query("DESCRIBE inventory_logs");
foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . "\n";
}
