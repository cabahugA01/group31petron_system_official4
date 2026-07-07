<?php
$host = "localhost";
$dbname = "petron_pos_db_secure";
$user = "root";
$pass = "";
try {  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);  // Get Judy's station  $stmt = $pdo->prepare("SELECT station_id FROM users WHERE username = 'Judy'");  $stmt->execute();  $station_id = (int)$stmt->fetchColumn();  if (!$station_id) {  $station_id = 1;  }  // Ensure labor session exists for Judy  $check_sess = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = 2 AND end_time IS NULL");  $check_sess->execute();  if (!$check_sess->fetchColumn()) {  $pdo->prepare("INSERT INTO labor_sessions (user_id, station_id, start_time, shift_name, shift_period) VALUES (2, ?, NOW(), 'Shift 1', 'first')")->execute([$station_id]);  echo "Created active labor session for Judy (Shift 1)\n";  } else {  echo "Judy already has an active session.\n";  }

} catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
