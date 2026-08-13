<?php
/**
 * Handler for Official Petron Fuel Sales Closing AJAX requests
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
$station_id = (int)($_SESSION['station_id'] ?? 0);

if ($station_id <= 0 && $user_id > 0) {
    try {
        $st_stmt = $pdo->prepare("SELECT station_id FROM users WHERE id = ?");
        $st_stmt->execute([$user_id]);
        $st_val = $st_stmt->fetchColumn();
        if ($st_val && (int)$st_val > 0) {
            $station_id = (int)$st_val;
            $_SESSION['station_id'] = $station_id;
        }
    } catch (Exception $e) {}
}
if ($station_id <= 0) $station_id = 1;

$action = $_REQUEST['action'] ?? '';

if ($action === 'get_summary') {
    $report_date = $_GET['date'] ?? date('Y-m-d');
    
    // Deduplicated query: fetch only the latest transaction entry per pump/nozzle for Section A
    $stmt = $pdo->prepare("
        SELECT 
            ft.id,
            COALESCE(NULLIF(fp.pump_number, ''), ft.fuel_type) AS pump_name,
            ft.fuel_type,
            COALESCE(ft.previous_reading, 0) AS beginning_reading,
            COALESCE(ft.present_reading, 0) AS ending_reading,
            COALESCE(ft.calibration, 0) AS calibration,
            COALESCE(ft.liters_sold, 0) AS liters_sold,
            COALESCE(ft.price_per_liter, 0) AS price_per_liter,
            COALESCE(ft.total_amount, 0) AS total_amount
        FROM fuel_transactions ft
        LEFT JOIN fuel_pumps fp ON ft.pump_id = fp.id
        INNER JOIN (
            SELECT MAX(id) AS max_id
            FROM fuel_transactions
            WHERE station_id = ? AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
              AND LOWER(COALESCE(status, '')) NOT IN ('voided','rejected','cancelled','canceled')
            GROUP BY COALESCE(pump_id, fuel_type)
        ) latest ON ft.id = latest.max_id
        ORDER BY ft.id ASC
    ");
    $stmt->execute([$station_id, $report_date, $report_date]);
    $meter_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Summaries per fuel type
    $by_fuel = [
        'Diesel'       => ['liters' => 0.0, 'amount' => 0.0],
        'Turbo Diesel' => ['liters' => 0.0, 'amount' => 0.0],
        'XCS Plus'     => ['liters' => 0.0, 'amount' => 0.0],
        'XTRA Advance' => ['liters' => 0.0, 'amount' => 0.0],
        'Kerosene'     => ['liters' => 0.0, 'amount' => 0.0],
    ];

    // Exactly 7 UGT Tanks
    $tank_summary = [
        'UGT #1 (DIESEL 1)'       => 0.0,
        'UGT #2 (DIESEL 2)'       => 0.0,
        'UGT #3 (TURBO DIESEL)'   => 0.0,
        'UGT #4 (XCS PLUS)'       => 0.0,
        'UGT #5 (XTRA ADVANCE 1)' => 0.0,
        'UGT #6 (XTRA ADVANCE 2)' => 0.0,
        'UGT #7 (KEROSENE)'       => 0.0,
    ];

    $total_fuel_sales   = 0.00;
    $total_liters_sold  = 0.00;

    foreach ($meter_rows as $row) {
        $pName  = strtoupper(trim($row['pump_name'] ?: $row['fuel_type']));
        $ftype  = strtolower(trim($row['fuel_type']));
        $amt    = (float)$row['total_amount'];
        $liters = (float)$row['liters_sold'];

        $total_fuel_sales  += $amt;
        $total_liters_sold += $liters;

        // Group by Fuel Type
        if (strpos($ftype, 'turbo') !== false) {
            $by_fuel['Turbo Diesel']['liters'] += $liters;
            $by_fuel['Turbo Diesel']['amount'] += $amt;
        } elseif (strpos($ftype, 'diesel') !== false || strpos($ftype, 'dsl') !== false) {
            $by_fuel['Diesel']['liters'] += $liters;
            $by_fuel['Diesel']['amount'] += $amt;
        } elseif (strpos($ftype, 'xcs') !== false) {
            $by_fuel['XCS Plus']['liters'] += $liters;
            $by_fuel['XCS Plus']['amount'] += $amt;
        } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false || strpos($ftype, 'unl') !== false || strpos($ftype, 'uls') !== false || strpos($ftype, 'primax') !== false) {
            $by_fuel['XTRA Advance']['liters'] += $liters;
            $by_fuel['XTRA Advance']['amount'] += $amt;
        } elseif (strpos($ftype, 'kerosene') !== false || strpos($ftype, 'kero') !== false) {
            $by_fuel['Kerosene']['liters'] += $liters;
            $by_fuel['Kerosene']['amount'] += $amt;
        }

        // Group into Exactly 7 UGT Tanks
        if (strpos($pName, 'DIESEL 1') !== false) {
            $tank_summary['UGT #1 (DIESEL 1)'] += $liters;
        } elseif (strpos($pName, 'DIESEL 2') !== false) {
            $tank_summary['UGT #2 (DIESEL 2)'] += $liters;
        } elseif (strpos($pName, 'TURBO') !== false) {
            $tank_summary['UGT #3 (TURBO DIESEL)'] += $liters;
        } elseif (strpos($pName, 'XCS') !== false) {
            $tank_summary['UGT #4 (XCS PLUS)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 1') !== false || strpos($pName, 'XTRA AD 1') !== false || strpos($pName, 'ADVANCE 1') !== false) {
            $tank_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
        } elseif (strpos($pName, 'XTRA UNL 2') !== false || strpos($pName, 'XTRA AD 2') !== false || strpos($pName, 'ADVANCE 2') !== false) {
            $tank_summary['UGT #6 (XTRA ADVANCE 2)'] += $liters;
        } elseif (strpos($pName, 'KERO') !== false) {
            $tank_summary['UGT #7 (KEROSENE)'] += $liters;
        } else {
            if (strpos($ftype, 'turbo') !== false) {
                $tank_summary['UGT #3 (TURBO DIESEL)'] += $liters;
            } elseif (strpos($ftype, 'diesel') !== false) {
                $tank_summary['UGT #1 (DIESEL 1)'] += $liters;
            } elseif (strpos($ftype, 'xcs') !== false) {
                $tank_summary['UGT #4 (XCS PLUS)'] += $liters;
            } elseif (strpos($ftype, 'xtra') !== false || strpos($ftype, 'advance') !== false) {
                $tank_summary['UGT #5 (XTRA ADVANCE 1)'] += $liters;
            } elseif (strpos($ftype, 'kero') !== false) {
                $tank_summary['UGT #7 (KEROSENE)'] += $liters;
            }
        }
    }

    // Fetch existing saved closing if available
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
        'meter_rows' => $meter_rows,
        'by_fuel' => $by_fuel,
        'tank_summary' => $tank_summary,
        'totals' => [
            'total_fuel_sales' => $total_fuel_sales,
            'total_liters_sold' => $total_liters_sold,
        ],
        'existing_closing' => $existing ?: null
    ]);
    exit;
}

if ($action === 'save_closing') {
    $report_date        = $_POST['report_date'] ?? date('Y-m-d');
    $shift              = $_POST['shift'] ?? 'General';
    $total_fuel_sales   = (float)($_POST['total_fuel_sales'] ?? 0);
    $total_liters       = (float)($_POST['total_liters'] ?? 0);

    $cash_shift1        = (float)($_POST['cash_shift1'] ?? 0);
    $cash_shift2        = (float)($_POST['cash_shift2'] ?? 0);
    $total_cash         = (float)($_POST['total_cash'] ?? 0);

    $ar_shift1          = (float)($_POST['ar_shift1'] ?? 0);
    $ar_shift2          = (float)($_POST['ar_shift2'] ?? 0);
    $total_ar           = (float)($_POST['total_ar'] ?? 0);

    $net_sales          = (float)($_POST['net_sales'] ?? 0);
    $total_cash_bank    = (float)($_POST['total_cash_bank'] ?? 0);

    $verified_by        = trim($_POST['verified_by'] ?? '');
    $checked_by         = trim($_POST['checked_by'] ?? '');

    try {
        $stmt_chk = $pdo->prepare("SELECT id FROM fuel_sales_closing WHERE station_id = ? AND report_date = ?");
        $stmt_chk->execute([$station_id, $report_date]);
        $exist_id = $stmt_chk->fetchColumn();

        if ($exist_id) {
            $stmt_upd = $pdo->prepare("
                UPDATE fuel_sales_closing SET
                    shift = ?, total_fuel_sales = ?, total_liters = ?, cash_shift1 = ?, cash_shift2 = ?,
                    total_cash = ?, ar_shift1 = ?, ar_shift2 = ?, total_ar = ?, net_sales = ?,
                    total_cash_bank = ?, verified_by = ?, checked_by = ?, encoded_by = ?, encoded_at = NOW(), status = 'saved'
                WHERE id = ?
            ");
            $stmt_upd->execute([
                $shift, $total_fuel_sales, $total_liters, $cash_shift1, $cash_shift2,
                $total_cash, $ar_shift1, $ar_shift2, $total_ar, $net_sales,
                $total_cash_bank, $verified_by, $checked_by, $user_id, $exist_id
            ]);
            $saved_id = $exist_id;
        } else {
            $stmt_ins = $pdo->prepare("
                INSERT INTO fuel_sales_closing (
                    station_id, report_date, shift, total_fuel_sales, total_liters, cash_shift1, cash_shift2,
                    total_cash, ar_shift1, ar_shift2, total_ar, net_sales, total_cash_bank, verified_by, checked_by,
                    encoded_by, encoded_at, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'saved'
                )
            ");
            $stmt_ins->execute([
                $station_id, $report_date, $shift, $total_fuel_sales, $total_liters, $cash_shift1, $cash_shift2,
                $total_cash, $ar_shift1, $ar_shift2, $total_ar, $net_sales, $total_cash_bank, $verified_by, $checked_by, $user_id
            ]);
            $saved_id = $pdo->lastInsertId();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Official Fuel Sales Closing saved successfully.',
            'closing_id' => $saved_id,
            'report_date' => $report_date
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to save closing: ' . $e->getMessage()]);
    }
    exit;
}
