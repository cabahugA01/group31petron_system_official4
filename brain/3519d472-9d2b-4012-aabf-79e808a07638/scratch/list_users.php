<?php
require_once 'c:/xampp/htdocs/group31petron_system_official4/public/db_connect.php';
$stmt = $pdo->query("SELECT id, username, name, role FROM users LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
