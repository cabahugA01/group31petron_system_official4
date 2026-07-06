<?php
require_once __DIR__ . '/public/db_connect.php';

try {  $sql = file_get_contents(__DIR__ . '/database/migrations/add_station_coordinates.sql');  // Remove comments  $sql = preg_replace('/--.*\n/', '', $sql);  // Split queries by semicolon (simple splitter)  $queries = explode(';', $sql);  foreach ($queries as $query) {  $q = trim($query);  if (!empty($q)) {  $pdo->exec($q);  }  }  echo "Migration applied successfully!\n";
} catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
