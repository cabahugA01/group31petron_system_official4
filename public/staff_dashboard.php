<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'dashboard';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$station_id = user_station_id();
$role       = role_key($me['role'] ?? '');

if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    header('Location: dashboard.php'); exit;
}
if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

$range = strtolower(trim($_GET['range'] ?? 'today'));
if (!in_array($range, ['today', 'week', 'month'])) $range = 'today';

$date_cond_txn = match($range) {
    'week'  => 'YEARWEEK(COALESCE(NULLIF(transaction_date,"0000-00-00 00:00:00"), created_at), 1) = YEARWEEK(CURDATE(), 1)',
    'month' => 'YEAR(COALESCE(NULLIF(transaction_date,"0000-00-00 00:00:00"), created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(NULLIF(transaction_date,"0000-00-00 00:00:00"), created_at)) = MONTH(CURDATE())',
    default => 'DATE(COALESCE(NULLIF(transaction_date,"0000-00-00 00:00:00"), created_at)) = CURDATE()',
};

$date_cond_jo = match($range) {
    'week'  => 'YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)',
    'month' => 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())',
    default => 'DATE(created_at) = CURDATE()',
};

$display_name  = htmlspecialchars($me['full_name'] ?? trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: ($me['name'] ?? 'Staff'));
$station_label = htmlspecialchars($me['station_name'] ?? 'Station #' . $station_id);

$flash_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? null; unset($_SESSION['error']);

