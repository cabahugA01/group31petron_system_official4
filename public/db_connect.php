<?php
global $pdo;

$host = "localhost";
$dbname = "petron_pos_db_secure";
$user = "root";
$pass = ""; // XAMPP default is empty

try {
  $pdo = new PDO(
    "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  die("DB connection failed: " . $e->getMessage());
}
