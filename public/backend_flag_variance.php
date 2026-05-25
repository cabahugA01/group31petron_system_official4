<?php
/**
 * backend_flag_variance.php
 * Handles POST from the "Flag Alert" modal on transactions_variance.php
 * Inserts a new row into variance_alerts for a detected anomaly.
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['manager','admin','superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: transactions_variance.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'flag_variance') {
    header('Location: transactions_variance.php'); exit;
}

$txn_type       = trim($_POST['transaction_type'] ?? '');
$item_identifier= trim($_POST['item_identifier']  ?? '');
$variance_amount= (float)($_POST['variance_amount'] ?? 0);
$user_id        = (int)($_POST['user_id']          ?? 0) ?: null;
$notes          = trim($_POST['notes']             ?? '');

// Validate type — only Merchandise or Job Order (no Fuel)
$valid_types = ['Merchandise', 'Job Order'];
if (!in_array($txn_type, $valid_types)) {
    $_SESSION['error'] = 'Invalid transaction type. Fuel variances are handled separately.';
    header('Location: transactions_variance.php'); exit;
}

if (!$item_identifier || !$notes) {
    $_SESSION['error'] = 'Item identifier and notes are required.';
    header('Location: transactions_variance.php'); exit;
}

// Map 'Job Order' → 'Merchandise' for the ENUM (table only has Fuel/Merchandise)
// We encode the subtype in item_identifier prefix instead
$db_type = ($txn_type === 'Job Order') ? 'Merchandise' : $txn_type;
// Prefix item with [JO] so it's distinguishable in the table
if ($txn_type === 'Job Order') {
    $item_identifier = '[JO] ' . $item_identifier;
}

try {
    $stmt = $pdo->prepare(
        "INSERT INTO variance_alerts
            (station_id, transaction_type, item_identifier, variance_amount,
             status, user_id, investigation_notes, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'open', ?, ?, NOW(), NOW())"
    );
    $stmt->execute([
        $station_id,
        $db_type,
        $item_identifier,
        $variance_amount,
        $user_id,
        $notes,
    ]);
    log_activity($pdo, $me['id'], 'Variance_Flagged',
        "New variance alert flagged: {$item_identifier} by {$me['name']}");
    $_SESSION['success'] = 'Anomaly flagged as a new Variance Alert successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Error flagging alert: ' . $e->getMessage();
}

header('Location: transactions_variance.php'); exit;