// ============================================================
// POST HANDLER: clock_in / clock_out
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'clock_in') {
        $check = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
        $check->execute([$me['id']]);
        if ($check->fetch()) {
            $_SESSION['error'] = 'You are already clocked in.';
        } else {
            $sp = $pdo->prepare(
                "SELECT shift_key, shift_name FROM shift_periods
                 WHERE is_active = 1 AND start_time <= TIME(NOW()) AND end_time >= TIME(NOW())
                 ORDER BY sort_order ASC LIMIT 1"
            );
            $sp->execute([]);
            $shift = $sp->fetch(PDO::FETCH_ASSOC);
            if (!$shift) {
                $sp2 = $pdo->query(
                    "SELECT shift_key, shift_name FROM shift_periods
                     WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1"
                );
                $shift = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
            }
            if (!$shift) {
                $shift = ['shift_key' => 'first', 'shift_name' => 'First Shift'];
            }
            $pdo->prepare(
                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                 VALUES (?, ?, NOW(), ?, ?)"
            )->execute([$me['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
            log_activity($pdo, $me['id'], 'Clock In', "Station {$station_id} - {$shift['shift_name']}");
            $_SESSION['success'] = "Clocked in successfully. Shift: {$shift['shift_name']}";
        }
    }
    if ($_POST['action'] === 'clock_out') {
        $stmt = $pdo->prepare(
            "UPDATE labor_sessions
             SET end_time = NOW(),
                 hours_worked = ROUND(TIMESTAMPDIFF(MINUTE, start_time, NOW()) / 60, 2)
             WHERE user_id = ? AND end_time IS NULL"
        );
        $stmt->execute([$me['id']]);
        if ($stmt->rowCount() > 0) {
            log_activity($pdo, $me['id'], 'Clock Out', 'Clocked out');
            $_SESSION['success'] = 'Clocked out successfully.';
        } else {
            $_SESSION['error'] = 'You are not clocked in.';
        }
    }
    header('Location: staff_dashboard.php?range=' . $range); exit;
}

// ============================================================
// AJAX ENDPOINT: ?refresh=1
// ============================================================
if (isset($_GET['refresh']) && $_GET['refresh'] == '1') {
    header('Content-Type: application/json');
    try {
        // Merchandise
        $ms = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS merch_sales, COUNT(*) AS merch_count,
            COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS merch_cash,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END),0) AS merch_card,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS merch_ewallet,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS merch_efuel,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS merch_credit
            FROM merchandise_transactions WHERE station_id = ? AND {$date_cond_txn}");
        $ms->execute([$station_id]);
        $mr = $ms->fetch(PDO::FETCH_ASSOC) ?: [];
        // JO payments
        $js = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS jo_cash,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END),0) AS jo_card,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS jo_ewallet,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS jo_efuel,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS jo_credit
            FROM job_orders WHERE station_id = ? AND status = 'Completed' AND {$date_cond_jo}");
        $js->execute([$station_id]);
        $jr = $js->fetch(PDO::FETCH_ASSOC) ?: [];
        // Fuel by type
        $fs = $pdo->prepare("SELECT fuel_type,
            COALESCE(SUM(liters_sold),0) AS total_liters,
            COALESCE(SUM(total_amount),0) AS total_revenue,
            COALESCE(AVG(price_per_liter),0) AS avg_price,
            COUNT(*) AS txn_count,
            MAX(CASE WHEN ABS((present_reading-previous_reading)-liters_sold)>=2 THEN 1 ELSE 0 END) AS has_discrepancy
            FROM fuel_transactions WHERE station_id = ? AND {$date_cond_txn} AND liters_sold > 0
            GROUP BY fuel_type ORDER BY total_liters DESC");
        $fs->execute([$station_id]);
        $fbt = $fs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // JO status counts
        $pv = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending Validation'"); $pv->execute([$station_id]); $pvc = (int)$pv->fetchColumn();
        $av = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')"); $av->execute([$station_id]); $avc = (int)$av->fetchColumn();
        $ip = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'"); $ip->execute([$station_id]); $ipc = (int)$ip->fetchColumn();
        $cp = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'"); $cp->execute([$station_id]); $cpc = (int)$cp->fetchColumn();
        $rj = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Rejected'"); $rj->execute([$station_id]); $rjc = (int)$rj->fetchColumn();
        // Variance count
        $vc = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE() AND liters_sold>0 AND ABS((present_reading-previous_reading)-liters_sold)>=2"); $vc->execute([$station_id]); $vcc = (int)$vc->fetchColumn();
        // Low stock count
        $lc = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status='active' AND stock_level<=reorder_level"); $lc->execute([$station_id]); $lcc = (int)$lc->fetchColumn();        echo json_encode([
            'success'            => true,
            'today_sales'        => (float)($mr['merch_sales'] ?? 0) + array_sum(array_column($fbt, 'total_revenue')),
            'today_fuel'         => array_sum(array_column($fbt, 'total_revenue')),
            'today_merch'        => (float)($mr['merch_sales'] ?? 0),
            'merch_cash'         => (float)($mr['merch_cash'] ?? 0),
            'merch_card'         => (float)($mr['merch_card'] ?? 0),
            'merch_ewallet'      => (float)($mr['merch_ewallet'] ?? 0),
            'merch_efuel'        => (float)($mr['merch_efuel'] ?? 0),
            'merch_credit'       => (float)($mr['merch_credit'] ?? 0),
            'jo_cash'            => (float)($jr['jo_cash'] ?? 0),
            'jo_card'            => (float)($jr['jo_card'] ?? 0),
            'jo_ewallet'         => (float)($jr['jo_ewallet'] ?? 0),
            'jo_efuel'           => (float)($jr['jo_efuel'] ?? 0),
            'jo_credit'          => (float)($jr['jo_credit'] ?? 0),
            'credit_sales'       => (float)($mr['merch_credit'] ?? 0) + (float)($jr['jo_credit'] ?? 0),
            'txn_today'          => (int)($mr['merch_count'] ?? 0),
            'pending_validation' => $pvc,
            'approved_validated' => $avc,
            'in_progress'        => $ipc,
            'completed'          => $cpc,
            'rejected'           => $rjc,
            'fuel_variance_count'=> $vcc,
            'low_stock_count'    => $lcc,
            'fuel_by_type'       => [
                'labels'  => array_column($fbt, 'fuel_type'),
                'liters'  => array_map('floatval', array_column($fbt, 'total_liters')),
                'revenue' => array_map('floatval', array_column($fbt, 'total_revenue')),
                'flags'   => array_map('intval',   array_column($fbt, 'has_discrepancy')),
            ],
            'job_order_rows'     => (function() use ($pdo, $station_id) {
                try {
                    $s = $pdo->prepare("
                        SELECT COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
                               COALESCE(c.name, jo.customer_name, 'Walk-in')                  AS customer,
                               COALESCE(jo.service_type, jo.service_description, '—')         AS service_type,
                               COALESCE(m.full_name, m.name, '—')                             AS mechanic,
                               jo.created_at, jo.status,
                               COALESCE(jo.validation_status, jo.status)                      AS display_status,
                               jo.notes
                        FROM job_orders jo
                        LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                        LEFT JOIN customers c ON c.id = jo.customer_id
                        WHERE jo.station_id = ?
                        ORDER BY FIELD(jo.status,'Pending Validation','In Progress','Approved','Validated','Completed','Rejected'), jo.created_at DESC
                        LIMIT 20
                    ");
                    $s->execute([$station_id]);
                    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) { return []; }
            })(),
            'qa_txns_today'   => (function() use ($pdo, $station_id) { try { $s=$pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'),created_at))=CURDATE()"); $s->execute([$station_id]); return (int)$s->fetchColumn(); } catch(Exception $e){return 0;} })(),
            'qa_credit_today' => (function() use ($pdo, $station_id) { try { $s=$pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'),created_at))=CURDATE() AND payment_method IN ('Credit','Account Receivable','utang','Utang')"); $s->execute([$station_id]); return (int)$s->fetchColumn(); } catch(Exception $e){return 0;} })(),
            'qa_pending_jo'   => (function() use ($pdo, $station_id) { try { $s=$pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending Validation'"); $s->execute([$station_id]); return (int)$s->fetchColumn(); } catch(Exception $e){return 0;} })(),
            'qa_pending_del'  => (function() use ($pdo, $station_id) { try { $s=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Pending','Pending Validation','pending')"); $s->execute([$station_id]); return (int)$s->fetchColumn(); } catch(Exception $e){return 0;} })(),
            'qa_fuel_today'   => (function() use ($pdo, $station_id) { try { $s=$pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()"); $s->execute([$station_id]); return (int)$s->fetchColumn(); } catch(Exception $e){return 0;} })(),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX ENDPOINT: ?refresh_charts=1
// ============================================================
if (isset($_GET['refresh_charts']) && $_GET['refresh_charts'] == '1') {
    header('Content-Type: application/json');
    try {
        $ms = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS merch_cash,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END),0) AS merch_card,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS merch_ewallet,
            COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS merch_efuel,
            COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS merch_credit
            FROM merchandise_transactions WHERE station_id = ? AND {$date_cond_txn}");
        $ms->execute([$station_id]);
        $mr = $ms->fetch(PDO::FETCH_ASSOC) ?: [];
        $fs = $pdo->prepare("SELECT fuel_type,
            COALESCE(SUM(liters_sold),0) AS total_liters,
            COALESCE(SUM(total_amount),0) AS total_revenue,
            MAX(CASE WHEN ABS((present_reading-previous_reading)-liters_sold)>=2 THEN 1 ELSE 0 END) AS has_discrepancy
            FROM fuel_transactions WHERE station_id = ? AND {$date_cond_txn} AND liters_sold > 0
            GROUP BY fuel_type ORDER BY total_liters DESC");
        $fs->execute([$station_id]);
        $fbt = $fs->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pv = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending Validation'"); $pv->execute([$station_id]); $pvc = (int)$pv->fetchColumn();
        $av = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')"); $av->execute([$station_id]); $avc = (int)$av->fetchColumn();
        $ip = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'"); $ip->execute([$station_id]); $ipc = (int)$ip->fetchColumn();
        $cp = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'"); $cp->execute([$station_id]); $cpc = (int)$cp->fetchColumn();
        $rj = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Rejected'"); $rj->execute([$station_id]); $rjc = (int)$rj->fetchColumn();
        echo json_encode([
            'success'            => true,
            'merch_cash'         => (float)($mr['merch_cash'] ?? 0),
            'merch_card'         => (float)($mr['merch_card'] ?? 0),
            'merch_ewallet'      => (float)($mr['merch_ewallet'] ?? 0),
            'merch_efuel'        => (float)($mr['merch_efuel'] ?? 0),
            'merch_credit'       => (float)($mr['merch_credit'] ?? 0),
            'fuel_by_type'       => [
                'labels'  => array_column($fbt, 'fuel_type'),
                'liters'  => array_map('floatval', array_column($fbt, 'total_liters')),
                'revenue' => array_map('floatval', array_column($fbt, 'total_revenue')),
                'flags'   => array_map('intval',   array_column($fbt, 'has_discrepancy')),
            ],
            'pending_validation' => $pvc,
            'approved_validated' => $avc,
            'in_progress'        => $ipc,
            'completed'          => $cpc,
            'rejected'           => $rjc,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX ENDPOINT: ?refresh_fuel=1  — live tank levels for fuel widget
// ============================================================
if (isset($_GET['refresh_fuel']) && $_GET['refresh_fuel'] == '1') {
    header('Content-Type: application/json');
    try {
        $fsl = $pdo->prepare("
            SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
                   COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
                   COALESCE(fi.capacity, 0)         AS capacity,
                   COALESCE(fi.price_per_liter, 0)  AS price_per_liter
            FROM fuel_inventory fi
            LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
            WHERE fi.station_id = ?
            ORDER BY fuel_type_name ASC
        ");
        $fsl->execute([$station_id]);
        $levels = $fsl->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Pending deliveries count
        $pd = $pdo->prepare("SELECT COUNT(*) FROM fuel_deliveries WHERE station_id = ? AND LOWER(status) IN ('pending','pending review')");
        $pd->execute([$station_id]);
        $pending_deliveries = (int)$pd->fetchColumn();

        echo json_encode([
            'success'            => true,
            'fuel_levels'        => $levels,
            'pending_deliveries' => $pending_deliveries,
            'refreshed_at'       => date('H:i:s'),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PHP DATA QUERIES
// ============================================================

// Widget 1: Merchandise Sales
$today_merch = 0; $merch_txns = 0;
$merch_cash = 0; $merch_card = 0; $merch_ewallet = 0; $merch_efuel = 0; $merch_credit = 0;
$jo_cash = 0; $jo_card = 0; $jo_ewallet = 0; $jo_efuel = 0; $jo_credit = 0;
try {
    $ms = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS merch_sales, COUNT(*) AS merch_count,
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS merch_cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END),0) AS merch_card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS merch_ewallet,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS merch_efuel,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS merch_credit
        FROM merchandise_transactions WHERE station_id = ? AND {$date_cond_txn}");
    $ms->execute([$station_id]);
    $mr = $ms->fetch(PDO::FETCH_ASSOC) ?: [];
    $today_merch  = (float)($mr['merch_sales'] ?? 0);
    $merch_txns   = (int)($mr['merch_count'] ?? 0);
    $merch_cash   = (float)($mr['merch_cash'] ?? 0);
    $merch_card   = (float)($mr['merch_card'] ?? 0);
    $merch_ewallet= (float)($mr['merch_ewallet'] ?? 0);
    $merch_efuel  = (float)($mr['merch_efuel'] ?? 0);
    $merch_credit = (float)($mr['merch_credit'] ?? 0);
    $js = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS jo_cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card') THEN total_amount ELSE 0 END),0) AS jo_card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet') THEN total_amount ELSE 0 END),0) AS jo_ewallet,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS jo_efuel,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS jo_credit
        FROM job_orders WHERE station_id = ? AND status = 'Completed' AND {$date_cond_jo}");
    $js->execute([$station_id]);
    $jr = $js->fetch(PDO::FETCH_ASSOC) ?: [];
    $jo_cash    = (float)($jr['jo_cash'] ?? 0);
    $jo_card    = (float)($jr['jo_card'] ?? 0);
    $jo_ewallet = (float)($jr['jo_ewallet'] ?? 0);
    $jo_efuel   = (float)($jr['jo_efuel'] ?? 0);
    $jo_credit  = (float)($jr['jo_credit'] ?? 0);
} catch (Exception $e) {}

// Widget 2: Fuel by type
$fuel_by_type = [];
try {
    $fs = $pdo->prepare("SELECT fuel_type,
        COALESCE(SUM(liters_sold),0) AS total_liters,
        COALESCE(SUM(total_amount),0) AS total_revenue,
        COALESCE(AVG(price_per_liter),0) AS avg_price,
        COUNT(*) AS txn_count,
        COALESCE(SUM(ABS((present_reading-previous_reading)-liters_sold)),0) AS total_variance_liters,
        MAX(CASE WHEN ABS((present_reading-previous_reading)-liters_sold)>=2 THEN 1 ELSE 0 END) AS has_discrepancy
        FROM fuel_transactions WHERE station_id = ? AND {$date_cond_txn} AND liters_sold > 0
        GROUP BY fuel_type ORDER BY total_liters DESC");
    $fs->execute([$station_id]);
    $fuel_by_type = $fs->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Widget 3: Job order status counts + detail rows
$pending_validation = 0; $approved_validated = 0; $in_progress = 0; $completed = 0; $rejected = 0;
$job_order_rows = [];
try {
    $pv = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending Validation'"); $pv->execute([$station_id]); $pending_validation = (int)$pv->fetchColumn();
    $av = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status IN ('Approved','Validated')"); $av->execute([$station_id]); $approved_validated = (int)$av->fetchColumn();
    $ip = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'"); $ip->execute([$station_id]); $in_progress = (int)$ip->fetchColumn();
    $cp = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'"); $cp->execute([$station_id]); $completed = (int)$cp->fetchColumn();
    $rj = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Rejected'"); $rj->execute([$station_id]); $rejected = (int)$rj->fetchColumn();

    // Detail rows — most recent 20, all statuses, scoped to station
    $jod = $pdo->prepare("
        SELECT
            COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
            COALESCE(c.name, jo.customer_name, 'Walk-in')                  AS customer,
            COALESCE(jo.service_type, jo.service_description, '—')         AS service_type,
            COALESCE(m.full_name, m.name, '—')                             AS mechanic,
            jo.created_at,
            jo.status,
            COALESCE(jo.validation_status, jo.status)                      AS display_status,
            jo.notes
        FROM job_orders jo
        LEFT JOIN mechanics m  ON m.id  = jo.assigned_mechanic_id
        LEFT JOIN customers c  ON c.id  = jo.customer_id
        WHERE jo.station_id = ?
        ORDER BY
            FIELD(jo.status,'Pending Validation','In Progress','Approved','Validated','Completed','Rejected'),
            jo.created_at DESC
        LIMIT 20
    ");
    $jod->execute([$station_id]);
    $job_order_rows = $jod->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $job_order_rows = []; }

// Widget 4: Fuel stock levels
$fuel_stock_levels = [];
$fuel_variance_alerts = [];
try {
    $fsl = $pdo->prepare("SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
        COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
        COALESCE(fi.capacity, 0) AS capacity,
        COALESCE(fi.price_per_liter, 0) AS price_per_liter
        FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ? ORDER BY fuel_type_name ASC");
    $fsl->execute([$station_id]);
    $fuel_stock_levels = $fsl->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
try {
    $fva = $pdo->prepare("SELECT transaction_id, fuel_type, liters_sold,
        ROUND(present_reading - previous_reading, 2) AS pump_delta,
        ROUND(ABS((present_reading - previous_reading) - liters_sold), 2) AS variance_liters
        FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
        AND liters_sold > 0 AND ABS((present_reading - previous_reading) - liters_sold) >= 2
        ORDER BY variance_liters DESC LIMIT 10");
    $fva->execute([$station_id]);
    $fuel_variance_alerts = $fva->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Widget 5: Low stock items
$low_stock_items = [];
try {
    $lsi = $pdo->prepare("SELECT product_name, stock_level, reorder_level,
        (reorder_level - stock_level) AS shortage,
        CASE
            WHEN stock_level <= 0                   THEN 'Critical'
            WHEN stock_level <= reorder_level * 0.5 THEN 'High'
            ELSE 'Medium'
        END AS priority
        FROM station_inventory
        WHERE station_id = ? AND status = 'active' AND stock_level <= reorder_level
        ORDER BY shortage DESC LIMIT 10");
    $lsi->execute([$station_id]);
    $low_stock_items = $lsi->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Widget 6: Clock-in / Clock-out
$current_session = null;
$my_shifts_today = [];
$current_shift_name = 'First Shift';
$current_shift_key  = 'first';
try {
    $cas = $pdo->prepare("SELECT * FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
    $cas->execute([$me['id']]);
    $current_session = $cas->fetch(PDO::FETCH_ASSOC) ?: null;
    $tsl = $pdo->prepare("SELECT start_time, end_time, shift_name, hours_worked FROM labor_sessions WHERE user_id = ? AND DATE(start_time) = CURDATE() ORDER BY (end_time IS NULL) DESC, shift_name ASC, start_time ASC");
    $tsl->execute([$me['id']]);
    $my_shifts_today = $tsl->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $csd = $pdo->prepare("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 AND start_time <= TIME(NOW()) AND end_time >= TIME(NOW()) LIMIT 1");
    $csd->execute([]);
    $csr = $csd->fetch(PDO::FETCH_ASSOC);
    if (!$csr) {
        $csd2 = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1");
        $csr = $csd2 ? $csd2->fetch(PDO::FETCH_ASSOC) : null;
    }
    if ($csr) { $current_shift_name = $csr['shift_name']; $current_shift_key = $csr['shift_key']; }
} catch (Exception $e) {}

// Widget 7: Calendar
$calendar_today    = [];
$calendar_upcoming = [];
try {
    $cjt = $pdo->prepare("SELECT 'job_order' AS type, job_order_number AS ref, customer_name AS label, status, DATE(created_at) AS task_date, 'staff' AS color_role FROM job_orders WHERE station_id = ? AND DATE(created_at) = CURDATE() ORDER BY created_at DESC LIMIT 20");
    $cjt->execute([$station_id]);
    $calendar_today = $cjt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cdt = $pdo->prepare("SELECT 'delivery' AS type, COALESCE(delivery_ref, CONCAT('DEL-', id)) AS ref, COALESCE(supplier, 'Delivery') AS label, status, DATE(COALESCE(delivery_date, created_at)) AS task_date, 'staff' AS color_role FROM deliveries_oversight WHERE station_id = ? AND DATE(COALESCE(delivery_date, created_at)) = CURDATE() ORDER BY COALESCE(delivery_date, created_at) DESC LIMIT 20");
    $cdt->execute([$station_id]);
    $calendar_today = array_merge($calendar_today, $cdt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    $cju = $pdo->prepare("SELECT 'job_order' AS type, job_order_number AS ref, customer_name AS label, status, DATE(created_at) AS task_date, 'staff' AS color_role FROM job_orders WHERE station_id = ? AND DATE(created_at) BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY created_at ASC LIMIT 15");
    $cju->execute([$station_id]);
    $calendar_upcoming = $cju->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cdu = $pdo->prepare("SELECT 'delivery' AS type, COALESCE(delivery_ref, CONCAT('DEL-', id)) AS ref, COALESCE(supplier, 'Delivery') AS label, status, DATE(COALESCE(delivery_date, created_at)) AS task_date, 'staff' AS color_role FROM deliveries_oversight WHERE station_id = ? AND DATE(COALESCE(delivery_date, created_at)) BETWEEN DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY COALESCE(delivery_date, created_at) ASC LIMIT 15");
    $cdu->execute([$station_id]);
    $calendar_upcoming = array_merge($calendar_upcoming, $cdu->fetchAll(PDO::FETCH_ASSOC) ?: []);
} catch (Exception $e) {}

// Quick Action badge counts — all from DB, none hardcoded
$qa_txns_today    = 0; // merchandise transactions encoded today by this user
$qa_credit_today  = 0; // credit sales today
$qa_pending_jo    = 0; // pending job orders at this station
$qa_pending_del   = 0; // pending deliveries at this station
$qa_fuel_today    = 0; // fuel readings encoded today
$qa_shift_hours   = 0; // hours worked today (from labor_sessions)
$qa_clocked_in    = !empty($current_session); // already fetched above
try {
    // POS: merchandise transactions today by this user
    $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'),created_at))=CURDATE()");
    $s->execute([$station_id]); $qa_txns_today = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    // Credit sales today
    $s = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id=? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'),created_at))=CURDATE() AND payment_method IN ('Credit','Account Receivable','utang','Utang')");
    $s->execute([$station_id]); $qa_credit_today = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    // Pending job orders
    $s = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Pending Validation'");
    $s->execute([$station_id]); $qa_pending_jo = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    // Pending deliveries
    $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Pending','Pending Validation','pending')");
    $s->execute([$station_id]); $qa_pending_del = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    // Fuel readings today
    $s = $pdo->prepare("SELECT COUNT(*) FROM fuel_transactions WHERE station_id=? AND DATE(transaction_date)=CURDATE()");
    $s->execute([$station_id]); $qa_fuel_today = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    // Hours worked today
    $s = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN end_time IS NOT NULL THEN hours_worked ELSE ROUND(TIMESTAMPDIFF(MINUTE,start_time,NOW())/60,2) END),0) FROM labor_sessions WHERE user_id=? AND DATE(start_time)=CURDATE()");
    $s->execute([$me['id']]); $qa_shift_hours = (float)$s->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>
<div class="dashboard-content" style="max-width:100%;box-sizing:border-box;overflow-x:hidden;">
<style>
.dashboard-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; padding:0; }
.widget-full { grid-column: 1 / -1; }
@media(max-width:900px) { .dashboard-grid { grid-template-columns:1fr; } }
.widget-card { background:#fff; border-radius:14px; border:1px solid #EAEAEA; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.widget-card h3 { font-size:15px; font-weight:700; color:#00264D; margin:0 0 14px; display:flex; align-items:center; gap:8px; }
.welcome-banner { background:transparent; color:#00264D; padding:18px 24px; border-radius:12px; margin-bottom:16px; border:1px solid #e9ecef; }
.welcome-banner h2 { margin:0 0 4px; font-size:1.4rem; color:#00264D !important; }
.welcome-banner p { margin:0; opacity:.85; font-size:.9rem; color:#6c757d !important; }
.range-selector { display:flex; gap:8px; margin-bottom:16px; }
.range-btn { padding:7px 18px; border-radius:8px; border:1px solid #EAEAEA; background:#f8fafc; color:#344054; font-size:13px; font-weight:600; text-decoration:none; transition:.2s; }
.range-btn.active, .range-btn:hover { background:#00264D; color:#fff; border-color:#00264D; }
.flash-card { padding:12px 16px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px; }
.flash-success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; }
.flash-error { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }
.payment-row { display:flex; align-items:center; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f5f5f5; font-size:13px; }
.payment-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:8px; }
.status-card { border-radius:10px; padding:14px; text-align:center; cursor:pointer; text-decoration:none; display:block; transition:.2s; border:2px solid transparent; }
.status-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.1); }
.status-card .count { font-size:28px; font-weight:800; }
.status-card .label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }
.status-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:14px; }
.fuel-type-card { background:#f8fafc; border-radius:10px; padding:12px; margin-bottom:10px; border:1px solid #EAEAEA; }
.progress-bar-wrap { background:#e5e7eb; border-radius:20px; height:8px; margin:6px 0; overflow:hidden; }
.progress-bar-fill { height:100%; border-radius:20px; transition:width .3s; }
.priority-badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; text-transform:uppercase; }
.badge-critical { background:#FEE2E2; color:#991B1B; }
.badge-high { background:#FEF3C7; color:#92400E; }
.badge-medium { background:#FFF3CD; color:#856404; }
.badge-low-stock { background:#FEF3C7; color:#92400E; }
.badge-normal { background:#D1FAE5; color:#065F46; }
.quick-actions-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; }
.quick-action-btn { display:flex; flex-direction:column; align-items:center; gap:8px; padding:16px 10px; background:#f8fafc; border-radius:12px; border:1px solid #EAEAEA; text-decoration:none; color:#344054; font-size:12px; font-weight:600; text-align:center; transition:.2s; }
.quick-action-btn:hover { background:#00264D; color:#fff; border-color:#00264D; transform:translateY(-2px); }
.quick-action-btn i { font-size:22px; }
.reports-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; }
.report-btn { display:flex; flex-direction:column; align-items:center; gap:8px; padding:14px 10px; background:#f8fafc; border-radius:12px; border:1px solid #EAEAEA; text-decoration:none; color:#344054; font-size:12px; font-weight:600; text-align:center; transition:.2s; }
.report-btn:hover { background:#2563eb; color:#fff; border-color:#2563eb; }
.report-btn i { font-size:20px; }
.calendar-item { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid #f5f5f5; }
.calendar-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:4px; }
.clock-status-card { background:linear-gradient(135deg,#f0f9ff,#e0f2fe); border-radius:12px; padding:16px; margin-bottom:14px; border:1px solid #bae6fd; }
.clock-btn { padding:10px 24px; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; transition:.2s; }
.clock-in-btn { background:#22c55e; color:#fff; }
.clock-out-btn { background:#ef4444; color:#fff; }
.shift-timeline-item { display:flex; gap:12px; padding:8px 0; border-bottom:1px solid #f5f5f5; font-size:13px; }
.variance-alert-row { background:#FEF3C7; border-radius:8px; padding:8px 12px; margin-bottom:6px; font-size:12px; border-left:3px solid #f59e0b; }
.qa-badge { position:absolute; top:-8px; right:-10px; background:#00264D; color:#fff; font-size:10px; font-weight:800; min-width:18px; height:18px; border-radius:20px; display:inline-flex; align-items:center; justify-content:center; padding:0 4px; border:2px solid #fff; line-height:1; }
.qa-badge-red    { background:#ef4444; }
.qa-badge-orange { background:#f59e0b; }
.qa-badge-green  { background:#22c55e; }
.qa-desc { font-size:10px; color:#9ca3af; font-weight:400; margin-top:-4px; }
.quick-action-btn:hover .qa-desc { color:rgba(255,255,255,.7); }
.qa-active { background:#f0fdf4 !important; border-color:#22c55e !important; color:#065F46 !important; }
.qa-active i { color:#22c55e; }
@media(max-width:768px) { .quick-actions-grid { grid-template-columns:repeat(3,1fr); } .reports-grid { grid-template-columns:repeat(3,1fr); } .status-grid { grid-template-columns:repeat(3,1fr); } }
</style>

<!-- Page Header -->
<div class="page-head">
  <div>
    <h1 class="h1" style="font-size:20px; font-weight:bold; color:#00264D;"><i class="fas fa-gauge" style="margin-right:8px"></i>MY DASHBOARD</h1>
    <div class="sub" style="font-size:13px;opacity:.85; color:#6c757d; font-weight:bold;">WELCOME BACK, <?= $display_name ?></div>
  </div>
  <div class="header-actions">
  </div>
</div>


<!-- Flash Messages -->
<?php if ($flash_success): ?>
<div class="flash-card flash-success"><i class="fas fa-check-circle"></i><?= htmlspecialchars($flash_success) ?></div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div class="flash-card flash-error"><i class="fas fa-exclamation-circle"></i><?= htmlspecialchars($flash_error) ?></div>
<?php endif; ?>

<!-- Range Selector -->
<div class="range-selector">
  <a href="staff_dashboard.php?range=today" class="range-btn <?= $range==='today'?'active':'' ?>"><i class="fas fa-calendar-day"></i> Today</a>
  <a href="staff_dashboard.php?range=week"  class="range-btn <?= $range==='week'?'active':'' ?>"><i class="fas fa-calendar-week"></i> This Week</a>
  <a href="staff_dashboard.php?range=month" class="range-btn <?= $range==='month'?'active':'' ?>"><i class="fas fa-calendar-alt"></i> This Month</a>
</div>

<div class="dashboard-grid">

<!-- ===== WIDGET 1: Merchandise Sales Summary ===== -->
<div class="widget-card">
  <h3><i class="fas fa-cash-register"></i> Merchandise Sales</h3>
  <?php
    $merch_total_combined = $merch_cash+$jo_cash + $merch_card+$jo_card + $merch_ewallet+$jo_ewallet + $merch_efuel+$jo_efuel + $merch_credit+$jo_credit;
  ?>
  <?php if ($merch_total_combined == 0 && $merch_txns == 0): ?>
    <p style="color:#9ca3af;text-align:center;padding:20px 0;font-size:13px"><i class="fas fa-inbox"></i> No sales recorded for this period.</p>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
        <th style="text-align:left;padding:8px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Payment Type</th>
        <th style="text-align:right;padding:8px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Amount</th>
        <th style="text-align:right;padding:8px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">% Share</th>
      </tr>
    </thead>
    <tbody id="merch-tbody">
      <?php
        $payments = [
          ['label'=>'Cash',        'color'=>'#22c55e', 'icon'=>'fa-money-bill-wave', 'val'=>$merch_cash+$jo_cash],
          ['label'=>'Card',        'color'=>'#3b82f6', 'icon'=>'fa-credit-card',     'val'=>$merch_card+$jo_card],
          ['label'=>'E-Wallet',    'color'=>'#a855f7', 'icon'=>'fa-mobile-alt',      'val'=>$merch_ewallet+$jo_ewallet],
          ['label'=>'E-Fuel Card', 'color'=>'#f59e0b', 'icon'=>'fa-gas-pump',        'val'=>$merch_efuel+$jo_efuel],
          ['label'=>'Credit/Utang','color'=>'#ef4444', 'icon'=>'fa-file-invoice',    'val'=>$merch_credit+$jo_credit],
        ];
        foreach ($payments as $p):
          $share = $merch_total_combined > 0 ? round(($p['val'] / $merch_total_combined) * 100, 1) : 0;
      ?>
      <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
        <td style="padding:9px 10px">
          <span style="display:inline-flex;align-items:center;gap:7px">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $p['color'] ?>;flex-shrink:0;display:inline-block"></span>
            <i class="fas <?= $p['icon'] ?>" style="color:<?= $p['color'] ?>;font-size:12px"></i>
            <span style="font-weight:600;color:#344054"><?= $p['label'] ?></span>
          </span>
        </td>
        <td style="padding:9px 10px;text-align:right;font-weight:700;color:#101828">&#8369;<?= number_format($p['val'],2) ?></td>
        <td style="padding:9px 10px;text-align:right">
          <span style="background:<?= $p['color'] ?>22;color:<?= $p['color'] ?>;font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px"><?= $share ?>%</span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot id="merch-tfoot">
      <tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">
        <td style="padding:10px;font-weight:800;color:#00264D;font-size:13px"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>
        <td style="padding:10px;text-align:right;font-weight:800;color:#00264D;font-size:14px">&#8369;<?= number_format($merch_total_combined,2) ?></td>
        <td style="padding:10px;text-align:right;font-size:12px;color:#667085"><?= $merch_txns ?> txn<?= $merch_txns!=1?'s':'' ?></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- ===== WIDGET 2: Fuel Sales Monitoring ===== -->
<div class="widget-card">
  <h3><i class="fas fa-gas-pump"></i> Fuel Sales</h3>
  <span style="margin-left:8px;font-size:11px;font-weight:500;color:#667085">Liters &bull; Revenue &bull; Variance</span>
  <a href="staff_transactions_hub.php?section=fuel" style="margin-left:auto;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;flex-shrink:0">
    <i class="fas fa-plus"></i> Encode
  </a>
  <?php if (empty($fuel_by_type)): ?>
    <p style="color:#9ca3af;text-align:center;padding:20px 0;font-size:13px"><i class="fas fa-inbox"></i> No fuel readings recorded for this period.</p>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
        <th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Fuel Type</th>
        <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Liters Sold</th>
        <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Revenue</th>
        <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Avg Price/L</th>
        <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Variance</th>
        <th style="text-align:center;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Status</th>
      </tr>
    </thead>
    <tbody id="fuel-tbody">
      <?php
        $fuel_total_liters  = 0;
        $fuel_total_revenue = 0;
        $has_any_variance = false;
        foreach ($fuel_by_type as $ft):
          $fuel_total_liters += $ft['total_liters'];
          $fuel_total_revenue += $ft['total_revenue'];
          $has_variance = $ft['has_discrepancy'];
          $has_any_variance = $has_any_variance || $has_variance;
          $avg_price = $ft['total_liters'] > 0 ? $ft['total_revenue'] / $ft['total_liters'] : 0;
      ?>
      <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
        <td style="padding:10px 12px"><span style="display:inline-flex;align-items:center;gap:8px">
          <span style="width:10px;height:10px;border-radius:50%;background:#3b82f6;flex-shrink:0;display:inline-block"></span>
          <strong style="color:#00264D"><?= htmlspecialchars($ft['fuel_type']) ?></strong></span></td>
        <td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828"><?= number_format($ft['total_liters'],2) ?> <span style="font-size:11px;color:#667085;font-weight:400">L</span></td>
        <td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">&#8369;<?= number_format($ft['total_revenue'],2) ?></td>
        <td style="padding:10px 12px;text-align:right;color:#667085">&#8369;<?= number_format($avg_price,2) ?></td>
        <td style="padding:10px 12px;text-align:right;color:#667085">—</td>
        <td style="padding:10px 12px;text-align:center">
          <?php if ($has_variance): ?>
            <span style="background:#FEF3C7;color:#92400E;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px"><i class="fas fa-exclamation-triangle"></i> Variance</span>
          <?php else: ?>
            <span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px"><i class="fas fa-check"></i> OK</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot id="fuel-tfoot">
      <tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">
        <td style="padding:10px 12px;font-weight:800;color:#00264D"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>
        <td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D"><?= number_format($fuel_total_liters,2) ?> L</td>
        <td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">&#8369;<?= number_format($fuel_total_revenue,2) ?></td>
        <td style="padding:10px 12px;text-align:right;color:#667085">—</td>
        <td style="padding:10px 12px;text-align:right;font-weight:700;color:#00264D">—</td>
        <td style="padding:10px 12px;text-align:center">
          <?php if ($has_any_variance): ?>
            <span style="background:#FEE2E2;color:#991B1B;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px"><i class="fas fa-exclamation-circle"></i> Check Readings</span>
          <?php else: ?>
            <span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px"><i class="fas fa-check-circle"></i> All OK</span>
          <?php endif; ?>
        </td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<!-- ===== WIDGET 3: Job Orders Status ===== -->
<div class="widget-card widget-full">
  <h3><i class="fas fa-wrench"></i> Job Orders Status
    <a href="joborder.php?action=create" style="margin-left:auto;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;flex-shrink:0">
      <i class="fas fa-plus"></i> New Job Order
    </a>
  </h3>

  <!-- Detail Table -->
  <?php
    $jo_status_cfg = [
      'Pending Validation' => ['bg'=>'#FFF3CD','color'=>'#92400E','icon'=>'fa-hourglass-half'],
      'Approved'           => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'fa-check-circle'],
      'Validated'          => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'fa-check-circle'],
      'In Progress'        => ['bg'=>'#DBEAFE','color'=>'#1E40AF','icon'=>'fa-spinner'],
      'Completed'          => ['bg'=>'#DCFCE7','color'=>'#14532D','icon'=>'fa-flag-checkered'],
      'Rejected'           => ['bg'=>'#FEE2E2','color'=>'#991B1B','icon'=>'fa-times-circle'],
    ];
  ?>
  <div style="overflow-x:auto" id="jo-table-wrap">
  <?php if (empty($job_order_rows)): ?>
    <p style="color:#9ca3af;text-align:center;padding:20px 0;font-size:13px"><i class="fas fa-inbox"></i> No job orders found for this station.</p>
  <?php else: ?>
  <table style="width:100%;border-collapse:collapse;font-size:13px">
    <thead>
      <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
        <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Job Order ID</th>
        <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Customer</th>
        <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Service Type</th>
        <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Mechanic</th>
        <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Timestamp</th>
        <th style="text-align:center;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Status</th>
      </tr>
    </thead>
    <tbody id="jo-tbody">
    <?php foreach ($job_order_rows as $jo):
      $st  = $jo['display_status'] ?? $jo['status'] ?? 'Pending Validation';
      $cfg = $jo_status_cfg[$st] ?? ['bg'=>'#f3f4f6','color'=>'#374151','icon'=>'fa-circle'];
    ?>
      <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
        <td style="padding:9px 12px;font-weight:700;color:#00264D">
          <a href="joborder.php?id=<?= (int)($jo['id'] ?? 0) ?>" style="color:#00264D;text-decoration:none">
            <?= htmlspecialchars($jo['jo_ref']) ?>
          </a>
        </td>
        <td style="padding:9px 12px;color:#344054"><?= htmlspecialchars($jo['customer']) ?></td>
        <td style="padding:9px 12px;color:#344054"><?= htmlspecialchars($jo['service_type']) ?></td>
        <td style="padding:9px 12px;color:#667085"><?= htmlspecialchars($jo['mechanic']) ?></td>
        <td style="padding:9px 12px;color:#667085;font-size:12px;white-space:nowrap">
          <?= date('M j, Y g:i A', strtotime($jo['created_at'])) ?>
        </td>
        <td style="padding:9px 12px;text-align:center">
          <span style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap">
            <i class="fas <?= $cfg['icon'] ?>"></i> <?= htmlspecialchars($st) ?>
          </span>
          <?php if (!empty($jo['notes']) && $st === 'Rejected'): ?>
            <div style="font-size:10px;color:#991B1B;margin-top:3px"><?= htmlspecialchars(mb_strimwidth($jo['notes'],0,40,'…')) ?></div>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">
        <td colspan="5" style="padding:9px 12px;font-size:12px;color:#667085">
          Showing <?= count($job_order_rows) ?> most recent &bull;
          <a href="joborder.php" style="color:#2563eb;font-weight:600">View all job orders</a>
        </td>
        <td style="padding:9px 12px;text-align:center;font-size:12px;color:#667085">
          Total: <strong style="color:#00264D"><?= $pending_validation+$approved_validated+$in_progress+$completed+$rejected ?></strong>
        </td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
  </div>
</div>


<!-- ===== WIDGET 4: Fuel Monitoring ===== -->
<div class="widget-card" id="fuel-monitor-card">
  <h3><i class="fas fa-tachometer-alt"></i> Fuel Monitoring
    <span style="margin-left:8px;font-size:11px;font-weight:500;color:#667085">Tank Levels &bull; Variance &bull; Low Stock Alerts</span>
    <span id="fuel-refresh-ts" style="margin-left:auto;font-size:10px;color:#9ca3af;font-weight:400;flex-shrink:0"></span>
    <button onclick="refreshFuelWidget()" title="Refresh tank levels" style="margin-left:8px;background:none;border:1px solid #e5e7eb;border-radius:6px;padding:3px 8px;cursor:pointer;color:#667085;font-size:11px;flex-shrink:0">
      <i class="fas fa-sync-alt" id="fuel-refresh-icon"></i>
    </button>
    <?php if (in_array($role, ['manager', 'admin', 'superadmin'])): ?>
    <a href="fuel_reconciliation_manager.php" style="margin-left:6px;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;flex-shrink:0">
      <i class="fas fa-tools"></i> Manage
    </a>
    <?php endif; ?>
  </h3>

  <div id="fuel-monitor-body">
  
  <!-- Variance Alerts Banner -->
  <?php if (!empty($fuel_variance_alerts)): ?>
  <div style="background:#FEF3C7;border:1px solid #f59e0b;border-radius:8px;padding:12px;margin-bottom:16px">
    <div style="font-size:12px;font-weight:700;color:#92400E;margin-bottom:8px">
      <i class="fas fa-exclamation-triangle"></i> Variance Alerts Today
    </div>
    <?php foreach ($fuel_variance_alerts as $fva): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #fde68a;font-size:12px">
      <div>
        <strong style="color:#92400E"><?= htmlspecialchars($fva['fuel_type']) ?></strong>
        <div style="color:#78350f;font-size:11px">
          Sold: <?= number_format($fva['liters_sold'],2) ?>L &bull; Pump: <?= number_format($fva['pump_delta'],2) ?>L
        </div>
      </div>
      <div style="text-align:right">
        <strong style="color:#92400E"><?= number_format($fva['variance_liters'],2) ?>L</strong>
        <div style="color:#78350f;font-size:10px">TXN #<?= (int)$fva['transaction_id'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Low Stock Alerts Banner -->
  <?php 
  $low_stock_fuel = array_filter($fuel_stock_levels ?? [], function($fsl) {
    return $fsl['current_stock'] <= 500;
  });
  ?>
  <?php if (!empty($low_stock_fuel)): ?>
  <div style="background:#FEE2E2;border:1px solid #ef4444;border-radius:8px;padding:12px;margin-bottom:16px">
    <div style="font-size:12px;font-weight:700;color:#991B1B;margin-bottom:8px">
      <i class="fas fa-exclamation-circle"></i> Low Stock Alerts
    </div>
    <?php foreach ($low_stock_fuel as $fsl): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #fecaca;font-size:12px">
      <div>
        <strong style="color:#991B1B"><?= htmlspecialchars($fsl['fuel_type_name']) ?></strong>
        <div style="color:#7f1d1d;font-size:11px">Critical: <?= number_format($fsl['current_stock'],0) ?>L remaining</div>
      </div>
      <span style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">
        CRITICAL
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Tank Levels Table -->
  <?php if (empty($fuel_stock_levels)): ?>
    <p style="color:#9ca3af;text-align:center;padding:20px 0;font-size:13px">
      <i class="fas fa-inbox"></i> Fuel stock data unavailable.
    </p>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
          <th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Fuel Type</th>
          <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Current Level</th>
          <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Capacity</th>
          <th style="text-align:center;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $total_current = 0;
        $total_capacity = 0;
        foreach ($fuel_stock_levels as $fsl): 
          $total_current += $fsl['current_stock'];
          $total_capacity += $fsl['capacity'];
          $pct = ($fsl['capacity'] > 0) ? min(100, ($fsl['current_stock'] / $fsl['capacity']) * 100) : 0;
          
          // Determine status
          $status = 'OK';
          $status_color = '#22c55e';
          $status_bg = '#DCFCE7';
          if ($fsl['current_stock'] <= 500) {
            $status = 'Critical';
            $status_color = '#991B1B';
            $status_bg = '#FEE2E2';
          } elseif ($fsl['current_stock'] <= 2000) {
            $status = 'Low Stock';
            $status_color = '#92400E';
            $status_bg = '#FEF3C7';
          }
        ?>
        <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
          <td style="padding:10px 12px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="width:8px;height:8px;border-radius:50%;background:<?= $status_color ?>;flex-shrink:0"></span>
              <strong style="color:#00264D"><?= htmlspecialchars($fsl['fuel_type_name']) ?></strong>
            </div>
          </td>
          <td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">
            <?= number_format($fsl['current_stock'],0) ?> <span style="font-size:11px;color:#667085">L</span>
          </td>
          <td style="padding:10px 12px;text-align:right;color:#667085">
            <?= number_format($fsl['capacity'],0) ?> <span style="font-size:11px;color:#667085">L</span>
          </td>
          <td style="padding:10px 12px;text-align:center">
            <span style="background:<?= $status_bg ?>;color:<?= $status_color ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">
              <?= $status ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">
          <td style="padding:10px 12px;font-weight:800;color:#00264D">
            <i class="fas fa-sigma" style="margin-right:5px"></i>Total
          </td>
          <td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">
            <?= number_format($total_current,0) ?> <span style="font-size:11px;color:#667085">L</span>
          </td>
          <td style="padding:10px 12px;text-align:right;color:#667085">
            <?= number_format($total_capacity,0) ?> <span style="font-size:11px;color:#667085">L</span>
          </td>
          <td style="padding:10px 12px;text-align:center">
            <?php 
            $overall_pct = ($total_capacity > 0) ? ($total_current / $total_capacity) * 100 : 0;
            $overall_status = 'OK';
            $overall_color = '#22c55e';
            $overall_bg = '#DCFCE7';
            if ($overall_pct < 25) {
              $overall_status = 'Critical';
              $overall_color = '#991B1B';
              $overall_bg = '#FEE2E2';
            } elseif ($overall_pct < 50) {
              $overall_status = 'Low';
              $overall_color = '#92400E';
              $overall_bg = '#FEF3C7';
            }
            ?>
            <span style="background:<?= $overall_bg ?>;color:<?= $overall_color ?>;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">
              <?= $overall_status ?>
            </span>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
  </div><!-- /#fuel-monitor-body -->
</div>

<!-- ===== WIDGET 6: Clock-in / Clock-out ===== -->
<div class="widget-card">
  <h3><i class="fas fa-clock"></i> Shift Tracker</h3>
  <div class="clock-status-card">
    <?php if ($current_session): ?>
      <div style="font-size:22px;font-weight:800;color:#0369a1;margin-bottom:4px;letter-spacing:-0.5px">
        <span id="elapsed-time">--</span>
        <span style="font-size:13px;font-weight:500;color:#64748b;margin-left:4px">active</span>
      </div>
      <div style="font-size:12px;color:#667085;margin-bottom:4px">
        Since: <?= date('h:i A', strtotime($current_session['start_time'])) ?>
      </div>
      <div style="font-size:12px;color:#0369a1;font-weight:600;margin-bottom:10px">
        <i class="fas fa-circle" style="color:#22c55e;font-size:9px"></i>
        Clocked In &mdash; <?= htmlspecialchars($current_session['shift_name'] ?? '') ?>
      </div>
      <form method="POST" action="staff_dashboard.php?range=<?= htmlspecialchars($range) ?>">
        <input type="hidden" name="action" value="clock_out">
        <button type="submit" class="clock-btn clock-out-btn"><i class="fas fa-sign-out-alt"></i> Clock Out</button>
      </form>
    <?php else: ?>
      <div style="font-size:13px;color:#667085;margin-bottom:6px">
        <i class="fas fa-circle" style="color:#9ca3af;font-size:10px"></i> Not clocked in &mdash; <?= htmlspecialchars($current_shift_name) ?>
      </div>
      <form method="POST" action="staff_dashboard.php?range=<?= htmlspecialchars($range) ?>">
        <input type="hidden" name="action" value="clock_in">
        <button type="submit" class="clock-btn clock-in-btn"><i class="fas fa-sign-in-alt"></i> Clock In</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (!empty($my_shifts_today)): ?>
  <div style="font-size:12px;font-weight:700;color:#344054;margin-bottom:8px">Today's Timeline</div>
  <?php
    // Separate active from completed, then group completed by shift_name
    $active_sessions   = array_filter($my_shifts_today, fn($s) => !$s['end_time']);
    $completed_sessions = array_filter($my_shifts_today, fn($s) => $s['end_time']);

    // Group completed by shift_name
    $grouped = [];
    foreach ($completed_sessions as $s) {
        $grouped[$s['shift_name']][] = $s;
    }
  ?>

  <?php foreach ($active_sessions as $shift): ?>
  <div class="shift-timeline-item" style="background:#fffbeb;border-radius:8px;padding:8px 10px;margin-bottom:8px;border:1px solid #fde68a;">
    <div style="width:10px;height:10px;border-radius:50%;background:#f59e0b;flex-shrink:0;margin-top:3px;box-shadow:0 0 0 3px rgba(245,158,11,0.2)"></div>
    <div>
      <div style="font-weight:700;color:#0369a1;font-size:13px">
        <?= htmlspecialchars($shift['shift_name'] ?? '') ?>
        <span style="color:#f59e0b;font-size:11px;font-weight:700">&bull; Active</span>
      </div>
      <div style="color:#667085;font-size:11px">
        <?= date('h:i A', strtotime($shift['start_time'])) ?> &mdash; now
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <?php foreach ($grouped as $shift_name => $sessions): ?>
  <div style="margin-bottom:10px">
    <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;padding-left:2px">
      <?= htmlspecialchars($shift_name) ?>
    </div>
    <?php foreach ($sessions as $shift): ?>
    <div class="shift-timeline-item">
      <div style="width:8px;height:8px;border-radius:50%;background:#22c55e;flex-shrink:0;margin-top:4px"></div>
      <div>
        <div style="color:#667085;font-size:11px">
          <?= date('h:i A', strtotime($shift['start_time'])) ?> &mdash;
          <?= date('h:i A', strtotime($shift['end_time'])) ?>
          <?php if ($shift['hours_worked']): ?>&bull; <?= number_format($shift['hours_worked'], 2) ?> hrs<?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>
</div>

<!-- ===== WIDGET 7: Calendar Widget ===== -->
<div class="widget-card">
  <h3><i class="fas fa-calendar-alt"></i> Calendar</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <!-- Today -->
    <div>
      <div style="font-size:13px;font-weight:700;color:#00264D;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #EAEAEA">
        <i class="fas fa-sun"></i> Today &mdash; <?= date('F j, Y') ?>
      </div>
      <?php if (empty($calendar_today)): ?>
        <p style="color:#9ca3af;font-size:13px;padding:12px 0">No tasks scheduled for today.</p>
      <?php else: ?>
        <?php foreach ($calendar_today as $ct): ?>
        <div class="calendar-item">
          <div class="calendar-dot" style="background:<?= $ct['color_role']==='manager'?'#dc2626':'#2563eb' ?>"></div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span style="background:<?= $ct['type']==='job_order'?'#DBEAFE':'#D1FAE5' ?>;color:<?= $ct['type']==='job_order'?'#1E40AF':'#065F46' ?>;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px"><?= $ct['type']==='job_order'?'Job Order':'Delivery' ?></span>
              <span style="font-size:12px;font-weight:600;color:#101828"><?= htmlspecialchars($ct['ref'] ?? '') ?></span>
            </div>
            <div style="font-size:12px;color:#667085;margin-top:2px"><?= htmlspecialchars($ct['label'] ?? '') ?> &bull; <?= htmlspecialchars($ct['status'] ?? '') ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <!-- Upcoming -->
    <div>
      <div style="font-size:13px;font-weight:700;color:#00264D;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid #EAEAEA">
        <i class="fas fa-clock"></i> Upcoming (Next 3 Days)
      </div>
      <?php if (empty($calendar_upcoming)): ?>
        <p style="color:#9ca3af;font-size:13px;padding:12px 0">No upcoming tasks in the next 3 days.</p>
      <?php else: ?>
        <?php foreach ($calendar_upcoming as $cu): ?>
        <div class="calendar-item">
          <div class="calendar-dot" style="background:<?= $cu['color_role']==='manager'?'#dc2626':'#2563eb' ?>"></div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span style="background:<?= $cu['type']==='job_order'?'#DBEAFE':'#D1FAE5' ?>;color:<?= $cu['type']==='job_order'?'#1E40AF':'#065F46' ?>;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px"><?= $cu['type']==='job_order'?'Job Order':'Delivery' ?></span>
              <span style="font-size:12px;font-weight:600;color:#101828"><?= htmlspecialchars($cu['ref'] ?? '') ?></span>
              <span style="font-size:10px;color:#667085"><?= htmlspecialchars($cu['task_date'] ?? '') ?></span>
            </div>
            <div style="font-size:12px;color:#667085;margin-top:2px"><?= htmlspecialchars($cu['label'] ?? '') ?> &bull; <?= htmlspecialchars($cu['status'] ?? '') ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- .dashboard-grid -->

<!-- ===== QUICK ACTIONS ===== -->
<div class="widget-card widget-full" style="margin-top:0">
  <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
  <div class="quick-actions-grid">

    <!-- POS -->
    <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-cash-register"></i>
        <?php if ($qa_txns_today > 0): ?>
        <span class="qa-badge" id="qa-txns"><?= $qa_txns_today ?></span>
        <?php else: ?>
        <span class="qa-badge" id="qa-txns" style="display:none">0</span>
        <?php endif; ?>
      </div>
      <span>POS</span>
      <span class="qa-desc">Encode daily sales</span>
    </a>

    <!-- Credit Sale -->
    <a href="staff_transactions_hub.php?section=merchandise#credit" class="quick-action-btn">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-file-invoice-dollar"></i>
        <?php if ($qa_credit_today > 0): ?>
        <span class="qa-badge qa-badge-red" id="qa-credit"><?= $qa_credit_today ?></span>
        <?php else: ?>
        <span class="qa-badge qa-badge-red" id="qa-credit" style="display:none">0</span>
        <?php endif; ?>
      </div>
      <span>Credit Sale</span>
      <span class="qa-desc">Utang encoding</span>
    </a>

    <!-- Create Job Order -->
    <a href="joborder.php?action=create" class="quick-action-btn">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-wrench"></i>
        <?php if ($qa_pending_jo > 0): ?>
        <span class="qa-badge qa-badge-orange" id="qa-jo"><?= $qa_pending_jo ?></span>
        <?php else: ?>
        <span class="qa-badge qa-badge-orange" id="qa-jo" style="display:none">0</span>
        <?php endif; ?>
      </div>
      <span>Create Job Order</span>
      <span class="qa-desc">Encode service request</span>
    </a>

    <!-- Receive Items -->
    <a href="staff_deliveries.php" class="quick-action-btn">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-box-open"></i>
        <?php if ($qa_pending_del > 0): ?>
        <span class="qa-badge qa-badge-orange" id="qa-del"><?= $qa_pending_del ?></span>
        <?php else: ?>
        <span class="qa-badge qa-badge-orange" id="qa-del" style="display:none">0</span>
        <?php endif; ?>
      </div>
      <span>Receive Items</span>
      <span class="qa-desc">Log deliveries</span>
    </a>

    <!-- Fuel Transactions -->
    <a href="staff_transactions_hub.php?section=fuel" class="quick-action-btn">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-gas-pump"></i>
        <?php if ($qa_fuel_today > 0): ?>
        <span class="qa-badge" id="qa-fuel"><?= $qa_fuel_today ?></span>
        <?php else: ?>
        <span class="qa-badge" id="qa-fuel" style="display:none">0</span>
        <?php endif; ?>
      </div>
      <span>Fuel Transactions</span>
      <span class="qa-desc">Encode readings</span>
    </a>

    <!-- My Shift -->
    <a href="staff_shift_history.php" class="quick-action-btn <?= $qa_clocked_in ? 'qa-active' : '' ?>">
      <div style="position:relative;display:inline-block">
        <i class="fas fa-clock"></i>
        <?php if ($qa_clocked_in): ?>
        <span class="qa-badge qa-badge-green" id="qa-shift" title="Clocked in"><i class="fas fa-circle" style="font-size:8px"></i></span>
        <?php else: ?>
        <span class="qa-badge qa-badge-green" id="qa-shift" style="display:none"></span>
        <?php endif; ?>
      </div>
      <span>My Shift</span>
      <span class="qa-desc" id="qa-shift-desc"><?= $qa_clocked_in ? number_format($qa_shift_hours,1).'h today' : 'View shift history' ?></span>
    </a>

  </div>
</div>

<!-- ===== REPORTS SHORTCUTS ===== -->
<div class="widget-card widget-full" style="margin-top:20px">
  <h3><i class="fas fa-file-alt"></i> Reports Shortcuts
    <span style="margin-left:8px;font-size:11px;font-weight:500;color:#667085">Your personal reports — scoped to your station &amp; activity</span>
  </h3>
  <div class="reports-grid">
    <a href="staff_reports.php?view=job_order_report" class="report-btn">
      <i class="fas fa-clipboard-list"></i>
      <span>Job Orders Report</span>
      <span class="qa-desc">Your encoded job orders</span>
    </a>
    <a href="staff_reports.php?view=deliveries_report" class="report-btn">
      <i class="fas fa-truck"></i>
      <span>Deliveries Report</span>
      <span class="qa-desc">Received &amp; encoded deliveries</span>
    </a>
    <a href="staff_reports.php?view=customer_report" class="report-btn">
      <i class="fas fa-users"></i>
      <span>Customer Report</span>
      <span class="qa-desc">Basic info + your transactions</span>
    </a>
    <a href="staff_reports.php?view=transaction_report" class="report-btn">
      <i class="fas fa-receipt"></i>
      <span>Transaction Report</span>
      <span class="qa-desc">Merchandise &amp; credit sales</span>
    </a>
    <a href="staff_reports.php?view=personal_activity&user_id=<?= (int)$me['id'] ?>" class="report-btn">
      <i class="fas fa-user-clock"></i>
      <span>Personal Activity</span>
      <span class="qa-desc">Clock-in/out &amp; action logs</span>
    </a>
  </div>
</div>
</div><!-- .page-content -->

<script>
// ============================================================
// Chart.js Initialisation — removed (tables used instead)
// ============================================================
(function() {
    // Fuel Chart removed — replaced with table view
    var fuelCtx = null; // kept for AJAX compat
})();

// ============================================================
// updateMetricCards — rebuilds tables from AJAX data
// ============================================================
function fmt(v) {
    return '\u20B1' + parseFloat(v || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function pct(val, total) {
    if (!total) return '0.0';
    return (val / total * 100).toFixed(1);
}

function updateMetricCards(data) {
    var el = function(id) { return document.getElementById(id); };

    // ---- Merchandise Sales Table ----
    var payments = [
        {label:'Cash',        color:'#22c55e', icon:'fa-money-bill-wave', val:(data.merch_cash||0)+(data.jo_cash||0)},
        {label:'Card',        color:'#3b82f6', icon:'fa-credit-card',     val:(data.merch_card||0)+(data.jo_card||0)},
        {label:'E-Wallet',    color:'#a855f7', icon:'fa-mobile-alt',      val:(data.merch_ewallet||0)+(data.jo_ewallet||0)},
        {label:'E-Fuel Card', color:'#f59e0b', icon:'fa-gas-pump',        val:(data.merch_efuel||0)+(data.jo_efuel||0)},
        {label:'Credit/Utang','color':'#ef4444', icon:'fa-file-invoice',  val:(data.merch_credit||0)+(data.jo_credit||0)}
    ];
    var total = payments.reduce(function(s,p){return s+p.val;},0);
    var tbody = el('merch-tbody');
    if (tbody) {
        tbody.innerHTML = payments.map(function(p) {
            var share = pct(p.val, total);
            return '<tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                + '<td style="padding:9px 10px"><span style="display:inline-flex;align-items:center;gap:7px">'
                + '<span style="width:8px;height:8px;border-radius:50%;background:'+p.color+';flex-shrink:0;display:inline-block"></span>'
                + '<i class="fas '+p.icon+'" style="color:'+p.color+';font-size:12px"></i>'
                + '<span style="font-weight:600;color:#344054">'+p.label+'</span></span></td>'
                + '<td style="padding:9px 10px;text-align:right;font-weight:700;color:#101828">'+fmt(p.val)+'</td>'
                + '<td style="padding:9px 10px;text-align:right"><span style="background:'+p.color+'22;color:'+p.color+';font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px">'+share+'%</span></td>'
                + '</tr>';
        }).join('');
    }
    var tfoot = el('merch-tfoot');
    if (tfoot) {
        var txns = data.txn_today || 0;
        tfoot.innerHTML = '<tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">'
            + '<td style="padding:10px;font-weight:800;color:#00264D;font-size:13px"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>'
            + '<td style="padding:10px;text-align:right;font-weight:800;color:#00264D;font-size:14px">'+fmt(total)+'</td>'
            + '<td style="padding:10px;text-align:right;font-size:12px;color:#667085">'+txns+' txn'+(txns!==1?'s':'')+'</td>'
            + '</tr>';
    }

    // ---- Fuel Sales Table ----
    var fbt = data.fuel_by_type || {labels:[],liters:[],revenue:[],flags:[]};
    var labels  = fbt.labels  || [];
    var liters  = fbt.liters  || [];
    var revenue = fbt.revenue || [];
    var flags   = fbt.flags   || [];
    var ftbody = el('fuel-tbody');
    if (ftbody) {
        if (!labels.length) {
            ftbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;font-size:13px">'
                + '<i class="fas fa-inbox"></i> No fuel readings recorded for this period.</td></tr>';
        } else {
            var totalL = liters.reduce(function(s,v){return s+(v||0);},0);
            var totalR = revenue.reduce(function(s,v){return s+(v||0);},0);
            var totalT = (data.fuel_by_type_txns || []).reduce(function(s,v){return s+(v||0);},0);
            var hasAnyVariance = flags.some(function(f){return f;});
            ftbody.innerHTML = labels.map(function(lbl,i) {
                var hasVar = flags[i];
                var varBadge = hasVar
                    ? '<span style="background:#FEF3C7;color:#92400E;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px"><i class="fas fa-exclamation-triangle"></i> Variance</span>'
                    : '<span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px"><i class="fas fa-check"></i> OK</span>';
                return '<tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                    + '<td style="padding:10px 12px"><span style="display:inline-flex;align-items:center;gap:8px">'
                    + '<span style="width:10px;height:10px;border-radius:50%;background:#3b82f6;flex-shrink:0;display:inline-block"></span>'
                    + '<strong style="color:#00264D">'+lbl+'</strong></span></td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">'+parseFloat(liters[i]||0).toFixed(2)+' <span style="font-size:11px;color:#667085;font-weight:400">L</span></td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">\u20B1'+parseFloat(revenue[i]||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+'</td>'
                    + '<td style="padding:10px 12px;text-align:right;color:#667085">&mdash;</td>'
                    + '<td style="padding:10px 12px;text-align:right;color:#667085">&mdash;</td>'
                    + '<td style="padding:10px 12px;text-align:center">'+varBadge+'</td>'
                    + '</tr>';
            }).join('');
            // update fuel tfoot
            var ftfoot = el('fuel-tfoot');
            if (ftfoot) {
                var sumBadge = hasAnyVariance
                    ? '<span style="background:#FEE2E2;color:#991B1B;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px"><i class="fas fa-exclamation-circle"></i> Check Readings</span>'
                    : '<span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px"><i class="fas fa-check-circle"></i> All OK</span>';
                ftfoot.innerHTML = '<tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">'
                    + '<td style="padding:10px 12px;font-weight:800;color:#00264D"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">'+totalL.toFixed(2)+' L</td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">\u20B1'+totalR.toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})+'</td>'
                    + '<td style="padding:10px 12px;text-align:right;color:#667085">&mdash;</td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#00264D">&mdash;</td>'
                    + '<td style="padding:10px 12px;text-align:center">'+sumBadge+'</td>'
                    + '</tr>';
            }
        }
    }

    // ---- Job Orders Status Cards ----
    if (el('jo-pending'))    el('jo-pending').textContent    = data.pending_validation || 0;
    if (el('jo-approved'))   el('jo-approved').textContent   = data.approved_validated || 0;
    if (el('jo-inprogress')) el('jo-inprogress').textContent = data.in_progress || 0;
    if (el('jo-completed'))  el('jo-completed').textContent  = data.completed || 0;
    if (el('jo-rejected'))   el('jo-rejected').textContent   = data.rejected || 0;

    // ---- Job Orders Detail Table ----
    var joStatusCfg = {
        'Pending Validation': {bg:'#FFF3CD',color:'#92400E',icon:'fa-hourglass-half'},
        'Approved':           {bg:'#D1FAE5',color:'#065F46',icon:'fa-check-circle'},
        'Validated':          {bg:'#D1FAE5',color:'#065F46',icon:'fa-check-circle'},
        'In Progress':        {bg:'#DBEAFE',color:'#1E40AF',icon:'fa-spinner'},
        'Completed':          {bg:'#DCFCE7',color:'#14532D',icon:'fa-flag-checkered'},
        'Rejected':           {bg:'#FEE2E2',color:'#991B1B',icon:'fa-times-circle'}
    };
    var joTbody = el('jo-tbody');
    if (joTbody && data.job_order_rows) {
        var rows = data.job_order_rows;
        if (!rows.length) {
            joTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;font-size:13px"><i class="fas fa-inbox"></i> No job orders found.</td></tr>';
        } else {
            joTbody.innerHTML = rows.map(function(jo) {
                var st  = jo.display_status || jo.status || 'Pending Validation';
                var cfg = joStatusCfg[st] || {bg:'#f3f4f6',color:'#374151',icon:'fa-circle'};
                var ts  = jo.created_at ? new Date(jo.created_at).toLocaleString('en-PH',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '—';
                var remarks = (st === 'Rejected' && jo.notes)
                    ? '<div style="font-size:10px;color:#991B1B;margin-top:3px">' + jo.notes.substring(0,40) + (jo.notes.length>40?'…':'') + '</div>'
                    : '';
                return '<tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                    + '<td style="padding:9px 12px;font-weight:700;color:#00264D">' + (jo.jo_ref||'—') + '</td>'
                    + '<td style="padding:9px 12px;color:#344054">' + (jo.customer||'—') + '</td>'
                    + '<td style="padding:9px 12px;color:#344054">' + (jo.service_type||'—') + '</td>'
                    + '<td style="padding:9px 12px;color:#667085">' + (jo.mechanic||'—') + '</td>'
                    + '<td style="padding:9px 12px;color:#667085;font-size:12px;white-space:nowrap">' + ts + '</td>'
                    + '<td style="padding:9px 12px;text-align:center">'
                    + '<span style="background:'+cfg.bg+';color:'+cfg.color+';font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px;white-space:nowrap">'
                    + '<i class="fas '+cfg.icon+'"></i> '+st+'</span>'
                    + remarks + '</td>'
                    + '</tr>';
            }).join('');
        }
    }

    // ---- Update Job Orders doughnut chart ----
    if (window._joChart) {
        window._joChart.data.datasets[0].data = [
            data.pending_validation||0, data.approved_validated||0,
            data.in_progress||0, data.completed||0, data.rejected||0
        ];
        window._joChart.update();
    }

    // ---- Last refreshed indicator ----
    var lr = el('last-refreshed');
    if (lr) {
        var now = new Date();
        lr.textContent = 'Last updated: ' + now.toLocaleTimeString('en-PH', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
    }
}

// Helper: update a qa badge element
function updateQaBadge(id, count) {
    var el = document.getElementById(id);
    if (!el) return;
    if (count > 0) {
        el.textContent = count > 99 ? '99+' : count;
        el.style.display = 'inline-flex';
    } else {
        el.style.display = 'none';
    }
}

function updateQaBadges(data) {
    updateQaBadge('qa-txns',   data.qa_txns_today   || 0);
    updateQaBadge('qa-credit', data.qa_credit_today  || 0);
    updateQaBadge('qa-jo',     data.qa_pending_jo    || 0);
    updateQaBadge('qa-del',    data.qa_pending_del   || 0);
    updateQaBadge('qa-fuel',   data.qa_fuel_today    || 0);
    // Shift badge — show green dot if clocked in (qa_clocked_in not in refresh, use shift count as proxy)
    var shiftBadge = document.getElementById('qa-shift');
    if (shiftBadge) {
        // If pending_validation changed it means data is fresh — keep shift badge as-is (clock state needs POST)
    }
}

// ============================================================
// AJAX Polling — every 5 seconds for near real-time updates
// ============================================================
setInterval(function() {
    fetch('staff_dashboard.php?refresh=1&range=<?= $range ?>')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                updateMetricCards(data);
                updateQaBadges(data);
            }
        })
        .catch(function() {});
}, 5000);

// ============================================================
// Fuel Widget Live Refresh
// ============================================================
function refreshFuelWidget() {
    var icon = document.getElementById('fuel-refresh-icon');
    if (icon) { icon.style.animation = 'spin 0.8s linear infinite'; }

    fetch('staff_dashboard.php?refresh_fuel=1')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (icon) icon.style.animation = '';
            if (!data.success) return;

            var ts = document.getElementById('fuel-refresh-ts');
            if (ts) ts.textContent = 'Updated ' + data.refreshed_at;

            var levels = data.fuel_levels || [];
            var body   = document.getElementById('fuel-monitor-body');
            if (!body) return;

            if (levels.length === 0) {
                body.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:20px 0;font-size:13px"><i class="fas fa-inbox"></i> Fuel stock data unavailable.</p>';
                return;
            }

            var totalCurrent  = 0;
            var totalCapacity = 0;
            var rows = '';
            var lowAlerts = '';

            levels.forEach(function(f) {
                var cur = parseFloat(f.current_stock) || 0;
                var cap = parseFloat(f.capacity)      || 0;
                totalCurrent  += cur;
                totalCapacity += cap;
                var pct = cap > 0 ? Math.min(100, (cur / cap) * 100) : 0;

                var statusLabel = 'OK';
                var statusColor = '#22c55e';
                var statusBg    = '#DCFCE7';
                if (cur <= 500) {
                    statusLabel = 'Critical'; statusColor = '#991B1B'; statusBg = '#FEE2E2';
                    lowAlerts += '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #fecaca;font-size:12px">'
                        + '<div><strong style="color:#991B1B">' + escHtml(f.fuel_type_name) + '</strong>'
                        + '<div style="color:#7f1d1d;font-size:11px">Critical: ' + Math.round(cur).toLocaleString() + 'L remaining</div></div>'
                        + '<span style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:12px">CRITICAL</span></div>';
                } else if (cur <= 2000) {
                    statusLabel = 'Low Stock'; statusColor = '#92400E'; statusBg = '#FEF3C7';
                }

                rows += '<tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                    + '<td style="padding:10px 12px"><div style="display:flex;align-items:center;gap:8px">'
                    + '<span style="width:8px;height:8px;border-radius:50%;background:' + statusColor + ';flex-shrink:0"></span>'
                    + '<strong style="color:#00264D">' + escHtml(f.fuel_type_name) + '</strong></div></td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">'
                    + Math.round(cur).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                    + '<td style="padding:10px 12px;text-align:right;color:#667085">'
                    + Math.round(cap).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                    + '<td style="padding:10px 12px;text-align:center">'
                    + '<span style="background:' + statusBg + ';color:' + statusColor + ';font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">' + statusLabel + '</span></td></tr>';
            });

            // Overall footer
            var overallPct    = totalCapacity > 0 ? (totalCurrent / totalCapacity) * 100 : 0;
            var overallStatus = 'OK';
            var overallColor  = '#22c55e';
            var overallBg     = '#DCFCE7';
            if (overallPct < 25) { overallStatus = 'Critical'; overallColor = '#991B1B'; overallBg = '#FEE2E2'; }
            else if (overallPct < 50) { overallStatus = 'Low'; overallColor = '#92400E'; overallBg = '#FEF3C7'; }

            var html = '';
            if (lowAlerts) {
                html += '<div style="background:#FEE2E2;border:1px solid #ef4444;border-radius:8px;padding:12px;margin-bottom:16px">'
                    + '<div style="font-size:12px;font-weight:700;color:#991B1B;margin-bottom:8px"><i class="fas fa-exclamation-circle"></i> Low Stock Alerts</div>'
                    + lowAlerts + '</div>';
            }
            html += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">'
                + '<thead><tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">'
                + '<th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Fuel Type</th>'
                + '<th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Current Level</th>'
                + '<th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Capacity</th>'
                + '<th style="text-align:center;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Status</th>'
                + '</tr></thead><tbody>' + rows + '</tbody>'
                + '<tfoot><tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">'
                + '<td style="padding:10px 12px;font-weight:800;color:#00264D"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>'
                + '<td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">' + Math.round(totalCurrent).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                + '<td style="padding:10px 12px;text-align:right;color:#667085">' + Math.round(totalCapacity).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                + '<td style="padding:10px 12px;text-align:center"><span style="background:' + overallBg + ';color:' + overallColor + ';font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">' + overallStatus + '</span></td>'
                + '</tr></tfoot></table></div>';

            body.innerHTML = html;
        })
        .catch(function() {
            if (icon) icon.style.animation = '';
        });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Spin animation for refresh icon
(function() {
    var style = document.createElement('style');
    style.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
})();

// Auto-refresh fuel widget every 5 seconds (same cadence as main polling)
setInterval(refreshFuelWidget, 5000);

// ============================================================
// Elapsed time counter for active clock-in session
// ============================================================
<?php if ($current_session): ?>
(function() {
    var startTime = new Date('<?= addslashes($current_session['start_time']) ?>').getTime();
    function updateElapsed() {
        var now = Date.now();
        var diff = Math.floor((now - startTime) / 1000);
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        var el = document.getElementById('elapsed-time');
        if (el) el.textContent = (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's';
    }
    updateElapsed();
    setInterval(updateElapsed, 1000);
})();
<?php endif; ?>
</script>

<?php
if (file_exists(__DIR__ . '/../partials/footer.php')) {
    require_once __DIR__ . '/../partials/footer.php';
}
?>
