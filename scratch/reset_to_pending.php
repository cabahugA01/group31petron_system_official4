<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");
$pdo->query("UPDATE merchandise_transactions SET workflow_status='Pending' WHERE id=1");
echo "Reset to Pending\n";
