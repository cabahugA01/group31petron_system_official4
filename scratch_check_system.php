<?php
$host = "localhost";
$dbname = "petron_pos_db_secure";
$user = "root";
$pass = "";
try {  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);  echo "--- DESCRIBE labor_sessions ---\n";  $s = $pdo->query("DESCRIBE labor_sessions");  print_r($s->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
