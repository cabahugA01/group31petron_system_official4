<?php
$pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');
$stmt = $pdo->query('SELECT id, username, first_name, last_name, role, assigned_shift, email FROM users ORDER BY id');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
