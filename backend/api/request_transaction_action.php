<?php
/**
 * POST /backend/api/request_transaction_action.php
 * Staff submits a Request Void or Request Adjustment for a transaction.
 */
header('Content-Type: application/json');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Not authenticated']); exit; }
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$user_id    = (int) ($me['id'] ?? 0);
if (!in_array($role, ['staff','cashier','pump_attendant','manager','admin'])) {
    http_response_code(403); echo json_encode(['success'=>false,'error'=>'Access denied']); exit;
}

$raw  = file_get_contents('php://input');
$data = $raw ? (json_decode($raw, true) ?? []) : [];
if (!$data) $data = $_POST;

$transaction_id = (int)  ($data['transaction_id'] ?? 0);
$record_source  = trim(   $data['record_source']  ?? 'merchandise_transactions');
$request_type   = trim(   $data['request_type']   ?? '');
$request_reason = trim(   $data['request_reason'] ?? '');
$new_amount     = isset($data['new_amount']) && $data['new_amount'] !== '' ? (float)$data['new_amount'] : null;

if (!$transaction_id)           { echo json_encode(['success'=>false,'error'=>'Transaction ID is required']); exit; }
if (!in_array($request_type, ['Void','Adjustment'])) { echo json_encode(['success'=>false,'error'=>'Invalid request type']); exit; }
if (!$request_reason)           { echo json_encode(['success'=>false,'error'=>'Reason is required']); exit; }
if (!in_array($record_source, ['merchandise_transactions','job_orders'])) $record_source = 'merchandise_transactions';

try {
    if ($record_source === 'job_orders') {
        $chk = $pdo->prepare("SELECT id, status, customer_name FROM job_orders WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_status = $txn['status'] ?? '';
    } else {
        $chk = $pdo->prepare("SELECT id, workflow_status, validation_status, customer_name FROM merchandise_transactions WHERE id = ? AND station_id = ? LIMIT 1");
        $chk->execute([$transaction_id, $station_id]);
        $txn = $chk->fetch(PDO::FETCH_ASSOC);
        $txn_status = $txn['validation_status'] ?? ($txn['workflow_status'] ?? '');
    }
    if (!$txn) { echo json_encode(['success'=>false,'error'=>'Transaction not found']); exit; }
    
    // Disallow only if already voided or cancelled
    $disallowed = ['Voided', 'Cancelled', 'voided', 'cancelled'];
    if (in_array(strtolower($txn_status), ['voided', 'cancelled'])) {
        echo json_encode(['success'=>false,'error'=>"Transaction is already {$txn_status}"]); exit;
    }

    $dup = $pdo->prepare("SELECT id FROM transaction_requests WHERE transaction_id=? AND record_source=? AND request_type=? AND status='Pending' LIMIT 1");
    $dup->execute([$transaction_id, $record_source, $request_type]);
    if ($dup->fetchColumn()) { echo json_encode(['success'=>false,'error'=>"A pending {$request_type} request already exists for this transaction"]); exit; }

    $ins = $pdo->prepare("INSERT INTO transaction_requests (station_id,transaction_id,record_source,request_type,request_reason,requested_by,status,new_amount,requested_at) VALUES (?,?,?,?,?,?,'Pending',?,NOW())");
    $ins->execute([$station_id,$transaction_id,$record_source,$request_type,$request_reason,$user_id,$new_amount]);
    $request_id = $pdo->lastInsertId();

    $customer_name = $txn['customer_name'] ?? ('TXN #'.$transaction_id);
    $staff_name    = $me['full_name'] ?? 'Staff';
    $notif_msg     = "{$staff_name} requested a {$request_type} for {$customer_name}. Reason: {$request_reason}";
    try {
        $cols  = $pdo->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_COLUMN);
        $has_s = in_array('station_id',$cols);
        $has_r = in_array('target_role',$cols);
        $has_l = in_array('link',$cols);
        $f='title,message,type,is_read,created_at'; $v='?,?,?,0,NOW()';
        $p=["Staff {$request_type} Request",$notif_msg,'transaction_request'];
        if($has_s){$f.=',station_id';$v.=',?';$p[]=$station_id;}
        if($has_r){$f.=',target_role';$v.=',?';$p[]='manager';}
        if($has_l){$f.=',link';$v.=',?';$p[]='admin_dashboard.php?section=validation&tr_id='.$request_id;}
        $pdo->prepare("INSERT INTO notifications ({$f}) VALUES ({$v})")->execute($p);
    } catch(Exception $ne){ error_log('notif error: '.$ne->getMessage()); }

    if(function_exists('log_activity')) {
        log_activity($pdo,$user_id,"Request {$request_type}","Req#{$request_id}|{$request_type}|TXN#{$transaction_id}|{$request_reason}");
    }

    echo json_encode(['success'=>true,'request_id'=>(int)$request_id,'message'=>"{$request_type} request submitted! Request ID: #{$request_id}. Status: Pending Manager Review."]);
} catch(Exception $e){
    error_log('req_txn_action: '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Database error: '.$e->getMessage()]);
}