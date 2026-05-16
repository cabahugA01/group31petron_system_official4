<?php
/**
 * Fuel Readings API
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Start session exactly the same way login.php does
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

// Clean any stray output
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$me         = null;
$role       = '';
$station_id = null;

// Path 1: session cookie — login.php stores $_SESSION['user']
if (!empty($_SESSION['user'])) {
    $me         = $_SESSION['user'];
    $role       = role_key($me['role'] ?? '');
    $station_id = $me['station_id'] ?? null;
    try {
        $s = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([$me['id']]);
        $sid = $s->fetchColumn();
        if ($sid !== false) $station_id = $sid;
    } catch (Exception $e) {}
}

// Path 2: auth_user_id in POST — DB lookup, no session needed.
if (!$me) {
    $posted_uid = (int)($_POST['auth_user_id'] ?? $_GET['auth_user_id'] ?? 0);
    if ($posted_uid > 0) {
        try {
            $u = $pdo->prepare("SELECT * FROM users WHERE id = ? AND (status = 'active' OR status IS NULL) LIMIT 1");
            $u->execute([$posted_uid]);
            $db_user = $u->fetch(PDO::FETCH_ASSOC);
            if ($db_user) {
                unset($db_user['password']);
                $me         = $db_user;
                $role       = role_key($me['role'] ?? '');
                $station_id = $me['station_id'] ?? null;
                try {
                    $sid_stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ? LIMIT 1");
                    $sid_stmt->execute([$me['id']]);
                    $sid_val = $sid_stmt->fetchColumn();
                    if ($sid_val !== false) $station_id = $sid_val;
                } catch (Exception $e) {}
                $_SESSION['user']    = $me;
                $_SESSION['user_id'] = $me['id'];
                $_SESSION['role']    = $me['role'];
            }
        } catch (Exception $e) { /* fall through */ }
    }
}

