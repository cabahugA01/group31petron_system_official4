<?php
require_once __DIR__ . '/../public/db_connect.php';
$r = $pdo->query('DESCRIBE job_orders');
foreach($r->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo $c['Field'] . "\n";
}
