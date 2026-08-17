<?php
require_once __DIR__ . '/../public/db_connect.php';
$cols = $pdo->query("DESCRIBE fuel_inventory")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}
