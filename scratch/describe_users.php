<?php
require_once __DIR__ . '/../public/db_connect.php';
print_r($pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC));
