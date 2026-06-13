<?php
require_once __DIR__ . '/../public/db_connect.php';
$station_id = 1253;
$today = date('Y-m-d');
$month_start = date('Y-m-01');

$fin_snap=['total_collected'=>0,'total_payable'=>0,'variance'=>0];
try {
    $qa=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date) BETWEEN ? AND ?");
    $qa->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']=(float)$qa->fetchColumn();
    
    $qb=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(transaction_date,created_at)) BETWEEN ? AND ?");
    $qb->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']+=(float)$qb->fetchColumn();
    
    $qc=$pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM job_orders WHERE station_id=? AND status='Completed' AND DATE(COALESCE(completed_at,created_at)) BETWEEN ? AND ?");
    $qc->execute([$station_id,$month_start,$today]); $fin_snap['total_collected']+=(float)$qc->fetchColumn();
    
    // Payable from purchase_orders
    $qd=$pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE station_id=? AND status NOT IN ('Received','Cancelled','Rejected by Admin','Admin Finalized') AND DATE(created_at) BETWEEN ? AND ?");
    $qd->execute([$station_id,$month_start,$today]); $fin_snap['total_payable']=(float)$qd->fetchColumn();
    
    $fin_snap['variance']=$fin_snap['total_collected']-$fin_snap['total_payable'];
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

print_r($fin_snap);
