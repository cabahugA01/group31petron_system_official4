<?php
require_once __DIR__ . '/../public/db_connect.php';
$cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo $c['Field'] . "\n";
echo "\n--- fuel_transactions ---\n";
$cols2 = $pdo->query('DESCRIBE fuel_transactions')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols2 as $c) echo $c['Field'] . "\n";