if (!$me) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Schema migration ──────────────────────────────────────────
try {
    $existing = array_column(
        $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    $add = [
        'shift_period'  => "VARCHAR(50)  NULL",
        'shift_name'    => "VARCHAR(100) NULL",
        'shift_id'      => "INT          NULL",
        'notes'         => "TEXT         NULL",
        'status'        => "VARCHAR(50)  NULL DEFAULT 'Pending Validation'",
        'validated_by'  => "INT          NULL",
        'validated_at'  => "DATETIME     NULL",
        'reject_reason' => "TEXT         NULL",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $existing)) {
            $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$col` $def");
        }
    }
    try {
        $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `status` VARCHAR(50) NULL DEFAULT 'Pending Validation'");
    } catch (Exception $e2) {}
} catch (Exception $e) {}

// ── Router ────────────────────────────────────────────────────
try {
    switch ($action) {

        case 'encode_reading':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['staff', 'cashier', 'pump_attendant']))
                respond(false, 'Only staff can encode readings.');

            $fuel_type    = trim($_POST['fuel_type']      ?? '');
            $present      = (float)($_POST['present_reading']  ?? 0);
            $notes        = trim($_POST['notes'] ?? '');
            $reading_date = $_POST['reading_date']  ?? date('Y-m-d');
            $shift_period = trim($_POST['shift_period'] ?? '');
            $shift_name   = trim($_POST['shift_name']   ?? '');
            $shift_id     = (int)($_POST['shift_id'] ?? 0) ?: null;

            if (empty($fuel_type)) respond(false, 'Fuel type is required.');
            if ($present  <= 0)   respond(false, 'Present reading must be greater than 0.');

            // ── Re-pull previous reading from DB (last present_reading, any status) ──
            $previous = 0.0;
            try {
                $prev_stmt = $pdo->prepare("
                    SELECT present_reading FROM fuel_transactions
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ORDER BY transaction_date DESC LIMIT 1
                ");
                $prev_stmt->execute([$station_id, $fuel_type]);
                $row_prev = $prev_stmt->fetchColumn();
                if ($row_prev !== false) $previous = (float)$row_prev;
            } catch (Exception $e) {}

            // ── Re-pull calibration from DB ──
            // Priority: fuel_calibration table (technician record) → fuel_inventory.latest_calibration
            // Staff may override by editing the calibration input
            $calibration = 0.0;
            try {
                // Use a safe string comparison — avoid ENUM mismatch by casting to CHAR
                $cal_stmt = $pdo->prepare("
                    SELECT calibration_constant FROM fuel_calibration
                    WHERE LOWER(TRIM(CAST(fuel_type AS CHAR))) = LOWER(TRIM(?))
                      AND LOWER(TRIM(status)) = 'active'
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                ");
                $cal_stmt->execute([$fuel_type]);
                $cal_row = $cal_stmt->fetchColumn();
                if ($cal_row !== false && $cal_row !== null) {
                    $calibration = (float)$cal_row;
                }
            } catch (Exception $e) {}

            if ($calibration == 0.0) {
                // Fallback: fuel_inventory.latest_calibration
                try {
                    $cal2 = $pdo->prepare("
                        SELECT COALESCE(latest_calibration, 0)
                        FROM fuel_inventory
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ");
                    $cal2->execute([$station_id, $fuel_type]);
                    $calibration = (float)($cal2->fetchColumn() ?: 0);
                } catch (Exception $e) {}
            }

            // Staff override: if they edited the calibration field, use that value
            if (isset($_POST['calibration']) && $_POST['calibration'] !== '') {
                $calibration = (float)$_POST['calibration'];
            }

            // ── Re-pull price from DB ─────────────────────────────────────────
            // Priority 1: fuel_inventory for this station (most reliable — has correct prices)
            // Priority 2: fuel_pricing JOIN fuel_types (fallback)
            $price = 0.0;
            try {
                $pr_inv = $pdo->prepare("
                    SELECT COALESCE(price_per_liter, 0)
                    FROM fuel_inventory
                    WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    LIMIT 1
                ");
                $pr_inv->execute([$station_id, $fuel_type]);
                $price = (float)($pr_inv->fetchColumn() ?: 0);
            } catch (Exception $e) {}

            if ($price <= 0) {
                // Fallback: fuel_pricing joined with fuel_types
                try {
                    $pr_stmt = $pdo->prepare("
                        SELECT fp.price_per_liter
                        FROM fuel_pricing fp
                        INNER JOIN fuel_types ftt ON ftt.id = fp.fuel_type_id
                        WHERE fp.station_id = ?
                          AND LOWER(TRIM(ftt.name)) = LOWER(TRIM(?))
                          AND fp.is_active = 1
                        ORDER BY fp.effective_date DESC, fp.id DESC LIMIT 1
                    ");
                    $pr_stmt->execute([$station_id, $fuel_type]);
                    $price = (float)($pr_stmt->fetchColumn() ?: 0);
                } catch (Exception $e) {}
            }

            if ($price <= 0) {
                // Fallback 3: try fuel_pricing without JOIN (direct fuel_type name match)
                try {
                    $pr_stmt3 = $pdo->prepare("
                        SELECT fp.price_per_liter
                        FROM fuel_pricing fp
                        WHERE fp.station_id = ?
                          AND LOWER(TRIM(fp.fuel_type)) = LOWER(TRIM(?))
                          AND fp.is_active = 1
                        ORDER BY fp.effective_date DESC, fp.id DESC LIMIT 1
                    ");
                    $pr_stmt3->execute([$station_id, $fuel_type]);
                    $price = (float)($pr_stmt3->fetchColumn() ?: 0);
                } catch (Exception $e) {}
            }

            // ── Price = 0: allow submission, record reading with ₱0 amount ──
            // Staff can still record meter readings even if price isn't configured yet.
            // Manager can adjust/approve with correct price later.
            // Do NOT block the submission — just record with 0 amount.
            $price_missing = ($price <= 0);
            if ($price_missing) $price = 0.0;

            // ── Detect shift from DB if not posted ──
            if (empty($shift_period)) {
                try {
                    $ct = date('H:i:s');
                    $sp_stmt = $pdo->prepare("
                        SELECT shift_key, shift_name FROM shift_periods
                        WHERE is_active = 1 AND start_time <= ? AND end_time >= ?
                        ORDER BY sort_order ASC LIMIT 1
                    ");
                    $sp_stmt->execute([$ct, $ct]);
                    $sp_row = $sp_stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sp_row) {
                        $sp_stmt2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC LIMIT 1");
                        $sp_row = $sp_stmt2 ? $sp_stmt2->fetch(PDO::FETCH_ASSOC) : null;
                    }
                    if ($sp_row) {
                        $shift_period = $sp_row['shift_key'];
                        if (empty($shift_name)) $shift_name = $sp_row['shift_name'];
                    }
                } catch (Exception $e) {}
            }
            // Final fallback — shift_period is NOT NULL in DB
            if (empty($shift_period)) $shift_period = 'general';

            // ── Validate: present must be >= previous ──
            if ($present < $previous) {
                respond(false, "Present reading ({$present}) cannot be less than previous reading ({$previous}).");
            }

            // ── Compute: Volume = (Present − Previous) ± Calibration ──
            // Calibration can be positive (correction reduces volume) or negative (adds volume).
            // Volume is clamped to 0 — a zero-liter reading is valid (no sales this shift).
            $diff        = round($present - $previous, 4);
            $liters_sold = round(max(0.0, $diff - $calibration), 4);
            $total_amount = round($liters_sold * $price, 2);

            // ── Generate transaction ID ──
            $txn_id = 'FUEL' . date('Y')
                    . str_pad($station_id, 3, '0', STR_PAD_LEFT)
                    . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // ── Ensure required columns exist and are wide enough ────────────
            try {
                $cols = array_column(
                    $pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC),
                    'Field'
                );
                foreach (['shift_period','shift_name','shift_id','notes','status'] as $rc) {
                    if (!in_array($rc, $cols)) {
                        $def = ($rc === 'status') ? "VARCHAR(50) NULL DEFAULT 'Pending Validation'" : "TEXT NULL";
                        $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$rc` $def");
                    }
                }
                // Widen shift_period if it's still varchar(20) — shift keys can be longer
                $sp_col = $pdo->query("SHOW COLUMNS FROM fuel_transactions WHERE Field='shift_period'")->fetch(PDO::FETCH_ASSOC);
                if ($sp_col && preg_match('/varchar\((\d+)\)/i', $sp_col['Type'], $m) && (int)$m[1] < 50) {
                    $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `shift_period` VARCHAR(50) NULL");
                }
                // Widen payment_method if needed
                $pm_col = $pdo->query("SHOW COLUMNS FROM fuel_transactions WHERE Field='payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pm_col && preg_match('/varchar\((\d+)\)/i', $pm_col['Type'], $m) && (int)$m[1] < 50) {
                    $pdo->exec("ALTER TABLE fuel_transactions MODIFY COLUMN `payment_method` VARCHAR(50) NULL");
                }
            } catch (Exception $e) {}

            // ── Insert transaction ──
            // Ensure NOT NULL columns have fallback values
            $shift_period_safe   = substr(!empty($shift_period) ? $shift_period : 'general', 0, 50);
            $shift_name_safe     = substr($shift_name ?? '', 0, 100);
            $payment_method_safe = 'Internal';

            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO fuel_transactions
                        (transaction_id, station_id, fuel_type,
                         present_reading, previous_reading, calibration,
                         liters_sold, price_per_liter, total_amount,
                         payment_method, staff_id, transaction_date,
                         shift_period, shift_name, shift_id, notes, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending Validation')
                ")->execute([
                    $txn_id, $station_id, $fuel_type,
                    $present, $previous, $calibration,
                    $liters_sold, $price, $total_amount,
                    $payment_method_safe, $me['id'], $reading_date . ' ' . date('H:i:s'),
                    $shift_period_safe, $shift_name_safe, $shift_id, $notes,
                ]);

                try {
                    $pdo->prepare("
                        UPDATE fuel_inventory SET last_updated = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ")->execute([$station_id, $fuel_type]);
                } catch (Exception $e) {}

                $pdo->commit();
            } catch (Exception $insertEx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                respond(false, 'Database error saving reading: ' . $insertEx->getMessage());
            }

            try {
                log_activity($pdo, $me['id'], 'Fuel Reading Encoded',
                    "{$fuel_type}: Present={$present}, Prev={$previous}, Calib={$calibration}, Liters={$liters_sold}, Amount=₱{$total_amount}");
                // Notify managers
                if (function_exists('create_role_notification')) {
                    $staff_name = $me['name'] ?? $me['username'] ?? 'Staff';
                    create_role_notification($pdo, 'manager', 'info', 'New Fuel Transaction', "{$staff_name} submitted a new fuel transaction ({$txn_id}) pending validation.");
                }
            } catch (Exception $e) {}

            respond(true, 'Entry submitted successfully. Pending Manager validation.', [
                'transaction_id'  => $txn_id,
                'previous_reading'=> $previous,
                'calibration'     => $calibration,
                'liters_sold'     => $liters_sold,
                'price_per_liter' => $price,
                'total_amount'    => $total_amount,
                'price_missing'   => $price_missing ?? false,
            ]);
            break;

        case 'get_pending':
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');
            $date  = $_GET['date']  ?? date('Y-m-d');
            $shift = $_GET['shift'] ?? '';
            $sql   = "SELECT ft.*, u.name AS staff_name FROM fuel_transactions ft
                      LEFT JOIN users u ON ft.staff_id=u.id
                      WHERE ft.station_id=? AND DATE(ft.transaction_date)=? AND ft.status='Pending Validation'";
            $params = [$station_id, $date];
            if ($shift) { $sql .= " AND ft.shift_period=?"; $params[] = $shift; }
            $sql .= " ORDER BY ft.transaction_date ASC";
            $rows = $pdo->prepare($sql);
            $rows->execute($params);
            respond(true, '', ['readings' => $rows->fetchAll(PDO::FETCH_ASSOC)]);

        case 'validate_reading':
            if ($method !== 'POST') respond(false, 'Method not allowed.');
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');
            $txn_id        = trim($_POST['transaction_id'] ?? '');
            $new_status    = $_POST['status'] ?? '';
            $reject_reason = trim($_POST['reject_reason'] ?? '');
            if (!in_array($new_status, ['Approved','Rejected'])) respond(false, 'Status must be Approved or Rejected.');
            if ($new_status === 'Rejected' && empty($reject_reason)) respond(false, 'Rejection reason is required.');
            $row = $pdo->prepare("SELECT * FROM fuel_transactions WHERE transaction_id=? AND station_id=? AND status='Pending Validation'");
            $row->execute([$txn_id, $station_id]);
            $txn = $row->fetch(PDO::FETCH_ASSOC);
            if (!$txn) respond(false, 'Transaction not found or already processed.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE fuel_transactions SET status=?,validated_by=?,validated_at=NOW(),reject_reason=? WHERE transaction_id=? AND station_id=?")
                ->execute([$new_status, $me['id'], $reject_reason ?: null, $txn_id, $station_id]);
            if ($new_status === 'Approved') {
                try {
                    $pdo->prepare("
                        UPDATE fuel_inventory
                        SET current_level = GREATEST(0, COALESCE(current_level, current_stock, 0) - ?),
                            current_stock = GREATEST(0, COALESCE(current_stock, current_level, 0) - ?),
                            last_updated  = NOW()
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                    ")->execute([$txn['liters_sold'], $txn['liters_sold'], $station_id, $txn['fuel_type']]);
                } catch (Exception $e) {}

                // Audit trail entry
                try {
                    $pdo->prepare("
                        INSERT INTO fuel_adjustments
                            (station_id, fuel_type_id, adjustment_type, liters, reason, user_id, adjustment_date)
                        SELECT ?, fuel_type_id, 'verified_sale', ?, ?, ?, CURDATE()
                        FROM fuel_inventory
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        LIMIT 1
                    ")->execute([
                        $station_id,
                        -abs($txn['liters_sold']),
                        "Approved via API. TXN {$txn_id}. {$txn['fuel_type']}: {$txn['liters_sold']} L",
                        $me['id'],
                        $station_id,
                        $txn['fuel_type'],
                    ]);
                } catch (Exception $e) {}
            }
            $pdo->commit();
            log_activity($pdo, $me['id'], "Fuel Reading {$new_status}", "TXN {$txn_id} | {$txn['fuel_type']} | {$txn['liters_sold']} L");
            if ($new_status === 'Approved') {
                respond(true, "Transaction approved successfully. Entry saved to Daily Sales Summary.");
            } else {
                respond(true, "Reading {$new_status}.");
            }

        case 'my_entries':
            $date = $_GET['date'] ?? date('Y-m-d');
            $rows = $pdo->prepare("SELECT transaction_id,fuel_type,present_reading,previous_reading,calibration,liters_sold,price_per_liter,total_amount,shift_period,shift_name,status,transaction_date,notes FROM fuel_transactions WHERE station_id=? AND staff_id=? AND DATE(transaction_date)=? ORDER BY transaction_date DESC");
            $rows->execute([$station_id, $me['id'], $date]);
            respond(true, '', ['entries' => $rows->fetchAll(PDO::FETCH_ASSOC)]);

        // ══════════════════════════════════════════════════════════════════════
        // SUMMARY: 3-table fuel sales summary (manager + staff view)
        // Returns: meter_readings, vol_sales_summary, vol_amt_summary
        // ══════════════════════════════════════════════════════════════════════
        case 'summary':
            $date_from   = $_GET['date_from']   ?? date('Y-m-d');
            $date_to     = $_GET['date_to']     ?? date('Y-m-d');
            $shift       = $_GET['shift']       ?? '';
            $fuel_type   = $_GET['fuel_type']   ?? '';
            $staff_id    = $_GET['staff_id']    ?? '';
            $status      = $_GET['status']      ?? '';

            // Validate dates
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

            // Staff can only see their own entries; managers see all
            $is_manager = in_array($role, ['manager', 'admin', 'superadmin']);

            $base_where  = "ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?";
            $base_params = [$station_id, $date_from, $date_to];

            // Staff: see ALL their own entries (pending + approved) so they can
            //        track what they submitted. Table A is their raw meter log.
            // Manager: see all entries for the station across all staff.
            if (!$is_manager) {
                $base_where   .= " AND ft.staff_id = ?";
                $base_params[] = $me['id'];
            }

            // Additional filters
            if ($shift) {
                $base_where   .= " AND ft.shift_period = ?";
                $base_params[] = $shift;
            }
            if ($fuel_type) {
                $base_where   .= " AND LOWER(TRIM(ft.fuel_type)) = LOWER(TRIM(?))";
                $base_params[] = $fuel_type;
            }
            if ($staff_id && $is_manager) {
                $base_where   .= " AND ft.staff_id = ?";
                $base_params[] = (int)$staff_id;
            }
            if ($status) {
                $base_where   .= " AND LOWER(ft.status) = LOWER(?)";
                $base_params[] = $status;
            }

            // ── TABLE 1: Meter Reading Table (raw per-transaction rows) ──────
            $mr_sql = "
                SELECT
                    ft.transaction_id,
                    ft.fuel_type,
                    ft.previous_reading   AS beginning,
                    ft.present_reading    AS ending,
                    ft.calibration        AS cal,
                    ft.liters_sold        AS volume_liters,
                    ft.price_per_liter,
                    ft.total_amount       AS amount,
                    ft.shift_period,
                    ft.shift_name,
                    DATE(ft.transaction_date) AS reading_date,
                    u.name                AS staff_name,
                    ft.status,
                    ft.validated_at,
                    vm.name               AS validated_by_name
                FROM fuel_transactions ft
                LEFT JOIN users u  ON ft.staff_id    = u.id
                LEFT JOIN users vm ON ft.validated_by = vm.id
                WHERE {$base_where}
                ORDER BY ft.fuel_type ASC, ft.transaction_date ASC
            ";
            $mr_stmt = $pdo->prepare($mr_sql);
            $mr_stmt->execute($base_params);
            $meter_readings = $mr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── TABLE 2: Volume Sales Summary (liters per fuel type) ─────────
            $vs_sql = "
                SELECT
                    ft.fuel_type,
                    SUM(ft.liters_sold) AS volume_sales
                FROM fuel_transactions ft
                WHERE {$base_where}
                GROUP BY ft.fuel_type
                ORDER BY ft.fuel_type ASC
            ";
            $vs_stmt = $pdo->prepare($vs_sql);
            $vs_stmt->execute($base_params);
            $vol_sales_summary = $vs_stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── TABLE 3: Volume & Amount Summary (liters + amount per fuel type) ──
            $va_sql = "
                SELECT
                    ft.fuel_type,
                    SUM(ft.liters_sold)  AS volume_sales,
                    SUM(ft.total_amount) AS amount_sales
                FROM fuel_transactions ft
                WHERE {$base_where}
                GROUP BY ft.fuel_type
                ORDER BY ft.fuel_type ASC
            ";
            $va_stmt = $pdo->prepare($va_sql);
            $va_stmt->execute($base_params);
            $vol_amt_summary = $va_stmt->fetchAll(PDO::FETCH_ASSOC);

            // ── Totals ────────────────────────────────────────────────────────
            $total_liters = array_sum(array_column($vol_amt_summary, 'volume_sales'));
            $total_amount = array_sum(array_column($vol_amt_summary, 'amount_sales'));

            respond(true, '', [
                'meter_readings'    => $meter_readings,
                'vol_sales_summary' => $vol_sales_summary,
                'vol_amt_summary'   => $vol_amt_summary,
                'totals'            => [
                    'total_liters' => round($total_liters, 2),
                    'total_amount' => round($total_amount, 2),
                ],
                'filters' => [
                    'date_from' => $date_from,
                    'date_to'   => $date_to,
                    'shift'     => $shift,
                    'fuel_type' => $fuel_type,
                    'staff_id'  => $staff_id,
                    'status'    => $status,
                ],
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // DAILY SALES REPORT — per shift, for a given date
        // ══════════════════════════════════════════════════════════════════════
        case 'daily_report':
            $date  = $_GET['date']  ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
            $is_manager = in_array($role, ['manager','admin','superadmin']);

            $dr_where  = "ft.station_id = ? AND DATE(ft.transaction_date) = ?";
            $dr_params = [$station_id, $date];
            if (!$is_manager) { $dr_where .= " AND ft.staff_id = ?"; $dr_params[] = $me['id']; }

            // Per-shift breakdown
            $shift_sql = "
                SELECT
                    COALESCE(ft.shift_name, ft.shift_period, 'Unknown Shift') AS shift_label,
                    ft.fuel_type,
                    MIN(ft.previous_reading)  AS beginning_reading,
                    MAX(ft.present_reading)   AS ending_reading,
                    SUM(ft.calibration)       AS total_calibration,
                    SUM(ft.liters_sold)       AS total_liters,
                    AVG(ft.price_per_liter)   AS avg_price,
                    SUM(ft.total_amount)      AS total_amount,
                    COUNT(*)                  AS entry_count,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS staff_names
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                WHERE {$dr_where}
                GROUP BY ft.shift_period, ft.shift_name, ft.fuel_type
                ORDER BY ft.shift_period ASC, ft.fuel_type ASC
            ";
            $shift_stmt = $pdo->prepare($shift_sql);
            $shift_stmt->execute($dr_params);
            $shift_rows = $shift_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Day totals
            $day_sql = "
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS beginning_reading,
                    MAX(ft.present_reading)  AS ending_reading,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount
                FROM fuel_transactions ft
                WHERE {$dr_where}
                GROUP BY ft.fuel_type ORDER BY ft.fuel_type ASC
            ";
            $day_stmt = $pdo->prepare($day_sql);
            $day_stmt->execute($dr_params);
            $day_totals = $day_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'  => 'daily',
                'date'         => $date,
                'shift_detail' => $shift_rows,
                'day_totals'   => $day_totals,
                'grand_liters' => round(array_sum(array_column($day_totals, 'total_liters')), 2),
                'grand_amount' => round(array_sum(array_column($day_totals, 'total_amount')), 2),
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // WEEKLY SUMMARY — Previous vs Present readings per fuel type
        // ══════════════════════════════════════════════════════════════════════
        case 'weekly_report':
            $week_start = $_GET['week_start'] ?? date('Y-m-d', strtotime('monday this week'));
            $week_end   = date('Y-m-d', strtotime($week_start . ' +6 days'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) $week_start = date('Y-m-d', strtotime('monday this week'));

            $wr_stmt = $pdo->prepare("
                SELECT
                    ft.fuel_type,
                    DATE(ft.transaction_date)  AS reading_date,
                    MIN(ft.previous_reading)   AS beginning,
                    MAX(ft.present_reading)    AS ending,
                    SUM(ft.liters_sold)        AS liters_sold,
                    SUM(ft.total_amount)       AS amount,
                    AVG(ft.price_per_liter)    AS avg_price
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type, DATE(ft.transaction_date)
                ORDER BY ft.fuel_type ASC, reading_date ASC
            ");
            $wr_stmt->execute([$station_id, $week_start, $week_end]);
            $weekly_rows = $wr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Weekly totals per fuel type
            $wt_stmt = $pdo->prepare("
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS week_beginning,
                    MAX(ft.present_reading)  AS week_ending,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type ORDER BY ft.fuel_type ASC
            ");
            $wt_stmt->execute([$station_id, $week_start, $week_end]);
            $weekly_totals = $wt_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'   => 'weekly',
                'week_start'    => $week_start,
                'week_end'      => $week_end,
                'daily_detail'  => $weekly_rows,
                'weekly_totals' => $weekly_totals,
                'grand_liters'  => round(array_sum(array_column($weekly_totals, 'total_liters')), 2),
                'grand_amount'  => round(array_sum(array_column($weekly_totals, 'total_amount')), 2),
            ]);

        // ══════════════════════════════════════════════════════════════════════
        // MONTHLY AUDIT REPORT — Admin/Manager oversight
        // ══════════════════════════════════════════════════════════════════════
        case 'monthly_report':
            $year  = (int)($_GET['year']  ?? date('Y'));
            $month = (int)($_GET['month'] ?? date('n'));
            if ($year < 2020 || $year > 2100) $year = (int)date('Y');
            if ($month < 1 || $month > 12)    $month = (int)date('n');
            if (!in_array($role, ['manager','admin','superadmin'])) respond(false, 'Manager access required.');

            $month_start = sprintf('%04d-%02d-01', $year, $month);
            $month_end   = date('Y-m-t', strtotime($month_start));

            // Daily totals for the month
            $mr_stmt = $pdo->prepare("
                SELECT
                    DATE(ft.transaction_date)  AS reading_date,
                    ft.fuel_type,
                    MIN(ft.previous_reading)   AS beginning,
                    MAX(ft.present_reading)    AS ending,
                    SUM(ft.liters_sold)        AS liters_sold,
                    SUM(ft.total_amount)       AS amount,
                    COUNT(*)                   AS entries,
                    SUM(CASE WHEN LOWER(ft.status) = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                    SUM(CASE WHEN LOWER(ft.status) = 'pending validation' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN LOWER(ft.status) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count
                FROM fuel_transactions ft
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY DATE(ft.transaction_date), ft.fuel_type
                ORDER BY reading_date ASC, ft.fuel_type ASC
            ");
            $mr_stmt->execute([$station_id, $month_start, $month_end]);
            $monthly_detail = $mr_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Monthly totals per fuel type
            $mt_stmt = $pdo->prepare("
                SELECT ft.fuel_type,
                    MIN(ft.previous_reading) AS month_beginning,
                    MAX(ft.present_reading)  AS month_ending,
                    SUM(ft.liters_sold)      AS total_liters,
                    SUM(ft.total_amount)     AS total_amount,
                    COUNT(*)                 AS total_entries,
                    GROUP_CONCAT(DISTINCT u.name ORDER BY u.name SEPARATOR ', ') AS staff_names
                FROM fuel_transactions ft
                LEFT JOIN users u ON ft.staff_id = u.id
                WHERE ft.station_id = ?
                  AND DATE(ft.transaction_date) BETWEEN ? AND ?
                GROUP BY ft.fuel_type ORDER BY ft.fuel_type ASC
            ");
            $mt_stmt->execute([$station_id, $month_start, $month_end]);
            $monthly_totals = $mt_stmt->fetchAll(PDO::FETCH_ASSOC);

            respond(true, '', [
                'report_type'    => 'monthly',
                'year'           => $year,
                'month'          => $month,
                'month_start'    => $month_start,
                'month_end'      => $month_end,
                'daily_detail'   => $monthly_detail,
                'monthly_totals' => $monthly_totals,
                'grand_liters'   => round(array_sum(array_column($monthly_totals, 'total_liters')), 2),
                'grand_amount'   => round(array_sum(array_column($monthly_totals, 'total_amount')), 2),
            ]);

        default:
            // ── Debug auth endpoint (GET only, safe — returns no sensitive data) ──
            if ($action === 'debug_auth') {
                respond(true, 'Auth OK', [
                    'user_id'    => $me['id'] ?? null,
                    'name'       => $me['name'] ?? null,
                    'role'       => $role,
                    'station_id' => $station_id,
                    'auth_path'  => !empty($_SESSION['user']) ? 'session' : 'token',
                ]);
            }
            respond(false, 'Invalid action.');
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
}

function respond(bool $ok, string $msg = '', array $data = []): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}

function fuel_validate(float $present, float $previous, float $calibration, PDO $pdo): array {
    // Pull limits from system_settings; fall back to safe defaults if not configured
    $max_liters    = 2000.0;
    $max_calib     = 50.0;
    try {
        $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_liters_per_shift','max_calibration_liters')");
        if ($s) {
            foreach ($s->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                if ($k === 'max_liters_per_shift'    && (float)$v > 0) $max_liters = (float)$v;
                if ($k === 'max_calibration_liters'  && (float)$v > 0) $max_calib  = (float)$v;
            }
        }
    } catch (Exception $e) {}

    $errors = [];
    if ($present < $previous)
        $errors[] = "Present reading ({$present}) cannot be less than previous ({$previous}).";
    $diff = $present - $previous;
    // Allow calibration >= diff — results in 0 L net sales (valid, matches UI behaviour)
    $liters = $diff - $calibration;
    if ($liters < -0.001)     $errors[] = "Negative liters computed (" . round($liters, 3) . ").";
    if ($liters > $max_liters) $errors[] = "Liters sold ({$liters}) exceeds {$max_liters} L limit.";
    if ($calibration > $max_calib) $errors[] = "Calibration ({$calibration}) exceeds {$max_calib} L maximum.";
    return ['valid' => empty($errors), 'errors' => $errors, 'liters_sold' => max(0, $liters)];
}
