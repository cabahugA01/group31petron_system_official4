<?php
// Simulate staff POST request for set_in_progress
$pdo = new PDO("mysql:host=localhost;dbname=petron_pos_db_secure;charset=utf8mb4", "root", "");

$jo_id = 1;
$station_id = 1253;
$jo_src = 'merchandise_transactions';
$jo_action = 'set_in_progress';

if ($jo_src === 'merchandise_transactions') {
    if ($jo_action === 'set_in_progress') {
        $stmt = $pdo->prepare("UPDATE merchandise_transactions SET workflow_status='In Progress', updated_at=NOW() WHERE id=? AND (station_id=? OR ?=0)");
        $stmt->execute([$jo_id, $station_id, $station_id]);
        echo "Rows updated in merchandise_transactions: " . $stmt->rowCount() . "\n";
    }
}

$row = $pdo->query("SELECT id, transaction_id, customer_name, job_order_service, total_amount, payment_status, validation_status, workflow_status FROM merchandise_transactions WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "Current DB State:\n";
print_r($row);
