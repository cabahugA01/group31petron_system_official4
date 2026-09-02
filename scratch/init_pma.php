<?php
$pdo = new PDO("mysql:host=localhost;charset=utf8mb4", "root", "");
$pdo->exec("DROP DATABASE IF EXISTS phpmyadmin");
$pdo->exec("CREATE DATABASE phpmyadmin DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE phpmyadmin");

$sql = file_get_contents("C:/xampp/phpmyadmin/sql/create_tables.sql");
$pdo->exec($sql);

echo "phpmyadmin database initialized successfully!\n";

$stmt = $pdo->query("SHOW TABLES FROM phpmyadmin");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
