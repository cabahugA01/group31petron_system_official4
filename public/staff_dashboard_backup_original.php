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
        $lc = $pdo->prepare("SELECT COUNT(*) FROM station_inventory WHERE station_id=? AND status = 'Active' AND stock_level<=reorder_level"); $lc->execute([$station_id]); $lcc = (int)$lc->fetchColumn();        echo json_encode([
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
// AJAX ENDPOINT: ?refresh_stock_charts=1  — stock chart data for dashboard
// ============================================================
if (isset($_GET['refresh_stock_charts']) && $_GET['refresh_stock_charts'] == '1') {
    header('Content-Type: application/json');
    try {
        // Fuel: only Critical (<=10% fill) and Low Stock (<=25% fill) — exclude Normal
        $fsl = $pdo->prepare("
            SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
                   COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
                   COALESCE(fi.capacity, 0) AS capacity,
                   fi.id AS fuel_inv_id,
                   CASE
                       WHEN COALESCE(fi.current_level, fi.current_stock, 0) <= 0 THEN 'Out of Stock'
                       WHEN COALESCE(fi.capacity, 0) > 0
                            AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 10 THEN 'Critical'
                       ELSE 'Low Stock'
                   END AS stock_status
            FROM fuel_inventory fi
            LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
            WHERE fi.station_id = ?
              AND (
                  COALESCE(fi.current_level, fi.current_stock, 0) <= 0
                  OR (
                      COALESCE(fi.capacity, 0) > 0
                      AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 25
                  )
              )
            ORDER BY current_stock ASC
        ");
        $fsl->execute([$station_id]);
        $fuel_levels = $fsl->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Merchandise: ONLY out of stock and low stock items
        $merch_data = [];
        try {
            $msi = $pdo->prepare("
                SELECT COALESCE(si.product_name, ip.product_name, CONCAT('Product #', si.product_id)) AS product_name,
                       si.stock_level AS current_stock,
                       COALESCE(si.reorder_level, 10) AS threshold,
                       si.id AS inv_id,
                       si.product_id,
                       COALESCE(ip.category, 'Merchandise') AS category,
                       COALESCE(si.unit, ip.size, 'pcs') AS unit,
                       CASE
                           WHEN si.stock_level <= 0 THEN 'Out of Stock'
                           ELSE 'Low Stock'
                       END AS stock_status
                FROM station_inventory si
                LEFT JOIN inventory_products ip ON ip.id = si.product_id
                WHERE si.station_id = ? AND si.status = 'Active'
                  AND (si.product_name IS NOT NULL AND si.product_name != '')
                  AND si.stock_level <= COALESCE(si.reorder_level, 10)
                ORDER BY si.stock_level ASC
                LIMIT 25
            ");
            $msi->execute([$station_id]);
            $merch_data = $msi->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}

        // Fallback to inventory table if station_inventory is empty
        if (empty($merch_data)) {
            try {
                $msi2 = $pdo->prepare("
                    SELECT COALESCE(ip.product_name, CONCAT('Product #', i.product_id)) AS product_name,
                           i.stock_level AS current_stock,
                           COALESCE(i.reorder_level, 10) AS threshold,
                           i.id AS inv_id,
                           i.product_id,
                           COALESCE(ip.category, 'Merchandise') AS category,
                           COALESCE(i.unit, 'pcs') AS unit,
                           CASE
                               WHEN i.stock_level <= 0 THEN 'Out of Stock'
                               ELSE 'Low Stock'
                           END AS stock_status
                    FROM inventory i
                    LEFT JOIN inventory_products ip ON ip.id = i.product_id
                    WHERE i.station_id = ?
                      AND i.stock_level <= COALESCE(i.reorder_level, 10)
                    ORDER BY i.stock_level ASC
                    LIMIT 25
                ");
                $msi2->execute([$station_id]);
                $merch_data = $msi2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
        }

        echo json_encode([
            'success'      => true,
            'fuel_stocks'  => $fuel_levels,
            'merch_stocks' => $merch_data,
            'station_id'   => $station_id,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX ENDPOINT: ?refresh_fuel=1  — live fuel level monitor
// ============================================================
if (isset($_GET['refresh_fuel']) && $_GET['refresh_fuel'] == '1') {
    header('Content-Type: application/json');
    try {
        $fsl = $pdo->prepare("
            SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
                   COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
                   COALESCE(fi.capacity, 0) AS capacity,
                   COALESCE(fi.price_per_liter, 0) AS price_per_liter
            FROM fuel_inventory fi
            LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
            WHERE fi.station_id = ?
            ORDER BY fuel_type_name ASC
        ");
        $fsl->execute([$station_id]);
        $fuel_levels = $fsl->fetchAll(PDO::FETCH_ASSOC) ?: [];
        echo json_encode([
            'success'      => true,
            'fuel_levels'  => $fuel_levels,
            'refreshed_at' => date('h:i:s A'),
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
$merch_cash = 0; $merch_card = 0; $merch_ewallet = 0; $merch_efuel = 0; $merch_credit = 0; $merch_fleet = 0;
$jo_cash = 0; $jo_card = 0; $jo_ewallet = 0; $jo_efuel = 0; $jo_credit = 0; $jo_fleet = 0;
$fuel_cash = 0; $fuel_card = 0; $fuel_ewallet = 0; $fuel_efuel = 0; $fuel_credit = 0; $fuel_fleet = 0;
try {
    $ms = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) AS merch_sales, COUNT(*) AS merch_count,
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS merch_cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card','Debit Card') THEN total_amount ELSE 0 END),0) AS merch_card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet','GCash/Maya') THEN total_amount ELSE 0 END),0) AS merch_ewallet,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS merch_efuel,
        COALESCE(SUM(CASE WHEN payment_method IN ('Fleet Card','Fleet','fleet card') THEN total_amount ELSE 0 END),0) AS merch_fleet,
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
    $merch_fleet  = (float)($mr['merch_fleet'] ?? 0);
    $merch_credit = (float)($mr['merch_credit'] ?? 0);
    // Job Orders payment breakdown
    $js = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_cost ELSE 0 END),0) AS jo_cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card','Debit Card') THEN total_cost ELSE 0 END),0) AS jo_card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet','GCash/Maya') THEN total_cost ELSE 0 END),0) AS jo_ewallet,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_cost ELSE 0 END),0) AS jo_efuel,
        COALESCE(SUM(CASE WHEN payment_method IN ('Fleet Card','Fleet','fleet card') THEN total_cost ELSE 0 END),0) AS jo_fleet,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_cost ELSE 0 END),0) AS jo_credit
        FROM job_orders WHERE station_id = ? AND status = 'Completed' AND {$date_cond_jo}");
    $js->execute([$station_id]);
    $jr = $js->fetch(PDO::FETCH_ASSOC) ?: [];
    $jo_cash    = (float)($jr['jo_cash'] ?? 0);
    $jo_card    = (float)($jr['jo_card'] ?? 0);
    $jo_ewallet = (float)($jr['jo_ewallet'] ?? 0);
    $jo_efuel   = (float)($jr['jo_efuel'] ?? 0);
    $jo_fleet   = (float)($jr['jo_fleet'] ?? 0);
    $jo_credit  = (float)($jr['jo_credit'] ?? 0);
    // Fuel transactions payment breakdown
    $fs2 = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN payment_method IN ('Cash','cash') THEN total_amount ELSE 0 END),0) AS fuel_cash,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit Card','Card','card','Debit Card') THEN total_amount ELSE 0 END),0) AS fuel_card,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Wallet','GCash','Maya','ewallet','GCash/Maya') THEN total_amount ELSE 0 END),0) AS fuel_ewallet,
        COALESCE(SUM(CASE WHEN payment_method IN ('E-Fuel Card','Fuel Card','efuel') THEN total_amount ELSE 0 END),0) AS fuel_efuel,
        COALESCE(SUM(CASE WHEN payment_method IN ('Fleet Card','Fleet','fleet card') THEN total_amount ELSE 0 END),0) AS fuel_fleet,
        COALESCE(SUM(CASE WHEN payment_method IN ('Credit','Account Receivable','utang','Utang') THEN total_amount ELSE 0 END),0) AS fuel_credit
        FROM fuel_transactions WHERE station_id = ? AND {$date_cond_txn}");
    $fs2->execute([$station_id]);
    $fr2 = $fs2->fetch(PDO::FETCH_ASSOC) ?: [];
    $fuel_cash    = (float)($fr2['fuel_cash'] ?? 0);
    $fuel_card    = (float)($fr2['fuel_card'] ?? 0);
    $fuel_ewallet = (float)($fr2['fuel_ewallet'] ?? 0);
    $fuel_efuel   = (float)($fr2['fuel_efuel'] ?? 0);
    $fuel_fleet   = (float)($fr2['fuel_fleet'] ?? 0);
    $fuel_credit  = (float)($fr2['fuel_credit'] ?? 0);
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

