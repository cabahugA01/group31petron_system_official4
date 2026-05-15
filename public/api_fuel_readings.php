<?php
/**
 * Fuel Readings API — self-contained, lives in /public/
 */
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json');
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();
$action     = $_GET['action'] ?? $_POST['action'] ?? '';
$method     = $_SERVER['REQUEST_METHOD'];

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
            $previous     = (float)($_POST['previous_reading'] ?? 0);
            $calibration  = (float)($_POST['calibration']      ?? 0);
            $price        = (float)($_POST['price_per_liter']  ?? 0);
            $reading_date = $_POST['reading_date']  ?? date('Y-m-d');
            $shift_period = $_POST['shift_period']  ?? 'first';
            $shift_name   = $_POST['shift_name']    ?? '';
            $shift_id     = (int)($_POST['shift_id'] ?? 0) ?: null;
            $notes        = trim($_POST['notes'] ?? '');

            if (empty($fuel_type)) respond(false, 'Fuel type is required.');
            if ($present  <= 0)   respond(false, 'Present reading must be greater than 0.');
            if ($price    <= 0)   respond(false, 'Price per liter must be greater than 0.');

            // Compute liters — allow zero/negative result (calibration may exceed diff on low-volume shifts)
            $liters_sold  = max(0, ($present - $previous) - $calibration);
            $total_amount = round($liters_sold * $price, 2);

            $txn_id = 'FUEL' . date('Y')
                    . str_pad($station_id, 3, '0', STR_PAD_LEFT)
                    . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // Ensure columns exist
            $cols = array_column($pdo->query("SHOW COLUMNS FROM fuel_transactions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            foreach (['shift_period','shift_name','shift_id','notes','status'] as $rc) {
                if (!in_array($rc, $cols)) {
                    $def = ($rc === 'status') ? "VARCHAR(50) NULL DEFAULT 'Pending Validation'" : "TEXT NULL";
                    $pdo->exec("ALTER TABLE fuel_transactions ADD COLUMN `$rc` $def");
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO fuel_transactions
                    (transaction_id, station_id, fuel_type,
                     present_reading, previous_reading, calibration,
                     liters_sold, price_per_liter, total_amount,
                     staff_id, transaction_date,
                     shift_period, shift_name, shift_id, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending Validation')
            ")->execute([
                $txn_id, $station_id, $fuel_type,
                $present, $previous, $calibration,
                $liters_sold, $price, $total_amount,
                $me['id'], $reading_date . ' ' . date('H:i:s'),
                $shift_period, $shift_name, $shift_id, $notes,
            ]);

            try {
                $pdo->prepare("UPDATE fuel_inventory SET last_updated=NOW() WHERE station_id=? AND LOWER(TRIM(fuel_type))=LOWER(TRIM(?))")
                    ->execute([$station_id, $fuel_type]);
            } catch (Exception $e) {}

            $pdo->commit();

            try {
                log_activity($pdo, $me['id'], 'Fuel Reading Encoded',
                    "{$fuel_type}: Present={$present}, Prev={$previous}, Calib={$calibration}, Liters={$liters_sold}");
            } catch (Exception $e) {}

            respond(true, 'Reading submitted. Pending manager approval.', [
                'transaction_id' => $txn_id,
                'liters_sold'    => $liters_sold,
                'total_amount'   => $total_amount,
            ]);

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
            respond(true, "Reading {$new_status}.");

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

        default:
            respond(false, 'Invalid action.');
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (ob_get_level()) ob_end_clean();
    respond(false, 'Server error: ' . $e->getMessage());
}

function respond(bool $ok, string $msg = '', array $data = []): void {
    if (ob_get_level()) ob_end_clean();
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $data));
    exit;
}

function fuel_validate(float $present, float $previous, float $calibration): array {
    $errors = [];
    if ($present < $previous)
        $errors[] = "Present reading ({$present}) cannot be less than previous ({$previous}).";
    $diff = $present - $previous;
    if ($calibration > $diff)
        $errors[] = "Calibration ({$calibration}) cannot exceed the difference ({$diff}).";
    $liters = $diff - $calibration;
    if ($liters < 0)   $errors[] = "Negative liters computed ({$liters}).";
    if ($liters > 2000) $errors[] = "Liters sold ({$liters}) exceeds 2,000 L limit.";
    if ($calibration > 50) $errors[] = "Calibration ({$calibration}) exceeds 50 L maximum.";
    return ['valid' => empty($errors), 'errors' => $errors, 'liters_sold' => max(0, $liters)];
}
