<?php
require_once 'public/db_connect.php';
$s = $pdo->query('SELECT id, username, role, name, status FROM users LIMIT 15');
print_r($s->fetchAll(PDO::FETCH_ASSOC));
