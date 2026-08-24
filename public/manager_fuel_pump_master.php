<?php
// ============================================================
// Manager Calibration Review – manager_fuel_pump_master.php
// Purpose: Granular, shift-based calibration and meter reading validation.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'fuel_pump_master';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// Access control - Manager, Supervisor, Admin
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
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
if (!function_exists('get_preceding_shift_and_date')) {
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
}

if (!function_exists('get_preceding_shift_validated_ending')) {
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
}

if (!function_exists('is_preceding_shift_validated')) {
    function is_preceding_shift_validated($pdo, $station_id, $pump_id, $current_shift, $current_date) {
        $preceding = get_preceding_shift_and_date($pdo, $current_shift, $current_date);
        if (!$preceding) {
            return true; 
        }
        
        $stmt_exists = $pdo->prepare("
            SELECT COUNT(*) 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(shift_period) = LOWER(?) 
              AND DATE(transaction_date) = ?
        ");
        $stmt_exists->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
        $exists = (int)$stmt_exists->fetchColumn() > 0;
        
        if (!$exists) {
            return false;
        }
        
        $stmt_unverified = $pdo->prepare("
            SELECT COUNT(*) 
            FROM fuel_transactions 
            WHERE station_id = ? 
              AND pump_id = ? 
              AND LOWER(shift_period) = LOWER(?) 
              AND DATE(transaction_date) = ?
              AND LOWER(status) NOT IN ('verified', 'adjusted')
        ");
        $stmt_unverified->execute([$station_id, $pump_id, $preceding['shift_key'], $preceding['date']]);
        $unverified = (int)$stmt_unverified->fetchColumn();
        
        return $unverified === 0;
    }
}

if (!function_exists('formatShiftLabel')) {
    function formatShiftLabel($shift_key) {
        $s = strtolower(trim($shift_key ?? ''));
        if ($s === 'first') return 'Shift 1';
        if ($s === 'second') return 'Shift 2';
        if ($s === 'third') return 'Shift 3';
        return ucfirst($shift_key);
    }
}

if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($status) {
        $s = strtolower(trim($status ?? ''));
        if (str_contains($s, 'pending')) return 'bg-amber';
        if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'bg-green';
        if ($s === 'adjusted') return 'bg-blue';
        if ($s === 'rejected') return 'bg-red';
        return 'bg-gray';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($status) {
        $s = strtolower(trim($status ?? ''));
        if (str_contains($s, 'pending')) return 'Pending';
        if ($s === 'verified' || $s === 'approved' || $s === 'validated') return 'Verified';
        if ($s === 'adjusted') return 'Adjusted';
        if ($s === 'rejected') return 'Rejected';
        return ucfirst($status);
    }
}

if (!function_exists('normalizeFuelType')) {
    function normalizeFuelType($fuel_type) {
        $fuel_upper = strtoupper($fuel_type ?? '');
        
        if (strpos($fuel_upper, 'TURBO') !== false && strpos($fuel_upper, 'DIESEL') !== false) {
            return 'Turbo Diesel';
        } elseif (strpos($fuel_upper, 'KEROSENE') !== false) {
            return 'Kerosene';
        } elseif (strpos($fuel_upper, 'XCS') !== false) {
            return 'XCS Plus';
        } elseif (strpos($fuel_upper, 'XTRA') !== false || strpos($fuel_upper, 'UNL') !== false) {
            return 'Xtra UNL';
        } elseif (strpos($fuel_upper, 'DIESEL') !== false) {
            return 'Diesel';
        } else {
            // Fallback: remove numbers and clean up
            $clean = preg_replace('/\s*\d+\s*-?\s*\d*\s*/', ' ', $fuel_type);
            return trim(preg_replace('/\s+/', ' ', $clean));
        }
    }
}

// â”€â”€ GET Filters â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
// Default date: if no date provided in URL, use the most recent date with transactions.
// Falls back to today if nothing found.
if (isset($_GET['date']) && $_GET['date'] !== '') {
    $date_filter = trim($_GET['date']);
} else {
    // Find most recent date with any fuel transactions for this station
    try {
        $latest_stmt = $pdo->prepare("SELECT DATE(transaction_date) FROM fuel_transactions WHERE station_id = ? ORDER BY transaction_date DESC, id DESC LIMIT 1");
        $latest_stmt->execute([$station_id]);
        $latest_date = $latest_stmt->fetchColumn();
        $date_filter = $latest_date ?: date('Y-m-d');
    } catch (Exception $e) {
        $date_filter = date('Y-m-d');
    }
}
$shift_filter       = trim($_GET['shift']     ?? 'all');
$fuel_type_filter   = trim($_GET['fuel_type'] ?? 'all');
$staff_filter       = trim($_GET['staff']     ?? '');
$export             = trim($_GET['export']    ?? '');


// â”€â”€ POST Actions (Verify / Adjust / Reject) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $export === '') {
    $action  = trim($_POST['action'] ?? '');
    $tx_id   = (int)($_POST['id'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($tx_id <= 0) {
        $_SESSION['error'] = 'Invalid transaction ID.';
        header('Location: manager_fuel_pump_master.php'); exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT ft.*,
                   COALESCE(
                       NULLIF(CONCAT(TRIM(COALESCE(staff.first_name, '')), ' ', TRIM(COALESCE(staff.last_name, ''))), ' '),
                       staff.username,
                       'Unknown'
                   ) as staff_name
            FROM fuel_transactions ft
            LEFT JOIN users staff ON ft.staff_id = staff.id
            WHERE ft.id = ? AND ft.station_id = ?
        ");
        $stmt->execute([$tx_id, $station_id]);
        $tx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tx) {
            throw new Exception("Transaction not found.");
        }

        // 1. VERIFY ACTION
        if ($action === 'verify') {
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $liters_sold = (float)$tx['liters_sold'];
            $prev_reading = (float)$tx['previous_reading'];

            if ($tx['pump_id'] > 0) {
                $tx_date_str = date('Y-m-d', strtotime($tx['transaction_date']));
                
                // Shift validation gate check
                if (!is_preceding_shift_validated($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str)) {
                    $preceding = get_preceding_shift_and_date($pdo, $tx['shift_period'], $tx_date_str);
                    throw new Exception("Cannot validate this transaction. The transaction for the preceding shift (" . formatShiftLabel($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
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
                
                $up = $pdo->prepare("UPDATE fuel_transactions SET previous_reading = ?, liters_sold = ?, total_amount = ?, status = 'Verified', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
                $up->execute([$prev_reading, $liters_sold, $total_amount, $me['id'], $remarks ?: null, $tx_id]);
            } else {
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

            log_activity($pdo, $me['id'], 'Fuel Reading Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | {$liters_sold} L");
            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> verified and approved successfully.";
        }

        // 2. ADJUST ACTION
        elseif ($action === 'adjust') {
            if (empty($remarks)) {
                throw new Exception("Adjustment reason is required.");
            }
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $ending = isset($_POST['ending']) ? (float)$_POST['ending'] : null;
            $calibration = isset($_POST['calibration']) ? (float)$_POST['calibration'] : null;

            if ($ending !== null && $calibration !== null) {
                $tx_date_str = date('Y-m-d', strtotime($tx['transaction_date']));
                $beginning = (float)$tx['previous_reading'];

                if ($tx['pump_id'] > 0) {
                    // Shift validation gate check
                    if (!is_preceding_shift_validated($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str)) {
                        $preceding = get_preceding_shift_and_date($pdo, $tx['shift_period'], $tx_date_str);
                        throw new Exception("Cannot adjust this transaction. The transaction for the preceding shift (" . formatShiftLabel($preceding['shift_key']) . " on " . $preceding['date'] . ") for this fuel line must be verified or adjusted first.");
                    }

                    $beginning = get_preceding_shift_validated_ending($pdo, $station_id, $tx['pump_id'], $tx['shift_period'], $tx_date_str);
                }

                $liters_sold = $ending - $beginning - $calibration;
                if ($liters_sold < 0) {
                    throw new Exception("Ending reading cannot be less than beginning reading and calibration combined.");
                }
                $price_per_liter = (float)$tx['price_per_liter'];
                $total_amount = $liters_sold * $price_per_liter;

                // Update transaction readings and calibration
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

                // Deduct stock from fuel_inventory
                $up_stock = $pdo->prepare("UPDATE fuel_inventory 
                                           SET current_level = GREATEST(0, COALESCE(current_level, 0) - ?),
                                               current_stock  = GREATEST(0, COALESCE(current_stock, 0) - ?),
                                               last_updated   = NOW()
                                           WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
                $up_stock->execute([$liters_sold, $liters_sold, $station_id, $tx['fuel_type']]);

                // Log into fuel_adjustments audit log
                try {
                    $meta_notes = json_encode([
                        'transaction_id' => $tx['transaction_id'],
                        'fuel_line' => 'Pump #' . ($tx['pump_id'] ?? '—'),
                        'fuel_type' => $tx['fuel_type'],
                        'shift' => formatShiftLabel($tx['shift_period']),
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
                        VALUES (?, CURDATE(), ?, null, 'transaction_adjustment', ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
                    ");
                    $ins_adj->execute([
                        $station_id,
                        $tx['fuel_type'],
                        $liters_sold,
                        (float)$tx['liters_sold'],
                        $liters_sold,
                        $remarks,
                        $me['id'],
                        $meta_notes,
                        $me['id']
                    ]);
                } catch (Exception $e) {}

                log_activity($pdo, $me['id'], 'Fuel Reading Adjusted and Approved', "TXN {$tx['transaction_id']} | {$tx['fuel_type']} | Old: {$tx['liters_sold']} L -> New: {$liters_sold} L | Reason: {$remarks}");
                $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> adjusted and validated successfully.";
            }
        }

        // 3. REJECT ACTION
        elseif ($action === 'reject') {
            if (empty($remarks)) {
                throw new Exception("Rejection reason is required.");
            }
            if (!str_contains(strtolower($tx['status']), 'pending')) {
                throw new Exception("Transaction has already been processed.");
            }

            $up = $pdo->prepare("UPDATE fuel_transactions SET status = 'Rejected', validated_by = ?, validated_at = NOW(), reject_reason = ? WHERE id = ?");
            $up->execute([$me['id'], $remarks, $tx_id]);

            log_activity($pdo, $me['id'], 'Fuel Reading Rejected', "TXN {$tx['transaction_id']} | Reason: {$remarks}");
            $_SESSION['success'] = "Transaction <strong>{$tx['transaction_id']}</strong> rejected and returned to staff.";
        }

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }

    $redirect_url = 'manager_fuel_pump_master.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirect_url .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirect_url); exit;
}

// â”€â”€ Fetch Filtered Calibration Records â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$where = ["ft.station_id = ?"];
$params = [$station_id];

// Date Filter
$where[] = "DATE(ft.transaction_date) = ?";
$params[] = $date_filter;

// Shift Filter
if ($shift_filter !== 'all') {
    $where[] = "LOWER(ft.shift_period) = ?";
    $params[] = strtolower($shift_filter);
}

// Fuel Type Filter — use LIKE so 'Diesel' matches stored 'DIESEL 1 - 1', etc.
if ($fuel_type_filter !== 'all') {
    $where[] = "LOWER(ft.fuel_type) LIKE ?";
    $params[] = '%' . strtolower($fuel_type_filter) . '%';
}

// Staff Filter
if ($staff_filter !== '') {
    $where[] = "(LOWER(staff.username) LIKE ? OR LOWER(staff.first_name) LIKE ? OR LOWER(staff.last_name) LIKE ?)";
    $like_val = '%' . strtolower($staff_filter) . '%';
    $params = array_merge($params, [$like_val, $like_val, $like_val]);
}

$records = [];
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
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Fetch calibration records error: " . $e->getMessage());
    $_SESSION['error'] = "Error loading calibration records: " . $e->getMessage();
}

// â”€â”€ Metrics Calculations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total_calibration_liters = 0.0;
$pending_reviews_count = 0;
$total_liters_validated = 0.0;

foreach ($records as $r) {
    $st = strtolower(trim($r['status'] ?? ''));
    if (in_array($st, ['verified', 'approved', 'adjusted', 'validated'])) {
        $total_calibration_liters += (float)($r['calibration'] ?? 0.0);
        $total_liters_validated += (float)($r['liters_sold'] ?? 0.0);
    } elseif (str_contains($st, 'pending')) {
        $pending_reviews_count++;
    }
}

// â”€â”€ Fetch dynamic filters data (from fuel_transactions for accurate type list) â”€
$fuel_types = [];
try {
    // Pull distinct fuel types from actual transactions so the dropdown matches stored data
    $ft_stmt = $pdo->prepare("
        SELECT DISTINCT
            CASE
                WHEN UPPER(fuel_type) LIKE '%TURBO%DIESEL%' THEN 'Turbo Diesel'
                WHEN UPPER(fuel_type) LIKE '%KEROSENE%'     THEN 'Kerosene'
                WHEN UPPER(fuel_type) LIKE '%XCS%'          THEN 'XCS Plus'
                WHEN UPPER(fuel_type) LIKE '%XTRA%UNL%'     THEN 'Xtra UNL'
                WHEN UPPER(fuel_type) LIKE '%DIESEL%'       THEN 'Diesel'
                ELSE fuel_type
            END AS normalized_type
        FROM fuel_transactions
        WHERE station_id = ?
        ORDER BY 1
    ");
    $ft_stmt->execute([$station_id]);
    $fuel_types = array_unique($ft_stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (Exception $e) {}

// â”€â”€ EXPORTS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if (in_array($export, ['excel', 'pdf'])) {
    $headers = ['Date', 'Shift', 'Fuel Type', 'Staff Encoder', 'Beginning', 'Ending', 'Staff Calibration', 'Manager Calibration', 'Liters Sold', 'Status', 'Validated By', 'Date Validated'];
    $rows_fmt = [];
    foreach ($records as $r) {
        // Normalize fuel type for export
        $fuel_display = $r['fuel_type'];
        $fuel_upper = strtoupper($fuel_display);
        
        if (strpos($fuel_upper, 'TURBO') !== false && strpos($fuel_upper, 'DIESEL') !== false) {
            $fuel_normalized = 'Turbo Diesel';
        } elseif (strpos($fuel_upper, 'KEROSENE') !== false) {
            $fuel_normalized = 'Kerosene';
        } elseif (strpos($fuel_upper, 'XCS') !== false) {
            $fuel_normalized = 'XCS Plus';
        } elseif (strpos($fuel_upper, 'XTRA') !== false || strpos($fuel_upper, 'UNL') !== false) {
            $fuel_normalized = 'Xtra UNL';
        } elseif (strpos($fuel_upper, 'DIESEL') !== false) {
            $fuel_normalized = 'Diesel';
        } else {
            // Fallback: remove numbers and clean up
            $clean = preg_replace('/\s*\d+\s*-?\s*\d*\s*/', ' ', $fuel_display);
            $fuel_normalized = trim(preg_replace('/\s+/', ' ', $clean));
        }
        
        $rows_fmt[] = [
            date('Y-m-d', strtotime($r['transaction_date'])),
            formatShiftLabel($r['shift_period']),
            $fuel_normalized,
            $r['staff_name'] ?? '—',
            number_format($r['previous_reading'], 2),
            number_format($r['present_reading'], 2),
            number_format($r['staff_calibration'], 2) . ' L',
            number_format($r['calibration'], 2) . ' L',
            number_format($r['liters_sold'], 2) . ' L',
            getStatusLabel($r['status']),
            $r['validator_name'] ?? '—',
            $r['validated_at'] ? date('Y-m-d H:i', strtotime($r['validated_at'])) : '—'
        ];
    }
    $filename = 'calibration_review_' . $date_filter;

    if ($export === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"><style>table{border-collapse:collapse}th,td{border:1px solid #ddd;padding:7px}th{background:#002F6C;color:#fff;font-size:11px}</style></head><body>';
        echo '<h2>Calibration Review Report - ' . htmlspecialchars($date_filter) . '</h2>';
        echo '<p>Station: ' . htmlspecialchars(user_station_name()) . ' | Records: ' . count($rows_fmt) . '</p>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows_fmt as $row) {
            echo '<tr>';
            foreach ($row as $col) echo '<td>' . htmlspecialchars($col) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>'; exit;
    }

    if ($export === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $tbody = '';
        foreach ($rows_fmt as $row) {
            $tbody .= '<tr>';
            foreach ($row as $col) {
                $tbody .= '<td>' . htmlspecialchars($col) . '</td>';
            }
            $tbody .= '</tr>';
        }
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Calibration Review</title>
        <style>body{font-family:Arial,sans-serif;font-size:10px;padding:20px;color:#333;}
        .pbtn{margin-bottom:12px}@media print{.pbtn{display:none}}
        .hdr{border-bottom:3px solid #002F6C;margin-bottom:14px;padding-bottom:8px;display:flex;align-items:center;justify-content:between;}
        h1{color:#002F6C;font-size:16px;margin:0 0 4px;text-transform:uppercase;}
        table{width:100%;border-collapse:collapse;margin-top:10px;}
        th{background:#002F6C;color:#fff;padding:6px;font-size:8px;text-transform:uppercase;text-align:left;}
        td{padding:5px;border-bottom:1px solid #e2e8f0;font-size:8px;}
        tr:nth-child(even) td{background:#f8fafc}
        </style></head><body>';
        echo '<div class="pbtn"><button onclick="window.print()" style="background:#002F6C;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;font-weight:bold;">Print</button>
        <a href="javascript:history.back()" style="margin-left:8px;background:#6c757d;color:#fff;border:none;padding:8px 18px;border-radius:5px;cursor:pointer;text-decoration:none;font-weight:bold;">â† Back</a></div>';
        echo '<div class="hdr"><div><h1>Calibration Review</h1><p style="margin:2px 0 0;color:#666;">Date: ' . htmlspecialchars($date_filter) . ' | Station: ' . htmlspecialchars(user_station_name()) . '</p></div></div>';
        echo '<table><thead><tr>';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>' . ($tbody ?: '<tr><td colspan="' . count($headers) . '" style="text-align:center;padding:20px;color:#94a3b8">No records found.</td></tr>') . '</tbody></table>';
        echo '</body></html>'; exit;
    }
}

// ── AJAX JSON POLLING ENDPOINT FOR CALIBRATION REVIEW ─────────────────
if (isset($_GET['ajax_cr']) && $_GET['ajax_cr'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'kpis' => [
            'validated'   => number_format((float)$total_liters_validated, 2) . ' L',
            'calibration' => number_format((float)$total_calibration_liters, 2) . ' L',
            'pending'     => number_format($pending_reviews_count)
        ],
        'records_count' => count($records)
    ]);
    exit;
}

require_once __DIR__ . '/../partials/header.php'; require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
* { box-sizing: border-box; }
.mcr-wrap { max-width: 100%; width: 100%; box-sizing: border-box; overflow-x: hidden !important; padding: 0 !important; margin: 0 !important; }
.main-content { max-width: 100% !important; overflow-x: hidden !important; }

/* Petron style headers */
.int-head { display: flex !important; align-items: center !important; justify-content: space-between !important; flex-wrap: wrap !important; gap: 15px !important; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100% !important; }
.int-head h1 { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; font-size: 24px !important; font-weight: 700 !important; color: #002f70 !important; margin: 0 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.int-head .sub { font-size: 13px; color: #64748b; margin-top: 4px; line-height: 1.4; }

/* Summary Cards */
.mcr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.mcr-card { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 16px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.mcr-card-info { display: flex; flex-direction: column; }
.mcr-card-lbl { font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.mcr-card-val { font-size: 19px; font-weight: 700; color: #1e293b; }
.mcr-card-icon { font-size: 22px; opacity: 0.85; }
.mcr-card.blue .mcr-card-icon { color: #0ea5e9; }
.mcr-card.green .mcr-card-icon { color: #10b981; }
.mcr-card.amber .mcr-card-icon { color: #f59e0b; }

/* Filter Bar */
.mcr-filter { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
.mcr-fg { display: flex; flex-direction: column; gap: 3px; }
.mcr-fg label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
.mcr-fg input, .mcr-fg select { height: 36px; padding: 0 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px; color: #1e293b; background: #fff; outline: none; }
.mcr-fg input:focus, .mcr-fg select:focus { border-color: #002F70; box-shadow: 0 0 0 3px rgba(0,47,112,.1); }

/* Table design */
.mcr-table-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 11px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); width: 100%; }
.mcr-table-hd { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f1f5f9; }
.mcr-table-title { font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: .3px; margin: 0; }
.mcr-tbl-wrap { width: 100%; overflow-x: auto; }
.mcr-tbl { width: 100%; border-collapse: collapse; font-size: 11px; }
.mcr-tbl thead tr { background: #002F70; }
.mcr-tbl thead th { padding: 9px 10px; text-align: left; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
.mcr-tbl tbody tr { border-bottom: 1px solid #f1f5f9; }
.mcr-tbl tbody tr:hover td { background: #eff6ff; }
.mcr-tbl tbody td { padding: 9px 10px; color: #334155; vertical-align: middle; background: #fff; }

/* Buttons */
.ato-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 0 16px; border-radius: 7px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: all .15s; height: 36px; white-space: nowrap; background: white !important; }
.ato-btn-excel { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-excel:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-pdf { color: #00264D !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.ato-btn-pdf:hover { background: #f8fafc !important; border-color: #00264D !important; color: #00264D !important; }
.ato-btn-print { color: #334155 !important; border-color: #64748b !important; }
.ato-btn-print:hover { background: #64748b !important; color: #fff !important; }
.ato-btn-back { color: #4b5563 !important; border-color: #cbd5e1 !important; }
.ato-btn-back:hover { background: #cbd5e1 !important; }
.ato-btn-filter { color: #002F70 !important; border-color: #002F70 !important; }
.ato-btn-filter:hover { background: #002F70 !important; color: #fff !important; }
.ato-btn-reset { color: #475569 !important; border-color: #cbd5e1 !important; }
.ato-btn-reset:hover { background: #f1f5f9 !important; }

/* Row Actions — outlined style matching export buttons (white bg, colored border+text, hover fills) */
.act-btn { display: flex; align-items: center; justify-content: center; gap: 5px; padding: 0 10px; border-radius: 6px; font-size: 10.5px; font-weight: 700; border: 1.5px solid transparent; cursor: pointer; height: 28px; text-decoration: none; text-transform: uppercase; background: #ffffff !important; transition: all 0.15s; width: 100%; box-sizing: border-box; }
.act-btn-verify { border-color: #16a34a !important; color: #16a34a !important; background: #ffffff !important; }
.act-btn-verify:hover { background: #16a34a !important; color: #ffffff !important; }
.act-btn-edit { border-color: #0284c7 !important; color: #0284c7 !important; background: #ffffff !important; }
.act-btn-edit:hover { background: #0284c7 !important; color: #ffffff !important; }
.act-btn-reject { border-color: #dc2626 !important; color: #dc2626 !important; background: #ffffff !important; }
.act-btn-reject:hover { background: #dc2626 !important; color: #ffffff !important; }
.act-btn-view { border-color: #475569 !important; color: #475569 !important; background: #ffffff !important; }
.act-btn-view:hover { background: #475569 !important; color: #ffffff !important; }
.act-btn:disabled, .act-btn.disabled { opacity: 0.5; cursor: not-allowed; border-color: #cbd5e1 !important; color: #94a3b8 !important; background: #f1f5f9 !important; }

/* Status — plain colored text, no background fill */
.badge-st { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; background: none !important; padding: 0; border-radius: 0; }
.badge-st::before { content: ''; display: inline-block; width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
.badge-st.bg-amber { color: #b45309; }
.badge-st.bg-amber::before { background: #d97706; }
.badge-st.bg-green { color: #15803d; }
.badge-st.bg-green::before { background: #16a34a; }
.badge-st.bg-blue { color: #1d4ed8; }
.badge-st.bg-blue::before { background: #2563eb; }
.badge-st.bg-red { color: #b91c1c; }
.badge-st.bg-red::before { background: #dc2626; }
.badge-st.bg-gray { color: #475569; }
.badge-st.bg-gray::before { background: #64748b; }

/* Modal Window styles */
.modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15,23,42,0.55); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal-content { background: #fff; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); overflow: hidden; animation: modalIn 0.2s ease; }
@keyframes modalIn { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: none; } }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
.modal-header h3 { margin: 0; font-size: 14px; color: #00264D; font-weight: 700; text-transform: uppercase; }
.modal-body { padding: 20px; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; padding: 12px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; }

.modal-fg { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.modal-fg label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; }
.modal-fg input, .modal-fg textarea, .modal-fg select { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13.5px; color: #1e293b; background: #fff; outline: none; }
.modal-fg input:focus, .modal-fg textarea:focus { border-color: #002F70; }
.modal-fg input[readonly] { background-color: #f8fafc; color: #64748b; }

.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; }
.details-item { border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; }
.details-item.full-width { grid-column: span 2; }
.details-lbl { font-size: 10px; color: #64748b; text-transform: uppercase; font-weight: bold; }
.details-val { font-size: 12px; color: #1e293b; font-weight: 600; margin-top: 2px; }
</style>

<div class="mcr-wrap">
    <!-- Page Header -->
    <div class="int-head">
        <div>
            <h1><i class="fas fa-balance-scale"></i> Calibration Review</h1>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="mcr-cards">
        <div class="mcr-card blue">
            <div class="mcr-card-info">
                <span class="mcr-card-lbl">Total Validated Liters</span>
                <span class="mcr-card-val"><?= number_format($total_liters_validated, 2) ?> L</span>
            </div>
            <div class="mcr-card-icon"><i class="fas fa-gas-pump"></i></div>
        </div>
        <div class="mcr-card green">
            <div class="mcr-card-info">
                <span class="mcr-card-lbl">Total Calibration Liters</span>
                <span class="mcr-card-val"><?= number_format($total_calibration_liters, 2) ?> L</span>
            </div>
            <div class="mcr-card-icon"><i class="fas fa-tint"></i></div>
        </div>
        <div class="mcr-card amber">
            <div class="mcr-card-info">
                <span class="mcr-card-lbl">Pending Review Rows</span>
                <span class="mcr-card-val"><?= number_format($pending_reviews_count) ?></span>
            </div>
            <div class="mcr-card-icon"><i class="fas fa-clock"></i></div>
        </div>
    </div>

    <!-- Filters Form -->
    <form method="get" class="mcr-filter">
        <div class="mcr-fg">
            <label>Review Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($date_filter) ?>">
        </div>
        <div class="mcr-fg">
            <label>Shift</label>
            <select name="shift">
                <option value="all">All Shifts</option>
                <option value="first" <?= $shift_filter === 'first' ? 'selected' : '' ?>>Shift 1</option>
                <option value="second" <?= $shift_filter === 'second' ? 'selected' : '' ?>>Shift 2</option>
            </select>
        </div>
        <div class="mcr-fg">
            <label>Fuel Type</label>
            <select name="fuel_type">
                <option value="all">All Fuel Types</option>
                <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= htmlspecialchars($ft) ?>" <?= strtolower($fuel_type_filter) === strtolower($ft) ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="mcr-fg">
            <label>Staff Encoder</label>
            <input type="text" name="staff" value="<?= htmlspecialchars($staff_filter) ?>" placeholder="Staff name...">
        </div>
        <div style="display: flex; gap: 8px;">
            <button type="submit" class="ato-btn ato-btn-filter"><i class="fas fa-search"></i> Filter</button>
            <a href="manager_fuel_pump_master.php" class="ato-btn ato-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Calibration Review Table Card -->
    <div class="mcr-table-card">
        <div class="mcr-table-hd">
            <h3 class="mcr-table-title"><i class="fas fa-table"></i> Shift-Based Readings & Calibration Logs</h3>
        </div>
        <div class="mcr-tbl-wrap">
            <table class="mcr-tbl">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Shift</th>
                        <th>Fuel Type</th>
                        <th>Staff</th>
                        <th style="text-align:right;">Beginning</th>
                        <th style="text-align:right;">Ending</th>
                        <th style="text-align:right;">Staff Calibration</th>
                        <th style="text-align:right;">Manager Calibration</th>
                        <th style="text-align:center;">Status</th>
                        <th style="text-align:center; width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center;padding:40px;color:#94a3b8;">
                                <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                No calibration/readings records found for the selected filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $r): 
                            $status = strtolower($r['status'] ?? 'pending validation');
                            $tx_date = date('Y-m-d', strtotime($r['transaction_date']));
                            $preceding_ok = true;
                            
                            // Check sequence validation for active validation actions
                            if ($status === 'pending validation' && $r['pump_id'] > 0) {
                                $preceding_ok = is_preceding_shift_validated($pdo, $station_id, $r['pump_id'], $r['shift_period'], $tx_date);
                            }
                            
                            $shift_lbl = formatShiftLabel($r['shift_period']);
                        ?>
                            <tr>
                                <td><?= date('M d, Y', strtotime($r['transaction_date'])) ?></td>
                                <td><strong><?= htmlspecialchars($shift_lbl) ?></strong></td>
                                <td><?= htmlspecialchars(normalizeFuelType($r['fuel_type'])) ?></td>
                                <td><?= htmlspecialchars($r['staff_name']) ?></td>
                                <td style="text-align:right; font-family: monospace;"><?= number_format($r['previous_reading'], 2) ?></td>
                                <td style="text-align:right; font-family: monospace;"><?= number_format($r['present_reading'], 2) ?></td>
                                <td style="text-align:right; font-family: monospace; color: #475569;"><?= number_format($r['staff_calibration'], 2) ?> L</td>
                                <td style="text-align:right; font-family: monospace; font-weight: bold;"><?= number_format($r['calibration'], 2) ?> L</td>
                                <td style="text-align:center;">
                                    <span class="badge-st <?= getStatusBadgeClass($r['status']) ?>"><?= getStatusLabel($r['status']) ?></span>
                                </td>
                                <td style="text-align:center; padding: 8px 10px; vertical-align:middle;">
                                    <div style="display:flex; flex-direction:column; gap:5px; align-items:stretch; min-width:90px;">
                                    <?php if ($status === 'pending validation'): ?>
                                        <?php if ($preceding_ok): ?>
                                            <!-- Verify -->
                                            <form method="post" style="margin:0;" onsubmit="return confirm('Verify and approve this entry? Beginning reading will match preceding shift ending.');">
                                                <input type="hidden" name="action" value="verify">
                                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="act-btn act-btn-verify" title="Verify Readings"><i class="fas fa-check"></i> Verify</button>
                                            </form>
                                            <!-- Edit/Adjust -->
                                            <button class="act-btn act-btn-edit" onclick="openAdjustModal(<?= htmlspecialchars(json_encode([
                                                'id' => $r['id'],
                                                'txn_id' => $r['transaction_id'],
                                                'pump' => $r['pump_number'] ?? '—',
                                                'fuel_type' => $r['fuel_type'],
                                                'beginning' => get_preceding_shift_validated_ending($pdo, $station_id, $r['pump_id'], $r['shift_period'], $tx_date),
                                                'ending' => $r['present_reading'],
                                                'calibration' => $r['calibration'],
                                                'price' => $r['price_per_liter']
                                            ])) ?>)" title="Adjust Readings"><i class="fas fa-edit"></i> Edit</button>
                                            <!-- Reject -->
                                            <button class="act-btn act-btn-reject" onclick="openRejectModal(<?= $r['id'] ?>, '<?= $r['transaction_id'] ?>')" title="Reject"><i class="fas fa-times"></i> Reject</button>
                                        <?php else: ?>
                                            <?php 
                                                $preceding = get_preceding_shift_and_date($pdo, $r['shift_period'], $tx_date);
                                                $prec_lbl = $preceding ? (formatShiftLabel($preceding['shift_key']) . ' on ' . $preceding['date']) : 'Shift 1';
                                            ?>
                                            <button class="act-btn disabled" disabled title="Waiting for preceding shift (<?= $prec_lbl ?>) validation"><i class="fas fa-lock"></i> Locked</button>
                                            <span style="font-size:9px; color:#dc2626; text-align:center; display:block;">âš ï¸ Check preceding shift</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <button class="act-btn act-btn-view" onclick="openViewModal(<?= htmlspecialchars(json_encode([
                                            'txn_id' => $r['transaction_id'],
                                            'pump' => $r['pump_number'] ?? '—',
                                            'fuel_type' => $r['fuel_type'],
                                            'beginning' => number_format($r['previous_reading'], 2),
                                            'ending' => number_format($r['present_reading'], 2),
                                            'staff_cal' => number_format($r['staff_calibration'], 2) . ' L',
                                            'mgr_cal' => number_format($r['calibration'], 2) . ' L',
                                            'liters_sold' => number_format($r['liters_sold'], 2) . ' L',
                                            'total_amount' => '₱' . number_format($r['total_amount'], 2),
                                            'staff' => $r['staff_name'],
                                            'status' => getStatusLabel($r['status']),
                                            'validator' => $r['validator_name'],
                                            'validated_at' => $r['validated_at'] ? date('M d, Y h:i A', strtotime($r['validated_at'])) : '—',
                                            'remarks' => $r['reject_reason'] ?: '—'
                                        ])) ?>)"><i class="fas fa-eye"></i> View</button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Calibration Review Pagination Footer -->
        <div id="mcrPaginationFooter" style="display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-top:1px solid #e2e8f0; background:#ffffff; border-radius:0 0 12px 12px; font-size:13px; color:#475569; flex-wrap:wrap; gap:12px;">
            <div style="display:flex; align-items:center;">
                <span id="mcrShowingEntriesText" style="font-size:13px; color:#64748b; font-weight:600;">Showing <?= empty($records) ? '0' : '1–'.min(10, count($records)) ?> of <?= count($records) ?> entries</span>
            </div>
            <div style="display:flex; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="margin:0; font-weight:600; color:#64748b; font-size:13px;">Rows per page:</label>
                    <select id="mcrPerPage" onchange="mcrChangePerPage()" style="padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-weight:600; background:transparent !important; color:#334155; outline:none; cursor:pointer;">
                        <option value="10" selected>10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <button id="mcrPrevBtn" onclick="mcrGoPage(mcrState.page - 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:not-allowed; color:#cbd5e1; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <span id="mcrPageLabel" style="color:#334155; font-size:13px; font-weight:600; padding:0 4px;">Page 1 of <?= max(1, ceil(count($records) / 10)) ?></span>
                    <button id="mcrNextBtn" onclick="mcrGoPage(mcrState.page + 1)" 
                            style="width:32px; height:32px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; cursor:<?= count($records) > 10 ? 'pointer' : 'not-allowed' ?>; color:<?= count($records) > 10 ? '#475569' : '#cbd5e1' ?>; display:flex; align-items:center; justify-content:center; transition: all 0.2s;"
                            onmouseover="if(!this.disabled) this.style.backgroundColor='#f1f5f9';" onmouseout="this.style.backgroundColor='#fff';">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Adjust / Edit Modal -->
<div id="adjustModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="id" id="adj_tx_id">
            
            <div class="modal-header">
                <h3>Adjust Calibration & Readings</h3>
                <button type="button" onclick="closeModal('adjustModal')" style="border:none;background:none;font-size:20px;cursor:pointer;">&times;</button>
            </div>
            
            <div class="modal-body">
                <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 10px; font-size: 11px; color: #1e3a8a; margin-bottom: 14px;">
                    <i class="fas fa-info-circle"></i> <strong>Sequence Rules:</strong> The beginning reading is programmatically set to match the validated ending reading of the preceding shift to maintain a seamless audit trail.
                </div>
                
                <div class="details-grid" style="margin-bottom: 12px;">
                    <div>
                        <span class="details-lbl">Fuel Line</span>
                        <div class="details-val" id="adj_lbl_pump"></div>
                    </div>
                    <div>
                        <span class="details-lbl">Fuel Type</span>
                        <div class="details-val" id="adj_lbl_fuel"></div>
                    </div>
                </div>

                <div class="modal-fg">
                    <label>Beginning Reading (Preceding Ending)</label>
                    <input type="number" step="0.01" id="adj_beginning" name="beginning" readonly>
                </div>
                <div class="modal-fg">
                    <label>Ending Reading</label>
                    <input type="number" step="0.01" id="adj_ending" name="ending" required oninput="calculateAdjustedLiters()">
                </div>
                <div class="modal-fg">
                    <label>Manager Calibration (Liters)</label>
                    <input type="number" step="0.1" id="adj_calibration" name="calibration" required oninput="calculateAdjustedLiters()">
                </div>
                
                <div class="modal-fg" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; margin-top: 10px;">
                    <span class="details-lbl">Calculated Liters Sold</span>
                    <div id="adj_calculated_liters" style="font-size: 16px; font-weight: 700; color: #00264D; margin-top: 2px;">0.00 L</div>
                </div>
                
                <div class="modal-fg" style="margin-top: 10px;">
                    <label>Reason for Adjustment</label>
                    <textarea name="remarks" rows="3" required placeholder="Provide clear reason for this override..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('adjustModal')">Cancel</button>
                <button type="submit" class="ato-btn ato-btn-filter" style="background: #002F70 !important; color: white !important;"><i class="fas fa-save"></i> Save Adjustment</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" id="reject_tx_id">
            
            <div class="modal-header">
                <h3>Reject Reading Entry</h3>
                <button type="button" onclick="closeModal('rejectModal')" style="border:none;background:none;font-size:20px;cursor:pointer;">&times;</button>
            </div>
            
            <div class="modal-body">
                <p style="font-size: 12.5px; color: #475569; margin-bottom: 12px;">
                    Are you sure you want to reject transaction <strong id="reject_lbl_txn"></strong>? It will be returned to the staff for re-encoding.
                </p>
                <div class="modal-fg">
                    <label>Reason for Rejection</label>
                    <textarea name="remarks" rows="3" required placeholder="Specify why the meter reading/calibration was rejected..."></textarea>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('rejectModal')">Cancel</button>
                <button type="submit" class="ato-btn ato-btn-pdf" style="background: #dc2626 !important; color: white !important;"><i class="fas fa-times"></i> Reject Entry</button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Calibration Entry Audit Details</h3>
        </div>
        <div class="modal-body">
            <div class="details-grid">
                <div class="details-item">
                    <div class="details-lbl">Transaction ID</div>
                    <div class="details-val" id="view_txn_id"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Line (Pump)</div>
                    <div class="details-val" id="view_pump"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Fuel Type</div>
                    <div class="details-val" id="view_fuel_type"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Encoder</div>
                    <div class="details-val" id="view_staff"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Beginning Reading</div>
                    <div class="details-val" id="view_beginning"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Ending Reading</div>
                    <div class="details-val" id="view_ending"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Staff Calibration</div>
                    <div class="details-val" id="view_staff_cal"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Manager Calibration</div>
                    <div class="details-val" id="view_mgr_cal" style="font-weight:700;"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Liters Sold</div>
                    <div class="details-val" id="view_liters_sold"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Total Amount</div>
                    <div class="details-val" id="view_total_amount"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Review Status</div>
                    <div class="details-val" id="view_status"></div>
                </div>
                <div class="details-item">
                    <div class="details-lbl">Validator Manager</div>
                    <div class="details-val" id="view_validator"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Validation Timestamp</div>
                    <div class="details-val" id="view_validated_at"></div>
                </div>
                <div class="details-item full-width">
                    <div class="details-lbl">Manager Notes / Reason</div>
                    <div class="details-val" id="view_remarks" style="white-space:pre-wrap;font-weight:normal;color:#475569;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="ato-btn ato-btn-reset" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
let activePrice = 0.00;

function openAdjustModal(data) {
    document.getElementById('adj_tx_id').value = data.id;
    document.getElementById('adj_lbl_pump').innerText = data.pump;
    document.getElementById('adj_lbl_fuel').innerText = data.fuel_type;
    document.getElementById('adj_beginning').value = parseFloat(data.beginning).toFixed(2);
    document.getElementById('adj_ending').value = parseFloat(data.ending).toFixed(2);
    document.getElementById('adj_calibration').value = parseFloat(data.calibration).toFixed(2);
    activePrice = parseFloat(data.price || 0);
    calculateAdjustedLiters();
    document.getElementById('adjustModal').style.display = 'flex';
}

function calculateAdjustedLiters() {
    const beginning = parseFloat(document.getElementById('adj_beginning').value) || 0;
    const ending = parseFloat(document.getElementById('adj_ending').value) || 0;
    const calibration = parseFloat(document.getElementById('adj_calibration').value) || 0;
    const liters = Math.max(0, ending - beginning - calibration);
    document.getElementById('adj_calculated_liters').innerText = liters.toFixed(2) + ' L';
}

function openRejectModal(id, txnId) {
    document.getElementById('reject_tx_id').value = id;
    document.getElementById('reject_lbl_txn').innerText = txnId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function openViewModal(data) {
    document.getElementById('view_txn_id').innerText = data.txn_id;
    document.getElementById('view_pump').innerText = data.pump;
    document.getElementById('view_fuel_type').innerText = data.fuel_type;
    document.getElementById('view_staff').innerText = data.staff;
    document.getElementById('view_beginning').innerText = data.beginning;
    document.getElementById('view_ending').innerText = data.ending;
    document.getElementById('view_staff_cal').innerText = data.staff_cal;
    document.getElementById('view_mgr_cal').innerText = data.mgr_cal;
    document.getElementById('view_liters_sold').innerText = data.liters_sold;
    document.getElementById('view_total_amount').innerText = data.total_amount;
    document.getElementById('view_status').innerText = data.status;
    document.getElementById('view_validator').innerText = data.validator;
    document.getElementById('view_validated_at').innerText = data.validated_at;
    document.getElementById('view_remarks').innerText = data.remarks;
    document.getElementById('viewModal').style.display = 'flex';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// ── Calibration Review Pagination ──
var mcrState = { page: 1, per_page: 10 };

function mcrRender() {
    const tableBody = document.querySelector('.mcr-tbl tbody');
    if (!tableBody) return;

    const allRows = Array.from(tableBody.querySelectorAll('tr'));
    const validRows = allRows.filter(r => !r.querySelector('.fa-inbox'));
    const tot = validRows.length;
    const pp = mcrState.per_page || 10;
    const tp = Math.max(1, Math.ceil(tot / pp));

    if (mcrState.page > tp) mcrState.page = tp;
    if (mcrState.page < 1) mcrState.page = 1;
    const p = mcrState.page;

    const start = (p - 1) * pp;
    const end   = p * pp;

    validRows.forEach(function(r, i) {
        r.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update text counter
    const showingStart = tot === 0 ? 0 : start + 1;
    const showingEnd   = Math.min(end, tot);
    const entriesLbl   = document.getElementById('mcrShowingEntriesText');
    if (entriesLbl) {
        entriesLbl.textContent = 'Showing ' + (tot === 0 ? '0' : showingStart + '–' + showingEnd) + ' of ' + tot + ' entries';
    }

    const lbl = document.getElementById('mcrPageLabel');
    if (lbl) lbl.textContent = 'Page ' + p + ' of ' + tp;

    const prev = document.getElementById('mcrPrevBtn');
    const next = document.getElementById('mcrNextBtn');
    if (prev) {
        prev.disabled = (p <= 1);
        prev.style.cursor = prev.disabled ? 'not-allowed' : 'pointer';
        prev.style.color = prev.disabled ? '#cbd5e1' : '#475569';
    }
    if (next) {
        next.disabled = (p >= tp);
        next.style.cursor = next.disabled ? 'not-allowed' : 'pointer';
        next.style.color = next.disabled ? '#cbd5e1' : '#475569';
    }
}

window.mcrState = mcrState;
window.mcrGoPage = function(p) {
    const tableBody = document.querySelector('.mcr-tbl tbody');
    if (!tableBody) return;
    const validRows = Array.from(tableBody.querySelectorAll('tr')).filter(r => !r.querySelector('.fa-inbox'));
    const tp = Math.max(1, Math.ceil(validRows.length / (mcrState.per_page || 10)));
    if (p < 1 || p > tp) return;
    mcrState.page = p;
    mcrRender();
};

window.mcrChangePerPage = function() {
    const s = document.getElementById('mcrPerPage');
    if (s) mcrState.per_page = parseInt(s.value, 10);
    mcrState.page = 1;
    mcrRender();
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mcrRender);
} else {
    mcrRender();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
