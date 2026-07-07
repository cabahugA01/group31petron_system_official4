<?php
require_once 'public/db_connect.php';

try {  $s = $pdo->query("SELECT * FROM master_data_requests LIMIT 5");  print_r($s->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {  echo "Error: " . $e->getMessage() . "\n";
}
