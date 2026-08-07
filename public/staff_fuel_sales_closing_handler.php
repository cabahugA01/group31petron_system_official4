<?php
/**
 * Handler for Fuel Sales Closing AJAX requests
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$station_id = (int)($_SESSION['station_id'] ?? 1);
$action     = $_REQUEST['action'] ?? '';

if ($action === 'get_summary') {
    $report_date = $_GET['date'] ?? date('Y-m-d');
    
    // 1. Fetch Fuel Transactions for date (checking transaction_date or created_at)
    $stmt = $pdo->prepare("
        SELECT 
            fuel_type,
            SUM(liters_sold) AS total_liters,
            SUM(total_amount) AS total_amount
        FROM fuel_transactions
        WHERE station_id = ? AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
        GROUP BY fuel_type
    ");
    $stmt->execute([$station_id, $report_date, $report_date]);
    $fuel_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_fuel_sales   = 0.00;
    $total_liters_sold  = 0.00;
    $diesel_sales       = 0.00;
    $turbo_diesel_sales = 0.00;
    $xcs_plus_sales     = 0.00;
    $xtra_advance_sales = 0.00;
    $kerosene_sales     = 0.00;

    foreach ($fuel_rows as $row) {
        $ftype  = strtolower(trim($row['fuel_type']));
        $amt    = (float)$row['total_amount'];
        $liters = (float)$row['total_liters'];

        $total_fuel_sales  += $amt;
        $total_liters_sold += $liters;

        if (strpos($ftype, 'turbo') !== false) {
            $turbo_diesel_sales += $amt;
        } elseif (strpos($ftype, 'diesel') !== false || strpos($ftype, 'dsl') !== false) {
            $diesel_sales += $amt;
        } elseif (strpos($ftype, 'xcs') !== false) {
            $xcs_plus_sales += $amt;
        } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false || strpos($ftype, 'unl') !== false || strpos($ftype, 'uls') !== false || strpos($ftype, 'primax') !== false) {
            $xtra_advance_sales += $amt;
        } elseif (strpos($ftype, 'kerosene') !== false || strpos($ftype, 'kero') !== false) {
            $kerosene_sales += $amt;
        }
    }

    // 2. Fetch Job Orders / Service Income for date
    $stmt_service = $pdo->prepare("
        SELECT COALESCE(SUM(total_cost), 0) AS service_income
        FROM job_orders
        WHERE station_id = ? AND DATE(created_at) = ? AND status != 'Cancelled'
    ");
    $stmt_service->execute([$station_id, $report_date]);
    $service_income = (float)$stmt_service->fetchColumn();

    // 3. Fetch existing saved closing if available
    $stmt_closing = $pdo->prepare("
        SELECT * FROM fuel_sales_closing
        WHERE station_id = ? AND report_date = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt_closing->execute([$station_id, $report_date]);
    $existing = $stmt_closing->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'report_date' => $report_date,
        'auto_fuel_summary' => [
            'total_fuel_sales'   => $total_fuel_sales,
            'total_liters_sold'  => $total_liters_sold,
            'diesel_sales'       => $diesel_sales,
            'turbo_diesel_sales' => $turbo_diesel_sales,
            'xcs_plus_sales'     => $xcs_plus_sales,
            'xtra_advance_sales' => $xtra_advance_sales,
            'kerosene_sales'     => $kerosene_sales
        ],
        'auto_service_income' => $service_income,
        'existing_closing'    => $existing ?: null
    ]);
    exit;
}

if ($action === 'save_closing') {
    $report_date        = $_POST['report_date'] ?? date('Y-m-d');
    $shift              = $_POST['shift'] ?? 'General';
    $total_fuel_sales   = (float)($_POST['total_fuel_sales'] ?? 0);
    $total_liters       = (float)($_POST['total_liters'] ?? 0);
    $diesel_sales       = (float)($_POST['diesel_sales'] ?? 0);
    $turbo_diesel_sales = (float)($_POST['turbo_diesel_sales'] ?? 0);
    $xcs_plus_sales     = (float)($_POST['xcs_plus_sales'] ?? 0);
    $xtra_advance_sales = (float)($_POST['xtra_advance_sales'] ?? 0);
    $kerosene_sales     = (float)($_POST['kerosene_sales'] ?? 0);

    $olg_sales          = (float)($_POST['olg_sales'] ?? 0);
    $tba_sales          = (float)($_POST['tba_sales'] ?? 0);
    $service_income     = (float)($_POST['service_income'] ?? 0);
    $other_sales        = (float)($_POST['other_sales'] ?? 0);
    $ar_collected       = (float)($_POST['ar_collected'] ?? 0);
    $total_store_sales  = (float)($_POST['total_store_sales'] ?? 0);

    $cash_shift1        = (float)($_POST['cash_shift1'] ?? 0);
    $cash_shift2        = (float)($_POST['cash_shift2'] ?? 0);
    $total_cash         = (float)($_POST['total_cash'] ?? 0);

    $ar_shift1          = (float)($_POST['ar_shift1'] ?? 0);
    $ar_shift2          = (float)($_POST['ar_shift2'] ?? 0);
    $total_ar           = (float)($_POST['total_ar'] ?? 0);

    $gross_sales        = (float)($_POST['gross_sales'] ?? 0);
    $expected_cash      = (float)($_POST['expected_cash'] ?? 0);
    $total_cash_bank    = (float)($_POST['total_cash_bank'] ?? 0);

    try {
        // Check if existing record
        $stmt_chk = $pdo->prepare("SELECT id FROM fuel_sales_closing WHERE station_id = ? AND report_date = ?");
        $stmt_chk->execute([$station_id, $report_date]);
        $exist_id = $stmt_chk->fetchColumn();

        if ($exist_id) {
            $stmt_upd = $pdo->prepare("
                UPDATE fuel_sales_closing SET
                    shift = ?, total_fuel_sales = ?, total_liters = ?, diesel_sales = ?, turbo_diesel_sales = ?,
                    xcs_plus_sales = ?, xtra_advance_sales = ?, kerosene_sales = ?, olg_sales = ?, tba_sales = ?,
                    service_income = ?, other_sales = ?, ar_collected = ?, total_store_sales = ?, cash_shift1 = ?,
                    cash_shift2 = ?, total_cash = ?, ar_shift1 = ?, ar_shift2 = ?, total_ar = ?, gross_sales = ?,
                    expected_cash = ?, total_cash_bank = ?, encoded_by = ?, encoded_at = NOW(), status = 'saved'
                WHERE id = ?
            ");
            $stmt_upd->execute([
                $shift, $total_fuel_sales, $total_liters, $diesel_sales, $turbo_diesel_sales,
                $xcs_plus_sales, $xtra_advance_sales, $kerosene_sales, $olg_sales, $tba_sales,
                $service_income, $other_sales, $ar_collected, $total_store_sales, $cash_shift1,
                $cash_shift2, $total_cash, $ar_shift1, $ar_shift2, $total_ar, $gross_sales,
                $expected_cash, $total_cash_bank, $user_id, $exist_id
            ]);
            $saved_id = $exist_id;
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO fuel_sales_closing (
                    station_id, report_date, shift, total_fuel_sales, total_liters, diesel_sales, turbo_diesel_sales,
                    xcs_plus_sales, xtra_advance_sales, kerosene_sales, olg_sales, tba_sales, service_income,
                    other_sales, ar_collected, total_store_sales, cash_shift1, cash_shift2, total_cash,
                    ar_shift1, ar_shift2, total_ar, gross_sales, expected_cash, total_cash_bank, encoded_by, encoded_at, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'saved'
                )
            ");
            $stmt_ins->execute([
                $station_id, $report_date, $shift, $total_fuel_sales, $total_liters, $diesel_sales, $turbo_diesel_sales,
                $xcs_plus_sales, $xtra_advance_sales, $kerosene_sales, $olg_sales, $tba_sales, $service_income,
                $other_sales, $ar_collected, $total_store_sales, $cash_shift1, $cash_shift2, $total_cash,
                $ar_shift1, $ar_shift2, $total_ar, $gross_sales, $expected_cash, $total_cash_bank, $user_id
            ]);
            $saved_id = $pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Fuel Sales Closing saved successfully.',
            'closing_id' => $saved_id,
            'report_date' => $report_date
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to save closing: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
exit;
