<?php
// ============================================================
// Manager Fuel Transaction Validation – manager_fuel_transaction_validation.php
// Purpose: View, Validate, Reject, and Audit staff pump readings
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_transactions_validation';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager only
if (!in_array($role, ['manager', 'supervisor', 'admin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: staff_dashboard.php'); 
    exit;
}

if ($station_id <= 0) {
    $_SESSION['error'] = 'No station assigned.';
    header('Location: manager_dashboard.php'); 
    exit;
}

// â”€â”€ Shift Dependency & Continuity Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function get_preceding_shift_and_date($pdo, $shift_key, $date) {
    $stmt = $pdo->query("SELECT shift_key FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    $shifts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($shifts)) {
        return null;
    }
    
    $index = array_search(strtolower($shift_key), array_map('strtolower', $shifts));
    if ($index === false) {
        return null;
    }
    
    if ($index > 0) {
        return [
            'shift_key' => $shifts[$index - 1],
            'date' => $date
        ];
    } else {
        $prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
        return [
            'shift_key' => $shifts[count($shifts) - 1],
            'date' => $prev_date
        ];
    }
}

function get_preceding_shift_validated_ending($pdo, $station_id, $pump_id, $current_shift, $current_date) {
    $preceding = get_preceding_shift_and_date($pdo, $current_shift, $current_date);
    if ($preceding) {
        $stmt = $pdo->prepare("
            SELECT present_reading 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(shift_period) = LOWER(?) 
              AND DATE(transaction_date) = ?
              AND LOWER(status) IN ('verified', 'adjusted')
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            return (float)$val;
        }
    }
    
    $stmt_fallback = $pdo->prepare("
        SELECT present_reading 
        FROM fuel_transactions 
        WHERE station_id = ? 
          AND pump_id = ? 
          AND LOWER(status) IN ('verified', 'adjusted')
        ORDER BY transaction_date DESC, id DESC 
        LIMIT 1
    ");
    $stmt_fallback->execute([$station_id, $pump_id]);
    $val_fallback = $stmt_fallback->fetchColumn();
    return $val_fallback !== false ? (float)$val_fallback : 0.0;
}

function is_pending_validation_status($status_str) {
    $s = strtolower(trim($status_str ?? ''));
    if ($s === 'readings_submitted' || $s === 'draft' || empty($s)) {
        return false;
    }
    return str_contains($s, 'pending') || in_array($s, ['closing_completed', 'submitted']);
}

// ─── Filters & Inputs ──────────────────────────────────────────
$date_from          = trim($_GET['date_from']          ?? date('Y-m-d', strtotime('-30 days')));
$date_to            = trim($_GET['date_to']            ?? date('Y-m-d'));
$shift_filter       = trim($_GET['shift_filter']       ?? 'all');
$fuel_type_filter   = trim($_GET['fuel_type']          ?? '');
$status_filter      = trim($_GET['status_filter']      ?? 'pending');
$search_query       = trim($_GET['search_query']       ?? '');
$export             = trim($_GET['export']             ?? '');

// ─── AJAX Action: Get Fuel Closing for Review ─────────────────
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_closing_for_review') {
    header('Content-Type: application/json');
    $tx_ids_raw = $_GET['tx_ids'] ?? '';
    $tx_id_arr = [];
    if (is_array($tx_ids_raw)) {
        $tx_id_arr = array_map('intval', $tx_ids_raw);
    } else {
        $tx_id_arr = array_filter(array_map('intval', explode(',', (string)$tx_ids_raw)));
    }

    if (empty($tx_id_arr)) {
        echo json_encode(['success' => false, 'message' => 'No transaction IDs provided.']);
        exit;
    }

    $in_pl = implode(',', $tx_id_arr);
    $tx_stmt = $pdo->query("
        SELECT ft.*, fp.pump_number,
               COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Staff') as staff_name
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.id IN ($in_pl) AND ft.station_id = {$station_id}
        ORDER BY ft.id ASC
    ");
    $selected_txs = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($selected_txs)) {
        echo json_encode(['success' => false, 'message' => 'Selected transactions not found.']);
        exit;
    }

    $first_tx = $selected_txs[0];
    $rep_date = date('Y-m-d', strtotime($first_tx['transaction_date'] ?: $first_tx['created_at']));
    $rep_shift = !empty($first_tx['shift_name']) ? $first_tx['shift_name'] : (!empty($first_tx['shift_period']) ? (strtolower($first_tx['shift_period']) === 'second' ? 'Second Shift' : 'First Shift') : 'Second Shift');
    $rep_shift_key = strtolower($first_tx['shift_period'] ?? '');
    if (!$rep_shift_key) {
        $rep_shift_key = (strpos(strtolower($rep_shift), 'first') !== false || strpos(strtolower($rep_shift), '1') !== false) ? 'first' : 'second';
    }

    $calc_liters = 0.0;
    $calc_sales = 0.0;
    $pump_items = [];
    foreach ($selected_txs as $stx) {
        $beg = (float)$stx['previous_reading'];
        $end = (float)$stx['present_reading'];
        $cal = (float)$stx['calibration'];
        $lit = (float)$stx['liters_sold'];
        if ($end >= $beg && $end > 0) {
            $lit = max(0, $end - $beg - $cal);
        }
        $amt = round($lit * (float)$stx['price_per_liter'], 2);
        $calc_liters += $lit;
        $calc_sales += $amt;

        $pump_items[] = [
            'id' => (int)$stx['id'],
            'txn_id' => $stx['transaction_id'],
            'pump_name' => $stx['pump_number'] ?: ('Pump #' . ($stx['pump_id'] ?: '?')),
            'fuel_type' => $stx['fuel_type'],
            'beginning' => $beg,
            'ending' => $end,
            'cal' => $cal,
            'liters' => $lit,
            'price' => (float)$stx['price_per_liter'],
            'amount' => $amt,
            'staff' => $stx['staff_name']
        ];
    }

    // Check existing fuel_sales_closing record
    $cls_stmt = $pdo->prepare("
        SELECT * FROM fuel_sales_closing 
        WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?)
        ORDER BY id DESC LIMIT 1
    ");
    $cls_stmt->execute([$station_id, $rep_date, $rep_shift, $rep_shift_key]);
    $existing_closing = $cls_stmt->fetch(PDO::FETCH_ASSOC);

    $mgr_name = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
    if (!$mgr_name) $mgr_name = $me['username'] ?? 'Manager';

    $staff_encoder = $first_tx['staff_name'];

    echo json_encode([
        'success' => true,
        'report_date' => $rep_date,
        'shift' => $rep_shift,
        'shift_period' => $rep_shift_key,
        'staff_name' => $staff_encoder,
        'manager_name' => $mgr_name,
        'calculated' => [
            'total_liters' => $calc_liters,
            'total_sales' => $calc_sales,
            'pumps' => $pump_items
        ],
        'closing' => $existing_closing ?: null
    ]);
    exit;
}

