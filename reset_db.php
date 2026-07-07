<?php
// We connect to mysql without a dbname selected first
$host = "localhost";
$user = "root";
$pass = "";

try {  $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);  echo "Dropping database petron_pos_db_secure...\n";  $pdo->exec("DROP DATABASE IF EXISTS petron_pos_db_secure");  echo "Recreating database petron_pos_db_secure...\n";  $pdo->exec("CREATE DATABASE petron_pos_db_secure");  echo "Database reset successfully!\n";
} catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
