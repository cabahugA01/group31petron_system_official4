<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("SELECT id, username, email, role FROM users");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    print_r($row);
}
