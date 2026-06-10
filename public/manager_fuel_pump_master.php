<?php
$page_id = 'fuel_pump_master';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/manager_fuel_config.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
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

// ── Ensure pump_calibration_history table exists ──────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pump_calibration_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            fuel_type VARCHAR(100) NOT NULL,
            previous_calibration DECIMAL(12,3) DEFAULT 0,
            new_calibration DECIMAL(12,3) NOT NULL,
            updated_by INT NOT NULL,
            updated_at DATETIME NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_fuel (fuel_type),
            INDEX idx_date (updated_at),
            FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    error_log("Error creating pump_calibration_history table: " . $e->getMessage());
}

/* -------------------------------------------------------------
   POST HANDLERS
------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {

        /* -- CALIBRATION UPDATE -- */

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

                $pdo->beginTransaction();

                // Update transaction status
                $pdo->prepare("UPDATE fuel_transactions SET status=?, validated_by=?, validated_at=NOW() WHERE transaction_id=? AND station_id=?")->execute([$status,$me['id'],$reading_id,$station_id]);

                if ($status === 'verified') {
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

                log_activity($pdo, $me['id'], 'Validate Transaction', "Transaction #{$reading_id} {$status}. Variance: {$variance_liters} L. Notes: {$notes}");
                $pdo->commit();

                // ── Audit log ──
                try {
                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $action_type = $status === 'verified' ? 'Approve' : 'Reject';
                    $detail = "Fuel transaction {$status} | TXN: #{$reading_id} | {$transaction['fuel_type']} | {$transaction['liters_sold']} L | Variance: {$variance_liters} L | Notes: {$notes}";
                    $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', ?, ?, 'fuel_readings', ?, 'Success', ?, ?, NOW())")
                        ->execute([$me['id'], $action_type, $detail, $transaction['id'] ?? null, $ip, $ua]);
                } catch (Exception $e) {}

                if ($status === 'verified') {
                    $_SESSION['success'] = "Transaction approved successfully. Entry saved to Daily Sales Summary.";
                } else {
                    $_SESSION['success'] = "Transaction #{$reading_id} rejected and flagged for staff correction.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

        /* -- UPDATE CALIBRATION -- */
        case 'update_calibration':
            $fuel_type       = trim($_POST['fuel_type'] ?? '');
            $new_calibration = (float)($_POST['new_calibration'] ?? 0);
            
            try {
                if (empty($fuel_type)) throw new Exception('Fuel type is required.');
                if ($new_calibration < 0) throw new Exception('Calibration value must be a positive number.');

                $pdo->beginTransaction();

                // Get current calibration value for history
                $current_cal_stmt = $pdo->prepare("
                    SELECT latest_calibration 
                    FROM fuel_inventory 
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ");
                $current_cal_stmt->execute([$station_id, $fuel_type]);
                $current_cal = $current_cal_stmt->fetchColumn() ?: 0;
                
                $adjustment_amount = $new_calibration - $current_cal;

                // 1. Update fuel_inventory (for general lookup)
                $stmt = $pdo->prepare("
                    UPDATE fuel_inventory 
                    SET latest_calibration = ?, last_updated = NOW() 
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                ");
                $stmt->execute([$new_calibration, $station_id, $fuel_type]);

                // 2. Update fuel_pumps (for specific pump records)
                $stmt2 = $pdo->prepare("
                    UPDATE fuel_pumps 
                    SET calibration_value = ?, calibration_updated_at = NOW(), calibration_updated_by = ? 
                    WHERE station_id = ? AND fuel_type_id = (SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1)
                ");
                $stmt2->execute([$new_calibration, $me['id'], $station_id, $fuel_type]);

                // 3. Log to pump_calibration_history for Manager and Admin oversight
                $history_stmt = $pdo->prepare("
                    INSERT INTO pump_calibration_history 
                    (station_id, fuel_type, previous_calibration, new_calibration, updated_by, updated_at, created_at, reason)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)
                ");
                $history_stmt->execute([$station_id, $fuel_type, $current_cal, $new_calibration, $me['id'], 'Individual calibration update']);

                // 4. CRITICAL: Insert into fuel_adjustments for Admin oversight visibility
                $adjustment_reason = "Pump calibration updated from " . number_format($current_cal, 2) . "L to " . number_format($new_calibration, 2) . "L (adjustment: " . ($adjustment_amount >= 0 ? '+' : '') . number_format($adjustment_amount, 2) . "L)";
                
                $fuel_adjustments_stmt = $pdo->prepare("
                    INSERT INTO fuel_adjustments 
                    (station_id, adjustment_date, fuel_type, adjustment_type, liters, reason, user_id, status, created_at)
                    VALUES (?, CURDATE(), ?, 'Calibration', ?, ?, ?, 'Completed', NOW())
                ");
                $fuel_adjustments_stmt->execute([
                    $station_id, 
                    $fuel_type, 
                    $adjustment_amount, 
                    $adjustment_reason, 
                    $me['id']
                ]);

                log_activity($pdo, $me['id'], 'Update Calibration', $adjustment_reason);
                $pdo->commit();

                $_SESSION['success'] = "✔ Calibration for {$fuel_type} updated to " . number_format($new_calibration, 2) . " L successfully.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '✖ ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;


        /* -- PUMP CALIBRATION ADJUSTMENT (bulk save from single form) -- */
        case 'pump_calibration_adjust':
            $rows         = $_POST['rows']         ?? [];  // array of row data
            $fuel_types   = $_POST['fuel_type']    ?? [];
            $tanker_labels= $_POST['tanker_label'] ?? [];
            $tanker_refs  = $_POST['tanker_ref']   ?? [];
            $encoded_cals = $_POST['encoded_cal']  ?? [];
            $actual_cals  = $_POST['actual_cal']   ?? [];
            $adj_reasons  = $_POST['adj_reason']   ?? [];

            $saved = 0; $skipped = 0; $errors = [];
            $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['username'] ?? 'Manager');

            foreach ($fuel_types as $i => $fuel_type_raw) {
                $fuel_type_raw = trim($fuel_type_raw);
                $actual_cal    = trim($actual_cals[$i] ?? '');
                $adj_reason    = trim($adj_reasons[$i] ?? '');

                // Skip rows where manager left Actual Value blank
                if ($actual_cal === '' || $actual_cal === null) { $skipped++; continue; }

                $actual_cal    = (float)$actual_cal;
                $encoded_cal   = (float)($encoded_cals[$i] ?? 0);
                $tanker_label  = trim($tanker_labels[$i] ?? '');
                $tanker_ref    = trim($tanker_refs[$i]   ?? '');
                $variance      = round($encoded_cal - $actual_cal, 4);

                if (empty($fuel_type_raw))  { $errors[] = "Row $i: missing fuel type."; continue; }
                if (empty($adj_reason))     { $errors[] = "{$tanker_label}: reason is required."; continue; }
                if ($actual_cal < 0)        { $errors[] = "{$tanker_label}: actual value cannot be negative."; continue; }

                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("
                        UPDATE fuel_inventory
                        SET latest_calibration = ?, last_updated = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ")->execute([$actual_cal, $station_id, $fuel_type_raw]);

                    try {
                        $pdo->prepare("
                            INSERT INTO pump_calibration_history
                                (station_id, fuel_type, previous_calibration, new_calibration, updated_by, updated_at, created_at, reason)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?)
                        ")->execute([$station_id, $tanker_label ?: $fuel_type_raw, $encoded_cal, $actual_cal, $me['id'], $adj_reason]);
                    } catch (Exception $he) {}

                    $full_reason = "Pump Cal [{$tanker_label}][{$tanker_ref}]: Encoded={$encoded_cal}, Actual={$actual_cal}, Var={$variance}. {$adj_reason}";
                    try {
                        $pdo->prepare("
                            INSERT INTO fuel_adjustments
                                (station_id, adjustment_date, fuel_type, adjustment_type, liters, reason, user_id, status, created_at)
                            VALUES (?, CURDATE(), ?, 'Calibration', ?, ?, ?, 'Cleared', NOW())
                        ")->execute([$station_id, $fuel_type_raw, $variance, substr($full_reason, 0, 255), $me['id']]);
                    } catch (Exception $ae) {}

                    log_activity($pdo, $me['id'], 'Pump Calibration Adjust', $full_reason);
                    $pdo->commit();
                    $saved++;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = "{$tanker_label}: " . $e->getMessage();
                }
            }

            if ($saved > 0 && empty($errors)) {
                $_SESSION['success'] = "✔ {$saved} tanker calibration(s) saved successfully by {$mgr_name}.";
            } elseif ($saved > 0) {
                $_SESSION['success'] = "✔ {$saved} saved. Errors: " . implode('; ', $errors);
            } elseif ($skipped === count($fuel_types)) {
                $_SESSION['error'] = '✖ No rows submitted — please fill in at least one Actual Value.';
            } else {
                $_SESSION['error'] = '✖ ' . implode('; ', $errors);
            }
            header('Location: manager_fuel_pump_master.php'); exit;


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
                if (strtolower($transaction['status']) !== 'pending')
                    throw new Exception('This transaction has already been processed.');

                $original_liters = (float)$transaction['liters_sold'];

                $pdo->beginTransaction();

                // Update liters_sold to adjusted value, mark as verified
                $pdo->prepare("
                    UPDATE fuel_transactions
                    SET liters_sold = ?, status = 'verified', validated_by = ?, validated_at = NOW()
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
                    "Transaction #{$reading_id} adjusted: {$original_liters} L ? {$adjusted_liters} L. Reason: {$adj_reason}");
                $pdo->commit();
                $_SESSION['success'] = "? Transaction #{$reading_id} adjusted to {$adjusted_liters} L and approved.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

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
            header('Location: manager_fuel_pump_master.php'); exit;

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

                // Audit trail - no inventory change on reject
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
            header('Location: manager_fuel_pump_master.php'); exit;

        /* -- RECORD DELIVERY -- */
        case 'record_delivery':
            $fuel_type       = trim($_POST['fuel_type_name'] ?? '');
            $delivery_liters = (float)($_POST['delivery_volume'] ?? 0);
            $supplier        = trim($_POST['supplier_name'] ?? 'Petron Corporation');
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
            header('Location: manager_fuel_pump_master.php'); exit;

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
                if (!in_array(strtolower($del['status']), ['pending', 'pending review'])) {
                    throw new Exception('This delivery has already been processed.');
                }

                $original_liters = (float)$del['delivery_liters'];
                $fuel_type       = $del['fuel_type'];
                $liters_to_add   = 0;

                // ── Capacity check (before transaction) ──────────────────────
                // Determine how many liters will actually be added
                $liters_for_cap_check = ($action === 'adjust') ? $adj_liters : $original_liters;

                if (in_array($action, ['approve', 'adjust']) && $liters_for_cap_check > 0) {
                    $capStmt = $pdo->prepare("
                        SELECT COALESCE(current_level, current_stock, 0) AS current_level,
                               COALESCE(capacity, 0) AS capacity
                        FROM fuel_inventory
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $capStmt->execute([$station_id, $fuel_type]);
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

                    // Update tank — try by station+fuel_type first
                    $upd = $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = COALESCE(current_level, 0) + ?,
                            current_stock = COALESCE(current_stock, 0) + ?,
                            last_updated  = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ");
                    $upd->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type]);

                    // If no row matched, insert a new one (requires fuel_type_id)
                    if ($upd->rowCount() === 0 && $fuel_type_id) {
                        $pdo->prepare("
                            INSERT INTO fuel_inventory
                                (station_id, fuel_type_id, fuel_type, current_level, current_stock, last_updated)
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                current_level = COALESCE(current_level, 0) + VALUES(current_level),
                                current_stock = COALESCE(current_stock, 0) + VALUES(current_stock),
                                last_updated  = NOW()
                        ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters_to_add, $liters_to_add]);
                    }

                } elseif ($action === 'adjust') {
                    if ($adj_liters <= 0) throw new Exception('Adjusted volume must be greater than 0.');
                    $liters_to_add = $adj_liters;
                    $full_notes = " | Manager Adjusted (Orig: {$original_liters}L → New: {$adj_liters}L): " . $val_notes;

                    $pdo->prepare("
                        UPDATE fuel_deliveries
                        SET status = 'Verified', delivery_liters = ?, verified_by = ?, verified_at = NOW(),
                            notes = CONCAT(IFNULL(notes,''), ?)
                        WHERE id = ?
                    ")->execute([$adj_liters, $me['id'], $full_notes, $delivery_id]);

                    $upd2 = $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = COALESCE(current_level, 0) + ?,
                            current_stock = COALESCE(current_stock, 0) + ?,
                            last_updated  = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ");
                    $upd2->execute([$liters_to_add, $liters_to_add, $station_id, $fuel_type]);

                    if ($upd2->rowCount() === 0 && $fuel_type_id) {
                        $pdo->prepare("
                            INSERT INTO fuel_inventory
                                (station_id, fuel_type_id, fuel_type, current_level, current_stock, last_updated)
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                current_level = COALESCE(current_level, 0) + VALUES(current_level),
                                current_stock = COALESCE(current_stock, 0) + VALUES(current_stock),
                                last_updated  = NOW()
                        ")->execute([$station_id, $fuel_type_id, $fuel_type, $liters_to_add, $liters_to_add]);
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
                        $tank_note    = $new_tank_level !== null ? " New tank: {$new_tank_level}L." : '';
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

                $pdo->commit();

                if (in_array($action, ['approve', 'adjust'])) {
                    $tank_msg = $new_tank_level !== null
                        ? " Tank level updated to <strong>" . number_format($new_tank_level, 0) . " L</strong>."
                        : '';
                    $_SESSION['success'] = "Delivery #{$delivery_id} approved. Added " . number_format($liters_to_add, 0) . "L of {$fuel_type} to inventory.{$tank_msg}";
                } else {
                    $_SESSION['success'] = "Delivery #{$delivery_id} returned to staff for correction.";
                }

            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

        /* -- ADJUST TANK LEVEL -- */
        case 'adjust_tank_level':
            $fuel_type_id    = $_POST['fuel_type_id'] ?? '';
            $new_level       = (float)($_POST['new_level'] ?? 0);
            $reason          = trim($_POST['reason'] ?? '');
            $adjustment_type = $_POST['adjustment_type'] ?? '';
            try {
                if (empty($fuel_type_id))  throw new Exception('Please select a fuel type.');
                if (empty($adjustment_type)) throw new Exception('Please select an adjustment type.');
                if (strlen($reason) < 10)  throw new Exception('Detailed reason is required (minimum 10 characters) for transparency.');
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

                $diff_str = ($difference >= 0 ? '+' : '') . number_format($difference, 2);
                $_SESSION['success'] = "? {$fuel_name} tank level set to " . number_format($new_level, 2) . " L (change: {$diff_str} L). Audit trail saved.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

        /* -- UPDATE PRICE -- */
        case 'update_price':
            $fuel_type_id = $_POST['fuel_type_id'] ?? '';
            $new_price    = (float)($_POST['new_price'] ?? 0);
            $reason       = trim($_POST['reason'] ?? '');
            try {
                if (empty($fuel_type_id)) throw new Exception('Please select a fuel type.');
                if (strlen($reason) < 10) throw new Exception('Detailed reason is required (minimum 10 characters) for transparency.');
                if ($new_price <= 0)      throw new Exception('Price must be greater than 0.');

                // Get current price + fuel type name
                $stmt = $pdo->prepare("SELECT fi.price_per_liter, ft.name as fuel_type_name FROM fuel_inventory fi JOIN fuel_types ft ON fi.fuel_type_id=ft.id WHERE fi.station_id=? AND fi.fuel_type_id=?");
                $stmt->execute([$station_id, $fuel_type_id]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) throw new Exception('Fuel inventory record not found.');

                $old_price    = $current['price_per_liter'];
                $fuel_name    = $current['fuel_type_name'];
                $reason_short = substr($reason, 0, 255);

                $pdo->beginTransaction();

                // Update price
                $pdo->prepare("UPDATE fuel_inventory SET price_per_liter=?, last_updated=NOW() WHERE station_id=? AND fuel_type_id=?")->execute([$new_price, $station_id, $fuel_type_id]);

                // Audit trail row - use liters=0, store price change in reason
                $audit_reason = substr("Price: ?{$old_price} ? ?{$new_price}/L. {$reason}", 0, 255);
                $pdo->prepare("INSERT INTO fuel_adjustments (station_id, fuel_type_id, fuel_type, adjustment_type, liters, reason, user_id, adjustment_date) VALUES (?,?,?,'price_update',0,?,?,CURDATE())")->execute([$station_id, $fuel_type_id, $fuel_name, $audit_reason, $me['id']]);

                log_activity($pdo, $me['id'], 'Update Price', "Updated {$fuel_name} price: ?{$old_price} ? ?{$new_price}/L. Reason: {$reason}");
                $pdo->commit();

                $_SESSION['success'] = "? {$fuel_name} price updated: ?" . number_format($old_price, 2) . " ? ?" . number_format($new_price, 2) . "/L. Audit trail saved.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

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
                $_SESSION['success'] = "? Variance #{$variance_id} updated to {$db_status}.";
            } catch (Exception $e) {
                $_SESSION['error'] = '? ' . $e->getMessage();
            }
            header('Location: manager_fuel_pump_master.php'); exit;

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
                    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Variance Report</title><style>body{font-family:Arial,sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#003d7a;color:#fff}.high{color:#dc3545;font-weight:700}.ok{color:#28a745}
/* Anti-scroll / Compress Tables */
.data-table {
    width: 100% !important;
    table-layout: fixed !important;
}
.data-table th, .data-table td {
    white-space: normal !important;
    word-break: break-word !important;
    padding: 8px 6px !important;
    font-size: .82rem !important;
}
.jo-act-btn {
    white-space: normal !important;
    padding: 4px 8px !important;
    font-size: .75rem !important;
    text-align: center;
    justify-content: center;
}
.audit-badge, .tag-open, .tag-investigate, .tag-resolved {
    white-space: normal !important;
    text-align: center;
}
</style></head><body>';
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
            header('Location: manager_fuel_pump_master.php'); exit;
    }
}

/* -------------------------------------------------------------
   FETCH DATA
------------------------------------------------------------- */
$tank_data          = [];
$calibration_logs   = [];
$pending_readings   = [];
$variance_reports   = [];
$recent_adjustments = [];
$shift_history      = [];
$deliveries         = [];
$reconciliation_data = [];

try {
    // Tank levels
    $stmt = $pdo->prepare("
        SELECT fi.*, ft.name as fuel_type_name,
               CASE WHEN fi.current_stock<=0 THEN 'Out of Stock' WHEN fi.current_stock<=fi.critical_level THEN 'Low Stock' ELSE 'Available' END as stock_status,
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
        SELECT pch.id, pch.fuel_type as fuel_type_name, 
               pch.previous_calibration, pch.new_calibration as calibration_value, 
               u.name as updated_by_name, pch.updated_at as calibration_updated_at
        FROM pump_calibration_history pch
        LEFT JOIN users u ON pch.updated_by = u.id
        WHERE pch.station_id=? 
        ORDER BY pch.updated_at DESC LIMIT 30
    ");
    $stmt->execute([$station_id]);
    $calibration_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("calibration_logs: ".$e->getMessage()); }

try {
    $stmt = $pdo->prepare("
        SELECT ft.*, u.name as staff_name,
               fi.current_stock as tank_level,
               fi.latest_calibration as tank_calibration
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
        ORDER BY FIELD(fvr.status,'Open','Under Investigation','Resolved'), fvr.report_date DESC
        LIMIT 30
    ");
    $stmt->execute([$station_id]);
    $variance_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("variance_reports: ".$e->getMessage()); }

try {
    $stmt = $pdo->prepare("SELECT fa.*, ft.name as fuel_type_name, u.name as user_name FROM fuel_adjustments fa JOIN fuel_types ft ON fa.fuel_type_id=ft.id JOIN users u ON fa.user_id=u.id WHERE fa.station_id=? ORDER BY fa.adjustment_date DESC, fa.created_at DESC LIMIT 15");
    $stmt->execute([$station_id]);
    $recent_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("recent_adjustments: ".$e->getMessage()); }

try {
    $stmt = $pdo->prepare("
        SELECT fd.*, u.name as recorded_by_name, v.name as verified_by_name,
               COALESCE(fi.current_level, fi.current_stock, 0) as current_tank_level,
               COALESCE(fi.capacity, 0)                        as tank_capacity
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        LEFT JOIN users v ON fd.verified_by = v.id
        LEFT JOIN fuel_inventory fi
            ON fi.station_id = fd.station_id
            AND LOWER(TRIM(fi.fuel_type)) = LOWER(TRIM(fd.fuel_type))
        WHERE fd.station_id = ?
        ORDER BY fd.delivery_date DESC, fd.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$station_id]);
    $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("deliveries: ".$e->getMessage()); }

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
        SELECT fi.fuel_type_id, ft.name as fuel_type_name, fi.current_stock,
               COALESCE(SUM(ftr.liters_sold),0) as total_sold_today,
               fi.capacity
        FROM fuel_inventory fi
        JOIN fuel_types ft ON fi.fuel_type_id=ft.id
        LEFT JOIN fuel_transactions ftr ON ftr.station_id=fi.station_id AND LOWER(TRIM(ftr.fuel_type))=LOWER(TRIM(ft.name)) AND DATE(ftr.transaction_date)=CURDATE() AND ftr.status='verified'
        WHERE fi.station_id=?
        GROUP BY fi.fuel_type_id, ft.name, fi.current_stock, fi.capacity
    ");
    $stmt->execute([$station_id]);
    $reconciliation_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { error_log("reconciliation_data: ".$e->getMessage()); }

// ── 17-Tanker Pump Master Config ─────────────────────────────────────────────
// Static map of all 17 tankers with their fuel type and underground tank reference
$TANK_CONFIG_17 = [
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 1',     'tank'=>'Underground Tank #1',  'tanker_num'=>1],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 2',     'tank'=>'Underground Tank #2',  'tanker_num'=>2],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 3',     'tank'=>'Underground Tank #3',  'tanker_num'=>3],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 1 - 4',     'tank'=>'Underground Tank #4',  'tanker_num'=>4],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 5',     'tank'=>'Underground Tank #5',  'tanker_num'=>5],
    ['fuel_type'=>'Diesel',      'label'=>'DIESEL 2 - 6',     'tank'=>'Underground Tank #6',  'tanker_num'=>6],
    ['fuel_type'=>'Kerosene',    'label'=>'KEROSENE - 1',     'tank'=>'Underground Tank #7',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 1', 'tank'=>'Underground Tank #8',  'tanker_num'=>1],
    ['fuel_type'=>'Turbo Diesel','label'=>'TURBO DIESEL - 2', 'tank'=>'Underground Tank #9',  'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 1',     'tank'=>'Underground Tank #10', 'tanker_num'=>1],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 2',     'tank'=>'Underground Tank #11', 'tanker_num'=>2],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 3',     'tank'=>'Underground Tank #12', 'tanker_num'=>3],
    ['fuel_type'=>'XCS Plus',    'label'=>'XCS PLUS - 4',     'tank'=>'Underground Tank #13', 'tanker_num'=>4],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 1',  'tank'=>'Underground Tank #14', 'tanker_num'=>1],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 1 - 2',  'tank'=>'Underground Tank #15', 'tanker_num'=>2],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 3',  'tank'=>'Underground Tank #16', 'tanker_num'=>3],
    ['fuel_type'=>'XTRA UNL',    'label'=>'XTRA UNL 2 - 4',  'tank'=>'Underground Tank #17', 'tanker_num'=>4],
];

// Pull fuel_inventory calibration data (keyed by lowercase fuel_type)
$pm_inv_lookup = [];
try {
    $inv_stmt = $pdo->prepare("
        SELECT fuel_type, latest_calibration, last_updated, fuel_type_id
        FROM fuel_inventory WHERE station_id = ?
    ");
    $inv_stmt->execute([$station_id]);
    foreach ($inv_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pm_inv_lookup[strtolower(trim($row['fuel_type']))] = $row;
    }
} catch (Exception $e) { error_log("pm_inv: ".$e->getMessage()); }

// Pull latest calibration reading per (fuel_type, tanker_num) from fuel_transactions
$pm_txn_lookup = [];
try {
    $txn_cal_stmt = $pdo->prepare("
        SELECT fuel_type, pump_id, calibration, transaction_date,
               COALESCE(NULLIF(CONCAT(TRIM(COALESCE(u.first_name,'')), ' ', TRIM(COALESCE(u.last_name,''))), ' '), u.username, '—') AS staff_name
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.station_id = ?
          AND ft.calibration IS NOT NULL AND ft.calibration > 0
          AND ft.id = (
              SELECT id FROM fuel_transactions ft2
              WHERE ft2.station_id = ft.station_id
                AND LOWER(TRIM(ft2.fuel_type)) = LOWER(TRIM(ft.fuel_type))
                AND ft2.pump_id = ft.pump_id
              ORDER BY ft2.transaction_date DESC, ft2.id DESC LIMIT 1
          )
        GROUP BY ft.fuel_type, ft.pump_id
    ");
    $txn_cal_stmt->execute([$station_id]);
    foreach ($txn_cal_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower(trim($row['fuel_type'])) . '_' . (int)$row['pump_id'];
        $pm_txn_lookup[$key] = $row;
    }
} catch (Exception $e) { error_log("pm_txn: ".$e->getMessage()); }

// Build the final 17-row dataset
$pump_master_fuel_types = [];
foreach ($TANK_CONFIG_17 as $tc) {
    $ft_key  = strtolower(trim($tc['fuel_type']));
    $inv     = $pm_inv_lookup[$ft_key] ?? null;
    $txn_key = $ft_key . '_' . $tc['tanker_num'];
    $txn     = $pm_txn_lookup[$txn_key] ?? null;

    // Calibration value: prefer latest staff-encoded value from transactions, fall back to inventory
    $cal_value   = $txn ? (float)$txn['calibration'] : ($inv ? (float)$inv['latest_calibration'] : 0);
    $cal_date    = $txn ? $txn['transaction_date']    : ($inv ? $inv['last_updated'] : null);
    $encoded_by  = $txn ? ($txn['staff_name'] ?? '—') : '—';

    $pump_master_fuel_types[] = [
        'fuel_type'     => $tc['fuel_type'],
        'label'         => $tc['label'],
        'tank'          => $tc['tank'],
        'tanker_num'    => $tc['tanker_num'],
        'cal_value'     => $cal_value,
        'cal_date'      => $cal_date,
        'encoded_by'    => $encoded_by,
        'fuel_type_id'  => $inv['fuel_type_id'] ?? null,
        // Status: if cal > 0 it's been encoded; manager can flag/clear
        'status'        => $cal_value > 0 ? 'Pending' : 'No Reading',
    ];
}

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
.mfm-wrap { max-width:1400px; margin:0 auto; padding:10px; padding-bottom:120px; }

/* Notification Banner */
.mfm-alert { display:flex; align-items:center; gap:12px; padding:14px 20px; border-radius:10px; margin-bottom:16px; font-weight:600; font-size:.9rem; animation:slideDown .3s ease; }
.mfm-alert.success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.mfm-alert.error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
.mfm-alert .close-alert { margin-left:auto; cursor:pointer; font-size:1.2rem; opacity:.7; }
.mfm-alert .close-alert:hover { opacity:1; }
@keyframes slideDown { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }

.fuel-section { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); border:1px solid #e9ecef; margin-bottom:20px; scroll-margin-top:20px; transition:opacity 0.3s ease, transform 0.3s ease; }
.fuel-section-inner { padding:20px; }
.tab-content.active { display:block; }
.tab-inner { padding:20px; }

/* Section visibility states */
.fuel-section.hidden { display:none; opacity:0; transform:translateY(-10px); }
.fuel-section.visible { display:block; opacity:1; transform:translateY(0); }

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
.data-table { width:100%; border-collapse:collapse; table-layout:fixed; word-wrap:break-word; font-size:.85rem; }
.data-table th, .data-table td { padding:10px 12px; text-align:left; border-bottom:1px solid #e9ecef; }
.data-table th { background:#002F70 !important; color:#fff !important; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; }
.data-table tbody tr:hover { background:#e3f2fd !important; }

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

/* Anti-scroll / Compress Tables */
.data-table {
    width: 100% !important;
    table-layout: fixed !important;
}
.data-table th, .data-table td {
    white-space: normal !important;
    word-break: break-word !important;
    padding: 8px 6px !important;
    font-size: .82rem !important;
}
.jo-act-btn {
    white-space: normal !important;
    padding: 4px 8px !important;
    font-size: .75rem !important;
    text-align: center;
    justify-content: center;
}
.audit-badge, .tag-open, .tag-investigate, .tag-resolved {
    white-space: normal !important;
    text-align: center;
}
</style>

<div class="mfm-wrap">
    <div class="page-head">
        <div>
            <h1 class="h1">Pump Master</h1><div class="sub">MONITOR AND MANAGE PUMP ASSIGNMENTS, READINGS, AND OPERATIONAL STATUS.</div>
        </div>
        
    </div>

<?php if ($msg): ?>
<div class="mfm-alert <?php echo $msg_type; ?>">
    <i class="fas fa-<?php echo $msg_type==='success'?'check-circle':'exclamation-circle'; ?>"></i>
    <span><?php echo htmlspecialchars($msg); ?></span>
    <span class="close-alert" onclick="this.parentElement.remove()">-</span>
</div>
<?php endif; ?>

<!-- -- SECTION NAVIGATION (sidebar-driven, no top tabs) -- -->

<!-- ----------------------------------------------------------
     TAB 7: PUMP MASTER (CALIBRATION)
---------------------------------------------------------- -->
<div id="pump-master" class="fuel-section">
<div class="fuel-section-inner">

    <span class="audit-badge"><i class="fas fa-lock"></i> Manager Only</span>
    </div>

        <!-- Calibration Values Table -->
    <div class="info-box" style="margin-bottom:20px;border-left:none;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;">
        <h4 style="margin:0 0 14px;color:<?php echo $colors['primary']; ?>;"><i class="fas fa-sliders-h"></i> Pump Master – Calibration Management <span style="font-size:.75rem;font-weight:400;color:#888;">(<?php echo count($pump_master_fuel_types); ?> tankers)</span></h4>

        <style>
        .pm-tbl{width:100%;border-collapse:collapse;font-size:.8rem;table-layout:fixed;}
        .pm-tbl thead tr{background:#002F70;}
        .pm-tbl thead th{padding:9px 8px;color:#fff;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap;}
        .pm-tbl tbody tr{border-bottom:1px solid #eef0f3;transition:background .12s;}
        .pm-tbl tbody tr:hover{background:#f8fafc;}
        .pm-tbl tbody td{padding:8px;vertical-align:middle;word-break:break-word;}
        .pm-label{font-weight:700;color:#1e293b;font-size:.82rem;}
        .pm-sub{font-size:.7rem;color:#64748b;margin-top:1px;}
        .pm-cal-val{font-weight:700;font-family:monospace;font-size:.85rem;color:#1e293b;}
        .pm-cal-zero{color:#dc2626;font-style:italic;}
        .pm-var-pos{color:#dc2626;font-weight:700;font-family:monospace;font-size:.82rem;}
        .pm-var-neg{color:#16a34a;font-weight:700;font-family:monospace;font-size:.82rem;}
        .pm-var-zero{color:#94a3b8;font-family:monospace;font-size:.82rem;}
        .pm-input{padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.8rem;width:100%;box-sizing:border-box;transition:border-color .15s;background:#fff;}
        .pm-input:focus{outline:none;border-color:#475569;box-shadow:0 0 0 2px rgba(71,85,105,.1);}
        .pm-reason{padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:.75rem;width:100%;box-sizing:border-box;resize:none;background:#fff;}
        .pm-reason:focus{outline:none;border-color:#475569;}
        .sb-pending{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#fef3c7;color:#92400e;}
        .sb-cleared{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#dcfce7;color:#166534;}
        .sb-noread{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:700;background:#f1f5f9;color:#64748b;}
        .pm-actions{display:flex;justify-content:flex-end;align-items:center;gap:10px;margin-top:14px;padding-top:12px;border-top:1px solid #e2e8f0;}
        .pm-btn-save{padding:9px 24px;background:#002F70;color:#fff;border:none;border-radius:7px;font-size:.85rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .15s;}
        .pm-btn-save:hover{background:#001a40;}
        .pm-btn-reset{padding:9px 18px;background:#f1f5f9;color:#475569;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;transition:background .15s;}
        .pm-btn-reset:hover{background:#e2e8f0;}
        </style>

        <form method="post" action="manager_fuel_pump_master.php" id="pmBulkForm">
        <input type="hidden" name="action" value="pump_calibration_adjust">

        <div style="overflow-x:auto;">
        <table class="pm-tbl">
            <colgroup>
                <col style="width:13%"><col style="width:13%"><col style="width:10%">
                <col style="width:10%"><col style="width:10%"><col style="width:9%">
                <col style="width:22%"><col style="width:8%">
            </colgroup>
            <thead><tr>
                <th>Fuel Type Name</th>
                <th>Tanker Reference</th>
                <th>Cal. Value (L)</th>
                <th>Last Cal. Date</th>
                <th>Actual Value (L)</th>
                <th>Variance (L)</th>
                <th>Reason</th>
                <th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pump_master_fuel_types as $i => $f):
                $cal = (float)$f['cal_value'];
                $st  = $f['status'];
            ?>
            <!-- Hidden array fields for this row -->
            <input type="hidden" name="fuel_type[]"    value="<?php echo htmlspecialchars($f['fuel_type']); ?>">
            <input type="hidden" name="tanker_label[]" value="<?php echo htmlspecialchars($f['label']); ?>">
            <input type="hidden" name="tanker_ref[]"   value="<?php echo htmlspecialchars($f['tank']); ?>">
            <input type="hidden" name="encoded_cal[]"  value="<?php echo $cal; ?>">
            <tr>
                <!-- Fuel Type Name -->
                <td>
                    <div class="pm-label"><?php echo htmlspecialchars($f['label']); ?></div>
                    <div class="pm-sub"><?php echo htmlspecialchars($f['fuel_type']); ?></div>
                </td>

                <!-- Tanker Reference -->
                <td>
                    <div style="font-size:.78rem;font-weight:600;color:#334155;"><?php echo htmlspecialchars($f['tank']); ?></div>
                    <div class="pm-sub">Tanker #<?php echo $f['tanker_num']; ?></div>
                </td>

                <!-- Calibration Value (staff-encoded) -->
                <td>
                    <?php if ($cal > 0): ?>
                        <span class="pm-cal-val"><?php echo number_format($cal, 2); ?></span>
                        <?php if (!empty($f['encoded_by']) && $f['encoded_by'] !== '—'): ?>
                        <div class="pm-sub">by <?php echo htmlspecialchars($f['encoded_by']); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pm-cal-zero">⚠ No Reading</span>
                    <?php endif; ?>
                </td>

                <!-- Last Calibration Date -->
                <td style="font-size:.75rem;color:#555;">
                    <?php echo $f['cal_date'] ? date('M j, Y', strtotime($f['cal_date'])) : '—'; ?>
                    <?php if ($f['cal_date']): ?>
                    <div class="pm-sub"><?php echo date('H:i', strtotime($f['cal_date'])); ?></div>
                    <?php endif; ?>
                </td>

                <!-- Actual Value (Manager Input) -->
                <td>
                    <input type="number"
                           name="actual_cal[]"
                           id="actual_<?php echo $i; ?>"
                           class="pm-input"
                           step="0.0001" min="0"
                           placeholder="<?php echo $cal > 0 ? $cal : '0.00'; ?>"
                           oninput="pmCalcVar(<?php echo $i; ?>, <?php echo $cal; ?>)">
                </td>

                <!-- Variance (auto-computed) -->
                <td>
                    <span id="var_<?php echo $i; ?>" class="pm-var-zero">—</span>
                </td>

                <!-- Reason -->
                <td>
                    <textarea name="adj_reason[]"
                              class="pm-reason" rows="2"
                              id="reason_<?php echo $i; ?>"
                              placeholder="e.g. calibration test, staff error, pump variance"></textarea>
                </td>

                <!-- Status -->
                <td>
                    <?php if ($st === 'Cleared'): ?>
                        <span class="sb-cleared">✔ Cleared</span>
                    <?php elseif ($st === 'No Reading'): ?>
                        <span class="sb-noread">No Reading</span>
                    <?php else: ?>
                        <span class="sb-pending">⏳ Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- Single Save + Reset at bottom right -->
        <div class="pm-actions">
            <button type="button" class="pm-btn-reset" onclick="pmResetAll()">
                <i class="fas fa-undo"></i> Reset
            </button>
            <button type="submit" class="pm-btn-save">
                <i class="fas fa-save"></i> Save All Changes
            </button>
        </div>
        </form>

        <script>
        function pmCalcVar(idx, encodedCal) {
            var inp  = document.getElementById('actual_' + idx);
            var span = document.getElementById('var_' + idx);
            if (!inp || !span) return;
            var v = parseFloat(inp.value);
            if (isNaN(v)) { span.textContent = '—'; span.className = 'pm-var-zero'; return; }
            var diff = Math.round((encodedCal - v) * 10000) / 10000;
            span.textContent = (diff > 0 ? '+' : '') + diff.toFixed(4) + ' L';
            span.className   = diff > 0 ? 'pm-var-pos' : (diff < 0 ? 'pm-var-neg' : 'pm-var-zero');
        }
        function pmResetAll() {
            document.querySelectorAll('#pmBulkForm input[name="actual_cal[]"]').forEach(function(el, i) {
                el.value = '';
                var span = document.getElementById('var_' + i);
                if (span) { span.textContent = '—'; span.className = 'pm-var-zero'; }
            });
            document.querySelectorAll('#pmBulkForm textarea[name="adj_reason[]"]').forEach(function(el) { el.value = ''; });
        }
        // Validate before submit: any filled actual_cal must have a reason
        document.getElementById('pmBulkForm').addEventListener('submit', function(e) {
            var actuals = document.querySelectorAll('#pmBulkForm input[name="actual_cal[]"]');
            var reasons = document.querySelectorAll('#pmBulkForm textarea[name="adj_reason[]"]');
            var hasAny  = false, missingReason = false, label = '';
            var labels  = <?php echo json_encode(array_column($pump_master_fuel_types, 'label')); ?>;
            actuals.forEach(function(el, i) {
                if (el.value.trim() !== '') {
                    hasAny = true;
                    if (!reasons[i] || reasons[i].value.trim() === '') {
                        missingReason = true;
                        label = labels[i] || ('Row ' + (i+1));
                    }
                }
            });
            if (!hasAny) { e.preventDefault(); alert('Please fill in at least one Actual Value before saving.'); return; }
            if (missingReason) { e.preventDefault(); alert('Please enter a Reason for: ' + label); return; }
        });
        </script>

    </div>

    <!-- Calibration Log -->
    <?php if (!empty($calibration_logs)): ?>
    </div>
    <div style="overflow-x:hidden;">
    <table class="data-table">
        <thead><tr><th>Log #</th><th>Fuel Type</th><th>Previous Cal.</th><th>New Calibration</th><th>Updated By</th><th>Updated At</th></tr></thead>
        <tbody>
        <?php foreach ($calibration_logs as $cl): ?>
        <tr>
            <td><strong>#<?php echo htmlspecialchars($cl['id']); ?></strong></td>
            <td><?php echo htmlspecialchars($cl['fuel_type_name']); ?></td>
            <td style="color:#6c757d;"><?php echo number_format($cl['previous_calibration']??0,2); ?> L</td>
            <td><strong><?php echo number_format($cl['calibration_value']??0,2); ?> L</strong></td>
            <td><span class="audit-badge"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($cl['updated_by_name']??'System'); ?></span></td>
            <td style="font-size:.8rem;"><?php echo $cl['calibration_updated_at'] ? date('M j, Y H:i',strtotime($cl['calibration_updated_at'])) : '-'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

</div>
</div>

</div><!-- /.mfm-wrap -->


</div><!-- /.mfm-wrap -->
<!-- ----------------------------------------------------------
     MODALS
---------------------------------------------------------- -->

<!-- Edit Calibration Modal -->
<div id="calEditModal" class="modal">
<div class="modal-box" style="max-width:460px;">
    <div class="modal-header">
        <div class="modal-title"><i class="fas fa-edit"></i> Edit Calibration - <span id="calEditFuelName"></span></div>
        <button class="modal-close" onclick="closeModal('calEditModal')">-</button>
    </div>

    <!-- Current values display -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;background:#f8f9fa;border-radius:8px;padding:12px;">
        <div style="text-align:center;">
            <div style="font-size:.7rem;color:#888;text-transform:uppercase;margin-bottom:4px;">Current Calibration</div>
            <div style="font-size:1.2rem;font-weight:700;color:<?php echo $colors['primary']; ?>;" id="calEditCurrentCal">-</div>
        </div>
        <div style="text-align:center;">
            <div style="font-size:.7rem;color:#888;text-transform:uppercase;margin-bottom:4px;">Current Price/L</div>
            <div style="font-size:1.2rem;font-weight:700;color:<?php echo $colors['primary']; ?>;" id="calEditCurrentPrice">-</div>
        </div>
    </div>

    <!-- Update Calibration -->
    <form method="post" action="manager_fuel_pump_master.php" id="calEditForm">
        <input type="hidden" name="action" value="update_calibration">
        <input type="hidden" name="fuel_type" id="calEditFuelType">

        <div class="form-group">
            <label class="form-label">New Calibration Value (Liters) <span class="required">*</span></label>
            <input type="number" name="new_calibration" id="calEditNewCal"
                class="form-control" step="0.01" min="0" required
                placeholder="Enter calibration value in liters">
            <div class="form-hint"><i class="fas fa-info-circle"></i> Enter the actual calibration reading from dipstick or pump meter.</div>
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
        <form method="post" action="manager_fuel_pump_master.php">
            <input type="hidden" name="action" value="update_price">
            <input type="hidden" name="fuel_type_id" id="calEditFuelTypeId">
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <div class="form-group" style="margin:0;flex:1;min-width:120px;">
                    <label class="form-label" style="font-size:.82rem;">New Price (?/L)</label>
                    <input type="number" name="new_price" id="calEditNewPrice"
                        class="form-control" step="0.01" min="0.01" placeholder="e.g. 58.50">
                </div>
                <div class="form-group" style="margin:0;flex:2;min-width:160px;">
                    <label class="form-label" style="font-size:.82rem;">Reason <span class="required">*</span></label>
                    <input type="text" name="reason" class="form-control"
                        placeholder="e.g. Petron price update" minlength="10">
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
                    <td style="padding:8px 6px;font-weight:700;" id="dd_id">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Supplier</td>
                    <td style="padding:8px 6px;" id="dd_supplier">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Fuel Type</td>
                    <td style="padding:8px 6px;" id="dd_fuel_type">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Volume (L)</td>
                    <td style="padding:8px 6px;font-weight:700;color:#198754;" id="dd_liters">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Invoice No.</td>
                    <td style="padding:8px 6px;" id="dd_invoice">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Delivery Date</td>
                    <td style="padding:8px 6px;" id="dd_date">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Encoded By</td>
                    <td style="padding:8px 6px;" id="dd_encoded_by">—</td>
                </tr>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:8px 6px;color:#888;font-weight:600;vertical-align:top;">Notes</td>
                    <td style="padding:8px 6px;white-space:pre-wrap;" id="dd_notes">—</td>
                </tr>
                <tr>
                    <td style="padding:8px 6px;color:#888;font-weight:600;">Current Tank Level</td>
                    <td style="padding:8px 6px;font-weight:700;color:#003d82;" id="dd_current_tank">—</td>
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
    <form method="post" action="manager_fuel_pump_master.php">
        <input type="hidden" name="action" value="validate_delivery">
        <input type="hidden" name="delivery_action" value="approve">
        <input type="hidden" name="delivery_id" id="dapprove_id">

        <div class="modal-body">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Fuel Type</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_fuel">—</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Volume</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_liters">—</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Invoice No.</div>
                    <div style="font-weight:700;color:#212529;" id="dapprove_invoice">—</div>
                </div>
            </div>
            <!-- Tank level preview -->
            <div id="dapprove_new_tank" style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:6px;padding:8px 12px;margin-bottom:14px;font-size:.82rem;color:#1b5e20;font-weight:600;text-align:center;">
                —
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
    <form method="post" action="manager_fuel_pump_master.php">
        <input type="hidden" name="action" value="validate_delivery">
        <input type="hidden" name="delivery_action" value="reject">
        <input type="hidden" name="delivery_id" id="dreturn_id">

        <div class="modal-body">
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Fuel Type</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_fuel">—</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Volume</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_liters">—</div>
                </div>
                <div>
                    <div style="font-size:.68rem;color:#888;text-transform:uppercase;margin-bottom:3px;">Invoice No.</div>
                    <div style="font-weight:700;color:#212529;" id="dreturn_invoice">—</div>
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
        <button class="modal-close" onclick="closeModal('validateModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
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
                    <div style="font-weight:700;font-size:.95rem;" id="val_tank_level">-</div>
                </div>
                <div>
                    <div style="font-size:.7rem;color:#888;">Calibration</div>
                    <div style="font-weight:700;font-size:.95rem;" id="val_calibration">-</div>
                </div>
                <div>
                    <div style="font-size:.7rem;color:#888;">Variance %</div>
                    <div style="font-weight:700;font-size:.95rem;" id="val_variance_pct">-</div>
                </div>
            </div>
            <div id="val_variance_warn" style="display:none;margin-top:10px;padding:8px 10px;background:#f8d7da;border-radius:6px;font-size:.78rem;color:#721c24;font-weight:600;">
                <i class="fas fa-exclamation-triangle"></i> Variance &gt;5% detected. Review carefully before approving. Manager notes required.
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Variance Override (Liters) <span style="font-size:.75rem;color:#888;">- 0 if none</span></label>
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
        <button class="modal-close" onclick="closeModal('rejectModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
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
        <button class="modal-close" onclick="closeModal('adjustModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
        <input type="hidden" name="action" value="adjust_reading">
        <input type="hidden" name="reading_id" id="adj_reading_id">

        <!-- Reading summary -->
        <div style="background:#fffbf0;border:1px solid #ffeaa7;border-radius:8px;padding:12px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Transaction</div>
                <div style="font-weight:700;font-size:.8rem;font-family:monospace;" id="adj_txn_id">-</div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;" id="adj_fuel_type">-</div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Staff-Encoded (L)</div>
                <div style="font-weight:700;color:#00264D;" id="adj_original_liters">-</div>
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
        <button class="modal-close" onclick="closeModal('approveDailyModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
        <input type="hidden" name="action" value="approve_daily_log">
        <input type="hidden" name="txn_id" id="adl_txn_id">

        <!-- Entry summary -->
        <div style="background:#f0fff4;border:1px solid #c3e6cb;border-radius:8px;padding:12px;margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center;">
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Log ID</div>
                <div style="font-weight:700;font-size:.78rem;font-family:monospace;" id="adl_display_id">-</div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Fuel Type</div>
                <div style="font-weight:700;" id="adl_fuel_type">-</div>
            </div>
            <div>
                <div style="font-size:.7rem;color:#888;text-transform:uppercase;">Liters Sold</div>
                <div style="font-weight:700;color:<?php echo $colors['primary']; ?>;" id="adl_liters">-</div>
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
        <button class="modal-close" onclick="closeModal('rejectDailyModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
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
        <button class="modal-close" onclick="closeModal('varianceModal')">-</button>
    </div>
    <form method="post" action="manager_fuel_pump_master.php">
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
    // Hide all fuel sections first with fade out
    document.querySelectorAll('.fuel-section').forEach(section => {
        if (section.id !== name) {
            section.classList.add('hidden');
            section.classList.remove('visible');
            setTimeout(() => {
                section.style.display = 'none';
            }, 300);
        }
    });
    
    // Show only the selected section with fade in
    const targetSection = document.getElementById(name);
    if (targetSection) {
        targetSection.style.display = 'block';
        setTimeout(() => {
            targetSection.classList.remove('hidden');
            targetSection.classList.add('visible');
        }, 50);
        
        // Scroll to top of the section smoothly
        setTimeout(() => {
            window.scrollTo({
                top: targetSection.offsetTop - 20,
                behavior: 'smooth'
            });
        }, 100);
        
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
                + ' &rarr; After Approval: <strong>' + Math.round(newLevel).toLocaleString() + ' L</strong>'
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
                const sign = diff >= 0 ? '+' : '';
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
    document.getElementById('calEditCurrentPrice').textContent = '-' + parseFloat(currentPrice).toFixed(2);
    document.getElementById('calEditNewCal').value   = parseFloat(currentCal).toFixed(2);
    document.getElementById('calEditNewPrice').value = parseFloat(currentPrice).toFixed(2);
    openModal('calEditModal');
}

function quickEditCalibration(fuelType, currentVal) {
    // Legacy: used by tank cards "Update" button - open modal with price=0
    openCalEditModal(fuelType, currentVal, 0, '');
}

function prefillCalibration(sel) {
    const opt = sel.options[sel.selectedIndex];
    const cur = opt.getAttribute('data-current');
    const inp = document.getElementById('calValueInput');
    if (inp && cur !== null) inp.value = cur;
}

/* -- SCROLL TO SECTION FROM HASH -- */
const _validTabs = ['fuel-transactions','daily-ops','fuel-deliveries','adjustments','reconciliation','variance-reports','shift-history','fuel-reports','pump-master'];

function activateTabFromHash() {
    const hash = window.location.hash.replace('#','');
    if (hash && _validTabs.includes(hash)) {
        // Small delay to let page render fully
        setTimeout(() => showSectionOnly(hash), 100);
    } else {
        // If no hash, show the first section (Fuel Transactions) by default
        setTimeout(() => showSectionOnly('pump-master'), 100);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    activateTabFromHash();

    // Sidebar sub-item clicks - show/hide section without reload if already on page
    document.querySelectorAll('a[href*="manager_fuel_pump_master.php"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            const hash = this.getAttribute('href').split('#')[1];
            if (hash && _validTabs.includes(hash)) {
                if (window.location.pathname.includes('manager_fuel_management_complete')) {
                    e.preventDefault();
                    showSectionOnly(hash);
                }
            }
        });
    });
});

/* -- ADJUSTMENT FORM HELPERS -- */
function showCurrentLevel(sel) {
    const opt = sel.options[sel.selectedIndex];
    const stock = opt.getAttribute('data-stock');
    const hint  = document.getElementById('currentLevelHint');
    const val   = document.getElementById('currentLevelVal');
    if (stock !== null && stock !== '') {
        val.textContent = parseFloat(stock).toFixed(2) + ' L';
        hint.style.display = 'block';
    } else {
        hint.style.display = 'none';
    }
    updateDiffHint();
}

function showCurrentPrice(sel) {
    const opt   = sel.options[sel.selectedIndex];
    const price = opt.getAttribute('data-price');
    const hint  = document.getElementById('currentPriceHint');
    const val   = document.getElementById('currentPriceVal');
    if (price !== null && price !== '') {
        val.textContent = '-' + parseFloat(price).toFixed(2) + '/L';
        hint.style.display = 'block';
    } else {
        hint.style.display = 'none';
    }
    updatePriceDiffHint();
}

function updateDiffHint() {
    const sel     = document.querySelector('[name="fuel_type_id"]', document.getElementById('tankAdjForm'));
    const newInp  = document.getElementById('newLevelInput');
    const diffEl  = document.getElementById('diffHint');
    if (!sel || !newInp || !diffEl) return;
    const opt     = sel.options[sel.selectedIndex];
    const current = parseFloat(opt.getAttribute('data-stock') || 0);
    const newVal  = parseFloat(newInp.value || 0);
    if (newInp.value !== '' && !isNaN(current)) {
        const diff = newVal - current;
        const sign = diff >= 0 ? '+' : '';
        diffEl.style.display = 'block';
        diffEl.style.color   = diff >= 0 ? '#28a745' : '#dc3545';
        diffEl.innerHTML     = '<i class="fas fa-arrow-right"></i> Change: <strong>' + sign + diff.toFixed(2) + ' L</strong>';
    } else {
        diffEl.style.display = 'none';
    }
}

function updatePriceDiffHint() {
    const sel    = document.querySelector('select[name="fuel_type_id"]', document.getElementById('priceForm'));
    const newInp = document.getElementById('newPriceInput');
    const diffEl = document.getElementById('priceDiffHint');
    if (!sel || !newInp || !diffEl) return;
    const opt     = sel.options[sel.selectedIndex];
    const current = parseFloat(opt.getAttribute('data-price') || 0);
    const newVal  = parseFloat(newInp.value || 0);
    if (newInp.value !== '' && !isNaN(current) && current > 0) {
        const diff = newVal - current;
        const sign = diff >= 0 ? '+' : '';
        diffEl.style.display = 'block';
        diffEl.style.color   = diff >= 0 ? '#dc3545' : '#28a745';
        diffEl.innerHTML     = '<i class="fas fa-arrow-right"></i> Change: <strong>?' + sign + diff.toFixed(2) + '/L</strong>';
    } else {
        diffEl.style.display = 'none';
    }
}

function checkReasonLength(textarea, counterId, minLen) {
    const len = textarea.value.length;
    const el  = document.getElementById(counterId);
    if (!el) return;
    el.textContent = len + '/' + minLen + ' min';
    el.style.color = len >= minLen ? '#28a745' : '#dc3545';
}

// Wire up live diff hints
document.addEventListener('DOMContentLoaded', function() {
    const newLevel = document.getElementById('newLevelInput');
    if (newLevel) newLevel.addEventListener('input', updateDiffHint);
    const newPrice = document.getElementById('newPriceInput');
    if (newPrice) newPrice.addEventListener('input', updatePriceDiffHint);
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

    // Data from PHP - tank levels per fuel type (last known stock, simplified)
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
        if (el) el.value = id === 'histDateFilter' ? '<?php echo date('Y-m-d'); ?>' : '';
    });
    const tbody = document.getElementById('historyTbody');
    if (tbody) tbody.querySelectorAll('tr').forEach(r => r.style.display = '');
}

/* -- WEEKLY / MONTHLY SALES SUMMARY REPORT -- */
let _rptData = null;
let _rptChart = null;
let _shiftBreakdownVisible = false;

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
            label: 'Week: ' + mon.toLocaleDateString('en-US',{month:'short',day:'numeric'}) + ' - ' + sun.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) };
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
    // ── Grand totals ──────────────────────────────────────────────────────────
    let grandL = 0, grandS = 0;
    (vol_amt_summary||[]).forEach(r => { grandL += parseFloat(r.volume_sales||0); grandS += parseFloat(r.amount_sales||0); });
    const grandAvg = grandL > 0 ? grandS / grandL : 0;
    document.getElementById('rptGrandLiters').textContent   = n2(grandL) + ' L';
    document.getElementById('rptGrandSales').textContent    = '₱' + n2(grandS);
    document.getElementById('rptGrandAvgPrice').textContent = '₱' + n2(grandAvg);
    document.getElementById('rptPeriodLabel').textContent   = label;

    const rowBg = ['#fff','#f8faff'];

    // ── TABLE 1: Meter Reading ────────────────────────────────────────────────
    const mrTbody = document.getElementById('meterReadingTbody');
    const mrFoot  = document.getElementById('meterReadingFoot');
    mrTbody.innerHTML = '';
    let mrTotalL = 0, mrTotalA = 0;
    if (meter_readings && meter_readings.length) {
        meter_readings.forEach((r, i) => {
            const vol = parseFloat(r.volume_liters||0);
            const amt = parseFloat(r.amount||0);
            mrTotalL += vol; mrTotalA += amt;
            const shiftLbl = r.shift_period === 'first' ? '6AM–2PM' : (r.shift_period === 'second' ? '2PM–12MN' : (r.shift_period||'—'));
            mrTbody.innerHTML += `<tr style="background:${rowBg[i%2]};border-bottom:1px solid #f0f0f0;">
                <td style="padding:8px 12px;font-weight:600;">${esc(r.fuel_type)}</td>
                <td style="padding:8px 12px;text-align:right;color:#555;">${n2(parseFloat(r.beginning||0))}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:600;">${n2(parseFloat(r.ending||0))}</td>
                <td style="padding:8px 12px;text-align:right;color:#888;">${parseFloat(r.cal||0).toFixed(3)}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;color:#003d7a;">${n2(vol)}</td>
                <td style="padding:8px 12px;text-align:right;">₱${n2(parseFloat(r.price_per_liter||0))}</td>
                <td style="padding:8px 12px;text-align:right;font-weight:700;">₱${n2(amt)}</td>
                <td style="padding:8px 12px;text-align:center;"><span style="font-size:.72rem;background:#e8f4fd;color:#0056b3;padding:2px 7px;border-radius:10px;">${esc(shiftLbl)}</span></td>
                <td style="padding:8px 12px;font-size:.78rem;color:#555;">${esc(r.staff_name||'—')}</td>
            </tr>`;
        });
        document.getElementById('mrTotalLiters').textContent = n2(mrTotalL) + ' L';
        document.getElementById('mrTotalAmount').textContent = '₱' + n2(mrTotalA);
        mrFoot.style.display = '';
    } else {
        mrTbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#bbb;padding:20px;">No approved meter readings found.</td></tr>';
        mrFoot.style.display = 'none';
    }

    // ── TABLE 2: Volume Sales Summary ─────────────────────────────────────────
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
        vsTbody.innerHTML = '<tr><td colspan="2" style="text-align:center;color:#bbb;padding:20px;">—</td></tr>';
        vsFoot.style.display = 'none';
    }

    // ── TABLE 3: Volume & Amount Summary ──────────────────────────────────────
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
                <td style="padding:10px 14px;text-align:right;font-weight:700;color:#166534;">₱${n2(amt)}</td>
            </tr>`;
        });
        document.getElementById('volAmtTotalLiters').textContent = n2(grandL) + ' L';
        document.getElementById('volAmtTotalAmount').textContent = '₱' + n2(grandS);
        vaFoot.style.display = '';
    } else {
        vaTbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:#bbb;padding:20px;">—</td></tr>';
        vaFoot.style.display = 'none';
    }

    // ── Per-fuel KPI cards ────────────────────────────────────────────────────
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
            <div style="font-size:.72rem;color:#888;">Avg ?${n2(price)}/L &nbsp;-&nbsp; ${count} entries</div>
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
        cTbody.innerHTML += `<tr style="${flagged ? 'background:#fff8f0;' : ''}">
            <td><strong>${esc(row.fuel_type)}</strong></td>
            <td style="text-align:right;">${n2(encoded)} L</td>
            <td style="text-align:right;color:#28a745;font-weight:600;">${n2(approved)} L</td>
            <td style="text-align:right;${varL > 0.01 ? 'color:#dc3545;' : 'color:#28a745;'}">${varL >= 0 ? '+' : ''}${n2(varL)} L</td>
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
        head.innerHTML = '<th>Date</th><th>Fuel Type</th><th>Shift</th><th>Staff</th><th>Liters Sold</th><th>Avg Price/L</th><th>Sales Amount (?)</th>';
    } else {
        head.innerHTML = '<th>Date</th><th>Fuel Type</th><th>Liters Sold</th><th>Avg Price/L</th><th>Sales Amount (?)</th>';
    }
    tbody.innerHTML = '';
    (daily||[]).forEach(row => {
        const d  = new Date(row.day + 'T00:00:00');
        const ds = d.toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
        const shiftLabel = row.shift_period === 'first' ? '6AM-2PM' : (row.shift_period === 'second' ? '2PM-12MN' : (row.shift_period || '-'));
        if (showShift) {
            tbody.innerHTML += `<tr>
                <td style="white-space:nowrap;">${ds}</td>
                <td><strong>${esc(row.fuel_type)}</strong></td>
                <td><span style="font-size:.75rem;background:#e8f4fd;color:#0056b3;padding:2px 6px;border-radius:8px;">${shiftLabel}</span></td>
                <td><span style="font-size:.78rem;">${esc(row.staff_name||'-')}</span></td>
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

    // ── TABLE 1: METER READING ──
    csv += `TABLE 1 - METER READING\n`;
    csv += `Fuel Type,Beginning,Ending,CAL,Volume (L),Price/L,Amount (PHP),Shift,Staff\n`;
    (meter_readings||[]).forEach(r => {
        const shiftLbl = r.shift_period === 'first' ? '6AM-2PM' : (r.shift_period === 'second' ? '2PM-12MN' : (r.shift_period||''));
        csv += `"${r.fuel_type}",${parseFloat(r.beginning||0).toFixed(2)},${parseFloat(r.ending||0).toFixed(2)},${parseFloat(r.cal||0).toFixed(3)},${parseFloat(r.volume_liters||0).toFixed(2)},${parseFloat(r.price_per_liter||0).toFixed(2)},${parseFloat(r.amount||0).toFixed(2)},"${shiftLbl}","${r.staff_name||''}"\n`;
    });
    csv += `TOTAL,,,,${grandL.toFixed(2)},,${grandS.toFixed(2)},,\n\n`;

    // ── TABLE 2: VOLUME SALES SUMMARY ──
    csv += `TABLE 2 - VOLUME SALES SUMMARY\n`;
    csv += `Fuel Type,Volume Sales (L)\n`;
    (vol_sales_summary||[]).forEach(r => {
        csv += `"${r.fuel_type}",${parseFloat(r.volume_sales||0).toFixed(2)}\n`;
    });
    csv += `TOTAL,${grandL.toFixed(2)}\n\n`;

    // ── TABLE 3: VOLUME & AMOUNT SUMMARY ──
    csv += `TABLE 3 - VOLUME & AMOUNT SUMMARY\n`;
    csv += `Fuel Type,Volume Sales (L),Amount Sales (PHP)\n`;
    (vol_amt_summary||[]).forEach(r => {
        csv += `"${r.fuel_type}",${parseFloat(r.volume_sales||0).toFixed(2)},${parseFloat(r.amount_sales||0).toFixed(2)}\n`;
    });
    csv += `TOTAL,${grandL.toFixed(2)},${grandS.toFixed(2)}\n\n`;

    // ── PUMP READINGS VS SALES COMPARISON ──
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
