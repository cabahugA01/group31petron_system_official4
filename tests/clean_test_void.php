<?php
require_once __DIR__ . '/../public/db_connect.php';
$pdo->exec("DELETE FROM transaction_requests WHERE request_reason LIKE '%Staff Encoding Error%'");
echo "Cleaned test requests from database.\n";
