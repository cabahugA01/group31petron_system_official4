<?php
error_reporting(0);
require __DIR__ . '/../public/db_connect.php';
$r = $pdo->query('DESCRIBE customers');
foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $c) {  echo $c['Field'] . ' | ' . $c['Type'] . ' | null=' . $c['Null'] . ' | default=' . $c['Default'] . PHP_EOL;
}
