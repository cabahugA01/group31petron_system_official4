<?php
require_once __DIR__ . '/db_connect.php';
$ft = $pdo->query("SELECT * FROM fuel_types")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>\n";
print_r($ft);
echo "</pre>";
