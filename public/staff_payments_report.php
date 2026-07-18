<?php
/**
 * STAFF PAYMENTS REPORT
 * Complete payment tracking with shift summaries
 * Plain black & white design, structured tabular format
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$user_id = (int)($me['id'] ?? 0);
$station_id = user_station_id();

// Determine user's assigned shift (for filtering shift summary display)
// Staff only see their own shift box; managers/admins see both
$user_shift_number = 0; // 0 = show both, 1 = shift 1 only, 2 = shift 2 only
if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
    try {
        // Try active labor session first
        $ls_stmt = $pdo->prepare("SELECT shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
        $ls_stmt->execute([$user_id]);
        $ls = $ls_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ls) {
            // Fall back to most recent session from today
            $ls_stmt2 = $pdo->prepare("SELECT shift_period, shift_name FROM labor_sessions WHERE user_id = ? AND DATE(start_time) = CURDATE() ORDER BY start_time DESC LIMIT 1");
            $ls_stmt2->execute([$user_id]);
            $ls = $ls_stmt2->fetch(PDO::FETCH_ASSOC);
        }
        if ($ls) {
            $sp = strtolower(trim($ls['shift_period'] ?? ''));
            $sn = strtolower(trim($ls['shift_name'] ?? ''));
            $combined = $sp . ' ' . $sn;
            if (strpos($combined, '2') !== false || strpos($combined, 'second') !== false || strpos($combined, 'afternoon') !== false || strpos($combined, 'evening') !== false) {
                $user_shift_number = 2;
            } elseif (strpos($combined, '1') !== false || strpos($combined, 'first') !== false || strpos($combined, 'morning') !== false) {
                $user_shift_number = 1;
            }
        }
    } catch (Exception $e) { $user_shift_number = 0; }

    // User-specific overrides: Yyang is Shift 1, Judy Lastimosa is Shift 2
    $username_lower = isset($me['username']) ? strtolower(trim($me['username'])) : '';
    $first_name_lower = isset($me['first_name']) ? strtolower(trim($me['first_name'])) : '';
    $last_name_lower = isset($me['last_name']) ? strtolower(trim($me['last_name'])) : '';

    if ($username_lower === 'yyang' || $first_name_lower === 'yyang') {
        $user_shift_number = 1;
    } elseif ($username_lower === 'judy' || $first_name_lower === 'judy' || $last_name_lower === 'lastimosa') {
        $user_shift_number = 2;
    }
}

// Access control
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php'); exit;
}

// Module gate
if (!in_array($role, ['superadmin', 'developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

// Get Station Info
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) {
        $station_name = $st['name'];
    }
} catch (Exception $e) {}

// Date handling
$today = date('Y-m-d');
$date_start = trim($_GET['date_start'] ?? date('Y-m-01'));
$date_end = trim($_GET['date_end'] ?? $today);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end)) $date_end = $today;

// Helper functions
function table_exists($pdo, $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function payment_is_shift1(array $payment): bool {
    $shift = strtolower(trim((string)($payment['shift'] ?? '')));
    if ($shift !== '') {
        if (preg_match('/(^|[^a-z0-9])(2|second|shift 2|shift2|pm|evening|afternoon|night)([^a-z0-9]|$)/', $shift)) {
            return false;
        }
        if (preg_match('/(^|[^a-z0-9])(1|first|shift 1|shift1|am|morning|day)([^a-z0-9]|$)/', $shift)) {
            return true;
        }
    }

    $created_at = $payment['created_at'] ?? null;
    if ($created_at) {
        $timestamp = strtotime((string)$created_at);
        if ($timestamp !== false) {
            $hour = (int)date('G', $timestamp);
            return $hour >= 6 && $hour < 14;
        }
    }

    return true;
}

function payment_mode_bucket($mode): string {
    $mode = strtolower(trim(str_replace(['_', '-'], ' ', (string)$mode)));

    if (strpos($mode, 'fleet') !== false) return 'fleet';
    if (strpos($mode, 'efuel') !== false || strpos($mode, 'e fuel') !== false) return 'efuel';
    if (strpos($mode, 'gcash') !== false || strpos($mode, 'maya') !== false || strpos($mode, 'wallet') !== false) return 'ewallet';
    if (strpos($mode, 'card') !== false) return 'card';

    return 'cash';
}

// Check available tables
$has_payments = table_exists($pdo, 'payments');
$has_merchandise_transactions = table_exists($pdo, 'merchandise_transactions');
$has_job_orders = table_exists($pdo, 'job_orders');

// Initialize data
$payments = [];
$shift1_cash = 0;
$shift1_card = 0;
$shift1_ewallet = 0;
$shift1_efuel = 0;
$shift1_fleet = 0;
$shift2_cash = 0;
$shift2_card = 0;
$shift2_ewallet = 0;
$shift2_efuel = 0;
$shift2_fleet = 0;

// ============================================================
// FETCH PAYMENTS
// ============================================================
if ($has_payments) {
    try {
        $sql = "SELECT 
                    p.id,
                    p.transaction_id,
                    COALESCE(c.name, p.customer_name, 'Walk-in') AS customer_name,
                    p.payment_mode,
                    p.amount_paid,
                    COALESCE(p.outstanding_balance, 0) AS outstanding_balance,
                    p.shift,
                    u.username AS encoder,
                    p.status,
                    p.remarks,
                    p.created_at
            FROM payments p
            LEFT JOIN customers c ON p.customer_id = c.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.station_id = ? 
              AND DATE(p.created_at) BETWEEN ? AND ?
            ORDER BY p.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$station_id, $date_start, $date_end]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_message = "Error fetching payments: " . $e->getMessage();
    }
}

if (count($payments) === 0) {
    $fallback_payments = [];

    if ($has_merchandise_transactions) {
        try {
            $merchDateExpr = "COALESCE(NULLIF(mt.created_at, '0000-00-00 00:00:00'), NULLIF(mt.transaction_date, '0000-00-00 00:00:00'))";
            $merchPaidExpr = "CASE
                WHEN COALESCE(mt.amount_paid, 0) > 0 THEN mt.amount_paid
                WHEN LOWER(COALESCE(mt.payment_status, '')) IN ('paid', 'completed') THEN COALESCE(mt.total_amount, 0)
                ELSE 0
            END";

            $sql = "SELECT
                        mt.id,
                        COALESCE(NULLIF(mt.transaction_id, ''), CONCAT('MT-', mt.id)) AS transaction_id,
                        COALESCE(
                            NULLIF(TRIM(mt.customer_name), ''),
                            NULLIF(TRIM(CONCAT(COALESCE(mt.customer_first_name, ''), ' ', COALESCE(mt.customer_last_name, ''))), ''),
                            'Walk-in'
                        ) AS customer_name,
                        COALESCE(NULLIF(mt.payment_method, ''), 'Cash') AS payment_mode,
                        {$merchPaidExpr} AS amount_paid,
                        COALESCE(mt.balance_due, GREATEST(COALESCE(mt.total_amount, 0) - {$merchPaidExpr}, 0), 0) AS outstanding_balance,
                        CASE
                            WHEN LOWER(COALESCE(mt.shift_period, mt.shift_name, '')) IN ('first', 'first shift', 'shift 1', 'shift1', '1') THEN 'Shift 1'
                            WHEN LOWER(COALESCE(mt.shift_period, mt.shift_name, '')) IN ('second', 'second shift', 'shift 2', 'shift2', '2') THEN 'Shift 2'
                            WHEN HOUR({$merchDateExpr}) >= 6 AND HOUR({$merchDateExpr}) < 14 THEN 'Shift 1'
                            ELSE 'Shift 2'
                        END AS shift,
                        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.username, CONCAT('User #', mt.staff_id), 'N/A') AS encoder,
                        COALESCE(NULLIF(mt.payment_status, ''), NULLIF(mt.workflow_status, ''), NULLIF(mt.validation_status, ''), 'Recorded') AS status,
                        COALESCE(mt.staff_remarks, mt.remarks, mt.manager_remarks, '') AS remarks,
                        {$merchDateExpr} AS created_at
                FROM merchandise_transactions mt
                LEFT JOIN users u ON u.id = mt.staff_id
                WHERE mt.station_id = ?
                  AND DATE({$merchDateExpr}) BETWEEN ? AND ?
                  AND LOWER(COALESCE(mt.validation_status, '')) NOT IN ('voided', 'void', 'rejected', 'cancelled', 'canceled')
                  AND LOWER(COALESCE(mt.workflow_status, '')) NOT IN ('voided', 'void', 'rejected', 'cancelled', 'canceled')
                  AND (
                        COALESCE(mt.amount_paid, 0) > 0
                        OR LOWER(COALESCE(mt.payment_status, '')) IN ('paid', 'partial', 'partially paid', 'completed')
                  )
                ORDER BY {$merchDateExpr} DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $fallback_payments = array_merge($fallback_payments, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            $error_message = "Error fetching merchandise payments: " . $e->getMessage();
        }
    }

    if ($has_job_orders) {
        try {
            $jobDateExpr = "COALESCE(NULLIF(jo.created_at, '0000-00-00 00:00:00'), NULLIF(jo.completed_at, '0000-00-00 00:00:00'))";
            $jobTotalExpr = "COALESCE(jo.total_cost, jo.estimated_cost, jo.actual_labor_cost + jo.actual_parts_cost, jo.estimated_labor_cost + jo.estimated_parts_cost, 0)";
            $jobPaidExpr = "CASE
                WHEN COALESCE(jo.amount_paid, 0) > 0 THEN jo.amount_paid
                WHEN LOWER(COALESCE(jo.payment_status, '')) IN ('paid', 'completed') THEN {$jobTotalExpr}
                ELSE 0
            END";

            $sql = "SELECT
                        jo.id,
                        COALESCE(NULLIF(jo.job_order_id, ''), NULLIF(jo.job_order_number, ''), CONCAT('JO-', jo.id)) AS transaction_id,
                        COALESCE(NULLIF(TRIM(jo.customer_name), ''), c.name, 'Walk-in') AS customer_name,
                        COALESCE(NULLIF(jo.payment_method, ''), 'Cash') AS payment_mode,
                        {$jobPaidExpr} AS amount_paid,
                        COALESCE(jo.balance_due, GREATEST({$jobTotalExpr} - {$jobPaidExpr}, 0), 0) AS outstanding_balance,
                        CASE
                            WHEN HOUR({$jobDateExpr}) >= 6 AND HOUR({$jobDateExpr}) < 14 THEN 'Shift 1'
                            ELSE 'Shift 2'
                        END AS shift,
                        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.username, CONCAT('User #', COALESCE(jo.created_by, jo.user_id)), 'N/A') AS encoder,
                        COALESCE(NULLIF(jo.payment_status, ''), NULLIF(jo.validation_status, ''), NULLIF(jo.status, ''), 'Recorded') AS status,
                        COALESCE(jo.notes, jo.additional_notes, jo.admin_remarks, '') AS remarks,
                        {$jobDateExpr} AS created_at
                FROM job_orders jo
                LEFT JOIN users u ON u.id = COALESCE(jo.created_by, jo.user_id)
                LEFT JOIN customers c ON c.id = jo.customer_id
                WHERE jo.station_id = ?
                  AND DATE({$jobDateExpr}) BETWEEN ? AND ?
                  AND LOWER(COALESCE(jo.status, '')) NOT IN ('cancelled', 'canceled', 'rejected')
                  AND LOWER(COALESCE(jo.validation_status, '')) NOT IN ('voided', 'void', 'rejected', 'cancelled', 'canceled')
                  AND (
                        COALESCE(jo.amount_paid, 0) > 0
                        OR LOWER(COALESCE(jo.payment_status, '')) IN ('paid', 'partial', 'partially paid', 'completed')
                  )
                ORDER BY {$jobDateExpr} DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $fallback_payments = array_merge($fallback_payments, $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {
            $error_message = "Error fetching job order payments: " . $e->getMessage();
        }
    }

    if (count($fallback_payments) > 0) {
        usort($fallback_payments, function ($a, $b) {
            return strtotime($b['created_at'] ?? '') <=> strtotime($a['created_at'] ?? '');
        });
        $payments = $fallback_payments;
    }
}

// Filter payments based on user shift if user is staff
if ($user_shift_number !== 0) {
    $payments = array_filter($payments, function($payment) use ($user_shift_number) {
        $is_shift1 = payment_is_shift1($payment);
        if ($user_shift_number === 1) {
            return $is_shift1;
        } else {
            return !$is_shift1;
        }
    });
}

foreach ($payments as $payment) {
    $amount = (float)($payment['amount_paid'] ?? 0);
    $bucket = payment_mode_bucket($payment['payment_mode'] ?? 'cash');
    $is_shift1 = payment_is_shift1($payment);

    if ($bucket === 'card') {
        if ($is_shift1) $shift1_card += $amount;
        else $shift2_card += $amount;
    } elseif ($bucket === 'ewallet') {
        if ($is_shift1) $shift1_ewallet += $amount;
        else $shift2_ewallet += $amount;
    } elseif ($bucket === 'efuel') {
        if ($is_shift1) $shift1_efuel += $amount;
        else $shift2_efuel += $amount;
    } elseif ($bucket === 'fleet') {
        if ($is_shift1) $shift1_fleet += $amount;
        else $shift2_fleet += $amount;
    } else {
        if ($is_shift1) $shift1_cash += $amount;
        else $shift2_cash += $amount;
    }
}

// Calculate totals
$shift1_total = $shift1_cash + $shift1_card + $shift1_ewallet + $shift1_efuel + $shift1_fleet;
$shift2_total = $shift2_cash + $shift2_card + $shift2_ewallet + $shift2_efuel + $shift2_fleet;
$overall_total = $shift1_total + $shift2_total;

// ============================================================
// EXCEL EXPORT HANDLER
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="Payments_Report_' . $date_start . '_to_' . $date_end . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Payments Report</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:Print>';
    echo '<x:ValidPrinterInfo/>';
    echo '</x:Print>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; }';
    echo 'th, td { border: 1px solid #000000; padding: 8px; text-align: left; }';
    echo 'th { background-color: #E0E0E0; font-weight: bold; text-align: center; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '.font-bold { font-weight: bold; }';
    echo 'h1 { font-size: 18px; font-weight: bold; margin: 10px 0; }';
    echo 'h2 { font-size: 14px; font-weight: bold; margin: 15px 0 10px 0; background-color: #F0F0F0; padding: 5px; border: 1px solid #000; }';
    echo 'h3 { font-size: 12px; font-weight: bold; margin: 10px 0 5px 0; }';
    echo 'p { margin: 5px 0; }';
    echo '.summary-table { background-color: #F9F9F9; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    // Header
    echo '<h1>PAYMENTS REPORT</h1>';
    echo '<p>' . htmlspecialchars($station_name) . '</p>';
    echo '<p><strong>Period:</strong> ' . date('F d, Y', strtotime($date_start)) . ' - ' . date('F d, Y', strtotime($date_end)) . '</p>';
    echo '<br/>';
    
    // PAYMENTS TABLE
    echo '<h2>PAYMENTS</h2>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Transaction ID</th>';
    echo '<th>Customer Name</th>';
    echo '<th>Payment Mode</th>';
    echo '<th>Amount Paid</th>';
    echo '<th>Outstanding Balance</th>';
    echo '<th>Shift</th>';
    echo '<th>Encoder</th>';
    echo '<th>Status</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    if (count($payments) > 0) {
        foreach ($payments as $payment) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($payment['transaction_id']) . '</td>';
            echo '<td>' . htmlspecialchars($payment['customer_name']) . '</td>';
            echo '<td>' . htmlspecialchars($payment['payment_mode']) . '</td>';
            echo '<td class="text-right font-bold">₱' . number_format($payment['amount_paid'], 2) . '</td>';
            echo '<td class="text-right">₱' . number_format($payment['outstanding_balance'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($payment['shift'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($payment['encoder'] ?? 'N/A') . '</td>';
            echo '<td class="text-center">' . strtoupper($payment['status']) . '</td>';
            echo '<td>' . htmlspecialchars($payment['remarks'] ?? '—') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="9" style="text-align: center; padding: 20px;">No payments found for this period.</td></tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<br/>';
    
    // Shift 1 Summary
    if ($user_shift_number !== 2) {
        echo '<h3>SHIFT 1 PAYMENTS SUMMARY (6AM - 2PM)</h3>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Payment Mode</th>';
        echo '<th>Total Amount</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        echo '<tr><td>Cash</td><td class="text-right font-bold">₱' . number_format($shift1_cash, 2) . '</td></tr>';
        echo '<tr><td>Card</td><td class="text-right font-bold">₱' . number_format($shift1_card, 2) . '</td></tr>';
        echo '<tr><td>E-Wallet</td><td class="text-right font-bold">₱' . number_format($shift1_ewallet, 2) . '</td></tr>';
        echo '<tr><td>E-Fuel Card</td><td class="text-right font-bold">₱' . number_format($shift1_efuel, 2) . '</td></tr>';
        echo '<tr><td>Fleet Card</td><td class="text-right font-bold">₱' . number_format($shift1_fleet, 2) . '</td></tr>';
        echo '<tr class="font-bold"><td>SHIFT 1 TOTAL</td><td class="text-right">₱' . number_format($shift1_total, 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // Shift 2 Summary
    if ($user_shift_number !== 1) {
        echo '<h3>SHIFT 2 PAYMENTS SUMMARY (2PM - 10PM)</h3>';
        echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Payment Mode</th>';
        echo '<th>Total Amount</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        echo '<tr><td>Cash</td><td class="text-right font-bold">₱' . number_format($shift2_cash, 2) . '</td></tr>';
        echo '<tr><td>Card</td><td class="text-right font-bold">₱' . number_format($shift2_card, 2) . '</td></tr>';
        echo '<tr><td>E-Wallet</td><td class="text-right font-bold">₱' . number_format($shift2_ewallet, 2) . '</td></tr>';
        echo '<tr><td>E-Fuel Card</td><td class="text-right font-bold">₱' . number_format($shift2_efuel, 2) . '</td></tr>';
        echo '<tr><td>Fleet Card</td><td class="text-right font-bold">₱' . number_format($shift2_fleet, 2) . '</td></tr>';
        echo '<tr class="font-bold"><td>SHIFT 2 TOTAL</td><td class="text-right">₱' . number_format($shift2_total, 2) . '</td></tr>';
        echo '</tbody>';
        echo '</table>';
        echo '<br/>';
    }
    
    // Overall Summary
    echo '<h3>OVERALL DAILY SUMMARY</h3>';
    echo '<table border="1" cellpadding="5" cellspacing="0" class="summary-table" style="width: 60%;">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Payment Mode</th>';
    echo '<th>Total Amount</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    echo '<tr><td>Cash</td><td class="text-right font-bold">₱' . number_format($shift1_cash + $shift2_cash, 2) . '</td></tr>';
    echo '<tr><td>Card</td><td class="text-right font-bold">₱' . number_format($shift1_card + $shift2_card, 2) . '</td></tr>';
    echo '<tr><td>E-Wallet</td><td class="text-right font-bold">₱' . number_format($shift1_ewallet + $shift2_ewallet, 2) . '</td></tr>';
    echo '<tr><td>E-Fuel Card</td><td class="text-right font-bold">₱' . number_format($shift1_efuel + $shift2_efuel, 2) . '</td></tr>';
    echo '<tr><td>Fleet Card</td><td class="text-right font-bold">₱' . number_format($shift1_fleet + $shift2_fleet, 2) . '</td></tr>';
    echo '<tr class="font-bold" style="font-size: 14px;"><td>GRAND TOTAL</td><td class="text-right">₱' . number_format($overall_total, 2) . '</td></tr>';
    echo '</tbody>';
    echo '</table>';
    
    echo '</body>';
    echo '</html>';
    exit;
}

$page_title = "Payments Report";

// Include system header
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    body {
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .main-content {
        width: 100%;
        max-width: 100%;
        padding: 0;
        margin: 0;
    }
    
    .container {
        max-width: 100%;
        margin: 0;
        padding: 0;
        background: white;
    }
    
    .header {
        background: #fff;
        color: #000;
        padding: 15px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    
    .header h1 {
        font-size: 22px;
        margin: 0 0 8px 0;
        font-weight: 700;
        color: #000;
    }
    
    .header p {
        font-size: 12px;
        color: #000;
        margin: 3px 0;
    }
    
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .date-controls {
        display: flex;
        gap: 8px;
        align-items: center;
        font-size: 12px;
    }
    
    .date-controls label {
        font-weight: 700;
        color: #000;
    }
    
    .date-controls input[type="date"] {
        padding: 6px 10px;
        border: 1px solid #000;
        font-size: 12px;
    }
    
    .btn {
        padding: 6px 12px;
        border: 1px solid #000;
        background: #fff;
        cursor: pointer;
        font-size: 12px;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: #000;
    }
    
    .btn:hover {
        background: #f5f5f5;
    }
    
    .btn-primary {
        background: #000;
        color: #fff;
    }
    
    .btn-primary:hover {
        background: #333;
    }
    
    /* Export Buttons (Filter Button Style) */
    .flt-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid transparent;
        transition: all 0.15s;
        height: 34px;
        line-height: 1;
        white-space: nowrap;
        text-decoration: none;
        background: white !important;
    }
    .flt-btn-search { color: #002F70 !important; border-color: #002F70 !important; }
    .flt-btn-search:hover { background: #002F70 !important; color: #fff !important; }
    .flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
    .flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
    .flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
    .flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
    .flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
    .flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }
    .flt-btn-csv    { color: #002F70 !important; border-color: #002F70 !important; }
    .flt-btn-csv:hover    { background: #002F70 !important; color: #fff !important; }
    
    .print-area {
        background: #fff;
    }
    
    .content {
        padding: 0;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 700;
        margin: 20px 0 10px 0;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    
    .table-container {
        overflow-x: visible;
        margin-bottom: 20px;
        width: 100%;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        border: 1px solid #000;
        font-size: 10px;
        table-layout: fixed;
    }
    
    /* Specific column widths for better layout */
    #paymentsTable th:nth-child(1),
    #paymentsTable td:nth-child(1) { width: 15%; } /* Transaction ID */
    #paymentsTable th:nth-child(2),
    #paymentsTable td:nth-child(2) { width: 14%; } /* Customer Name */
    #paymentsTable th:nth-child(3),
    #paymentsTable td:nth-child(3) { width: 10%; } /* Payment Mode */
    #paymentsTable th:nth-child(4),
    #paymentsTable td:nth-child(4) { width: 10%; } /* Amount Paid */
    #paymentsTable th:nth-child(5),
    #paymentsTable td:nth-child(5) { width: 10%; } /* Outstanding */
    #paymentsTable th:nth-child(6),
    #paymentsTable td:nth-child(6) { width: 8%; } /* Shift */
    #paymentsTable th:nth-child(7),
    #paymentsTable td:nth-child(7) { width: 12%; } /* Encoder */
    #paymentsTable th:nth-child(8),
    #paymentsTable td:nth-child(8) { width: 8%; } /* Status */
    #paymentsTable th:nth-child(9),
    #paymentsTable td:nth-child(9) { width: 13%; } /* Remarks */
    
    thead { background: #fff; color: #000; }
    th { padding: 6px 4px; text-align: left; font-weight: 700; font-size: 9px; text-transform: uppercase; border: 1px solid #000; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    td { padding: 5px 4px; border: 1px solid #000; font-size: 10px; word-wrap: break-word; overflow-wrap: break-word; }
    tbody tr { background: #fff; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 700; }
    
    .shift-summary { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin: 0; }
    .shift-box { background: #fff; padding: 15px; border: 1px solid #000; height: 100%; }
    .shift-box h3 { font-size: 14px; color: #000; margin: 0 0 10px 0; font-weight: 700; border-bottom: 1px solid #000; padding-bottom: 8px; text-transform: uppercase; }
    .shift-box table { font-size: 11px; }
    .shift-box td { padding: 6px 4px; border: none; border-bottom: 1px solid #ddd; }
    
    .summary-grid-container { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 0; }
    .summary-grid-container > div { display: flex; flex-direction: column; }
    .summary-grid-container .shift-box { flex: 1; }
    
    @media print {
        @page { size: legal portrait; margin: 0.5in 0.4in; }

        /* Hide everything, show only print-area */
        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important; top: 0 !important; left: 0 !important;
            width: 100% !important; margin: 0 !important; padding: 0 !important;
            background: white !important;
            overflow-x: hidden !important;
        }
        html, body { 
            margin: 0 !important; 
            padding: 0 !important; 
            background: white !important; 
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        .container, .content { 
            margin: 0 !important; 
            padding: 0 !important; 
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
        }

        /* Hide sidebar, navigation, header elements */
        .sidebar, .header-nav, .top-nav, nav, .menu-toggle, .hamburger, 
        #sidebar, #header, #menu-toggle, .nav, .navbar, .menu-btn,
        .toggle-btn, .sidebar-toggle, [class*="toggle"], [class*="menu-btn"] {
            display: none !important;
            visibility: hidden !important;
            width: 0 !important;
            height: 0 !important;
        }

        /* ── Kill ALL icons everywhere ── */
        i, svg, .fas, .far, .fab, .fa, .fa-solid, .fa-regular, .fa-brands,
        [class*="fa-"], [class^="fa "], .icon, [class*="icon-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
            visibility: hidden !important;
        }
        /* Re-show the print-area text but keep icons gone */
        .print-area, .print-area * { visibility: visible !important; }
        .print-area i, .print-area svg, .print-area .fas, .print-area .far,
        .print-area .fab, .print-area .fa, .print-area [class*="fa-"],
        .print-area .icon, .print-area [class*="icon-"] {
            display: none !important;
            width: 0 !important; height: 0 !important;
            font-size: 0 !important; line-height: 0 !important;
            margin: 0 !important; padding: 0 !important;
            visibility: hidden !important;
        }

        .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .header h1 { font-size: 16px !important; font-weight: 700 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .section-title { font-size: 12px !important; font-weight: 700 !important; margin: 8px 0 4px 0 !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .table-container { 
            overflow: hidden !important; 
            overflow-x: hidden !important; 
            width: 100% !important; 
            max-width: 100% !important; 
            text-align: center !important; 
        }
        table { 
            width: 100% !important; 
            max-width: 100% !important; 
            border-collapse: collapse !important; 
            font-size: 9px !important; 
            table-layout: fixed !important; 
            margin: 0 auto 8px auto !important; 
        }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { 
            font-size: 9px !important; 
            padding: 5px 4px !important; 
            border: 1px solid #000 !important; 
            background: #fff !important; 
            color: #000 !important; 
            font-weight: 700 !important; 
            text-align: center !important; 
            white-space: nowrap !important; 
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        td { 
            font-size: 8px !important; 
            padding: 4px 3px !important; 
            border: 1px solid #000 !important; 
            white-space: nowrap !important; 
            vertical-align: top !important; 
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            word-wrap: break-word !important;
        }
        .shift-summary { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .shift-box { border: 1px solid #000 !important; padding: 5px !important; }
        .shift-box h3 { font-size: 10px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px 0 !important; }
        .shift-box table { width: auto !important; margin: 0 !important; }
        .shift-box td { border: none !important; border-bottom: 1px solid #ddd !important; font-size: 9px !important; }
    }
</style>

<div class="stock-page">
<!-- CONTROLS - OUTSIDE PRINTABLE AREA -->
<div class="controls">
    <div class="date-controls">
        <label><strong>From:</strong></label>
        <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>">
        <label><strong>To:</strong></label>
        <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>">
        <button class="btn btn-primary" onclick="applyFilters()">
            Apply
        </button>
    </div>
    
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <!-- Excel -->
        <a href="?export=excel&date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>" 
           class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <!-- CSV -->
        <button onclick="exportTableToCSV('paymentsTable','payments_report_<?= date('Ymd') ?>.csv')"
                class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <!-- PDF -->
        <button type="button" onclick="exportPrintableAreaToPDF('.print-area', 'Staff Payments Report', 'staff_payments_report_<?= date('Ymd', strtotime($date_start)) ?>_<?= date('Ymd', strtotime($date_end)) ?>', this)" class="flt-btn flt-btn-pdf" title="Export PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
        <!-- Print -->
        <button type="button" onclick="printReportArea()" class="flt-btn flt-btn-print" title="Print report">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<!-- PRINTABLE DOCUMENT AREA -->
<div class="print-area">
    <div class="container">
        <div class="header">
            <h1>PAYMENTS REPORT</h1>
            <p><?= htmlspecialchars($station_name) ?></p>
            <p><strong>Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?> - <?= date('F d, Y', strtotime($date_end)) ?></p>
        </div>
        
        <div class="content">
            <div class="section-title">PAYMENTS</div>
            <div class="table-container">
                <table id="paymentsTable">
                    <thead>
                        <tr>
                            <th>TRANSACTION ID</th>
                            <th>CUSTOMER NAME</th>
                            <th>PAYMENT MODE</th>
                            <th>AMOUNT PAID</th>
                            <th>OUTSTANDING</th>
                            <th>SHIFT</th>
                            <th>ENCODER</th>
                            <th>STATUS</th>
                            <th>REMARKS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($payments) > 0): foreach ($payments as $payment): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($payment['transaction_id']) ?></strong></td>
                            <td><?= htmlspecialchars($payment['customer_name']) ?></td>
                            <td><?= htmlspecialchars($payment['payment_mode']) ?></td>
                            <td class="text-right font-bold">₱<?= number_format($payment['amount_paid'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($payment['outstanding_balance'], 2) ?></td>
                            <td><?= htmlspecialchars($payment['shift'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($payment['encoder'] ?? 'N/A') ?></td>
                            <td class="text-center"><?= strtoupper($payment['status']) ?></td>
                            <td><?= htmlspecialchars($payment['remarks'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="9" style="text-align: center; padding: 40px;">No payments found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="summary-grid-container">
                <div>
                    <div class="section-title">SHIFT SUMMARY</div>
                    <div style="display: flex; flex-direction: column; gap: 15px; height: 100%;">
                        <?php if ($user_shift_number !== 2): // Hide Shift 1 summary for Shift 2 staff ?>
                        <div class="shift-box">
                            <h3>SHIFT 1 (6AM - 2PM)</h3>
                            <table>
                                <tbody>
                                    <tr><td><strong>Cash:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_cash, 2) ?></td></tr>
                                    <tr><td><strong>Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_card, 2) ?></td></tr>
                                    <tr><td><strong>E-Wallet:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_ewallet, 2) ?></td></tr>
                                    <tr><td><strong>E-Fuel Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_efuel, 2) ?></td></tr>
                                    <tr><td><strong>Fleet Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_fleet, 2) ?></td></tr>
                                    <tr><td colspan="2" style="height: 5px;"></td></tr>
                                    <tr><td class="font-bold">TOTAL:</td><td class="text-right font-bold">₱<?= number_format($shift1_total, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($user_shift_number !== 1): // Hide Shift 2 summary for Shift 1 staff ?>
                        <div class="shift-box">
                            <h3>SHIFT 2 (2PM - 10PM)</h3>
                            <table>
                                <tbody>
                                    <tr><td><strong>Cash:</strong></td><td class="text-right font-bold">₱<?= number_format($shift2_cash, 2) ?></td></tr>
                                    <tr><td><strong>Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift2_card, 2) ?></td></tr>
                                    <tr><td><strong>E-Wallet:</strong></td><td class="text-right font-bold">₱<?= number_format($shift2_ewallet, 2) ?></td></tr>
                                    <tr><td><strong>E-Fuel Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift2_efuel, 2) ?></td></tr>
                                    <tr><td><strong>Fleet Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift2_fleet, 2) ?></td></tr>
                                    <tr><td colspan="2" style="height: 5px;"></td></tr>
                                    <tr><td class="font-bold">TOTAL:</td><td class="text-right font-bold">₱<?= number_format($shift2_total, 2) ?></td></tr>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <div class="section-title">OVERALL DAILY SUMMARY</div>
                    <div class="shift-box" style="height: calc(100% - 50px);">
                        <table>
                            <tbody>
                                <tr><td><strong>Total Cash:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_cash + $shift2_cash, 2) ?></td></tr>
                                <tr><td><strong>Total Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_card + $shift2_card, 2) ?></td></tr>
                                <tr><td><strong>Total E-Wallet:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_ewallet + $shift2_ewallet, 2) ?></td></tr>
                                <tr><td><strong>Total E-Fuel Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_efuel + $shift2_efuel, 2) ?></td></tr>
                                <tr><td><strong>Total Fleet Card:</strong></td><td class="text-right font-bold">₱<?= number_format($shift1_fleet + $shift2_fleet, 2) ?></td></tr>
                                <tr><td colspan="2" style="height: 5px;"></td></tr>
                                <tr><td class="font-bold">GRAND TOTAL:</td><td class="text-right font-bold" style="font-size: 14px;">₱<?= number_format($overall_total, 2) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
</div>
</div>

<script>
function applyFilters() {
    const dateStart = document.getElementById('date_start').value;
    const dateEnd = document.getElementById('date_end').value;
    window.location.href = `?date_start=${dateStart}&date_end=${dateEnd}`;
}
document.getElementById('date_start').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
document.getElementById('date_end').addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
