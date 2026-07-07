<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->query("DESCRIBE customers");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . ' (' . $row['Type'] . ")\n";
}