// ── AJAX Action: Save Closing and Validate Fuel Transactions ─────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save_closing_and_validate')) {
    header('Content-Type: application/json');
    $tx_ids_raw = $_POST['tx_ids'] ?? [];
    if (is_string($tx_ids_raw)) {
        $tx_ids_raw = json_decode($tx_ids_raw, true) ?: explode(',', $tx_ids_raw);
    }
    $tx_id_arr = array_filter(array_map('intval', (array)$tx_ids_raw));

    if (empty($tx_id_arr)) {
        echo json_encode(['success' => false, 'message' => 'No transaction IDs provided for approval.']);
        exit;
    }

    $rep_date        = trim($_POST['report_date'] ?? date('Y-m-d'));
    $shift           = trim($_POST['shift'] ?? 'Second Shift');
    $shift_period    = trim($_POST['shift_period'] ?? 'second');
    $total_fuel_sales= (float)($_POST['total_fuel_sales'] ?? 0);
    $total_liters    = (float)($_POST['total_liters'] ?? 0);

    $cash_shift1     = (float)($_POST['cash_shift1'] ?? 0);
    $cash_shift2     = (float)($_POST['cash_shift2'] ?? 0);
    $total_cash      = (float)($_POST['total_cash'] ?? 0);

    $ar_shift1       = (float)($_POST['ar_shift1'] ?? 0);
    $ar_shift2       = (float)($_POST['ar_shift2'] ?? 0);
    $total_ar        = (float)($_POST['total_ar'] ?? 0);

    $net_sales       = (float)($_POST['net_sales'] ?? 0);
    $total_cash_bank = (float)($_POST['total_cash_bank'] ?? 0);

    $checked_by      = trim($_POST['checked_by'] ?? '');
    $verified_by     = trim($_POST['verified_by'] ?? '');
    $manager_remarks = trim($_POST['manager_remarks'] ?? '');

    try {
        $pdo->beginTransaction();

        // 1. Check or Insert/Update fuel_sales_closing
        $stmt_chk = $pdo->prepare("SELECT id FROM fuel_sales_closing WHERE station_id = ? AND report_date = ? AND (shift = ? OR shift_period = ?) LIMIT 1");
        $stmt_chk->execute([$station_id, $rep_date, $shift, $shift_period]);
        $closing_id = $stmt_chk->fetchColumn();

        if ($closing_id) {
            $stmt_upd = $pdo->prepare("
                UPDATE fuel_sales_closing SET
                    shift = ?, shift_period = ?, total_fuel_sales = ?, total_liters = ?,
                    cash_shift1 = ?, cash_shift2 = ?, total_cash = ?,
                    ar_shift1 = ?, ar_shift2 = ?, total_ar = ?, net_sales = ?,
                    total_cash_bank = ?, verified_by = ?, checked_by = ?, encoded_by = ?,
                    encoded_at = NOW(), status = 'VERIFIED'
                WHERE id = ?
            ");
            $stmt_upd->execute([
                $shift, $shift_period, $total_fuel_sales, $total_liters,
                $cash_shift1, $cash_shift2, $total_cash,
                $ar_shift1, $ar_shift2, $total_ar, $net_sales,
                $total_cash_bank, $verified_by, $checked_by, $me['id'],
                $closing_id
            ]);
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO fuel_sales_closing (
                    station_id, report_date, shift, shift_period, total_fuel_sales, total_liters,
                    cash_shift1, cash_shift2, total_cash, ar_shift1, ar_shift2, total_ar,
                    net_sales, total_cash_bank, verified_by, checked_by, encoded_by, encoded_at, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'VERIFIED'
                )
            ");
            $stmt_ins->execute([
                $station_id, $rep_date, $shift, $shift_period, $total_fuel_sales, $total_liters,
                $cash_shift1, $cash_shift2, $total_cash, $ar_shift1, $ar_shift2, $total_ar,
                $net_sales, $total_cash_bank, $verified_by, $checked_by, $me['id']
            ]);
            $closing_id = $pdo->lastInsertId();
        }

        // 2. Validate all transactions in $tx_id_arr
        $in_pl = implode(',', $tx_id_arr);
        $tx_stmt = $pdo->query("SELECT * FROM fuel_transactions WHERE id IN ($in_pl) AND station_id = {$station_id}");
        $transactions_to_validate = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);

        $validated_count = 0;
        foreach ($transactions_to_validate as $tx) {
            $prev_reading    = (float)$tx['previous_reading'];
            $present_reading = (float)$tx['present_reading'];
            $calibration     = (float)$tx['calibration'];
            $price_per_liter = (float)$tx['price_per_liter'];

            if ($present_reading >= $prev_reading && $present_reading > 0) {
                $liters_sold = max(0.00, $present_reading - $prev_reading - $calibration);
            } else {
                $liters_sold = max(0.00, (float)$tx['liters_sold']);
            }

            $total_amount = round($liters_sold * $price_per_liter, 2);

            $up = $pdo->prepare("
                UPDATE fuel_transactions 
                SET previous_reading = ?, present_reading = ?, liters_sold = ?, total_amount = ?,
                    status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ?
                WHERE id = ?
            ");
            $up->execute([$prev_reading, $present_reading, $liters_sold, $total_amount, $me['id'], $manager_remarks ?: null, $tx['id']]);

            // Deduct inventory
            $base_fuel_type = preg_replace('/\s*-\s*\d+$/i', '', trim($tx['fuel_type']));
            $up_stock = $pdo->prepare("
                UPDATE fuel_inventory 
                SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                    current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                    last_updated   = NOW()
                WHERE station_id = ? 
                  AND (
                      LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                   OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                  )
            ");
            $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type'], $base_fuel_type]);

            log_activity($pdo, $me['id'], 'Fuel Reading Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | {$liters_sold} L");
            $validated_count++;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Fuel Sales Closing for {$rep_date} ({$shift}) saved and {$validated_count} transaction(s) approved successfully!"
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ─── POST Actions (Validate / Reject) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_ajax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    $action  = trim($_POST['action'] ?? '');
    $raw_id  = trim($_POST['id'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    if (empty($raw_id)) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid transaction ID.']);
            exit;
        }
        $_SESSION['error'] = 'Invalid transaction ID.';
        header('Location: manager_fuel_transaction_validation.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch transaction details (supports both integer id and transaction_id string)
        $stmt = $pdo->prepare("SELECT * FROM fuel_transactions WHERE (id = ? OR transaction_id = ?) AND station_id = ? LIMIT 1");
        $stmt->execute([$raw_id, $raw_id, $station_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new Exception("Transaction not found.");
        }
        $tx_id = (int)$tx['id'];

        if ($action === 'validate') {
            // Guard: must be pending validation
            if (!is_pending_validation_status($tx['status'])) {
                throw new Exception("Transaction has already been processed.");
            }

            $prev_reading    = (float)$tx['previous_reading'];
            $present_reading = (float)$tx['present_reading'];
            $calibration     = (float)$tx['calibration'];
            $price_per_liter = (float)$tx['price_per_liter'];

            if ($present_reading >= $prev_reading && $present_reading > 0) {
                $liters_sold = max(0.00, $present_reading - $prev_reading - $calibration);
            } else {
                $liters_sold = max(0.00, (float)$tx['liters_sold']);
            }

            $total_amount = round($liters_sold * $price_per_liter, 2);

            // Mark as Verified (Validated)
            $up = $pdo->prepare("UPDATE fuel_transactions SET previous_reading = ?, present_reading = ?, liters_sold = ?, total_amount = ?, status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
            $up->execute([$prev_reading, $present_reading, $liters_sold, $total_amount, $me['id'], $remarks ?: null, $tx_id]);

            // Deduct stock from fuel_inventory (matching exact fuel_type or base fuel_type without pump suffix)
            $base_fuel_type = preg_replace('/\s*-\s*\d+$/i', '', trim($tx['fuel_type']));
            $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                       SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                           current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                           last_updated   = NOW()
                                       WHERE station_id = ? 
                                         AND (
                                             LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                                          OR LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                                         )");
            $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type'], $base_fuel_type]);

            // Safety check: ensure the fuel type was found in inventory
            if ($up_stock->rowCount() === 0) {
                error_log("FUEL INVENTORY WARNING: No fuel_inventory row matched fuel_type='{$tx['fuel_type']}' station_id={$station_id} for TXN {$tx['transaction_id']}");
            }

            // Log activity
            log_activity($pdo, $me['id'], 'Fuel Reading Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | {$liters_sold} L");

            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> validated successfully.";
        }
        
        elseif ($action === 'reject') {
            if (empty($remarks)) {
                throw new Exception("Rejection remarks/reason is required.");
            }
            // Guard: must be pending validation
            if (!is_pending_validation_status($tx['status'])) {
                throw new Exception("Transaction has already been processed.");
            }

            // Update status to Rejected
            $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Rejected', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
            $up->execute([$me['id'], $remarks, $tx_id]);

            // Log activity
            log_activity($pdo, $me['id'], 'Fuel Reading Rejected', "TXN {$tx['transaction_id']} | Reason: {$remarks}");

            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> rejected and returned to staff.";
        }

        elseif ($action === 'adjust') {
            if (empty($remarks)) {
                throw new Exception("Adjustment remarks/reason is required.");
            }
            // Guard: must be pending validation
            if (!is_pending_validation_status($tx['status'])) {
                throw new Exception("Transaction has already been processed.");
            }

            $beginning   = isset($_POST['beginning'])   ? (float)$_POST['beginning']   : null;
            $ending      = isset($_POST['ending'])      ? (float)$_POST['ending']      : null;
            $calibration = isset($_POST['calibration']) ? (float)$_POST['calibration'] : null;

            if ($beginning !== null && $ending !== null && $calibration !== null) {
                // Enforce sequence validation & carry-over check for pump transactions
                if ($tx['pump_id'] > 0) {
                    $tx_date_str = date('Y-m-d', strtotime($tx['transaction_date']));
                    $preceding = get_preceding_shift_and_date($pdo, $tx['shift_period'], $tx_date_str);
                    
                    if ($preceding) {
                        // Check if there is any unverified/unadjusted transaction for the preceding shift
                        $stmt_check = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM fuel_transactions 
                            WHERE station_id = ? 
                              AND pump_id = ? 
                              AND LOWER(shift_period) = LOWER(?) 
                              AND DATE(transaction_date) = ?
                              AND LOWER(status) NOT IN ('verified', 'adjusted')
                        ");
                        $stmt_check->execute([$station_id, $tx['pump_id'], $preceding['shift_key'], $preceding['date']]);
                        $unverified_count = (int)$stmt_check->fetchColumn();
                        
                        if ($unverified_count > 0) {
                            throw new Exception("Cannot adjust this transaction. The transaction for the preceding shift (" . ucfirst($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
                        }
                    }
                    
                    // Verify that beginning reading matches validated preceding shift ending reading
                    $prev_ending = get_preceding_shift_validated_ending($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str);
                    if (abs($beginning - $prev_ending) > 0.01) {
                        throw new Exception("Invalid Beginning Reading: Adjusted Beginning Reading (" . number_format($beginning, 2) . ") must match the preceding shift's validated ending reading (" . number_format($prev_ending, 2) . ").");
                    }
                }

                // Perform calculations
                $liters_sold = $ending - $beginning - $calibration;
                if ($liters_sold < 0) {
                    throw new Exception("Ending reading cannot be less than beginning reading and calibration combined.");
                }
                $price_per_liter = (float)$tx['price_per_liter'];
                $total_amount    = $liters_sold * $price_per_liter;

                // Update all fields including adjusted readings
                $up = $pdo->prepare("
                    UPDATE fuel_transactions 
                    SET previous_reading = ?, 
                        present_reading = ?, 
                        calibration = ?, 
                        liters_sold = ?, 
                        total_amount = ?, 
                        status = 'Adjusted', 
                        validated_by = ?, 
                        validated_at = NOW(), 
                        reject_reason = ? 
                    WHERE id = ?
                ");
                $up->execute([$beginning, $ending, $calibration, $liters_sold, $total_amount, $me['id'], $remarks, $tx_id]);

                // Deduct stock from fuel_inventory using the new liters_sold
                $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                           SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                               current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                               last_updated   = NOW()
                                           WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
                $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type']]);

                // Fetch fuel_type_id for the adjustment record
                $fuel_type_id = null;
                try {
                    $ft_stmt = $pdo->prepare("SELECT fuel_type_id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?)) LIMIT 1");
                    $ft_stmt->execute([$station_id, $tx['fuel_type']]);
                    $fuel_type_id = $ft_stmt->fetchColumn() ?: null;
                } catch (Exception $e) {}

                // Insert into fuel_adjustments log
                try {
                    $meta_notes = json_encode([
                        'transaction_id' => $tx['transaction_id'],
                        'fuel_line' => 'Pump #' . ($tx['pump_id'] ?? '—'),
                        'fuel_type' => $tx['fuel_type'],
                        'shift' => $tx['shift_name'] ?: ($tx['shift_period'] ?: '—'),
                        'staff_name' => $tx['staff_name'] ?? '—',
                        'prev_beginning' => (float)$tx['previous_reading'],
                        'prev_ending' => (float)$tx['present_reading'],
                        'prev_calibration' => (float)$tx['calibration'],
                        'new_beginning' => $beginning,
                        'new_ending' => $ending,
                        'new_calibration' => $calibration,
                    ]);

                    $ins_adj = $pdo->prepare("
                        INSERT INTO fuel_adjustments 
                        (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, notes, status, approved_by, approved_at, created_at)
                        VALUES (?, CURDATE(), ?, ?, 'transaction_adjustment', ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
                    ");
                    $liters_diff = $liters_sold - (float)$tx['liters_sold'];
                    $ins_adj->execute([
                        $station_id,
                        $tx['fuel_type'],
                        $fuel_type_id,
                        $liters_diff,
                        $tx['liters_sold'],
                        $liters_sold,
                        $remarks,
                        $me['id'],
                        $meta_notes,
                        $me['id']
                    ]);
                } catch (Exception $e) {
                    error_log("Failed to insert transaction adjustment log: " . $e->getMessage());
                }

                log_activity($pdo, $me['id'], 'Fuel Reading Adjusted and Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | Old: {$tx['liters_sold']} L -> New: {$liters_sold} L | Reason: {$remarks}");
                $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> adjusted and validated successfully.";
            } else {
                // Legacy / bulk adjust: just change status to Adjusted
                $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Adjusted', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$me['id'], $remarks, $tx_id]);

                // Insert into fuel_adjustments log for bulk/legacy adjustment
                try {
                    $meta_notes = json_encode([
                        'transaction_id' => $tx['transaction_id'],
                        'fuel_line' => 'Pump #' . ($tx['pump_id'] ?? '—'),
                        'fuel_type' => $tx['fuel_type'],
                        'shift' => $tx['shift_name'] ?: ($tx['shift_period'] ?: '—'),
                        'staff_name' => $tx['staff_name'] ?? '—',
                        'prev_beginning' => (float)$tx['previous_reading'],
                        'prev_ending' => (float)$tx['present_reading'],
                        'prev_calibration' => (float)$tx['calibration'],
                        'new_beginning' => (float)$tx['previous_reading'],
                        'new_ending' => (float)$tx['present_reading'],
                        'new_calibration' => (float)$tx['calibration'],
                    ]);

                    $ins_adj = $pdo->prepare("
                        INSERT INTO fuel_adjustments 
                        (station_id, adjustment_date, fuel_type, fuel_type_id, adjustment_type, liters, previous_value, new_value, reason, user_id, notes, status, approved_by, approved_at, created_at)
                        VALUES (?, CURDATE(), ?, null, 'transaction_adjustment', 0, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
                    ");
                    $ins_adj->execute([
                        $station_id,
                        $tx['fuel_type'],
                        (float)$tx['liters_sold'],
                        (float)$tx['liters_sold'],
                        $remarks,
                        $me['id'],
                        $meta_notes,
                        $me['id']
                    ]);
                } catch (Exception $e) {}

                log_activity($pdo, $me['id'], 'Fuel Reading Marked for Adjustment', "TXN {$tx['transaction_id']} | Reason: {$remarks}");
                $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> marked for adjustment.";
            }
        }


        $pdo->commit();

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => strip_tags($_SESSION['success'] ?? 'Action completed successfully.')]);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }

    $redirect_url = 'manager_fuel_transaction_validation.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirect_url); exit;
}

// ── Helper functions for badges ─────────────────────────────────
function getStatusBadgeClass($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'readings_submitted') return 'bg-gray';       // not yet ready for manager
    if (str_contains($s, 'pending') || in_array($s, ['closing_completed', 'submitted'])) return 'bg-amber';
    if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'bg-green';
    if ($s === 'adjusted') return 'bg-blue';
    if ($s === 'rejected') return 'bg-red';
    return 'bg-gray';
}
function getStatusLabel($status) {
    $s = strtolower(trim($status ?? ''));
    if ($s === 'readings_submitted') return 'Awaiting Fuel Sales Closing';
    if (str_contains($s, 'pending') || in_array($s, ['closing_completed', 'submitted'])) return 'Pending Validation';
    if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'Validated';
    if ($s === 'adjusted') return 'Adjusted';
    if ($s === 'rejected') return 'Rejected';
    return ucfirst($status);
}

// ── Summary Cards Data ─────────────────────────────────────────────────
$pending_count   = 0;
$validated_count = 0;
$rejected_count  = 0;
$total_liters_today = 0.0;
$total_sales_today = 0.0;

try {
    // 1. Pending Transactions (Total overall currently awaiting manager validation)
    // Only CLOSING_COMPLETED = fuel sales closing done, ready for manager
    // READINGS_SUBMITTED = staff not yet done with closing, do NOT show to manager
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND (LOWER(status) LIKE '%pending%' OR LOWER(status) IN ('closing_completed', 'submitted'))");
    $sp->execute([$station_id]);
    $pending_count = (int)$sp->fetchColumn();

    // 2. Validated Transactions (Filtered by date range)
    $sv = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(status) IN ('verified', 'approved', 'adjusted') AND DATE(transaction_date) BETWEEN ? AND ?");
    $sv->execute([$station_id, $date_from, $date_to]);
    $validated_count = (int)$sv->fetchColumn();

    // 3. Rejected Transactions (Filtered by date range)
    $sr = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(status) = 'rejected' AND DATE(transaction_date) BETWEEN ? AND ?");
    $sr->execute([$station_id, $date_from, $date_to]);
    $rejected_count = (int)$sr->fetchColumn();

    // 4. Total Liters Sold Today (Verified/approved today)
    $slt = $pdo->prepare("SELECT COALESCE(SUM(liters_sold), 0) FROM fuel_transactions WHERE station_id = ? AND LOWER(status) IN ('verified', 'approved', 'adjusted') AND DATE(transaction_date) = CURDATE()");
    $slt->execute([$station_id]);
    $total_liters_today = (float)$slt->fetchColumn();

    // 5. Total Sales Amount Today (Verified/approved today)
    $sat = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM fuel_transactions WHERE station_id = ? AND LOWER(status) IN ('verified', 'approved', 'adjusted') AND DATE(transaction_date) = CURDATE()");
    $sat->execute([$station_id]);
    $total_sales_today = (float)$sat->fetchColumn();

} catch (Exception $e) {
    error_log("Summary cards fetch error: " . $e->getMessage());
}

// ── Fetch Dynamic Fuel Types ───────────────────────────────────────────
$fuel_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Fetch Filtered Transactions ───────────────────────────────────────
$where = ["ft.station_id = ?"];
$params = [$station_id];

// Date filter
$where[] = "DATE(ft.transaction_date) BETWEEN ? AND ?";
$params[] = $date_from;
$params[] = $date_to;

// Shift filter
if ($shift_filter !== 'all') {
    $where[] = "(LOWER(ft.shift_period) = ? OR LOWER(ft.shift_name) = ?)";
    $params[] = strtolower($shift_filter);
    $params[] = strtolower($shift_filter);
}

// Fuel Type filter
if ($fuel_type_filter !== '') {
    $where[] = "LOWER(ft.fuel_type) = ?";
    $params[] = strtolower($fuel_type_filter);
}

// Status filter
if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        // Only show CLOSING_COMPLETED (fuel sales closing done) - NOT readings_submitted (closing not yet done)
        $where[] = "(LOWER(ft.status) LIKE '%pending%' OR LOWER(ft.status) IN ('closing_completed', 'submitted'))";
    } elseif ($status_filter === 'validated') {
        $where[] = "LOWER(ft.status) IN ('verified', 'approved', 'adjusted')";
    } elseif ($status_filter === 'rejected') {
        $where[] = "LOWER(ft.status) = 'rejected'";
    }
}

// Search filter
if ($search_query !== '') {
    $where[] = "(LOWER(ft.transaction_id) LIKE ? OR LOWER(ft.fuel_type) LIKE ? OR LOWER(fp.pump_number) LIKE ? OR LOWER(staff.username) LIKE ? OR LOWER(staff.first_name) LIKE ? OR LOWER(staff.last_name) LIKE ? OR LOWER(ft.notes) LIKE ? OR LOWER(ft.reject_reason) LIKE ?)";
    $like_val = '%' . strtolower($search_query) . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val, $like_val]);
}

$transactions = [];
try {
    $sql = "SELECT ft.*, 
                   fp.pump_number,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(validator.first_name, '')), ' ', TRIM(COALESCE(validator.last_name, ''))), ' '),
                       validator.username,
                       '—'
                   ) as validator_name
            FROM fuel_transactions ft
            LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
            LEFT JOIN users staff ON ft.staff_id = staff.id
            LEFT JOIN users validator ON ft.validated_by = validator.id
            WHERE " . implode(" AND ", $where) . "
            ORDER BY
                CASE
                    WHEN TRIM(UPPER(ft.fuel_type)) = 'DIESEL'                                          THEN 1
                    WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 1%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 1%' THEN 2
                    WHEN UPPER(ft.fuel_type) LIKE 'DIESEL 2%' OR UPPER(ft.fuel_type) LIKE '%DIESEL 2%' THEN 3
                    WHEN UPPER(ft.fuel_type) LIKE '%TURBO%DIESEL%'                                      THEN 4
                    WHEN UPPER(ft.fuel_type) LIKE '%KEROSENE%'                                          THEN 5
                    WHEN UPPER(ft.fuel_type) LIKE '%XCS%PLUS%' OR UPPER(ft.fuel_type) LIKE 'XCS PLUS%' THEN 6
                    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%1%'                                       THEN 7
                    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%2%'                                       THEN 8
                    WHEN UPPER(ft.fuel_type) LIKE '%XTRA%UNL%'                                         THEN 9
                    WHEN UPPER(ft.fuel_type) LIKE '%DIESEL%'                                           THEN 10
                    ELSE 99
                END ASC,
                ft.fuel_type ASC,
                fp.pump_number ASC,
                ft.transaction_date ASC,
                ft.created_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute dynamic filtered summaries based on the displayed transactions
    $pending_count = 0;
    $validated_count = 0;
    $rejected_count = 0;
    $total_liters_today = 0.0;
    $total_sales_today = 0.0;

    foreach ($transactions as $tx) {
        $st = strtolower(trim($tx['status'] ?? ''));
        $total_liters_today += (float)($tx['liters_sold'] ?? 0);
        $total_sales_today += (float)($tx['total_amount'] ?? 0);
        if (str_contains($st, 'pending')) {
            $pending_count++;
        } elseif (in_array($st, ['verified', 'approved', 'adjusted', 'validated'])) {
            $validated_count++;
        } elseif ($st === 'rejected') {
            $rejected_count++;
        }
    }
} catch (Exception $e) {
    error_log("Fetch transactions error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading transactions: " . $e->getMessage();
}

// â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Transaction ID', 'Date', 'Shift', 'Fuel Type', 'Beginning Reading', 'Ending Reading', 'Calibration', 'Volume Liters', 'Price/L', 'Amount', 'Staff Encoder', 'Status', 'Validation Date', 'Remarks'];
    $rows_fmt = [];
    foreach ($transactions as $tx) {
        $shift_display = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? '—'));
        $rows_fmt[] = [
            $tx['transaction_id'],
            date('M d, Y H:i', strtotime($tx['transaction_date'])),
            $shift_display,
            $tx['fuel_type'],
            number_format($tx['previous_reading'], 2),
            number_format($tx['present_reading'], 2),
            number_format($tx['calibration'], 2),
            number_format($tx['liters_sold'], 2),
            '₱' . number_format($tx['price_per_liter'], 2),
            '₱' . number_format($tx['total_amount'], 2),
            $tx['staff_name'] ?? '—',
            getStatusLabel($tx['status'] ?? ''),
            $tx['validated_at'] ? date('M d, Y H:i', strtotime($tx['validated_at'])) : '—',
            $tx['reject_reason'] ?? '—'
        ];
    }
    $filename = 'fuel_transaction_validation_' . $date_from . '_to_' . $date_to;

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Fuel Transaction Validation Report</h2><p>Period: ' . $date_from . ' to ' . $date_to . ' | Records: ' . count($rows_fmt) . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>' . htmlspecialchars($c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $logo_url = '../assets/img/Petron%20Logo.png';
        $generated = date('M d, Y H:i');
        
        $tbody = '';
        foreach ($transactions as $tx) {
            $shift_display = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Second Shift' : ($tx['shift_period'] ?? '—'));
            $tbody .= '<tr>';
            $tbody .= '<td>' . htmlspecialchars($tx['transaction_id']) . '</td>';
            $tbody .= '<td>' . date('M d, Y', strtotime($tx['transaction_date'])) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($shift_display) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($tx['fuel_type']) . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($tx['previous_reading'], 2) . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($tx['present_reading'], 2) . '</td>';
            $tbody .= '<td style="text-align:right;">' . number_format($tx['calibration'], 2) . '</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;">' . number_format($tx['liters_sold'], 2) . ' L</td>';
            $tbody .= '<td style="text-align:right;">₱' . number_format($tx['price_per_liter'], 2) . '</td>';
            $tbody .= '<td style="text-align:right;font-weight:700;color:#002F70;">₱' . number_format($tx['total_amount'], 2) . '</td>';
            $tbody .= '<td>' . htmlspecialchars($tx['staff_name'] ?? '—') . '</td>';
            $tbody .= '<td>' . getStatusLabel($tx['status'] ?? '') . '</td>';
            $tbody .= '<td>' . ($tx['validated_at'] ? date('M d, Y', strtotime($tx['validated_at'])) : '—') . '</td>';
            $tbody .= '<td>' . htmlspecialchars($tx['reject_reason'] ?? '—') . '</td>';
            $tbody .= '</tr>';
        }

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Transaction Validation Report</title>';
        echo '<style>';
        echo '@page{size:A4 landscape;margin:0.4in 0.3in;}';
        echo 'body{font-family:Arial,sans-serif;font-size:9px;margin:0;padding:0;background:#fff;color:#1e293b;}';
        echo '.report{background:#fff;max-width:100%;margin:0;}';
        echo '.hdr{background:linear-gradient(135deg,#002F70 0%,#003d8a 100%);color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}';
        echo '.hdr img{height:38px;width:auto;margin-right:14px;}';
        echo 'h1{color:#fff;font-size:15px;margin:0 0 2px;text-transform:uppercase;font-weight:800;}';
        echo '.hdr p{margin:2px 0 0;color:#93c5fd;font-size:9px;}';
        echo 'table{width:100%;border-collapse:collapse;margin:12px 0;font-size:8px;table-layout:fixed;}';
        echo 'th{background:#002F70;color:#fff;padding:5px 3px;font-size:7.5px;text-transform:uppercase;text-align:left;letter-spacing:.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
        echo 'td{padding:4px 3px;border-bottom:1px solid #e2e8f0;font-size:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
        echo 'tr:nth-child(even) td{background:#f8fafc;}';
        echo '@media print{';
        echo 'body{background:#fff;margin:0;}';
        echo '@page{size:A4 landscape;margin:0.3in 0.25in;}';
        echo 'a[href]:after{content:none !important;display:none !important;}';
        echo 'a{text-decoration:none !important;color:inherit !important;}';
        echo '}';
        echo '</style>';
        echo '<script>';
        echo 'window.onload=function(){window.print();setTimeout(function(){window.close();},100);};';
        echo 'window.onafterprint=function(){window.close();};';
        echo '</script>';
        echo '</head><body>';
        echo '<div class="report">';
        echo '<div class="hdr">';
        echo '<div><h1>Petron Fuel Transaction Validation</h1>';
        echo '<p>Period: ' . htmlspecialchars($date_from) . ' — ' . htmlspecialchars($date_to) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div>';
        echo '<div style="text-align:right;"><p style="margin:0;color:#bfdbfe;">Generated: ' . $generated . '</p></div>';
        echo '</div>';
        echo '<table><thead><tr><th>Txn ID</th><th>Date</th><th>Shift</th><th>Fuel Type</th><th>Beginning</th><th>Ending</th><th>Calib</th><th>Liters</th><th>Price/L</th><th>Amount</th><th>Staff</th><th>Status</th><th>Val Date</th><th>Remarks</th></tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="14" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</div></body></html>'; 
        exit;
    }
}

// ── AJAX JSON POLLING ENDPOINT FOR FUEL TRANSACTION VALIDATION ─────────────────
if (isset($_GET['ajax_ftv']) && $_GET['ajax_ftv'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'pending'   => number_format($pending_count),
            'validated' => number_format($validated_count),
            'rejected'  => number_format($rejected_count),
            'liters'    => number_format((float)$total_liters_today, 2) . ' L',
            'sales'     => '₱' . number_format((float)$total_sales_today, 2)
        ],
        'transactions_count' => count($transactions)
    ]);
    exit;
}

// ── AJAX JSON POLLING ENDPOINT FOR FUEL TRANSACTION VALIDATION ─────────────────
if (isset($_GET['ajax_ftv']) && $_GET['ajax_ftv'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'pending'   => number_format($pending_count),
            'validated' => number_format($validated_count),
            'rejected'  => number_format($rejected_count),
            'liters'    => number_format((float)$total_liters_today, 2) . ' L',
            'sales'     => '₱' . number_format((float)$total_sales_today, 2)
        ],
        'transactions_count' => count($transactions)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* Reset and core alignment */
* { box-sizing: border-box; }
html, body { max-width: 100vw !important; width: 100%; overflow-x: hidden !important; position: relative; }
.mftv-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; }

/* Petron clean headers */
.int-head { display: flex !important; align-items: center !important; justify-content: space-between !important; flex-wrap: wrap !important; gap: 15px !important; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100% !important; }
.int-head > div:first-child { flex: 1; min-width: 280px; max-width: 65%; }
.int-head > div:last-child { flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.int-head h1 { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; font-size: 24px !important; font-weight: 700 !important; color: #002f70 !important; margin: 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Outline buttons */
.ato-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s;
    height: 36px; white-space: nowrap; background: white !important;
}
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print  { color: #334155 !important; border-color: #64748b !important; }
.ato-btn-print:hover  { background: #64748b !important; color: #fff !important; }
.ato-btn-back   { color: #4b5563 !important; border-color: #6b7280 !important; }
.ato-btn-back:hover   { background: #6b7280 !important; color: #fff !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset  { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover  { background: #f1f5f9 !important; }

/* Batch Buttons Styled Properly */
.ato-btn-batch-approve { color: #16a34a !important; border-color: #16a34a !important; background: white !important; }
.ato-btn-batch-approve:hover:not(:disabled) { background: #16a34a !important; color: #fff !important; }
.ato-btn-batch-reject { color: #dc2626 !important; border-color: #dc2626 !important; background: white !important; }
.ato-btn-batch-reject:hover:not(:disabled) { background: #dc2626 !important; color: #fff !important; }
.ato-btn-batch-adjust { color: #0ea5e9 !important; border-color: #0ea5e9 !important; background: white !important; }
.ato-btn-batch-adjust:hover:not(:disabled) { background: #0ea5e9 !important; color: #fff !important; }

/* Fuel Closing Modal Custom Inputs */
.fsc-calc-input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #CBD5E1;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    color: #1E293B;
    background: #FFFFFF;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.fsc-calc-input:focus {
    border-color: #002F6C;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.12);
    background: #FFF;
}


/* Summary Cards matching Adjustments Oversight standard */
.afto-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
.afto-card { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 16px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,.05); position: relative; overflow: hidden; }
.afto-card-info { display: flex; flex-direction: column; }
.afto-card-lbl { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.afto-card-val { font-size: 20px; font-weight: 700; color: #1e293b; }
.afto-card-icon { font-size: 24px; opacity: 0.8; }
.afto-card.blue .afto-card-icon { color: #2563eb; }
.afto-card.yellow .afto-card-icon { color: #d97706; }
.afto-card.green .afto-card-icon { color: #16a34a; }
.afto-card.red .afto-card-icon { color: #dc2626; }
.afto-card.purple .afto-card-icon { color: #8b5cf6; }

/* Filter Bar */
.afto-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
.afto-fg { display: flex; flex-direction: column; gap: 3px; }
.afto-fg label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.afto-fg input, .afto-fg select { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; color: #1e293b; background: #fff; outline: none; box-sizing: border-box; }
.afto-fg input:focus, .afto-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Table Container & Layout */
.afto-table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100%; max-width: 100%; }
.afto-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap; gap: 8px; }
.afto-table-title { font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: .3px; margin: 0; }
.afto-tbl-wrap { width: 100%; max-width: 100%; overflow-x: hidden !important; }
.afto-tbl { width: 100%; max-width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
.afto-tbl thead tr { background: #002F70; }
.afto-tbl thead th { padding: 10px 8px; text-align: left; font-size: 12px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .3px; border-bottom: 2px solid #001a3d; vertical-align: middle; white-space: nowrap; }
.afto-tbl tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.afto-tbl tbody tr:hover td { background: #eff6ff; }
.afto-tbl tbody td { padding: 10px 8px; color: #334155; vertical-align: middle; background: #fff; font-size: 13px; font-weight: 600; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; overflow: visible; text-overflow: clip; }

/* Status Badges */
.afto-badge { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; white-space: nowrap; text-transform: uppercase; }
.bg-amber  { background: #fffbeb; color: #b45309; border: 1px solid #fef3c7; }
.bg-green  { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
.bg-red    { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }
.bg-blue   { background: #eff6ff; color: #1e40af; border: 1px solid #dbeafe; }
.bg-gray   { background: #f8fafc; color: #475569; border: 1px solid #e2e8f0; }

/* Action buttons */
.row-btn {
    padding: 0 8px; border-radius: 5px; font-size: 9px; font-weight: 700; border: 1px solid transparent; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: 3px; transition: all .15s; text-transform: uppercase;
    height: 24px; background: white !important; text-decoration: none;
}
.row-btn-info    { color: #0284c7 !important; border-color: #0284c7 !important; }
.row-btn-info:hover { background: #0284c7 !important; color: #fff !important; }
.row-btn-success { color: #16a34a !important; border-color: #16a34a !important; }
.row-btn-success:hover { background: #16a34a !important; color: #fff !important; }
.row-btn-danger  { color: #dc2626 !important; border-color: #dc2626 !important; }
.row-btn-danger:hover { background: #dc2626 !important; color: #fff !important; }
.row-btn-info:hover    { background: #0284c7 !important; color: #fff !important; }
.row-btn-success { color: #16a34a !important; border-color: #16a34a !important; }
.row-btn-success:hover { background: #16a34a !important; color: #fff !important; }
.row-btn-danger  { color: #dc2626 !important; border-color: #dc2626 !important; }
.row-btn-danger:hover  { background: #dc2626 !important; color: #fff !important; }
.row-btn-print   { color: #4b5563 !important; border-color: #4b5563 !important; }
.row-btn-print:hover   { background: #4b5563 !important; color: #fff !important; }

/* Batch action buttons disabled state */
.ato-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

/* Checkbox styling */
input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

input[type="checkbox"]:indeterminate {
    opacity: 0.7;
}

/* Empty state */
.afto-empty { text-align: center; padding: 60px 20px; color: #94a3b8; }
.afto-empty i { font-size: 44px; display: block; margin-bottom: 14px; opacity: .4; }

/* Modal styles */
.modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.5); overflow-y: auto; }
.modal-content { background: #fff; margin: 10% auto; padding: 24px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
.modal-header h3 { margin: 0; font-size: 15px; color: #00264D; font-weight: 700; text-transform: uppercase; }
.modal-close { cursor: pointer; font-size: 20px; color: #94a3b8; font-weight: bold; }
.modal-close:hover { color: #dc2626; }
.modal-body { margin-bottom: 18px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; }

/* Form field inside modal */
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; }
.form-group textarea, .form-group input { width: 100%; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; outline: none; background: #fff; }
.form-group textarea:focus, .form-group input:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Details list inside view modal */
.details-list { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
.details-label { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; }
.details-value { font-size: 12px; color: #1e293b; font-weight: 600; margin-top: 2px; }

/* Clean White Footer Pagination Bar */
.afto-footer {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 14px 20px !important;
    background: #ffffff !important;
    border-top: 1px solid #e2e8f0 !important;
    border-bottom-left-radius: 12px !important;
    border-bottom-right-radius: 12px !important;
    font-size: 13px !important;
    color: #334155 !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
    margin-bottom: 30px !important;
}

.afto-footer select#rowsPerPage {
    padding: 6px 12px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #0f172a !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    outline: none !important;
}

.afto-footer #pageInfo {
    font-weight: 600 !important;
    color: #334155 !important;
}

.page-btn {
    padding: 6px 14px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px !important;
    background: #ffffff !important;
    color: #334155 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
}

.page-btn:hover:not(:disabled) {
    background: #f1f5f9 !important;
    border-color: #94a3b8 !important;
    color: #0f172a !important;
}

.page-btn:disabled {
    opacity: 0.4 !important;
    cursor: not-allowed !important;
    background: #f8fafc !important;
}
</style>

<div class="mftv-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-check-double"></i> Fuel Transaction Validation</h1>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="afto-cards">
        <div class="afto-card blue">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Pending Transactions</span>
                <span class="afto-card-val"><?= number_format($pending_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-clock"></i></div>
        </div>
        <div class="afto-card green">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Validated</span>
                <span class="afto-card-val"><?= number_format($validated_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-check-circle"></i></div>
        </div>
        <div class="afto-card red">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Rejected</span>
                <span class="afto-card-val"><?= number_format($rejected_count) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-times-circle"></i></div>
        </div>
        <div class="afto-card yellow">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Total Liters Sold</span>
                <span class="afto-card-val"><?= number_format($total_liters_today, 2) ?> L</span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-tint"></i></div>
        </div>
        <div class="afto-card purple">
            <div class="afto-card-info">
                <span class="afto-card-lbl">Total Fuel Sales</span>
                <span class="afto-card-val">₱<?= number_format($total_sales_today, 2) ?></span>
            </div>
            <div class="afto-card-icon"><i class="fas fa-peso-sign"></i></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="get" class="afto-filter">
        <div class="afto-fg">
            <label>Date From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
        </div>
        <div class="afto-fg">
            <label>Date To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
        </div>
        <div class="afto-fg">
            <label>Shift</label>
            <select name="shift_filter">
                <option value="all" <?= $shift_filter === 'all' ? 'selected' : '' ?>>All Shifts</option>
                <option value="first" <?= $shift_filter === 'first' ? 'selected' : '' ?>>First Shift</option>
                <option value="second" <?= $shift_filter === 'second' ? 'selected' : '' ?>>Second Shift</option>
            </select>
        </div>
        <div class="afto-fg">
            <label>Fuel Type</label>
            <select name="fuel_type">
                <option value="">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= $fuel_type_filter === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="afto-fg">
            <label>Status</label>
            <select name="status_filter">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Awaiting Validation</option>
                <option value="validated" <?= $status_filter === 'validated' ? 'selected' : '' ?>>Validated</option>
                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
        </div>
        <div class="afto-fg">
            <label>Search</label>
            <input type="text" name="search_query" value="<?= htmlspecialchars($search_query) ?>" placeholder="Search transactions...">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
            <a href="manager_fuel_transaction_validation.php" class="ato-btn ato-btn-reset"><i class="fas fa-times"></i> Reset</a>
        </div>
    </form>

    <!-- Table Card -->
    <div class="afto-table-card">
        <div class="afto-table-hd">
            <h3 class="afto-table-title"><i class="fas fa-list"></i> Fuel Transactions Log</h3>
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                <button type="button" id="btnApproveSelected" class="ato-btn ato-btn-batch-approve" style="font-size: 11px; padding: 7px 14px;" onclick="batchValidate()" disabled>
                    <i class="fas fa-check"></i> Approve
                </button>
                <button type="button" id="btnRejectSelected" class="ato-btn ato-btn-batch-reject" style="font-size: 11px; padding: 7px 14px;" onclick="openBatchReject()" disabled>
                    <i class="fas fa-times"></i> Reject
                </button>
                <button type="button" id="btnAdjustSelected" class="ato-btn ato-btn-batch-adjust" style="font-size: 11px; padding: 7px 14px;" onclick="openBatchAdjust()" disabled>
                    <i class="fas fa-edit"></i> Adjust
                </button>
                <span id="selectedCount" style="display:none;">0</span>
            </div>
        </div>

        <div class="afto-tbl-wrap">
            <table class="afto-tbl">
                    <thead>
                        <tr>
                            <th style="width: 3%;">
                                <input type="checkbox" id="selectAllCheck" style="cursor: pointer;" onchange="toggleSelectAll(this)">
                            </th>
                            <th style="width: 8%;">Transaction ID</th>
                            <th style="width: 7%;">Date</th>
                            <th style="width: 5%;">Shift</th>
                            <th style="width: 12%;">Fuel Type</th>
                            <th style="text-align: right; width: 7%;">Begin</th>
                            <th style="text-align: right; width: 7%;">Ending</th>
                            <th style="text-align: right; width: 5%;">Cal</th>
                            <th style="text-align: right; width: 7%;">Liters</th>
                            <th style="text-align: right; width: 8%;">Amount</th>
                            <th style="width: 7%;">Staff</th>
                            <th style="width: 7%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="12">
                                    <div class="afto-empty">
                                        <i class="fas fa-inbox"></i>
                                        <div style="font-size: 15px; font-weight: 700; color: #64748b; margin-bottom: 4px;">No records found</div>
                                        <div style="font-size: 13px;">No transactions match the selected filters.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php
                            // Helper: map fuel type to its parent group for shared sequential numbering
                            function mgr_fuel_group(string $ft): string {
                                $f = strtoupper(trim($ft));
                                if (str_contains($f,'TURBO') && str_contains($f,'DIESEL')) return 'TURBO DIESEL';
                                if (str_contains($f,'DIESEL'))   return 'DIESEL';
                                if (str_contains($f,'KEROSENE')) return 'KEROSENE';
                                if (str_contains($f,'XCS') && str_contains($f,'PLUS')) return 'XCS PLUS';
                                if (str_contains($f,'XTRA') && str_contains($f,'UNL')) return 'XTRA UNL';
                                return $f;
                            }
                            // Helper: get the formatted fuel name incorporating pump groupings
                            function get_mgr_formatted_fuel_name(string $fuel_type, int $seq): string {
                                $f = strtoupper(trim($fuel_type));
                                if (str_contains($f,'TURBO') && str_contains($f,'DIESEL')) {
                                    return "TURBO DIESEL - {$seq}";
                                }
                                if (str_contains($f,'DIESEL')) {
                                    if ($seq <= 4) {
                                        return "DIESEL 1 - {$seq}";
                                    } else {
                                        return "DIESEL 2 - {$seq}";
                                    }
                                }
                                if (str_contains($f,'KEROSENE')) {
                                    return "KEROSENE - {$seq}";
                                }
                                if (str_contains($f,'XCS') && str_contains($f,'PLUS')) {
                                    return "XCS PLUS - {$seq}";
                                }
                                if (str_contains($f,'XTRA') && str_contains($f,'UNL')) {
                                    if ($seq <= 2) {
                                        return "XTRA UNL 1 - {$seq}";
                                    } else {
                                        return "XTRA UNL 2 - {$seq}";
                                    }
                                }
                                return "{$f} - {$seq}";
                            }
                            // Pre-compute group-level sequential labels
                            $grp_counters = [];
                            foreach ($transactions as &$_tx) {
                                $grp    = mgr_fuel_group($_tx['fuel_type'] ?? '');
                                if (!isset($grp_counters[$grp])) $grp_counters[$grp] = 0;
                                $grp_counters[$grp]++;
                                $_tx['_seq_label'] = get_mgr_formatted_fuel_name($_tx['fuel_type'] ?? '', $grp_counters[$grp]);
                            }
                            unset($_tx);
                            ?>
                            <?php foreach ($transactions as $tx): 
                            $shift_display = !empty($tx['shift_name']) ? $tx['shift_name'] : (strtolower($tx['shift_period'] ?? '') === 'second' ? 'Shift 2' : 'Shift 1');
                        ?>
                            <tr id="tx_row_<?= $tx['id'] ?>" data-tx-id="<?= $tx['id'] ?>" data-tx-json='<?= htmlspecialchars(json_encode($tx), ENT_QUOTES, 'UTF-8') ?>'>
                                <td>
                                    <?php if (is_pending_validation_status($tx['status'] ?? '')): ?>
                                        <input type="checkbox" class="tx-checkbox" data-tx-id="<?= $tx['id'] ?>" style="cursor: pointer;" onchange="updateBatchButtons()">
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 700; color: #00264D; font-size: 13px;"><?= htmlspecialchars($tx['transaction_id']) ?></td>
                                <td style="font-size: 13px;"><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></td>
                                <td style="font-size: 13px;"><?= htmlspecialchars($shift_display) ?></td>
                                <td style="font-size: 13px; font-weight:700; color:#0f172a; white-space:normal; word-break:break-word;" title="<?= htmlspecialchars($tx['_seq_label']) ?>"><?= htmlspecialchars($tx['_seq_label']) ?></td>
                                <td style="text-align: right; font-size: 13px;"><?= number_format($tx['previous_reading'], 2) ?></td>
                                <td style="text-align: right; font-weight: 700; font-size: 13px;"><?= number_format($tx['present_reading'], 2) ?></td>
                                <td style="text-align: right; font-size: 13px;"><?= number_format($tx['calibration'], 2) ?></td>
                                <td style="text-align: right; font-weight: 700; color: #1e293b; font-size: 13px;"><?= number_format($tx['liters_sold'], 2) ?> L</td>
                                <td style="text-align: right; font-weight: 800; color: #002F70; font-size: 13px;">₱<?= number_format($tx['total_amount'], 2) ?></td>
                                <td style="font-size: 13px;"><?= htmlspecialchars($tx['staff_name'] ?? '—') ?></td>
                                <td><span class="afto-badge <?= getStatusBadgeClass($tx['status'] ?? '') ?>"><?= getStatusLabel($tx['status'] ?? '') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            
    </div>
</div>


<!-- View Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content" style="max-width: 550px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-eye" style="color:#0284c7;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Transaction Details</h3>
            </div>
        </div>
        <div class="modal-body">
            <div class="details-list">
                <div class="details-item">
                    <div class="details-label">Transaction ID</div>
                    <div class="details-value" id="det_id">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Date/Time</div>
                    <div class="details-value" id="det_date">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Fuel Type</div>
                    <div class="details-value" id="det_fuel">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Shift</div>
                    <div class="details-value" id="det_shift">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Price per Liter</div>
                    <div class="details-value" id="det_price">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Beginning Reading</div>
                    <div class="details-value" id="det_beg">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Ending Reading</div>
                    <div class="details-value" id="det_end">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Calibration</div>
                    <div class="details-value" id="det_cal">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Volume Sold</div>
                    <div class="details-value" id="det_vol" style="color:#00264D;">—</div>
                </div>
                <div class="details-item" style="grid-column: span 2;">
                    <div class="details-label">Total Amount</div>
                    <div class="details-value" id="det_amt" style="font-size:16px; color:#16a34a;">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Staff Encoder</div>
                    <div class="details-value" id="det_staff">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Status</div>
                    <div class="details-value" id="det_status">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Validated By</div>
                    <div class="details-value" id="det_validator">—</div>
                </div>
                <div class="details-item">
                    <div class="details-label">Validation Date</div>
                    <div class="details-value" id="det_val_date">—</div>
                </div>
                <div class="details-item" style="grid-column: span 2;">
                    <div class="details-label">Remarks / Audit Note</div>
                    <div class="details-value" id="det_remarks" style="white-space: pre-wrap; font-weight: normal; color: #475569;">—</div>
                </div>
                <div class="details-item" style="grid-column: span 2;">
                    <div class="details-label">Staff Encoding Notes</div>
                    <div class="details-value" id="det_staff_notes" style="white-space: pre-wrap; font-weight: normal; color: #475569;">—</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<!-- Validate Modal -->
<div id="validateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#f0fdf4;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-check" style="color:#16a34a;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Validate Transaction</h3>
            </div>
        </div>
        <form method="post" id="validateForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="validate">
                <input type="hidden" name="id" id="val_id_field">
                <p id="val_prompt" style="font-size: 13px; color: #475569; margin: 0 0 14px; font-weight: 500;"></p>
                <div class="form-group">
                    <label>Validation Remarks <span style="font-weight: normal; color: #94a3b8;">(Optional)</span></label>
                    <textarea name="remarks" rows="3" placeholder="Enter optional notes about this validation..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('validateModal')">Cancel</button>
                <button type="submit" class="ato-btn ato-btn-filter" style="background:#16a34a !important; color:#fff !important; border-color:#16a34a !important;"><i class="fas fa-check"></i> Approve & Validate</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-times" style="color:#dc2626;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Reject Transaction</h3>
            </div>
        </div>
        <form method="post" id="rejectForm">
            <div class="modal-body">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rej_id_field">
                <p id="rej_prompt" style="font-size: 13px; color: #475569; margin: 0 0 14px; font-weight: 500;"></p>
                <div class="form-group">
                    <label>Rejection Reason <span style="color:#dc2626;">*</span></label>
                    <textarea name="remarks" rows="3" required placeholder="Describe why this transaction is being rejected and returned to staff..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="ato-btn" style="background:#dc2626 !important; color:#fff !important; border-color:#dc2626 !important;"><i class="fas fa-times"></i> Reject Transaction</button>
            </div>
        </form>
    </div>
</div>

<!-- Fuel Sales Closing Review & Approval Modal -->
<div id="fuelClosingApprovalModal" class="modal" style="display:none; align-items:center; justify-content:center;">
    <div class="modal-content" style="max-width: 880px; width: 95%; max-height: 90vh; display: flex; flex-direction: column; padding: 0; border-radius: 14px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Header -->
        <div class="modal-header" style="background: linear-gradient(135deg, #002F6C 0%, #001A3D 100%); color: #fff; padding: 18px 24px; margin-bottom: 0; border-bottom: none;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.15); border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="fas fa-file-invoice-dollar" style="color: #FBBF24; font-size: 20px;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; color: #fff; font-size: 17px; font-weight: 800; letter-spacing: 0.3px;">Fuel Sales Closing Review &amp; Approval</h3>
                    <div style="font-size: 12px; color: #93C5FD; margin-top: 2px;">
                        <span id="fsc_badge_station">Petron Carmen Station</span> • 
                        <span id="fsc_badge_date" style="font-weight: 700; color: #fff;"></span> • 
                        <span id="fsc_badge_shift" class="afto-badge bg-amber" style="font-size: 10px; padding: 2px 8px;"></span>
                    </div>
                </div>
            </div>
            <span class="modal-close" style="color: #fff; opacity: 0.8; font-size: 24px;" onclick="closeModal('fuelClosingApprovalModal')">&times;</span>
        </div>

        <!-- Scrollable Modal Body -->
        <div class="modal-body" style="padding: 20px 24px; overflow-y: auto; flex: 1; background: #F8FAFC; margin-bottom: 0;">
            <!-- Loading Indicator -->
            <div id="fsc_loading" style="display: none; text-align: center; padding: 40px 20px; color: #64748B;">
                <i class="fas fa-circle-notch fa-spin" style="font-size: 32px; color: #002F6C; margin-bottom: 12px;"></i>
                <div style="font-weight: 600; font-size: 14px;">Loading Fuel Sales Closing data...</div>
            </div>

            <div id="fsc_content_wrap">
                <!-- Section 1: Selected Meter Readings & Volume Breakdown -->
                <div style="background: #fff; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                        <div style="font-size: 13px; font-weight: 800; color: #002F6C; text-transform: uppercase;">
                            <i class="fas fa-gas-pump" style="color: #DC2626; margin-right: 6px;"></i> Pump Meter Readings Summary
                        </div>
                        <div style="font-size: 11px; color: #64748B;">
                            Selected Transactions: <strong id="fsc_tx_count" style="color: #0F172A;">0</strong>
                        </div>
                    </div>
                    
                    <div style="max-height: 180px; overflow-y: auto; border: 1px solid #E2E8F0; border-radius: 6px; margin-bottom: 12px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                            <thead style="background: #002F6C; color: #fff; position: sticky; top: 0;">
                                <tr>
                                    <th style="padding: 6px 8px; text-align: left;">Pump</th>
                                    <th style="padding: 6px 8px; text-align: left;">Fuel Type</th>
                                    <th style="padding: 6px 8px; text-align: right;">Begin</th>
                                    <th style="padding: 6px 8px; text-align: right;">Ending</th>
                                    <th style="padding: 6px 8px; text-align: right;">Cal</th>
                                    <th style="padding: 6px 8px; text-align: right;">Net Liters</th>
                                    <th style="padding: 6px 8px; text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody id="fsc_readings_tbody">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>

                    <!-- Computed KPI Cards -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-size: 10px; font-weight: 700; color: #1E40AF; text-transform: uppercase;">Total Fuel Liters Sold</div>
                                <div id="fsc_disp_liters" style="font-size: 18px; font-weight: 800; color: #1E3A8A; margin-top: 2px;">0.00 L</div>
                            </div>
                            <i class="fas fa-tint" style="font-size: 24px; color: #3B82F6; opacity: 0.6;"></i>
                        </div>
                        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <div style="font-size: 10px; font-weight: 700; color: #166534; text-transform: uppercase;">Total Fuel Sales Amount</div>
                                <div id="fsc_disp_sales" style="font-size: 18px; font-weight: 800; color: #14532D; margin-top: 2px;">₱0.00</div>
                            </div>
                            <i class="fas fa-peso-sign" style="font-size: 24px; color: #22C55E; opacity: 0.6;"></i>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Editable Closing Breakdown (Cash & AR) -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <!-- Cash Breakdown Card -->
                    <div style="background: #fff; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <div style="font-size: 13px; font-weight: 800; color: #15803D; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                            <i class="fas fa-money-bill-wave" style="margin-right: 6px;"></i> Cash Breakdown (Editable)
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Shift 1 Cash (₱)</label>
                            <input type="text" id="fsc_cash_shift1" class="fsc-calc-input" oninput="formatAutoCommaDot(this); fscRecalcTotals();" onblur="formatAutoCommaDotOnBlur(this); fscRecalcTotals();" placeholder="0.00">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Shift 2 Cash (₱)</label>
                            <input type="text" id="fsc_cash_shift2" class="fsc-calc-input" oninput="formatAutoCommaDot(this); fscRecalcTotals();" onblur="formatAutoCommaDotOnBlur(this); fscRecalcTotals();" placeholder="0.00">
                        </div>
                        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                            <span style="font-size: 11px; font-weight: 700; color: #166534;">Total Cash Sales:</span>
                            <span id="fsc_total_cash_val" style="font-size: 15px; font-weight: 800; color: #15803D;">₱0.00</span>
                        </div>
                    </div>

                    <!-- AR Breakdown Card -->
                    <div style="background: #fff; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <div style="font-size: 13px; font-weight: 800; color: #D97706; text-transform: uppercase; margin-bottom: 12px; border-bottom: 1px solid #F1F5F9; padding-bottom: 8px;">
                            <i class="fas fa-file-invoice" style="margin-right: 6px;"></i> Accounts Receivable / Credit (Editable)
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Shift 1 AR (₱)</label>
                            <input type="text" id="fsc_ar_shift1" class="fsc-calc-input" oninput="formatAutoCommaDot(this); fscRecalcTotals();" onblur="formatAutoCommaDotOnBlur(this); fscRecalcTotals();" placeholder="0.00">
                        </div>
                        <div class="form-group" style="margin-bottom: 10px;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Shift 2 AR (₱)</label>
                            <input type="text" id="fsc_ar_shift2" class="fsc-calc-input" oninput="formatAutoCommaDot(this); fscRecalcTotals();" onblur="formatAutoCommaDotOnBlur(this); fscRecalcTotals();" placeholder="0.00">
                        </div>
                        <div style="background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 6px; padding: 8px 12px; display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                            <span style="font-size: 11px; font-weight: 700; color: #B45309;">Total AR Sales:</span>
                            <span id="fsc_total_ar_val" style="font-size: 15px; font-weight: 800; color: #B45309;">₱0.00</span>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Net Sales & Bank Summary + Manager Sign-Off -->
                <div style="background: #fff; border: 1px solid #E2E8F0; border-radius: 10px; padding: 16px; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 14px;">
                        <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; padding: 10px 14px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <span style="font-size: 11px; font-weight: 700; color: #1E40AF; text-transform: uppercase;">Net Sales (Total Cash + AR):</span>
                                <div id="fsc_net_sales_val" style="font-size: 16px; font-weight: 800; color: #1E3A8A; margin-top: 2px;">₱0.00</div>
                            </div>
                            <i class="fas fa-calculator" style="font-size: 22px; color: #3B82F6; opacity: 0.5;"></i>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Total Cash to Bank / Deposit (₱)</label>
                            <input type="text" id="fsc_total_cash_bank" class="fsc-calc-input" oninput="formatAutoCommaDot(this);" onblur="formatAutoCommaDotOnBlur(this);" placeholder="0.00" style="font-weight: 700; color: #002F6C; background: #F8FAFC;">
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Checked By (Staff Encoder)</label>
                            <input type="text" id="fsc_checked_by" placeholder="Staff Name" style="font-size: 13px;">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 700; color: #475569;">Verified By (Manager)</label>
                            <input type="text" id="fsc_verified_by" placeholder="Manager Name" style="font-size: 13px; font-weight: 700; color: #002F6C;">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label style="font-size: 11px; font-weight: 700; color: #475569;">Manager Validation Remarks / Notes <span style="color:#94A3B8; font-weight: normal;">(Optional)</span></label>
                        <textarea id="fsc_remarks" rows="2" placeholder="Enter validation remarks or approval notes (e.g. 'Readings reconciled, cash verified with deposit slip')..." style="font-size: 12px;"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer" style="padding: 14px 24px; background: #F1F5F9; border-top: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center;">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('fuelClosingApprovalModal')">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" id="btnConfirmFscApprove" class="ato-btn" style="background: #16A34A !important; color: #fff !important; border-color: #16A34A !important; padding: 10px 24px !important; font-size: 13px !important; font-weight: 700 !important; border-radius: 8px !important; box-shadow: 0 4px 12px rgba(22,163,74,0.3);" onclick="saveClosingAndValidate()">
                <i class="fas fa-check-circle" style="margin-right: 6px;"></i> Save Closing &amp; Approve Transactions
            </button>
        </div>
    </div>
</div>

<!-- Review Selected Modal (Batch Summary) -->
<div id="reviewModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-eye" style="color:#0ea5e9;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Review Selected Readings</h3>
            </div>
            <span class="modal-close" onclick="closeModal('reviewModal')">&times;</span>
        </div>
        <div class="modal-body">
            <!-- Summary Cards -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Total Pumps</div>
                    <div id="revTotalPumps" style="font-size: 20px; font-weight: 700; color: #1e293b;">0</div>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Total Liters</div>
                    <div id="revTotalLiters" style="font-size: 20px; font-weight: 700; color: #1e293b;">0 L</div>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Total Sales</div>
                    <div id="revTotalSales" style="font-size: 20px; font-weight: 700; color: #16a34a;">₱0.00</div>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;">Total Cal</div>
                    <div id="revTotalCal" style="font-size: 20px; font-weight: 700; color: #1e293b;">0 L</div>
                </div>
            </div>

            <!-- Reading List -->
            <div style="max-height: 400px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px;">
                <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                    <thead style="background: #f8fafc; position: sticky; top: 0;">
                        <tr>
                            <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 10px; color: #64748b;">Pump</th>
                            <th style="padding: 8px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 10px; color: #64748b;">Fuel Type</th>
                            <th style="padding: 8px; text-align: right; border-bottom: 1px solid #e2e8f0; font-size: 10px; color: #64748b;">Liters</th>
                            <th style="padding: 8px; text-align: right; border-bottom: 1px solid #e2e8f0; font-size: 10px; color: #64748b;">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="revReadingsList">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('reviewModal')">Cancel</button>
            <button type="button" class="ato-btn" style="background: #dc2626; color: white;" onclick="closeReviewAndReject()">
                <i class="fas fa-times"></i> Reject
            </button>
            <button type="button" class="ato-btn ato-btn-filter" style="background: #16a34a; color: white;" onclick="closeReviewAndValidate()">
                <i class="fas fa-check"></i> Validate
            </button>
        </div>
    </div>
</div>

<!-- Batch Reject Modal -->
<div id="batchRejectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#fef2f2;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-times" style="color:#dc2626;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Reject Selected Transactions</h3>
            </div>
            <span class="modal-close" onclick="closeModal('batchRejectModal')">&times;</span>
        </div>
        <div class="modal-body">
            <p id="batchRejPrompt" style="font-size: 13px; color: #475569; margin: 0 0 14px; font-weight: 500;"></p>
            <div class="form-group">
                <label>Rejection Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="batchRejectReason" rows="3" required placeholder="Enter reason for rejecting these transactions..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('batchRejectModal')">Cancel</button>
            <button type="button" class="ato-btn" style="background:#dc2626; color:#fff;" onclick="confirmBatchReject()">
                <i class="fas fa-times"></i> Reject Selected
            </button>
        </div>
    </div>
</div>

<!-- Batch Adjust Modal -->
<div id="batchAdjustModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:36px;height:36px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-edit" style="color:#0ea5e9;font-size:15px;"></i>
                </div>
                <h3 style="margin:0;">Adjust Selected Transactions</h3>
            </div>
        </div>
        <div class="modal-body">
            <p id="batchAdjustPrompt" style="font-size: 13px; color: #475569; margin: 0 0 14px; font-weight: 500;"></p>
            
            <!-- Edit Fields for Single Transaction Adjustment -->
            <div id="singleAdjustFields" style="border-top: 1px solid #f1f5f9; padding-top: 14px; margin-top: 14px; margin-bottom: 14px; display: none;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Beginning Reading</label>
                        <input type="text" inputmode="decimal" autocomplete="off" id="adj_beginning" name="beginning" oninput="formatAutoCommaDot(this); calcAdjTotals();" onblur="formatAutoCommaDotOnBlur(this); calcAdjTotals();">
                    </div>
                    <div class="form-group">
                        <label>Ending Reading</label>
                        <input type="text" inputmode="decimal" autocomplete="off" id="adj_ending" name="ending" oninput="formatAutoCommaDot(this); calcAdjTotals();" onblur="formatAutoCommaDotOnBlur(this); calcAdjTotals();">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Calibration</label>
                        <input type="text" inputmode="decimal" autocomplete="off" id="adj_calibration" name="calibration" oninput="formatAutoCommaDot(this); calcAdjTotals();" onblur="formatAutoCommaDotOnBlur(this); calcAdjTotals();">
                    </div>
                    <div class="form-group">
                        <label>Price per Liter</label>
                        <input type="text" id="adj_price" readonly style="background:#f8fafc; color:#64748b; cursor:not-allowed;">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: #eff6ff; padding: 10px; border-radius: 8px; margin-top: 10px;">
                    <div>
                        <div style="font-size: 10px; color: #1e40af; font-weight: bold; text-transform: uppercase;">Adj. Liters</div>
                        <div id="adj_liters_val" style="font-size: 14px; font-weight: 700; color: #1e3a8a; margin-top: 2px;">0.00 L</div>
                    </div>
                    <div>
                        <div style="font-size: 10px; color: #166534; font-weight: bold; text-transform: uppercase;">Adj. Amount</div>
                        <div id="adj_amount_val" style="font-size: 14px; font-weight: 700; color: #14532d; margin-top: 2px;">₱0.00</div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Adjustment Reason <span style="color:#dc2626;">*</span></label>
                <textarea id="batchAdjustReason" rows="3" required placeholder="Explain why these transactions need adjustment (e.g., 'Incorrect meter reading', 'Price correction needed')..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('batchAdjustModal')">Cancel</button>
            <button type="button" class="ato-btn" style="background:#0ea5e9 !important; color:#fff !important; border-color:#0ea5e9 !important;" onclick="confirmBatchAdjust()">
                <i class="fas fa-edit"></i> Submit Adjust
            </button>
        </div>
    </div>
</div>

<script>
// Details View Modal
function viewDetails(tx) {
    const shift_display = tx.shift_name ? tx.shift_name : (tx.shift_period === 'second' ? 'Second Shift' : (tx.shift_period ? tx.shift_period : '—'));
    
    document.getElementById('det_id').textContent = tx.transaction_id || '—';
    document.getElementById('det_date').textContent = tx.transaction_date || '—';
    document.getElementById('det_shift').textContent = shift_display;
    document.getElementById('det_fuel').textContent = tx.fuel_type || '—';
    document.getElementById('det_price').textContent = '₱' + parseFloat(tx.price_per_liter || 0).toFixed(2);
    document.getElementById('det_beg').textContent = parseFloat(tx.previous_reading || 0).toFixed(2);
    document.getElementById('det_end').textContent = parseFloat(tx.present_reading || 0).toFixed(2);
    document.getElementById('det_cal').textContent = parseFloat(tx.calibration || 0).toFixed(2);
    document.getElementById('det_vol').textContent = parseFloat(tx.liters_sold || 0).toFixed(2) + ' L';
    document.getElementById('det_amt').textContent = '₱' + parseFloat(tx.total_amount || 0).toFixed(2);
    document.getElementById('det_staff').textContent = tx.staff_name || '—';
    document.getElementById('det_status').innerHTML = `<span class="afto-badge ${getStatusBadgeClass(tx.status)}">${getStatusLabel(tx.status)}</span>`;
    document.getElementById('det_validator').textContent = tx.validator_name || '—';
    document.getElementById('det_val_date').textContent = tx.validated_at || '—';
    document.getElementById('det_remarks').textContent = tx.reject_reason || '—';
    document.getElementById('det_staff_notes').textContent = tx.notes || '—';
    
    document.getElementById('viewModal').style.display = 'block';
}

function getStatusBadgeClass(status) {
    const s = String(status || '').toLowerCase().trim();
    if (s.includes('pending')) return 'bg-amber';
    if (s === 'verified' || s === 'approved' || s === 'validated') return 'bg-green';
    if (s === 'adjusted') return 'bg-blue';
    if (s === 'rejected') return 'bg-red';
    return 'bg-gray';
}

function getStatusLabel(status) {
    const s = String(status || '').toLowerCase().trim();
    if (s.includes('pending')) return 'Pending';
    if (s === 'verified' || s === 'approved' || s === 'validated') return 'Validated';
    if (s === 'adjusted') return 'Adjusted';
    if (s === 'rejected') return 'Rejected';
    return status;
}

// Validate / Reject Modals
function openValidate(id, txCode) {
    document.getElementById('val_id_field').value = id;
    document.getElementById('val_prompt').innerHTML = `Are you sure you want to validate and approve transaction <strong>${txCode}</strong>? This will deduct the sold volume from the tank inventory.`;
    document.getElementById('validateModal').style.display = 'block';
}

function openReject(id, txCode) {
    document.getElementById('rej_id_field').value = id;
    document.getElementById('rej_prompt').innerHTML = `Are you sure you want to reject transaction <strong>${txCode}</strong>? This will return it to the staff for correction.`;
    document.getElementById('rejectModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Client-side pagination logic
function initManagerPagination() {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    const validRows = allRows.filter(r => !r.querySelector('.afto-empty'));
    const totalRows = validRows.length;

    // Show all valid rows directly without page slicing
    validRows.forEach(row => row.style.display = '');

    const pageInfo = document.getElementById('pageInfo');
    if (pageInfo) {
        pageInfo.textContent = `Showing ${totalRows} entries`;
    }
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initManagerPagination);
} else {
    initManagerPagination();
}

// Export Helper
function mftvExport(format) {
    if (format === 'pdf') {
        const rows = Array.from(document.querySelectorAll('.afto-tbl tbody tr'));
        const originalDisplay = rows.map(row => row.style.display);
        rows.forEach(row => { row.style.display = ''; });
        exportPrintableAreaToPDF(
            '.afto-table-card',
            'Manager Fuel Transaction Validation',
            'manager_fuel_transactions_' + new Date().toISOString().slice(0, 10),
            document.activeElement
        );
        rows.forEach((row, index) => { row.style.display = originalDisplay[index]; });
        return;
    }
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    window.location.href = '?' + params.toString();
}

// Single Transaction Print Helper
function printSingleTx(tx) {
    const shift_display = tx.shift_name ? tx.shift_name : (tx.shift_period === 'second' ? 'Second Shift' : (tx.shift_period ? tx.shift_period : '—'));
    
    let iframe = document.getElementById('print-iframe');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.id = 'print-iframe';
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        document.body.appendChild(iframe);
    }

    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Transaction Print</title>
    <style>
        body{font-family:'Courier New',monospace;font-size:12px;padding:20px;color:#000;}
        .receipt-header{text-align:center;border-bottom:1px dashed #000;padding-bottom:10px;margin-bottom:10px;}
        .receipt-line{display:flex;justify-content:between;margin:4px 0;}
        .receipt-line span{display:inline-block;}
        .receipt-line span:first-child{font-weight:bold;}
        .total-row{font-size:14px;font-weight:bold;border-top:1px dashed #000;border-bottom:1px dashed #000;padding:6px 0;margin:8px 0;}
    </style></head><body>
        <div class="receipt-header">
            <h3>PETRON FUEL SLIP</h3>
            <p>${escapeHtml(user_station_name())}</p>
        </div>
        <div class="receipt-line"><span>Txn ID:</span><span>${escapeHtml(tx.transaction_id)}</span></div>
        <div class="receipt-line"><span>Date:</span><span>${escapeHtml(tx.transaction_date)}</span></div>
        <div class="receipt-line"><span>Shift:</span><span>${escapeHtml(shift_display)}</span></div>
        <div class="receipt-line"><span>Fuel Type:</span><span>${escapeHtml(tx.fuel_type)}</span></div>
        <div class="receipt-line"><span>Beginning:</span><span>${parseFloat(tx.previous_reading || 0).toFixed(2)}</span></div>
        <div class="receipt-line"><span>Ending:</span><span>${parseFloat(tx.present_reading || 0).toFixed(2)}</span></div>
        <div class="receipt-line"><span>Calibration:</span><span>${parseFloat(tx.calibration || 0).toFixed(2)}</span></div>
        <div class="receipt-line"><span>Price/Liter:</span><span>₱${parseFloat(tx.price_per_liter || 0).toFixed(2)}</span></div>
        <div class="receipt-line"><span>Volume:</span><span>${parseFloat(tx.liters_sold || 0).toFixed(2)} L</span></div>
        <div class="total-row"><span style="float:left;">AMOUNT DUE:</span><span style="float:right;">₱${parseFloat(tx.total_amount || 0).toFixed(2)}</span><div style="clear:both;"></div></div>
        <div class="receipt-line"><span>Staff Encoder:</span><span>${escapeHtml(tx.staff_name || '')}</span></div>
        <div class="receipt-line"><span>Status:</span><span>${escapeHtml(getStatusLabel(tx.status))}</span></div>
        <div class="receipt-line"><span>Validator:</span><span>${escapeHtml(tx.validator_name || '—')}</span></div>
        <div class="receipt-line"><span>Val Date:</span><span>${escapeHtml(tx.validated_at || '—')}</span></div>
        <div style="margin-top:15px;text-align:center;font-size:10px;border-top:1px dashed #000;padding-top:10px;">Thank you! For internal reconciliation only.</div>
    </body></html>`);
    doc.close();

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
    }, 250);
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// BATCH SELECTION & ACTIONS
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// Toggle Select All checkbox
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.tx-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBatchButtons();
}

// Update batch action buttons based on selection
function updateBatchButtons() {
    const selected = getSelectedTransactions();
    const count = selected.length;
    
    document.getElementById('selectedCount').textContent = count;
    document.getElementById('btnApproveSelected').disabled = count === 0;
    document.getElementById('btnRejectSelected').disabled = count === 0;
    document.getElementById('btnAdjustSelected').disabled = count === 0;
    
    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.tx-checkbox');
    const selectAllCheck = document.getElementById('selectAllCheck');
    if (selectAllCheck && allCheckboxes.length > 0) {
        selectAllCheck.checked = count === allCheckboxes.length;
        selectAllCheck.indeterminate = count > 0 && count < allCheckboxes.length;
    }
}

// Get all selected transaction IDs and data
function getSelectedTransactions() {
    const checkboxes = document.querySelectorAll('.tx-checkbox:checked');
    const selected = [];
    checkboxes.forEach(cb => {
        const txId = cb.dataset.txId;
        const row = document.querySelector(`tr[data-tx-id="${txId}"]`);
        if (row) {
            try {
                const txData = JSON.parse(row.dataset.txJson);
                selected.push(txData);
            } catch (e) {
                console.error('Failed to parse tx data:', e);
            }
        }
    });
    return selected;
}

// â”€â”€ Custom Petron Modal Helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function showActionConfirmModal({ icon, title, message, confirmText, confirmBg }) {
    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.style.cssText = 'display:flex;position:fixed;inset:0;background:rgba(15,23,42,0.6);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(3px);';
        overlay.innerHTML = `
            <div class="modal-content" style="max-width:440px;width:90%;border-radius:14px;padding:28px 30px;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;text-align:center;">
                <div style="width:52px;height:52px;background:${confirmBg}18;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                    <i class="${icon}" style="font-size:24px;color:${confirmBg};"></i>
                </div>
                <h3 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">${escapeHtml(title)}</h3>
                <p style="margin:0 0 24px;font-size:13px;color:#475569;line-height:1.6;font-weight:500;">${escapeHtml(message).replace(/\n/g, '<br>')}</p>
                <div style="display:flex;gap:12px;justify-content:center;">
                    <button id="modalConfirmCancel" type="button" class="ato-btn ato-btn-back" style="padding:9px 22px !important;border-radius:6px !important;">Cancel</button>
                    <button id="modalConfirmOk" type="button" class="ato-btn" style="background:${confirmBg} !important;color:#fff !important;border-color:${confirmBg} !important;padding:9px 22px !important;border-radius:6px !important;font-weight:700 !important;">
                        ${escapeHtml(confirmText)}
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#modalConfirmOk').onclick = () => { overlay.remove(); resolve(true); };
        overlay.querySelector('#modalConfirmCancel').onclick = () => { overlay.remove(); resolve(false); };
    });
}

function showActionResultModal({ title, message, badgeText, badgeBg, badgeColor, icon, count, totalLiters, totalSales }) {
    const toastType = (badgeBg && badgeBg.includes('dcfce7')) ? 'success' : ((badgeBg && badgeBg.includes('fee2e2')) ? 'error' : 'info');
    
    // Trigger top-right floating toast notification banner immediately!
    if (window.showPetronFlash) {
        window.showPetronFlash(title + ': ' + message, toastType, 6000);
    } else if (window.showToast) {
        window.showToast(title + ': ' + message, toastType, 6000);
    }

    return new Promise(resolve => {
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.style.cssText = 'display:flex;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(4px);';
        
        let detailsBox = '';
        if (count !== undefined && count > 0) {
            detailsBox = `
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 18px;margin:18px 0 24px;text-align:left;display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:12px;">
                    <div>
                        <span style="color:#64748b;font-size:10px;font-weight:700;text-transform:uppercase;display:block;">TRANSACTIONS</span>
                        <strong style="color:#0f172a;font-size:15px;">${count} Record(s)</strong>
                    </div>
                    ${totalLiters ? `
                    <div>
                        <span style="color:#64748b;font-size:10px;font-weight:700;text-transform:uppercase;display:block;">TOTAL VOLUME</span>
                        <strong style="color:#0f172a;font-size:15px;">${totalLiters}</strong>
                    </div>` : ''}
                    ${totalSales ? `
                    <div style="grid-column: span 2; border-top:1px solid #e2e8f0; padding-top:8px; margin-top:2px;">
                        <span style="color:#64748b;font-size:10px;font-weight:700;text-transform:uppercase;display:block;">TOTAL SALES AMOUNT</span>
                        <strong style="color:#16a34a;font-size:17px;">${totalSales}</strong>
                    </div>` : ''}
                </div>`;
        }

        overlay.innerHTML = `
            <div class="modal-content" style="max-width:460px;width:90%;border-radius:16px;padding:30px;box-shadow:0 24px 48px rgba(0,0,0,0.35);background:#fff;text-align:center;">
                <div style="width:60px;height:60px;background:${badgeBg};border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 8px 20px ${badgeBg}44;">
                    <i class="${icon}" style="font-size:28px;color:${badgeColor};"></i>
                </div>
                <div style="display:inline-block;padding:4px 12px;background:${badgeBg};color:${badgeColor};border-radius:20px;font-size:11px;font-weight:800;letter-spacing:0.5px;text-transform:uppercase;margin-bottom:10px;">
                    ${escapeHtml(badgeText)}
                </div>
                <h3 style="margin:0 0 8px;font-size:20px;font-weight:800;color:#0f172a;">${escapeHtml(title)}</h3>
                <p style="margin:0;font-size:13px;color:#475569;line-height:1.5;font-weight:500;">${escapeHtml(message)}</p>
                ${detailsBox}
                <div style="margin-top:10px;">
                    <button id="modalResultOk" type="button" class="ato-btn" style="width:100%;padding:11px;border-radius:8px !important;background:#002F6C !important;color:#fff !important;font-size:14px !important;font-weight:700 !important;box-shadow:0 4px 12px rgba(0,47,108,0.3);">
                        <i class="fas fa-check-circle" style="margin-right:6px;"></i> OK / Refresh
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        overlay.querySelector('#modalResultOk').onclick = () => {
            sessionStorage.setItem('petron_post_reload_toast_msg', title);
            sessionStorage.setItem('petron_post_reload_toast_type', toastType);
            overlay.remove();
            resolve(true);
        };
    });
}

// â”€â”€ Check for post-reload flash toast banner on page load â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
document.addEventListener('DOMContentLoaded', function() {
    const postReloadMsg = sessionStorage.getItem('petron_post_reload_toast_msg');
    const postReloadType = sessionStorage.getItem('petron_post_reload_toast_type') || 'success';
    if (postReloadMsg) {
        sessionStorage.removeItem('petron_post_reload_toast_msg');
        sessionStorage.removeItem('petron_post_reload_toast_type');
        setTimeout(function() {
            if (window.showPetronFlash) {
                window.showPetronFlash(postReloadMsg, postReloadType, 5000);
            } else if (window.showToast) {
                window.showToast(postReloadMsg, postReloadType, 5000);
            }
        }, 300);
    }
});


function notifySelectWarning(msg) {
    if (window.showPetronFlash) {
        window.showPetronFlash(msg, 'warning');
    } else {
        showActionResultModal({
            title: 'Selection Required',
            message: msg,
            badgeText: 'âš ï¸ Action Required',
            badgeBg: '#fef3c7',
            badgeColor: '#b45309',
            icon: 'fas fa-exclamation-triangle'
        });
    }
}

let currentFscTxIds = [];
let currentFscReportDate = '';
let currentFscShift = '';
let currentFscShiftPeriod = '';
let currentFscTotalLiters = 0;
let currentFscTotalSales = 0;

function fscRecalcTotals() {
    const c1 = getRawNumber('fsc_cash_shift1');
    const c2 = getRawNumber('fsc_cash_shift2');
    const totCash = c1 + c2;
    const elTotCash = document.getElementById('fsc_total_cash_val');
    if (elTotCash) {
        elTotCash.textContent = '₱' + totCash.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    const ar1 = getRawNumber('fsc_ar_shift1');
    const ar2 = getRawNumber('fsc_ar_shift2');
    const totAr = ar1 + ar2;
    const elTotAr = document.getElementById('fsc_total_ar_val');
    if (elTotAr) {
        elTotAr.textContent = '₱' + totAr.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    const netSales = totCash + totAr;
    const elNetSales = document.getElementById('fsc_net_sales_val');
    if (elNetSales) {
        elNetSales.textContent = '₱' + netSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}

async function openFuelClosingApprovalModal(txIds) {
    if (!txIds || txIds.length === 0) {
        const selected = getSelectedTransactions();
        if (selected.length === 0) {
            notifySelectWarning('Please select at least one transaction to approve.');
            return;
        }
        txIds = selected.map(tx => tx.id);
    }
    
    currentFscTxIds = txIds;
    document.getElementById('fsc_loading').style.display = 'block';
    document.getElementById('fsc_content_wrap').style.display = 'none';
    document.getElementById('fuelClosingApprovalModal').style.display = 'flex';

    try {
        const res = await fetch('manager_fuel_transaction_validation.php?ajax_action=get_closing_for_review&tx_ids=' + txIds.join(','));
        const data = await res.json();
        
        if (!data || !data.success) {
            throw new Error(data?.message || 'Failed to load fuel closing data.');
        }

        currentFscReportDate = data.report_date || '';
        currentFscShift = data.shift || 'Second Shift';
        currentFscShiftPeriod = data.shift_period || 'second';
        currentFscTotalLiters = parseFloat(data.calculated?.total_liters || 0);
        currentFscTotalSales = parseFloat(data.calculated?.total_sales || 0);

        document.getElementById('fsc_badge_date').textContent = currentFscReportDate;
        document.getElementById('fsc_badge_shift').textContent = currentFscShift;
        document.getElementById('fsc_tx_count').textContent = data.calculated?.pumps?.length || 0;
        document.getElementById('fsc_disp_liters').textContent = currentFscTotalLiters.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
        document.getElementById('fsc_disp_sales').textContent = '₱' + currentFscTotalSales.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        // Populate readings table
        let tbodyHtml = '';
        if (data.calculated?.pumps && data.calculated.pumps.length > 0) {
            data.calculated.pumps.forEach(p => {
                tbodyHtml += `
                    <tr style="border-bottom: 1px solid #F1F5F9;">
                        <td style="padding: 6px 8px; font-weight: 700; color: #002F6C;">${escapeHtml(p.pump_name)}</td>
                        <td style="padding: 6px 8px;">${escapeHtml(p.fuel_type)}</td>
                        <td style="padding: 6px 8px; text-align: right;">${parseFloat(p.beginning).toFixed(2)}</td>
                        <td style="padding: 6px 8px; text-align: right; font-weight: 700;">${parseFloat(p.ending).toFixed(2)}</td>
                        <td style="padding: 6px 8px; text-align: right;">${parseFloat(p.cal).toFixed(2)}</td>
                        <td style="padding: 6px 8px; text-align: right; font-weight: 700; color: #1E3A8A;">${parseFloat(p.liters).toFixed(2)} L</td>
                        <td style="padding: 6px 8px; text-align: right; font-weight: 800; color: #15803D;">₱${parseFloat(p.amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    </tr>
                `;
            });
        }
        document.getElementById('fsc_readings_tbody').innerHTML = tbodyHtml;

        // Populate closing values
        const closing = data.closing || {};
        const isShift1 = (currentFscShiftPeriod === 'first' || currentFscShift.toLowerCase().includes('first') || currentFscShift.includes('1'));
        
        let c1 = parseFloat(closing.cash_shift1 || 0);
        let c2 = parseFloat(closing.cash_shift2 || 0);
        let ar1 = parseFloat(closing.ar_shift1 || 0);
        let ar2 = parseFloat(closing.ar_shift2 || 0);

        // Intelligent default if not set
        if (c1 === 0 && c2 === 0 && ar1 === 0 && ar2 === 0) {
            if (isShift1) {
                c1 = currentFscTotalSales;
            } else {
                c2 = currentFscTotalSales;
            }
        }

        document.getElementById('fsc_cash_shift1').value = c1.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('fsc_cash_shift2').value = c2.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('fsc_ar_shift1').value = ar1.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('fsc_ar_shift2').value = ar2.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        const bankVal = parseFloat(closing.total_cash_bank || (c1 + c2) || 0);
        document.getElementById('fsc_total_cash_bank').value = bankVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        document.getElementById('fsc_checked_by').value = closing.checked_by || data.staff_name || 'Staff';
        document.getElementById('fsc_verified_by').value = closing.verified_by || data.manager_name || 'Edgar Eslit';
        document.getElementById('fsc_remarks').value = '';

        fscRecalcTotals();

        document.getElementById('fsc_loading').style.display = 'none';
        document.getElementById('fsc_content_wrap').style.display = 'block';
    } catch (e) {
        document.getElementById('fsc_loading').innerHTML = `
            <div style="color: #DC2626; font-weight: 700; margin-bottom: 8px;">Failed to load closing data</div>
            <div style="font-size: 12px; margin-bottom: 12px;">${escapeHtml(e.message)}</div>
            <button type="button" class="ato-btn ato-btn-back" onclick="closeModal('fuelClosingApprovalModal')">Close</button>
        `;
    }
}

async function saveClosingAndValidate() {
    const btn = document.getElementById('btnConfirmFscApprove');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Processing Approval...';

    const c1 = getRawNumber('fsc_cash_shift1');
    const c2 = getRawNumber('fsc_cash_shift2');
    const totCash = c1 + c2;

    const ar1 = getRawNumber('fsc_ar_shift1');
    const ar2 = getRawNumber('fsc_ar_shift2');
    const totAr = ar1 + ar2;

    const netSales = totCash + totAr;
    const totBank = getRawNumber('fsc_total_cash_bank');

    const checkedBy = document.getElementById('fsc_checked_by').value.trim();
    const verifiedBy = document.getElementById('fsc_verified_by').value.trim();
    const remarks = document.getElementById('fsc_remarks').value.trim();

    try {
        const formData = new FormData();
        formData.append('action', 'save_closing_and_validate');
        formData.append('tx_ids', JSON.stringify(currentFscTxIds));
        formData.append('report_date', currentFscReportDate);
        formData.append('shift', currentFscShift);
        formData.append('shift_period', currentFscShiftPeriod);
        formData.append('total_fuel_sales', currentFscTotalSales);
        formData.append('total_liters', currentFscTotalLiters);
        formData.append('cash_shift1', c1);
        formData.append('cash_shift2', c2);
        formData.append('total_cash', totCash);
        formData.append('ar_shift1', ar1);
        formData.append('ar_shift2', ar2);
        formData.append('total_ar', totAr);
        formData.append('net_sales', netSales);
        formData.append('total_cash_bank', totBank);
        formData.append('checked_by', checkedBy);
        formData.append('verified_by', verifiedBy);
        formData.append('manager_remarks', remarks);

        const response = await fetch('manager_fuel_transaction_validation.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const jsonRes = await response.json();
        if (jsonRes && jsonRes.success) {
            closeModal('fuelClosingApprovalModal');
            sessionStorage.setItem('petron_post_reload_toast_msg', jsonRes.message);
            sessionStorage.setItem('petron_post_reload_toast_type', 'success');
            location.reload();
        } else {
            throw new Error(jsonRes?.message || 'Approval failed.');
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = originalText;
        if (window.showPetronFlash) {
            window.showPetronFlash('Error: ' + err.message, 'error', 6000);
        } else {
            alert('Error: ' + err.message);
        }
    }
}

// Batch Validate (Approve) - Opens Fuel Sales Closing Review Modal
function batchValidate() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        notifySelectWarning('Please select at least one transaction to approve.');
        return;
    }
    openFuelClosingApprovalModal(selected.map(tx => tx.id));
}

function openValidate(id, txCode) {
    openFuelClosingApprovalModal([id]);
}

// Open Batch Reject Modal
function openBatchReject() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        notifySelectWarning('Please select at least one transaction to reject.');
        return;
    }
    
    document.getElementById('batchRejPrompt').textContent = `You are about to reject ${selected.length} transaction(s). Please provide a reason:`;
    document.getElementById('batchRejectReason').value = '';
    document.getElementById('batchRejectModal').style.display = 'flex';
}

// Confirm Batch Reject
async function confirmBatchReject() {
    const reason = document.getElementById('batchRejectReason').value.trim();
    if (!reason) {
        notifySelectWarning('Please enter a rejection reason.');
        return;
    }
    
    const selected = getSelectedTransactions();
    if (selected.length === 0) return;
    
    closeModal('batchRejectModal');
    
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    
    for (const tx of selected) {
        try {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'reject');
            formData.append('id', tx.id || tx.transaction_id);
            formData.append('remarks', reason);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            
            const jsonRes = await response.json().catch(() => null);
            if (jsonRes && jsonRes.success) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${tx.transaction_id}: ${jsonRes?.message || 'Server error'}`);
            }
        } catch (error) {
            errorCount++;
            errors.push(`${tx.transaction_id}: ${error.message}`);
        }
    }
    
    if (errorCount === 0) {
        sessionStorage.setItem('petron_post_reload_toast_msg', `${successCount} transaction(s) rejected successfully.`);
        sessionStorage.setItem('petron_post_reload_toast_type', 'warning');
        location.reload();
    } else {
        sessionStorage.setItem('petron_post_reload_toast_msg', `Rejected with ${errorCount} error(s): ` + errors.join(', '));
        sessionStorage.setItem('petron_post_reload_toast_type', 'error');
        location.reload();
    }
}

// Print Selected Transactions
function printSelected() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        notifySelectWarning('Please select at least one transaction to print.');
        return;
    }
    
    // Build print content
    let printHTML = `
        <html>
        <head>
            <title>Fuel Transaction Validation Report</title>
            <style>
                body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
                h1 { font-size: 16px; margin-bottom: 5px; }
                h2 { font-size: 13px; margin: 15px 0 8px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
                th { background: #f5f5f5; font-weight: bold; }
                .text-right { text-align: right; }
                .summary { margin: 15px 0; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <h1>Fuel Transaction Validation Report</h1>
            <p><strong>Date:</strong> ${new Date().toLocaleString('en-PH')}</p>
            <p><strong>Total Transactions:</strong> ${selected.length}</p>
            
            <div class="summary">
                <strong>Summary:</strong><br>
                Total Liters: ${selected.reduce((sum, tx) => sum + (parseFloat(tx.liters_sold) || 0), 0).toFixed(2)} L<br>
                Total Sales: ₱${selected.reduce((sum, tx) => sum + (parseFloat(tx.total_amount) || 0), 0).toLocaleString('en-PH', {minimumFractionDigits:2})}
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Date</th>
                        <th>Shift</th>
                        <th>Fuel Type</th>
                        <th class="text-right">Begin</th>
                        <th class="text-right">Ending</th>
                        <th class="text-right">Liters</th>
                        <th class="text-right">Amount</th>
                        <th>Staff</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    selected.forEach(tx => {
        const pump = tx.pump_number || 'Pump #' + (tx.pump_id || '?');
        printHTML += `
            <tr>
                <td>${escapeHtml(tx.transaction_id || '—')}</td>
                <td>${new Date(tx.transaction_date).toLocaleDateString('en-PH')}</td>
                <td>${escapeHtml(pump)}</td>
                <td>${escapeHtml(tx.fuel_type || '—')}</td>
                <td class="text-right">${parseFloat(tx.previous_reading || 0).toFixed(2)}</td>
                <td class="text-right">${parseFloat(tx.present_reading || 0).toFixed(2)}</td>
                <td class="text-right">${parseFloat(tx.liters_sold || 0).toFixed(2)} L</td>
                <td class="text-right">₱${parseFloat(tx.total_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                <td>${escapeHtml(tx.staff_name || '—')}</td>
            </tr>
        `;
    });
    
    printHTML += `
                </tbody>
            </table>
        </body>
        </html>
    `;
    
    // Open print window
    const printWindow = window.open('', '_blank');
    printWindow.document.write(printHTML);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => printWindow.print(), 250);
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// BATCH ADJUST FUNCTIONS
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•

// Number formatting helper with automatic commas and dot
function formatAutoCommaDot(el) {
    if (!el) return;
    let val = el.value || '';
    let rawPos = el.selectionStart || 0;
    let digitsBeforeCursor = (val.substring(0, rawPos).match(/[0-9.]/g) || []).length;

    let clean = val.replace(/[^0-9.]/g, '');
    let parts = clean.split('.');
    if (parts.length > 2) {
        clean = parts[0] + '.' + parts.slice(1).join('');
        parts = clean.split('.');
    }

    let integerPart = parts[0] || '';
    let decimalPart = parts.length > 1 ? parts[1] : null;

    if (integerPart !== '') {
        integerPart = parseInt(integerPart, 10).toLocaleString('en-US');
    }

    let formatted = integerPart;
    if (decimalPart !== null) {
        decimalPart = decimalPart.substring(0, 2);
        formatted += '.' + decimalPart;
    }

    el.value = formatted;

    if (document.activeElement === el) {
        let newPos = 0;
        let digitsCount = 0;
        for (let i = 0; i < formatted.length; i++) {
            if (/[0-9.]/.test(formatted[i])) {
                digitsCount++;
            }
            if (digitsCount === digitsBeforeCursor) {
                newPos = i + 1;
                break;
            }
        }
        if (newPos === 0) newPos = formatted.length;
        try {
            el.setSelectionRange(newPos, newPos);
        } catch (e) {}
    }
}

function formatAutoCommaDotOnBlur(el) {
    if (!el) return;
    let val = (el.value || '').toString().replace(/,/g, '');
    let num = parseFloat(val);
    if (!isNaN(num)) {
        el.value = num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    } else {
        el.value = '0.00';
    }
}

function getRawNumber(elId) {
    const el = document.getElementById(elId);
    if (!el) return 0;
    const raw = (el.value || '').toString().replace(/,/g, '');
    return parseFloat(raw) || 0;
}

// Open Batch Adjust Modal
function openBatchAdjust() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        notifySelectWarning('Please select at least one transaction to adjust.');
        return;
    }
    
    document.getElementById('batchAdjustPrompt').textContent = `You are about to mark ${selected.length} transaction(s) for adjustment. Please provide a reason:`;
    document.getElementById('batchRejectReason') ? null : null;
    document.getElementById('batchAdjustReason').value = '';
    
    const singleFields = document.getElementById('singleAdjustFields');
    if (selected.length === 1) {
        const tx = selected[0];
        const begVal = parseFloat(tx.previous_reading || 0);
        const endVal = parseFloat(tx.present_reading || 0);
        const calVal = parseFloat(tx.calibration || 0);
        const prcVal = parseFloat(tx.price_per_liter || 0);

        document.getElementById('adj_beginning').value = begVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('adj_ending').value = endVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('adj_calibration').value = calVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('adj_price').value = prcVal.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        singleFields.style.display = 'block';
        calcAdjTotals();
    } else {
        singleFields.style.display = 'none';
    }
    
    document.getElementById('batchAdjustModal').style.display = 'flex';
}

function calcAdjTotals() {
    const beg = getRawNumber('adj_beginning');
    const end = getRawNumber('adj_ending');
    const cal = getRawNumber('adj_calibration');
    const price = getRawNumber('adj_price');

    let liters = end - beg - cal;
    if (liters < 0) liters = 0;

    const amount = liters * price;

    document.getElementById('adj_liters_val').textContent = liters.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' L';
    document.getElementById('adj_amount_val').textContent = '₱' + amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Confirm Batch Adjust
async function confirmBatchAdjust() {
    const reason = document.getElementById('batchAdjustReason').value.trim();
    if (!reason) {
        notifySelectWarning('Please enter an adjustment reason.');
        return;
    }
    
    const selected = getSelectedTransactions();
    if (selected.length === 0) return;
    
    closeModal('batchAdjustModal');
    
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    
    for (const tx of selected) {
        try {
            const formData = new FormData();
            formData.append('ajax', '1');
            formData.append('action', 'adjust');
            formData.append('id', tx.id);
            formData.append('remarks', reason);
            
            if (selected.length === 1) {
                formData.append('beginning', getRawNumber('adj_beginning'));
                formData.append('ending', getRawNumber('adj_ending'));
                formData.append('calibration', getRawNumber('adj_calibration'));
            }
            
            const response = await fetch('', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            
            const jsonRes = await response.json().catch(() => null);
            if (jsonRes && jsonRes.success) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${tx.transaction_id}: ${jsonRes?.message || 'Server error'}`);
            }
        } catch (error) {
            errorCount++;
            errors.push(`${tx.transaction_id}: ${error.message}`);
        }
    }
    
    if (errorCount === 0) {
        sessionStorage.setItem('petron_post_reload_toast_msg', `${successCount} transaction(s) adjusted successfully.`);
        sessionStorage.setItem('petron_post_reload_toast_type', 'info');
        location.reload();
    } else {
        sessionStorage.setItem('petron_post_reload_toast_msg', `Adjusted with ${errorCount} error(s): ` + errors.join(', '));
        sessionStorage.setItem('petron_post_reload_toast_type', 'error');
        location.reload();
    }
}


</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