// Sales Performance: 7-day trend data for charts
$sales_trend_labels  = [];
$sales_trend_fuel    = [];
$sales_trend_merch   = [];
$sales_trend_liters  = [];
try {
    // Generate last 7 days labels
    for ($i = 6; $i >= 0; $i--) {
        $sales_trend_labels[] = date('M j', strtotime("-$i days"));
    }
    // Fuel daily revenue + liters (last 7 days, no liters_sold filter so zero days still show)
    $ft7 = $pdo->prepare("
        SELECT DATE(transaction_date) AS d,
               COALESCE(SUM(total_amount),0) AS revenue,
               COALESCE(SUM(liters_sold),0)  AS liters
        FROM fuel_transactions
        WHERE station_id = ? AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(transaction_date)
    ");
    $ft7->execute([$station_id]);
    $fuel_daily = [];
    foreach ($ft7->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fuel_daily[$row['d']] = ['revenue' => (float)$row['revenue'], 'liters' => (float)$row['liters']];
    }
    // If no fuel data in last 7 days, try last 30 days and pick last 7 active days
    if (empty(array_filter($fuel_daily, fn($d) => $d['revenue'] > 0))) {
        $ft30 = $pdo->prepare("
            SELECT DATE(transaction_date) AS d,
                   COALESCE(SUM(total_amount),0) AS revenue,
                   COALESCE(SUM(liters_sold),0)  AS liters
            FROM fuel_transactions
            WHERE station_id = ? AND total_amount > 0
            GROUP BY DATE(transaction_date)
            ORDER BY d DESC LIMIT 7
        ");
        $ft30->execute([$station_id]);
        $rows30 = array_reverse($ft30->fetchAll(PDO::FETCH_ASSOC));
        if (!empty($rows30)) {
            $sales_trend_labels = [];
            $fuel_daily = [];
            foreach ($rows30 as $row) {
                $label = date('M j', strtotime($row['d']));
                $sales_trend_labels[] = $label;
                $fuel_daily[$row['d']] = ['revenue' => (float)$row['revenue'], 'liters' => (float)$row['liters']];
            }
        }
    }
    // Merchandise daily revenue (direct sales + job order parts)
    $mt7 = $pdo->prepare("
        SELECT DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) AS d,
               COALESCE(SUM(total_amount),0) AS revenue
        FROM merchandise_transactions
        WHERE station_id = ?
          AND COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY d
    ");
    $mt7->execute([$station_id]);
    $merch_daily = [];
    foreach ($mt7->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $merch_daily[$row['d']] = (float)$row['revenue'];
    }
    // Job order parts/service revenue — add to merch
    try {
        $jt7 = $pdo->prepare("
            SELECT DATE(created_at) AS d,
                   COALESCE(SUM(total_cost),0) AS revenue
            FROM job_orders
            WHERE station_id = ? AND status = 'Completed'
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
              AND total_cost > 0
            GROUP BY d
        ");
        $jt7->execute([$station_id]);
        foreach ($jt7->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $merch_daily[$row['d']] = ($merch_daily[$row['d']] ?? 0) + (float)$row['revenue'];
        }
    } catch (Exception $e) {}
    // If merch also empty in last 7 days, extend to last 30 days
    if (empty(array_filter($merch_daily, fn($v) => $v > 0))) {
        $mt30 = $pdo->prepare("
            SELECT DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) AS d,
                   COALESCE(SUM(total_amount),0) AS revenue
            FROM merchandise_transactions
            WHERE station_id = ? AND total_amount > 0
            GROUP BY d ORDER BY d DESC LIMIT 7
        ");
        $mt30->execute([$station_id]);
        $mrows30 = array_reverse($mt30->fetchAll(PDO::FETCH_ASSOC));
        if (!empty($mrows30)) {
            $merch_daily = [];
            foreach ($mrows30 as $row) {
                $merch_daily[$row['d']] = (float)$row['revenue'];
            }
        }
    }
    // Build all_dates as a union of fuel and merch dates, take the last 7
    $combined_dates = array_unique(array_merge(
        array_keys($fuel_daily),
        array_keys($merch_daily)
    ));
    sort($combined_dates);
    $combined_dates = array_slice($combined_dates, -7);
    // If we have combined dates, use them; otherwise fall back to last 7 calendar days
    if (!empty($combined_dates)) {
        $all_dates = $combined_dates;
        $sales_trend_labels = array_map(fn($d) => date('M j', strtotime($d)), $all_dates);
    } else {
        $all_dates = [];
        $sales_trend_labels = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $all_dates[] = $d;
            $sales_trend_labels[] = date('M j', strtotime($d));
        }
    }
    // Build final aligned arrays
    $sales_trend_fuel   = [];
    $sales_trend_liters = [];
    $sales_trend_merch  = [];
    foreach ($all_dates as $day) {
        $sales_trend_fuel[]   = $fuel_daily[$day]['revenue']  ?? 0;
        $sales_trend_liters[] = $fuel_daily[$day]['liters']   ?? 0;
        $sales_trend_merch[]  = $merch_daily[$day]            ?? 0;
    }
} catch (Exception $e) {
    // fallback: empty 7-day arrays
    for ($i = 6; $i >= 0; $i--) {
        $sales_trend_labels[] = date('M j', strtotime("-$i days"));
        $sales_trend_fuel[]   = 0;
        $sales_trend_liters[] = 0;
        $sales_trend_merch[]  = 0;
    }
}

// Widget 3: Job order status counts + detail rows
$pending_validation = 0; $approved_validated = 0; $in_progress = 0; $completed = 0; $rejected = 0;
$job_order_rows = [];
try {
    $pv = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status='Pending Validation' OR status='Pending' OR validation_status='Pending Validation')"); $pv->execute([$station_id]); $pending_validation = (int)$pv->fetchColumn();
    $av = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND (status IN ('Approved','Validated') OR validation_status IN ('Approved','Validated'))"); $av->execute([$station_id]); $approved_validated = (int)$av->fetchColumn();
    $ip = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='In Progress'"); $ip->execute([$station_id]); $in_progress = (int)$ip->fetchColumn();
    $cp = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Completed'"); $cp->execute([$station_id]); $completed = (int)$cp->fetchColumn();
    $rj = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id=? AND status='Rejected'"); $rj->execute([$station_id]); $rejected = (int)$rj->fetchColumn();

    // Detail rows — most recent 20, all statuses, scoped to station
    $jod = $pdo->prepare("
        SELECT
            COALESCE(jo.job_order_id, jo.job_order_number, CONCAT('JO-', jo.id)) AS jo_ref,
            jo.id,
            COALESCE(c.name, jo.customer_name, 'Walk-in')                  AS customer,
            COALESCE(jo.service_type, jo.service_description, '—')         AS service_type,
            COALESCE(u.name, u.username, m.full_name, '—')         AS mechanic,
            jo.created_at,
            jo.status,
            COALESCE(jo.validation_status, jo.status)                      AS display_status,
            jo.notes
        FROM job_orders jo
        LEFT JOIN users u      ON u.id = jo.assigned_mechanic_id
        LEFT JOIN mechanics m  ON m.id  = jo.assigned_mechanic_id
        LEFT JOIN customers c  ON c.id  = jo.customer_id
        WHERE jo.station_id = ?
        ORDER BY jo.created_at DESC
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
        WHERE station_id = ? AND status = 'Active' AND stock_level <= reorder_level
        ORDER BY shortage DESC LIMIT 10");
    $lsi->execute([$station_id]);
    $low_stock_items = $lsi->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Widget: Stock Charts — ALL fuel types + low/out-of-stock merchandise only
$stock_chart_fuel = [];
$stock_chart_merch = [];
try {
    // Fuel: only Critical (<=10% fill) and Low Stock (<=25% fill) — exclude Normal
    $scf = $pdo->prepare("
        SELECT COALESCE(ft.name, fi.fuel_type) AS fuel_type_name,
               COALESCE(fi.current_level, fi.current_stock, 0) AS current_stock,
               COALESCE(fi.capacity, 0) AS capacity,
               fi.id AS fuel_inv_id,
               CASE
                   WHEN COALESCE(fi.current_level, fi.current_stock, 0) <= 0 THEN 'Out of Stock'
                   WHEN COALESCE(fi.capacity, 0) > 0
                        AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 10 THEN 'Critical'
                   ELSE 'Low Stock'
               END AS stock_status
        FROM fuel_inventory fi
        LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id
        WHERE fi.station_id = ?
          AND (
              COALESCE(fi.current_level, fi.current_stock, 0) <= 0
              OR (
                  COALESCE(fi.capacity, 0) > 0
                  AND (COALESCE(fi.current_level, fi.current_stock, 0) / COALESCE(fi.capacity, 0)) * 100 <= 25
              )
          )
        ORDER BY current_stock ASC
    ");
    $scf->execute([$station_id]);
    $stock_chart_fuel = $scf->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
try {
    // Merchandise: only Out of Stock (stock_level <= 0) and Low Stock (stock_level <= reorder_level)
    $scm = $pdo->prepare("
        SELECT COALESCE(si.product_name, ip.product_name, CONCAT('Product #', si.product_id)) AS product_name,
               si.stock_level AS current_stock,
               COALESCE(si.reorder_level, 10) AS threshold,
               si.id AS inv_id,
               si.product_id,
               COALESCE(ip.category, 'Merchandise') AS category,
               COALESCE(si.unit, ip.size, 'pcs') AS unit,
               CASE
                   WHEN si.stock_level <= 0 THEN 'Out of Stock'
                   ELSE 'Low Stock'
               END AS stock_status
        FROM station_inventory si
        LEFT JOIN inventory_products ip ON ip.id = si.product_id
        WHERE si.station_id = ? AND si.status = 'Active'
          AND (si.product_name IS NOT NULL AND si.product_name != '')
          AND si.stock_level <= COALESCE(si.reorder_level, 10)
        ORDER BY si.stock_level ASC
        LIMIT 25
    ");
    $scm->execute([$station_id]);
    $stock_chart_merch = $scm->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}
// Fallback: if station_inventory is empty, try inventory table joined with inventory_products
if (empty($stock_chart_merch)) {
    try {
        $scm2 = $pdo->prepare("
            SELECT COALESCE(ip.product_name, CONCAT('Product #', i.product_id)) AS product_name,
                   i.stock_level AS current_stock,
                   COALESCE(i.reorder_level, 10) AS threshold,
                   i.id AS inv_id,
                   i.product_id,
                   COALESCE(ip.category, 'Merchandise') AS category,
                   COALESCE(i.unit, 'pcs') AS unit,
                   CASE
                       WHEN i.stock_level <= 0 THEN 'Out of Stock'
                       ELSE 'Low Stock'
                   END AS stock_status
            FROM inventory i
            LEFT JOIN inventory_products ip ON ip.id = i.product_id
            WHERE i.station_id = ?
              AND i.stock_level <= COALESCE(i.reorder_level, 10)
            ORDER BY i.stock_level ASC
            LIMIT 25
        ");
        $scm2->execute([$station_id]);
        $stock_chart_merch = $scm2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}
}

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

// ============================================================
// CUSTOMER OVERVIEW DATA
// ============================================================
$new_customers_today = 0;
$recent_customers = [];
try {
    // Count new customers added today
    $nc = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id = ? AND DATE(created_at) = CURDATE()");
    $nc->execute([$station_id]);
    $new_customers_today = (int)$nc->fetchColumn();
    
    // Get recent customers with transaction info
    $rc = $pdo->prepare("
        SELECT c.id, c.name, c.contact_number, 
               COALESCE(c.outstanding_balance, 0) AS balance,
               COUNT(DISTINCT mt.id) AS txn_count,
               MAX(mt.created_at) AS last_txn
        FROM customers c
        LEFT JOIN merchandise_transactions mt ON mt.customer_id = c.id AND mt.station_id = ?
        WHERE c.station_id = ?
        GROUP BY c.id
        ORDER BY last_txn DESC, c.created_at DESC
        LIMIT 5
    ");
    $rc->execute([$station_id, $station_id]);
    $recent_customers = $rc->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ============================================================
// ACTIVITY LOG TIMELINE DATA (Recent 10 activities)
// ============================================================
$recent_activities = [];
try {
    $ra = $pdo->prepare("
        SELECT al.action_type, al.action_details, al.created_at, u.username
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE u.station_id = ? OR al.user_id = ?
        ORDER BY al.created_at DESC
        LIMIT 10
    ");
    $ra->execute([$station_id, $me['id']]);
    $recent_activities = $ra->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    // Fallback: try user_sessions for login activities
    try {
        $ls = $pdo->prepare("
            SELECT 'Login' AS action_type, 
                   'User logged in' AS action_details,
                   us.login_time AS created_at,
                   u.username
            FROM user_sessions us
            LEFT JOIN users u ON us.user_id = u.id
            WHERE u.station_id = ? AND DATE(us.login_time) = CURDATE()
            ORDER BY us.login_time DESC
            LIMIT 10
        ");
        $ls->execute([$station_id]);
        $recent_activities = $ls->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e2) {}
}

// ============================================================
// SHIFT SUMMARIES DATA (Shift 1, Shift 2, Overall Daily)
// ============================================================
// Helper to get payments breakdown for a given hour range
function get_shift_payments_breakdown($pdo, $station_id, $start_hour, $end_hour) {
    $totals = ['cash' => 0.0, 'card' => 0.0, 'ewallet' => 0.0, 'efuel' => 0.0, 'fleet' => 0.0, 'credit' => 0.0];
    
    // 1. Fuel
    try {
        $s = $pdo->prepare("SELECT payment_method, COALESCE(SUM(total_amount), 0) AS total 
                            FROM fuel_transactions 
                            WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
                              AND HOUR(transaction_date) >= ? AND HOUR(transaction_date) < ?
                            GROUP BY payment_method");
        $s->execute([$station_id, $start_hour, $end_hour]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = strtolower(trim($r['payment_method']));
            $val = (float)$r['total'];
            if (in_array($m, ['cash'])) $totals['cash'] += $val;
            elseif (in_array($m, ['credit card', 'card', 'debit card'])) $totals['card'] += $val;
            elseif (in_array($m, ['e-wallet', 'ewallet', 'gcash', 'maya', 'e wallet'])) $totals['ewallet'] += $val;
            elseif (in_array($m, ['e-fuel card', 'efuel card', 'efuel'])) $totals['efuel'] += $val;
            elseif (in_array($m, ['fleet card', 'fleet'])) $totals['fleet'] += $val;
            elseif (in_array($m, ['credit', 'account receivable', 'utang', 'utang payment'])) $totals['credit'] += $val;
            else $totals['cash'] += $val;
        }
    } catch (Exception $e) {}

    // 2. Merchandise
    try {
        $s = $pdo->prepare("SELECT payment_method, COALESCE(SUM(total_amount), 0) AS total 
                            FROM merchandise_transactions 
                            WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) = CURDATE()
                              AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) >= ? 
                              AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) < ?
                            GROUP BY payment_method");
        $s->execute([$station_id, $start_hour, $end_hour]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = strtolower(trim($r['payment_method']));
            $val = (float)$r['total'];
            if (in_array($m, ['cash'])) $totals['cash'] += $val;
            elseif (in_array($m, ['credit card', 'card', 'debit card'])) $totals['card'] += $val;
            elseif (in_array($m, ['e-wallet', 'ewallet', 'gcash', 'maya', 'e wallet'])) $totals['ewallet'] += $val;
            elseif (in_array($m, ['e-fuel card', 'efuel card', 'efuel'])) $totals['efuel'] += $val;
            elseif (in_array($m, ['fleet card', 'fleet'])) $totals['fleet'] += $val;
            elseif (in_array($m, ['credit', 'account receivable', 'utang', 'utang payment'])) $totals['credit'] += $val;
            else $totals['cash'] += $val;
        }
    } catch (Exception $e) {}

    // 3. Job Orders Completed
    try {
        $s = $pdo->prepare("SELECT payment_method, COALESCE(SUM(total_cost), 0) AS total 
                            FROM job_orders 
                            WHERE station_id = ? AND DATE(created_at) = CURDATE() AND status = 'Completed'
                              AND HOUR(created_at) >= ? AND HOUR(created_at) < ?
                            GROUP BY payment_method");
        $s->execute([$station_id, $start_hour, $end_hour]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $m = strtolower(trim($r['payment_method']));
            $val = (float)$r['total'];
            if (in_array($m, ['cash'])) $totals['cash'] += $val;
            elseif (in_array($m, ['credit card', 'card', 'debit card'])) $totals['card'] += $val;
            elseif (in_array($m, ['e-wallet', 'ewallet', 'gcash', 'maya', 'e wallet'])) $totals['ewallet'] += $val;
            elseif (in_array($m, ['e-fuel card', 'efuel card', 'efuel'])) $totals['efuel'] += $val;
            elseif (in_array($m, ['fleet card', 'fleet'])) $totals['fleet'] += $val;
            elseif (in_array($m, ['credit', 'account receivable', 'utang', 'utang payment'])) $totals['credit'] += $val;
            else $totals['cash'] += $val;
        }
    } catch (Exception $e) {}

    return $totals;
}

$shift1_fuel_liters = 0.0; $shift1_fuel_revenue = 0.0;
$shift1_merch_items = 0; $shift1_merch_revenue = 0.0;
$shift1_jo_count = 0; $shift1_jo_revenue = 0.0;
$shift1_deliveries_count = 0; $shift1_activities_count = 0;
$shift1_payments = ['cash' => 0.0, 'card' => 0.0, 'ewallet' => 0.0, 'efuel' => 0.0, 'fleet' => 0.0, 'credit' => 0.0];

$shift2_fuel_liters = 0.0; $shift2_fuel_revenue = 0.0;
$shift2_merch_items = 0; $shift2_merch_revenue = 0.0;
$shift2_jo_count = 0; $shift2_jo_revenue = 0.0;
$shift2_deliveries_count = 0; $shift2_activities_count = 0;
$shift2_payments = ['cash' => 0.0, 'card' => 0.0, 'ewallet' => 0.0, 'efuel' => 0.0, 'fleet' => 0.0, 'credit' => 0.0];

try {
    // === SHIFT 1 (6AM - 2PM) ===
    // Fuel
    $s = $pdo->prepare("SELECT COALESCE(SUM(liters_sold),0) AS liters, COALESCE(SUM(total_amount),0) AS revenue 
                        FROM fuel_transactions 
                        WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
                          AND HOUR(transaction_date) >= 6 AND HOUR(transaction_date) < 14");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift1_fuel_liters = (float)($res['liters'] ?? 0);
    $shift1_fuel_revenue = (float)($res['revenue'] ?? 0);

    // Merchandise
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS items, COALESCE(SUM(total_amount),0) AS revenue 
                        FROM merchandise_transactions 
                        WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) = CURDATE()
                          AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) >= 6 
                          AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) < 14");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift1_merch_items = (int)($res['items'] ?? 0);
    $shift1_merch_revenue = (float)($res['revenue'] ?? 0);

    // Job Orders Completed
    $s = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(total_cost), 0) AS total 
                        FROM job_orders 
                        WHERE station_id = ? AND DATE(created_at) = CURDATE() AND status = 'Completed'
                          AND HOUR(created_at) >= 6 AND HOUR(created_at) < 14");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift1_jo_count = (int)($res['count'] ?? 0);
    $shift1_jo_revenue = (float)($res['total'] ?? 0);

    // Payments
    $shift1_payments = get_shift_payments_breakdown($pdo, $station_id, 6, 14);

    // Deliveries
    $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight 
                        WHERE station_id = ? AND DATE(created_at) = CURDATE()
                          AND HOUR(created_at) >= 6 AND HOUR(created_at) < 14");
    $s->execute([$station_id]);
    $shift1_deliveries_count = (int)$s->fetchColumn();

    // Activities
    $s = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al 
                        LEFT JOIN users u ON al.user_id = u.id 
                        WHERE (u.station_id = ? OR al.user_id = ?) AND DATE(al.created_at) = CURDATE()
                          AND HOUR(al.created_at) >= 6 AND HOUR(al.created_at) < 14");
    $s->execute([$station_id, $me['id']]);
    $shift1_activities_count = (int)$s->fetchColumn();

    // === SHIFT 2 (2PM - 10PM) ===
    // Fuel
    $s = $pdo->prepare("SELECT COALESCE(SUM(liters_sold),0) AS liters, COALESCE(SUM(total_amount),0) AS revenue 
                        FROM fuel_transactions 
                        WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
                          AND HOUR(transaction_date) >= 14 AND HOUR(transaction_date) < 22");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift2_fuel_liters = (float)($res['liters'] ?? 0);
    $shift2_fuel_revenue = (float)($res['revenue'] ?? 0);

    // Merchandise
    $s = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) AS items, COALESCE(SUM(total_amount),0) AS revenue 
                        FROM merchandise_transactions 
                        WHERE station_id = ? AND DATE(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) = CURDATE()
                          AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) >= 14 
                          AND HOUR(COALESCE(NULLIF(transaction_date,'0000-00-00 00:00:00'), created_at)) < 22");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift2_merch_items = (int)($res['items'] ?? 0);
    $shift2_merch_revenue = (float)($res['revenue'] ?? 0);

    // Job Orders Completed
    $s = $pdo->prepare("SELECT COUNT(*) AS count, COALESCE(SUM(total_cost), 0) AS total 
                        FROM job_orders 
                        WHERE station_id = ? AND DATE(created_at) = CURDATE() AND status = 'Completed'
                          AND HOUR(created_at) >= 14 AND HOUR(created_at) < 22");
    $s->execute([$station_id]);
    $res = $s->fetch(PDO::FETCH_ASSOC);
    $shift2_jo_count = (int)($res['count'] ?? 0);
    $shift2_jo_revenue = (float)($res['total'] ?? 0);

    // Payments
    $shift2_payments = get_shift_payments_breakdown($pdo, $station_id, 14, 22);

    // Deliveries
    $s = $pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight 
                        WHERE station_id = ? AND DATE(created_at) = CURDATE()
                          AND HOUR(created_at) >= 14 AND HOUR(created_at) < 22");
    $s->execute([$station_id]);
    $shift2_deliveries_count = (int)$s->fetchColumn();

    // Activities
    $s = $pdo->prepare("SELECT COUNT(*) FROM audit_logs al 
                        LEFT JOIN users u ON al.user_id = u.id 
                        WHERE (u.station_id = ? OR al.user_id = ?) AND DATE(al.created_at) = CURDATE()
                          AND HOUR(al.created_at) >= 14 AND HOUR(al.created_at) < 22");
    $s->execute([$station_id, $me['id']]);
    $shift2_activities_count = (int)$s->fetchColumn();

} catch (Exception $e) {}

// For legacy code references:
$shift1_sales = $shift1_fuel_revenue + $shift1_merch_revenue;
$shift1_fuel = $shift1_fuel_revenue;
$shift1_merch = $shift1_merch_revenue;
$shift1_jo = $shift1_jo_count;

$shift2_sales = $shift2_fuel_revenue + $shift2_merch_revenue;
$shift2_fuel = $shift2_fuel_revenue;
$shift2_merch = $shift2_merch_revenue;
$shift2_jo = $shift2_jo_count;

$daily_total_sales = $shift1_sales + $shift2_sales;
$daily_total_fuel = $shift1_fuel + $shift2_fuel;
$daily_total_merch = $shift1_merch + $shift2_merch;
$daily_total_jo = $shift1_jo + $shift2_jo;


// Consolidated daily breakdown
$daily_fuel_liters = $shift1_fuel_liters + $shift2_fuel_liters;
$daily_fuel_revenue = $shift1_fuel_revenue + $shift2_fuel_revenue;
$daily_merch_items = $shift1_merch_items + $shift2_merch_items;
$daily_merch_revenue = $shift1_merch_revenue + $shift2_merch_revenue;
$daily_jo_count = $shift1_jo_count + $shift2_jo_count;
$daily_jo_revenue = $shift1_jo_revenue + $shift2_jo_revenue;
$daily_deliveries_count = $shift1_deliveries_count + $shift2_deliveries_count;
$daily_activities_count = $shift1_activities_count + $shift2_activities_count;
$daily_payments = [
    'cash' => $shift1_payments['cash'] + $shift2_payments['cash'],
    'card' => $shift1_payments['card'] + $shift2_payments['card'],
    'ewallet' => $shift1_payments['ewallet'] + $shift2_payments['ewallet'],
    'efuel' => $shift1_payments['efuel'] + $shift2_payments['efuel'],
    'fleet' => $shift1_payments['fleet'] + $shift2_payments['fleet'],
    'credit' => $shift1_payments['credit'] + $shift2_payments['credit']
];

// ============================================================
// EXTRA SHIFT-SPECIFIC CHART DATA
// ============================================================

// Fuel by type per shift (for bar charts)
$shift1_fuel_by_type = []; $shift2_fuel_by_type = [];
try {
    $s = $pdo->prepare("SELECT fuel_type, COALESCE(SUM(liters_sold),0) AS liters, COALESCE(SUM(total_amount),0) AS revenue
                        FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
                          AND HOUR(transaction_date) >= 6 AND HOUR(transaction_date) < 14
                        GROUP BY fuel_type ORDER BY liters DESC");
    $s->execute([$station_id]);
    $shift1_fuel_by_type = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $s = $pdo->prepare("SELECT fuel_type, COALESCE(SUM(liters_sold),0) AS liters, COALESCE(SUM(total_amount),0) AS revenue
                        FROM fuel_transactions WHERE station_id = ? AND DATE(transaction_date) = CURDATE()
                          AND HOUR(transaction_date) >= 14 AND HOUR(transaction_date) < 22
                        GROUP BY fuel_type ORDER BY liters DESC");
    $s->execute([$station_id]);
    $shift2_fuel_by_type = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Merchandise sales by category per shift (for pie charts)
$shift1_merch_by_cat = []; $shift2_merch_by_cat = [];
try {
    $s = $pdo->prepare("SELECT COALESCE(ip.category, 'Uncategorized') AS category, 
                                COALESCE(SUM(mt.total_amount),0) AS revenue
                        FROM merchandise_transactions mt
                        LEFT JOIN inventory_products ip ON mt.product_id = ip.id
                        WHERE mt.station_id = ? AND DATE(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) = CURDATE()
                          AND HOUR(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) >= 6
                          AND HOUR(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) < 14
                        GROUP BY category ORDER BY revenue DESC LIMIT 8");
    $s->execute([$station_id]);
    $shift1_merch_by_cat = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $s = $pdo->prepare("SELECT COALESCE(ip.category, 'Uncategorized') AS category, 
                                COALESCE(SUM(mt.total_amount),0) AS revenue
                        FROM merchandise_transactions mt
                        LEFT JOIN inventory_products ip ON mt.product_id = ip.id
                        WHERE mt.station_id = ? AND DATE(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) = CURDATE()
                          AND HOUR(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) >= 14
                          AND HOUR(COALESCE(NULLIF(mt.transaction_date,'0000-00-00 00:00:00'), mt.created_at)) < 22
                        GROUP BY category ORDER BY revenue DESC LIMIT 8");
    $s->execute([$station_id]);
    $shift2_merch_by_cat = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// JO status counts per shift
$shift1_jo_status = ['Pending Validation'=>0,'Approved'=>0,'In Progress'=>0,'Completed'=>0,'Cancelled'=>0];
$shift2_jo_status = ['Pending Validation'=>0,'Approved'=>0,'In Progress'=>0,'Completed'=>0,'Cancelled'=>0];
try {
    $s = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM job_orders
                        WHERE station_id = ? AND DATE(created_at) = CURDATE()
                          AND HOUR(created_at) >= 6 AND HOUR(created_at) < 14
                        GROUP BY status");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $r['status'];
        if (isset($shift1_jo_status[$st])) $shift1_jo_status[$st] = (int)$r['cnt'];
    }
    $s = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM job_orders
                        WHERE station_id = ? AND DATE(created_at) = CURDATE()
                          AND HOUR(created_at) >= 14 AND HOUR(created_at) < 22
                        GROUP BY status");
    $s->execute([$station_id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $st = $r['status'];
        if (isset($shift2_jo_status[$st])) $shift2_jo_status[$st] = (int)$r['cnt'];
    }
} catch (Exception $e) {}

// New customers per shift
$shift1_new_customers = 0; $shift2_new_customers = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id = ? AND DATE(created_at) = CURDATE() AND HOUR(created_at) >= 6 AND HOUR(created_at) < 14");
    $s->execute([$station_id]); $shift1_new_customers = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE station_id = ? AND DATE(created_at) = CURDATE() AND HOUR(created_at) >= 14 AND HOUR(created_at) < 22");
    $s->execute([$station_id]); $shift2_new_customers = (int)$s->fetchColumn();
} catch (Exception $e) {}

// Recent activity logs per shift
$shift1_activity_log = []; $shift2_activity_log = [];
try {
    $s = $pdo->prepare("SELECT al.action_type, al.action_details, al.created_at, u.username
                        FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id
                        WHERE (u.station_id = ? OR al.user_id = ?) AND DATE(al.created_at) = CURDATE()
                          AND HOUR(al.created_at) >= 6 AND HOUR(al.created_at) < 14
                        ORDER BY al.created_at DESC LIMIT 8");
    $s->execute([$station_id, $me['id']]);
    $shift1_activity_log = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $s = $pdo->prepare("SELECT al.action_type, al.action_details, al.created_at, u.username
                        FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id
                        WHERE (u.station_id = ? OR al.user_id = ?) AND DATE(al.created_at) = CURDATE()
                          AND HOUR(al.created_at) >= 14 AND HOUR(al.created_at) < 22
                        ORDER BY al.created_at DESC LIMIT 8");
    $s->execute([$station_id, $me['id']]);
    $shift2_activity_log = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Clock-in logs per shift from labor_sessions
$shift1_clock_logs = []; $shift2_clock_logs = [];
try {
    $s = $pdo->prepare("SELECT u.full_name, ls.start_time, ls.end_time, 
                               TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())) AS duration_min
                        FROM labor_sessions ls LEFT JOIN users u ON ls.user_id = u.id
                        WHERE u.station_id = ? AND DATE(ls.start_time) = CURDATE()
                          AND HOUR(ls.start_time) >= 6 AND HOUR(ls.start_time) < 14
                        ORDER BY ls.start_time DESC LIMIT 5");
    $s->execute([$station_id]);
    $shift1_clock_logs = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $s = $pdo->prepare("SELECT u.full_name, ls.start_time, ls.end_time,
                               TIMESTAMPDIFF(MINUTE, ls.start_time, COALESCE(ls.end_time, NOW())) AS duration_min
                        FROM labor_sessions ls LEFT JOIN users u ON ls.user_id = u.id
                        WHERE u.station_id = ? AND DATE(ls.start_time) = CURDATE()
                          AND HOUR(ls.start_time) >= 14 AND HOUR(ls.start_time) < 22
                        ORDER BY ls.start_time DESC LIMIT 5");
    $s->execute([$station_id]);
    $shift2_clock_logs = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}



require_once __DIR__ . '/../partials/header.php';

// ── Inventory flow alerts for staff ──────────────────────────────────────────
$staff_pending_stock_in = 0;
$staff_pending_sr       = 0;
try {
    // Only count POs that are both admin-finalized AND manager-validated — those are truly ready for stock-in
    $s = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE station_id=? AND admin_finalized=1 AND delivery_validated=1 AND stock_in_done=0 AND type='merch'");
    $s->execute([$station_id]);
    $staff_pending_stock_in = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE staff_id=? AND status='Pending'");
    $s->execute([$me['id']]);
    $staff_pending_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}
?>
<div class="dashboard-content" style="max-width:100%;box-sizing:border-box;overflow-x:hidden;padding-bottom:100px;">
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
.reports-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; }
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

/* ── Stock Status Charts ── */
.stock-chart-section { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:900px) { .stock-chart-section { grid-template-columns:1fr; } }
.stock-chart-wrap { position:relative; }
.stock-legend { display:flex; gap:14px; flex-wrap:wrap; margin-top:10px; font-size:11px; font-weight:600; }
.stock-legend-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:4px; }
.stock-alert-banner { display:flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; font-size:12px; font-weight:700; margin-bottom:10px; }
.stock-alert-red    { background:#FEE2E2; color:#991B1B; border:1px solid #fecaca; }
.stock-alert-orange { background:#FEF3C7; color:#92400E; border:1px solid #fde68a; }

/* Stock Request Modal */
#stockRequestModal .modal-card { max-width:480px; }
.sr-field { margin-bottom:14px; }
.sr-field label { display:block; font-size:12px; font-weight:700; color:#344054; margin-bottom:5px; }
.sr-field input, .sr-field select, .sr-field textarea {
    width:100%; padding:9px 12px; border:1px solid #d0d5dd; border-radius:8px;
    font-size:13px; color:#101828; background:#fff; box-sizing:border-box;
}
.sr-field textarea { resize:vertical; min-height:70px; }
.sr-urgency-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.sr-urgency-btn { padding:8px; border:2px solid #e5e7eb; border-radius:8px; background:#f8fafc;
    font-size:12px; font-weight:700; cursor:pointer; text-align:center; transition:.15s; }
.sr-urgency-btn.active-low    { border-color:#22c55e; background:#f0fdf4; color:#065F46; }
.sr-urgency-btn.active-medium { border-color:#f59e0b; background:#fffbeb; color:#92400E; }
.sr-urgency-btn.active-high   { border-color:#ef4444; background:#fef2f2; color:#991B1B; }
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

<!-- ===== WIDGET 1+2: Sales Performance Charts (side by side) ===== -->
<div class="widget-card widget-full">
  <h3>
    <i class="fas fa-chart-line"></i> Sales Performance
    <span style="margin-left:8px;font-size:11px;font-weight:500;color:#667085">Last 7 days &bull; Hover for details</span>
    <div style="margin-left:auto;display:flex;gap:8px;flex-shrink:0">
      <a href="staff_transactions_hub.php?section=merchandise" style="font-size:12px;background:#2563eb;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:5px">
        <i class="fas fa-shopping-cart"></i> Merchandise
      </a>
      <a href="staff_transactions_hub.php?section=fuel" style="font-size:12px;background:#dc2626;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:5px">
        <i class="fas fa-gas-pump"></i> Fuel
      </a>
    </div>
  </h3>

  <!-- Summary totals row -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
    <div style="background:#fef2f2;border-radius:10px;padding:12px 16px;border:1px solid #fecaca">
      <div style="font-size:11px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><i class="fas fa-gas-pump"></i> Fuel Revenue</div>
      <div style="font-size:20px;font-weight:800;color:#991b1b">&#8369;<?= number_format(array_sum($sales_trend_fuel), 2) ?></div>
      <div style="font-size:11px;color:#ef4444;margin-top:2px"><?= number_format(array_sum($sales_trend_liters), 2) ?> L sold</div>
    </div>
    <div style="background:#eff6ff;border-radius:10px;padding:12px 16px;border:1px solid #bfdbfe">
      <div style="font-size:11px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><i class="fas fa-boxes"></i> Merch Revenue</div>
      <div style="font-size:20px;font-weight:800;color:#1d4ed8">&#8369;<?= number_format(array_sum($sales_trend_merch), 2) ?></div>
      <div style="font-size:11px;color:#3b82f6;margin-top:2px"><?= $merch_txns ?> transaction<?= $merch_txns!=1?'s':'' ?></div>
    </div>
    <div style="background:#f0fdf4;border-radius:10px;padding:12px 16px;border:1px solid #bbf7d0">
      <div style="font-size:11px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><i class="fas fa-coins"></i> Combined Total</div>
      <div style="font-size:20px;font-weight:800;color:#15803d">&#8369;<?= number_format(array_sum($sales_trend_fuel) + array_sum($sales_trend_merch), 2) ?></div>
      <div style="font-size:11px;color:#22c55e;margin-top:2px">7-day period</div>
    </div>
    <div style="background:#fefce8;border-radius:10px;padding:12px 16px;border:1px solid #fde68a">
      <div style="font-size:11px;font-weight:700;color:#ca8a04;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><i class="fas fa-wrench"></i> Job Orders</div>
      <div style="font-size:20px;font-weight:800;color:#92400e"><?= $pending_validation + $approved_validated + $in_progress + $completed + $rejected ?></div>
      <div style="font-size:11px;color:#f59e0b;margin-top:2px"><?= $in_progress ?> in progress</div>
    </div>
  </div>

  <!-- Charts side by side -->
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Fuel Sales Chart -->
    <div>
      <div style="font-size:12px;font-weight:700;color:#991b1b;margin-bottom:10px;display:flex;align-items:center;gap:6px">
        <span style="width:12px;height:12px;border-radius:2px;background:#ef4444;display:inline-block"></span>
        Fuel Sales — Revenue &amp; Liters (7 days)
      </div>
      <div style="position:relative;height:220px">
        <canvas id="fuelSalesChart"></canvas>
      </div>
    </div>

    <!-- Merchandise Sales Chart -->
    <div>
      <div style="font-size:12px;font-weight:700;color:#1d4ed8;margin-bottom:10px;display:flex;align-items:center;gap:6px">
        <span style="width:12px;height:12px;border-radius:2px;background:#3b82f6;display:inline-block"></span>
        Merchandise Sales — Direct + Job Order Parts (7 days)
      </div>
      <div style="position:relative;height:220px">
        <canvas id="merchSalesChart"></canvas>
      </div>
    </div>

  </div>
</div>
<!-- ===== WIDGET 2b: Payments Summary & Fuel Sales (side by side) ===== -->
<div class="widget-card widget-full">
  <h3>
    <i class="fas fa-credit-card"></i> Payments Summary
    <span style="margin-left:auto;font-size:11px;font-weight:500;color:#667085" id="last-refreshed">All transactions (Fuel + Merchandise + Job Orders)</span>
  </h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">

    <!-- Payment Method Breakdown -->
    <div>
      <div style="font-size:12px;font-weight:700;color:#344054;margin-bottom:10px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-money-bill-wave" style="color:#22c55e"></i> Payment Mode Breakdown (Today)
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
              <th style="text-align:left;padding:9px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Mode</th>
              <th style="text-align:right;padding:9px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Amount</th>
              <th style="text-align:right;padding:9px 10px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Share</th>
            </tr>
          </thead>
          <tbody id="merch-tbody">
            <?php
              $pay_rows = [
                ['label'=>'Cash',         'color'=>'#22c55e','icon'=>'fa-money-bill-wave','val'=>$fuel_cash+$merch_cash+$jo_cash],
                ['label'=>'Card',         'color'=>'#3b82f6','icon'=>'fa-credit-card',    'val'=>$fuel_card+$merch_card+$jo_card],
                ['label'=>'E-Wallet',     'color'=>'#a855f7','icon'=>'fa-mobile-alt',     'val'=>$fuel_ewallet+$merch_ewallet+$jo_ewallet],
                ['label'=>'E-Fuel Card',  'color'=>'#f59e0b','icon'=>'fa-gas-pump',       'val'=>$fuel_efuel+$merch_efuel+$jo_efuel],
                ['label'=>'Fleet Card',   'color'=>'#0891b2','icon'=>'fa-id-card',        'val'=>$fuel_fleet+$merch_fleet+$jo_fleet],
                ['label'=>'Credit/Utang', 'color'=>'#ef4444','icon'=>'fa-file-invoice',   'val'=>$fuel_credit+$merch_credit+$jo_credit],
              ];
              $pay_total = array_sum(array_column($pay_rows,'val'));
              foreach ($pay_rows as $p):
                $share = $pay_total > 0 ? number_format($p['val']/$pay_total*100,1) : '0.0';
            ?>
            <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
              <td style="padding:9px 10px">
                <span style="display:inline-flex;align-items:center;gap:7px">
                  <span style="width:8px;height:8px;border-radius:50%;background:<?= $p['color'] ?>;display:inline-block"></span>
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
              <td style="padding:10px;font-weight:800;color:#00264D;font-size:13px">Total Collected</td>
              <td style="padding:10px;text-align:right;font-weight:800;color:#00264D;font-size:14px">&#8369;<?= number_format($pay_total,2) ?></td>
              <td style="padding:10px;text-align:right;font-size:12px;color:#667085">All modes</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Fuel Sales by Type -->
    <div>
      <div style="font-size:12px;font-weight:700;color:#344054;margin-bottom:10px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-gas-pump" style="color:#dc2626"></i> Fuel Sales by Type
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">
              <th style="text-align:left;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Fuel Type</th>
              <th style="text-align:right;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Liters</th>
              <th style="text-align:right;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Revenue</th>
              <th style="text-align:right;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Avg Price</th>
              <th style="text-align:right;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Txns</th>
              <th style="text-align:center;padding:9px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Status</th>
            </tr>
          </thead>
          <tbody id="fuel-tbody">
            <?php if (empty($fuel_by_type)): ?>
            <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;font-size:13px">
              <i class="fas fa-inbox"></i> No fuel readings recorded for this period.
            </td></tr>
            <?php else: ?>
            <?php foreach ($fuel_by_type as $ft):
              $hasVar = abs(($ft['total_variance_liters'] ?? 0)) >= 2;
            ?>
            <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
              <td style="padding:10px 12px">
                <span style="display:inline-flex;align-items:center;gap:8px">
                  <span style="width:10px;height:10px;border-radius:50%;background:#3b82f6;display:inline-block"></span>
                  <strong style="color:#00264D"><?= htmlspecialchars($ft['fuel_type']) ?></strong>
                </span>
              </td>
              <td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828"><?= number_format((float)$ft['total_liters'],2) ?> <span style="font-size:11px;color:#667085">L</span></td>
              <td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">&#8369;<?= number_format((float)$ft['total_revenue'],2) ?></td>
              <td style="padding:10px 12px;text-align:right;color:#667085">&#8369;<?= number_format((float)$ft['avg_price'],2) ?></td>
              <td style="padding:10px 12px;text-align:right;color:#667085"><?= (int)$ft['txn_count'] ?></td>
              <td style="padding:10px 12px;text-align:center">
                <?php if ($hasVar): ?>
                <span style="background:#FEF3C7;color:#92400E;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px">
                  <i class="fas fa-exclamation-triangle"></i> Variance
                </span>
                <?php else: ?>
                <span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:4px">
                  <i class="fas fa-check"></i> OK
                </span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot id="fuel-tfoot">
            <?php
              $totalL = array_sum(array_column($fuel_by_type,'total_liters'));
              $totalR = array_sum(array_column($fuel_by_type,'total_revenue'));
              $anyVar = !empty(array_filter(array_column($fuel_by_type,'has_discrepancy')));
            ?>
            <tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">
              <td style="padding:10px 12px;font-weight:800;color:#00264D"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>
              <td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D"><?= number_format($totalL,2) ?> L</td>
              <td style="padding:10px 12px;text-align:right;font-weight:800;color:#00264D">&#8369;<?= number_format($totalR,2) ?></td>
              <td style="padding:10px 12px;text-align:right;color:#667085">&mdash;</td>
              <td style="padding:10px 12px;text-align:right;font-weight:700;color:#00264D">&mdash;</td>
              <td style="padding:10px 12px;text-align:center">
                <?php if ($anyVar): ?>
                <span style="background:#FEE2E2;color:#991B1B;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">
                  <i class="fas fa-exclamation-circle"></i> Check Readings
                </span>
                <?php else: ?>
                <span style="background:#D1FAE5;color:#065F46;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px">
                  <i class="fas fa-check-circle"></i> All OK
                </span>
                <?php endif; ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

  </div>
</div>


<!-- ===== WIDGET 3: Job Orders Status ===== -->
<div class="widget-card widget-full">
  <h3><i class="fas fa-wrench"></i> Job Orders Status
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=encode_jo" style="margin-left:auto;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600;flex-shrink:0">
      <i class="fas fa-plus"></i> New Job Order
    </a>
  </h3>

  <!-- Status Summary Cards -->
  <div class="status-grid" style="margin-bottom:16px">
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="status-card" style="background:#FFF3CD;border-color:#FDE68A">
      <div class="count" style="color:#92400E" id="jo-pending"><?= $pending_validation ?></div>
      <div class="label" style="color:#92400E"><i class="fas fa-hourglass-half"></i> Pending</div>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="status-card" style="background:#D1FAE5;border-color:#A7F3D0">
      <div class="count" style="color:#065F46" id="jo-approved"><?= $approved_validated ?></div>
      <div class="label" style="color:#065F46"><i class="fas fa-check-circle"></i> Approved</div>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="status-card" style="background:#DBEAFE;border-color:#BFDBFE">
      <div class="count" style="color:#1E40AF" id="jo-inprogress"><?= $in_progress ?></div>
      <div class="label" style="color:#1E40AF"><i class="fas fa-spinner"></i> In Progress</div>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="status-card" style="background:#DCFCE7;border-color:#BBF7D0">
      <div class="count" style="color:#14532D" id="jo-completed"><?= $completed ?></div>
      <div class="label" style="color:#14532D"><i class="fas fa-flag-checkered"></i> Completed</div>
    </a>
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" class="status-card" style="background:#FEE2E2;border-color:#FECACA">
      <div class="count" style="color:#991B1B" id="jo-rejected"><?= $rejected ?></div>
      <div class="label" style="color:#991B1B"><i class="fas fa-times-circle"></i> Rejected</div>
    </a>
  </div>

  <!-- Detail Table -->
  <?php
    $jo_status_cfg = [
      'Pending Validation' => ['bg'=>'#FFF3CD','color'=>'#92400E','icon'=>'fa-hourglass-half'],
      'Pending'            => ['bg'=>'#FFF3CD','color'=>'#92400E','icon'=>'fa-hourglass-half'],
      'Approved'           => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'fa-check-circle'],
      'Validated'          => ['bg'=>'#D1FAE5','color'=>'#065F46','icon'=>'fa-check-circle'],
      'Adjusted'           => ['bg'=>'#DBEAFE','color'=>'#1E40AF','icon'=>'fa-edit'],
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
          <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="color:#00264D;text-decoration:none">
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
          <a href="staff_transactions_hub.php?section=merchandise&active_tab=tracker" style="color:#2563eb;font-weight:600">View all job orders</a>
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




<!-- ===== WIDGET 4b+5: Fuel & Merchandise Inventory Status (side by side) ===== -->

<!-- Fuel Inventory Status -->
<div class="widget-card" id="fuel-stock-chart-card">
  <h3>
    <i class="fas fa-gas-pump"></i> Fuel Inventory Status
  </h3>

  <!-- Color legend -->
  <div style="display:flex;gap:18px;margin-bottom:14px;flex-wrap:wrap">
    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
      <span style="width:14px;height:14px;border-radius:3px;background:#f59e0b;display:inline-block"></span>Low Stock (≤25% fill)
    </span>
    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
      <span style="width:14px;height:14px;border-radius:3px;background:#ef4444;display:inline-block"></span>Out of Stock (0L)
    </span>
    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
      <span style="width:14px;height:14px;border-radius:3px;background:rgba(0,0,0,0.06);border:1px solid #d1d5db;display:inline-block"></span>Capacity
    </span>
  </div>

  <!-- Chart area -->
  <div id="fuel-stock-chart-wrap" style="position:relative;min-height:120px;height:<?= max(120, count($stock_chart_fuel) * 60) ?>px">
    <canvas id="fuelStockChart"></canvas>
  </div>

  <!-- Empty state -->
  <div id="fuel-stock-empty" style="display:<?= empty($stock_chart_fuel) ? 'block' : 'none' ?>;text-align:center;padding:30px 0;color:#9ca3af;font-size:13px">
    <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:8px"></i>
    No fuel inventory data available for this station.
  </div>
</div>

<!-- Merchandise Inventory Status -->
<div class="widget-card" id="stock-charts-card">
  <h3>
    <i class="fas fa-boxes"></i> Merchandise Inventory Status
  </h3>

  <!-- Color legend -->
  <div style="display:flex;gap:18px;margin-bottom:14px;flex-wrap:wrap">
    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
      <span style="width:14px;height:14px;border-radius:3px;background:#f59e0b;display:inline-block"></span>Low Stock
    </span>
    <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
      <span style="width:14px;height:14px;border-radius:3px;background:#ef4444;display:inline-block"></span>Out of Stock
    </span>
  </div>

  <div style="display:grid;grid-template-columns:160px 1fr;gap:16px;align-items:start">

    <!-- Donut summary -->
    <div style="display:flex;flex-direction:column;align-items:center">
      <div style="font-size:12px;font-weight:700;color:#344054;margin-bottom:10px;text-align:center">Summary</div>
      <div style="position:relative;height:140px;width:140px">
        <canvas id="merchStockDonut"></canvas>
      </div>
      <div id="merch-donut-counts" style="margin-top:10px;font-size:12px;text-align:center;color:#667085"></div>
      <div style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
        <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
          <span style="width:10px;height:10px;border-radius:50%;background:#f59e0b;display:inline-block"></span>Low Stock
        </span>
        <span style="display:flex;align-items:center;gap:6px;font-size:12px;color:#667085">
          <span style="width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block"></span>Out of Stock
        </span>
      </div>
    </div>

    <!-- Horizontal bar charts: two columns split by stock level -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">

      <!-- Left column: stock below 50 -->
      <div>
        <div style="font-size:12px;font-weight:700;color:#ef4444;margin-bottom:8px;display:flex;align-items:center;gap:5px">
          <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block"></span>
          Below 50
          <span style="font-size:10px;font-weight:400;color:#9ca3af;margin-left:4px">— click to request</span>
        </div>
        <div id="merch-stock-bar-wrap-low" style="position:relative;height:<?= max(120, count($stock_chart_merch) * 44) ?>px;min-height:120px">
          <canvas id="merchStockBarLow"></canvas>
        </div>
        <div id="merch-bar-low-empty" style="display:none;text-align:center;padding:20px 0;color:#9ca3af;font-size:11px">
          <i class="fas fa-check" style="color:#22c55e"></i> None below 50
        </div>
      </div>

      <!-- Right column: stock 50 and above (but still low/at threshold) -->
      <div>
        <div style="font-size:12px;font-weight:700;color:#f59e0b;margin-bottom:8px;display:flex;align-items:center;gap:5px">
          <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block"></span>
          Above 50
          <span style="font-size:10px;font-weight:400;color:#9ca3af;margin-left:4px">— click to request</span>
        </div>
        <div id="merch-stock-bar-wrap-high" style="position:relative;height:<?= max(120, count($stock_chart_merch) * 44) ?>px;min-height:120px">
          <canvas id="merchStockBarHigh"></canvas>
        </div>
        <div id="merch-bar-high-empty" style="display:none;text-align:center;padding:20px 0;color:#9ca3af;font-size:11px">
          <i class="fas fa-check" style="color:#22c55e"></i> None above 50
        </div>
      </div>

    </div>

  </div>

  <!-- Empty state -->
  <div id="merch-stock-empty" style="display:none;text-align:center;padding:30px 0;color:#9ca3af;font-size:13px">
    <i class="fas fa-check-circle" style="font-size:28px;color:#22c55e;display:block;margin-bottom:8px"></i>
    All merchandise items are well-stocked. No alerts at this time.
  </div>
</div>

<!-- ===== STOCK REQUEST MODAL ===== -->
<div class="modal" id="stockRequestModal" aria-hidden="true" role="dialog" aria-labelledby="srModalTitle">
  <div class="modal-card" style="max-width:480px">
    <div class="modal-head">
      <div class="modal-title" id="srModalTitle"><i class="fas fa-clipboard-list"></i> Request Stock Replenishment</div>
      <button class="icon-btn" onclick="closeStockRequestModal()" aria-label="Close"><i class="fas fa-times"></i></button>
    </div>
    <div style="padding:0 4px">
      <div id="sr-stock-info" style="background:#f8fafc;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12px;border:1px solid #e5e7eb"></div>
      <div class="sr-field">
        <label>Product / Fuel Type</label>
        <input type="text" id="sr-product-name" readonly style="background:#f3f4f6;color:#667085" />
      </div>
      <div class="sr-field">
        <label>Type</label>
        <input type="text" id="sr-product-type" readonly style="background:#f3f4f6;color:#667085" />
      </div>
      <div class="sr-field">
        <label>Requested Quantity <span style="color:#9ca3af;font-weight:400">(liters / pieces)</span></label>
        <input type="number" id="sr-qty" min="1" step="1" placeholder="Enter quantity needed" />
      </div>
      <div class="sr-field">
        <label>Urgency</label>
        <div class="sr-urgency-row">
          <button type="button" class="sr-urgency-btn" data-urgency="low" onclick="setSrUrgency('low')"><i class="fas fa-circle" style="color:#22c55e;font-size:9px;vertical-align:middle"></i> Low</button>
          <button type="button" class="sr-urgency-btn active-medium" data-urgency="medium" onclick="setSrUrgency('medium')"><i class="fas fa-circle" style="color:#f59e0b;font-size:9px;vertical-align:middle"></i> Medium</button>
          <button type="button" class="sr-urgency-btn" data-urgency="high" onclick="setSrUrgency('high')"><i class="fas fa-circle" style="color:#ef4444;font-size:9px;vertical-align:middle"></i> High</button>
        </div>
      </div>
      <div class="sr-field">
        <label>Reason / Notes <span style="color:#9ca3af;font-weight:400">(optional)</span></label>
        <textarea id="sr-notes" placeholder="e.g., Running low before weekend rush..."></textarea>
      </div>
      <div id="sr-feedback" style="display:none;padding:8px 12px;border-radius:8px;font-size:12px;font-weight:600;margin-bottom:10px"></div>
    </div>
    <div class="modal-actions">
      <button class="btn ghost" onclick="closeStockRequestModal()">Cancel</button>
      <button class="btn primary" id="sr-submit-btn" onclick="submitStockRequest()">
        <i class="fas fa-paper-plane"></i> Submit Request
      </button>
    </div>
  </div>
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

<!-- ===== CUSTOMER OVERVIEW ===== -->
<div class="widget-card widget-full" style="margin-top:20px">
  <h3>
    <i class="fas fa-users"></i> Customer Overview
    <a href="customers.php" style="margin-left:auto;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600">View All</a>
  </h3>
  
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:15px;margin-bottom:15px">
    <!-- New Customers Today -->
    <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:15px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div style="width:36px;height:36px;background:#0284c7;border-radius:10px;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-user-plus" style="color:#fff;font-size:16px"></i>
        </div>
        <div>
          <div style="font-size:11px;color:#0c4a6e;font-weight:600;text-transform:uppercase;letter-spacing:0.3px">New Today</div>
          <div style="font-size:24px;font-weight:800;color:#0c4a6e"><?= $new_customers_today ?></div>
        </div>
      </div>
      <div style="font-size:11px;color:#075985;margin-top:8px">Customers added today</div>
    </div>
    
    <!-- Total Customers -->
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:15px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <div style="width:36px;height:36px;background:#16a34a;border-radius:10px;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-users" style="color:#fff;font-size:16px"></i>
        </div>
        <div>
          <div style="font-size:11px;color:#14532d;font-weight:600;text-transform:uppercase;letter-spacing:0.3px">Total</div>
          <div style="font-size:24px;font-weight:800;color:#14532d"><?= count($recent_customers) ?></div>
        </div>
      </div>
      <div style="font-size:11px;color:#15803d;margin-top:8px">Active customers</div>
    </div>
  </div>
  
  <!-- Recent Customers Table -->
  <?php if (!empty($recent_customers)): ?>
  <div style="overflow-x:auto;margin-top:12px">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
          <th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase">Customer</th>
          <th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase">Contact</th>
          <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase">Balance</th>
          <th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase">Transactions</th>
          <th style="text-align:center;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase">Last Activity</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recent_customers as $cust): ?>
        <tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
          <td style="padding:10px 12px;font-weight:600;color:#00264D"><?= htmlspecialchars($cust['name']) ?></td>
          <td style="padding:10px 12px;color:#667085"><?= htmlspecialchars($cust['contact_number'] ?? '—') ?></td>
          <td style="padding:10px 12px;text-align:right;font-weight:700;color:<?= $cust['balance'] > 0 ? '#dc2626' : '#16a34a' ?>">
            ₱<?= number_format($cust['balance'], 2) ?>
          </td>
          <td style="padding:10px 12px;text-align:right;color:#667085"><?= $cust['txn_count'] ?> txn<?= $cust['txn_count'] != 1 ? 's' : '' ?></td>
          <td style="padding:10px 12px;text-align:center;font-size:12px;color:#667085">
            <?= $cust['last_txn'] ? date('M d, Y', strtotime($cust['last_txn'])) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px">
    <i class="fas fa-users" style="font-size:32px;opacity:0.3;display:block;margin-bottom:10px"></i>
    No customer data available yet
  </div>
  <?php endif; ?>
</div>

<!-- ===== ACTIVITY LOG TIMELINE ===== -->
<div class="widget-card widget-full" style="margin-top:20px">
  <h3>
    <i class="fas fa-history"></i> Recent Activity
    <a href="staff_activity_report.php" style="margin-left:auto;font-size:12px;background:#00264D;color:#fff;padding:5px 14px;border-radius:8px;text-decoration:none;font-weight:600">View Full Log</a>
  </h3>
  
  <?php if (!empty($recent_activities)): ?>
  <div style="position:relative;padding-left:25px">
    <!-- Timeline line -->
    <div style="position:absolute;left:8px;top:10px;bottom:10px;width:2px;background:#e5e7eb"></div>
    
    <?php foreach ($recent_activities as $idx => $act): ?>
    <div style="position:relative;margin-bottom:<?= $idx < count($recent_activities) - 1 ? '15px' : '0' ?>">
      <!-- Timeline dot -->
      <div style="position:absolute;left:-17px;top:6px;width:10px;height:10px;border-radius:50%;background:#3b82f6;border:2px solid #fff"></div>
      
      <!-- Activity card -->
      <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px">
        <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:4px">
          <span style="font-weight:700;color:#00264D;font-size:12px"><?= htmlspecialchars($act['action_type'] ?? 'Activity') ?></span>
          <span style="font-size:11px;color:#667085"><?= date('h:i A', strtotime($act['created_at'])) ?></span>
        </div>
        <div style="font-size:12px;color:#667085"><?= htmlspecialchars($act['action_details'] ?? '') ?></div>
        <div style="font-size:11px;color:#9ca3af;margin-top:6px">
          <i class="fas fa-user" style="font-size:10px;margin-right:4px"></i><?= htmlspecialchars($act['username'] ?? 'System') ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div style="text-align:center;padding:30px;color:#9ca3af;font-size:13px">
    <i class="fas fa-history" style="font-size:32px;opacity:0.3;display:block;margin-bottom:10px"></i>
    No recent activity to display
  </div>
  <?php endif; ?>
</div>

<!-- ===== SHIFT SUMMARIES ===== -->
<style>
.shift-tab-bar{display:flex;gap:4px;margin-bottom:0;padding:0 4px}
.shift-tab{padding:10px 22px;border-radius:10px 10px 0 0;font-size:13px;font-weight:700;cursor:pointer;border:2px solid #e2e8f0;border-bottom:none;background:#f8fafc;color:#667085;transition:all .2s}
.shift-tab.active{background:#00264D;color:#fff;border-color:#00264D}
.shift-tab-content{display:none}.shift-tab-content.active{display:block}
.sd-cards{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px}
@media(max-width:900px){.sd-cards{grid-template-columns:repeat(3,1fr)}}
.sd-card{background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:14px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.sd-card-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.sd-card-label{font-size:10px;font-weight:700;text-transform:uppercase;color:#667085;letter-spacing:.4px}
.sd-card-val{font-size:18px;font-weight:800;color:#00264D}
.sd-card-sub{font-size:10px;color:#94a3b8}
.sd-section-title{font-size:12px;font-weight:700;color:#00264D;margin:14px 0 8px;display:flex;align-items:center;gap:6px;text-transform:uppercase;letter-spacing:.4px}
.sd-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
.sd-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media(max-width:800px){.sd-grid-2{grid-template-columns:1fr}}
.sd-chart-title{font-size:11px;font-weight:700;color:#344054;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.sd-table{width:100%;border-collapse:collapse;font-size:12px}
.sd-table th{padding:7px 10px;text-align:left;color:#667085;font-weight:700;font-size:10px;text-transform:uppercase;background:#f0f4ff;border-bottom:2px solid #e2e8f0}
.sd-table td{padding:7px 10px;border-bottom:1px solid #f0f0f0}
.sd-empty{text-align:center;padding:20px;color:#9ca3af;font-size:12px}
.sd-jo-pill{border-radius:8px;padding:10px 14px;text-align:center;min-width:90px;display:inline-block}
</style>

<div class="widget-card widget-full" style="margin-top:20px;padding-bottom:24px">
  <div class="shift-tab-bar">
    <button class="shift-tab active" onclick="switchShiftTab('shift1',this)"><i class="fas fa-sun" style="color:#f59e0b"></i> Shift 1 <small style="opacity:.7">6AM–2PM</small></button>
    <button class="shift-tab" onclick="switchShiftTab('shift2',this)"><i class="fas fa-moon" style="color:#6366f1"></i> Shift 2 <small style="opacity:.7">2PM–10PM</small></button>
    <button class="shift-tab" onclick="switchShiftTab('daily',this)"><i class="fas fa-calendar-check" style="color:#16a34a"></i> Daily Total</button>
  </div>
  <div style="border:2px solid #e2e8f0;border-radius:0 12px 12px 12px;padding:20px">

  <?php
  // Build data arrays for each shift
  $shift_data = [
    'shift1' => [
      'fuel_liters'=>$shift1_fuel_liters,'fuel_revenue'=>$shift1_fuel_revenue,
      'merch_items'=>$shift1_merch_items,'merch_revenue'=>$shift1_merch_revenue,
      'jo_count'=>$shift1_jo_count,'jo_revenue'=>$shift1_jo_revenue,
      'jo_status'=>$shift1_jo_status,'payments'=>$shift1_payments,
      'deliveries'=>$shift1_deliveries_count,'activities'=>$shift1_activities_count,
      'new_customers'=>$shift1_new_customers,'clock_logs'=>$shift1_clock_logs,
      'fuel_by_type'=>$shift1_fuel_by_type,'merch_by_cat'=>$shift1_merch_by_cat,
      'activity_log'=>$shift1_activity_log,'accent'=>'#0284c7','label'=>'Shift 1','hours'=>'6AM–2PM',
    ],
    'shift2' => [
      'fuel_liters'=>$shift2_fuel_liters,'fuel_revenue'=>$shift2_fuel_revenue,
      'merch_items'=>$shift2_merch_items,'merch_revenue'=>$shift2_merch_revenue,
      'jo_count'=>$shift2_jo_count,'jo_revenue'=>$shift2_jo_revenue,
      'jo_status'=>$shift2_jo_status,'payments'=>$shift2_payments,
      'deliveries'=>$shift2_deliveries_count,'activities'=>$shift2_activities_count,
      'new_customers'=>$shift2_new_customers,'clock_logs'=>$shift2_clock_logs,
      'fuel_by_type'=>$shift2_fuel_by_type,'merch_by_cat'=>$shift2_merch_by_cat,
      'activity_log'=>$shift2_activity_log,'accent'=>'#f59e0b','label'=>'Shift 2','hours'=>'2PM–10PM',
    ],
    'daily' => [
      'fuel_liters'=>$daily_fuel_liters,'fuel_revenue'=>$daily_fuel_revenue,
      'merch_items'=>$daily_merch_items,'merch_revenue'=>$daily_merch_revenue,
      'jo_count'=>$daily_jo_count,'jo_revenue'=>$daily_jo_revenue,
      'jo_status'=>array_map(null,$shift1_jo_status,$shift2_jo_status),
      'payments'=>$daily_payments,
      'deliveries'=>$daily_deliveries_count,'activities'=>$daily_activities_count,
      'new_customers'=>$shift1_new_customers+$shift2_new_customers,
      'clock_logs'=>array_merge($shift1_clock_logs,$shift2_clock_logs),
      'fuel_by_type'=>$fuel_by_type,'merch_by_cat'=>array_merge($shift1_merch_by_cat,$shift2_merch_by_cat),
      'activity_log'=>array_merge($shift1_activity_log,$shift2_activity_log),
      'accent'=>'#16a34a','label'=>'Daily','hours'=>'All Day',
    ],
  ];
  // Fix daily jo_status
  $daily_jo_status_merged = [];
  foreach (['Pending Validation','Approved','In Progress','Completed','Cancelled'] as $st) {
    $daily_jo_status_merged[$st] = ($shift1_jo_status[$st]??0)+($shift2_jo_status[$st]??0);
  }
  $shift_data['daily']['jo_status'] = $daily_jo_status_merged;

  $jo_conf = [
    'Pending Validation'=>['#FEF3C7','#92400E','fa-hourglass-half'],
    'Approved'=>['#DBEAFE','#1E40AF','fa-check-circle'],
    'In Progress'=>['#EDE9FE','#6D28D9','fa-spinner'],
    'Completed'=>['#D1FAE5','#065F46','fa-check-double'],
    'Cancelled'=>['#FEE2E2','#991B1B','fa-times-circle'],
  ];
  $pay_modes = [
    'Cash'=>['cash','#22c55e','fa-money-bill-wave'],
    'Card'=>['card','#3b82f6','fa-credit-card'],
    'E-Wallet'=>['ewallet','#a855f7','fa-mobile-alt'],
    'E-Fuel Card'=>['efuel','#f59e0b','fa-gas-pump'],
    'Fleet Card'=>['fleet','#0891b2','fa-id-card'],
    'Credit/Utang'=>['credit','#ef4444','fa-file-invoice'],
  ];
  ?>

  <?php foreach ($shift_data as $sid => $sd):
    $p = $sd['payments'];
    $pay_total = array_sum($p);
    $jo_s = $sd['jo_status'];
    $first = ($sid === 'shift1') ? 'active' : '';
  ?>
  <div class="shift-tab-content <?= $first ?>" id="tab-<?= $sid ?>">

    <!-- Summary Cards -->
    <div class="sd-cards">
      <div class="sd-card" style="border-top:3px solid #ef4444">
        <div class="sd-card-icon" style="background:#fef2f2;color:#ef4444"><i class="fas fa-gas-pump"></i></div>
        <div><div class="sd-card-label">Fuel Sales</div>
        <div class="sd-card-val">₱<?= number_format($sd['fuel_revenue'],2) ?></div>
        <div class="sd-card-sub"><?= number_format($sd['fuel_liters'],1) ?> L sold</div></div>
      </div>
      <div class="sd-card" style="border-top:3px solid #2563eb">
        <div class="sd-card-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-boxes"></i></div>
        <div><div class="sd-card-label">Merchandise</div>
        <div class="sd-card-val">₱<?= number_format($sd['merch_revenue'],2) ?></div>
        <div class="sd-card-sub"><?= $sd['merch_items'] ?> items sold</div></div>
      </div>
      <div class="sd-card" style="border-top:3px solid #16a34a">
        <div class="sd-card-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-credit-card"></i></div>
        <div><div class="sd-card-label">Payments</div>
        <div class="sd-card-val">₱<?= number_format($pay_total,2) ?></div>
        <div class="sd-card-sub">All modes combined</div></div>
      </div>
      <div class="sd-card" style="border-top:3px solid #f59e0b">
        <div class="sd-card-icon" style="background:#fffbeb;color:#f59e0b"><i class="fas fa-wrench"></i></div>
        <div><div class="sd-card-label">Job Orders</div>
        <div class="sd-card-val"><?= array_sum($jo_s) ?> total</div>
        <div class="sd-card-sub"><?= $jo_s['Completed']??0 ?> completed</div></div>
      </div>
      <div class="sd-card" style="border-top:3px solid #8b5cf6">
        <div class="sd-card-icon" style="background:#f5f3ff;color:#8b5cf6"><i class="fas fa-user-plus"></i></div>
        <div><div class="sd-card-label">New Customers</div>
        <div class="sd-card-val"><?= $sd['new_customers'] ?></div>
        <div class="sd-card-sub"><?= $sd['deliveries'] ?> deliveries</div></div>
      </div>
    </div>

    <!-- Shift Tracker -->
    <div class="sd-section-title"><i class="fas fa-clock"></i> Shift Tracker</div>
    <div class="sd-box" style="margin-bottom:14px">
      <?php if (!empty($sd['clock_logs'])): ?>
      <table class="sd-table"><thead><tr><th>Staff</th><th>Clock In</th><th>Clock Out</th><th>Duration</th></tr></thead><tbody>
      <?php foreach ($sd['clock_logs'] as $cl):
        $dm = $cl['duration_min']??0; $hh = floor($dm/60); $mm = $dm%60;
      ?><tr>
        <td><strong><?= htmlspecialchars($cl['full_name']??'—') ?></strong></td>
        <td><?= $cl['start_time'] ? date('h:i A',strtotime($cl['start_time'])) : '—' ?></td>
        <td><?= $cl['end_time'] ? date('h:i A',strtotime($cl['end_time'])) : '<span style="color:#16a34a;font-weight:700">Active</span>' ?></td>
        <td><?= $hh>0?"$hh h ":'' ?><?= $mm ?> min</td>
      </tr><?php endforeach; ?>
      </tbody></table>
      <?php else: ?><div class="sd-empty"><i class="fas fa-clock"></i> No clock-in records for this period.</div><?php endif; ?>
    </div>

    <!-- Charts Row -->
    <div class="sd-grid-2" style="margin-bottom:14px">
      <div class="sd-box">
        <div class="sd-chart-title"><i class="fas fa-gas-pump" style="color:#ef4444"></i> Fuel: Liters per Type</div>
        <?php if (!empty($sd['fuel_by_type'])): ?>
        <div style="position:relative;height:180px"><canvas id="<?= $sid ?>_fuelChart"></canvas></div>
        <?php else: ?><div class="sd-empty">No fuel data for this period.</div><?php endif; ?>
      </div>
      <div class="sd-box">
        <div class="sd-chart-title"><i class="fas fa-boxes" style="color:#2563eb"></i> Merchandise: Sales by Category</div>
        <?php if (!empty($sd['merch_by_cat'])): ?>
        <div style="position:relative;height:180px"><canvas id="<?= $sid ?>_merchChart"></canvas></div>
        <?php else: ?><div class="sd-empty">No merchandise data for this period.</div><?php endif; ?>
      </div>
    </div>

    <!-- Job Orders -->
    <div class="sd-section-title"><i class="fas fa-wrench"></i> Job Orders Status</div>
    <div class="sd-box" style="margin-bottom:14px">
      <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <?php foreach ($jo_conf as $st=>[$bg,$col,$ico]): ?>
        <div style="background:<?= $bg ?>;color:<?= $col ?>;border-radius:10px;padding:10px 16px;text-align:center;min-width:95px">
          <i class="fas <?= $ico ?>" style="font-size:16px;display:block;margin-bottom:4px"></i>
          <div style="font-size:20px;font-weight:800"><?= $jo_s[$st]??0 ?></div>
          <div style="font-size:10px;font-weight:600"><?= $st ?></div>
        </div>
        <?php endforeach; ?>
        <a href="staff_transactions_hub.php?section=merchandise&active_tab=encode_jo" style="margin-left:auto;background:#00264D;color:#fff;padding:8px 14px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px">
          <i class="fas fa-plus"></i> New Job Order
        </a>
      </div>
    </div>

    <!-- Payments Summary -->
    <div class="sd-section-title"><i class="fas fa-credit-card"></i> Payments Summary</div>
    <div class="sd-grid-2" style="margin-bottom:14px">
      <div class="sd-box">
        <?php foreach ($pay_modes as $lbl=>[$key,$col,$ico]):
          $val=$p[$key]??0; $pct=$pay_total>0?round($val/$pay_total*100,1):0;
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f0f0f0">
          <span style="display:flex;align-items:center;gap:7px;font-size:12px;color:#344054;font-weight:600">
            <i class="fas <?= $ico ?>" style="color:<?= $col ?>;width:14px"></i><?= $lbl ?>
          </span>
          <span style="display:flex;align-items:center;gap:6px">
            <strong style="font-size:12px">₱<?= number_format($val,2) ?></strong>
            <span style="background:<?= $col ?>22;color:<?= $col ?>;font-size:10px;font-weight:700;padding:1px 5px;border-radius:20px"><?= $pct ?>%</span>
          </span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:8px;display:flex;justify-content:space-between;font-weight:800;color:#00264D;font-size:13px">
          <span>Total</span><span>₱<?= number_format($pay_total,2) ?></span>
        </div>
      </div>
      <div class="sd-box">
        <div class="sd-chart-title"><i class="fas fa-chart-bar" style="color:#0891b2"></i> Payment Mode Comparison</div>
        <div style="position:relative;height:200px"><canvas id="<?= $sid ?>_payChart"></canvas></div>
      </div>
    </div>

    <!-- Activity Log -->
    <div class="sd-section-title"><i class="fas fa-history"></i> Activity Log</div>
    <div class="sd-box" style="margin-bottom:4px">
      <?php if (!empty($sd['activity_log'])): ?>
      <div style="position:relative;padding-left:18px">
        <div style="position:absolute;left:6px;top:8px;bottom:8px;width:2px;background:#e5e7eb"></div>
        <?php foreach (array_slice($sd['activity_log'],0,6) as $act): ?>
        <div style="position:relative;margin-bottom:8px">
          <div style="position:absolute;left:-12px;top:5px;width:7px;height:7px;border-radius:50%;background:#3b82f6;border:2px solid #fff"></div>
          <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:7px 10px">
            <div style="display:flex;justify-content:space-between">
              <span style="font-size:11px;font-weight:700;color:#00264D"><?= htmlspecialchars($act['action_type']??'Activity') ?></span>
              <span style="font-size:10px;color:#667085"><?= date('h:i A',strtotime($act['created_at'])) ?></span>
            </div>
            <div style="font-size:11px;color:#667085"><?= htmlspecialchars(substr($act['action_details']??'',0,75)) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?><div class="sd-empty">No activities for this period.</div><?php endif; ?>
      <div style="text-align:right;margin-top:6px"><a href="staff_activity_report.php" style="font-size:11px;color:#2563eb;font-weight:600">Full Audit Trail &rarr;</a></div>
    </div>

  </div><!-- end tab-<?= $sid ?> -->
  <?php endforeach; ?>

  </div><!-- end tab body -->
</div>

<script>
function switchShiftTab(tabId, btn) {
  document.querySelectorAll('.shift-tab-content').forEach(el => el.classList.remove('active'));
  document.querySelectorAll('.shift-tab').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + tabId).classList.add('active');
  btn.classList.add('active');
}
// Build shift charts after Chart.js loads
function buildShiftCharts() {
  var shiftData = <?php
    $out = [];
    foreach (['shift1','shift2','daily'] as $sid) {
      $sd = $shift_data[$sid];
      $out[$sid] = [
        'fuel_labels' => array_column($sd['fuel_by_type'],'fuel_type'),
        'fuel_liters' => array_map('floatval', array_column($sd['fuel_by_type'],'liters')),
        'merch_labels'=> array_column($sd['merch_by_cat'],'category'),
        'merch_rev'   => array_map('floatval', array_column($sd['merch_by_cat'],'revenue')),
        'pay_labels'  => ['Cash','Card','E-Wallet','E-Fuel','Fleet','Credit'],
        'pay_vals'    => [
          $sd['payments']['cash'],$sd['payments']['card'],$sd['payments']['ewallet'],
          $sd['payments']['efuel'],$sd['payments']['fleet'],$sd['payments']['credit'],
        ],
      ];
    }
    echo json_encode($out);
  ?>;
  var paletteFuel  = ['#ef4444','#f97316','#eab308','#22c55e','#3b82f6','#8b5cf6','#14b8a6'];
  var paletteMerch = ['#3b82f6','#a855f7','#22c55e','#f59e0b','#ef4444','#0891b2','#64748b','#f97316'];
  var palettePay   = ['#22c55e','#3b82f6','#a855f7','#f59e0b','#0891b2','#ef4444'];
  ['shift1','shift2','daily'].forEach(function(sid) {
    var d = shiftData[sid];
    // Fuel Bar
    var fc = document.getElementById(sid + '_fuelChart');
    if (fc && d.fuel_labels.length) {
      new Chart(fc.getContext('2d'), {
        type:'bar',
        data:{labels:d.fuel_labels,datasets:[{label:'Liters',data:d.fuel_liters,backgroundColor:paletteFuel,borderRadius:5}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>v+'L'}}}}
      });
    }
    // Merch Pie
    var mc = document.getElementById(sid + '_merchChart');
    if (mc && d.merch_labels.length) {
      new Chart(mc.getContext('2d'), {
        type:'doughnut',
        data:{labels:d.merch_labels,datasets:[{data:d.merch_rev,backgroundColor:paletteMerch,borderWidth:0}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{font:{size:10},boxWidth:10}}}}
      });
    }
    // Payment Stacked/Horizontal Bar
    var pc = document.getElementById(sid + '_payChart');
    if (pc) {
      new Chart(pc.getContext('2d'), {
        type:'bar',
        data:{labels:d.pay_labels,datasets:[{label:'Amount',data:d.pay_vals,backgroundColor:palettePay,borderRadius:5}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
          scales:{x:{grid:{display:false}},y:{beginAtZero:true,ticks:{callback:v=>'₱'+v.toLocaleString()}}}}
      });
    }
  });
}
(function waitForChartShift() {
  if (typeof Chart !== 'undefined') buildShiftCharts();
  else setTimeout(waitForChartShift, 150);
})();
</script>


  
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:15px;margin-top:12px">
    
    <!-- Shift 1 (6AM - 2PM) -->
    <div style="background:linear-gradient(135deg,#f0f9ff 0%,#e0f2fe 100%);border:2px solid #0284c7;border-radius:12px;padding:18px;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px">
          <h4 style="font-size:14px;font-weight:800;color:#0c4a6e;margin:0;display:flex;align-items:center;gap:8px">
            <i class="fas fa-sun" style="color:#f59e0b"></i> SHIFT 1
          </h4>
          <span style="background:#0284c7;color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px">6AM - 2PM</span>
        </div>
        
        <div style="display:grid;gap:8px">
          <!-- Total Sales -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:rgba(255,255,255,0.7);border-radius:6px;border-left:3px solid #0284c7">
            <span style="font-size:12px;color:#075985;font-weight:600">Total Sales</span>
            <span style="font-size:14px;font-weight:800;color:#0c4a6e">₱<?= number_format($shift1_sales, 2) ?></span>
          </div>
          
          <!-- Fuel Details -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#075985"><i class="fas fa-gas-pump" style="width:14px"></i> Fuel</span>
            <span style="font-size:11px;font-weight:700;color:#0c4a6e"><?= number_format($shift1_fuel_liters, 1) ?> L sold &bull; ₱<?= number_format($shift1_fuel_revenue, 2) ?></span>
          </div>
          
          <!-- Merchandise Details -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#075985"><i class="fas fa-boxes" style="width:14px"></i> Merchandise</span>
            <span style="font-size:11px;font-weight:700;color:#0c4a6e"><?= $shift1_merch_items ?> items &bull; ₱<?= number_format($shift1_merch_revenue, 2) ?></span>
          </div>
          
          <!-- Job Orders Completed -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#075985"><i class="fas fa-wrench" style="width:14px"></i> Job Orders</span>
            <span style="font-size:11px;font-weight:700;color:#0c4a6e"><?= $shift1_jo_count ?> completed &bull; ₱<?= number_format($shift1_jo_revenue, 2) ?></span>
          </div>

          <!-- Deliveries & Activities -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#075985"><i class="fas fa-truck" style="width:14px"></i> Logistics</span>
            <span style="font-size:11px;font-weight:700;color:#0c4a6e"><?= $shift1_deliveries_count ?> deliveries &bull; <?= $shift1_activities_count ?> activities</span>
          </div>

          <!-- Payments Breakdown -->
          <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(2,132,199,0.3)">
            <div style="font-size:10px;color:#075985;font-weight:700;text-transform:uppercase;margin-bottom:4px">Payments Breakdown:</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px;color:#334155">
              <div>Cash: <strong>₱<?= number_format($shift1_payments['cash'], 2) ?></strong></div>
              <div>Card: <strong>₱<?= number_format($shift1_payments['card'], 2) ?></strong></div>
              <div>E-Wallet: <strong>₱<?= number_format($shift1_payments['ewallet'], 2) ?></strong></div>
              <div>E-Fuel: <strong>₱<?= number_format($shift1_payments['efuel'], 2) ?></strong></div>
              <div style="grid-column: span 2">Fleet Card: <strong>₱<?= number_format($shift1_payments['fleet'], 2) ?></strong></div>
            </div>
          </div>

        </div>
      </div>
    </div>
    
    <!-- Shift 2 (2PM - 10PM) -->
    <div style="background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:2px solid #f59e0b;border-radius:12px;padding:18px;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px">
          <h4 style="font-size:14px;font-weight:800;color:#78350f;margin:0;display:flex;align-items:center;gap:8px">
            <i class="fas fa-moon" style="color:#6366f1"></i> SHIFT 2
          </h4>
          <span style="background:#f59e0b;color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px">2PM - 10PM</span>
        </div>
        
        <div style="display:grid;gap:8px">
          <!-- Total Sales -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:rgba(255,255,255,0.7);border-radius:6px;border-left:3px solid #f59e0b">
            <span style="font-size:12px;color:#92400e;font-weight:600">Total Sales</span>
            <span style="font-size:14px;font-weight:800;color:#78350f">₱<?= number_format($shift2_sales, 2) ?></span>
          </div>
          
          <!-- Fuel Details -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#92400e"><i class="fas fa-gas-pump" style="width:14px"></i> Fuel</span>
            <span style="font-size:11px;font-weight:700;color:#78350f"><?= number_format($shift2_fuel_liters, 1) ?> L sold &bull; ₱<?= number_format($shift2_fuel_revenue, 2) ?></span>
          </div>
          
          <!-- Merchandise Details -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#92400e"><i class="fas fa-boxes" style="width:14px"></i> Merchandise</span>
            <span style="font-size:11px;font-weight:700;color:#78350f"><?= $shift2_merch_items ?> items &bull; ₱<?= number_format($shift2_merch_revenue, 2) ?></span>
          </div>
          
          <!-- Job Orders Completed -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#92400e"><i class="fas fa-wrench" style="width:14px"></i> Job Orders</span>
            <span style="font-size:11px;font-weight:700;color:#78350f"><?= $shift2_jo_count ?> completed &bull; ₱<?= number_format($shift2_jo_revenue, 2) ?></span>
          </div>

          <!-- Deliveries & Activities -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#92400e"><i class="fas fa-truck" style="width:14px"></i> Logistics</span>
            <span style="font-size:11px;font-weight:700;color:#78350f"><?= $shift2_deliveries_count ?> deliveries &bull; <?= $shift2_activities_count ?> activities</span>
          </div>

          <!-- Payments Breakdown -->
          <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(245,158,11,0.3)">
            <div style="font-size:10px;color:#92400e;font-weight:700;text-transform:uppercase;margin-bottom:4px">Payments Breakdown:</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px;color:#334155">
              <div>Cash: <strong>₱<?= number_format($shift2_payments['cash'], 2) ?></strong></div>
              <div>Card: <strong>₱<?= number_format($shift2_payments['card'], 2) ?></strong></div>
              <div>E-Wallet: <strong>₱<?= number_format($shift2_payments['ewallet'], 2) ?></strong></div>
              <div>E-Fuel: <strong>₱<?= number_format($shift2_payments['efuel'], 2) ?></strong></div>
              <div style="grid-column: span 2">Fleet Card: <strong>₱<?= number_format($shift2_payments['fleet'], 2) ?></strong></div>
            </div>
          </div>

        </div>
      </div>
    </div>
    
    <!-- Overall Daily Summary -->
    <div style="background:linear-gradient(135deg,#dcfce7 0%,#bbf7d0 100%);border:2px solid #16a34a;border-radius:12px;padding:18px;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px">
          <h4 style="font-size:14px;font-weight:800;color:#14532d;margin:0;display:flex;align-items:center;gap:8px">
            <i class="fas fa-calendar-check" style="color:#16a34a"></i> DAILY TOTAL
          </h4>
          <span style="background:#16a34a;color:#fff;font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px">Today</span>
        </div>
        
        <div style="display:grid;gap:8px">
          <!-- Grand Total Sales -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:rgba(255,255,255,0.7);border-radius:6px;border-left:3px solid #16a34a">
            <span style="font-size:12px;color:#15803d;font-weight:600">Grand Total</span>
            <span style="font-size:14px;font-weight:800;color:#14532d">₱<?= number_format($daily_total_sales, 2) ?></span>
          </div>
          
          <!-- Fuel Total -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#15803d"><i class="fas fa-gas-pump" style="width:14px"></i> Fuel Total</span>
            <span style="font-size:11px;font-weight:700;color:#14532d"><?= number_format($daily_fuel_liters, 1) ?> L sold &bull; ₱<?= number_format($daily_fuel_revenue, 2) ?></span>
          </div>
          
          <!-- Merch Total -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#15803d"><i class="fas fa-boxes" style="width:14px"></i> Merch Total</span>
            <span style="font-size:11px;font-weight:700;color:#14532d"><?= $daily_merch_items ?> items &bull; ₱<?= number_format($daily_merch_revenue, 2) ?></span>
          </div>
          
          <!-- JO Total -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#15803d"><i class="fas fa-wrench" style="width:14px"></i> JO Total</span>
            <span style="font-size:11px;font-weight:700;color:#14532d"><?= $daily_jo_count ?> completed &bull; ₱<?= number_format($daily_jo_revenue, 2) ?></span>
          </div>

          <!-- Deliveries & Activities -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;background:rgba(255,255,255,0.5);border-radius:6px">
            <span style="font-size:11px;color:#15803d"><i class="fas fa-truck" style="width:14px"></i> Logistics Total</span>
            <span style="font-size:11px;font-weight:700;color:#14532d"><?= $daily_deliveries_count ?> deliveries &bull; <?= $daily_activities_count ?> activities</span>
          </div>

          <!-- Daily Payments Breakdown -->
          <div style="margin-top:8px;padding-top:8px;border-top:1px dashed rgba(22,163,74,0.3)">
            <div style="font-size:10px;color:#15803d;font-weight:700;text-transform:uppercase;margin-bottom:4px">Payments Breakdown:</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:11px;color:#334155">
              <div>Cash: <strong>₱<?= number_format($daily_payments['cash'], 2) ?></strong></div>
              <div>Card: <strong>₱<?= number_format($daily_payments['card'], 2) ?></strong></div>
              <div>E-Wallet: <strong>₱<?= number_format($daily_payments['ewallet'], 2) ?></strong></div>
              <div>E-Fuel: <strong>₱<?= number_format($daily_payments['efuel'], 2) ?></strong></div>
              <div style="grid-column: span 2">Fleet Card: <strong>₱<?= number_format($daily_payments['fleet'], 2) ?></strong></div>
            </div>
          </div>

        </div>
      </div>
    </div>
    
  </div>
</div>

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
    <a href="staff_transactions_hub.php?section=merchandise" class="quick-action-btn">
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
    <a href="staff_transactions_hub.php?section=merchandise&active_tab=encode_jo" class="quick-action-btn">
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
    <a href="staff_record_delivery.php" class="quick-action-btn">
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
    <a href="staff_reports_complete.php?view=activity_reports&sub=staff_activity" class="quick-action-btn <?= $qa_clocked_in ? 'qa-active' : '' ?>">
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

<!-- ===== REPORTS & ADD-ONS ===== -->
<div class="widget-card widget-full" style="margin-top:20px">
  <h3><i class="fas fa-file-chart-line"></i> Reports & Add-ons
    <span style="margin-left:8px;font-size:11px;font-weight:500;color:#667085">Comprehensive reports — Sales, Job Orders, Deliveries, Payments, Customers & Activity</span>
  </h3>
  <div class="reports-grid">
    <a href="staff_reports_complete.php?view=sales_reports&sub=daily_summary" class="report-btn">
      <i class="fas fa-dollar-sign"></i>
      <span>Sales Reports</span>
      <span class="qa-desc">Daily sales & customer linkage</span>
    </a>
    <a href="staff_reports_complete.php?view=job_orders_reports&sub=job_tracker" class="report-btn">
      <i class="fas fa-wrench"></i>
      <span>Job Orders</span>
      <span class="qa-desc">Tracker & performance</span>
    </a>
    <a href="staff_reports_complete.php?view=deliveries_reports&sub=fuel_deliveries" class="report-btn">
      <i class="fas fa-truck"></i>
      <span>Deliveries</span>
      <span class="qa-desc">Fuel, merchandise & movement</span>
    </a>
    <a href="staff_reports_complete.php?view=meter_readings" class="report-btn">
      <i class="fas fa-tachometer-alt"></i>
      <span>Meter Readings</span>
      <span class="qa-desc">Pump reading logs per day</span>
    </a>
    <a href="staff_reports_complete.php?view=payments_reports&sub=all" class="report-btn">
      <i class="fas fa-credit-card"></i>
      <span>Payments</span>
      <span class="qa-desc">Unpaid, pending, paid status</span>
    </a>
    <a href="staff_reports_complete.php?view=customer_reports&sub=customer_list" class="report-btn">
      <i class="fas fa-users"></i>
      <span>Customers</span>
      <span class="qa-desc">List & transaction history</span>
    </a>
    <a href="staff_reports_complete.php?view=activity_reports&sub=staff_activity" class="report-btn">
      <i class="fas fa-chart-line"></i>
      <span>Activity Reports</span>
      <span class="qa-desc">Activity log & audit trail</span>
    </a>
  </div>
</div>
</div><!-- .page-content -->

<!-- Chart.js CDN for stock status charts -->
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>

<script>
// ============================================================
// Sales Performance Charts — Fuel (red) + Merchandise (blue)
// ============================================================
var _salesTrendLabels = <?= json_encode($sales_trend_labels) ?>;
var _salesTrendFuel   = <?= json_encode($sales_trend_fuel) ?>;
var _salesTrendLiters = <?= json_encode($sales_trend_liters) ?>;
var _salesTrendMerch  = <?= json_encode($sales_trend_merch) ?>;

var _fuelSalesChart  = null;
var _merchSalesChart = null;

function buildSalesCharts() {
    var allFuelZero  = _salesTrendFuel.every(function(v){ return v === 0; });
    var allMerchZero = _salesTrendMerch.every(function(v){ return v === 0; });

    // ── Fuel Sales Chart ───────────────────────────────────────────────
    var fuelCtx = document.getElementById('fuelSalesChart');
    if (fuelCtx) {
        if (_fuelSalesChart) _fuelSalesChart.destroy();
        if (allFuelZero) {
            fuelCtx.style.display = 'none';
            var fe = document.getElementById('fuelSalesEmpty');
            if (!fe) {
                fe = document.createElement('div');
                fe.id = 'fuelSalesEmpty';
                fe.style.cssText = 'text-align:center;padding:60px 0;color:#9ca3af;font-size:13px';
                fe.innerHTML = '<i class="fas fa-gas-pump" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>No fuel sales recorded yet';
                fuelCtx.parentNode.appendChild(fe);
            }
            fe.style.display = 'block';
        } else {
            fuelCtx.style.display = '';
            var fe2 = document.getElementById('fuelSalesEmpty');
            if (fe2) fe2.style.display = 'none';
            _fuelSalesChart = new Chart(fuelCtx, {
            type: 'bar',
            data: {
                labels: _salesTrendLabels,
                datasets: [
                    {
                        label: 'Revenue (₱)',
                        data: _salesTrendFuel,
                        backgroundColor: 'rgba(239,68,68,0.75)',
                        borderColor: '#dc2626',
                        borderWidth: 1,
                        borderRadius: 5,
                        yAxisID: 'yRev',
                        order: 2,
                    },
                    {
                        label: 'Liters Sold',
                        data: _salesTrendLiters,
                        type: 'line',
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249,115,22,0.12)',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: '#f97316',
                        tension: 0.35,
                        fill: true,
                        yAxisID: 'yLit',
                        order: 1,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 10 } },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        padding: 10,
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.dataset.label === 'Revenue (₱)') return ' ₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2});
                                return ' ' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2}) + ' L';
                            }
                        }
                    }
                },
                scales: {
                    yRev: {
                        type: 'linear', position: 'left',
                        ticks: { font:{size:10}, callback: function(v){ return '₱'+v.toLocaleString(); } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    yLit: {
                        type: 'linear', position: 'right',
                        ticks: { font:{size:10}, callback: function(v){ return v+'L'; } },
                        grid: { drawOnChartArea: false }
                    },
                    x: { ticks: { font:{size:10} }, grid: { display: false } }
                }
            }
        });
        } // end else (fuel has data)
    }

    // ── Merchandise Sales Chart (bar) ───────────────────────────────────
    var merchCtx = document.getElementById('merchSalesChart');
    if (merchCtx) {
        if (_merchSalesChart) _merchSalesChart.destroy();
        if (allMerchZero) {
            merchCtx.style.display = 'none';
            var me = document.getElementById('merchSalesEmpty');
            if (!me) {
                me = document.createElement('div');
                me.id = 'merchSalesEmpty';
                me.style.cssText = 'text-align:center;padding:60px 0;color:#9ca3af;font-size:13px';
                me.innerHTML = '<i class="fas fa-boxes" style="font-size:28px;display:block;margin-bottom:8px;opacity:.4"></i>No merchandise sales recorded yet';
                merchCtx.parentNode.appendChild(me);
            }
            me.style.display = 'block';
        } else {
            merchCtx.style.display = '';
            var me2 = document.getElementById('merchSalesEmpty');
            if (me2) me2.style.display = 'none';
        _merchSalesChart = new Chart(merchCtx, {
            type: 'bar',
            data: {
                labels: _salesTrendLabels,
                datasets: [
                    {
                        label: 'Revenue (₱)',
                        data: _salesTrendMerch,
                        backgroundColor: 'rgba(59,130,246,0.75)',
                        borderColor: '#2563eb',
                        borderWidth: 1,
                        borderRadius: 5,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'top', labels: { font: { size: 11 }, boxWidth: 12, padding: 10 } },
                    tooltip: {
                        backgroundColor: '#1e293b',
                        titleColor: '#f1f5f9',
                        bodyColor: '#cbd5e1',
                        padding: 10,
                        callbacks: {
                            label: function(ctx) {
                                return ' ₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: { font:{size:10}, callback: function(v){ return '₱'+v.toLocaleString(); } },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: { ticks: { font:{size:10} }, grid: { display: false } }
                }
            }
        });
        } // end else (merch has data)
    }
}

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

            levels.forEach(function(f) {
                var cur = parseFloat(f.current_stock) || 0;
                var cap = parseFloat(f.capacity)      || 0;
                totalCurrent  += cur;
                totalCapacity += cap;
                var pct = cap > 0 ? Math.min(100, (cur / cap) * 100) : 0;

                var dotColor = '#22c55e';
                if (cur <= 500)       { dotColor = '#dc3545'; }
                else if (cur <= 2000) { dotColor = '#fd7e14'; }

                rows += '<tr style="border-bottom:1px solid #f5f5f5" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'">'
                    + '<td style="padding:10px 12px"><div style="display:flex;align-items:center;gap:8px">'
                    + '<span style="width:8px;height:8px;border-radius:50%;background:' + dotColor + ';flex-shrink:0"></span>'
                    + '<strong style="color:#00264D">' + escHtml(f.fuel_type_name) + '</strong></div></td>'
                    + '<td style="padding:10px 12px;text-align:right;font-weight:700;color:#101828">'
                    + Math.round(cur).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                    + '<td style="padding:10px 12px;text-align:right;color:#667085">'
                    + Math.round(cap).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td></tr>';
            });

            // Overall footer
            var overallPct    = totalCapacity > 0 ? (totalCurrent / totalCapacity) * 100 : 0;
            var overallColor  = '#22c55e';
            if (overallPct < 25)      { overallColor = '#dc3545'; }
            else if (overallPct < 50) { overallColor = '#fd7e14'; }

            var html = '';
            html += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:13px">'
                + '<thead><tr style="background:#f8fafc;border-bottom:2px solid #EAEAEA">'
                + '<th style="text-align:left;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Fuel Type</th>'
                + '<th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Current Level</th>'
                + '<th style="text-align:right;padding:10px 12px;color:#667085;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Capacity</th>'
                + '</tr></thead><tbody>' + rows + '</tbody>'
                + '<tfoot><tr style="background:#f0f4ff;border-top:2px solid #EAEAEA">'
                + '<td style="padding:10px 12px;font-weight:800;color:#00264D"><i class="fas fa-sigma" style="margin-right:5px"></i>Total</td>'
                + '<td style="padding:10px 12px;text-align:right;font-weight:800;color:' + overallColor + '">' + Math.round(totalCurrent).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
                + '<td style="padding:10px 12px;text-align:right;color:#667085">' + Math.round(totalCapacity).toLocaleString() + ' <span style="font-size:11px;color:#667085">L</span></td>'
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
// Stock Status Charts (Chart.js)
// ============================================================

// PHP data passed to JS
var _stockFuelData  = <?= json_encode(array_values($stock_chart_fuel)) ?>;
var _stockMerchData = <?= json_encode(array_values($stock_chart_merch)) ?>;
var _stationId      = <?= (int)$station_id ?>;

var _fuelChart  = null;
var _merchChart = null;
var _merchBarChart = null;
var _srUrgency  = 'medium';
var _srProductType = 'fuel';

function getFuelBarColor(current, capacity) {
    if (current <= 0) return '#ef4444';
    var pct = capacity > 0 ? (current / capacity) * 100 : 0;
    if (pct <= 10) return '#ef4444';
    if (pct <= 25) return '#f59e0b';
    return '#22c55e';
}

function getMerchBarColor(current, threshold) {
    if (current <= 0) return '#ef4444';
    if (current <= threshold) return '#f59e0b';
    return '#22c55e';
}

function getFuelStatusLabel(current, capacity) {
    if (current <= 0) return 'Out of Stock';
    var pct = capacity > 0 ? (current / capacity) * 100 : 0;
    if (pct <= 10) return 'Out of Stock';
    if (pct <= 25) return 'Low Stock';
    return 'Normal';
}

function buildFuelChart(allData) {
    var ctx = document.getElementById('fuelStockChart');
    var wrapEl = document.getElementById('fuel-stock-chart-wrap');
    var emptyEl = document.getElementById('fuel-stock-empty');
    if (!ctx) return;
    if (_fuelChart) { _fuelChart.destroy(); _fuelChart = null; }

    if (!allData || !allData.length) {
        if (wrapEl) wrapEl.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }

    // Data already filtered to Critical/Low Stock only from the backend
    var data = allData;

    if (!data || !data.length) {
        if (wrapEl) wrapEl.style.display = 'none';
        if (emptyEl) { emptyEl.style.display = 'block'; emptyEl.textContent = 'All fuel levels are normal.'; }
        return;
    }

    if (wrapEl) { wrapEl.style.display = 'block'; wrapEl.style.height = Math.max(120, data.length * 60) + 'px'; }

    // Labels: just the fuel type name
    var labels = data.map(function(d) {
        return d.fuel_type_name;
    });
    var currents = data.map(function(d) { return parseFloat(d.current_stock) || 0; });
    var caps     = data.map(function(d) { return parseFloat(d.capacity) || 0; });
    var colors   = data.map(function(d) {
        var cur = parseFloat(d.current_stock) || 0;
        var cap = parseFloat(d.capacity) || 0;
        var status = d.stock_status || getFuelStatusLabel(cur, cap);
        if (status === 'Out of Stock' || status === 'Critical') return '#ef4444';
        return '#f59e0b'; // Low Stock
    });

    _fuelChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Current Level (L)',
                    data: currents,
                    backgroundColor: colors,
                    borderRadius: 6,
                    borderSkipped: false,
                    barThickness: 26,
                },
                {
                    label: 'Capacity (L)',
                    data: caps,
                    backgroundColor: 'rgba(0,0,0,0.06)',
                    borderRadius: 6,
                    borderSkipped: false,
                    barThickness: 26,
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: { font: { size: 11 }, boxWidth: 12, padding: 14 }
                },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    callbacks: {
                        title: function(items) {
                            return data[items[0].dataIndex].fuel_type_name;
                        },
                        label: function(item) {
                            var d = data[item.dataIndex];
                            var cur = parseFloat(d.current_stock) || 0;
                            var cap = parseFloat(d.capacity) || 0;
                            var pct = cap > 0 ? ((cur / cap) * 100).toFixed(1) : '0.0';
                            var status = d.stock_status || getFuelStatusLabel(cur, cap);
                            if (item.datasetIndex === 0) {
                                var icon = status === 'Out of Stock' ? '⚠' : (status === 'Low Stock' ? '▼' : '✓');
                                return [
                                    icon + ' Status: ' + status,
                                    'Current Level: ' + Math.round(cur).toLocaleString() + ' L',
                                    'Capacity: ' + Math.round(cap).toLocaleString() + ' L',
                                    'Fill Level: ' + pct + '%',
                                    (status !== 'Normal' ? '' : null),
                                    (status !== 'Normal' ? 'Click to request restock' : null)
                                ].filter(function(v) { return v !== null; });
                            }
                            return item.datasetIndex === 1
                                ? ['Capacity: ' + Math.round(cap).toLocaleString() + ' L']
                                : null;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 }, callback: function(v) { return v.toLocaleString() + ' L'; } }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        font: { size: 11, weight: '600' },
                        color: '#344054',
                        // Wrap long labels
                        callback: function(val) {
                            var label = this.getLabelForValue(val);
                            if (label.length > 45) return label.substring(0, 43) + '…';
                            return label;
                        }
                    }
                }
            },
            onClick: function(evt, elements) {
                if (!elements.length) return;
                var idx = elements[0].index;
                var d = data[idx];
                var cur = parseFloat(d.current_stock) || 0;
                var cap = parseFloat(d.capacity) || 0;
                var status = d.stock_status || getFuelStatusLabel(cur, cap);
                if (status !== 'Normal') {
                    openStockRequestModal('fuel', d.fuel_type_name, cur, cap, 'L');
                }
            },
            onHover: function(evt, elements) {
                if (!elements.length) { evt.native.target.style.cursor = 'default'; return; }
                var idx = elements[0].index;
                var d = data[idx];
                var cur = parseFloat(d.current_stock) || 0;
                var cap = parseFloat(d.capacity) || 0;
                var status = d.stock_status || getFuelStatusLabel(cur, cap);
                evt.native.target.style.cursor = status !== 'Normal' ? 'pointer' : 'default';
            }
        }
    });
}

function buildMerchDonut(data) {
    var ctx = document.getElementById('merchStockDonut');
    var emptyEl = document.getElementById('merch-stock-empty');
    if (!ctx) return;
    if (_merchChart) { _merchChart.destroy(); _merchChart = null; }
    if (_merchBarChart) { _merchBarChart.destroy(); _merchBarChart = null; }
    if (window._merchBarChartHigh) { window._merchBarChartHigh.destroy(); window._merchBarChartHigh = null; }

    // Only show Low Stock and Out of Stock — filter out Normal
    var alertData = (data || []).filter(function(d) {
        var cur = parseFloat(d.current_stock) || 0;
        var thr = parseFloat(d.threshold) || 10;
        return cur <= thr; // includes out of stock (cur <= 0) and low stock (cur <= thr)
    });

    if (!alertData.length) {
        ['merch-stock-bar-wrap-low','merch-stock-bar-wrap-high'].forEach(function(id) {
            var el = document.getElementById(id); if (el) el.style.display = 'none';
        });
        if (emptyEl) emptyEl.style.display = 'block';
        var countsEl = document.getElementById('merch-donut-counts');
        if (countsEl) countsEl.innerHTML = '';
        return;
    }
    if (emptyEl) emptyEl.style.display = 'none';

    var low = 0, out = 0;
    alertData.forEach(function(d) {
        var cur = parseFloat(d.current_stock) || 0;
        if (cur <= 0) out++;
        else low++;
    });

    // Update counts label
    var countsEl = document.getElementById('merch-donut-counts');
    if (countsEl) {
        countsEl.innerHTML = (out > 0 ? '<span style="color:#991B1B;font-weight:700">' + out + ' Out of Stock</span><br>' : '')
            + (low > 0 ? '<span style="color:#92400E;font-weight:700">' + low + ' Low Stock</span>' : '');
    }

    _merchChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Low Stock', 'Out of Stock'],
            datasets: [{
                data: [low, out],
                backgroundColor: ['#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 10,
                    callbacks: {
                        label: function(item) {
                            var total = low + out;
                            var pct = total > 0 ? ((item.raw / total) * 100).toFixed(1) : 0;
                            return item.label + ': ' + item.raw + ' items (' + pct + '%)';
                        }
                    }
                }
            },
            onClick: function(evt, elements) {
                if (!elements.length) return;
                var idx = elements[0].index; // 0=low, 1=out
                var target = null;
                alertData.forEach(function(d) {
                    if (target) return;
                    var cur = parseFloat(d.current_stock) || 0;
                    var thr = parseFloat(d.threshold) || 10;
                    if (idx === 1 && cur <= 0) target = d;
                    else if (idx === 0 && cur > 0 && cur <= thr) target = d;
                });
                if (target) {
                    openStockRequestModal('merch', target.product_name, parseFloat(target.current_stock)||0, parseFloat(target.threshold)||10, target.unit || 'pcs');
                }
            }
        }
    });

    // Build horizontal bar chart for merchandise items
    buildMerchBar(alertData);
}

function buildMerchBar(alertData) {
    // Split into below-50 and above-50 (but still low/at threshold)
    var lowData  = (alertData || []).filter(function(d) { return (parseFloat(d.current_stock) || 0) < 50; });
    var highData = (alertData || []).filter(function(d) { return (parseFloat(d.current_stock) || 0) >= 50; });

    _buildMerchBarSingle('low',  lowData);
    _buildMerchBarSingle('high', highData);
}

function _buildMerchBarSingle(side, alertData) {
    var canvasId  = side === 'low' ? 'merchStockBarLow'        : 'merchStockBarHigh';
    var wrapId    = side === 'low' ? 'merch-stock-bar-wrap-low' : 'merch-stock-bar-wrap-high';
    var emptyId   = side === 'low' ? 'merch-bar-low-empty'      : 'merch-bar-high-empty';

    var barCtx    = document.getElementById(canvasId);
    var barWrapEl = document.getElementById(wrapId);
    var emptyEl   = document.getElementById(emptyId);

    // Destroy existing chart on this canvas
    if (side === 'low'  && _merchBarChart)            { _merchBarChart.destroy(); _merchBarChart = null; }
    if (side === 'high' && window._merchBarChartHigh) { window._merchBarChartHigh.destroy(); window._merchBarChartHigh = null; }

    if (!barCtx) return;

    if (!alertData || !alertData.length) {
        if (barWrapEl) barWrapEl.style.display = 'none';
        if (emptyEl)   emptyEl.style.display   = 'block';
        return;
    }

    // Set height BEFORE Chart.js init so it can measure correctly
    var newHeight = Math.max(120, alertData.length * 44);
    if (barWrapEl) {
        barWrapEl.style.height  = newHeight + 'px';
        barWrapEl.style.display = 'block';
    }
    if (emptyEl) emptyEl.style.display = 'none';

    var labels     = alertData.map(function(d) { return d.product_name; });
    var currents   = alertData.map(function(d) { return parseFloat(d.current_stock) || 0; });
    var thresholds = alertData.map(function(d) { return parseFloat(d.threshold) || 10; });
    var colors     = alertData.map(function(d) { return getMerchBarColor(parseFloat(d.current_stock)||0, parseFloat(d.threshold)||10); });

    var chart = new Chart(barCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Current Stock',
                    data: currents,
                    backgroundColor: colors,
                    borderRadius: 5,
                    borderSkipped: false,
                    barThickness: 18,
                },
                {
                    label: 'Threshold',
                    data: thresholds,
                    backgroundColor: 'rgba(0,0,0,0.07)',
                    borderRadius: 5,
                    borderSkipped: false,
                    barThickness: 18,
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e293b',
                    titleColor: '#f1f5f9',
                    bodyColor: '#cbd5e1',
                    padding: 12,
                    callbacks: {
                        title: function(items) {
                            var d = alertData[items[0].dataIndex];
                            return d.product_name;
                        },
                        label: function(item) {
                            if (item.datasetIndex !== 0) return null;
                            var d = alertData[item.dataIndex];
                            var cur = parseFloat(d.current_stock) || 0;
                            var thr = parseFloat(d.threshold) || 10;
                            var unit = d.unit || 'pcs';
                            var statusIcon = cur <= 0 ? '[OUT]' : '[LOW]';
                            var status = cur <= 0 ? 'Out of Stock' : 'Low Stock';
                            return [
                                statusIcon + ' Status: ' + status,
                                'Remaining: ' + Math.round(cur) + ' ' + unit,
                                'Threshold: ' + Math.round(thr) + ' ' + unit,
                                'Shortage: ' + Math.max(0, Math.round(thr - cur)) + ' ' + unit,
                                '',
                                'Click to request restock'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: false,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { size: 11, weight: '600' }, color: '#344054' }
                }
            },
            onClick: function(evt, elements) {
                if (!elements.length) return;
                var idx = elements[0].index;
                var d = alertData[idx];
                var cur = parseFloat(d.current_stock) || 0;
                var thr = parseFloat(d.threshold) || 10;
                openStockRequestModal('merch', d.product_name, cur, thr, d.unit || 'pcs');
            },
            onHover: function(evt, elements) {
                evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
            }
        }
    });

    if (side === 'low')  _merchBarChart = chart;
    if (side === 'high') window._merchBarChartHigh = chart;
}

function initStockCharts() {
    buildFuelChart(_stockFuelData);
    buildMerchDonut(_stockMerchData);
}

function refreshStockCharts() {
    var icon = document.getElementById('stock-refresh-icon');
    var icon2 = document.getElementById('fuel-stock-refresh-icon');
    if (icon) icon.style.animation = 'spin 0.8s linear infinite';
    if (icon2) icon2.style.animation = 'spin 0.8s linear infinite';
    fetch('staff_dashboard.php?refresh_stock_charts=1')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (icon) icon.style.animation = '';
            if (icon2) icon2.style.animation = '';
            if (!data.success) return;
            _stockFuelData  = data.fuel_stocks  || [];
            _stockMerchData = data.merch_stocks || [];
            buildFuelChart(_stockFuelData);
            buildMerchDonut(_stockMerchData);
        })
        .catch(function() {
            if (icon) icon.style.animation = '';
            if (icon2) icon2.style.animation = '';
        });
}

// ── Stock Request Modal ──────────────────────────────────────
function openStockRequestModal(type, productName, currentQty, threshold, unit) {
    _srProductType = type;
    _srUrgency = currentQty <= 0 ? 'high' : 'medium';
    setSrUrgency(_srUrgency);

    var infoEl = document.getElementById('sr-stock-info');
    var statusLabel = currentQty <= 0 ? '<i class="fas fa-ban"></i> OUT OF STOCK' : '<i class="fas fa-exclamation-triangle"></i> LOW STOCK';
    var statusColor = currentQty <= 0 ? '#991B1B' : '#92400E';
    if (infoEl) {
        infoEl.innerHTML = '<span style="font-weight:700;color:' + statusColor + '">' + statusLabel + '</span>'
            + ' &mdash; <strong>' + escHtml(productName) + '</strong>'
            + '<br><span style="color:#667085">Current: <strong>' + Math.round(currentQty) + ' ' + unit + '</strong>'
            + ' &nbsp;|&nbsp; Threshold: <strong>' + Math.round(threshold) + ' ' + unit + '</strong></span>';
    }

    var nameEl = document.getElementById('sr-product-name');
    if (nameEl) nameEl.value = productName;

    var typeEl = document.getElementById('sr-product-type');
    if (typeEl) typeEl.value = type === 'fuel' ? 'Fuel (Liters)' : 'Merchandise (Pieces)';

    var qtyEl = document.getElementById('sr-qty');
    if (qtyEl) {
        var suggested = type === 'fuel' ? Math.max(1000, Math.round(threshold * 2)) : Math.max(10, Math.round(threshold * 2));
        qtyEl.value = suggested;
        qtyEl.placeholder = 'Suggested: ' + suggested + ' ' + unit;
    }

    var notesEl = document.getElementById('sr-notes');
    if (notesEl) notesEl.value = '';

    var fb = document.getElementById('sr-feedback');
    if (fb) { fb.style.display = 'none'; fb.textContent = ''; }

    var btn = document.getElementById('sr-submit-btn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request'; }

    document.getElementById('stockRequestModal').classList.add('show');
}

function closeStockRequestModal() {
    document.getElementById('stockRequestModal').classList.remove('show');
}

function setSrUrgency(level) {
    _srUrgency = level;
    document.querySelectorAll('.sr-urgency-btn').forEach(function(btn) {
        btn.className = 'sr-urgency-btn';
        if (btn.dataset.urgency === level) {
            btn.classList.add('active-' + level);
        }
    });
}

function submitStockRequest() {
    var productName = (document.getElementById('sr-product-name').value || '').trim();
    var qty = parseInt(document.getElementById('sr-qty').value || '0', 10);
    var notes = (document.getElementById('sr-notes').value || '').trim();
    var fb = document.getElementById('sr-feedback');
    var btn = document.getElementById('sr-submit-btn');

    if (!productName) { showSrFeedback('error', 'Product name is required.'); return; }
    if (!qty || qty <= 0) { showSrFeedback('error', 'Please enter a valid quantity.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    var formData = new FormData();
    formData.append('action', 'request_stock');
    formData.append('req_type', _srProductType === 'fuel' ? 'fuel' : 'merch');
    formData.append('product_name', productName);
    formData.append('notes', notes);
    // CSRF token
    var csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    fetch('inventory.php', { method: 'POST', body: formData })
        .then(function(r) { return r.text(); })
        .then(function() {
            showSrFeedback('success', 'Stock request submitted! Waiting for manager approval.');
            btn.innerHTML = '<i class="fas fa-check"></i> Submitted';
            setTimeout(function() { closeStockRequestModal(); }, 1800);
        })
        .catch(function() {
            showSrFeedback('error', 'Failed to submit. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Request';
        });
}

function showSrFeedback(type, msg) {
    var fb = document.getElementById('sr-feedback');
    if (!fb) return;
    fb.style.display = 'block';
    fb.style.background = type === 'success' ? '#D1FAE5' : '#FEE2E2';
    fb.style.color = type === 'success' ? '#065F46' : '#991B1B';
    fb.style.border = '1px solid ' + (type === 'success' ? '#A7F3D0' : '#FECACA');
    fb.textContent = msg;
}

// Init charts on page load (after Chart.js is available)
(function waitForChartJs() {
    if (typeof Chart !== 'undefined') {
        buildSalesCharts();
        initStockCharts();
    } else {
        setTimeout(waitForChartJs, 100);
    }
})();

// Auto-refresh stock charts every 30 seconds
setInterval(refreshStockCharts, 30000);

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
