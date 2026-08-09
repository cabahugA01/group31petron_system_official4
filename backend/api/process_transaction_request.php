<?php
/**
 * POST /backend/api/process_transaction_request.php
 * Manager approves or rejects a staff transaction request (Void or Adjustment)
 */
header('Content-Type: application/json');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$me = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Manager access required.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input.']);
    exit;
}

$request_id = (int)($data['request_id'] ?? 0);
$action = strtolower(trim($data['action'] ?? ''));
$review_remarks = trim($data['review_remarks'] ?? '');

if ($request_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing or invalid request ID.']);
    exit;
}

if (!in_array($action, ['approve', 'reject'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid action. Must be approve or reject.']);
    exit;
}

try {
    // Check if request exists
    $stmt = $pdo->prepare("SELECT * FROM transaction_requests WHERE id = ?");
    $stmt->execute([$request_id]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        echo json_encode(['success' => false, 'error' => 'Transaction request not found.']);
        exit;
    }

    if ($req['status'] !== 'Pending') {
        echo json_encode(['success' => false, 'error' => "This request is already {$req['status']}."]);
        exit;
    }

    $new_status = ($action === 'approve') ? 'Approved' : 'Rejected';
    $user_id = (int)($me['id'] ?? 0);

    $upd = $pdo->prepare("UPDATE transaction_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ? WHERE id = ?");
    $upd->execute([$new_status, $user_id, $review_remarks, $request_id]);

    // Send notification to staff member who requested
    if (!empty($req['requested_by'])) {
        $msg = "Your {$req['request_type']} request for Transaction #{$req['transaction_id']} was {$new_status} by Manager.";
        if (!empty($review_remarks)) {
            $msg .= " Remarks: {$review_remarks}";
        }
        $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at) VALUES (?, 'info', ?, ?, 'staff_transactions_hub.php', 0, NOW())");
        $notif->execute([(int)$req['requested_by'], "Request {$new_status}", $msg]);
    }

    // Log activity
    log_activity($pdo, $user_id, "Transaction Request {$new_status}", "Req#{$request_id}|{$req['request_type']}|TXN#{$req['transaction_id']}");

    echo json_encode([
        'success' => true,
        'message' => "Request has been successfully {$new_status}."
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
