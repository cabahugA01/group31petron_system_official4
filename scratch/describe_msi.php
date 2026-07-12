<?php
require_once __DIR__ . '/../public/db_connect.php';
echo "\n*** Table: merchandise_stock_in ***\n";
$q = $pdo->query("DESCRIBE merchandise_stock_in");
while($r = $q->fetch(PDO::FETCH_ASSOC)) {
    echo "  {$r['Field']} - {$r['Type']}\n";
}
