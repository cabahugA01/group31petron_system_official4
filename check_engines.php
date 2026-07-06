<?php
require_once __DIR__ . '/public/db_connect.php';
try {  $stmt = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'petron_pos_db_secure'");  $memory_tables = [];  $innodb_tables = [];  $other_tables = [];  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {  if ($row['ENGINE'] === 'MEMORY') {  $memory_tables[] = $row['TABLE_NAME'];  } elseif ($row['ENGINE'] === 'InnoDB') {  $innodb_tables[] = $row['TABLE_NAME'];  } else {  $other_tables[] = $row['TABLE_NAME'] . " (" . $row['ENGINE'] . ")";  }  }  echo "InnoDB tables: " . count($innodb_tables) . "\n";  echo "Memory tables: " . count($memory_tables) . "\n";  if (count($memory_tables) > 0) {  echo "MEMORY tables list:\n" . implode("\n", $memory_tables) . "\n";  }  echo "Other engine tables: " . count($other_tables) . "\n";  if (count($other_tables) > 0) {  echo "Other engines list:\n" . implode("\n", $other_tables) . "\n";  }
} catch (Exception $e) {  echo "ERROR: " . $e->getMessage() . "\n";
}
