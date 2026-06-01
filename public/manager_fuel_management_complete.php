<?php
$page_id = match($_GET['tab'] ?? '') {
    'deliveries'    => 'fuel_deliveries_validation',
    'transactions'  => 'fuel_transactions_oversight',
    'reconciliation'=> 'fuel_reconciliation',
    'adjustments'   => 'fuel_adjustments',
    default         => 'fuel_transactions_oversight',
};
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/manager_fuel_config.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// -"-"- Module gate -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('fuel_management')) {
    render_module_disabled_page('Fuel Management');
}

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

$config        = getManagerFuelConfig($pdo, $station_id);
$business_rules = $config->getBusinessRules();
$ui_config     = $config->getUIConfig();
$colors        = $config->getColors();
$suppliers     = $config->getSuppliers();

$msg      = '';
$msg_type = 'success';
if (isset($_SESSION['success'])) { $msg = $_SESSION['success']; $msg_type = 'success'; unset($_SESSION['success']); }
if (isset($_SESSION['error']))   { $msg = $_SESSION['error'];   $msg_type = 'error';   unset($_SESSION['error']); }

/* -------------------------------------------------------------
   POST HANDLERS
------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        /* -- CALIBRATION UPDATE -- */
        case 'update_calibration':
            $fuel_type     = trim($_POST['fuel_type'] ?? '');
            $new_cal       = (float)($_POST['new_calibration'] ?? -1);
            try {
                if (empty($fuel_type))          throw new Exception('Fuel type is required.');
                if ($new_cal < 0 || $new_cal > 50) throw new Exception('Calibration value must be between 0 and 50 liters.');

                // 1. Update fuel_inventory.latest_calibration
                $stmt = $pdo->prepare("
                    UPDATE fuel_inventory
                    SET latest_calibration = ?, last_updated = NOW()
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ");
                $stmt->execute([$new_cal, $station_id, $fuel_type]);

                // 2. Update fuel_pumps.calibration_value for all pumps of this fuel type at this station
                $stmt2 = $pdo->prepare("
                    UPDATE fuel_pumps fp
                    JOIN fuel_inventory fi ON fp.fuel_type_id = fi.fuel_type_id AND fp.station_id = fi.station_id
                    SET fp.calibration_value = ?,
                        fp.calibration_updated_by = ?,
                        fp.calibration_updated_at = NOW()
                    WHERE fp.station_id = ? AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(?))
                ");
                $stmt2->execute([$new_cal, $me['id'], $station_id, $fuel_type]);

                log_activity($pdo, $me['id'], 'Update Calibration', "Set calibration for {$fuel_type} to {$new_cal} L at station {$station_id}");

                $_SESSION['success'] = "Calibration for <strong>" . htmlspecialchars($fuel_type) . "</strong> updated to <strong>{$new_cal} L</strong>.";
            } catch (Exception $e) {
                $_SESSION['error'] = "Calibration update failed: " . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#pump-master');
            exit;

        /* -- VALIDATE READING -- */
        case 'validate_reading':
            $reading_id     = $_POST['reading_id'] ?? '';
            $status         = $_POST['status'] ?? '';
            $notes          = trim($_POST['notes'] ?? '');
            $variance_liters = (float)($_POST['variance_liters'] ?? 0);
            try {
                if (empty($notes)) throw new Exception('Manager notes are required for validation.');
                $stmt = $pdo->prepare("SELECT ft.*, u.name as staff_name FROM fuel_transactions ft JOIN users u ON ft.staff_id=u.id WHERE ft.transaction_id=? AND ft.station_id=?");
                $stmt->execute([$reading_id, $station_id]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$transaction) throw new Exception('Transaction not found.');

                // Allow action on any pending-variant status (case-insensitive)
                $cur_status = strtolower(trim($transaction['status'] ?? ''));
                if (!str_contains($cur_status, 'pending')) {
                    throw new Exception('This transaction has already been processed (status: ' . htmlspecialchars($transaction['status']) . ').');
                }

                // Normalize status value to canonical form
                $new_status = in_array(strtolower($status), ['verified','approved']) ? 'Verified' : 'Rejected';

                $pdo->beginTransaction();

                // Update transaction status — use $new_status (canonical casing)
                $pdo->prepare("UPDATE fuel_transactions SET status=?, validated_by=?, validated_at=NOW(), reject_reason=? WHERE transaction_id=? AND station_id=?")->execute([
                    $new_status,
                    $me['id'],
                    $new_status === 'Rejected' ? $notes : null,
                    $reading_id,
                    $station_id,
                ]);

                if ($new_status === 'Verified') {
                    // -- System Update: deduct liters from tank level --
                    $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = GREATEST(0, COALESCE(current_level, current_stock, 0) - ?),
                            current_stock = GREATEST(0, COALESCE(current_stock, current_level, 0) - ?),
                            last_updated  = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ")->execute([$transaction['liters_sold'], $transaction['liters_sold'], $station_id, $transaction['fuel_type']]);

                    // -- Audit trail entry --
                    $pdo->prepare("
                        INSERT INTO fuel_adjustments (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                        SELECT ?, fuel_type_id, 'verified_sale', ?, ?, ?, CURDATE()
                        FROM fuel_inventory WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))
                        LIMIT 1
                    ")->execute([
                        $station_id,
                        -abs($transaction['liters_sold']),
                        "Approved by manager. Reading #{$reading_id}. Notes: {$notes}",
                        $me['id'],
                        $station_id,
                        $transaction['fuel_type']
                    ]);

                    // -- Auto-flag variance report if >5% --
                    if (abs($variance_liters) > 0.1) {
                        $vp = $transaction['liters_sold'] > 0 ? ($variance_liters / $transaction['liters_sold']) * 100 : 0;
                        if (abs($vp) > 5) {
                            $pdo->prepare("INSERT INTO fuel_variance_reports (station_id,report_date,fuel_type,expected_stock,actual_stock,variance_liters,variance_percent,reason,status,created_at,updated_at) VALUES (?,CURDATE(),?,?,?,?,?,?,'Open',NOW(),NOW())")->execute([
                                $station_id,
                                $transaction['fuel_type'],
                                $transaction['liters_sold'],
                                $transaction['liters_sold'] - $variance_liters,
                                $variance_liters,
                                $vp,
                                "Auto-flagged: variance {$variance_liters} L ({$vp}%) on reading #{$reading_id}"
                            ]);
                        }
                    }
                } else {
                    // -- Rejected: log for staff correction --
                    $pdo->prepare("
                        INSERT INTO fuel_adjustments (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                        SELECT ?, fuel_type_id, 'rejected_reading', 0, ?, ?, CURDATE()
                        FROM fuel_inventory WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))
                        LIMIT 1
                    ")->execute([
                        $station_id,
                        "REJECTED by manager. Reading #{$reading_id}. Reason: {$notes}",
                        $me['id'],
                        $station_id,
                        $transaction['fuel_type']
                    ]);
                }

                log_activity($pdo, $me['id'], 'Validate Transaction', "Transaction #{$reading_id} {$new_status}. Variance: {$variance_liters} L. Notes: {$notes}");
                $pdo->commit();

                // -"-"- Audit log -"-"-
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $action_type = $new_status === 'Verified' ? 'Approve' : 'Reject';
                    $detail = "Fuel transaction {$new_status} | TXN: #{$reading_id} | {$transaction['fuel_type']} | {$transaction['liters_sold']} L | Variance: {$variance_liters} L | Notes: {$notes}";
                    $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', ?, ?, 'fuel_readings', ?, 'Success', ?, ?, NOW())")
                        ->execute([$me['id'], $action_type, $detail, $transaction['id'] ?? null, $ip, $ua]);
                } catch (Exception $e) {}

                // -- Notify staff member of the outcome --
                try {
                    if (!empty($transaction['staff_id'])) {
                        $notif_title = $new_status === 'Verified'
                            ? 'Fuel Reading Approved'
                            : 'Fuel Reading Rejected';
                        $notif_msg = $new_status === 'Verified'
                            ? "Your fuel reading #{$reading_id} ({$transaction['fuel_type']}, {$transaction['liters_sold']} L) has been approved by the manager."
                            : "Your fuel reading #{$reading_id} ({$transaction['fuel_type']}) was rejected. Reason: {$notes}";
                        $notif_severity = $new_status === 'Verified' ? 'low' : 'high';
                        $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, redirect_url, created_at)
                            VALUES (?, 'info', ?, ?, 'fuel_reading', ?, ?, 'staff_fuel_readings.php', NOW())
                        ")->execute([
                            $transaction['staff_id'],
                            $notif_title,
                            $notif_msg,
                            $notif_severity,
                            'fuel_txn_' . $reading_id . '_' . strtolower($new_status),
                        ]);
                    }
                } catch (Exception $ne) {
                    error_log("Transaction notification failed: " . $ne->getMessage());
                }

                if ($new_status === 'Verified') {
                    $_SESSION['success'] = "Transaction approved successfully. Entry saved to Daily Sales Summary.";
                } else {
                    $_SESSION['success'] = "Transaction #{$reading_id} rejected and flagged for staff correction.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php?tab=transactions'); exit;

        /* -- ADJUST READING (Manager corrects liters_sold before approving) -- */
        case 'adjust_reading':
            $reading_id      = $_POST['reading_id'] ?? '';
            $adjusted_liters = (float)($_POST['adjusted_liters'] ?? 0);
            $adj_reason      = trim($_POST['adj_reason'] ?? '');
            try {
                if ($adjusted_liters <= 0)
                    throw new Exception('Adjusted liters must be greater than 0.');
                if (strlen($adj_reason) < 5)
                    throw new Exception('A reason for the adjustment is required.');

                $stmt = $pdo->prepare("SELECT ft.*, u.name as staff_name FROM fuel_transactions ft JOIN users u ON ft.staff_id=u.id WHERE ft.transaction_id=? AND ft.station_id=?");
                $stmt->execute([$reading_id, $station_id]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$transaction) throw new Exception('Transaction not found.');
                if (!str_contains(strtolower($transaction['status'] ?? ''), 'pending'))
                    throw new Exception('This transaction has already been processed.');

                $original_liters = (float)$transaction['liters_sold'];

                $pdo->beginTransaction();

                // Update liters_sold to adjusted value, mark as Verified
                $pdo->prepare("
                    UPDATE fuel_transactions
                    SET liters_sold = ?, status = 'Verified', validated_by = ?, validated_at = NOW()
                    WHERE transaction_id = ? AND station_id = ?
                ")->execute([$adjusted_liters, $me['id'], $reading_id, $station_id]);

                // Deduct adjusted liters from tank inventory
                $pdo->prepare("
                    UPDATE fuel_inventory
                    SET current_level = GREATEST(0, COALESCE(current_level, current_stock, 0) - ?),
                        current_stock = GREATEST(0, COALESCE(current_stock, current_level, 0) - ?),
                        last_updated  = NOW()
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ")->execute([$adjusted_liters, $adjusted_liters, $station_id, $transaction['fuel_type']]);

                // Audit trail
                $audit_reason = substr("ADJUSTED by manager. Reading #{$reading_id}. Original: {$original_liters} L ? Adjusted: {$adjusted_liters} L. Reason: {$adj_reason}", 0, 255);
                $pdo->prepare("
                    INSERT INTO fuel_adjustments (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                    SELECT ?, fuel_type_id, 'adjusted_reading', ?, ?, ?, CURDATE()
                    FROM fuel_inventory WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))
                    LIMIT 1
                ")->execute([
                    $station_id,
                    -abs($adjusted_liters),
                    $audit_reason,
                    $me['id'],
                    $station_id,
                    $transaction['fuel_type']
                ]);

                log_activity($pdo, $me['id'], 'Adjust Transaction',
                    "Transaction #{$reading_id} adjusted: {$original_liters} L → {$adjusted_liters} L. Reason: {$adj_reason}");
                $pdo->commit();

                // -- Notify staff of the adjustment --
                try {
                    if (!empty($transaction['staff_id'])) {
                        $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, redirect_url, created_at)
                            VALUES (?, 'info', 'Fuel Reading Adjusted & Approved', ?, 'fuel_reading', 'medium', ?, 'staff_fuel_readings.php', NOW())
                        ")->execute([
                            $transaction['staff_id'],
                            "Your fuel reading #{$reading_id} ({$transaction['fuel_type']}) was adjusted from {$original_liters} L to {$adjusted_liters} L and approved. Reason: {$adj_reason}",
                            'fuel_txn_' . $reading_id . '_adjusted',
                        ]);
                    }
                } catch (Exception $ne) {
                    error_log("Adjust notification failed: " . $ne->getMessage());
                }

                $_SESSION['success'] = "✓ Transaction #{$reading_id} adjusted to {$adjusted_liters} L and approved.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#fuel-transactions'); exit;

        /* -- APPROVE DAILY LOG -- */
        case 'approve_daily_log':
            $txn_id = $_POST['txn_id'] ?? '';
            $mgr_notes = trim($_POST['mgr_notes'] ?? '');
            try {
                if (empty($mgr_notes)) throw new Exception('Manager notes are required.');
                $stmt = $pdo->prepare("SELECT ft.*, u.name as staff_name FROM fuel_transactions ft JOIN users u ON ft.staff_id=u.id WHERE ft.transaction_id=? AND ft.station_id=?");
                $stmt->execute([$txn_id, $station_id]);
                $txn = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$txn) throw new Exception('Transaction not found.');
                if (!str_contains(strtolower($txn['status'] ?? ''), 'pending'))
                    throw new Exception('This entry has already been processed.');

                $pdo->beginTransaction();

                // Mark as verified
                $pdo->prepare("UPDATE fuel_transactions SET status='Verified', validated_by=?, validated_at=NOW() WHERE transaction_id=? AND station_id=?")->execute([$me['id'], $txn_id, $station_id]);

                // Deduct liters from tank inventory
                $pdo->prepare("
                    UPDATE fuel_inventory
                    SET current_level = GREATEST(0, COALESCE(current_level, current_stock, 0) - ?),
                        current_stock = GREATEST(0, COALESCE(current_stock, current_level, 0) - ?),
                        last_updated  = NOW()
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ")->execute([$txn['liters_sold'], $txn['liters_sold'], $station_id, $txn['fuel_type']]);

                // Audit trail
                $reason = substr("Daily log approved. Txn #{$txn_id}. Notes: {$mgr_notes}", 0, 255);
                $pdo->prepare("
                    INSERT INTO fuel_adjustments (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                    SELECT ?, fuel_type_id, 'daily_log_approved', ?, ?, ?, CURDATE()
                    FROM fuel_inventory WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?)) LIMIT 1
                ")->execute([$station_id, -abs($txn['liters_sold']), $reason, $me['id'], $station_id, $txn['fuel_type']]);

                log_activity($pdo, $me['id'], 'Approve Daily Log', "Txn #{$txn_id} approved. {$txn['liters_sold']} L of {$txn['fuel_type']}. Notes: {$mgr_notes}");
                $pdo->commit();
                $_SESSION['success'] = "? Daily log #{$txn_id} approved. Tank levels and sales summary updated.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#daily-ops'); exit;

        /* -- REJECT DAILY LOG -- */
        case 'reject_daily_log':
            $txn_id    = $_POST['txn_id'] ?? '';
            $rej_notes = trim($_POST['rej_notes'] ?? '');
            try {
                if (empty($rej_notes)) throw new Exception('Rejection reason is required.');
                $stmt = $pdo->prepare("SELECT ft.* FROM fuel_transactions ft WHERE ft.transaction_id=? AND ft.station_id=?");
                $stmt->execute([$txn_id, $station_id]);
                $txn = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$txn) throw new Exception('Transaction not found.');
                if (!str_contains(strtolower($txn['status'] ?? ''), 'pending'))
                    throw new Exception('This entry has already been processed.');

                $pdo->beginTransaction();

                // Mark as rejected
                $pdo->prepare("UPDATE fuel_transactions SET status='Rejected', validated_by=?, validated_at=NOW() WHERE transaction_id=? AND station_id=?")->execute([$me['id'], $txn_id, $station_id]);

                // Audit trail  -  no inventory change on reject
                $reason = substr("Daily log REJECTED. Txn #{$txn_id}. Reason: {$rej_notes}", 0, 255);
                $pdo->prepare("
                    INSERT INTO fuel_adjustments (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                    SELECT ?, fuel_type_id, 'daily_log_rejected', 0, ?, ?, CURDATE()
                    FROM fuel_inventory WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?)) LIMIT 1
                ")->execute([$station_id, $reason, $me['id'], $station_id, $txn['fuel_type']]);

                log_activity($pdo, $me['id'], 'Reject Daily Log', "Txn #{$txn_id} rejected. Reason: {$rej_notes}");
                $pdo->commit();
                $_SESSION['success'] = "?? Daily log #{$txn_id} rejected and returned to Staff for correction.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#daily-ops'); exit;

        /* -- RECORD DELIVERY -- */
        case 'record_delivery':
            $fuel_type       = trim($_POST['fuel_type_name'] ?? '');
            $delivery_liters = (float)($_POST['delivery_volume'] ?? 0);
            $supplier        = trim($_POST['supplier_name'] ?? '');
            // Fallback: look up first active supplier from DB if none submitted
            if (empty($supplier)) {
                try {
                    $sup_stmt = $pdo->prepare("SELECT supplier_name FROM fuel_suppliers WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
                    $sup_stmt->execute();
                    $sup_row = $sup_stmt->fetch(PDO::FETCH_ASSOC);
                    $supplier = $sup_row ? $sup_row['supplier_name'] : 'Unknown Supplier';
                } catch (Exception $sup_e) { $supplier = 'Unknown Supplier'; }
            }
            $delivery_date   = $_POST['delivery_date'] ?? date('Y-m-d');
            $invoice_no      = trim($_POST['receipt_number'] ?? '');
            $tanker_number   = trim($_POST['tanker_number'] ?? '');
            $notes           = trim($_POST['delivery_notes'] ?? '');
            try {
                if ($delivery_liters <= 0) throw new Exception('Delivery volume must be greater than 0.');
                if (empty($invoice_no))    throw new Exception('Invoice / Receipt number is required.');
                if (empty($fuel_type))     throw new Exception('Fuel type is required.');

                $pdo->beginTransaction();

                // Insert into fuel_deliveries using actual schema
                $pdo->prepare("
                    INSERT INTO fuel_deliveries
                        (station_id, delivery_date, fuel_type, supplier, invoice_no,
                         delivery_liters, tanker_number, received_by, notes, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
                ")->execute([
                    $station_id, $delivery_date, $fuel_type, $supplier,
                    $invoice_no, $delivery_liters, $tanker_number, $me['id'], $notes
                ]);                $delivery_id = $pdo->lastInsertId();

                // Removed auto-update to fuel_inventory and fuel_adjustments.
                // Stock is updated only upon Manager validation of the delivery receipt.

                log_activity($pdo, $me['id'], 'Record Delivery',
                    "Delivery #{$delivery_id}: {$delivery_liters} L of {$fuel_type}. Invoice: {$invoice_no}. Status: Pending validation.");

                $pdo->commit();
                $_SESSION['success'] = "? Delivery of {$delivery_liters} L ({$fuel_type}) recorded. Invoice: {$invoice_no}. Tank levels updated. Pending admin validation.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? Error recording delivery: ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#fuel-deliveries'); exit;

        /* -- VALIDATE DELIVERY (Manager confirms vs receipt) -- */
        case 'validate_delivery':
            $delivery_id  = (int)($_POST['delivery_id'] ?? 0);
            $action       = $_POST['delivery_action'] ?? ''; // 'approve', 'reject', 'adjust'
            $val_notes    = trim($_POST['validation_notes'] ?? '');
            $adj_liters   = (float)($_POST['adjusted_liters'] ?? 0);
            try {
                if (empty($val_notes)) throw new Exception('Validation notes are required.');
                if (!$delivery_id)     throw new Exception('Invalid delivery ID.');
                if (!in_array($action, ['approve','adjust','reject'])) throw new Exception('Invalid action.');

                // Load delivery record
                $stmt = $pdo->prepare("
                    SELECT fd.*, u.name AS staff_name
                    FROM fuel_deliveries fd
                    LEFT JOIN users u ON fd.received_by = u.id
                    WHERE fd.id = ? AND fd.station_id = ?
                ");
                $stmt->execute([$delivery_id, $station_id]);
                $del = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$del) throw new Exception('Delivery record not found.');
                $pending_statuses = ['pending', 'pending review', 'pending manager approval', 'discrepancy'];
                if (!in_array(strtolower($del['status']), $pending_statuses)) {
                    throw new Exception('This delivery has already been processed (status: ' . htmlspecialchars($del['status']) . ').');
                }

                $original_liters = (float)$del['delivery_liters'];
                $fuel_type       = $del['fuel_type'];
                $liters_to_add   = 0;

                // -"-"- Capacity check (before transaction) -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
                // Determine how many liters will actually be added
                $liters_for_cap_check = ($action === 'adjust') ? $adj_liters : $original_liters;

                if (in_array($action, ['approve', 'adjust']) && $liters_for_cap_check > 0) {
                    // Try matching by fuel_type_id first, then fall back to text match
                    $capStmt = $pdo->prepare("
                        SELECT COALESCE(fi.current_level, fi.current_stock, 0) AS current_level,
                               COALESCE(fi.capacity, 0) AS capacity
                        FROM fuel_inventory fi
                        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
                        WHERE fi.station_id = ?
                          AND (
                              LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(?))
                              OR LOWER(TRIM(ft.name)) = LOWER(TRIM(?))
                          )
                        ORDER BY (fi.fuel_type IS NOT NULL AND fi.fuel_type != '') DESC
                        LIMIT 1
                    ");
                    $capStmt->execute([$station_id, $fuel_type, $fuel_type]);
                    $capRow = $capStmt->fetch(PDO::FETCH_ASSOC);

                    if ($capRow && (float)$capRow['capacity'] > 0) {
                        $current  = (float)$capRow['current_level'];
                        $capacity = (float)$capRow['capacity'];
                        $after    = $current + $liters_for_cap_check;
                        if ($after > $capacity) {
                            $available = max(0, $capacity - $current);
                            throw new Exception(
                                "Cannot approve: delivery of " . number_format($liters_for_cap_check, 0) . " L " .
                                "would exceed the {$fuel_type} tank capacity. " .
                                "Capacity: " . number_format($capacity, 0) . " L, " .
                                "Current level: " . number_format($current, 0) . " L, " .
                                "Available space: " . number_format($available, 0) . " L. " .
                                "Please use Adjust to enter a corrected volume <= " . number_format($available, 0) . " L."
                            );
                        }
                    }
                }

                // Resolve fuel_type_id from fuel_inventory or fuel_types table
                $fuel_type_id = null;
                try {
                    $ftStmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
                    $ftStmt->execute([$station_id, $fuel_type]);
                    $ftRow = $ftStmt->fetch(PDO::FETCH_ASSOC);
                    if ($ftRow) $fuel_type_id = (int)$ftRow['fuel_type_id'];
                } catch (Exception $fte) {}

                if (!$fuel_type_id) {
                    try {
                        $ftStmt2 = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                        $ftStmt2->execute([$fuel_type]);
                        $ftRow2 = $ftStmt2->fetch(PDO::FETCH_ASSOC);
                        if ($ftRow2) $fuel_type_id = (int)$ftRow2['id'];
                    } catch (Exception $fte2) {}
                }

                $pdo->beginTransaction();

                if ($action === 'approve') {
                    $liters_to_add = $original_liters;

                    $pdo->prepare("
                        UPDATE fuel_deliveries
                        SET status = 'Verified', verified_by = ?, verified_at = NOW(),
                            notes = CONCAT(IFNULL(notes,''), ' | Manager Approved: ', ?)
                        WHERE id = ?
                    ")->execute([$me['id'], $val_notes, $delivery_id]);

                    // Update tank - try by fuel_type_id first (most reliable), then by text
                    $upd = null;
                    if ($fuel_type_id) {
                        $upd = $pdo->prepare("
                            UPDATE fuel_inventory
                            SET current_level = COALESCE(current_level, 0) + ?,
                                current_stock  = COALESCE(current_stock, 0) + ?,
                                last_updated   = NOW()
                            WHERE station_id = ? AND fuel_type_id = ?
                        ");
                        $upd->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type_id]);
                    }

                    if (!$fuel_type_id || $upd->rowCount() === 0) {
                        $upd2 = $pdo->prepare("
                            UPDATE fuel_inventory
                            SET current_level = COALESCE(current_level, 0) + ?,
                                current_stock  = COALESCE(current_stock, 0) + ?,
                                last_updated   = NOW()
                            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        ");
                        $upd2->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type]);

                        // Still nothing - insert new row
                        if ($upd2->rowCount() === 0 && $fuel_type_id) {
                            $pdo->prepare("
                                INSERT INTO fuel_inventory
                                    (station_id, fuel_type_id, fuel_type, current_level, current_stock, last_updated)
                                VALUES (?, ?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE
                                    current_level = COALESCE(current_level, 0) + VALUES(current_level),
                                    current_stock  = COALESCE(current_stock, 0) + VALUES(current_stock),
                                    last_updated   = NOW()
                            ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters_to_add, $liters_to_add]);
                        }
                    }

                } elseif ($action === 'adjust') {
                    if ($adj_liters <= 0) throw new Exception('Adjusted volume must be greater than 0.');
                    $liters_to_add = $adj_liters;
                    $full_notes = " | Manager Adjusted (Orig: {$original_liters}L -> New: {$adj_liters}L): " . $val_notes;

                    $pdo->prepare("
                        UPDATE fuel_deliveries
                        SET status = 'Verified', delivery_liters = ?, verified_by = ?, verified_at = NOW(),
                            notes = CONCAT(IFNULL(notes,''), ?)
                        WHERE id = ?
                    ")->execute([$adj_liters, $me['id'], $full_notes, $delivery_id]);

                    // Update tank - try by fuel_type_id first, then by text
                    $upd2 = null;
                    if ($fuel_type_id) {
                        $upd2 = $pdo->prepare("
                            UPDATE fuel_inventory
                            SET current_level = COALESCE(current_level, 0) + ?,
                                current_stock  = COALESCE(current_stock, 0) + ?,
                                last_updated   = NOW()
                            WHERE station_id = ? AND fuel_type_id = ?
                        ");
                        $upd2->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type_id]);
                    }

                    if (!$fuel_type_id || $upd2->rowCount() === 0) {
                        $upd2b = $pdo->prepare("
                            UPDATE fuel_inventory
                            SET current_level = COALESCE(current_level, 0) + ?,
                                current_stock  = COALESCE(current_stock, 0) + ?,
                                last_updated   = NOW()
                            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        ");
                        $upd2b->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type]);

                        if ($upd2b->rowCount() === 0 && $fuel_type_id) {
                            $pdo->prepare("
                                INSERT INTO fuel_inventory
                                    (station_id, fuel_type_id, fuel_type, current_level, current_stock, last_updated)
                                VALUES (?, ?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE
                                    current_level = COALESCE(current_level, 0) + VALUES(current_level),
                                    current_stock  = COALESCE(current_stock, 0) + VALUES(current_stock),
                                    last_updated   = NOW()
                            ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters_to_add, $liters_to_add]);
                        }
                    }

                } elseif ($action === 'reject') {
                    $pdo->prepare("
                        UPDATE fuel_deliveries
                        SET status = 'Rejected', verified_by = ?, verified_at = NOW(),
                            notes = CONCAT(IFNULL(notes,''), ' | Manager Returned: ', ?)
                        WHERE id = ?
                    ")->execute([$me['id'], $val_notes, $delivery_id]);
                    // Do NOT update stock on reject
                }

                // Fetch new tank level for success message
                $new_tank_level = null;
                try {
                    $tStmt = $pdo->prepare("
                        SELECT COALESCE(current_level, current_stock, 0) AS tank_level
                        FROM fuel_inventory
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $tStmt->execute([$station_id, $fuel_type]);
                    $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
                    if ($tRow) $new_tank_level = (float)$tRow['tank_level'];
                } catch (Exception $te) {}

                // Audit log (non-fatal)
                if ($fuel_type_id && in_array($action, ['approve', 'adjust'])) {
                    try {
                        $tank_note    = $new_tank_level !== null ? " New tank: {$new_tank_level}L." : ' N/A';
                        $audit_reason = substr("Delivery #{$delivery_id} {$action}d. Added {$liters_to_add}L of {$fuel_type}.{$tank_note} Notes: {$val_notes}", 0, 255);
                        $pdo->prepare("
                            INSERT INTO fuel_adjustments
                                (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                            VALUES (?, ?, 'delivery', ?, ?, ?, CURDATE())
                        ")->execute([$station_id, $fuel_type_id, $liters_to_add, $audit_reason, $me['id']]);
                    } catch (Exception $ae) {
                        error_log("fuel_adjustments insert failed: " . $ae->getMessage());
                    }
                }

                log_activity($pdo, $me['id'], 'Validate Delivery',
                    "Delivery #{$delivery_id} {$action}. Fuel: {$fuel_type}. Liters: {$liters_to_add}. Notes: {$val_notes}");

                // -- Audit Trail (audit_trail table - visible in Audit Trail sidebar) --
                try {
                    try { $pdo->exec("ALTER TABLE audit_trail ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) NOT NULL DEFAULT 'transaction'"); } catch (Exception $sch) {}
                    $at_action = match($action) {
                        'approve' => 'Approve',
                        'adjust'  => 'Adjust',
                        'reject'  => 'Return',
                        default   => ucfirst($action),
                    };
                    $at_detail = match($action) {
                        'approve' => "Delivery #DEL-{$delivery_id} approved | Fuel: {$fuel_type} | Volume: " . number_format($liters_to_add, 2) . " L | Invoice: " . ($del['invoice_no'] ?? '-') . " | Supplier: " . ($del['supplier'] ?? '-') . " | Encoded by: " . ($del['staff_name'] ?? '-') . " | Notes: {$val_notes}",
                        'adjust'  => "Delivery #DEL-{$delivery_id} adjusted | Fuel: {$fuel_type} | Original: " . number_format($original_liters, 2) . " L -> Adjusted: " . number_format($liters_to_add, 2) . " L | Invoice: " . ($del['invoice_no'] ?? '-') . " | Notes: {$val_notes}",
                        'reject'  => "Delivery #DEL-{$delivery_id} returned to staff | Fuel: {$fuel_type} | Volume: " . number_format($original_liters, 2) . " L | Invoice: " . ($del['invoice_no'] ?? '-') . " | Supplier: " . ($del['supplier'] ?? '-') . " | Reason: {$val_notes}",
                        default   => "Delivery #DEL-{$delivery_id} {$action} | {$val_notes}",
                    };
                    $pdo->prepare("
                        INSERT INTO audit_trail
                            (station_id, manager_id, transaction_id, action_type, old_value, new_value, entity_type)
                        VALUES (?, ?, ?, ?, ?, ?, 'fuel_delivery')
                    ")->execute([
                        $station_id,
                        $me['id'],
                        "DEL-{$delivery_id}",
                        $at_action,
                        $del['status'],
                        $at_detail,
                    ]);
                } catch (Exception $ate) {
                    error_log("audit_trail insert failed: " . $ate->getMessage());
                }

                // Notify the staff member who recorded the delivery
                try {
                    if ($del['received_by']) {
                        $notif_title = $action === 'reject'
                            ? 'Fuel Delivery Returned for Correction'
                            : 'Fuel Delivery Approved';
                        $notif_msg = $action === 'reject'
                            ? "Your fuel delivery #{$delivery_id} ({$fuel_type}) has been returned by the manager. Reason: {$val_notes}"
                            : "Your fuel delivery #{$delivery_id} ({$fuel_type}, " . number_format($liters_to_add, 0) . " L) has been approved and added to inventory.";
                        $notif_severity = $action === 'reject' ? 'high' : 'medium';
                        $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, message, event_type, severity, source_key, redirect_url, created_at)
                            VALUES (?, 'info', ?, ?, 'delivery', ?, ?, 'staff_fuel_deliveries.php', NOW())
                        ")->execute([
                            $del['received_by'],
                            $notif_title,
                            $notif_msg,
                            $notif_severity,
                            'fuel_del_' . $delivery_id . '_' . $action
                        ]);
                    }
                } catch (Exception $ne) {
                    error_log("Delivery notification failed: " . $ne->getMessage());
                }

                $pdo->commit();

                if (in_array($action, ['approve', 'adjust'])) {
                    $tank_msg = $new_tank_level !== null
                        ? " Tank level updated to <strong>" . number_format($new_tank_level, 0) . " L</strong>."
                        : '-"';
                    $_SESSION['success'] = "Delivery #{$delivery_id} approved. Added " . number_format($liters_to_add, 0) . "L of {$fuel_type} to inventory.{$tank_msg}";
                } else {
                    $_SESSION['success'] = "Delivery #{$delivery_id} returned to staff for correction.";
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#fuel-deliveries'); exit;

        /* -- ADJUST TANK LEVEL -- */
        case 'adjust_tank_level':
            $fuel_type_id    = $_POST['fuel_type_id'] ?? '';
            $new_level       = (float)($_POST['new_level'] ?? 0);
            $reason          = trim($_POST['reason'] ?? '');
            $adjustment_type = $_POST['adjustment_type'] ?? '';
            try {
                if (empty($fuel_type_id))  throw new Exception('Please select a fuel type.');
                if (empty($adjustment_type)) throw new Exception('Please select an adjustment type.');
                // reason is optional — no minimum length enforced
                if ($new_level < 0)        throw new Exception('New level cannot be negative.');

                // Get current stock + fuel type name
                $stmt = $pdo->prepare("SELECT COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock, ft.name as fuel_type_name FROM fuel_inventory fi JOIN fuel_types ft ON fi.fuel_type_id=ft.id WHERE fi.station_id=? AND fi.fuel_type_id=?");
                $stmt->execute([$station_id, $fuel_type_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new Exception('Fuel inventory record not found.');

                $difference   = $new_level - $current['current_stock'];
                $fuel_name    = $current['fuel_type_name'];
                $reason_short = substr($reason, 0, 255);

                $pdo->beginTransaction();

                // Update both columns so all reads stay consistent
                $pdo->prepare("UPDATE fuel_inventory SET current_level=?, current_stock=?, last_updated=NOW() WHERE station_id=? AND fuel_type_id=?")->execute([$new_level, $new_level, $station_id, $fuel_type_id]);

                // Audit trail row
                $pdo->prepare("INSERT INTO fuel_adjustments (station_id, fuel_type_id, fuel_type, adjustment_type, liters, reason, user_id, adjustment_date) VALUES (?,?,?,?,?,?,?,CURDATE())")->execute([$station_id, $fuel_type_id, $fuel_name, $adjustment_type, $difference, $reason_short, $me['id']]);

                log_activity($pdo, $me['id'], 'Adjust Tank Level', "Adjusted {$adjustment_type} for {$fuel_name}: {$difference} L (new level: {$new_level} L). Reason: {$reason}");
                $pdo->commit();

                $diff_sign = $difference >= 0 ? '+' : '';
                $diff_str  = $diff_sign . number_format(abs($difference), 2);
                $_SESSION['success'] = "Tank Level Adjusted: {$fuel_name} is now set to " . number_format($new_level, 2) . " L (" . ($difference >= 0 ? 'increased' : 'decreased') . " by " . number_format(abs($difference), 2) . " L). Change has been recorded in the audit trail.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Adjustment Failed: ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#adjustments'); exit;

        /* -- UPDATE PRICE -- */
        case 'update_price':
            $fuel_type_id = $_POST['fuel_type_id'] ?? '';
            $new_price    = (float)($_POST['new_price'] ?? 0);
            $reason       = trim($_POST['reason'] ?? '');
            if ($reason === '') $reason = 'Price update by manager';
            try {
                if (empty($fuel_type_id)) throw new Exception('Please select a fuel type.');
                if ($new_price <= 0)      throw new Exception('Price must be greater than 0.');

                // Ensure fuel_price_log table exists BEFORE starting transaction
                // (DDL causes implicit commit in MySQL — must be outside transaction)
                try {
                    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_price_log (
                        id               INT AUTO_INCREMENT PRIMARY KEY,
                        station_id       INT NOT NULL,
                        fuel_type_id     INT,
                        fuel_type        VARCHAR(100) NOT NULL,
                        old_price        DECIMAL(10,4) NOT NULL,
                        new_price        DECIMAL(10,4) NOT NULL,
                        price_difference DECIMAL(10,4) NOT NULL,
                        change_type      VARCHAR(50) DEFAULT 'Price Update',
                        reason_for_change TEXT,
                        changed_by       INT NOT NULL,
                        changed_by_name  VARCHAR(255),
                        ip_address       VARCHAR(45),
                        user_agent       TEXT,
                        change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_station (station_id),
                        INDEX idx_fuel_type (fuel_type),
                        INDEX idx_changed_by (changed_by),
                        INDEX idx_timestamp (change_timestamp)
                    )");
                } catch (Exception $tbl_e) { error_log("fuel_price_log create: " . $tbl_e->getMessage()); }

                // Get current price + fuel type name
                $stmt = $pdo->prepare("SELECT fi.price_per_liter, fi.fuel_type AS fuel_type_str, ft.name as fuel_type_name FROM fuel_inventory fi JOIN fuel_types ft ON fi.fuel_type_id=ft.id WHERE fi.station_id=? AND fi.fuel_type_id=?");
                $stmt->execute([$station_id, $fuel_type_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new Exception('Fuel inventory record not found.');

                $old_price     = (float)$current['price_per_liter'];
                $fuel_name     = $current['fuel_type_name'];
                $fuel_type_str = $current['fuel_type_str'] ?: $fuel_name;
                $reason_short  = substr($reason, 0, 500);
                $price_diff    = round($new_price - $old_price, 4);
                $change_label  = $price_diff > 0 ? 'Price Increase' : ($price_diff < 0 ? 'Price Decrease / Rollback' : 'No Change');

                if ($old_price == $new_price) throw new Exception('New price is the same as the current price. No update needed.');

                $pdo->beginTransaction();

                // 1. Update fuel_inventory price
                $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, last_updated=NOW() WHERE station_id=? AND fuel_type_id=?")->execute([$new_price, $station_id, $fuel_type_id]);

                // 2. Insert into fuel_price_log (immutable — never overwrite)
                try {
                    $pdo->prepare("
                        INSERT INTO fuel_price_log
                            (station_id, fuel_type_id, fuel_type, old_price, new_price,
                             price_difference, change_type, reason_for_change,
                             changed_by, changed_by_name, ip_address, user_agent)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $station_id,
                        $fuel_type_id,
                        $fuel_type_str,
                        $old_price,
                        $new_price,
                        $price_diff,
                        $change_label,
                        $reason_short,
                        $me['id'],
                        $me['name'],
                        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                } catch (Exception $fpl_e) { error_log("fuel_price_log insert: " . $fpl_e->getMessage()); }

                // 3. Legacy fuel_adjustments row (backward compat)
                $audit_reason = substr("Price: \xE2\x82\xB9{$old_price} -> \xE2\x82\xB9{$new_price}/L. {$reason}", 0, 255);
                try {
                    $pdo->prepare("INSERT INTO fuel_adjustments (station_id, fuel_type_id, fuel_type, adjustment_type, liters, reason, user_id, adjustment_date) VALUES (?,?,?,'price_update',0,?,?,CURDATE())")->execute([$station_id, $fuel_type_id, $fuel_name, $audit_reason, $me['id']]);
                } catch (Exception $fa_e) { error_log("fuel_adjustments price_update: " . $fa_e->getMessage()); }

                // 4. Audit logs (visible in Audit Trail sidebar)
                try {
                    $pdo->prepare("
                        INSERT INTO audit_logs
                            (user_id, log_type, action_type, action_details,
                             entity_type, entity_id, old_values, new_values,
                             ip_address, user_agent, status, created_at)
                        VALUES (?, 'inventory', 'Update', ?, 'fuel_price', ?, ?, ?, ?, ?, 'Success', NOW())
                    ")->execute([
                        $me['id'],
                        "{$change_label}: {$fuel_name} \xE2\x82\xB9" . number_format($old_price,2) . " -> \xE2\x82\xB9" . number_format($new_price,2) . "/L. Reason: {$reason}",
                        $fuel_type_id,
                        json_encode(['price_per_liter' => $old_price, 'fuel_type' => $fuel_name]),
                        json_encode(['price_per_liter' => $new_price, 'reason' => $reason, 'change_type' => $change_label]),
                        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                    ]);
                } catch (Exception $ae) { error_log("audit_logs insert failed: " . $ae->getMessage()); }

                // 5. Activity log
                log_activity($pdo, $me['id'], 'Update Price', "Updated {$fuel_name} price: \xE2\x82\xB9{$old_price} -> \xE2\x82\xB9{$new_price}/L. {$change_label}. Reason: {$reason}");

                $pdo->commit();

                $diff_sign    = $price_diff >= 0 ? '+' : '';
                $diff_display = $diff_sign . number_format(abs($price_diff), 2);
                $_SESSION['success'] = "\xE2\x9C\x93 {$change_label}: <strong>{$fuel_name}</strong> price changed from <strong>\xE2\x82\xB9" . number_format($old_price, 2) . "/L</strong> to <strong>\xE2\x82\xB9" . number_format($new_price, 2) . "/L</strong> ({$diff_sign}\xE2\x82\xB9{$diff_display}). Logged to Audit Trail.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Price Update Failed: ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#adjustments'); exit;

        /* -- INVESTIGATE / RESOLVE VARIANCE -- */
        case 'update_variance_status':
            $variance_id = (int)($_POST['variance_id'] ?? 0);
            $new_status  = $_POST['new_status'] ?? '';
            $inv_notes   = trim($_POST['investigation_notes'] ?? '');
            try {
                if (empty($inv_notes)) throw new Exception('Investigation notes are required.');
                // Map to actual enum values
                $status_map = [
                    'investigating' => 'Under Investigation',
                    'resolved'      => 'Resolved',
                ];
                $db_status = $status_map[$new_status] ?? $new_status;
                $pdo->prepare("
                    UPDATE fuel_variance_reports
                    SET status = ?, resolution_notes = ?, resolved_by = ?, updated_at = NOW()
                    WHERE id = ? AND station_id = ?
                ")->execute([$db_status, $inv_notes, $me['id'], $variance_id, $station_id]);
                log_activity($pdo, $me['id'], 'Variance Update', "Variance #{$variance_id} set to {$db_status}. Notes: {$inv_notes}");
                $_SESSION['success'] = "Variance Report #{$variance_id} has been updated to '{$db_status}'. Your notes have been recorded in the audit trail.";
            } catch (Exception $e) {
                $_SESSION['error'] = 'Variance Update Failed: ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#reconciliation'); exit;

        /* -- EXPORT VARIANCE REPORT -- */
        case 'export_variance':
            $format    = $_POST['format'] ?? 'excel';
            $date_from = $_POST['date_from'] ?? date('Y-m-01');
            $date_to   = $_POST['date_to']   ?? date('Y-m-d');
            try {
                $stmt = $pdo->prepare("
                    SELECT fvr.id, fvr.report_date, fvr.fuel_type, fvr.expected_stock, fvr.actual_stock,
                           fvr.variance_liters, fvr.variance_percent, fvr.status, fvr.resolution_notes,
                           u.name as resolved_by_name
                    FROM fuel_variance_reports fvr
                    LEFT JOIN users u ON fvr.resolved_by = u.id
                    WHERE fvr.station_id=? AND fvr.report_date BETWEEN ? AND ?
                    ORDER BY fvr.report_date DESC
                ");
                $stmt->execute([$station_id,$date_from,$date_to]);
                $variances = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($format === 'excel') {
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="variance_report_' . date('Y-m-d') . '.csv"');
                    $out = fopen('php://output','w');
                    fputcsv($out,['Report ID','Date','Fuel Type','Expected (L)','Actual (L)','Variance (L)','Variance (%)','Status','Resolved By','Resolution Notes']);
                    foreach ($variances as $v) {
                        fputcsv($out,[$v['id'],$v['report_date'],$v['fuel_type'],number_format($v['expected_stock'],2),number_format($v['actual_stock'],2),number_format($v['variance_liters'],2),number_format($v['variance_percent'],2).'%',$v['status'],$v['resolved_by_name']??'Pending',$v['resolution_notes']??'']);
                    }
                    fclose($out); exit;
                } else {
                    header('Content-Type: text/html');
                    header('Content-Disposition: attachment; filename="variance_report_' . date('Y-m-d') . '.html"');
                    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Variance Report</title><style>body{font-family:Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#003d7a;color:#fff}.high{color:#dc3545;font-weight:700}.ok{color:#28a745}</style></head><body>';
                    echo '<h1 style="color:#003d7a">Fuel Variance Report</h1>';
                    echo "<p><strong>Station ID:</strong> {$station_id} &nbsp;|&nbsp; <strong>Period:</strong> {$date_from} to {$date_to} &nbsp;|&nbsp; <strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>";
                    echo '<table><thead><tr><th>ID</th><th>Date</th><th>Fuel Type</th><th>Expected (L)</th><th>Actual (L)</th><th>Variance (L)</th><th>Variance %</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
                    foreach ($variances as $v) {
                        $cls = abs($v['variance_percent']) > 5 ? 'high' : 'ok';
                        echo "<tr><td>{$v['id']}</td><td>{$v['report_date']}</td><td>{$v['fuel_type']}</td><td>" . number_format($v['expected_stock'],2) . "</td><td>" . number_format($v['actual_stock'],2) . "</td><td class='{$cls}'>" . number_format($v['variance_liters'],2) . "</td><td class='{$cls}'>" . number_format($v['variance_percent'],2) . "%</td><td>{$v['status']}</td><td>" . htmlspecialchars($v['resolution_notes']??'') . "</td></tr>";
                    }
                    echo '</tbody></table></body></html>'; exit;
                }
            } catch (Exception $e) {
                $_SESSION['error'] = '? Export error: ' . $e->getMessage();
            }
            header('Location: manager_fuel_management_complete.php#variance-reports'); exit;
    }
}

/* -------------------------------------------------------------
   SCHEMA SAFETY — widen columns that are too narrow for actual values
   (idempotent: MySQL ignores if already wide enough)
------------------------------------------------------------- */
$_schema_fixes = [
    // fuel_deliveries.status VARCHAR(20) → VARCHAR(60)
    // 'Pending Manager Approval' = 24 chars, would be silently truncated
    "ALTER TABLE fuel_deliveries MODIFY COLUMN `status` VARCHAR(60) NOT NULL DEFAULT 'Pending'",
    // fuel_deliveries.fuel_type VARCHAR(50) → VARCHAR(100) to match fuel_inventory
    "ALTER TABLE fuel_deliveries MODIFY COLUMN `fuel_type` VARCHAR(100) DEFAULT NULL",
    // fuel_variance_reports.fuel_type VARCHAR(50) → VARCHAR(100) to match fuel_inventory
    "ALTER TABLE fuel_variance_reports MODIFY COLUMN `fuel_type` VARCHAR(100) NOT NULL",
    // fuel_transactions.shift_period VARCHAR(20) → VARCHAR(50) for longer shift keys
    "ALTER TABLE fuel_transactions MODIFY COLUMN `shift_period` VARCHAR(50) NOT NULL DEFAULT 'general'",
    // fuel_transactions.payment_method VARCHAR(20) → VARCHAR(50)
    "ALTER TABLE fuel_transactions MODIFY COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Internal'",
    // fuel_transactions: ensure validated_by, validated_at, reject_reason columns exist
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS `validated_by` INT NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS `validated_at` DATETIME NULL",
    "ALTER TABLE fuel_transactions ADD COLUMN IF NOT EXISTS `reject_reason` TEXT NULL",
];
foreach ($_schema_fixes as $_sf) {
    try { $pdo->exec($_sf); } catch (Exception $_e) { /* already correct width — ignore */ }
}
unset($_schema_fixes, $_sf, $_e);

/* -------------------------------------------------------------
   FETCH DATA
------------------------------------------------------------- */
$tank_data          = [];
$pending_readings   = [];
$variance_reports   = [];
$recent_adjustments = [];
$shift_history      = [];
$deliveries         = [];
$reconciliation_data = [];

// -- Load shift periods from DB (replaces all hardcoded shift labels) --
require_once __DIR__ . '/../backend/classes/ShiftPeriodConfig.php';
$shiftConfig  = new ShiftPeriodConfig($pdo, $station_id);
$shift_periods = $shiftConfig->getShiftPeriods();

// Build a lookup: shift_key => display label (e.g. 'first' => 'First Shift: 6:00 AM – 2:00 PM')
$shift_label_map = [];
foreach ($shift_periods as $sp) {
    $shift_label_map[$sp['shift_key']] = $sp['shift_name'];
    // Also map common aliases
    $shift_label_map[strtolower($sp['shift_name'])] = $sp['shift_name'];
}

/**
 * Resolve a raw shift_period/shift_name value to a display label using DB config.
 */
function resolve_shift_label(string $raw, array $map): string {
    if ($raw === '') return '—';
    $key = strtolower(trim($raw));
    // Direct key match (e.g. 'first', 'second')
    if (isset($map[$key])) return htmlspecialchars($map[$key]);
    // Alias matches
    $aliases = [
        'morning' => 'first', 'am' => 'first', '1' => 'first',
        'afternoon' => 'second', 'pm' => 'second', '2' => 'second',
    ];
    if (isset($aliases[$key]) && isset($map[$aliases[$key]])) {
        return htmlspecialchars($map[$aliases[$key]]);
    }
    // Fallback: return raw value
    return htmlspecialchars($raw);
}

// -- Load active suppliers from DB --
$db_suppliers = [];
try {
    $stmt = $pdo->prepare("SELECT id, supplier_name FROM fuel_suppliers WHERE is_active = 1 AND (station_id = ? OR station_id IS NULL) ORDER BY supplier_name ASC");
    $stmt->execute([$station_id]);
    $db_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: try without station filter
    try {
        $stmt = $pdo->prepare("SELECT id, supplier_name FROM fuel_suppliers WHERE is_active = 1 ORDER BY supplier_name ASC");
        $stmt->execute();
        $db_suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) { error_log("db_suppliers: " . $e2->getMessage()); }
}

// -- Load fuel types from DB (no is_active column in this table) --
$db_fuel_types = [];
try {
    $stmt = $pdo->prepare("SELECT ft.id, ft.name FROM fuel_types ft INNER JOIN fuel_inventory fi ON fi.fuel_type_id = ft.id WHERE fi.station_id = ? GROUP BY ft.id, ft.name ORDER BY ft.name ASC");
    $stmt->execute([$station_id]);
    $db_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("db_fuel_types: " . $e->getMessage()); }

try {
    // Tank levels
    $stmt = $pdo->prepare("
        SELECT fi.*,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
               ft.name as fuel_type_name,
               CASE
                   WHEN COALESCE(fi.current_level, fi.current_stock, 0) <= 0 THEN 'Out of Stock'
                   WHEN COALESCE(fi.current_level, fi.current_stock, 0) <= fi.critical_level THEN 'Low Stock'
                   ELSE 'Available'
               END as stock_status,
               (SELECT COUNT(*) FROM fuel_pumps fp WHERE fp.fuel_type_id=fi.fuel_type_id AND fp.station_id=fi.station_id) as pump_count
        FROM fuel_inventory fi JOIN fuel_types ft ON fi.fuel_type_id=ft.id
        WHERE fi.station_id=? ORDER BY ft.name
    ");
    $stmt->execute([$station_id]);
    $tank_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("tank_data: ".$e->getMessage()); }

// -- Build calibration lookup from fuel_inventory (used in Fuel Transactions section) --
$cal_lookup = [];
foreach ($tank_data as $td) {
    $key = strtolower(trim($td['fuel_type_name']));
    $cal_lookup[$key] = [
        'calibration' => (float)($td['latest_calibration'] ?? 0),
        'last_updated' => $td['last_updated'] ?? null,
        'price'        => (float)($td['price_per_liter'] ?? 0),
        'capacity'     => (float)($td['capacity'] ?? 0),
    ];
    // Also index by raw fuel_type string for fallback matching
    $key2 = strtolower(trim($td['fuel_type'] ?? ''));
    if ($key2 && $key2 !== $key) $cal_lookup[$key2] = $cal_lookup[$key];
}

try {
    $stmt = $pdo->prepare("
        SELECT ft.*, u.name as staff_name,
               COALESCE(fi.current_level, fi.current_stock, 0) as tank_level,
               fi.latest_calibration as tank_calibration,
               ft.pump_id as pump_number,
               ft.total_amount,
               ft.price_per_liter
        FROM fuel_transactions ft
        JOIN users u ON ft.staff_id = u.id
        LEFT JOIN fuel_inventory fi
            ON fi.station_id = ft.station_id
            AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(ft.fuel_type))
        WHERE ft.station_id = ? AND (ft.status = 'pending' OR ft.status = 'Pending Validation' OR ft.status LIKE '%pending%')
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
    ");
    $stmt->execute([$station_id]);
    $pending_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("pending_readings: ".$e->getMessage()); }
try {
    $stmt = $pdo->prepare("
        SELECT fvr.*, u.name as resolved_by_name
        FROM fuel_variance_reports fvr
        LEFT JOIN users u ON fvr.resolved_by = u.id
        WHERE fvr.station_id = ?
        ORDER BY
            CASE LOWER(TRIM(fvr.status))
                WHEN 'open' THEN 1
                WHEN 'under investigation' THEN 2
                WHEN 'resolved' THEN 3
                ELSE 4
            END ASC,
            fvr.report_date DESC
        LIMIT 30
    ");
    $stmt->execute([$station_id]);
    $variance_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("variance_reports: ".$e->getMessage()); }

try {
    $stmt = $pdo->prepare("SELECT fa.*, COALESCE(ft.name, fa.fuel_type, 'Unknown') as fuel_type_name, u.name as user_name FROM fuel_adjustments fa LEFT JOIN fuel_types ft ON fa.fuel_type_id=ft.id LEFT JOIN users u ON fa.user_id=u.id WHERE fa.station_id=? ORDER BY COALESCE(fa.created_at, fa.adjustment_date) DESC LIMIT 15");
    $stmt->execute([$station_id]);
    $recent_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("recent_adjustments: ".$e->getMessage()); }

try {
    // Ensure verified_by / verified_at columns exist
    try { $pdo->exec("ALTER TABLE fuel_deliveries ADD COLUMN verified_by INT DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE fuel_deliveries ADD COLUMN verified_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

    // Filter state for DR Validation table
    $del_filter_status = trim($_GET['del_status'] ?? '');
    $del_filter_fuel   = trim($_GET['del_fuel']   ?? '');
    $del_filter_kw     = trim($_GET['del_kw']     ?? '');

    $del_sql = "
        SELECT fd.id, fd.delivery_date, fd.fuel_type, fd.supplier,
               fd.invoice_no, fd.delivery_liters, fd.tanker_number,
               fd.notes, fd.status, fd.created_at,
               fd.verified_by, fd.verified_at,
               u.name  AS recorded_by_name,
               v.name  AS verified_by_name,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_tank_level,
               COALESCE(fi.capacity, 0)                        AS tank_capacity
        FROM fuel_deliveries fd
        LEFT JOIN users u  ON fd.received_by = u.id
        LEFT JOIN users v  ON fd.verified_by = v.id
        LEFT JOIN fuel_inventory fi
            ON fi.station_id = fd.station_id
            AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(fd.fuel_type))
        WHERE fd.station_id = ?
    ";
    $del_params = [$station_id];

    if ($del_filter_status !== '') {
        if ($del_filter_status === 'pending') {
            $del_sql .= " AND LOWER(fd.status) IN ('pending','pending review','pending manager approval','discrepancy')";
        } elseif ($del_filter_status === 'verified') {
            $del_sql .= " AND LOWER(fd.status) IN ('verified','approved')";
        } elseif ($del_filter_status === 'rejected') {
            $del_sql .= " AND LOWER(fd.status) = 'rejected'";
        }
    }
    if ($del_filter_fuel !== '') {
        $del_sql .= " AND LOWER(TRIM(fd.fuel_type)) = LOWER(TRIM(?))";
        $del_params[] = $del_filter_fuel;
    }
    if ($del_filter_kw !== '') {
        $del_sql .= " AND (fd.invoice_no LIKE ? OR fd.supplier LIKE ? OR fd.tanker_number LIKE ?)";
        $kw = '%' . $del_filter_kw . '%';
        $del_params = array_merge($del_params, [$kw, $kw, $kw]);
    }

    $del_sql .= " ORDER BY
        CASE WHEN LOWER(fd.status) IN ('pending','pending review','pending manager approval','discrepancy') THEN 0 ELSE 1 END ASC,
        fd.delivery_date DESC, fd.created_at DESC";

    $stmt = $pdo->prepare($del_sql);
    $stmt->execute($del_params);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("deliveries: ".$e->getMessage()); $deliveries = []; }

try {
    $stmt = $pdo->prepare("
        SELECT ft.transaction_id, ft.transaction_date, ft.fuel_type, ft.liters_sold,
               ft.status, ft.shift_period, ft.shift_name,
               ft.pump_id, ft.previous_reading, ft.present_reading,
               ft.calibration, ft.price_per_liter, ft.total_amount,
               u.name as staff_name, u.id as staff_id,
               fi.current_stock as current_tank_level,
               fi.latest_calibration as tank_calibration
        FROM fuel_transactions ft
        JOIN users u ON ft.staff_id = u.id
        LEFT JOIN fuel_inventory fi
            ON fi.station_id = ft.station_id
            AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(ft.fuel_type))
        WHERE ft.station_id = ?
        ORDER BY ft.transaction_date DESC, ft.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$station_id]);
    $shift_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("shift_history: ".$e->getMessage()); }

// Reconciliation: pump sales vs tank levels summary per fuel type
try {
    $stmt = $pdo->prepare("
        SELECT fi.fuel_type_id, ft.name as fuel_type_name,
               COALESCE(fi.current_level, fi.current_stock, 0) as current_stock,
               COALESCE(SUM(ftr.liters_sold),0) as total_sold_today,
               COALESCE(fi.capacity, 0) as capacity
        FROM fuel_inventory fi
        JOIN fuel_types ft ON fi.fuel_type_id=ft.id
        LEFT JOIN fuel_transactions ftr
            ON ftr.station_id = fi.station_id
            AND LOWER(TRIM(ftr.fuel_type)) = LOWER(TRIM(ft.name))
            AND DATE(ftr.transaction_date) = CURDATE()
            AND LOWER(ftr.status) IN ('verified','approved','validated','complete','completed')
        WHERE fi.station_id=?
        GROUP BY fi.fuel_type_id, ft.name, fi.current_level, fi.current_stock, fi.capacity
    ");
    $stmt->execute([$station_id]);
    $reconciliation_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("reconciliation_data: ".$e->getMessage()); }

// Pump master fuel types -" join fuel_pumps for pump ID, encoded-by, last calibration date
$pump_master_fuel_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            fi.fuel_type,
            fi.current_level,
            fi.current_stock,
            fi.latest_calibration,
            fi.price_per_liter,
            fi.last_updated,
            fi.fuel_type_id,
            fp.id            AS pump_db_id,
            fp.pump_number,
            fp.calibration_value,
            fp.calibration_updated_at AS last_calibration_date,
            fp.status        AS pump_status,
            u.name           AS calibration_encoded_by
        FROM fuel_inventory fi
        LEFT JOIN fuel_pumps fp
            ON fp.station_id = fi.station_id
            AND fp.fuel_type_id = fi.fuel_type_id
        LEFT JOIN users u ON fp.calibration_updated_by = u.id
        WHERE fi.station_id = ?
        ORDER BY fi.fuel_type ASC, fp.pump_number ASC
    ");
    $stmt->execute([$station_id]);
    $pump_master_fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("pump_master: ".$e->getMessage()); }

// Counts for stats
$total_tanks    = count($tank_data);
$available_cnt  = count(array_filter($tank_data, fn($t) => $t['stock_status'] === 'Available'));
$low_stock_cnt  = count(array_filter($tank_data, fn($t) => $t['stock_status'] === 'Low Stock'));
$pending_cnt    = count($pending_readings);
$open_variances = count(array_filter($variance_reports, fn($v) => strtolower($v['status']) === 'open'));
$high_variances = count(array_filter($variance_reports, fn($v) => abs($v['variance_percent'] ?? 0) > 5));

include __DIR__ . '/../partials/header.php';
?>

<?php
// Helper functions
function status_badge(string $status): string {
    $map = [
        'pending validation'        => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending'],
        'pending'                   => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending'],
        'pending review'            => ['bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047', 'label' => 'Pending Review'],
        'pending manager approval'  => ['bg' => '#fff3cd', 'color' => '#7c5c00', 'border' => '#ffc107', 'label' => 'Pending Approval'],
        'discrepancy'               => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Discrepancy'],
        'verified'                  => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'approved'                  => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'label' => 'Verified'],
        'rejected'                  => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'label' => 'Returned'],
    ];
    $key  = strtolower(trim($status));
    $cfg  = $map[$key] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e2e8f0', 'label' => htmlspecialchars($status)];
    return sprintf(
        '<span style="background:%s;color:%s;border:1px solid %s;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap;display:inline-block;text-align:center;">%s</span>',
        $cfg['bg'], $cfg['color'], $cfg['border'], $cfg['label']
    );
}

function hex2rgb($hex) {
    $hex = str_replace('#','',$hex);
    if (strlen($hex)==3) { $r=hexdec($hex[0].$hex[0]); $g=hexdec($hex[1].$hex[1]); $b=hexdec($hex[2].$hex[2]); }
    else { $r=hexdec(substr($hex,0,2)); $g=hexdec(substr($hex,2,2)); $b=hexdec(substr($hex,4,2)); }
    return "$r,$g,$b";
}
function adjustColor($hex,$pct) {
    $hex=str_replace('#','',$hex);
    if (strlen($hex)==3) { $r=hexdec($hex[0].$hex[0]); $g=hexdec($hex[1].$hex[1]); $b=hexdec($hex[2].$hex[2]); }
    else { $r=hexdec(substr($hex,0,2)); $g=hexdec(substr($hex,2,2)); $b=hexdec(substr($hex,4,2)); }
    $r=max(0,min(255,$r+($r*$pct/100))); $g=max(0,min(255,$g+($g*$pct/100))); $b=max(0,min(255,$b+($b*$pct/100)));
    return '#'.str_pad(dechex((int)$r),2,'0',STR_PAD_LEFT).str_pad(dechex((int)$g),2,'0',STR_PAD_LEFT).str_pad(dechex((int)$b),2,'0',STR_PAD_LEFT);
}
?>
<style>
/* -- MANAGER FUEL MANAGEMENT ENHANCED STYLES -- */
.mfm-wrap { max-width:1400px; margin:0 auto; padding:10px; }

/* Notification Banner */
.mfm-alert { display:flex; align-items:center; gap:12px; padding:14px 20px; border-radius:10px; margin-bottom:16px; font-weight:600; font-size:.9rem; animation:slideDown .3s ease; }
.mfm-alert.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.mfm-alert.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.mfm-alert .close-alert { margin-left:auto; cursor:pointer; font-size:1.2rem; opacity:.7; }
.mfm-alert .close-alert:hover { opacity:1; }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

.fuel-section { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; scroll-margin-top:20px; transition:opacity 0.3s ease, transform 0.3s ease; display:none; }
.fuel-section-inner { padding:20px; }
.tab-content.active { display:block; }
.tab-inner { padding:20px; }

/* Section visibility states */
.fuel-section.hidden { display:none !important; opacity:0; transform:translateY(-10px); }
.fuel-section.visible { display:block !important; opacity:1; transform:translateY(0); }

/* Stats Grid */
.stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; margin-bottom:20px; }
.stat-card { background:linear-gradient(135deg,#f8f9fa,#e9ecef); border-radius:10px; padding:16px; text-align:center; border-left:4px solid <?php echo $colors['primary']; ?>; transition:transform .2s; }
.stat-card:hover { transform:translateY(-2px); }
.stat-card.danger { border-left-color:<?php echo $colors['danger']; ?>; }
.stat-card.warning { border-left-color:<?php echo $colors['warning']; ?>; }
.stat-card.success { border-left-color:<?php echo $colors['success']; ?>; }
.stat-value { font-size:1.8rem; font-weight:700; color:<?php echo $colors['primary']; ?>; }
.stat-card.danger .stat-value { color:<?php echo $colors['danger']; ?>; }
.stat-card.warning .stat-value { color:#CC8800; }
.stat-card.success .stat-value { color:<?php echo $colors['success']; ?>; }
.stat-label { font-size:.75rem; color:#666; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }

/* Tank Cards */
.tank-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:16px; margin-bottom:20px; }
.tank-card { background:#fff; border:2px solid #e9ecef; border-radius:12px; padding:16px; transition:all .3s; }
.tank-card:hover { border-color:<?php echo $colors['primary']; ?>; box-shadow:0 4px 12px rgba(<?php echo hex2rgb($colors['primary']); ?>,.15); }
.tank-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.tank-name { font-size:1rem; font-weight:700; color:#333; }
.tank-status { padding:3px 10px; border-radius:16px; font-size:.7rem; font-weight:700; text-transform:uppercase; }
.status-available { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.status-low-stock, .status-low { background:#fff3cd; color:#CC8800; border:1px solid #ffeaa7; }
.status-out-of-stock, .status-out { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.tank-level { font-size:1.6rem; font-weight:700; color:<?php echo $colors['primary']; ?>; }
.tank-capacity { font-size:.8rem; color:#666; }
.tank-progress { width:100%; height:10px; background:#e9ecef; border-radius:5px; overflow:hidden; margin:10px 0; }
.tank-progress-fill { height:100%; border-radius:5px; transition:width .4s ease; }
.fill-ok   { background:linear-gradient(90deg,<?php echo $colors['success']; ?>,#5cb85c); }
.fill-low  { background:linear-gradient(90deg,<?php echo $colors['warning']; ?>,#f0ad4e); }
.fill-crit { background:linear-gradient(90deg,<?php echo $colors['danger']; ?>,#c9302c); }
.tank-details { display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:.75rem; }
.tank-detail { display:flex; justify-content:space-between; }
.tank-detail-label { color:#666; }
.tank-detail-value { font-weight:600; color:#333; }

/* Tables */
.data-table { width:100%; border-collapse:collapse; font-size:.80rem; margin-top:8px; table-layout:fixed; }
.data-table th, .data-table td { padding:6px 7px; text-align:left; border-bottom:1px solid #f1f3f5; vertical-align:middle; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.data-table th { background:#f8f9fa; color:#555; font-weight:700; font-size:.70rem; text-transform:uppercase; letter-spacing:.4px; border-bottom:2px solid #dee2e6; padding-top:9px; padding-bottom:9px; }
.data-table tr { transition:background-color 0.2s ease; }
.data-table tr:hover { background:rgba(<?php echo hex2rgb($colors['primary']); ?>,.03); }

/* Actions column — labeled buttons stacked vertically */
.data-table th.col-actions,
.data-table td.col-actions {
    width: 100px;
    min-width: 100px;
    max-width: 100px;
    text-align: center;
    white-space: nowrap;
    overflow: visible;
}

/* Labeled action buttons — stacked, full width */
.act-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 88px;
    height: 24px;
    border-radius: 5px;
    border: none;
    cursor: pointer;
    font-size: .70rem;
    font-weight: 600;
    transition: opacity .15s, transform .1s;
    color: #fff;
    margin: 2px auto;
    white-space: nowrap;
}
.act-btn:hover { opacity: .85; transform: scale(1.02); }
.act-btn.approve     { background: #22c55e; }
.act-btn.reject      { background: #ef4444; }
.act-btn.adjust      { background: #00264D; }
.act-btn.view        { background: #6b7280; }
.act-btn.investigate { background: #f59e0b; }

/* Modern Status Pills */
.status-pill-pending {
    background-color: #fffbeb;
    color: #b45309;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 0.78rem;
    font-weight: 700;
    display: inline-block;
    text-align: center;
    border: 1px solid #fef3c7;
}
.status-pill-verified {
    background-color: #e6f7ed;
    color: #137333;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 0.78rem;
    font-weight: 700;
    display: inline-block;
    text-align: center;
    border: 1px solid #c6f6d5;
}
.status-pill-rejected {
    background-color: #fef2f2;
    color: #991b1b;
    padding: 5px 12px;
    border-radius: 16px;
    font-size: 0.78rem;
    font-weight: 700;
    display: inline-block;
    text-align: center;
    border: 1px solid #fee2e2;
}

/* Variance Tags */
.tag-investigate { background:#dc3545; color:#fff; padding:3px 8px; border-radius:4px; font-size:.72rem; font-weight:700; animation:pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.7} }
.tag-open { background:#ffc107; color:#212529; padding:3px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }
.tag-resolved { background:#28a745; color:#fff; padding:3px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }
.tag-investigating { background:#17a2b8; color:#fff; padding:3px 8px; border-radius:4px; font-size:.72rem; font-weight:700; }

/* Buttons */
.btn { padding:6px 14px; border:none; border-radius:6px; cursor:pointer; font-size:.8rem; font-weight:600; transition:all .2s; display:inline-flex; align-items:center; gap:5px; text-decoration:none; }
.btn-lg { padding:10px 22px; font-size:.9rem; }
/* Approve / Validate ? Green #28A745 */
.btn-success { background:#28A745; color:#fff; }
.btn-success:hover { background:#218838; transform:translateY(-1px); }
/* Reject / Return ? Red #DC3545 */
.btn-danger { background:#DC3545; color:#fff; }
.btn-danger:hover { background:#c82333; transform:translateY(-1px); }
/* Adjust / Edit / View / Save ? Petron Dark Blue #00264D */
.btn-primary { background:#00264D; color:#fff; }
.btn-primary:hover { background:#001a36; transform:translateY(-1px); }
/* Stock Request / Urgent ? Petron Red #CC0000 */
.btn-accent { background:#CC0000; color:#fff; }
.btn-accent:hover { background:#aa0000; transform:translateY(-1px); }
/* Print / Export / Info ? Info Blue #17A2B8 */
.btn-info { background:#17A2B8; color:#fff; }
.btn-info:hover { background:#138496; transform:translateY(-1px); }
/* Warning / Pending ? Yellow #FFC107 */
.btn-warning { background:#FFC107; color:#212529; }
.btn-warning:hover { background:#e0a800; transform:translateY(-1px); }
/* Reset / Clear / Neutral ? Gray #6C757D */
.btn-secondary { background:#6C757D; color:#fff; }
.btn-secondary:hover { background:#5a6268; transform:translateY(-1px); }
/* Outline variant */
.btn-outline { background:transparent; border:2px solid #00264D; color:#00264D; }
.btn-outline:hover { background:#00264D; color:#fff; }

/* Forms */
.form-group { margin-bottom:14px; }
.form-label { display:block; margin-bottom:5px; font-weight:600; color:#333; font-size:.88rem; }
.form-label .required { color:<?php echo $colors['danger']; ?>; }
.form-control { width:100%; padding:8px 12px; border:1px solid #ddd; border-radius:6px; font-size:.88rem; transition:border-color .2s; box-sizing:border-box; }
.form-control:focus { outline:none; border-color:<?php echo $colors['primary']; ?>; box-shadow:0 0 0 3px rgba(<?php echo hex2rgb($colors['primary']); ?>,.15); }
.form-hint { font-size:.75rem; color:#888; margin-top:3px; }

/* Section Headers */
.section-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px; }
.section-title { font-size:1rem; font-weight:700; color:<?php echo $colors['primary']; ?>; display:flex; align-items:center; gap:8px; }
.section-title i { font-size:.9rem; }

/* Info Box */
.info-box { background:linear-gradient(135deg,#f8f9fa,#e9ecef); border-radius:10px; padding:18px; border-left:4px solid <?php echo $colors['primary']; ?>; margin-bottom:18px; }
.info-box.warning { border-left-color:<?php echo $colors['warning']; ?>; background:linear-gradient(135deg,#fffbf0,#fff3cd); }
.info-box.danger  { border-left-color:<?php echo $colors['danger']; ?>;  background:linear-gradient(135deg,#fff5f5,#f8d7da); }
.info-box.success { border-left-color:<?php echo $colors['success']; ?>; background:linear-gradient(135deg,#f0fff4,#d4edda); }

/* Modal */
.modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.55); }
.modal.show { display:flex; align-items:center; justify-content:center; }
.modal-box { background:#fff; border-radius:14px; padding:28px; width:90%; max-width:520px; max-height:85vh; overflow-y:auto; position:relative; animation:modalIn .25s ease; }
@keyframes modalIn { from{opacity:0;transform:scale(.95)} to{opacity:1;transform:scale(1)} }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:2px solid #e9ecef; }
.modal-title { font-size:1.1rem; font-weight:700; color:<?php echo $colors['primary']; ?>; }
.modal-close { background:none; border:none; font-size:1.5rem; cursor:pointer; color:#999; line-height:1; }
.modal-close:hover { color:#333; }
.modal-footer { display:flex; gap:10px; margin-top:20px; padding-top:14px; border-top:1px solid #e9ecef; }

/* Audit Trail Badge */
.audit-badge { display:inline-flex; align-items:center; gap:4px; background:#e8f4fd; color:#0056b3; padding:2px 8px; border-radius:10px; font-size:.72rem; font-weight:600; }

/* Empty State */
.empty-state { text-align:center; padding:40px 20px; color:#888; }
.empty-state i { font-size:2.5rem; color:<?php echo $colors['success']; ?>; margin-bottom:12px; display:block; }

/* Variance % color */
.var-ok   { color:<?php echo $colors['success']; ?>; font-weight:700; }
.var-warn { color:#CC8800; font-weight:700; }
.var-crit { color:<?php echo $colors['danger']; ?>; font-weight:700; }

/* Shift history read-only */
.readonly-badge { background:#6c757d; color:#fff; padding:2px 7px; border-radius:4px; font-size:.7rem; font-weight:600; }

/* Responsive */
@media(max-width:768px){
    .stats-grid { grid-template-columns:repeat(2,1fr); }
    .tank-grid  { grid-template-columns:1fr; }
    .data-table { font-size:.78rem; }
    .data-table th, .data-table td { padding:7px 8px; }
}
</style>

<div class="mfm-wrap">
    <div class="page-head">
        <div>
            <h1 class="h1" id="mfm-page-title">Fuel Transactions Oversight</h1>
            <div class="sub" id="mfm-page-subtitle">Review pump readings encoded by Staff — Validate / Approve / Adjust</div>
        </div>
        <div style="display:none;"></div>
    </div>

<?php if ($msg): ?>
<div class="mfm-alert <?php echo $msg_type; ?>">
    <i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <span><?php echo htmlspecialchars($msg); ?></span>
    <span class="close-alert" onclick="this.parentElement.remove()">&times; - </span>
</div>
<?php endif; ?>

<!-- -- SECTION NAVIGATION (sidebar-driven, no top tabs) -- -->

<!-- ----------------------------------------------------------
     SECTION 1: FUEL TRANSACTIONS  -  PUMP READING VALIDATION
---------------------------------------------------------- -->
<div id="fuel-transactions" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-gas-pump"></i> Fuel Transactions Oversight</div>
    </div>


    <?php if (empty($pending_readings)): ?>
        <div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending staff readings to validate.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; width:100%;">
        <thead><tr>
            <th style="width:130px; max-width:130px;">TXN ID</th>
            <th style="width:80px; max-width:80px;">Pump / Fuel</th>
            <th style="width:95px; max-width:95px;">Meter Reading<br><small style="font-weight:normal;opacity:0.8">Prev → Present</small></th>
            <th style="width:75px; max-width:75px;">Liters Sold</th>
            <th style="width:90px; max-width:90px;">Revenue (₱)</th>
            <th style="width:130px; max-width:130px;">Shift</th>
            <th style="width:100px; max-width:100px;">Staff</th>
            <th style="width:90px; max-width:90px;">Submitted</th>
            <th style="width:70px; max-width:70px;">Variance</th>
            <th style="width:80px; max-width:80px;">Status</th>
            <th class="col-actions" style="width:90px; min-width:90px; max-width:90px;">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pending_readings as $r):
            $ft_key_r      = strtolower(trim($r['fuel_type']));
            // Calibration: prefer value stored on the transaction row, then inventory join, then lookup
            $cal_r         = (float)($r['calibration'] ?? $r['tank_calibration'] ?? $cal_lookup[$ft_key_r]['calibration'] ?? 0);
            $tank_lvl_r    = (float)($r['tank_level'] ?? 0);
            $liters_sold   = (float)($r['liters_sold'] ?? 0);
            $prev_reading  = (float)($r['previous_reading'] ?? 0);
            $pres_reading  = (float)($r['present_reading'] ?? 0);
            // Variance: compare liters_sold vs (present - previous - calibration)
            $computed_liters = max(0, $pres_reading - $prev_reading - $cal_r);
            $variance_l      = ($pres_reading > 0 && $prev_reading > 0)
                               ? $liters_sold - $computed_liters
                               : 0;
            $variance_pct    = ($liters_sold > 0) ? abs($variance_l / $liters_sold) * 100 : 0;
            $is_flagged      = $variance_pct > 5;
            // Shift label from DB config
            $shift_raw   = $r['shift_period'] ?? $r['shift_name'] ?? '';
            $shift_label = resolve_shift_label($shift_raw, $shift_label_map);
            $submitted_at = $r['created_at'] ?? $r['transaction_date'] ?? null;
        ?>
        <tr style="<?php echo $is_flagged ? 'background:#fff8f0;' : ''; ?>">
            <td title="<?php echo htmlspecialchars($r['transaction_id']); ?>"><strong style="font-size:.70rem;font-family:monospace;"><?php echo htmlspecialchars(substr($r['transaction_id'], -14)); ?></strong></td>
            <td>
                <div style="font-size:.75rem;color:#666;"><?php echo htmlspecialchars($r['fuel_type']); ?></div>
            </td>
            <td style="text-align:right;">
                <div style="font-size:.82rem;color:#555;"><?php echo $prev_reading > 0 ? number_format($prev_reading, 2) : '<span style="color:#bbb;"></span>'; ?></div>
                <div style="font-size:.82rem;font-weight:600;"><i class="fas fa-arrow-down" style="font-size:0.6rem;opacity:0.5;"></i> <?php echo $pres_reading > 0 ? number_format($pres_reading, 2) : '<span style="color:#bbb;"></span>'; ?></div>
            </td>
            <td style="text-align:right;">
                <strong style="color:<?php echo $colors['primary']; ?>;"><?php echo number_format($liters_sold, 2); ?> L</strong>
                <?php if ($cal_r > 0): ?>
                <div style="font-size:.68rem;color:#888;">cal (staff): <?php echo number_format($cal_r, 3); ?> L</div>
                <?php endif; ?>
            </td>
            <td style="text-align:right;">
                <?php
                $price_r   = (float)($r['price_per_liter'] ?? $cal_lookup[$ft_key_r]['price'] ?? 0);
                $revenue_r = (float)($r['total_amount'] ?? 0) ?: ($liters_sold * $price_r);
                ?>
                <?php if ($revenue_r > 0): ?>
                <strong style="color:#155724;">₱<?php echo number_format($revenue_r, 2); ?></strong>
                <?php if ($price_r > 0): ?>
                <div style="font-size:.68rem;color:#888;">@ ₱<?php echo number_format($price_r, 2); ?>/L</div>
                <?php endif; ?>
                <?php else: ?>
                <span style="color:#bbb;font-size:.78rem;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span style="font-size:.75rem;background:#e8f4fd;color:#0056b3;padding:2px 5px;border-radius:8px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($shift_label); ?>">
                    <i class="fas fa-clock"></i> <?php echo htmlspecialchars($shift_label); ?>
                </span>
            </td>
            <td>
                <span class="audit-badge"><i class="fas fa-user"></i> <?php echo htmlspecialchars($r['staff_name']); ?></span>
            </td>
            <td style="font-size:.73rem;color:#666;">
                <?php echo $submitted_at ? date('M j Y', strtotime($submitted_at)) . '<br><span style="color:#999">' . date('H:i', strtotime($submitted_at)) . '</span>' : '—'; ?>
            </td>
            <td style="text-align:center;">
                <?php if ($is_flagged): ?>
                    <span class="tag-investigate" style="font-size:.7rem;padding:3px 7px;">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo number_format($variance_pct, 1); ?>%
                    </span>
                <?php elseif ($variance_pct > 0): ?>
                    <span class="var-ok" style="font-size:.82rem;">
                        <i class="fas fa-check"></i> <?php echo number_format($variance_pct, 1); ?>%
                    </span>
                <?php else: ?>
                    <span style="color:#bbb;font-size:.78rem;"> - </span>
                <?php endif; ?>
            </td>
            <td>
                <?php echo status_badge($r['status'] ?? 'pending'); ?>
            </td>
            <td class="col-actions" style="white-space:nowrap;">
                <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                    <button class="act-btn approve" title="Approve"
                        onclick="openValidateModal(
                            '<?php echo htmlspecialchars($r['transaction_id']); ?>',
                            <?php echo $liters_sold; ?>,
                            '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>',
                            <?php echo $tank_lvl_r; ?>,
                            <?php echo $cal_r; ?>,
                            <?php echo round($variance_pct, 2); ?>,
                            <?php echo $is_flagged ? 'true' : 'false'; ?>
                        )">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="act-btn reject" title="Reject"
                        onclick="openRejectModal('<?php echo htmlspecialchars($r['transaction_id']); ?>')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                    <button class="act-btn adjust" title="Adjust"
                        onclick="openAdjustModal(
                            '<?php echo htmlspecialchars($r['transaction_id']); ?>',
                            <?php echo $liters_sold; ?>,
                            '<?php echo htmlspecialchars(addslashes($r['fuel_type'])); ?>',
                            '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>'
                        )">
                        <i class="fas fa-sliders"></i> Adjust
                    </button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php endif; ?>

    

</div>
</div>

<!-- ----------------------------------------------------------
     SECTION 2: DAILY OPERATIONS  -  TANK LEVELS & APPROVAL
---------------------------------------------------------- -->
<div id="daily-ops" class="fuel-section">
<div class="fuel-section-inner">

<?php
// -- Calibration lookup already built in data-fetch section above --
?>


<!-- ---------------------------------------------
     SECTION B: DAILY LOGS APPROVAL
     Manager approves/rejects daily shift entries
--------------------------------------------- -->
<div class="section-head" style="margin-top:4px;padding-top:18px;border-top:2px solid #e9ecef;">
    <div class="section-title"><i class="fas fa-clipboard-check"></i> Daily Logs  -  Shift Approval</div>
    <?php
    $pending_daily = array_filter($shift_history, fn($h) => str_contains(strtolower($h['status'] ?? ''), 'pending'));
    $pending_daily_cnt = count($pending_daily);
    if ($pending_daily_cnt > 0):
    ?>
    <span class="tag-open"><i class="fas fa-clock"></i> <?php echo $pending_daily_cnt; ?> Awaiting Approval</span>
    <?php endif; ?>
</div>

<?php if (empty($shift_history)): ?>
    <div class="empty-state"><i class="fas fa-check-circle"></i><p>No daily logs found.</p></div>
<?php else: ?>

<!-- Filter tabs: Pending first, then all -->
<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
    <button class="btn btn-primary" style="font-size:.78rem;padding:4px 12px;" onclick="filterDailyLogs('pending')">
        <i class="fas fa-clock"></i> Pending <?php if($pending_daily_cnt>0): ?><span style="background:#fff;color:<?php echo $colors['primary'];?>;border-radius:10px;padding:1px 6px;margin-left:4px;font-weight:700;"><?php echo $pending_daily_cnt; ?></span><?php endif; ?>
    </button>
    <button class="btn btn-secondary" style="font-size:.78rem;padding:4px 12px;" onclick="filterDailyLogs('all')">
        <i class="fas fa-list"></i> All Entries
    </button>
    <button class="btn btn-secondary" style="font-size:.78rem;padding:4px 12px;" onclick="filterDailyLogs('verified')">
        <i class="fas fa-check-circle"></i> Approved
    </button>
    <button class="btn btn-secondary" style="font-size:.78rem;padding:4px 12px;" onclick="filterDailyLogs('rejected')">
        <i class="fas fa-times-circle"></i> Rejected
    </button>
</div>

<div style="overflow-x:auto;">
<table class="data-table" id="dailyLogsTable">
    <thead><tr>
        <th>Log ID</th>
        <th>Date</th>
        <th>Shift</th>
        <th>Pump/Fuel</th>
        <th>Sales (L)</th>
        <th>Tank (L)</th>
        <th>Variance %</th>
        <th>Staff</th>
        <th>Status</th>
        <th class="col-actions">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($shift_history as $h):
        $st = strtolower($h['status'] ?? 'pending');
        // Normalize: 'pending validation' ? 'pending', 'verified'/'approved' ? 'verified'
        $st_norm = str_contains($st, 'pending') ? 'pending'
                 : (str_contains($st, 'verified') || str_contains($st, 'approved') ? 'verified'
                 : (str_contains($st, 'rejected') ? 'rejected' : $st));

        // Shift label from DB config
        $shift_raw   = $h['shift_period'] ?? $h['shift_name'] ?? '';
        $shift_label = resolve_shift_label($shift_raw, $shift_label_map);

        // Pump display  -  only pump_id exists in fuel_transactions
        $pump_display = $h['pump_id'] ?? null;

        // Tank level from inventory join
        $tank_lvl = (float)($h['current_tank_level'] ?? 0);

        // Variance calculation
        $liters_sold  = (float)($h['liters_sold'] ?? 0);
        $cal_h        = (float)($h['calibration'] ?? $h['tank_calibration'] ?? 0);
        $prev_h       = (float)($h['previous_reading'] ?? 0);
        $pres_h       = (float)($h['present_reading'] ?? 0);
        $computed_h   = ($prev_h > 0 && $pres_h > 0) ? max(0, $pres_h - $prev_h - $cal_h) : 0;
        $var_l_h      = ($computed_h > 0) ? $liters_sold - $computed_h : 0;
        $var_pct_h    = ($liters_sold > 0 && $computed_h > 0) ? abs($var_l_h / $liters_sold) * 100 : 0;
        $is_flagged_h = $var_pct_h > 5;

        $row_bg = '';
        if ($st_norm === 'pending' && $is_flagged_h) $row_bg = 'background:#fff8f0;';
        elseif ($st_norm === 'pending') $row_bg = 'background:#fffef0;';
    ?>
    <tr data-status="<?php echo $st_norm; ?>" style="<?php echo $row_bg; ?>">
        <td>
            <strong style="font-size:.76rem;font-family:monospace;"><?php echo htmlspecialchars($h['transaction_id']); ?></strong>
        </td>
        <td style="white-space:nowrap;font-size:.82rem;">
            <?php echo date('M j, Y', strtotime($h['transaction_date'])); ?>
        </td>
        <td>
            <span style="font-size:.78rem;background:#e8f4fd;color:#0056b3;padding:2px 7px;border-radius:10px;white-space:nowrap;">
                <i class="fas fa-clock"></i> <?php echo $shift_label; ?>
            </span>
        </td>
        <td>
            <?php if ($pump_display): ?>
            <div style="font-weight:700;font-size:.85rem;">Pump #<?php echo htmlspecialchars($pump_display); ?></div>
            <?php endif; ?>
            <div style="font-size:.78rem;color:#555;"><?php echo htmlspecialchars($h['fuel_type']); ?></div>
        </td>
        <td style="text-align:right;">
            <strong style="color:<?php echo $colors['primary']; ?>;"><?php echo number_format($liters_sold, 2); ?> L</strong>
            <?php if ($prev_h > 0 && $pres_h > 0): ?>
            <div style="font-size:.68rem;color:#888;"><?php echo number_format($prev_h,2); ?> ? <?php echo number_format($pres_h,2); ?></div>
            <?php endif; ?>
        </td>
        <td style="text-align:right;">
            <?php if ($tank_lvl > 0): ?>
                <span style="font-size:.85rem;font-weight:600;"><?php echo number_format($tank_lvl, 2); ?> L</span>
            <?php else: ?>
                <span style="color:#bbb;font-size:.78rem;"> - </span>
            <?php endif; ?>
        </td>
        <td style="text-align:center;">
            <?php if ($is_flagged_h): ?>
                <span class="tag-investigate" style="font-size:.7rem;padding:3px 7px;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo number_format($var_pct_h, 1); ?>%
                </span>
            <?php elseif ($var_pct_h > 0): ?>
                <span class="var-ok" style="font-size:.82rem;">
                    <i class="fas fa-check"></i> <?php echo number_format($var_pct_h, 1); ?>%
                </span>
            <?php else: ?>
                <span style="color:#bbb;font-size:.78rem;"> - </span>
            <?php endif; ?>
        </td>
        <td>
            <span class="audit-badge"><i class="fas fa-user"></i> <?php echo htmlspecialchars($h['staff_name']); ?></span>
        </td>
        <td>
            <?php if ($st_norm === 'verified'): ?>
                <span class="tag-resolved"><i class="fas fa-check"></i> Approved</span>
            <?php elseif ($st_norm === 'rejected'): ?>
                <span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>
            <?php else: ?>
                <span class="tag-open"><i class="fas fa-clock"></i> Pending</span>
            <?php endif; ?>
        </td>
        <td class="col-actions" style="white-space:nowrap;">
            <?php if ($st_norm === 'pending'): ?>
            <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                <button class="act-btn approve" title="Approve"
                    onclick="openApproveDailyModal(
                        '<?php echo htmlspecialchars($h['transaction_id']); ?>',
                        '<?php echo htmlspecialchars(addslashes($h['fuel_type'])); ?>',
                        <?php echo $liters_sold; ?>,
                        '<?php echo htmlspecialchars(addslashes($h['staff_name'])); ?>'
                    )">
                    <i class="fas fa-check"></i> Approve
                </button>
                <button class="act-btn reject" title="Reject"
                    onclick="openRejectDailyModal('<?php echo htmlspecialchars($h['transaction_id']); ?>')">
                    <i class="fas fa-times"></i> Reject
                </button>
            </div>
            <?php else: ?>
                <span style="color:#bbb;font-size:.75rem;">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

</div>
</div>

<!-- ----------------------------------------------------------
     SECTION 3: FUEL DELIVERIES  -  STAFF DR VALIDATION
---------------------------------------------------------- -->
<div id="fuel-deliveries" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-truck"></i> Fuel Deliveries Validation</div>
    </div>





    <?php if (empty($deliveries)): ?>
        <div class="empty-state"><i class="fas fa-truck"></i><p>No deliveries recorded yet.</p></div>
    <?php else: ?>

    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; width:100%;">
        <thead><tr>
            <th style="width:50px;">#</th>
            <th style="width:90px;">Fuel Type</th>
            <th style="width:80px;">Status</th>
            <th style="width:130px;">Supplier</th>
            <th style="width:110px;">Invoice No.</th>
            <th style="width:80px;">Volume (L)</th>
            <th style="width:110px;">Tank Level</th>
            <th style="width:110px;">Encoded By</th>
            <th style="width:120px;">Date &amp; Time</th>
            <th style="width:120px;">Validated By</th>
            <th class="col-actions">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($deliveries as $d):
            $st         = strtolower($d['status'] ?? 'pending');
            $is_pending = in_array($st, ['pending','pending review','pending manager approval','discrepancy']);
            $cap_val    = (float)($d['tank_capacity']      ?? 0);
            $cur_val    = (float)($d['current_tank_level'] ?? 0);
            $del_val    = (float)($d['delivery_liters']    ?? 0);
            $over_cap   = $cap_val > 0 && ($cur_val + $del_val) > $cap_val;
            $row_bg     = $is_pending ? ($over_cap ? 'background:#fff5f5;' : 'background:#fffbea;') : '';
        ?>
        <tr style="<?= $row_bg ?>">
            <td><strong style="font-size:.78rem;color:#002F6C;">#<?= (int)$d['id'] ?></strong></td>
            <td>
                <span style="font-weight:700;font-size:.82rem;">
                    <i class="fas fa-gas-pump" style="opacity:.5;margin-right:3px;"></i><?= htmlspecialchars($d['fuel_type']) ?>
                </span>
            </td>
            <td><?= status_badge($d['status'] ?? 'pending') ?></td>
            <td style="font-size:.80rem;"><?= htmlspecialchars($d['supplier'] ?? '—') ?></td>
            <td style="font-family:monospace;font-size:.78rem;"><?= htmlspecialchars($d['invoice_no'] ?? '—') ?></td>
            <td style="text-align:right;font-weight:700;color:#002F6C;">
                <?= number_format($del_val, 2) ?>
                <?php if ($over_cap): ?>
                <div style="font-size:.68rem;color:#dc3545;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> Over cap</div>
                <?php endif; ?>
            </td>
            <td style="font-size:.78rem;">
                <?php if ($cur_val > 0): ?>
                    <?= number_format($cur_val, 0) ?> L<?= $cap_val > 0 ? ' / ' . number_format($cap_val, 0) . ' L' : '' ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:.78rem;">
                <span class="audit-badge"><i class="fas fa-user"></i> <?= htmlspecialchars($d['recorded_by_name'] ?? 'Staff') ?></span>
            </td>
            <td style="font-size:.75rem;color:#555;">
                <?= date('M j, Y', strtotime($d['created_at'])) ?><br>
                <span style="color:#94a3b8;"><?= date('H:i', strtotime($d['created_at'])) ?></span>
            </td>
            <td style="font-size:.75rem;">
                <?php if (!empty($d['verified_by_name'])): ?>
                    <span class="audit-badge"><i class="fas fa-user-check"></i> <?= htmlspecialchars($d['verified_by_name']) ?></span>
                    <?php if (!empty($d['verified_at'])): ?>
                    <div style="font-size:.68rem;color:#94a3b8;margin-top:2px;"><?= date('M j, g:i A', strtotime($d['verified_at'])) ?></div>
                    <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="col-actions" style="white-space:nowrap;">
                <?php if ($is_pending): ?>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                    <button class="act-btn view" title="View Details"
                        onclick="openDeliveryDetailsModal(<?= htmlspecialchars(json_encode([
                            'id'             => $d['id'],
                            'supplier'       => $d['supplier'] ?? 'N/A',
                            'fuel_type'      => $d['fuel_type'],
                            'delivery_liters'=> $d['delivery_liters'],
                            'invoice_no'     => $d['invoice_no'] ?? '',
                            'notes'          => $d['notes'] ?? '',
                            'recorded_by'    => $d['recorded_by_name'] ?? 'N/A',
                            'created_at'     => $d['created_at'],
                            'delivery_date'  => $d['delivery_date'] ?? $d['created_at'],
                            'current_tank'   => $d['current_tank_level'] ?? null,
                            'tank_capacity'  => $d['tank_capacity'] ?? null,
                        ]), ENT_QUOTES) ?>)">
                        <i class="fas fa-eye"></i> View
                    </button>
                    <button class="act-btn approve" title="Approve"
                        onclick="openDeliveryApproveModal(<?= $d['id'] ?>,'<?= htmlspecialchars($d['fuel_type']) ?>',<?= $del_val ?>,'<?= htmlspecialchars($d['invoice_no'] ?? '') ?>',<?= $cur_val ?>,<?= $cap_val ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                    <button class="act-btn reject" title="Return / Reject"
                        onclick="openDeliveryReturnModal(<?= $d['id'] ?>,'<?= htmlspecialchars($d['fuel_type']) ?>',<?= $del_val ?>,'<?= htmlspecialchars($d['invoice_no'] ?? '') ?>')">
                        <i class="fas fa-undo"></i> Return
                    </button>
                </div>
                <?php else: ?>
                    <span style="color:#94a3b8;font-size:.75rem;"><i class="fas fa-check-circle" style="color:#86efac;"></i> Done</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php endif; ?>

</div>
</div>

<!-- ----------------------------------------------------------
     TAB 3: ADJUSTMENTS (MANAGER-ONLY)
---------------------------------------------------------- -->
<?php
// -- Recent price changes for this station (last 10) --
// Sources: fuel_price_log (new, structured) + fuel_adjustments with adjustment_type='price_update' (legacy)
$recent_price_changes = [];
try {
    // Ensure fuel_price_log table exists (DDL outside any transaction)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_price_log (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            station_id       INT NOT NULL,
            fuel_type_id     INT,
            fuel_type        VARCHAR(100) NOT NULL,
            old_price        DECIMAL(10,4) NOT NULL,
            new_price        DECIMAL(10,4) NOT NULL,
            price_difference DECIMAL(10,4) NOT NULL,
            change_type      VARCHAR(50) DEFAULT 'Price Update',
            reason_for_change TEXT,
            changed_by       INT NOT NULL,
            changed_by_name  VARCHAR(255),
            ip_address       VARCHAR(45),
            user_agent       TEXT,
            change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_fuel_type (fuel_type),
            INDEX idx_changed_by (changed_by),
            INDEX idx_timestamp (change_timestamp)
        )");
    } catch (Exception $tbl_e) { error_log("fuel_price_log create (display): " . $tbl_e->getMessage()); }

    // Check how many rows exist in fuel_price_log for this station
    $fpl_check = $pdo->prepare("SELECT COUNT(*) FROM fuel_price_log WHERE station_id = ?");
    $fpl_check->execute([$station_id]);
    $fpl_count = (int)$fpl_check->fetchColumn();

    if ($fpl_count > 0) {
        // Primary: fuel_price_log — fully structured with old/new price
        $stmt = $pdo->prepare("
            SELECT
                fpl.id,
                fpl.change_timestamp,
                COALESCE(fpl.fuel_type, ft.name, 'Unknown') AS fuel_type,
                fpl.old_price,
                fpl.new_price,
                fpl.price_difference,
                fpl.change_type,
                fpl.reason_for_change,
                COALESCE(u.name, fpl.changed_by_name, 'Manager') AS manager_name
            FROM fuel_price_log fpl
            LEFT JOIN users u ON fpl.changed_by = u.id
            LEFT JOIN fuel_types ft ON fpl.fuel_type_id = ft.id
            WHERE fpl.station_id = ?
            ORDER BY fpl.change_timestamp DESC
            LIMIT 10
        ");
        $stmt->execute([$station_id]);
        $recent_price_changes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fallback: fuel_adjustments with adjustment_type = 'price_update'
        $stmt = $pdo->prepare("
            SELECT
                fa.id,
                COALESCE(fa.created_at, CONCAT(fa.adjustment_date, ' 00:00:00')) AS change_timestamp,
                COALESCE(fa.fuel_type, ft.name, 'Unknown') AS fuel_type,
                NULL AS old_price,
                NULL AS new_price,
                NULL AS price_difference,
                'Price Update' AS change_type,
                fa.reason AS reason_for_change,
                COALESCE(u.name, 'Manager') AS manager_name
            FROM fuel_adjustments fa
            LEFT JOIN fuel_types ft ON fa.fuel_type_id = ft.id
            LEFT JOIN users u ON fa.user_id = u.id
            WHERE fa.station_id = ?
              AND fa.adjustment_type = 'price_update'
            ORDER BY COALESCE(fa.created_at, fa.adjustment_date) DESC
            LIMIT 10
        ");
        $stmt->execute([$station_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse old/new price from reason string for display
        // Pattern examples: "Price: ₱85.00 → ₱87.00/L." or "Price: ?85.00 ? ?87.00/L."
        foreach ($rows as &$row) {
            $reason = $row['reason_for_change'] ?? '';
            preg_match_all('/\d+(?:\.\d+)?/', $reason, $all_prices);
            if (!empty($all_prices[0]) && count($all_prices[0]) >= 2) {
                $row['old_price']        = (float)$all_prices[0][0];
                $row['new_price']        = (float)$all_prices[0][1];
                $row['price_difference'] = round($row['new_price'] - $row['old_price'], 4);
                $row['change_type']      = $row['price_difference'] >= 0 ? 'Price Increase' : 'Price Decrease / Rollback';
            }
        }
        unset($row);
        $recent_price_changes = $rows;
    }
} catch (Exception $e) { error_log("recent_price_changes: " . $e->getMessage()); }
?>
<div id="adjustments" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-sliders-h"></i> Adjustment</div>
    </div>

    <!-- Hidden Forms for Tank Level Adjustments (one per fuel type) -->
    <?php foreach ($tank_data as $t): ?>
    <form id="form_adj_<?php echo $t['fuel_type_id']; ?>" method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="adjust_tank_level">
        <input type="hidden" name="fuel_type_id" value="<?php echo $t['fuel_type_id']; ?>">
    </form>
    <?php endforeach; ?>

    <!-- Hidden Forms for Price Updates (one per fuel type) — submit via confirm modal -->
    <?php foreach ($tank_data as $t): ?>
    <form id="form_price_<?php echo $t['fuel_type_id']; ?>" method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="update_price">
        <input type="hidden" name="fuel_type_id" value="<?php echo $t['fuel_type_id']; ?>">
        <input type="hidden" name="new_price"    id="hidden_new_price_<?php echo $t['fuel_type_id']; ?>" value="">
        <input type="hidden" name="reason"       id="hidden_reason_<?php echo $t['fuel_type_id']; ?>"    value="">
    </form>
    <?php endforeach; ?>

    <div style="display:flex; flex-direction:column; gap:24px;">

        <!-- -- Tank Level Adjustment -- -->
        <div class="info-box" style="padding: 20px;">
            <h4 style="margin:0 0 14px;color:<?php echo $colors['primary']; ?>;"><i class="fas fa-database"></i> Tank Level Adjustment</h4>
            <div style="overflow-x:auto; border-radius:8px; border:1px solid #eef0f2; background:#fff; margin-top:8px;">
                <table class="data-table" style="font-size:0.82rem;  margin:0;">
                    <thead>
                        <tr style="background:#f8f9fa;">
                            <th style="width: 15%; padding: 12px 14px;">Fuel Type</th>
                            <th style="width: 15%; padding: 12px 14px;">Current Level</th>
                            <th style="width: 18%; padding: 12px 14px;">New Level (L)</th>
                            <th style="width: 18%; padding: 12px 14px;">Adjustment Type</th>
                            <th style="width: 24%; padding: 12px 14px;">Detailed Reason <span style="font-weight:400;color:#aaa;font-size:.75rem;">(Optional)</span></th>
                            <th style="width: 10%; padding: 12px 14px; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tank_data as $t): ?>
                        <tr>
                            <td style="padding: 12px 14px; vertical-align: middle;">
                                <strong><?php echo htmlspecialchars($t['fuel_type_name']); ?></strong>
                            </td>
                            <?php $safe_stock = (float)($t['current_stock'] ?? 0); ?>
                            <td style="padding: 12px 14px; vertical-align: middle; font-weight: 600; color: #555;">
                                <?php echo number_format($safe_stock, 2); ?> L
                                <?php if (!empty($t['capacity']) && (float)$t['capacity'] > 0): ?>
                                <div style="font-size:.7rem;color:#aaa;margin-top:2px;">Capacity: <?php echo number_format((float)$t['capacity'], 0); ?> L</div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 8px 14px; vertical-align: middle;">
                                <input type="number" name="new_level" 
                                    form="form_adj_<?php echo $t['fuel_type_id']; ?>" 
                                    class="form-control new-level-input" 
                                    data-fuel-id="<?php echo $t['fuel_type_id']; ?>" 
                                    data-current-stock="<?php echo $safe_stock; ?>"
                                    step="0.01" min="0" 
                                    <?php if (!empty($t['capacity']) && (float)$t['capacity'] > 0): ?>max="<?php echo (float)$t['capacity']; ?>"<?php endif; ?>
                                    required 
                                    placeholder="Enter new level (L)" 
                                    style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 0.82rem;">
                                <div id="diff_hint_<?php echo $t['fuel_type_id']; ?>" style="font-size:.72rem; margin-top:4px; display:none; font-weight: 600;"></div>
                            </td>
                            <td style="padding: 8px 14px; vertical-align: middle;">
                                <select name="adjustment_type" 
                                    form="form_adj_<?php echo $t['fuel_type_id']; ?>" 
                                    class="form-control" required 
                                    style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 0.82rem; min-width: 130px;">
                                    <option value="">Select type...</option>
                                    <option value="delivery">Fuel Delivery</option>
                                    <option value="calibration">Calibration Adjustment</option>
                                    <option value="manual">Manual Stock Correction</option>
                                    <option value="evaporation">Evaporation Loss</option>
                                    <option value="spillage">Spillage / Wastage</option>
                                </select>
                            </td>
                            <td style="padding: 8px 14px; vertical-align: middle;">
                                <input type="text" name="reason" 
                                    form="form_adj_<?php echo $t['fuel_type_id']; ?>" 
                                    class="form-control" 
                                    placeholder="Optional reason / notes" 
                                    style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 0.82rem;">
                            </td>
                            <td style="padding: 8px 14px; vertical-align: middle; text-align: center;">
                                <button type="submit" 
                                    form="form_adj_<?php echo $t['fuel_type_id']; ?>" 
                                    class="btn btn-sm" 
                                    style="background:#003d82; color:white; border:none; padding: 6px 14px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; cursor: pointer; white-space: nowrap;">
                                    <i class="fas fa-edit"></i> Apply
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- -- Price Per Liter Update (tabbed) -- -->
        <div class="info-box" style="padding: 0; overflow:hidden;">

            <!-- Tab bar -->
            <div style="display:flex; border-bottom:1px solid #e9ecef; background:#f8f9fa;">
                <button type="button" id="ptab-update"
                    onclick="switchPriceTab('update')"
                    style="padding:12px 20px; font-size:.82rem; font-weight:600; border:none; background:transparent; cursor:pointer; border-bottom:2px solid <?php echo $colors['primary']; ?>; color:<?php echo $colors['primary']; ?>;">
                    <i class="fas fa-tag"></i> Price Per Liter Update
                </button>
                <button type="button" id="ptab-history"
                    onclick="switchPriceTab('history')"
                    style="padding:12px 20px; font-size:.82rem; font-weight:600; border:none; background:transparent; cursor:pointer; border-bottom:2px solid transparent; color:#888;">
                    <i class="fas fa-history"></i> Recent Price Changes
                    <?php if (!empty($recent_price_changes)): ?>
                    <span style="background:#e8f4fd; color:#0056b3; font-size:.7rem; padding:1px 6px; border-radius:10px; margin-left:4px;"><?php echo count($recent_price_changes); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Panel: Update -->
            <div id="ppanel-update" style="padding:20px;">
                <div style="overflow-x:auto; border-radius:8px; border:1px solid #eef0f2; background:#fff;">
                    <table class="data-table" style="font-size:0.82rem;  margin:0;">
                        <thead>
                            <tr style="background:#f8f9fa;">
                                <th style="width: 18%; padding: 12px 14px;">Fuel Type</th>
                                <th style="width: 18%; padding: 12px 14px;">Current Price</th>
                                <th style="width: 20%; padding: 12px 14px;">New Price (&#8369;/L)</th>
                                <th style="width: 32%; padding: 12px 14px;">Reason for Change <span style="font-weight:400;color:#aaa;font-size:.75rem;">(Optional)</span></th>
                                <th style="width: 12%; padding: 12px 14px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tank_data as $t): ?>
                            <tr>
                                <td style="padding: 12px 14px; vertical-align: middle;">
                                    <strong><?php echo htmlspecialchars($t['fuel_type_name']); ?></strong>
                                </td>
                                <td style="padding: 12px 14px; vertical-align: middle; font-weight: 700; color: <?php echo $colors['primary']; ?>; font-size:.9rem;">
                                    &#8369;<?php echo number_format($t['price_per_liter']??0,2); ?>/L
                                </td>
                                <td style="padding: 8px 14px; vertical-align: middle;">
                                    <input type="number"
                                        id="price_input_<?php echo $t['fuel_type_id']; ?>"
                                        class="form-control new-price-input"
                                        data-fuel-id="<?php echo $t['fuel_type_id']; ?>"
                                        data-current-price="<?php echo $t['price_per_liter'] ?? 0; ?>"
                                        data-fuel-name="<?php echo htmlspecialchars($t['fuel_type_name'], ENT_QUOTES); ?>"
                                        step="0.01" min="0.01" max="<?php echo $business_rules['max_price_per_liter']; ?>"
                                        placeholder="Enter new price"
                                        style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 0.82rem;">
                                    <div id="price_diff_hint_<?php echo $t['fuel_type_id']; ?>" style="font-size:.72rem; margin-top:4px; display:none; font-weight: 600;"></div>
                                </td>
                                <td style="padding: 8px 14px; vertical-align: middle;">
                                    <input type="text"
                                        id="reason_input_<?php echo $t['fuel_type_id']; ?>"
                                        class="form-control"
                                        placeholder="Optional - e.g. Supplier memo, DOE advisory"
                                        style="width: 100%; box-sizing: border-box; padding: 6px 10px; font-size: 0.82rem;">
                                </td>
                                <td style="padding: 8px 14px; vertical-align: middle; text-align: center;">
                                    <button type="button"
                                        class="btn-price-update"
                                        data-fuel-id="<?php echo $t['fuel_type_id']; ?>"
                                        data-current-price="<?php echo $t['price_per_liter'] ?? 0; ?>"
                                        data-fuel-name="<?php echo htmlspecialchars($t['fuel_type_name'], ENT_QUOTES); ?>"
                                        style="background:<?php echo $colors['primary']; ?>; color:white; border:none; padding: 6px 14px; font-size: 0.78rem; font-weight: 600; border-radius: 4px; cursor: pointer; white-space: nowrap;">
                                        <i class="fas fa-tag"></i> Update Price
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Panel: History -->
            <div id="ppanel-history" style="padding:20px; display:none;">
                <div style="display:flex; align-items:center; justify-content:flex-end; margin-bottom:10px;">
                    <a href="manager_reports.php?section=price_logs" style="font-size:.75rem; color:#0056b3; text-decoration:none;"><i class="fas fa-external-link-alt"></i> View Full Log</a>
                </div>
                <?php if (empty($recent_price_changes)): ?>
                <div style="text-align:center; padding:28px; color:#aaa; font-size:.82rem; background:#f8f9fa; border-radius:6px;">
                    <i class="fas fa-tags" style="font-size:1.4rem; display:block; margin-bottom:8px;"></i>
                    No price changes recorded yet.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto; border-radius:8px; border:1px solid #eef0f2; background:#fff;">
                    <table class="data-table" style="font-size:0.78rem; min-width:700px; margin:0;">
                        <thead>
                            <tr style="background:#f8f9fa;">
                                <th style="padding:10px 12px;">Date &amp; Time</th>
                                <th style="padding:10px 12px;">Fuel Type</th>
                                <th style="padding:10px 12px;">Old Price</th>
                                <th style="padding:10px 12px;">New Price</th>
                                <th style="padding:10px 12px;">Change</th>
                                <th style="padding:10px 12px;">Type</th>
                                <th style="padding:10px 12px;">Reason</th>
                                <th style="padding:10px 12px;">Manager</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_price_changes as $pc):
                            $diff = (float)$pc['price_difference'];
                            $diff_color = $diff > 0 ? '#dc3545' : ($diff < 0 ? '#28a745' : '#888');
                            $diff_icon  = $diff > 0 ? '&#9650;' : ($diff < 0 ? '&#9660;' : '&mdash;');
                            $diff_label = ($diff >= 0 ? '+' : '') . '&#8369;' . number_format(abs($diff), 2);
                        ?>
                        <tr>
                            <td style="padding:9px 12px; white-space:nowrap; color:#555;">
                                <?php echo date('M j, Y', strtotime($pc['change_timestamp'])); ?><br>
                                <span style="font-size:.7rem; color:#aaa;"><?php echo date('g:i A', strtotime($pc['change_timestamp'])); ?></span>
                            </td>
                            <td style="padding:9px 12px;"><strong><?php echo htmlspecialchars($pc['fuel_type']); ?></strong></td>
                            <td style="padding:9px 12px; color:#555;">&#8369;<?php echo number_format((float)$pc['old_price'], 2); ?>/L</td>
                            <td style="padding:9px 12px; font-weight:700; color:<?php echo $colors['primary']; ?>;">&#8369;<?php echo number_format((float)$pc['new_price'], 2); ?>/L</td>
                            <td style="padding:9px 12px; font-weight:700; color:<?php echo $diff_color; ?>;">
                                <?php echo $diff_icon; ?> <?php echo $diff_label; ?>
                            </td>
                            <td style="padding:9px 12px;">
                                <?php $ct = strtolower($pc['change_type'] ?? ''); ?>
                                <span style="font-size:.7rem; padding:2px 7px; border-radius:10px; font-weight:600;
                                    background:<?php echo (strpos($ct,'rollback')!==false||strpos($ct,'decrease')!==false) ? '#fff3cd' : '#e8f4fd'; ?>;
                                    color:<?php echo (strpos($ct,'rollback')!==false||strpos($ct,'decrease')!==false) ? '#856404' : '#0056b3'; ?>;">
                                    <?php echo htmlspecialchars($pc['change_type'] ?? 'Price Update'); ?>
                                </span>
                            </td>
                            <td style="padding:9px 12px; max-width:200px; color:#555; font-size:.75rem;">
                                <?php echo htmlspecialchars(mb_strimwidth($pc['reason_for_change'] ?? '', 0, 80, '...')); ?>
                            </td>
                            <td style="padding:9px 12px; color:#555;">
                                <i class="fas fa-user-tie" style="opacity:.5; margin-right:3px;"></i>
                                <?php echo htmlspecialchars($pc['manager_name'] ?? $pc['changed_by_name'] ?? 'Manager'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>
</div>

<script>
function switchPriceTab(tab) {
    var tabs   = ['update', 'history'];
    var primary = '<?php echo $colors['primary']; ?>';
    tabs.forEach(function(t) {
        var btn   = document.getElementById('ptab-' + t);
        var panel = document.getElementById('ppanel-' + t);
        if (!btn || !panel) return;
        var active = (t === tab);
        btn.style.borderBottomColor = active ? primary : 'transparent';
        btn.style.color             = active ? primary : '#888';
        panel.style.display         = active ? 'block' : 'none';
    });
}
</script>

<!-- ── Price Update Confirmation Modal ─────────────────────── -->
<div id="modal-price-confirm" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; padding:28px 28px 22px; max-width:440px; width:92%; box-shadow:0 8px 32px rgba(0,0,0,.18); position:relative;">
        <h4 style="margin:0 0 6px; color:#00264D; font-size:1rem;"><i class="fas fa-tag"></i> Confirm Price Update</h4>
        <p style="margin:0 0 18px; font-size:.82rem; color:#666;">Review the change below before applying. This action is logged and cannot be silently undone — use a rollback entry to revert.</p>
        <div id="price-confirm-summary" style="background:#f8f9fa; border-radius:8px; padding:14px 16px; margin-bottom:18px; font-size:.85rem; line-height:1.7;"></div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <button type="button" id="btn-price-cancel" style="padding:8px 18px; border:1px solid #dee2e6; background:#fff; border-radius:5px; cursor:pointer; font-size:.82rem;">Cancel</button>
            <button type="button" id="btn-price-confirm" style="padding:8px 20px; background:#00264D; color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:.82rem; font-weight:600;"><i class="fas fa-check"></i> Confirm &amp; Apply</button>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Price diff hint ──────────────────────────────────────────
    document.querySelectorAll('.new-price-input').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var fid   = this.dataset.fuelId;
            var cur   = parseFloat(this.dataset.currentPrice) || 0;
            var nv    = parseFloat(this.value) || 0;
            var hint  = document.getElementById('price_diff_hint_' + fid);
            if (!hint) return;
            if (!this.value || nv === cur) { hint.style.display = 'none'; return; }
            var diff  = nv - cur;
            var sign  = diff > 0 ? '+' : '';
            var color = diff > 0 ? '#dc3545' : '#28a745';
            var label = diff > 0 ? 'Price Increase' : 'Price Decrease / Rollback';
            hint.style.display = 'block';
            hint.style.color   = color;
            hint.textContent   = sign + '\u20B1' + Math.abs(diff).toFixed(2) + '/L  (' + label + ')';
        });
    });

    // ── Confirmation modal ───────────────────────────────────────
    var modal      = document.getElementById('modal-price-confirm');
    var summary    = document.getElementById('price-confirm-summary');
    var btnConfirm = document.getElementById('btn-price-confirm');
    var btnCancel  = document.getElementById('btn-price-cancel');
    var pendingFid = null;

    document.querySelectorAll('.btn-price-update').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var fid       = this.dataset.fuelId;
            var cur       = parseFloat(this.dataset.currentPrice) || 0;
            var name      = this.dataset.fuelName;
            var priceInp  = document.getElementById('price_input_' + fid);
            var reasonInp = document.getElementById('reason_input_' + fid);

            if (!priceInp || !reasonInp) return;
            var nv     = parseFloat(priceInp.value);
            var reason = reasonInp.value.trim();

            if (!priceInp.value || isNaN(nv) || nv <= 0) {
                priceInp.focus();
                priceInp.style.borderColor = '#dc3545';
                setTimeout(function () { priceInp.style.borderColor = ''; }, 2000);
                return;
            }
            // reason is optional — no minimum length check
            if (nv === cur) {
                alert('New price is the same as the current price. No update needed.');
                return;
            }

            var diff  = nv - cur;
            var sign  = diff > 0 ? '+' : '';
            var color = diff > 0 ? '#dc3545' : '#28a745';
            var label = diff > 0 ? '\u25B2 Price Increase' : '\u25BC Price Decrease / Rollback';

            summary.innerHTML =
                '<strong>' + name + '</strong><br>' +
                'Current Price: <strong>\u20B1' + cur.toFixed(2) + '/L</strong><br>' +
                'New Price: <strong>\u20B1' + nv.toFixed(2) + '/L</strong><br>' +
                'Change: <strong style="color:' + color + ';">' + sign + '\u20B1' + Math.abs(diff).toFixed(2) + '/L &nbsp;' + label + '</strong><br>' +
                'Reason: <em>' + reason.replace(/</g, '&lt;') + '</em>';

            pendingFid = fid;
            modal.style.display = 'flex';
        });
    });

    if (btnCancel) btnCancel.addEventListener('click', function () {
        modal.style.display = 'none';
        pendingFid = null;
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal) { modal.style.display = 'none'; pendingFid = null; }
    });

    if (btnConfirm) btnConfirm.addEventListener('click', function () {
        if (!pendingFid) return;
        var priceInp  = document.getElementById('price_input_' + pendingFid);
        var reasonInp = document.getElementById('reason_input_' + pendingFid);
        var hiddenP   = document.getElementById('hidden_new_price_' + pendingFid);
        var hiddenR   = document.getElementById('hidden_reason_' + pendingFid);
        if (!priceInp || !reasonInp || !hiddenP || !hiddenR) return;
        hiddenP.value = priceInp.value;
        hiddenR.value = reasonInp.value.trim();
        modal.style.display = 'none';
        document.getElementById('form_price_' + pendingFid).submit();
    });
}());
</script>

<!-- ----------------------------------------------------------
     TAB 4: RECONCILIATION
---------------------------------------------------------- -->
<div id="reconciliation" class="fuel-section">
<div class="fuel-section-inner">

<?php
// -- Last delivery date per fuel type --
$last_delivery_map = [];
try {
    $stmt = $pdo->prepare("SELECT LOWER(TRIM(fuel_type)) as ft_key, MAX(delivery_date) as last_delivery FROM fuel_deliveries WHERE station_id=? GROUP BY LOWER(TRIM(fuel_type))");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $last_delivery_map[$r['ft_key']] = $r['last_delivery'];
} catch (Exception $e) { error_log("last_delivery_map: ".$e->getMessage()); }

// -- Last pump reading per fuel type --
$last_reading_map = [];
try {
    $stmt = $pdo->prepare("SELECT LOWER(TRIM(fuel_type)) as ft_key, MAX(transaction_date) as last_reading_date, MAX(present_reading) as last_pump_reading FROM fuel_transactions WHERE station_id=? GROUP BY LOWER(TRIM(fuel_type))");
    $stmt->execute([$station_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $last_reading_map[$r['ft_key']] = $r;
} catch (Exception $e) { error_log("last_reading_map: ".$e->getMessage()); }

// -- Critical low fuels --
$critical_fuels = [];
foreach ($reconciliation_data as $rec) {
    $fp = $rec['capacity'] > 0 ? ($rec['current_stock'] / $rec['capacity']) * 100 : 0;
    if ($fp <= 15) $critical_fuels[] = htmlspecialchars($rec['fuel_type_name']);
}
?>

    <div class="section-head">
        <div class="section-title"><i class="fas fa-balance-scale"></i> Reconciliation — Pump Sales vs Tank Levels</div>
        <span class="audit-badge"><i class="fas fa-calendar-day"></i> <?php echo date('M j, Y'); ?></span>
    </div>

    <?php if (!empty($critical_fuels)): ?>
    <div style="border-left:4px solid <?php echo $colors['danger']; ?>;background:#fff8f8;border-radius:0 8px 8px 0;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <i class="fas fa-bell" style="color:<?php echo $colors['danger']; ?>;font-size:1rem;"></i>
        <div style="flex:1;">
            <strong style="color:<?php echo $colors['danger']; ?>;font-size:.88rem;">Critical Low Stock:</strong>
            <span style="color:#555;font-size:.85rem;"> <?php echo implode(', ', $critical_fuels); ?>  -  below 15%. Coordinate with Admin to trigger Purchase Order.</span>
        </div>
        <span class="audit-badge" style="background:<?php echo $colors['danger']; ?>;color:#fff;white-space:nowrap;">
            <i class="fas fa-user-tie"></i> Manager + Admin
        </span>
    </div>
    <?php endif; ?>

    <!-- Today's Pump Sales vs Tank Summary -->
    <?php if (!empty($reconciliation_data)): ?>
    <div class="section-head" style="margin-top:4px;">
        <div class="section-title"><i class="fas fa-chart-bar"></i> Today's Pump Sales vs Tank Summary</div>
    </div>
    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; ">
        <thead><tr>
            <th>Fuel Type</th><th>Current Stock</th><th>Capacity</th>
            <th>Sold Today</th><th>Last Delivery</th><th>Last Pump Reading</th>
            <th>Fill %</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($reconciliation_data as $rec):
            $fill_pct  = $rec['capacity'] > 0 ? ($rec['current_stock'] / $rec['capacity']) * 100 : 0;
            $ft_key    = strtolower(trim($rec['fuel_type_name']));
            $last_del  = $last_delivery_map[$ft_key] ?? null;
            $last_rdg  = $last_reading_map[$ft_key]  ?? null;
            $bar_color = $fill_pct > 40 ? $colors['success'] : ($fill_pct > 15 ? $colors['warning'] : $colors['danger']);
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($rec['fuel_type_name']); ?></strong></td>
            <td><?php echo number_format($rec['current_stock'], 2); ?> L</td>
            <td><?php echo $rec['capacity'] ? number_format($rec['capacity'], 2).' L' : 'N/A'; ?></td>
            <td><strong><?php echo number_format($rec['total_sold_today'], 2); ?> L</strong></td>
            <td style="font-size:.78rem;color:#555;">
                <?php echo $last_del ? date('M j, Y', strtotime($last_del)) : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td style="font-size:.78rem;">
                <?php if ($last_rdg): ?>
                    <strong><?php echo number_format($last_rdg['last_pump_reading'], 2); ?></strong>
                    <span style="color:#aaa;font-size:.7rem;display:block;"><?php echo date('M j H:i', strtotime($last_rdg['last_reading_date'])); ?></span>
                <?php else: ?>
                    <span style="color:#bbb;"> - </span>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;">
                    <div style="flex:1;background:#e9ecef;border-radius:4px;height:8px;min-width:50px;">
                        <div style="width:<?php echo min(100,$fill_pct); ?>%;height:100%;border-radius:4px;background:<?php echo $bar_color; ?>"></div>
                    </div>
                    <span style="font-size:.8rem;font-weight:600;"><?php echo number_format($fill_pct,1); ?>%</span>
                </div>
            </td>
            <td>
                <?php if ($fill_pct <= 0): ?>
                    <span class="tag-investigate">OUT OF STOCK</span>
                <?php elseif ($fill_pct <= 15): ?>
                    <span class="tag-investigate">CRITICAL LOW</span>
                <?php elseif ($fill_pct <= 40): ?>
                    <span class="tag-open">LOW STOCK</span>
                <?php else: ?>
                    <span class="tag-resolved">NORMAL</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>

    <!-- Optional Trend Chart (collapsible) -->
    <div style="margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:8px;margin:8px 0;cursor:pointer;user-select:none;" onclick="toggleTrendChart()">
            <span style="font-size:.85rem;font-weight:600;color:<?php echo $colors['primary']; ?>;"><i class="fas fa-chart-line"></i> Tank Level vs Sales Trend (Last 7 Days)</span>
            <span id="trendToggleIcon" style="font-size:.75rem;color:#888;"><i class="fas fa-chevron-down"></i> Show</span>
        </div>
        <div id="trendChartWrap" style="display:none;background:#f8f9fa;border-radius:8px;padding:16px;border:1px solid #e9ecef;">
            <canvas id="trendChart" height="80"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Discrepancy Log -->
    <div class="section-head" style="padding-top:16px;border-top:2px solid #e9ecef;">
        <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Discrepancy Log</div>
        <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
            <span style="font-size:.72rem;color:#888;">Lifecycle:</span>
            <span class="tag-open" style="font-size:.68rem;padding:2px 7px;">Open</span>
            <span style="font-size:.7rem;color:#ccc;">?</span>
            <span class="tag-investigating" style="font-size:.68rem;padding:2px 7px;">Investigating</span>
            <span style="font-size:.7rem;color:#ccc;">?</span>
            <span class="tag-resolved" style="font-size:.68rem;padding:2px 7px;">Resolved</span>
            <?php if ($high_variances > 0): ?>
            <span class="tag-investigate" style="margin-left:10px;font-size:.68rem;padding:2px 7px;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $high_variances; ?> &gt;5%
            </span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($variance_reports)): ?>
        <div class="empty-state"><i class="fas fa-check-circle"></i><p>No discrepancies found. All reconciled.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; ">
        <thead><tr>
            <th>#</th><th>Date</th><th>Fuel Type</th>
            <th>Variance (L)</th><th>Variance %</th>
            <th>Cause / Reason</th><th>Resolution Notes</th>
            <th>Status</th><th>Manager</th><th>Timestamp</th><th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($variance_reports as $v):
            $vp     = abs($v['variance_percent'] ?? 0);
            $vp_val = (float)($v['variance_percent'] ?? 0);
            $vl     = (float)($v['variance_liters'] ?? 0);
            $st     = $v['status'] ?? 'Open';
            $st_key = strtolower(str_replace(' ', '_', $st));
            $vl_cls = $vl >= 0 ? 'var-ok' : 'var-crit';
            $vp_cls = $vp > 10 ? 'var-crit' : ($vp > 5 ? 'var-warn' : 'var-ok');
        ?>
        <tr>
            <td><strong style="font-size:.78rem;">#<?php echo $v['id']; ?></strong></td>
            <td style="font-size:.78rem;white-space:nowrap;"><?php echo date('M j, Y', strtotime($v['report_date'])); ?></td>
            <td><strong><?php echo htmlspecialchars($v['fuel_type']); ?></strong></td>
            <td class="<?php echo $vl_cls; ?>" style="font-weight:700;white-space:nowrap;">
                <?php echo ($vl >= 0 ? '+' : '-"') . number_format($vl, 2); ?> L
            </td>
            <td>
                <span class="<?php echo $vp_cls; ?>" style="font-weight:700;"><?php echo number_format($vp_val, 2); ?>%</span>
                <?php if ($vp > 5): ?><div style="font-size:.63rem;color:<?php echo $colors['danger']; ?>;font-weight:700;">? INVESTIGATE</div><?php endif; ?>
            </td>
            <td style="max-width:150px;font-size:.78rem;">
                <?php $cause = $v['reason'] ?? '';
                echo $cause ? '<span title="'.htmlspecialchars($cause).'">'.htmlspecialchars(mb_strimwidth($cause,0,45,' - ')).'</span>' : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td style="max-width:150px;font-size:.78rem;">
                <?php $rn = $v['resolution_notes'] ?? '';
                echo $rn ? '<span title="'.htmlspecialchars($rn).'">'.htmlspecialchars(mb_strimwidth($rn,0,45,' - ')).'</span>' : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td>
                <?php if ($st === 'Resolved'): ?>
                    <span class="tag-resolved"><i class="fas fa-check"></i> Resolved</span>
                <?php elseif ($st === 'Under Investigation'): ?>
                    <span class="tag-investigating"><i class="fas fa-search"></i> Investigating</span>
                <?php else: ?>
                    <span class="tag-open"><i class="fas fa-circle"></i> Open</span>
                <?php endif; ?>
            </td>
            <td style="font-size:.78rem;">
                <?php echo $v['resolved_by_name']
                    ? '<span class="audit-badge"><i class="fas fa-user-tie"></i> '.htmlspecialchars($v['resolved_by_name']).'</span>'
                    : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td style="font-size:.75rem;color:#555;white-space:nowrap;">
                <?php echo !empty($v['updated_at'])
                    ? date('M j H:i', strtotime($v['updated_at']))
                    : date('M j H:i', strtotime($v['created_at'])); ?>
            </td>
            <td class="col-actions" style="white-space:nowrap;">
                <?php if ($st !== 'Resolved'): ?>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                    <button class="act-btn investigate" title="<?php echo $st === 'Under Investigation' ? 'Resolve' : 'Investigate'; ?>"
                        onclick="openVarianceModal(<?php echo $v['id']; ?>,'<?php echo $st_key; ?>')">
                        <i class="fas fa-<?php echo $st === 'Under Investigation' ? 'check' : 'search'; ?>"></i>
                        <?php echo $st === 'Under Investigation' ? 'Resolve' : 'Investigate'; ?>
                    </button>
                </div>
                <?php else: ?>
                <button class="act-btn view" title="View"
                    onclick="openVarianceModal(<?php echo $v['id']; ?>,'view')">
                    <i class="fas fa-eye"></i> View
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div style="margin-top:8px;font-size:.75rem;color:#888;">
        <i class="fas fa-shield-alt"></i> Every status change is auto-logged with Manager ID + timestamp in the audit trail.
    </div>
    <?php endif; ?>

</div>
</div>

<!-- ----------------------------------------------------------
     TAB 5: VARIANCE REPORTS
---------------------------------------------------------- -->
<div id="variance-reports" class="fuel-section">
<div class="fuel-section-inner">

<?php
// Counts for badge
$vr_open  = count(array_filter($variance_reports, fn($v) => ($v['status']??'Open') === 'Open'));
$vr_inv   = count(array_filter($variance_reports, fn($v) => ($v['status']??'') === 'Under Investigation'));
$vr_res   = count(array_filter($variance_reports, fn($v) => ($v['status']??'') === 'Resolved'));
$vr_pending = $vr_open + $vr_inv; // pending = not yet resolved
?>

    <div class="section-head">
        <div class="section-title"><i class="fas fa-chart-line"></i> Variance Reports</div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <!-- Export Controls -->
            <div style="display:flex;gap:8px;align-items:center;padding:8px 12px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef;">
                <form method="post" action="manager_fuel_management_complete.php" style="display:flex;gap:8px;align-items:center;margin:0;">
                    <input type="hidden" name="action" value="export_variance">
                    <div style="display:flex;align-items:center;gap:4px;">
                        <label style="font-size:.75rem;color:#666;font-weight:500;margin:0;">From:</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo date('Y-m-01'); ?>" style="width:120px;font-size:.8rem;padding:3px 6px;border:1px solid #ddd;">
                    </div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <label style="font-size:.75rem;color:#666;font-weight:500;margin:0;">To:</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo date('Y-m-d'); ?>" style="width:120px;font-size:.8rem;padding:3px 6px;border:1px solid #ddd;">
                    </div>
                    <select name="format" class="form-control" style="width:80px;font-size:.8rem;padding:3px 6px;border:1px solid #ddd;">
                        <option value="excel">Excel</option>
                        <option value="pdf">PDF</option>
                    </select>
                    <button type="submit" class="btn btn-info" style="font-size:.8rem;padding:4px 12px;"><i class="fas fa-download"></i> Export</button>
                </form>
            </div>
            <!-- Status Indicators -->
            <div style="display:flex;gap:8px;align-items:center;">
                <?php if ($vr_pending > 0): ?>
                <div style="display:flex;align-items:center;gap:4px;padding:4px 8px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;">
                    <i class="fas fa-clock" style="color:#CC8800;font-size:.8rem;"></i>
                    <span style="font-size:.75rem;color:#CC8800;font-weight:600;"><?php echo $vr_pending; ?> Pending</span>
                </div>
                <?php endif; ?>
                <?php if ($high_variances > 0): ?>
                <div style="display:flex;align-items:center;gap:4px;padding:4px 8px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;">
                    <i class="fas fa-exclamation-triangle" style="color:#721c24;font-size:.8rem;"></i>
                    <span style="font-size:.75rem;color:#721c24;font-weight:600;"><?php echo $high_variances; ?> Priority</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Summary Statistics -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px;">
        <div style="background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#007bff;"><?php echo count($variance_reports); ?></div>
            <div style="font-size:.75rem;color:#666;margin-top:2px;">Total Reports</div>
        </div>
        <div style="background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#dc3545;"><?php echo $vr_open; ?></div>
            <div style="font-size:.75rem;color:#666;margin-top:2px;">Open Cases</div>
        </div>
        <div style="background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#17a2b8;"><?php echo $vr_inv; ?></div>
            <div style="font-size:.75rem;color:#666;margin-top:2px;">Under Investigation</div>
        </div>
        <div style="background:#fff;border:1px solid #e9ecef;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:700;color:#28a745;"><?php echo $vr_res; ?></div>
            <div style="font-size:.75rem;color:#666;margin-top:2px;">Resolved</div>
        </div>
    </div>

    <!-- Variance Table -->
    <div class="section-head">
        <div class="section-title"><i class="fas fa-list-alt"></i> Detailed Variance Log</div>
        <span class="audit-badge"><i class="fas fa-shield-alt"></i> Auto-logged with Manager ID + timestamp</span>
    </div>

    <?php if (empty($variance_reports)): ?>
        <div class="empty-state"><i class="fas fa-chart-line"></i><p>No variance reports found for this station.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; ">
        <thead><tr>
            <th>#</th>
            <th>Date</th>
            <th>Fuel Type</th>
            <th>Variance (L)</th>
            <th>Variance %</th>
            <th>Severity</th>
            <th>Status</th>
            <th>Resolution Notes</th>
            <th>Manager</th>
            <th>Timestamp</th>
            <th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($variance_reports as $v):
            $vp       = abs($v['variance_percent'] ?? 0);
            $vp_val   = (float)($v['variance_percent'] ?? 0);
            $vl       = (float)($v['variance_liters'] ?? 0);
            $st       = $v['status'] ?? 'Open'; // real enum: Open, Under Investigation, Resolved
            $st_key   = strtolower(str_replace(' ', '_', $st));
            $severity = $vp > 10 ? 'Critical' : ($vp > 5 ? 'High' : ($vp > 2 ? 'Medium' : 'Low'));
            $sev_cls  = $severity === 'Critical' ? 'tag-investigate' : ($severity === 'High' ? 'tag-open' : ($severity === 'Medium' ? 'tag-investigating' : 'tag-resolved'));
            $vl_cls   = $vl >= 0 ? 'var-ok' : 'var-crit';
            $vp_cls   = $vp > 10 ? 'var-crit' : ($vp > 5 ? 'var-warn' : 'var-ok');
        ?>
        <tr>
            <td><strong style="font-size:.78rem;">#<?php echo $v['id']; ?></strong></td>
            <td style="font-size:.8rem;white-space:nowrap;"><?php echo date('M j, Y', strtotime($v['report_date'])); ?></td>
            <td><strong><?php echo htmlspecialchars($v['fuel_type']); ?></strong></td>
            <td class="<?php echo $vl_cls; ?>" style="font-weight:700;white-space:nowrap;">
                <?php echo ($vl >= 0 ? '+' : '-"') . number_format($vl, 2); ?> L
            </td>
            <td>
                <span class="<?php echo $vp_cls; ?>" style="font-weight:700;"><?php echo number_format($vp_val, 2); ?>%</span>
            </td>
            <td>
                <span class="<?php echo $sev_cls; ?>" style="font-size:.72rem;padding:2px 7px;"><?php echo $severity; ?></span>
                <?php if ($vp > 5): ?>
                <div style="font-size:.63rem;color:<?php echo $colors['danger']; ?>;font-weight:700;margin-top:2px;">? Immediate</div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($st === 'Resolved'): ?>
                    <span class="tag-resolved"><i class="fas fa-check"></i> Resolved</span>
                <?php elseif ($st === 'Under Investigation'): ?>
                    <span class="tag-investigating"><i class="fas fa-search"></i> Investigating</span>
                <?php else: ?>
                    <span class="tag-open"><i class="fas fa-circle"></i> Open</span>
                <?php endif; ?>
            </td>
            <td style="max-width:180px;font-size:.78rem;">
                <?php $rn = $v['resolution_notes'] ?? '';
                echo $rn
                    ? '<span title="'.htmlspecialchars($rn).'">'.htmlspecialchars(mb_strimwidth($rn, 0, 55, ' - ')).'</span>'
                    : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td style="font-size:.78rem;">
                <?php echo $v['resolved_by_name']
                    ? '<span class="audit-badge"><i class="fas fa-user-tie"></i> '.htmlspecialchars($v['resolved_by_name']).'</span>'
                    : '<span style="color:#bbb;"> - </span>'; ?>
            </td>
            <td style="font-size:.75rem;color:#555;white-space:nowrap;">
                <?php echo !empty($v['updated_at'])
                    ? date('M j, Y H:i', strtotime($v['updated_at']))
                    : date('M j, Y H:i', strtotime($v['created_at'])); ?>
            </td>
            <td class="col-actions" style="white-space:nowrap;">
                <?php if ($st !== 'Resolved'): ?>
                <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                    <button class="act-btn investigate" title="<?php echo $st === 'Under Investigation' ? 'Resolve' : 'Investigate'; ?>"
                        onclick="openVarianceModal(<?php echo $v['id']; ?>,'<?php echo $st_key; ?>')">
                        <i class="fas fa-<?php echo $st === 'Under Investigation' ? 'check' : 'search'; ?>"></i>
                        <?php echo $st === 'Under Investigation' ? 'Resolve' : 'Investigate'; ?>
                    </button>
                </div>
                <?php else: ?>
                <span class="tag-resolved" style="font-size:.72rem;"><i class="fas fa-check"></i> Done</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div style="margin-top:8px;font-size:.75rem;color:#888;display:flex;gap:16px;flex-wrap:wrap;">
        <span><i class="fas fa-shield-alt"></i> Every action auto-logged: Manager ID + timestamp + notes</span>
        <span><i class="fas fa-file-export"></i> Use Export above to generate compliance report for Admin/regulatory</span>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- ----------------------------------------------------------
     TAB 6: SHIFT HISTORY (READ-ONLY)
---------------------------------------------------------- -->
<div id="shift-history" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-history"></i> Shift History  -  Read-Only Audit Log</div>
        <span class="readonly-badge"><i class="fas fa-lock"></i> Read-Only</span>
    </div>

    <!-- Filters -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding:14px 16px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef;">
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Date</label>
            <input type="date" id="histDateFilter" class="form-control" style="width:160px;" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Shift</label>
            <select id="histShiftFilter" class="form-control" style="width:180px;">
                <option value="">All Shifts</option>
                <?php foreach ($shift_periods as $sp): ?>
                <option value="<?php echo htmlspecialchars($sp['shift_key']); ?>"><?php echo htmlspecialchars($sp['shift_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Fuel Type</label>
            <select id="histFuelFilter" class="form-control" style="width:160px;">
                <option value="">All Types</option>
                <?php foreach ($pump_master_fuel_types as $f): ?>
                <option value="<?php echo htmlspecialchars($f['fuel_type']); ?>"><?php echo htmlspecialchars($f['fuel_type']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Status</label>
            <select id="histStatusFilter" class="form-control" style="width:140px;">
                <option value="">All</option>
                <option value="Pending">Pending</option>
                <option value="Approved">Approved</option>
                <option value="Rejected">Rejected</option>
            </select>
        </div>
        <button class="btn btn-primary" onclick="loadShiftHistory()"><i class="fas fa-search"></i> Filter</button>
        <button class="btn btn-secondary" onclick="resetHistoryFilters()"><i class="fas fa-undo"></i> Reset</button>
    </div>

    <!-- History Table -->
    <div id="historyTableWrap">
    <?php if (empty($shift_history)): ?>
        <div class="empty-state"><i class="fas fa-history"></i><p>No shift history found.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="data-table" id="historyTable">
        <thead><tr>
            <th>Transaction ID</th><th>Date</th><th>Shift</th><th>Fuel Type</th>
            <th>Present</th><th>Previous</th><th>Calib.</th><th>Liters Sold</th>
            <th>Price/L</th><th>Total (?)</th>
            <th>Staff</th><th>Status</th><th>Validated By</th><th>Validated At</th>
        </tr></thead>
        <tbody id="historyTbody">
        <?php foreach ($shift_history as $h):
            $st = strtolower($h['status']??'pending');
        ?>
        <tr>
            <td><strong style="font-size:.76rem;"><?php echo htmlspecialchars($h['transaction_id']); ?></strong></td>
            <td style="white-space:nowrap;font-size:.82rem;"><?php echo date('M j, Y',strtotime($h['transaction_date'])); ?></td>
            <td style="font-size:.78rem;"><?php echo htmlspecialchars($h['shift_name'] ?? ucfirst($h['shift_period'] ?? ' - ')); ?></td>
            <td><strong><?php echo htmlspecialchars($h['fuel_type']); ?></strong></td>
            <td><?php echo number_format($h['present_reading'] ?? 0, 2); ?></td>
            <td><?php echo number_format($h['previous_reading'] ?? 0, 2); ?></td>
            <td><?php echo number_format($h['calibration'] ?? 0, 2); ?></td>
            <td><strong><?php echo number_format($h['liters_sold'],2); ?> L</strong></td>
            <td>?<?php echo number_format($h['price_per_liter'] ?? 0, 2); ?></td>
            <td><strong>?<?php echo number_format(($h['liters_sold'] ?? 0) * ($h['price_per_liter'] ?? 0), 2); ?></strong></td>
            <td><span class="audit-badge"><i class="fas fa-user"></i> <?php echo htmlspecialchars($h['staff_name']); ?></span></td>
            <td>
                <?php
                if ($st==='approved' || $st==='verified') echo '<span class="tag-resolved"><i class="fas fa-check"></i> Approved</span>';
                elseif ($st==='rejected') echo '<span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>';
                else echo '<span class="tag-open"><i class="fas fa-clock"></i> Pending</span>';
                ?>
            </td>
            <td>
                <?php echo !empty($h['validated_by_name']) ? '<span class="audit-badge"><i class="fas fa-user-tie"></i> '.htmlspecialchars($h['validated_by_name']).'</span>' : ' - '; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;">
                <?php echo !empty($h['validated_at']) ? date('M j H:i',strtotime($h['validated_at'])) : ' - '; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div style="margin-top:10px;font-size:.8rem;color:#888;"><i class="fas fa-info-circle"></i> Showing last 30 transactions. All entries are immutable for audit compliance.</div>
    <?php endif; ?>
    </div><!-- /historyTableWrap -->

</div>
</div>

<!-- ----------------------------------------------------------
     TAB: WEEKLY / MONTHLY SALES SUMMARY REPORT
---------------------------------------------------------- -->
<div id="fuel-reports" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-chart-bar"></i> Sales Summary  -  Monitoring</div>
    </div>

    <div style="padding:12px 16px;background:#f0f4ff;border-radius:8px;border-left:4px solid <?php echo $colors['primary']; ?>;margin-bottom:18px;font-size:.85rem;color:#444;">
        <strong style="color:<?php echo $colors['primary']; ?>;">Purpose:</strong>
        Track consolidated fuel sales per period. Compare actual pump readings vs sales revenue.
        Detect anomalies or underperformance. Provides quick monitoring dashboard for managerial decisions.
    </div>

    <!-- -- Report Controls -- -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;padding:16px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef;margin-bottom:20px;">
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Period</label>
            <select id="rptPeriod" class="form-control" style="width:150px;" onchange="onRptPeriodChange()">
                <option value="daily">Daily</option>
                <option value="weekly" selected>Weekly</option>
                <option value="monthly">Monthly</option>
                <option value="custom">Custom Range</option>
            </select>
        </div>
        <div id="rptDayWrap" style="display:none;">
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Date</label>
            <input type="date" id="rptDay" class="form-control" style="width:160px;" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div id="rptWeekWrap">
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Week of</label>
            <input type="date" id="rptWeekDate" class="form-control" style="width:160px;" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div id="rptMonthWrap" style="display:none;">
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Month</label>
            <input type="month" id="rptMonth" class="form-control" style="width:160px;" value="<?php echo date('Y-m'); ?>">
        </div>
        <div id="rptCustomWrap" style="display:none;gap:8px;align-items:flex-end;">
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">From</label>
                <input type="date" id="rptFrom" class="form-control" style="width:150px;" value="<?php echo date('Y-m-01'); ?>">
            </div>
            <div>
                <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">To</label>
                <input type="date" id="rptTo" class="form-control" style="width:150px;" value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div>
            <label style="font-size:.78rem;font-weight:600;color:#555;display:block;margin-bottom:4px;">Shift</label>
            <select id="rptShift" class="form-control" style="width:180px;">
                <option value="">All Shifts</option>
                <?php foreach ($shift_periods as $sp): ?>
                <option value="<?php echo htmlspecialchars($sp['shift_key']); ?>"><?php echo htmlspecialchars($sp['shift_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-lg" onclick="generateReport()">
            <i class="fas fa-chart-bar"></i> Generate Report
        </button>
        <button class="btn btn-info" id="rptExportBtn" style="display:none;" onclick="exportReport()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>

    <!-- -- Loading / Empty states -- -->
    <div id="rptLoading" style="display:none;text-align:center;padding:40px;color:#888;">
        <i class="fas fa-spinner fa-spin" style="font-size:2rem;margin-bottom:12px;display:block;"></i>
        Generating report - 
    </div>
    <div id="rptEmpty" style="display:none;">
        <div class="empty-state">
            <i class="fas fa-chart-bar"></i>
            <p>No approved fuel readings found for the selected period.</p>
            <p style="font-size:.8rem;color:#aaa;">Only manager-approved entries are included in reports.</p>
        </div>
    </div>

    <!-- -- Report Output -- -->
    <div id="rptOutput" style="display:none;">

        <!-- Grand Total Banner -->
        <div id="rptGrandTotal" style="padding:16px 20px;background:linear-gradient(135deg,<?php echo $colors['primary']; ?>,<?php echo adjustColor($colors['primary'],-20); ?>);border-radius:10px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px;">
            <div>
                <div style="font-size:.72rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px;">Total Liters Sold</div>
                <div id="rptGrandLiters" style="font-size:1.8rem;font-weight:700;">0.00 L</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:.72rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px;">Total Revenue</div>
                <div id="rptGrandSales" style="font-size:1.8rem;font-weight:700;">&#8369;0.00</div>
            </div>
            <div style="text-align:center;">
                <div style="font-size:.72rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px;">Avg Price/L</div>
                <div id="rptGrandAvgPrice" style="font-size:1.8rem;font-weight:700;">&#8369;0.00</div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.72rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px;">Period</div>
                <div id="rptPeriodLabel" style="font-size:1rem;font-weight:600;">-"</div>
            </div>
        </div>

        <!-- -
             TABLE 1 -" METER READING TABLE  (raw per-pump rows)
        - -->
        <div class="section-head" style="margin-bottom:8px;">
            <div class="section-title"><i class="fas fa-tachometer-alt"></i> Table 1 -" Meter Reading</div>
            <span class="audit-badge" style="background:#e8f5e9;color:#1b5e20;border:1px solid #a5d6a7;">
                <i class="fas fa-check-circle"></i> Approved Only
            </span>
        </div>
        <div style="overflow-x:auto;margin-bottom:28px;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
            <thead>
                <tr style="background:<?php echo $colors['primary']; ?>;color:#fff;">
                    <th style="padding:9px 12px;text-align:left;white-space:nowrap;">Fuel Type</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">Beginning</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">Ending</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">CAL</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">Volume (L)</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">Price/L</th>
                    <th style="padding:9px 12px;text-align:right;white-space:nowrap;">Amount (&#8369;)</th>
                    <th style="padding:9px 12px;text-align:center;white-space:nowrap;">Shift</th>
                    <th style="padding:9px 12px;text-align:left;white-space:nowrap;">Staff</th>
                </tr>
            </thead>
            <tbody id="meterReadingTbody">
                <tr><td colspan="9" style="text-align:center;color:#bbb;padding:20px;">Generate a report to view meter readings.</td></tr>
            </tbody>
            <tfoot id="meterReadingFoot" style="display:none;">
                <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid <?php echo $colors['primary']; ?>;">
                    <td style="padding:9px 12px;color:<?php echo $colors['primary']; ?>;" colspan="4">TOTAL</td>
                    <td id="mrTotalLiters" style="padding:9px 12px;text-align:right;color:<?php echo $colors['primary']; ?>;"></td>
                    <td style="padding:9px 12px;"></td>
                    <td id="mrTotalAmount" style="padding:9px 12px;text-align:right;color:<?php echo $colors['primary']; ?>;"></td>
                    <td colspan="2" style="padding:9px 12px;"></td>
                </tr>
            </tfoot>
        </table>
    </div>
        </div>

        <!-- -
             TABLE 2 -" VOLUME SALES SUMMARY  (liters per fuel type)
        - -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;align-items:start;">

            <div>
                <div class="section-head" style="margin-bottom:8px;">
                    <div class="section-title"><i class="fas fa-gas-pump"></i> Table 2 -" Volume Sales Summary</div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                    <thead>
                        <tr style="background:#0056b3;color:#fff;">
                            <th style="padding:10px 14px;text-align:left;">Fuel Type</th>
                            <th style="padding:10px 14px;text-align:right;">Volume Sales (L)</th>
                        </tr>
                    </thead>
                    <tbody id="volSalesTbody">
                        <tr><td colspan="2" style="text-align:center;color:#bbb;padding:20px;">-"</td></tr>
                    </tbody>
                    <tfoot id="volSalesFoot" style="display:none;">
                        <tr style="background:#e8f0fe;font-weight:700;border-top:2px solid #0056b3;">
                            <td style="padding:10px 14px;color:#0056b3;">TOTAL</td>
                            <td id="volSalesTotalLiters" style="padding:10px 14px;text-align:right;color:#0056b3;"></td>
                        </tr>
                    </tfoot>
                </table>
    </div>
            </div>

            <!-- -
                 TABLE 3 -" VOLUME & AMOUNT SUMMARY  (liters + amount + TOTAL)
            - -->
            <div>
                <div class="section-head" style="margin-bottom:8px;">
                    <div class="section-title"><i class="fas fa-table"></i> Table 3 -" Volume &amp; Amount Summary</div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
                    <thead>
                        <tr style="background:<?php echo $colors['primary']; ?>;color:#fff;">
                            <th style="padding:10px 14px;text-align:left;">Fuel Type</th>
                            <th style="padding:10px 14px;text-align:right;">Volume Sales (L)</th>
                            <th style="padding:10px 14px;text-align:right;">Amount Sales (&#8369;)</th>
                        </tr>
                    </thead>
                    <tbody id="volAmtSummaryTbody">
                        <tr><td colspan="3" style="text-align:center;color:#bbb;padding:20px;">-"</td></tr>
                    </tbody>
                    <tfoot id="volAmtSummaryFoot" style="display:none;">
                        <tr style="background:#f0f4ff;font-weight:700;border-top:2px solid <?php echo $colors['primary']; ?>;">
                            <td style="padding:10px 14px;color:<?php echo $colors['primary']; ?>;font-size:.9rem;">TOTAL</td>
                            <td id="volAmtTotalLiters" style="padding:10px 14px;text-align:right;color:<?php echo $colors['primary']; ?>;font-size:.9rem;"></td>
                            <td id="volAmtTotalAmount" style="padding:10px 14px;text-align:right;color:<?php echo $colors['primary']; ?>;font-size:.9rem;"></td>
                        </tr>
                    </tfoot>
                </table>
    </div>
            </div>

        </div><!-- /2-col grid -->

        <!-- Per-Fuel-Type KPI Cards (Hidden) -->
        <div class="section-head" style="display:none;">
            <div class="section-title"><i class="fas fa-gas-pump"></i> Sales by Fuel Type</div>
        </div>
        <div id="rptSummaryCards" style="display:none;"></div>

        <!-- Trend Chart (Hidden) -->
        <div class="section-head" style="display:none;margin-top:4px;">
            <div class="section-title"><i class="fas fa-chart-line"></i> Sales Trend</div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-secondary" style="font-size:.75rem;padding:3px 10px;" onclick="switchChart('bar')"><i class="fas fa-chart-bar"></i> Bar</button>
                <button class="btn btn-secondary" style="font-size:.75rem;padding:3px 10px;" onclick="switchChart('line')"><i class="fas fa-chart-line"></i> Line</button>
            </div>
        </div>
        <div style="display:none;background:#fff;border:1px solid #e9ecef;border-radius:10px;padding:16px;margin-bottom:24px;position:relative;height:300px;">
            <canvas id="rptChart"></canvas>
        </div>

        <!-- Pump Readings vs Sales Comparison (Hidden) -->
        <div class="section-head" style="display:none;">
            <div class="section-title"><i class="fas fa-balance-scale"></i> Pump Readings vs Sales Comparison</div>
            <span style="font-size:.75rem;color:#888;">Variance &gt;5% auto-flagged</span>
        </div>
        <div style="display:none;overflow-x:auto;margin-bottom:24px;">
        <table class="data-table" id="rptCompareTable">
            <thead><tr>
                <th>Fuel Type</th>
                <th>Total Encoded (L)</th>
                <th>Approved (L)</th>
                <th>Variance (L)</th>
                <th>Variance %</th>
                <th>Readings</th>
                <th>Approved</th>
                <th>Pending</th>
                <th>Rejected</th>
            </tr></thead>
            <tbody id="rptCompareTbody"></tbody>
        </table>
    </div>
        </div>

        <!-- Daily Breakdown + Staff/Shift Drill-down -->
        <div class="section-head" style="display:none;">
            <div class="section-title"><i class="fas fa-table"></i> Daily Breakdown</div>
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="rptEntryCount" style="font-size:.8rem;color:#888;"></span>
                <button class="btn btn-secondary" style="font-size:.75rem;padding:3px 10px;" onclick="toggleShiftBreakdown()">
                    <i class="fas fa-users"></i> <span id="shiftToggleLabel">Show Staff/Shift</span>
                </button>
            </div>
        </div>
        <div style="display:none;overflow-x:auto;">
        <table class="data-table" id="rptDailyTable">
            <thead>
                <tr id="rptDailyHead">
                    <th>Date</th>
                    <th>Fuel Type</th>
                    <th>Liters Sold</th>
                    <th>Avg Price/L</th>
                    <th>Sales Amount (&#8369;)</th>
                </tr>
            </thead>
            <tbody id="rptDailyTbody"></tbody>
        </table>
    </div>
        </div>

    </div><!-- /rptOutput -->

</div>
</div>

<!-- ----------------------------------------------------------
     TAB 7: PUMP MASTER (CALIBRATION)
---------------------------------------------------------- -->
<div id="pump-master" class="fuel-section">
<div class="fuel-section-inner">

    <div class="section-head">
        <div class="section-title"><i class="fas fa-cog"></i> Pump Master — Calibration Management</div>
    </div>

    <!-- Update Calibration Table Form -->
    <div class="info-box">
        <h4 style="margin:0 0 14px;color:<?php echo $colors['primary']; ?>;"><i class="fas fa-edit"></i> Update Calibration Value</h4>
        <?php if (empty($pump_master_fuel_types)): ?>
            <div class="empty-state"><i class="fas fa-cog"></i><p>No fuel types found.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="data-table" style="font-size:0.85rem;">
            <thead><tr>
                <th>Fuel Type</th>
                <th>Current Calibration</th>
                <th>New Calibration Value (Liters)</th>
                <th>Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pump_master_fuel_types as $f):
                $cur_cal = $f['latest_calibration'] ?? 0;
                $safe_ft = htmlspecialchars($f['fuel_type']);
                $row_id  = 'calRow_' . preg_replace('/\W+/', '_', $f['fuel_type']);
            ?>
            <tr>
                <td><strong><?php echo $safe_ft; ?></strong></td>
                <td>
                    <span style="font-weight:700;color:<?php echo $cur_cal>0?$colors['success']:$colors['danger']; ?>;">
                        <?php echo number_format($cur_cal,2); ?> L
                    </span>
                    <?php if ($cur_cal == 0): ?>
                    <div style="font-size:.72rem;color:<?php echo $colors['danger']; ?>;font-weight:700;">- NEEDS UPDATE</div>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" action="manager_fuel_management_complete.php" id="<?php echo $row_id; ?>" style="margin:0;">
                        <input type="hidden" name="action" value="update_calibration">
                        <input type="hidden" name="fuel_type" value="<?php echo $safe_ft; ?>">
                        <input type="number" name="new_calibration"
                            class="form-control" style="width:160px;display:inline-block;"
                            step="0.01" min="0" max="50" required
                            placeholder="0.00 - 50.00 L">
                    </form>
                </td>
                <td>
                    <button type="submit" form="<?php echo $row_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-save"></i> Save
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="form-hint" style="margin-top:8px;"><i class="fas fa-info-circle"></i> Range: 0 - 50 liters per row. Values auto-pull to staff transaction forms on save.</div>
        <?php endif; ?>
    </div>

    <!-- Calibration Values Table -->
    <div class="section-head">
        <div class="section-title"><i class="fas fa-table"></i> Current Calibration Values</div>
    </div>
    <?php if (empty($pump_master_fuel_types)): ?>
        <div class="empty-state"><i class="fas fa-cog"></i><p>No fuel types found.</p></div>
    <?php else: ?>
    <div style="overflow-x:auto; width:100%; border-radius:8px; border:1px solid #eef0f2; margin-top:12px; background:#fff; position:relative;">
    <table class="data-table" style="font-size:0.82rem; ">
        <thead><tr>
            <th>Fuel Type</th><th>Available Stock</th><th>Calibration Value</th>
            <th>Price/Liter</th><th>Last Updated</th><th>Quick Edit</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pump_master_fuel_types as $f):
            $cal = $f['latest_calibration'] ?? 0;
            $lvl = $f['current_level'] ?? 0;
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($f['fuel_type']); ?></strong></td>
            <td>
                <span class="tank-status status-<?php echo $lvl>2000?'available':($lvl>500?'low':'out'); ?>">
                    <?php echo number_format($lvl,2); ?> L
                </span>
            </td>
            <td>
                <span style="font-weight:700;color:<?php echo $cal>0?$colors['success']:$colors['danger']; ?>;">
                    <?php echo number_format($cal,2); ?> L
                </span>
                <?php if ($cal == 0): ?>
                <div style="font-size:.72rem;color:<?php echo $colors['danger']; ?>;font-weight:700;">? NEEDS UPDATE</div>
                <?php endif; ?>
            </td>
            <td>?<?php echo number_format($f['price_per_liter']??0,2); ?></td>
            <td style="font-size:.8rem;color:#555;"><?php echo date('M j, Y H:i',strtotime($f['last_updated'])); ?></td>
            <td>
                <button class="btn btn-primary"
                    onclick="openCalEditModal(
                        '<?php echo htmlspecialchars($f['fuel_type']); ?>',
                        <?php echo (float)($f['latest_calibration'] ?? 0); ?>,
                        <?php echo (float)($f['price_per_liter'] ?? 0); ?>,
                        '<?php echo htmlspecialchars($f['fuel_type_id'] ?? ''); ?>'
                    )">
                    <i class="fas fa-edit"></i> Edit
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php endif; ?>



</div>
</div>

</div><!-- /.mfm-wrap -->

<!-- ----------------------------------------------------------
     MODALS
---------------------------------------------------------- -->

<!-- Edit Calibration Modal -->
<div id="calEditModal" class="modal">
<div class="modal-box" style="max-width:460px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-edit"></i> Edit Calibration  -  <span id="calEditFuelName"></span></div>
        <button class="modal-close" onclick="closeModal('calEditModal')"> - </button>
    </div>

    <!-- Current values display -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;background:#f8f9fa;border-radius:8px;padding:12px;">
        <div style="text-align:center;">
            <div style="font-size:.7rem;color:#888;text-transform:uppercase;margin-bottom:4px;">Current Calibration</div>
            <div style="font-size:1.2rem;font-weight:700;color:<?php echo $colors['primary']; ?>;" id="calEditCurrentCal"> - </div>
        </div>
        <div style="text-align:center;">
            <div style="font-size:.7rem;color:#888;text-transform:uppercase;margin-bottom:4px;">Current Price/L</div>
            <div style="font-size:1.2rem;font-weight:700;color:<?php echo $colors['primary']; ?>;" id="calEditCurrentPrice"> - </div>
        </div>
    </div>

    <!-- Update Calibration -->
    <form method="post" action="manager_fuel_management_complete.php" id="calEditForm">
        <input type="hidden" name="action" value="update_calibration">
        <input type="hidden" name="fuel_type" id="calEditFuelType">

        <div class="form-group">
            <label class="form-label">New Calibration Value (Liters) <span class="required">*</span></label>
            <input type="number" name="new_calibration" id="calEditNewCal"
                class="form-control" step="0.01" min="0" max="50" required
                placeholder="0.00  -  50.00 L">
            <div class="form-hint"><i class="fas fa-info-circle"></i> Range: 0 - 50 L. Auto-pulls to staff transaction forms on save.</div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Save Calibration
            </button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('calEditModal')">Cancel</button>
        </div>
    </form>

    <!-- Update Price (separate form, same modal) -->
    <div style="border-top:1px solid #e9ecef;margin-top:4px;padding-top:14px;">
        <div style="font-size:.8rem;font-weight:600;color:#555;margin-bottom:10px;">
            <i class="fas fa-tag"></i> Also update Price/Liter? <span style="font-weight:400;color:#aaa;">(optional)</span>
        </div>
        <form method="post" action="manager_fuel_management_complete.php">
            <input type="hidden" name="action" value="update_price">
            <input type="hidden" name="fuel_type_id" id="calEditFuelTypeId">
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="margin:0;flex:1;min-width:120px;">
                    <label class="form-label" style="font-size:.82rem;">New Price (?/L)</label>
                    <input type="number" name="new_price" id="calEditNewPrice"
                        class="form-control" step="0.01" min="0.01" placeholder="e.g. 58.50">
                </div>
                <div class="form-group" style="margin:0;flex:2;min-width:160px;">
                    <label class="form-label" style="font-size:.82rem;">Reason <span style="font-weight:400;color:#aaa;font-size:.75rem;">(Optional)</span></label>
                    <input type="text" name="reason" class="form-control"
                        placeholder="e.g. Petron price update">
                </div>
                <button type="submit" class="btn" style="white-space:nowrap;padding:8px 14px;background:#003d82;color:white;border:none;">
                    <i class="fas fa-tag"></i> Update Price
                </button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- ============================================================
     DELIVERY: RECORD NEW DELIVERY MODAL (DB-driven fuel types & suppliers)
============================================================ -->
<div id="recordDeliveryModal" class="modal">
<div class="modal-box" style="max-width:520px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-truck" style="color:#198754;margin-right:7px;"></i> Record New Delivery</div>
        <button class="modal-close" onclick="closeModal('recordDeliveryModal')" title="Close">&#x2715;</button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="record_delivery">
        <div class="modal-body" style="padding:18px 20px;">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Fuel Type <span class="required">*</span></label>
                    <select name="fuel_type_name" class="form-control" required>
                        <option value="">Select fuel type...</option>
                        <?php foreach ($db_fuel_types as $ft): ?>
                        <option value="<?php echo htmlspecialchars($ft['name']); ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Volume (Liters) <span class="required">*</span></label>
                    <input type="number" name="delivery_volume" class="form-control" step="0.01" min="1" required placeholder="e.g. 10000">
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Supplier <span class="required">*</span></label>
                    <select name="supplier_name" class="form-control" required>
                        <option value="">Select supplier...</option>
                        <?php foreach ($db_suppliers as $sup): ?>
                        <option value="<?php echo htmlspecialchars($sup['supplier_name']); ?>"><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($db_suppliers)): ?>
                        <option value="Petron Fuel Supply">Petron Fuel Supply</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Invoice / Receipt No. <span class="required">*</span></label>
                    <input type="text" name="receipt_number" class="form-control" required placeholder="e.g. INV-2026-001">
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Delivery Date <span class="required">*</span></label>
                    <input type="date" name="delivery_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group" style="margin:0;">
                    <label class="form-label">Tanker Number <span style="font-weight:400;color:#aaa;font-size:.75rem;">(Optional)</span></label>
                    <input type="text" name="tanker_number" class="form-control" placeholder="e.g. TK-001">
                </div>

            </div>

            <div class="form-group" style="margin-top:12px;">
                <label class="form-label">Notes <span style="font-weight:400;color:#aaa;font-size:.75rem;">(Optional)</span></label>
                <textarea name="delivery_notes" class="form-control" rows="2" placeholder="Any observations or notes about this delivery..."></textarea>
            </div>

            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px;margin-top:10px;font-size:.8rem;color:#856404;">
                <i class="fas fa-info-circle"></i>
                Delivery will be saved as <strong>Pending</strong>. Tank levels are updated only after manager validation.
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Save Delivery</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('recordDeliveryModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- ============================================================
     DELIVERY: VIEW DETAILS MODAL
============================================================ -->
<div id="deliveryDetailsModal" class="modal">
<div class="modal-box" style="max-width:520px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-eye" style="color:#0d6efd;margin-right:7px;"></i> Delivery Details</div>
        <button class="modal-close" onclick="closeModal('deliveryDetailsModal')" title="Close">&#x2715;</button>
    </div>
    <div class="modal-body" style="padding:18px 20px;">
        <table style="width:100%;border-collapse:collapse;font-size:.88rem;">
            <tbody>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;width:38%;font-weight:600;">Delivery #</td>
                    <td style="padding:8px 6px;font-weight:700;" id="dd_id">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Supplier</td>
                    <td style="padding:8px 6px;" id="dd_supplier">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Fuel Type</td>
                    <td style="padding:8px 6px;" id="dd_fuel_type">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Volume (L)</td>
                    <td style="padding:8px 6px;font-weight:700;color:#198754;" id="dd_liters">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Invoice No.</td>
                    <td style="padding:8px 6px;" id="dd_invoice">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Delivery Date</td>
                    <td style="padding:8px 6px;" id="dd_date">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Encoded By</td>
                    <td style="padding:8px 6px;" id="dd_encoded_by">-</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;vertical-align:top;">Notes</td>
                    <td style="padding:8px 6px;white-space:pre-wrap;" id="dd_notes">-</td>
                </tr>
                <tr>
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Current Tank Level</td>
                    <td style="padding:8px 6px;font-weight:700;color:#003d82;" id="dd_current_tank">-</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="modal-footer" style="justify-content:flex-end;">
        <button type="button" class="btn btn-success" style="font-size:.82rem;"
            onclick="promoteToApprove()">
            <i class="fas fa-check"></i> Approve
        </button>
        <button type="button" class="btn btn-danger" style="font-size:.82rem;"
            onclick="promoteToReturn()">
            <i class="fas fa-undo"></i> Return
        </button>
        <button type="button" class="btn btn-secondary" style="font-size:.82rem;"
            onclick="closeModal('deliveryDetailsModal')">Close</button>
    </div>
</div>
</div>

<!-- ============================================================
     DELIVERY: APPROVE MODAL
============================================================ -->
<div id="deliveryApproveModal" class="modal">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-check-circle" style="color:#198754;margin-right:7px;"></i> Approve Delivery</div>
        <button class="modal-close" onclick="closeModal('deliveryApproveModal')" title="Close">&#x2715;</button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="validate_delivery">
        <input type="hidden" name="delivery_action" value="approve">
        <input type="hidden" name="delivery_id" id="dapprove_id">

        <div class="modal-body">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Fuel Type</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_fuel">-</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Volume</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_liters">-</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Invoice No.</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_invoice">-</div>
                </div>
            </div>
            <!-- Tank level preview -->
            <div id="dapprove_new_tank" style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.82rem;color:#1b5e20;font-weight:600;text-align:center;">
                -
            </div>

            <div class="form-group">
                <label class="form-label">Manager Notes <span class="required">*</span></label>
                <textarea name="validation_notes" class="form-control" rows="3"
                    placeholder="Confirm receipt matches supplier DR, note any observations..." required></textarea>
                <div class="form-hint"><i class="fas fa-shield-alt"></i> Logged with your name &amp; timestamp. Stock will be updated automatically.</div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-check"></i> Confirm Approve</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryApproveModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- ============================================================
     DELIVERY: RETURN MODAL
============================================================ -->
<div id="deliveryReturnModal" class="modal">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-undo" style="color:#dc3545;margin-right:7px;"></i> Return to Staff</div>
        <button class="modal-close" onclick="closeModal('deliveryReturnModal')" title="Close">&#x2715;</button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="validate_delivery">
        <input type="hidden" name="delivery_action" value="reject">
        <input type="hidden" name="delivery_id" id="dreturn_id">

        <div class="modal-body">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Fuel Type</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_fuel">-</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Volume</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_liters">-</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Invoice No.</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_invoice">-</div>
                </div>
            </div>

            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px;margin-bottom:14px;font-size:.82rem;color:#856404;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Returning this delivery</strong> will flag it for staff correction. The staff member will be notified to re-encode or correct the DR.
            </div>

            <div class="form-group">
                <label class="form-label">Reason for Return <span class="required">*</span></label>
                <textarea name="validation_notes" class="form-control" rows="3"
                    placeholder="Describe the discrepancy or issue that needs to be corrected by staff..." required></textarea>
                <div class="form-hint"><i class="fas fa-shield-alt"></i> Logged with your name &amp; timestamp.</div>
            </div>
        </div>

        <div class="modal-footer">
            <button type="submit" class="btn btn-danger btn-lg"><i class="fas fa-undo"></i> Confirm Return</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('deliveryReturnModal')">Cancel</button>
        </div>
    </form>
</div>
</div>
<!-- Validate Reading Modal -->
<div id="validateModal" class="modal">
<div class="modal-box">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-clipboard-check"></i> Validate Staff Reading</div>
        <button class="modal-close" onclick="closeModal('validateModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="validate_reading">
        <input type="hidden" name="reading_id" id="val_reading_id">
        <input type="hidden" name="status" value="verified">

        <!-- Reading summary -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Staff</label>
                <input type="text" id="val_staff_name" class="form-control" readonly style="background:#f8f9fa;">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Sales (L)</label>
                <input type="text" id="val_liters" class="form-control" readonly style="background:#f8f9fa;">
            </div>
        </div>

        <!-- Variance info panel -->
        <div id="val_variance_panel" style="border:2px solid #28a745;border-radius:8px;padding:12px;margin-bottom:14px;background:#f0fff4;transition:all .3s;">
            <div style="font-size:.78rem;font-weight:700;color:#555;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px;">
                <i class="fas fa-balance-scale"></i> System Comparison
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;">
                <div>
                    <div style="font-size:.7rem;color:#888;">Tank Level</div>
                    <div style="font-weight:700;font-size:.95rem;" id="val_tank_level"> - </div>
                </div>
                <div>
                    <div style="font-size:.7rem;color:#888;">Calibration</div>
                    <div style="font-weight:700;font-size:.95rem;" id="val_calibration"> - </div>
                </div>
                <div>
                    <div style="font-size:.7rem;color:#888;">Variance %</div>
                    <div style="font-weight:700;font-size:.95rem;" id="val_variance_pct"> - </div>
                </div>
            </div>
            <div id="val_variance_warn" style="display:none;margin-top:10px;padding:8px 10px;background:#f8d7da;border-radius:6px;font-size:.78rem;color:#721c24;font-weight:600;">
                <i class="fas fa-exclamation-triangle"></i> Variance &gt;5% detected. Review carefully before approving. Manager notes required.
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Variance Override (Liters) <span style="font-size:.75rem;color:#888;"> -  0 if none</span></label>
            <input type="number" name="variance_liters" class="form-control" step="0.01" value="0" placeholder="Positive = overage, Negative = shortage">
        </div>
        <div class="form-group">
            <label class="form-label">Manager Notes <span class="required">*</span></label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Required: Enter your validation notes..." required></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Saved to audit trail with your name &amp; timestamp.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-check"></i> Approve &amp; Verify</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('validateModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Reject Reading Modal -->
<div id="rejectModal" class="modal">
<div class="modal-box">
    <div class="modal-header">
        <div class="modal-title" style="color:<?php echo $colors['danger']; ?>;"><i class="fas fa-times-circle"></i> Reject Staff Reading</div>
        <button class="modal-close" onclick="closeModal('rejectModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="validate_reading">
        <input type="hidden" name="reading_id" id="rej_reading_id">
        <input type="hidden" name="status" value="rejected">
        <input type="hidden" name="variance_liters" value="0">
        <div class="form-group">
            <label class="form-label">Reason for Rejection <span class="required">*</span></label>
            <textarea name="notes" class="form-control" rows="4" placeholder="Required: Explain why this reading is being rejected..." required></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Staff will be notified. Reason is logged for audit.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-danger btn-lg"><i class="fas fa-times"></i> Confirm Rejection</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('rejectModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Adjust Reading Modal -->
<div id="adjustModal" class="modal">
<div class="modal-box">
    <div class="modal-header">
        <div class="modal-title" style="color:#00264D;"><i class="fas fa-edit"></i> Adjust Staff Reading</div>
        <button class="modal-close" onclick="closeModal('adjustModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="adjust_reading">
        <input type="hidden" name="reading_id" id="adj_reading_id">

        <!-- Reading summary -->
        <div style="background:#fffbf0;border:1px solid #ffeaa7;border-radius:8px;padding:12px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Transaction</div>
                <div style="font-weight:700;font-size:.8rem;font-family:monospace;" id="adj_txn_id"> - </div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;" id="adj_fuel_type"> - </div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Staff-Encoded (L)</div>
                <div style="font-weight:700;color:#00264D;" id="adj_original_liters"> - </div>
            </div>
        </div>

        <div style="padding:10px 14px;background:#e8f0f7;border-radius:6px;border-left:3px solid #00264D;margin-bottom:14px;font-size:.82rem;color:#00264D;">
            <i class="fas fa-info-circle"></i>
            Use this when the pump reading has a calibration issue or the Staff-encoded value needs correction.
            The adjusted value will be used for inventory deduction and the audit trail.
        </div>

        <div class="form-group">
            <label class="form-label">Corrected Liters Sold <span class="required">*</span></label>
            <input type="number" name="adjusted_liters" id="adj_liters_input"
                class="form-control" step="0.01" min="0.01" required
                placeholder="Enter the correct liters sold value">
            <div id="adj_diff_hint" style="font-size:.75rem;margin-top:4px;display:none;"></div>
        </div>
        <div class="form-group">
            <label class="form-label">Reason for Adjustment <span class="required">*</span></label>
            <textarea name="adj_reason" class="form-control" rows="3"
                placeholder="e.g. Calibration error on Pump #2, corrected based on physical dip reading..."
                required minlength="5"></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Logged with your name, timestamp, original &amp; adjusted values.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-check"></i> Apply Adjustment &amp; Approve
            </button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('adjustModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Approve Daily Log Modal -->
<div id="approveDailyModal" class="modal">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
        <div class="modal-title" style="color:<?php echo $colors['success']; ?>;"><i class="fas fa-check-circle"></i> Approve Daily Log Entry</div>
        <button class="modal-close" onclick="closeModal('approveDailyModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="approve_daily_log">
        <input type="hidden" name="txn_id" id="adl_txn_id">

        <!-- Entry summary -->
        <div style="background:#f0fff4;border:1px solid #c3e6cb;border-radius:8px;padding:12px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Log ID</div>
                <div style="font-weight:700;font-size:.78rem;font-family:monospace;" id="adl_display_id"> - </div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;" id="adl_fuel_type"> - </div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Liters Sold</div>
                <div style="font-weight:700;color:<?php echo $colors['primary']; ?>;" id="adl_liters"> - </div>
            </div>
        </div>
        <div style="font-size:.82rem;color:#555;margin-bottom:14px;padding:8px 12px;background:#f8f9fa;border-radius:6px;">
            <i class="fas fa-info-circle" style="color:<?php echo $colors['primary']; ?>;"></i>
            Approving will mark this as a <strong>Verified Daily Record</strong>, deduct liters from tank inventory, and include it in the Sales Summary.
        </div>
        <div class="form-group">
            <label class="form-label">Manager Notes <span class="required">*</span></label>
            <textarea name="mgr_notes" class="form-control" rows="3"
                placeholder="e.g. Verified against physical dip reading. Shift data confirmed."
                required></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Saved to audit trail with your name &amp; timestamp.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-check"></i> Confirm Approval</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('approveDailyModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Reject Daily Log Modal -->
<div id="rejectDailyModal" class="modal">
<div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
        <div class="modal-title" style="color:<?php echo $colors['danger']; ?>;"><i class="fas fa-times-circle"></i> Reject Daily Log Entry</div>
        <button class="modal-close" onclick="closeModal('rejectDailyModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="reject_daily_log">
        <input type="hidden" name="txn_id" id="rdl_txn_id">
        <div style="font-size:.82rem;color:#555;margin-bottom:14px;padding:8px 12px;background:#fff5f5;border:1px solid #f5c6cb;border-radius:6px;">
            <i class="fas fa-exclamation-triangle" style="color:<?php echo $colors['danger']; ?>;"></i>
            This entry will be returned to Staff for correction. No inventory changes will be made.
        </div>
        <div class="form-group">
            <label class="form-label">Reason for Rejection <span class="required">*</span></label>
            <textarea name="rej_notes" class="form-control" rows="4"
                placeholder="Required: Explain the discrepancy or issue found..."
                required></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Staff will see this reason. Logged for audit compliance.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-danger btn-lg"><i class="fas fa-times"></i> Confirm Rejection</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('rejectDailyModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Variance Investigation Modal -->
<div id="varianceModal" class="modal">
<div class="modal-box">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-search"></i> Variance Investigation</div>
        <button class="modal-close" onclick="closeModal('varianceModal')"> - </button>
    </div>
    <form method="post" action="manager_fuel_management_complete.php">
        <input type="hidden" name="action" value="update_variance_status">
        <input type="hidden" name="variance_id" id="var_id">
        <div class="form-group">
            <label class="form-label">Update Status <span class="required">*</span></label>
            <select name="new_status" class="form-control" id="var_status_select" required>
                <option value="investigating">Mark as Investigating</option>
                <option value="resolved">Mark as Resolved</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Investigation Notes <span class="required">*</span></label>
            <textarea name="investigation_notes" class="form-control" rows="4" placeholder="Describe findings, root cause, and corrective actions taken..." required></textarea>
            <div class="form-hint"><i class="fas fa-shield-alt"></i> Required for compliance. Saved with your name and timestamp.</div>
        </div>
        <div class="modal-footer">
            <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save Investigation</button>
            <button type="button" class="btn btn-secondary" onclick="closeModal('varianceModal')">Cancel</button>
        </div>
    </form>
</div>
</div>

<script>
/* -- SECTION NAVIGATION (sidebar-driven, show/hide sections) -- */
function switchTab(name, btn) {
    showSectionOnly(name);
}

function showSectionOnly(name) {
    // Instantly hide all fuel sections
    document.querySelectorAll('.fuel-section').forEach(section => {
        section.classList.remove('visible');
        section.classList.add('hidden');
        section.style.display = 'none';
    });

    // Show only the selected section
    const targetSection = document.getElementById(name);
    if (targetSection) {
        targetSection.style.display = 'block';
        targetSection.classList.remove('hidden');
        targetSection.classList.add('visible');

        // Update page title to match the active section
        updatePageTitle(name);

        // Scroll to top of the section smoothly
        window.scrollTo({ top: targetSection.offsetTop - 20, behavior: 'smooth' });

        history.replaceState(null, '', '#' + name);
    }

    // Sync sidebar sub-item highlight
    document.querySelectorAll('.sidebar-sub-item').forEach(el => {
        el.classList.toggle('active', el.getAttribute('data-tab') === name);
    });
}

function scrollToSection(name) {
    // Legacy function - now delegates to showSectionOnly
    showSectionOnly(name);
}

/* -- MODAL HELPERS -- */
function openModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.add('show'); document.body.style.overflow = 'hidden'; }
}
function closeModal(id) {
    const m = document.getElementById(id);
    if (m) { m.classList.remove('show'); document.body.style.overflow = ''; }
}
// Close on backdrop click
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', function(e) { if (e.target === this) closeModal(this.id); });
});
// Close on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.modal.show').forEach(m => closeModal(m.id)); });

/* -- DELIVERY ACTION MODALS -- */
// Shared state for cross-modal promotion
let _currentDelivery = {};

function openDeliveryDetailsModal(data) {
    _currentDelivery = data;
    document.getElementById('dd_id').textContent         = '#' + data.id;
    document.getElementById('dd_supplier').textContent   = data.supplier || 'N/A';
    document.getElementById('dd_fuel_type').textContent  = data.fuel_type || '\u2014';
    document.getElementById('dd_liters').textContent     = parseFloat(data.delivery_liters).toFixed(2) + ' L';
    document.getElementById('dd_invoice').textContent    = data.invoice_no || '\u2014';
    document.getElementById('dd_date').textContent       = data.delivery_date || data.created_at || '\u2014';
    document.getElementById('dd_encoded_by').textContent = data.recorded_by || 'N/A';
    document.getElementById('dd_notes').textContent      = data.notes || '\u2014';
    const tankEl = document.getElementById('dd_current_tank');
    if (tankEl) {
        tankEl.textContent = data.current_tank != null
            ? Math.round(parseFloat(data.current_tank)).toLocaleString() + ' L  \u2192  After Approval: ' + Math.round(parseFloat(data.current_tank) + parseFloat(data.delivery_liters)).toLocaleString() + ' L'
            : '\u2014';
    }
    openModal('deliveryDetailsModal');
}

function openDeliveryApproveModal(id, fuelType, liters, invoiceNo, currentTank, tankCapacity) {
    _currentDelivery = { id: id, fuel_type: fuelType, delivery_liters: liters, invoice_no: invoiceNo, current_tank: currentTank, tank_capacity: tankCapacity };
    document.getElementById('dapprove_id').value              = id;
    document.getElementById('dapprove_fuel').textContent      = fuelType;
    document.getElementById('dapprove_liters').textContent    = parseFloat(liters).toFixed(2) + ' L';
    document.getElementById('dapprove_invoice').textContent   = invoiceNo || '\u2014';

    // Tank level preview + capacity warning
    var tankEl = document.getElementById('dapprove_new_tank');
    if (tankEl) {
        var cur      = parseFloat(currentTank) || 0;
        var cap      = parseFloat(tankCapacity) || 0;
        var newLevel = cur + parseFloat(liters);

        if (cap > 0 && newLevel > cap) {
            var over = newLevel - cap;
            tankEl.style.background = '#fff5f5';
            tankEl.style.border     = '1px solid #f5c6cb';
            tankEl.style.color      = '#721c24';
            tankEl.innerHTML = '<i class="fas fa-exclamation-triangle"></i> '
                + '<strong>Exceeds tank capacity by ' + Math.round(over).toLocaleString() + ' L!</strong><br>'
                + 'Current: ' + Math.round(cur).toLocaleString() + ' L &bull; '
                + 'Capacity: ' + Math.round(cap).toLocaleString() + ' L &bull; '
                + 'After: ' + Math.round(newLevel).toLocaleString() + ' L<br>'
                + '<small>Use <strong>Adjust</strong> to enter a corrected volume instead.</small>';
        } else if (cap > 0) {
            var pct = Math.round((newLevel / cap) * 100);
            tankEl.style.background = '#e8f5e9';
            tankEl.style.border     = '1px solid #a5d6a7';
            tankEl.style.color      = '#1b5e20';
            tankEl.innerHTML = '<i class="fas fa-check-circle"></i> '
                + 'Current: ' + Math.round(cur).toLocaleString() + ' L'
                + ' -> After Approval: <strong>' + Math.round(newLevel).toLocaleString() + ' L</strong>'
                + ' (' + pct + '% of ' + Math.round(cap).toLocaleString() + ' L capacity)';
        } else {
            tankEl.style.background = '#e8f5e9';
            tankEl.style.border     = '1px solid #a5d6a7';
            tankEl.style.color      = '#1b5e20';
            tankEl.innerHTML = 'Current: ' + Math.round(cur).toLocaleString() + ' L'
                + ' \u2192 After Approval: <strong>' + Math.round(newLevel).toLocaleString() + ' L</strong>';
        }
    }
    openModal('deliveryApproveModal');
}

function openDeliveryReturnModal(id, fuelType, liters, invoiceNo) {
    _currentDelivery = { id: id, fuel_type: fuelType, delivery_liters: liters, invoice_no: invoiceNo };
    document.getElementById('dreturn_id').value               = id;
    document.getElementById('dreturn_fuel').textContent       = fuelType;
    document.getElementById('dreturn_liters').textContent     = parseFloat(liters).toFixed(2) + ' L';
    document.getElementById('dreturn_invoice').textContent    = invoiceNo || '\u2014';
    openModal('deliveryReturnModal');
}

// Promote from Details modal to Approve/Return modal
function promoteToApprove() {
    closeModal('deliveryDetailsModal');
    openDeliveryApproveModal(
        _currentDelivery.id,
        _currentDelivery.fuel_type,
        _currentDelivery.delivery_liters,
        _currentDelivery.invoice_no,
        _currentDelivery.current_tank || 0,
        _currentDelivery.tank_capacity || 0
    );
}

function promoteToReturn() {
    closeModal('deliveryDetailsModal');
    openDeliveryReturnModal(
        _currentDelivery.id,
        _currentDelivery.fuel_type,
        _currentDelivery.delivery_liters,
        _currentDelivery.invoice_no
    );
}

/* -- VALIDATE MODAL -- */
function openValidateModal(id, liters, staffName, tankLevel, calibration, variancePct, isFlagged) {
    document.getElementById('val_reading_id').value = id;
    document.getElementById('val_liters').value = parseFloat(liters).toFixed(2) + ' L';
    document.getElementById('val_staff_name').value = staffName || '';

    // Populate variance info panel
    const panel = document.getElementById('val_variance_panel');
    const tankEl = document.getElementById('val_tank_level');
    const calEl  = document.getElementById('val_calibration');
    const varEl  = document.getElementById('val_variance_pct');
    const warnEl = document.getElementById('val_variance_warn');

    if (tankEl) tankEl.textContent = tankLevel > 0 ? parseFloat(tankLevel).toFixed(2) + ' L' : 'N/A';
    if (calEl)  calEl.textContent  = parseFloat(calibration).toFixed(2) + ' L';
    if (varEl)  varEl.textContent  = parseFloat(variancePct).toFixed(2) + '%';

    if (panel) {
        panel.style.borderColor = isFlagged ? '#dc3545' : '#28a745';
        panel.style.background  = isFlagged ? '#fff5f5' : '#f0fff4';
    }
    if (warnEl) warnEl.style.display = isFlagged ? 'block' : 'none';

    openModal('validateModal');
}

/* -- REJECT MODAL -- */
function openRejectModal(id) {
    document.getElementById('rej_reading_id').value = id;
    openModal('rejectModal');
}

/* -- ADJUST MODAL -- */
function openAdjustModal(id, originalLiters, fuelType, staffName) {
    document.getElementById('adj_reading_id').value = id;
    document.getElementById('adj_txn_id').textContent      = id;
    document.getElementById('adj_fuel_type').textContent   = fuelType;
    document.getElementById('adj_original_liters').textContent = parseFloat(originalLiters).toFixed(2) + ' L';
    // Pre-fill with original value so manager can tweak it
    const inp = document.getElementById('adj_liters_input');
    inp.value = parseFloat(originalLiters).toFixed(2);
    inp._original = parseFloat(originalLiters);
    document.getElementById('adj_diff_hint').style.display = 'none';
    openModal('adjustModal');
}

// Live diff hint in adjust modal
document.addEventListener('DOMContentLoaded', function() {
    const adjInp = document.getElementById('adj_liters_input');
    if (adjInp) {
        adjInp.addEventListener('input', function() {
            const hint = document.getElementById('adj_diff_hint');
            const orig = this._original || 0;
            const newV = parseFloat(this.value) || 0;
            if (this.value !== '' && orig > 0) {
                const diff = newV - orig;
                const sign = diff >= 0 ? '+' : '-"';
                hint.style.display = 'block';
                hint.style.color   = diff !== 0 ? '#CC8800' : '#28a745';
                hint.innerHTML     = '<i class="fas fa-arrow-right"></i> Change from original: <strong>' + sign + diff.toFixed(2) + ' L</strong>';
            } else {
                hint.style.display = 'none';
            }
        });
    }
});

/* -- DAILY LOG MODALS -- */
function openApproveDailyModal(txnId, fuelType, liters, staffName) {
    document.getElementById('adl_txn_id').value           = txnId;
    document.getElementById('adl_display_id').textContent = txnId;
    document.getElementById('adl_fuel_type').textContent  = fuelType;
    document.getElementById('adl_liters').textContent     = parseFloat(liters).toFixed(2) + ' L';
    openModal('approveDailyModal');
}

function openRejectDailyModal(txnId) {
    document.getElementById('rdl_txn_id').value = txnId;
    openModal('rejectDailyModal');
}

/* -- DAILY LOGS FILTER -- */
function filterDailyLogs(status) {
    const rows = document.querySelectorAll('#dailyLogsTable tbody tr');
    rows.forEach(function(row) {
        const rowStatus = row.getAttribute('data-status') || '';
        if (status === 'all') {
            row.style.display = '';
        } else if (status === 'verified') {
            row.style.display = (rowStatus === 'verified' || rowStatus === 'approved') ? '' : 'none';
        } else {
            row.style.display = rowStatus === status ? '' : 'none';
        }
    });
    // Update active button style
    document.querySelectorAll('#daily-ops .btn').forEach(function(btn) {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
    });
    event.target.classList.remove('btn-secondary');
    event.target.classList.add('btn-primary');
}

/* -- VARIANCE MODAL -- */
function openVarianceModal(id, currentStatus) {
    document.getElementById('var_id').value = id;
    const sel = document.getElementById('var_status_select');
    // currentStatus is: 'open', 'under_investigation', or 'view'
    if (currentStatus === 'under_investigation') sel.value = 'resolved';
    else sel.value = 'investigating';
    openModal('varianceModal');
}

/* -- CALIBRATION QUICK EDIT (modal) -- */
function openCalEditModal(fuelType, currentCal, currentPrice, fuelTypeId) {
    document.getElementById('calEditFuelName').textContent  = fuelType;
    document.getElementById('calEditFuelType').value        = fuelType;
    document.getElementById('calEditFuelTypeId').value      = fuelTypeId;
    document.getElementById('calEditCurrentCal').textContent   = parseFloat(currentCal).toFixed(2) + ' L';
    document.getElementById('calEditCurrentPrice').textContent = '?' + parseFloat(currentPrice).toFixed(2);
    document.getElementById('calEditNewCal').value   = parseFloat(currentCal).toFixed(2);
    document.getElementById('calEditNewPrice').value = parseFloat(currentPrice).toFixed(2);
    openModal('calEditModal');
}

function quickEditCalibration(fuelType, currentVal) {
    // Legacy: used by tank cards "Update" button  -  open modal with price=0
    openCalEditModal(fuelType, currentVal, 0, '');
}

function prefillCalibration(sel) {
    const opt = sel.options[sel.selectedIndex];
    const cur = opt.getAttribute('data-current');
    const inp = document.getElementById('calValueInput');
    if (inp && cur !== null) inp.value = cur;
}

/* -- SCROLL TO SECTION FROM HASH OR ?tab= PARAM -- */
const _validTabs = ['fuel-transactions','daily-ops','fuel-deliveries','adjustments','reconciliation','variance-reports','shift-history','fuel-reports','pump-master'];

// Map ?tab= query param values to section IDs
const _tabParamMap = {
    'transactions':   'fuel-transactions',
    'deliveries':     'fuel-deliveries',
    'reconciliation': 'reconciliation',
    'adjustments':    'adjustments',
    'pump-master':    'pump-master',
    'variance':       'variance-reports',
    'shift-history':  'shift-history',
    'reports':        'fuel-reports',
    'daily-ops':      'daily-ops',
};

// Section titles matching the spec
const _sectionTitles = {
    'fuel-transactions': 'Fuel Transactions Oversight',
    'fuel-deliveries':   'Fuel Deliveries Validation',
    'reconciliation':    'Reconciliation',
    'adjustments':       'Adjustment',
    'pump-master':       'Pump Master',
    'variance-reports':  'Variance Reports',
    'shift-history':     'Shift History',
    'fuel-reports':      'Sales Summary Report',
    'daily-ops':         'Daily Operations',
};

const _sectionSubtitles = {
    'fuel-transactions': 'Review pump readings encoded by Staff — Validate / Approve / Adjust',
    'fuel-deliveries':   'Review supplier Delivery Receipts encoded by Staff — Approve / Reject / Adjust',
    'reconciliation':    'Compare pump sales vs tank levels — Validate discrepancies, mark status',
    'adjustments':       'Encode corrections to tank levels, pump readings, or delivery entries',
    'pump-master':       'Manage pump list and calibration records — Add/Edit pumps, assign calibration schedules',
    'variance-reports':  'Detected variances requiring investigation or resolution',
    'shift-history':     'Read-only history of all validated fuel transactions',
    'fuel-reports':      'Weekly and monthly sales summary reports',
    'daily-ops':         'Today\'s tank levels and pump approval queue',
};

function updatePageTitle(sectionId) {
    const titleEl  = document.getElementById('mfm-page-title');
    const subEl    = document.getElementById('mfm-page-subtitle');
    if (titleEl) titleEl.textContent = _sectionTitles[sectionId] || 'Fuel Management';
    if (subEl)   subEl.textContent   = _sectionSubtitles[sectionId] || '';
}

function activateTabFromHash() {
    // Priority 1: ?tab= query param
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam  = urlParams.get('tab');
    if (tabParam && _tabParamMap[tabParam]) {
        showSectionOnly(_tabParamMap[tabParam]);
        return;
    }
    // Priority 2: URL hash
    const hash = window.location.hash.replace('#','');
    if (hash && _validTabs.includes(hash)) {
        showSectionOnly(hash);
    } else {
        showSectionOnly('fuel-transactions');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    activateTabFromHash();

    // Sidebar sub-item clicks — show/hide section without reload if already on page
    document.querySelectorAll('a[href*="manager_fuel_management_complete.php"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (!window.location.pathname.includes('manager_fuel_management_complete')) return;

            // Handle ?tab= param links (new style)
            const tabMatch = href.match(/[?&]tab=([^&#]+)/);
            if (tabMatch) {
                const sectionId = _tabParamMap[tabMatch[1]];
                if (sectionId) {
                    e.preventDefault();
                    showSectionOnly(sectionId);
                    // Update URL without reload
                    history.replaceState(null, '', '?tab=' + tabMatch[1]);
                    return;
                }
            }

            // Handle #hash links (legacy)
            const hash = href.split('#')[1];
            if (hash && _validTabs.includes(hash)) {
                e.preventDefault();
                showSectionOnly(hash);
            }
        });
    });
});

/* -- ADJUSTMENT FORM HELPERS (TABLE-BASED ROW SPECIFIC) -- */
document.addEventListener('DOMContentLoaded', function() {
    // Tank Level Adjustments
    document.querySelectorAll('.new-level-input').forEach(input => {
        input.addEventListener('input', function() {
            const fuelId = this.getAttribute('data-fuel-id');
            const current = parseFloat(this.getAttribute('data-current-stock') || 0);
            const newVal = parseFloat(this.value || 0);
            const diffEl = document.getElementById('diff_hint_' + fuelId);
            if (!diffEl) return;
            if (this.value !== '' && !isNaN(current)) {
                const diff = newVal - current;
                const sign = diff >= 0 ? '+' : '-';
                diffEl.style.display = 'block';
                diffEl.style.color = diff >= 0 ? '#28a745' : '#dc3545';
                diffEl.innerHTML = '<i class="fas fa-arrow-right"></i> Change: <strong>' + sign + Math.abs(diff).toFixed(2) + ' L</strong>';
            } else {
                diffEl.style.display = 'none';
            }
        });
    });

    // Price Updates
    document.querySelectorAll('.new-price-input').forEach(input => {
        input.addEventListener('input', function() {
            const fuelId = this.getAttribute('data-fuel-id');
            const current = parseFloat(this.getAttribute('data-current-price') || 0);
            const newVal = parseFloat(this.value || 0);
            const diffEl = document.getElementById('price_diff_hint_' + fuelId);
            if (!diffEl) return;
            if (this.value !== '' && !isNaN(current) && current > 0) {
                const diff = newVal - current;
                const sign = diff >= 0 ? '+' : '-';
                diffEl.style.display = 'block';
                diffEl.style.color = diff >= 0 ? '#dc3545' : '#28a745';
                diffEl.innerHTML = '<i class="fas fa-arrow-right"></i> Change: <strong>-' + sign + Math.abs(diff).toFixed(2) + '/L</strong>';
            } else {
                diffEl.style.display = 'none';
            }
        });
    });
});

/* -- TREND CHART (Reconciliation) -- */
function toggleTrendChart() {
    const wrap = document.getElementById('trendChartWrap');
    const icon = document.getElementById('trendToggleIcon');
    if (!wrap) return;
    const isHidden = wrap.style.display === 'none';
    wrap.style.display = isHidden ? 'block' : 'none';
    if (icon) icon.innerHTML = isHidden
        ? '<i class="fas fa-chevron-up"></i> Hide'
        : '<i class="fas fa-chevron-down"></i> Show';
    if (isHidden) initTrendChart();
}

function initTrendChart() {
    const canvas = document.getElementById('trendChart');
    if (!canvas || canvas._chartInitialized) return;
    canvas._chartInitialized = true;

    // Build labels for last 7 days
    const labels = [];
    for (let i = 6; i >= 0; i--) {
        const d = new Date(); d.setDate(d.getDate() - i);
        labels.push(d.toLocaleDateString('en-US', {month:'short', day:'numeric'}));
    }

    // Data from PHP  -  tank levels per fuel type (last known stock, simplified)
    const tankData = <?php
        $chart_data = [];
        foreach ($tank_data as $t) {
            $chart_data[] = [
                'label' => $t['fuel_type_name'],
                'stock' => (float)$t['current_stock'],
                'capacity' => (float)$t['capacity'],
            ];
        }
        echo json_encode($chart_data);
    ?>;

    const colors = ['#003d7a','#28a745','#ffc107','#dc3545','#17a2b8','#6f42c1'];
    const datasets = tankData.map((ft, i) => ({
        label: ft.label + ' Stock',
        data: Array(7).fill(null).map((_, j) => j === 6 ? ft.stock : null),
        borderColor: colors[i % colors.length],
        backgroundColor: colors[i % colors.length] + '22',
        tension: 0.3,
        fill: false,
        pointRadius: [0,0,0,0,0,0,5],
        spanGaps: true,
    }));

    if (typeof Chart === 'undefined') {
        // Load Chart.js dynamically
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
        s.onload = () => renderChart(canvas, labels, datasets);
        document.head.appendChild(s);
    } else {
        renderChart(canvas, labels, datasets);
    }
}

function renderChart(canvas, labels, datasets) {
    new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 } } },
                tooltip: { mode: 'index', intersect: false }
            },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Liters (L)', font: { size: 11 } } },
                x: { title: { display: true, text: 'Date', font: { size: 11 } } }
            }
        }
    });
}

/* -- AUTO-DISMISS ALERT -- */
(function() {
    const alert = document.querySelector('.mfm-alert');
    if (alert) setTimeout(() => { alert.style.opacity='0'; alert.style.transition='opacity .5s'; setTimeout(()=>alert.remove(),500); }, 5000);
})();

/* -- SHIFT HISTORY FILTER -- */
function loadShiftHistory() {
    const date   = document.getElementById('histDateFilter')?.value || '';
    const shift  = document.getElementById('histShiftFilter')?.value || '';
    const fuel   = document.getElementById('histFuelFilter')?.value || '';
    const status = document.getElementById('histStatusFilter')?.value || '';
    const tbody  = document.getElementById('historyTbody');
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (!cells.length) return;
        let show = true;
        if (fuel   && !cells[3]?.textContent.toLowerCase().includes(fuel.toLowerCase()))   show = false;
        if (status && !cells[11]?.textContent.toLowerCase().includes(status.toLowerCase())) show = false;
        row.style.display = show ? '' : 'none';
    });
}
function resetHistoryFilters() {
    ['histDateFilter','histShiftFilter','histFuelFilter','histStatusFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = id === 'histDateFilter' ? '<?php echo date('Y-m-d'); ?>' : '-"';
    });
    const tbody = document.getElementById('historyTbody');
    if (tbody) tbody.querySelectorAll('tr').forEach(r => r.style.display = '');
}

/* -- WEEKLY / MONTHLY SALES SUMMARY REPORT -- */
let _rptData = null;
let _rptChart = null;
let _shiftBreakdownVisible = false;

// DB-driven shift label map (populated from PHP)
const _shiftLabelMap = <?php
    $js_shift_map = [];
    foreach ($shift_periods as $sp) {
        $js_shift_map[$sp['shift_key']] = $sp['shift_name'];
    }
    echo json_encode($js_shift_map, JSON_UNESCAPED_UNICODE);
?>;

function getShiftLabel(shift_key) {
    if (!shift_key) return '—';
    if (_shiftLabelMap[shift_key]) return _shiftLabelMap[shift_key];
    // Alias fallback
    const aliases = { morning: 'first', am: 'first', '1': 'first', afternoon: 'second', pm: 'second', '2': 'second' };
    const mapped = aliases[String(shift_key).toLowerCase()];
    if (mapped && _shiftLabelMap[mapped]) return _shiftLabelMap[mapped];
    return shift_key;
}

function onRptPeriodChange() {
    const p = document.getElementById('rptPeriod').value;
    document.getElementById('rptDayWrap').style.display    = p === 'daily'   ? 'block' : 'none';
    document.getElementById('rptWeekWrap').style.display   = p === 'weekly'  ? 'block' : 'none';
    document.getElementById('rptMonthWrap').style.display  = p === 'monthly' ? 'block' : 'none';
    const cw = document.getElementById('rptCustomWrap');
    if (cw) cw.style.display = p === 'custom' ? 'flex' : 'none';
}

function getRptDateRange() {
    const p = document.getElementById('rptPeriod').value;
    if (p === 'daily') {
        const d = document.getElementById('rptDay').value || new Date().toISOString().split('T')[0];
        return { from: d, to: d, label: 'Day: ' + new Date(d + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'}) };
    } else if (p === 'weekly') {
        const d   = new Date(document.getElementById('rptWeekDate').value || new Date());
        const day = d.getDay();
        const mon = new Date(d); mon.setDate(d.getDate() - day + (day === 0 ? -6 : 1));
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        const fmt = dt => dt.toISOString().split('T')[0];
        return { from: fmt(mon), to: fmt(sun),
            label: 'Week: ' + mon.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + '  -  ' + sun.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) };
    } else if (p === 'monthly') {
        const m = document.getElementById('rptMonth').value || new Date().toISOString().slice(0,7);
        const [yr, mo] = m.split('-');
        const last = new Date(yr, mo, 0).getDate();
        return { from: `${yr}-${mo}-01`, to: `${yr}-${mo}-${String(last).padStart(2,'0')}`,
            label: new Date(yr, mo-1, 1).toLocaleDateString('en-US',{month:'long',year:'numeric'}) };
    } else {
        const from = document.getElementById('rptFrom').value;
        const to   = document.getElementById('rptTo').value;
        return { from, to, label: from + ' to ' + to };
    }
}

async function generateReport() {
    const { from, to, label } = getRptDateRange();
    const shift = document.getElementById('rptShift').value;
    document.getElementById('rptOutput').style.display  = 'none';
    document.getElementById('rptEmpty').style.display   = 'none';
    document.getElementById('rptLoading').style.display = 'block';
    document.getElementById('rptExportBtn').style.display = 'none';
    try {
        let url = `../backend/api/fuel_readings.php?action=summary&date_from=${from}&date_to=${to}`;
        if (shift) url += `&shift=${encodeURIComponent(shift)}`;
        const res  = await fetch(url, { credentials: 'same-origin' });
        const json = await res.json();
        document.getElementById('rptLoading').style.display = 'none';
        if (!json.success || !json.vol_amt_summary?.length) {
            document.getElementById('rptEmpty').style.display = 'block';
            return;
        }
        _rptData = {
            meter_readings:    json.meter_readings    || [],
            vol_sales_summary: json.vol_sales_summary || [],
            vol_amt_summary:   json.vol_amt_summary   || [],
            summary:           json.summary           || [],
            daily:             json.daily             || [],
            comparison:        json.comparison        || [],
            from, to, label
        };
        renderReport(_rptData);
        document.getElementById('rptOutput').style.display  = 'block';
        document.getElementById('rptExportBtn').style.display = 'inline-flex';
    } catch(e) {
        document.getElementById('rptLoading').style.display = 'none';
        document.getElementById('rptEmpty').style.display   = 'block';
        console.error('Report error:', e);
    }
}

const _chartColors = ['#003d7a','#28a745','#dc3545','#fd7e14','#6f42c1','#17a2b8','#e83e8c','#20c997'];

function renderReport({ meter_readings, vol_sales_summary, vol_amt_summary, summary, daily, comparison, label }) {
    // -"-"- Grand totals -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
    let grandL = 0, grandS = 0;
    (vol_amt_summary||[]).forEach(r => { grandL += parseFloat(r.volume_sales||0); grandS += parseFloat(r.amount_sales||0); });
    const grandAvg = grandL > 0 ? grandS / grandL : 0;
    document.getElementById('rptGrandLiters').textContent   = n2(grandL) + ' L';
    document.getElementById('rptGrandSales').textContent    = '&#8369;' + n2(grandS);
    document.getElementById('rptGrandAvgPrice').textContent = '&#8369;' + n2(grandAvg);
    document.getElementById('rptPeriodLabel').textContent   = label;

    const rowBg = ['#fff','#f8faff'];

    // -"-"- TABLE 1: Meter Reading -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
    const mrTbody = document.getElementById('meterReadingTbody');
    const mrFoot  = document.getElementById('meterReadingFoot');
    mrTbody.innerHTML = '';
    let mrTotalL = 0, mrTotalA = 0;
    if (meter_readings && meter_readings.length) {
        meter_readings.forEach((r, i) => {
            const vol = parseFloat(r.volume_liters||0);
            const amt = parseFloat(r.amount||0);
            mrTotalL += vol; mrTotalA += amt;
            const shiftLbl = getShiftLabel(r.shift_period);
            mrTbody.innerHTML += `<tr style="background:${rowBg[i%2]};border-bottom:1px solid #f0f0f0;">
                <td style="padding:8px 12px;font-weight:600;">${esc(r.fuel_type)}</td>
                <td style="padding:8px 12px;text-align:right;color:#555;">${n2(parseFloat(r.beginning||0))}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:600;">${n2(parseFloat(r.ending||0))}</td>
                <td style="padding:8px 12px;text-align:right;color:#888;">${parseFloat(r.cal||0).toFixed(3)}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;color:#003d7a;">${n2(vol)}</td>
                <td style="padding:8px 12px;text-align:right;">&#8369;${n2(parseFloat(r.price_per_liter||0))}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;">&#8369;${n2(amt)}</td>
                <td style="padding:8px 12px;text-align:center;"><span style="font-size:.72rem;background:#e8f4fd;color:#0056b3;padding:2px 7px;border-radius:10px;">${esc(shiftLbl)}</span></td>
                <td style="padding:8px 12px;font-size:.78rem;color:#555;">${esc(r.staff_name||'-"')}</td>
            </tr>`;
        });
        document.getElementById('mrTotalLiters').textContent = n2(mrTotalL) + ' L';
        document.getElementById('mrTotalAmount').textContent = '&#8369;' + n2(mrTotalA);
        mrFoot.style.display = '';
    } else {
        mrTbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#bbb;padding:20px;">No approved meter readings found.</td></tr>';
        mrFoot.style.display = 'none';
    }

    // -"-"- TABLE 2: Volume Sales Summary -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
    const vsTbody = document.getElementById('volSalesTbody');
    const vsFoot  = document.getElementById('volSalesFoot');
    vsTbody.innerHTML = '';
    let vsTotalL = 0;
    if (vol_sales_summary && vol_sales_summary.length) {
        vol_sales_summary.forEach((r, i) => {
            const vol = parseFloat(r.volume_sales||0);
            vsTotalL += vol;
            vsTbody.innerHTML += `<tr style="background:${rowBg[i%2]};border-bottom:1px solid #f0f0f0;">
                <td style="padding:9px 14px;font-weight:600;">${esc(r.fuel_type)}</td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;color:#0056b3;">${n2(vol)}</td>
            </tr>`;
        });
        document.getElementById('volSalesTotalLiters').textContent = n2(vsTotalL) + ' L';
        vsFoot.style.display = '';
    } else {
        vsTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:#bbb;padding:20px;">-"</td></tr>';
        vsFoot.style.display = 'none';
    }

    // -"-"- TABLE 3: Volume & Amount Summary -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
    const vaTbody = document.getElementById('volAmtSummaryTbody');
    const vaFoot  = document.getElementById('volAmtSummaryFoot');
    vaTbody.innerHTML = '';
    if (vol_amt_summary && vol_amt_summary.length) {
        vol_amt_summary.forEach((r, i) => {
            const vol = parseFloat(r.volume_sales||0);
            const amt = parseFloat(r.amount_sales||0);
            vaTbody.innerHTML += `<tr style="background:${rowBg[i%2]};border-bottom:1px solid #f0f0f0;">
                <td style="padding:10px 14px;font-weight:600;">${esc(r.fuel_type)}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#003d7a;">${n2(vol)}</td>
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#166534;">&#8369;${n2(amt)}</td>
            </tr>`;
        });
        document.getElementById('volAmtTotalLiters').textContent = n2(grandL) + ' L';
        document.getElementById('volAmtTotalAmount').textContent = '&#8369;' + n2(grandS);
        vaFoot.style.display = '';
    } else {
        vaTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#bbb;padding:20px;">-"</td></tr>';
        vaFoot.style.display = 'none';
    }

    // -"-"- Per-fuel KPI cards -"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-"-
    const cardsEl = document.getElementById('rptSummaryCards');
    cardsEl.innerHTML = '';
    (summary||[]).forEach((row, i) => {
        const liters = parseFloat(row.total_liters||0);
        const sales  = parseFloat(row.total_sales||0);
        const price  = parseFloat(row.avg_price||0);
        const count  = parseInt(row.entry_count||0);
        const pct    = grandL > 0 ? (liters / grandL * 100) : 0;
        const c = _chartColors[i % _chartColors.length];
        cardsEl.innerHTML += `
        <div style="background:#fff;border:2px solid ${c};border-radius:10px;padding:16px;">
            <div style="font-size:.72rem;font-weight:700;color:${c};text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">
                <i class="fas fa-gas-pump"></i> ${esc(row.fuel_type)}
            </div>
            <div style="font-size:1.5rem;font-weight:700;color:${c};">${n2(liters)} L</div>
            <div style="font-size:.82rem;color:#333;font-weight:600;margin:4px 0;">?${n2(sales)}</div>
            <div style="font-size:.72rem;color:#888;">Avg ?${n2(price)}/L &nbsp; - &nbsp; ${count} entries</div>
            <div style="margin-top:8px;background:#e9ecef;border-radius:4px;height:6px;overflow:hidden;">
                <div style="width:${pct.toFixed(1)}%;height:100%;background:${c};border-radius:4px;"></div>
            </div>
            <div style="font-size:.68rem;color:#aaa;margin-top:3px;">${pct.toFixed(1)}% of total volume</div>
        </div>`;
    });

    // -- Trend chart --
    renderChart(daily, 'bar');

    // -- Comparison table --
    const cTbody = document.getElementById('rptCompareTbody');
    cTbody.innerHTML = '';
    (comparison||[]).forEach(row => {
        const encoded  = parseFloat(row.total_encoded_liters||0);
        const approved = parseFloat(row.approved_liters||0);
        const varL     = encoded - approved;
        const varPct   = encoded > 0 ? Math.abs(varL / encoded * 100) : 0;
        const flagged  = varPct > 5;
        cTbody.innerHTML += `<tr style="${flagged ? 'background:#fff8f0;' : '-"'}">
            <td><strong>${esc(row.fuel_type)}</strong></td>
            <td style="text-align:right;">${n2(encoded)} L</td>
            <td style="text-align:right;color:#28a745;font-weight:600;">${n2(approved)} L</td>
            <td style="text-align:right;${varL > 0.01 ? 'color:#dc3545;' : 'color:#28a745;'}">${varL >= 0 ? '+' : '-"'}${n2(varL)} L</td>
            <td style="text-align:center;">
                ${flagged
                    ? `<span style="background:#dc3545;color:#fff;padding:2px 7px;border-radius:10px;font-size:.72rem;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> ${varPct.toFixed(1)}%</span>`
                    : `<span style="color:#28a745;font-weight:700;font-size:.82rem;"><i class="fas fa-check"></i> ${varPct.toFixed(1)}%</span>`
                }
            </td>
            <td style="text-align:center;">${row.total_readings}</td>
            <td style="text-align:center;color:#28a745;font-weight:600;">${row.approved_count}</td>
            <td style="text-align:center;color:#CC8800;">${row.pending_count}</td>
            <td style="text-align:center;color:#dc3545;">${row.rejected_count}</td>
        </tr>`;
    });
    if (!comparison?.length) {
        cTbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#bbb;padding:20px;">No comparison data available for this period.</td></tr>';
    }

    // -- Daily breakdown --
    renderDailyTable(daily, false);
    document.getElementById('rptEntryCount').textContent = (daily||[]).length + ' entries';
}

function renderChart(daily, type) {
    const ctx = document.getElementById('rptChart');
    if (!ctx) return;
    if (_rptChart) { _rptChart.destroy(); _rptChart = null; }

    // Group by date and fuel type
    const dates    = [...new Set((daily||[]).map(r => r.day))].sort();
    const fuelTypes = [...new Set((daily||[]).map(r => r.fuel_type))];
    const datasets  = fuelTypes.map((ft, i) => {
        const c = _chartColors[i % _chartColors.length];
        return {
            label: ft,
            data: dates.map(d => {
                const row = (daily||[]).find(r => r.day === d && r.fuel_type === ft);
                return row ? parseFloat(row.liters||0) : 0;
            }),
            backgroundColor: c + (type === 'bar' ? 'cc' : '33'),
            borderColor: c,
            borderWidth: 2,
            fill: type === 'line',
            tension: 0.3,
            pointRadius: 4,
        };
    });

    if (typeof Chart === 'undefined') {
        // Try to load Chart.js dynamically
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js';
        s.onload = () => renderChart(daily, type);
        document.head.appendChild(s);
        return;
    }

    _rptChart = new Chart(ctx, {
        type,
        data: { labels: dates.map(d => new Date(d + 'T00:00:00').toLocaleDateString('en-US',{month:'short',day:'numeric'})), datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.dataset.label}: ${Number(ctx.raw).toLocaleString('en-PH',{minimumFractionDigits:2})} L`
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() + ' L' } },
                x: { ticks: { font: { size: 10 } } }
            }
        }
    });
}

function switchChart(type) {
    if (_rptData) renderChart(_rptData.daily, type);
}

function renderDailyTable(daily, showShift) {
    const head  = document.getElementById('rptDailyHead');
    const tbody = document.getElementById('rptDailyTbody');
    if (showShift) {
        head.innerHTML = '<th>Date</th><th>Fuel Type</th><th>Shift</th><th>Staff</th><th>Liters Sold</th><th>Avg Price/L</th><th>Sales Amount (&#8369;)</th>';
    } else {
        head.innerHTML = '<th>Date</th><th>Fuel Type</th><th>Liters Sold</th><th>Avg Price/L</th><th>Sales Amount (&#8369;)</th>';
    }
    tbody.innerHTML = '';
    (daily||[]).forEach(row => {
        const d  = new Date(row.day + 'T00:00:00');
        const ds = d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
        const shiftLabel = getShiftLabel(row.shift_period);
        if (showShift) {
            tbody.innerHTML += `<tr>
                <td style="white-space:nowrap;">${ds}</td>
                <td><strong>${esc(row.fuel_type)}</strong></td>
                <td><span style="font-size:.75rem;background:#e8f4fd;color:#0056b3;padding:2px 6px;border-radius:8px;">${shiftLabel}</span></td>
                <td><span style="font-size:.78rem;">${esc(row.staff_name||' - ')}</span></td>
                <td style="text-align:right;"><strong>${n2(parseFloat(row.liters||0))} L</strong></td>
                <td style="text-align:right;">?${n2(parseFloat(row.avg_price||0))}</td>
                <td style="text-align:right;"><strong>?${n2(parseFloat(row.sales||0))}</strong></td>
            </tr>`;
        } else {
            tbody.innerHTML += `<tr>
                <td style="white-space:nowrap;">${ds}</td>
                <td><strong>${esc(row.fuel_type)}</strong></td>
                <td style="text-align:right;"><strong>${n2(parseFloat(row.liters||0))} L</strong></td>
                <td style="text-align:right;">?${n2(parseFloat(row.avg_price||0))}</td>
                <td style="text-align:right;"><strong>?${n2(parseFloat(row.sales||0))}</strong></td>
            </tr>`;
        }
    });
    if (!daily?.length) {
        const cols = showShift ? 7 : 5;
        tbody.innerHTML = `<tr><td colspan="${cols}" style="text-align:center;color:#bbb;padding:20px;">No entries found.</td></tr>`;
    }
}

function toggleShiftBreakdown() {
    _shiftBreakdownVisible = !_shiftBreakdownVisible;
    document.getElementById('shiftToggleLabel').textContent = _shiftBreakdownVisible ? 'Hide Staff/Shift' : 'Show Staff/Shift';
    if (_rptData) renderDailyTable(_rptData.daily, _shiftBreakdownVisible);
}

function exportReport() {
    if (!_rptData) return;
    const { meter_readings, vol_sales_summary, vol_amt_summary, comparison, daily, from, to, label } = _rptData;
    let grandL = 0, grandS = 0;
    (vol_amt_summary||[]).forEach(r => { grandL += parseFloat(r.volume_sales||0); grandS += parseFloat(r.amount_sales||0); });

    let csv = `Daily Sales Report\nPeriod: ${label}\nGenerated: ${new Date().toLocaleString()}\n\n`;

    // -"-"- TABLE 1: METER READING -"-"-
    csv += `TABLE 1 - METER READING\n`;
    csv += `Fuel Type,Beginning,Ending,CAL,Volume (L),Price/L,Amount (PHP),Shift,Staff\n`;
    (meter_readings||[]).forEach(r => {
        const shiftLbl = getShiftLabel(r.shift_period);
        csv += `"${r.fuel_type}",${parseFloat(r.beginning||0).toFixed(2)},${parseFloat(r.ending||0).toFixed(2)},${parseFloat(r.cal||0).toFixed(3)},${parseFloat(r.volume_liters||0).toFixed(2)},${parseFloat(r.price_per_liter||0).toFixed(2)},${parseFloat(r.amount||0).toFixed(2)},"${shiftLbl}","${r.staff_name||''}"\n`;
    });
    csv += `TOTAL,,,,${grandL.toFixed(2)},,${grandS.toFixed(2)},,\n\n`;

    // -"-"- TABLE 2: VOLUME SALES SUMMARY -"-"-
    csv += `TABLE 2 - VOLUME SALES SUMMARY\n`;
    csv += `Fuel Type,Volume Sales (L)\n`;
    (vol_sales_summary||[]).forEach(r => {
        csv += `"${r.fuel_type}",${parseFloat(r.volume_sales||0).toFixed(2)}\n`;
    });
    csv += `TOTAL,${grandL.toFixed(2)}\n\n`;

    // -"-"- TABLE 3: VOLUME & AMOUNT SUMMARY -"-"-
    csv += `TABLE 3 - VOLUME & AMOUNT SUMMARY\n`;
    csv += `Fuel Type,Volume Sales (L),Amount Sales (PHP)\n`;
    (vol_amt_summary||[]).forEach(r => {
        csv += `"${r.fuel_type}",${parseFloat(r.volume_sales||0).toFixed(2)},${parseFloat(r.amount_sales||0).toFixed(2)}\n`;
    });
    csv += `TOTAL,${grandL.toFixed(2)},${grandS.toFixed(2)}\n\n`;

    // -"-"- PUMP READINGS VS SALES COMPARISON -"-"-
    csv += `PUMP READINGS VS SALES COMPARISON\nFuel Type,Total Encoded (L),Approved (L),Variance (L),Variance %,Total Readings,Approved,Pending,Rejected\n`;
    (comparison||[]).forEach(r => {
        const enc = parseFloat(r.total_encoded_liters||0), app = parseFloat(r.approved_liters||0);
        const varL = enc - app, varPct = enc > 0 ? Math.abs(varL/enc*100) : 0;
        csv += `"${r.fuel_type}",${enc.toFixed(2)},${app.toFixed(2)},${varL.toFixed(2)},${varPct.toFixed(2)}%,${r.total_readings},${r.approved_count},${r.pending_count},${r.rejected_count}\n`;
    });

    const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(new Blob([csv],{type:'text/csv'})), download: `daily_sales_report_${from}_to_${to}.csv` });
    a.click();
}

function n2(v) { return Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
