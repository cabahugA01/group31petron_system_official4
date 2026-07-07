<?php
require_once __DIR__ . '/public/db_connect.php';
try {  $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);  if (!in_array('remarks', $columns)) {  $pdo->exec("ALTER TABLE users ADD COLUMN remarks TEXT NULL");  echo "Successfully added 'remarks' column to 'users' table.\n";  } else {  echo "'remarks' column already exists in 'users' table.\n";  }
} catch (Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
