<?php
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$jo_id = 1;
$station_id = 1253;
$jo_src = 'merchandise_transactions';
$jo_action = 'set_in_progress';

if ($jo_src === 'merchandise_transactions') {
    if ($jo_action === 'set_in_progress') {
        $stmt = $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND station_id=?");
        $stmt->execute([$jo_id, $station_id]);
        echo "Rows affected: " . $stmt->rowCount() . "\n";
    }
}

$row = $pdo->query("SELECT id, workflow_status FROM merchandise_transactions WHERE id=1")->fetch(PDO::FETCH_ASSOC);
print_r($row);
