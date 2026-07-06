<?php
try {  $pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');  // Check columns of users table  $stmt = $pdo->query("SHOW COLUMNS FROM users");  echo "Columns in users table:\n";  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {  echo "- {$row['Field']} ({$row['Type']})\n";  }  echo "\nUser details with station info:\n";  $stmt = $pdo->query('SELECT * FROM users');  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {  echo "ID: {$row['id']} | Username: {$row['username']} | Role: {$row['role']} | Station ID: " . ($row['station_id'] ?? 'NULL') . "\n";  }
} catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
