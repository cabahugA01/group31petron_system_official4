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

// ── Shift Dependency & Continuity Helpers ─────────────────────
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

// ── Filters & Inputs ─────────────────────────────────────────
$date_from          = trim($_GET['date_from']          ?? date('Y-m-d', strtotime('-30 days')));
$date_to            = trim($_GET['date_to']            ?? date('Y-m-d'));
$shift_filter       = trim($_GET['shift_filter']       ?? 'all');
$fuel_type_filter   = trim($_GET['fuel_type']          ?? '');
$status_filter      = trim($_GET['status_filter']      ?? 'pending');
$search_query       = trim($_GET['search_query']       ?? '');
$export             = trim($_GET['export']             ?? '');

// ── POST Actions (Validate / Reject) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = trim($_POST['action'] ?? '');
    $tx_id   = (int)($_POST['id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($tx_id <= 0) {
        $_SESSION['error'] = 'Invalid transaction ID.';
        header('Location: manager_fuel_transaction_validation.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        // Fetch transaction details
        $stmt = $pdo->prepare("SELECT * FROM fuel_transactions WHERE id = ? AND station_id = ?");
        $stmt->execute([$tx_id, $station_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new Exception("Transaction not found.");
        }

        if ($action === 'validate') {
            // Guard: must be pending
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $liters_sold = (float)$tx['liters_sold'];
            $prev_reading = (float)$tx['previous_reading'];

            // Enforce sequence validation & carry-over for pump transactions
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
                        throw new Exception("Cannot validate this transaction. The transaction for the preceding shift (" . ucfirst($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
                    }
                }
                
                // Programmatically set Beginning Reading to match preceding shift's validated Ending Reading
                $prev_reading = get_preceding_shift_validated_ending($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str);
                $present_reading = (float)$tx['present_reading'];
                $calibration = (float)$tx['calibration'];
                
                if ($present_reading < $prev_reading) {
                    throw new Exception("Ending reading (" . number_format($present_reading, 2) . ") cannot be less than the preceding shift's validated ending reading (" . number_format($prev_reading, 2) . ").");
                }
                
                $liters_sold = max(0.00, $present_reading - $prev_reading - $calibration);
                $price_per_liter = (float)$tx['price_per_liter'];
                $total_amount = round($liters_sold * $price_per_liter, 2);
                
                // Update with carried over readings
                $up = $pdo->prepare("UPDATE fuel_transactions SET previous_reading = ?, liters_sold = ?, total_amount = ?, status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$prev_reading, $liters_sold, $total_amount, $me['id'], $remarks ?: null, $tx_id]);
            } else {
                // Non-pump fallback
                $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$me['id'], $remarks ?: null, $tx_id]);
            }

            // Deduct stock from fuel_inventory
            $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                       SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                           current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                           last_updated   = NOW()
                                       WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
            $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type']]);

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
            // Guard: must be pending
            if (!str_contains(strtolower($tx['status']), 'pending')) {
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
            // Guard: must be pending
            if (!str_contains(strtolower($tx['status']), 'pending')) {
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
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }

    $redirect_url = 'manager_fuel_transaction_validation.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirect_url); exit;
}

// ── Helper functions for badges ──────────────────────────────
function getStatusBadgeClass($status) {
    $s = strtolower(trim($status ?? ''));
    if (str_contains($s, 'pending')) return 'bg-amber';
    if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'bg-green';
    if ($s === 'adjusted') return 'bg-blue';
    if ($s === 'rejected') return 'bg-red';
    return 'bg-gray';
}
function getStatusLabel($status) {
    $s = strtolower(trim($status ?? ''));
    if (str_contains($s, 'pending')) return 'Pending';
    if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'Validated';
    if ($s === 'adjusted') return 'Adjusted';
    if ($s === 'rejected') return 'Rejected';
    return ucfirst($status);
}

// ── Summary Cards Data ───────────────────────────────────────
$pending_count   = 0;
$validated_count = 0;
$rejected_count  = 0;
$total_liters_today = 0.0;
$total_sales_today = 0.0;

try {
    // 1. Pending Transactions (Total overall currently awaiting manager validation)
    $sp = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id = ? AND LOWER(status) LIKE '%pending%'");
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

// ── Fetch Dynamic Fuel Types ────────────────────────────────
$fuel_types = [];
try {
    $ft_stmt = $pdo->prepare("SELECT DISTINCT fuel_type FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
    $ft_stmt->execute([$station_id]);
    $fuel_types = $ft_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Fetch Filtered Transactions ──────────────────────────────
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
        $where[] = "LOWER(ft.status) LIKE '%pending%'";
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

// ── EXPORTS ──────────────────────────────────────────────────
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

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Fuel Transaction Validation Report</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">🖨 Print / Save PDF</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">← Back</a></div>';
        echo '<div class="hdr"><div><h1>Petron Fuel Transaction Validation</h1><p style="margin:2px 0 0;color:#666;">Period: ' . htmlspecialchars($date_from) . ' — ' . htmlspecialchars($date_to) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div><div style="text-align:right;"><p style="margin:0;">Generated: ' . $generated . '</p></div></div>';
        echo '<table><thead><tr><th>Txn ID</th><th>Date</th><th>Shift</th><th>Fuel Type</th><th>Beginning</th><th>Ending</th><th>Calib</th><th>Liters</th><th>Price/L</th><th>Amount</th><th>Staff</th><th>Status</th><th>Val Date</th><th>Remarks</th></tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="14" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* Reset and core alignment */
* { box-sizing: border-box; }
html, body { max-width: 100vw !important; width: 100%; overflow-x: hidden !important; position: relative; }
.mftv-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 8px; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; padding: 0 !important; }

/* Petron clean headers */
.int-head { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; margin-top: 8px !important; padding-top: 8px; width: 100%; }
.int-head > div:first-child { flex: 1; min-width: 280px; max-width: 65%; }
.int-head > div:last-child { flex-shrink: 0; display: flex; gap: 8px; flex-wrap: wrap; }
.int-head h1 { font-size: 22px !important; font-weight: 700 !important; color: #00264D !important; margin: 0 !important; text-transform: uppercase !important; display: flex; align-items: center; gap: 8px; line-height: 1.3; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Outline buttons */
.ato-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 0 16px; border-radius: 7px; font-size: 13px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s;
    height: 36px; white-space: nowrap; background: white !important;
}
.ato-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.ato-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.ato-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.ato-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }
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
.afto-tbl { width: 100%; max-width: 100%; border-collapse: collapse; font-size: 10px; table-layout: fixed; }
.afto-tbl thead tr { background: #002F70; }
.afto-tbl thead th { padding: 8px 6px; text-align: left; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .3px; border-bottom: 2px solid #001a3d; vertical-align: middle; white-space: nowrap; }
.afto-tbl tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .1s; }
.afto-tbl tbody tr:hover td { background: #eff6ff; }
.afto-tbl tbody td { padding: 8px 6px; color: #334155; vertical-align: middle; background: #fff; font-size: 10px; word-wrap: break-word; overflow: hidden; text-overflow: ellipsis; }

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

/* Page buttons */
.page-btn { padding: 6px 12px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; color: #64748b; font-size: 12px; cursor: pointer; transition: all .15s; }
.page-btn:hover:not(:disabled) { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }
.page-btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>

<div class="mftv-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-check-double"></i> Fuel Transaction Validation</h1>
            <div class="sub">Review and validate fuel transactions encoded by staff for accuracy and compliance.</div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; justify-content: flex-end;">
            <button type="button" onclick="mftvExport('excel')" class="ato-btn ato-btn-excel"><i class="fas fa-file-excel"></i> Excel</button>
            <button type="button" onclick="mftvExport('pdf')" class="ato-btn ato-btn-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
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
                                    <?php if (str_contains(strtolower($tx['status'] ?? ''), 'pending')): ?>
                                        <input type="checkbox" class="tx-checkbox" data-tx-id="<?= $tx['id'] ?>" style="cursor: pointer;" onchange="updateBatchButtons()">
                                    <?php else: ?>
                                        <span style="color: #cbd5e1;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 600; color: #00264D; font-size: 9px;"><?= htmlspecialchars(substr($tx['transaction_id'], 0, 12)) ?></td>
                                <td style="font-size: 9px;"><?= date('M d, Y', strtotime($tx['transaction_date'])) ?></td>
                                <td style="font-size: 9px;"><?= htmlspecialchars($shift_display) ?></td>
                                <td style="font-size: 9px; font-weight:700; color:#0f172a; white-space:normal; word-break:break-word;" title="<?= htmlspecialchars($tx['_seq_label']) ?>"><?= htmlspecialchars($tx['_seq_label']) ?></td>
                                <td style="text-align: right; font-size: 9px;"><?= number_format($tx['previous_reading'], 2) ?></td>
                                <td style="text-align: right; font-weight: 600; font-size: 9px;"><?= number_format($tx['present_reading'], 2) ?></td>
                                <td style="text-align: right; font-size: 9px;"><?= number_format($tx['calibration'], 2) ?></td>
                                <td style="text-align: right; font-weight: 700; color: #1e293b; font-size: 9px;"><?= number_format($tx['liters_sold'], 2) ?> L</td>
                                <td style="text-align: right; font-weight: 800; color: #002F70; font-size: 9px;">₱<?= number_format($tx['total_amount'], 2) ?></td>
                                <td style="font-size: 9px;"><?= htmlspecialchars(substr($tx['staff_name'] ?? '—', 0, 12)) ?></td>
                                <td><span class="afto-badge <?= getStatusBadgeClass($tx['status'] ?? '') ?>"><?= getStatusLabel($tx['status'] ?? '') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Client-side Pagination -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-top: 1px solid #f1f5f9; flex-wrap: wrap; gap: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <label style="font-size: 12px; color: #64748b; font-weight: 600;">Rows per page:</label>
                    <select id="rowsPerPage" style="padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; cursor: pointer;">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span id="pageInfo" style="font-size: 12px; color: #64748b; font-weight: 600;">Page 1 of 1</span>
                    <div style="display: flex; gap: 4px;">
                        <button id="prevPage" class="page-btn" disabled><i class="fas fa-chevron-left"></i> Prev</button>
                        <button id="nextPage" class="page-btn">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
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
                        <input type="number" step="0.01" id="adj_beginning" name="beginning" oninput="calcAdjTotals()">
                    </div>
                    <div class="form-group">
                        <label>Ending Reading</label>
                        <input type="number" step="0.01" id="adj_ending" name="ending" oninput="calcAdjTotals()">
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Calibration</label>
                        <input type="number" step="0.01" id="adj_calibration" name="calibration" oninput="calcAdjTotals()">
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
(function() {
    const tableBody = document.querySelector('.afto-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    let currentPage = 1;
    let rowsPerPage = 25;

    const rowsSelect = document.getElementById('rowsPerPage');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');

    function updateTable() {
        const totalPages = Math.ceil(allRows.length / rowsPerPage) || 1;
        if (currentPage > totalPages) currentPage = totalPages;
        const start = (currentPage - 1) * rowsPerPage;
        const end = start + rowsPerPage;

        allRows.forEach(row => row.style.display = 'none');
        allRows.slice(start, end).forEach(row => row.style.display = '');

        pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
        prevBtn.disabled = currentPage === 1;
        nextBtn.disabled = currentPage === totalPages;
    }

    if (rowsSelect) {
        rowsSelect.addEventListener('change', function() {
            rowsPerPage = parseInt(this.value);
            currentPage = 1;
            updateTable();
        });
    }

    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            if (currentPage > 1) {
                currentPage--;
                updateTable();
                document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            const totalPages = Math.ceil(allRows.length / rowsPerPage) || 1;
            if (currentPage < totalPages) {
                currentPage++;
                updateTable();
                document.querySelector('.afto-table-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    updateTable();
})();

// Export Helper
function mftvExport(format) {
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

// ══════════════════════════════════════════════════════════════════════════════
// BATCH SELECTION & ACTIONS
// ══════════════════════════════════════════════════════════════════════════════

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

// Open Review Modal
function openReviewModal() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        alert('Please select at least one transaction to review.');
        return;
    }
    
    // Calculate totals
    let totalLiters = 0;
    let totalSales = 0;
    let totalCal = 0;
    
    // Build reading list HTML
    let listHTML = '';
    selected.forEach(tx => {
        const pump = tx.pump_number || 'Pump #' + (tx.pump_id || '?');
        const fuelType = tx.fuel_type || '—';
        const liters = parseFloat(tx.liters_sold) || 0;
        const amount = parseFloat(tx.total_amount) || 0;
        const cal = parseFloat(tx.calibration) || 0;
        
        totalLiters += liters;
        totalSales += amount;
        totalCal += cal;
        
        listHTML += `
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 8px;">${escapeHtml(pump)}</td>
                <td style="padding: 8px;">${escapeHtml(fuelType)}</td>
                <td style="padding: 8px; text-align: right; font-weight: 600;">${liters.toFixed(2)} L</td>
                <td style="padding: 8px; text-align: right; font-weight: 700; color: #16a34a;">₱${amount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            </tr>
        `;
    });
    
    // Update modal content
    document.getElementById('revTotalPumps').textContent = selected.length;
    document.getElementById('revTotalLiters').textContent = totalLiters.toFixed(2) + ' L';
    document.getElementById('revTotalSales').textContent = '₱' + totalSales.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('revTotalCal').textContent = totalCal.toFixed(2) + ' L';
    document.getElementById('revReadingsList').innerHTML = listHTML;
    
    // Show modal
    document.getElementById('reviewModal').style.display = 'flex';
}

// Close Review and proceed to Validate
function closeReviewAndValidate() {
    closeModal('reviewModal');
    batchValidate();
}

// Close Review and proceed to Reject
function closeReviewAndReject() {
    closeModal('reviewModal');
    openBatchReject();
}

// Batch Validate (Approve) - Direct approval without review modal
async function batchValidate() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        alert('Please select at least one transaction to approve.');
        return;
    }
    
    if (!confirm(`Approve ${selected.length} transaction(s)?\n\nThis will validate the readings and deduct fuel stock.`)) {
        return;
    }
    
    let successCount = 0;
    let errorCount = 0;
    const errors = [];
    
    for (const tx of selected) {
        try {
            const formData = new FormData();
            formData.append('action', 'validate');
            formData.append('id', tx.id);
            formData.append('remarks', 'Batch validation - approved by manager');
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${tx.transaction_id}: Server error`);
            }
        } catch (error) {
            errorCount++;
            errors.push(`${tx.transaction_id}: ${error.message}`);
        }
    }
    
    if (errorCount === 0) {
        alert(`✓ Successfully approved ${successCount} transaction(s).`);
        location.reload();
    } else {
        alert(`Completed with ${successCount} success, ${errorCount} failed.\n\n` + errors.join('\n'));
        location.reload();
    }
}

// Open Batch Reject Modal
function openBatchReject() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        alert('Please select at least one transaction to reject.');
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
        alert('Please enter a rejection reason.');
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
            formData.append('action', 'reject');
            formData.append('id', tx.id);
            formData.append('remarks', reason);
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${tx.transaction_id}: Server error`);
            }
        } catch (error) {
            errorCount++;
            errors.push(`${tx.transaction_id}: ${error.message}`);
        }
    }
    
    if (errorCount === 0) {
        alert(`✓ Successfully rejected ${successCount} transaction(s).`);
        location.reload();
    } else {
        alert(`Completed with ${successCount} success, ${errorCount} failed.\n\n` + errors.join('\n'));
        location.reload();
    }
}

// Print Selected Transactions
function printSelected() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        alert('Please select at least one transaction to print.');
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
                        <th>Pump</th>
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

// ══════════════════════════════════════════════════════════════════════════════
// BATCH ADJUST FUNCTIONS
// ══════════════════════════════════════════════════════════════════════════════

// Open Batch Adjust Modal
function openBatchAdjust() {
    const selected = getSelectedTransactions();
    if (selected.length === 0) {
        alert('Please select at least one transaction to adjust.');
        return;
    }
    
    document.getElementById('batchAdjustPrompt').textContent = `You are about to mark ${selected.length} transaction(s) for adjustment. Please provide a reason:`;
    document.getElementById('batchAdjustReason').value = '';
    
    const singleFields = document.getElementById('singleAdjustFields');
    if (selected.length === 1) {
        const tx = selected[0];
        document.getElementById('adj_beginning').value = parseFloat(tx.previous_reading || 0).toFixed(2);
        document.getElementById('adj_ending').value = parseFloat(tx.present_reading || 0).toFixed(2);
        document.getElementById('adj_calibration').value = parseFloat(tx.calibration || 0).toFixed(2);
        document.getElementById('adj_price').value = parseFloat(tx.price_per_liter || 0).toFixed(2);
        singleFields.style.display = 'block';
        calcAdjTotals();
    } else {
        singleFields.style.display = 'none';
    }
    
    document.getElementById('batchAdjustModal').style.display = 'flex';
}

function calcAdjTotals() {
    const beg = parseFloat(document.getElementById('adj_beginning').value) || 0;
    const end = parseFloat(document.getElementById('adj_ending').value) || 0;
    const cal = parseFloat(document.getElementById('adj_calibration').value) || 0;
    const price = parseFloat(document.getElementById('adj_price').value) || 0;

    let liters = end - beg - cal;
    if (liters < 0) liters = 0;

    const amount = liters * price;

    document.getElementById('adj_liters_val').textContent = liters.toFixed(2) + ' L';
    document.getElementById('adj_amount_val').textContent = '₱' + amount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Confirm Batch Adjust
async function confirmBatchAdjust() {
    const reason = document.getElementById('batchAdjustReason').value.trim();
    if (!reason) {
        alert('Please enter an adjustment reason.');
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
            formData.append('action', 'adjust');
            formData.append('id', tx.id);
            formData.append('remarks', reason);
            
            if (selected.length === 1) {
                formData.append('beginning', document.getElementById('adj_beginning').value);
                formData.append('ending', document.getElementById('adj_ending').value);
                formData.append('calibration', document.getElementById('adj_calibration').value);
            }
            
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                successCount++;
            } else {
                errorCount++;
                errors.push(`${tx.transaction_id}: Server error`);
            }
        } catch (error) {
            errorCount++;
            errors.push(`${tx.transaction_id}: ${error.message}`);
        }
    }
    
    if (errorCount === 0) {
        alert(`✓ Successfully adjusted ${successCount} transaction(s).`);
        location.reload();
    } else {
        alert(`Completed with ${successCount} success, ${errorCount} failed.\n\n` + errors.join('\n'));
        location.reload();
    }
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
