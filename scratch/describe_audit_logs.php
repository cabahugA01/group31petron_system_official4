<?php
require_once __DIR__ . '/../public/db_connect.php';
$s = $pdo->query('DESCRIBE audit_logs');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
