<?php
require_once __DIR__ . '/../public/db_connect.php';

try {
    $st = $pdo->query("SELECT * FROM stations LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($st);
} catch(Exception $e) { echo $e->getMessage(); }
