<?php
// Test set_in_progress POST logic directly
session_start();
$_SESSION['user'] = ['id' => 1, 'username' => 'staff_user', 'role' => 'staff', 'station_id' => 1];
$_SESSION['station_id'] = 1;

$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$jo_id = 1;
$station_id = 1;
$jo_src = 'merchandise_transactions';
$jo_action = 'set_in_progress';

if ($jo_src === 'merchandise_transactions') {
    if ($jo_action === 'set_in_progress') {
        $stmt = $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?");
        $stmt->execute([$jo_id, $station_id]);
        echo "Rows affected: " . $stmt->rowCount() . "\n";
        $_SESSION['success'] = 'Job Order marked as In Progress.';
    }
}

$row = $pdo->query("SELECT id, workflow_status FROM merchandise_transactions WHERE id=1")->fetch(PDO::FETCH_ASSOC);
print_r($row);
