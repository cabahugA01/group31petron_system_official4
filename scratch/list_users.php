<?php
include 'public/db_connect.php';
$stmt = $pdo->query('SELECT * FROM users LIMIT 2');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
