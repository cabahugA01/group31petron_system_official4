<?php
require_once __DIR__ . '/../public/db_connect.php';
$stmt = $pdo->exec("UPDATE notifications SET redirect_url = REPLACE(redirect_url, 'manager_shift_transactions.php', 'manager_validated_transactions.php') WHERE redirect_url LIKE '%manager_shift_transactions.php%'");
echo "Updated notifications redirect_url rows: {$stmt}\n";
