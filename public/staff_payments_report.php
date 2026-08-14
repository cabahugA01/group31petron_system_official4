<?php
/**
 * SHIFT TURNOVER REPORT
 * Replaces: staff_payments_report.php
 * Fully dynamic — fetches from fuel_transactions, merchandise_transactions,
 * job_orders, labor_sessions for the selected date & shift.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? 'staff');
$user_id    = (int)($me['id'] ?? 0);
$station_id = user_station_id();

if (!in_array($role, ['staff','cashier','pump_attendant','manager','admin','superadmin','developer'])) {
    header('Location: dashboard.php'); exit;
}
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}
if (!$station_id) die('Error: You are not assigned to a station.');

// ── Station Info ──────────────────────────────────────────────────────────────
$station_name     = 'Station';
$station_location = '';
try {
    $s = $pdo->prepare("SELECT name, location FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    if ($st = $s->fetch(PDO::FETCH_ASSOC)) {
        $station_name     = $st['name'];
        $station_location = $st['location'] ?? '';
    }
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$today        = date('Y-m-d');
$date_start   = trim($_GET['date_start'] ?? $_GET['biz_date'] ?? $today);
$date_end     = trim($_GET['date_end']   ?? $_GET['biz_date'] ?? $today);
$filter_shift = trim($_GET['shift']      ?? '');  // '' = all, 'first', 'second', etc.

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_start)) $date_start = $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_end))   $date_end   = $today;

// ── Build shift WHERE clauses ─────────────────────────────────────────────────
$shift_where_ft   = '';   // fuel_transactions
$shift_where_mt   = '';   // merchandise_transactions
$shift_where_jo   = '';   // job_orders
$shift_params_ft  = ['station_id' => $station_id, 'dstart' => $date_start, 'dend' => $date_end];
$shift_params_mt  = ['station_id' => $station_id, 'dstart' => $date_start, 'dend' => $date_end];

if ($filter_shift !== '') {
    $shift_where_ft .= " AND LOWER(COALESCE(ft.shift_period,'')) LIKE :shift_key";
    $shift_params_ft['shift_key'] = '%' . strtolower($filter_shift) . '%';
    $shift_where_mt .= " AND LOWER(COALESCE(mt.shift_period,'')) LIKE :shift_key";
    $shift_params_mt['shift_key'] = '%' . strtolower($filter_shift) . '%';
}

// ── Distinct shifts available for this date ───────────────────────────────────
$available_shifts = [];
try {
    $stmtS = $pdo->prepare(
        "SELECT DISTINCT shift_period, shift_name
         FROM (
             SELECT shift_period, shift_name FROM fuel_transactions
             WHERE station_id=:sid AND DATE(transaction_date)=:d
             UNION
             SELECT shift_period, shift_name FROM merchandise_transactions
             WHERE station_id=:sid2 AND DATE(transaction_date)=:d2
         ) combined
         ORDER BY shift_name"
    );
    $stmtS->execute(['sid'=>$station_id,'d'=>$biz_date,'sid2'=>$station_id,'d2'=>$biz_date]);
    $available_shifts = $stmtS->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// ── Labor Sessions (Shift Info) ───────────────────────────────────────────────
$shift_sessions = [];
try {
    $lsWhere = "WHERE ls.station_id=:station_id AND DATE(ls.start_time) BETWEEN :dstart AND :dend";
    $lsParams = ['station_id'=>$station_id,'dstart'=>$date_start,'dend'=>$date_end];
    if ($filter_shift !== '') {
        $lsWhere .= " AND LOWER(COALESCE(ls.shift_period,'')) LIKE :shift_key";
        $lsParams['shift_key'] = '%' . strtolower($filter_shift) . '%';
    }
    $stmt = $pdo->prepare(
        "SELECT ls.*, CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,'')) AS staff_name
         FROM labor_sessions ls
         LEFT JOIN users u ON u.id = ls.user_id
         {$lsWhere}
         ORDER BY ls.start_time"
    );
    $stmt->execute($lsParams);
    $shift_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Primary session for header display
$primary_session  = $shift_sessions[0] ?? null;
$display_shift    = $primary_session['shift_name']  ?? ($filter_shift !== '' ? ucfirst($filter_shift).' Shift' : 'All Shifts');
$display_staff    = $primary_session['staff_name']  ?? '—';
$display_time_in  = $primary_session ? date('h:i A', strtotime($primary_session['start_time'])) : '—';
$display_time_out = ($primary_session && $primary_session['end_time'])
                  ? date('h:i A', strtotime($primary_session['end_time'])) : '—';

// ── Fuel Sales ────────────────────────────────────────────────────────────────
$fuel_sales_total = 0;
$fuel_summary     = [];
try {
    $stmt = $pdo->prepare(
        "SELECT fuel_type AS raw_fuel, SUM(COALESCE(liters_sold,0)) AS liters, SUM(COALESCE(total_amount,0)) AS amount
         FROM fuel_transactions ft
         WHERE ft.station_id=:station_id AND DATE(COALESCE(ft.transaction_date, ft.created_at)) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(ft.status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_ft}
         GROUP BY fuel_type ORDER BY fuel_type"
    );
    $stmt->execute($shift_params_ft);
    $fuel_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fuel_rows as $r) {
        $fuel_sales_total += (float)$r['amount'];
        $fuel_summary[]    = [
            'fuel_type' => $r['raw_fuel'],
            'liters'    => (float)$r['liters'],
            'amount'    => (float)$r['amount'],
        ];
    }
} catch (Exception $e) {}

// ── Merchandise Transactions ───────────────────────────────────────────────────
$merch_sales_total = 0;
$merch_tx_count   = 0;
$merch_items_sold  = 0;
$payment_summary   = []; // payment_method => amount
$credit_sales      = 0;
$fleet_sales       = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT mt.payment_method, mt.total_amount, mt.fleet_card_number, mt.credit_account_number,
                mt.credit_company_name, mt.fleet_company_name
         FROM merchandise_transactions mt
         WHERE mt.station_id=:station_id AND DATE(mt.transaction_date) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_mt}"
    );
    $stmt->execute($shift_params_mt);
    $mt_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $merch_tx_count = count($mt_rows);
    foreach ($mt_rows as $r) {
        $amt = (float)$r['total_amount'];
        $merch_sales_total += $amt;
        $pm = trim($r['payment_method'] ?? 'Cash');
        $payment_summary[$pm] = ($payment_summary[$pm] ?? 0) + $amt;
        if (stripos($pm, 'credit') !== false || !empty($r['credit_account_number'])) {
            $credit_sales += $amt;
        }
        if (stripos($pm, 'fleet') !== false || !empty($r['fleet_card_number'])) {
            $fleet_sales += $amt;
        }
    }

    // Items sold
    $stmtI = $pdo->prepare(
        "SELECT SUM(mti.quantity) AS items
         FROM merchandise_transaction_items mti
         JOIN merchandise_transactions mt ON mt.transaction_id = mti.transaction_id
         WHERE mt.station_id=:station_id AND DATE(mt.transaction_date) BETWEEN :dstart AND :dend
           AND LOWER(COALESCE(mt.validation_status,'')) NOT IN ('voided','rejected','cancelled','canceled')
           {$shift_where_mt}"
    );
    $stmtI->execute($shift_params_mt);
    $merch_items_sold = (int)($stmtI->fetchColumn() ?: 0);
} catch (Exception $e) {}

// ── Job Order Sales & Fuel from job_orders via service entries ────────────────
$labor_fee_revenue  = 0;
$service_fee_revenue = 0;
$parts_sales        = 0;
$jo_status_counts   = ['Pending'=>0,'In Progress'=>0,'Completed'=>0,'Released'=>0,'Cancelled'=>0];
$jo_payment_summary = [];
try {
    $joParams = ['station_id'=>$station_id,'dstart'=>$date_start,'dend'=>$date_end];
    $joWhere  = "WHERE jo.station_id=:station_id AND DATE(jo.created_at) BETWEEN :dstart AND :dend";
    $stmt = $pdo->prepare(
        "SELECT jo.status, jo.actual_labor_cost, jo.actual_parts_cost, jo.total_cost,
                jo.amount_paid, jo.payment_method, jo.is_credit
         FROM job_orders jo {$joWhere}"
    );
    $stmt->execute($joParams);
    $jo_rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($jo_rows as $r) {
        $labor  = (float)($r['actual_labor_cost'] ?? 0);
        $parts  = (float)($r['actual_parts_cost'] ?? 0);
        $labor_fee_revenue   += $labor;
        $parts_sales         += $parts;
        $service_fee_revenue += (float)($r['total_cost'] ?? 0);
        $pm = trim($r['payment_method'] ?? '');
        if ($pm) {
            $jo_payment_summary[$pm] = ($jo_payment_summary[$pm] ?? 0) + (float)($r['amount_paid'] ?? 0);
            $payment_summary[$pm]    = ($payment_summary[$pm] ?? 0) + (float)($r['amount_paid'] ?? 0);
        }

        // Normalize status
        $st = ucfirst(strtolower(trim($r['status'] ?? '')));
        $st_map = ['in_progress'=>'In Progress','inprogress'=>'In Progress','in progress'=>'In Progress',
                   'pending'=>'Pending','completed'=>'Completed','released'=>'Released',
                   'cancelled'=>'Cancelled','canceled'=>'Cancelled'];
        $st_key = $st_map[strtolower($st)] ?? $st;
        if (array_key_exists($st_key, $jo_status_counts)) {
            $jo_status_counts[$st_key]++;
        }
    }
} catch (Exception $e) {}

// ── Outstanding Receivables ────────────────────────────────────────────────────
$outstanding_receivables = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT SUM(COALESCE(balance_due, total_amount - COALESCE(amount_paid,0), 0)) AS outstanding
         FROM merchandise_transactions
         WHERE station_id=:station_id AND LOWER(COALESCE(payment_status,'')) NOT IN ('paid','fully_paid')
           AND (credit_account_number IS NOT NULL OR fleet_card_number IS NOT NULL
                OR LOWER(COALESCE(payment_method,'')) LIKE '%credit%'
                OR LOWER(COALESCE(payment_method,'')) LIKE '%fleet%')"
    );
    $stmt->execute(['station_id'=>$station_id]);
    $outstanding_receivables = (float)($stmt->fetchColumn() ?: 0);
} catch (Exception $e) {}

// ── Cash Turnover ─────────────────────────────────────────────────────────────
// Beginning cash = not dynamically tracked, show 0 unless shift_reports has it
$beginning_cash   = 0;
$cash_sales       = ($payment_summary['Cash'] ?? 0);
$cash_collections = 0; // Collections on credit accounts — hard to compute without a collections table
try {
    // Try shift_reports for beginning cash
    $stmt = $pdo->prepare(
        "SELECT * FROM shift_reports WHERE station_id=:sid AND report_date=:d ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['sid'=>$station_id,'d'=>$biz_date]);
    if ($sr = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // No explicit beginning_cash column found; keep 0
    }
} catch (Exception $e) {}

$cash_turnover = $beginning_cash + $cash_sales + $cash_collections;
$ending_cash   = $cash_turnover; // same without deductions tracked

// ── Overall Sales ─────────────────────────────────────────────────────────────
$overall_sales = $fuel_sales_total + $merch_sales_total + $labor_fee_revenue + $parts_sales;

// ── Payment method display map ─────────────────────────────────────────────────
$all_payment_methods = [
    'Cash'               => 0,
    'Credit Card'        => 0,
    'Debit Card'         => 0,
    'GCash'              => 0,
    'Maya'               => 0,
    'Petron Fleet Card'  => 0,
    'Credit Account'     => 0,
];
// Merge collected payment data into display map
foreach ($payment_summary as $pm => $amt) {
    $pm_key = $pm;
    // Normalize keys
    if (stripos($pm, 'gcash') !== false) $pm_key = 'GCash';
    elseif (stripos($pm, 'maya') !== false || stripos($pm, 'paymaya') !== false) $pm_key = 'Maya';
    elseif (stripos($pm, 'fleet') !== false) $pm_key = 'Petron Fleet Card';
    elseif (stripos($pm, 'credit card') !== false || stripos($pm, 'creditcard') !== false) $pm_key = 'Credit Card';
    elseif (stripos($pm, 'debit') !== false) $pm_key = 'Debit Card';
    elseif (stripos($pm, 'credit') !== false || stripos($pm, 'account') !== false) $pm_key = 'Credit Account';
    elseif (stripos($pm, 'cash') !== false) $pm_key = 'Cash';
    $all_payment_methods[$pm_key] = ($all_payment_methods[$pm_key] ?? 0) + $amt;
}

// ── Export: PDF / Excel / CSV Slugs ──────────────────────────────────────────
$export_slug = date('Ymd', strtotime($date_start));
if ($date_start !== $date_end) $export_slug .= '_to_' . date('Ymd', strtotime($date_end));
if ($filter_shift) $export_slug .= '_' . strtolower(preg_replace('/\s+/','_',$filter_shift));

// ── Export: Excel ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Shift_Turnover_Report_{$export_slug}.xls";
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 11px; margin-bottom: 15px; }';
    echo 'th, td { border: 1px solid #000000; padding: 6px; text-align: left; }';
    echo 'th { background-color: #002F6C; color: #ffffff; font-weight: bold; }';
    echo '.total { font-weight: bold; background-color: #e8f0fe; }';
    echo '.text-right { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo '</style></head><body>';

    echo "<h2>SHIFT TURNOVER REPORT</h2>";
    echo "<p><strong>Station:</strong> " . htmlspecialchars($station_name . ($station_location ? " — {$station_location}" : "")) . "</p>";
    echo "<p><strong>Business Date:</strong> " . date('F d, Y', strtotime($biz_date)) . "</p>";
    echo "<p><strong>Shift:</strong> " . htmlspecialchars($display_shift) . " | <strong>Staff:</strong> " . htmlspecialchars($display_staff) . "</p>";
    echo "<br/>";

    // Sales Summary
    echo "<h3>SALES SUMMARY</h3><table><thead><tr><th>Category</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Fuel Sales</td><td class='text-right'>PHP " . number_format($fuel_sales_total, 2) . "</td></tr>";
    echo "<tr><td>Merchandise Sales</td><td class='text-right'>PHP " . number_format($merch_sales_total, 2) . "</td></tr>";
    echo "<tr><td>Labor Fee Revenue</td><td class='text-right'>PHP " . number_format($labor_fee_revenue, 2) . "</td></tr>";
    echo "<tr><td>Service Fee Revenue</td><td class='text-right'>PHP " . number_format($service_fee_revenue, 2) . "</td></tr>";
    echo "<tr><td>Parts Sales</td><td class='text-right'>PHP " . number_format($parts_sales, 2) . "</td></tr>";
    echo "<tr class='total'><td>Overall Sales</td><td class='text-right'>PHP " . number_format($overall_sales, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Payment Collection Summary
    echo "<h3>PAYMENT COLLECTION SUMMARY</h3><table><thead><tr><th>Payment Method</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    foreach ($all_payment_methods as $pm => $amt) {
        echo "<tr><td>" . htmlspecialchars($pm) . "</td><td class='text-right'>PHP " . number_format($amt, 2) . "</td></tr>";
    }
    echo "<tr class='total'><td>Total Collections</td><td class='text-right'>PHP " . number_format(array_sum($all_payment_methods), 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Accounts Receivable Turnover
    echo "<h3>ACCOUNTS RECEIVABLE TURNOVER</h3><table><thead><tr><th>Description</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Credit Account Sales</td><td class='text-right'>PHP " . number_format($credit_sales, 2) . "</td></tr>";
    echo "<tr><td>Fleet Card Sales</td><td class='text-right'>PHP " . number_format($fleet_sales, 2) . "</td></tr>";
    echo "<tr><td>Outstanding Receivables</td><td class='text-right'>PHP " . number_format($outstanding_receivables, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Cash Turnover
    echo "<h3>CASH TURNOVER</h3><table><thead><tr><th>Description</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    echo "<tr><td>Beginning Cash</td><td class='text-right'>PHP " . number_format($beginning_cash, 2) . "</td></tr>";
    echo "<tr><td>Cash Sales</td><td class='text-right'>PHP " . number_format($cash_sales, 2) . "</td></tr>";
    echo "<tr><td>Cash Collections</td><td class='text-right'>PHP " . number_format($cash_collections, 2) . "</td></tr>";
    echo "<tr class='total'><td>Cash Turnover</td><td class='text-right'>PHP " . number_format($cash_turnover, 2) . "</td></tr>";
    echo "<tr><td>Ending Cash</td><td class='text-right'>PHP " . number_format($ending_cash, 2) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Fuel Summary
    echo "<h3>FUEL SUMMARY</h3><table><thead><tr><th>Fuel Type</th><th class='text-right'>Liters Sold</th><th class='text-right'>Amount</th></tr></thead><tbody>";
    if ($fuel_summary) {
        $total_liters = 0;
        foreach ($fuel_summary as $f) {
            $total_liters += $f['liters'];
            echo "<tr><td>" . htmlspecialchars($f['fuel_type']) . "</td><td class='text-right'>" . number_format($f['liters'], 2) . " L</td><td class='text-right'>PHP " . number_format($f['amount'], 2) . "</td></tr>";
        }
        echo "<tr class='total'><td>Total</td><td class='text-right'>" . number_format($total_liters, 2) . " L</td><td class='text-right'>PHP " . number_format($fuel_sales_total, 2) . "</td></tr>";
    } else {
        echo "<tr><td colspan='3' class='text-center'>No fuel transactions for this period.</td></tr>";
    }
    echo "</tbody></table><br/>";

    // Job Order Summary
    echo "<h3>JOB ORDER SUMMARY</h3><table><thead><tr><th>Status</th><th class='text-center'>Count</th></tr></thead><tbody>";
    foreach ($jo_status_counts as $st => $cnt) {
        echo "<tr><td>" . htmlspecialchars($st) . "</td><td class='text-center'>{$cnt}</td></tr>";
    }
    echo "<tr class='total'><td>Total</td><td class='text-center'>" . array_sum($jo_status_counts) . "</td></tr>";
    echo "</tbody></table><br/>";

    // Merchandise Summary
    echo "<h3>MERCHANDISE SUMMARY</h3><table><thead><tr><th>Description</th><th class='text-right'>Value</th></tr></thead><tbody>";
    echo "<tr><td>Total Transactions</td><td class='text-right'>" . number_format($merch_tx_count) . "</td></tr>";
    echo "<tr><td>Total Items Sold</td><td class='text-right'>" . number_format($merch_items_sold) . "</td></tr>";
    echo "<tr class='total'><td>Total Merchandise Sales</td><td class='text-right'>PHP " . number_format($merch_sales_total, 2) . "</td></tr>";
    echo "</tbody></table>";

    echo "</body></html>";
    exit;
}

// ── Export: CSV ───────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "Shift_Turnover_Report_{$export_slug}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, ['SHIFT TURNOVER REPORT']);
    fputcsv($out, [$station_name . ($station_location ? " — {$station_location}" : "")]);
    fputcsv($out, ['Business Date:', date('F d, Y', strtotime($biz_date))]);
    fputcsv($out, ['Shift:', $display_shift, 'Staff:', $display_staff]);
    fputcsv($out, []);

    // Sales Summary
    fputcsv($out, ['SALES SUMMARY']);
    fputcsv($out, ['Category', 'Amount']);
    fputcsv($out, ['Fuel Sales', 'PHP ' . number_format($fuel_sales_total, 2)]);
    fputcsv($out, ['Merchandise Sales', 'PHP ' . number_format($merch_sales_total, 2)]);
    fputcsv($out, ['Labor Fee Revenue', 'PHP ' . number_format($labor_fee_revenue, 2)]);
    fputcsv($out, ['Service Fee Revenue', 'PHP ' . number_format($service_fee_revenue, 2)]);
    fputcsv($out, ['Parts Sales', 'PHP ' . number_format($parts_sales, 2)]);
    fputcsv($out, ['Overall Sales', 'PHP ' . number_format($overall_sales, 2)]);
    fputcsv($out, []);

    // Payment Collection Summary
    fputcsv($out, ['PAYMENT COLLECTION SUMMARY']);
    fputcsv($out, ['Payment Method', 'Amount']);
    foreach ($all_payment_methods as $pm => $amt) {
        fputcsv($out, [$pm, 'PHP ' . number_format($amt, 2)]);
    }
    fputcsv($out, ['Total Collections', 'PHP ' . number_format(array_sum($all_payment_methods), 2)]);
    fputcsv($out, []);

    // Accounts Receivable Turnover
    fputcsv($out, ['ACCOUNTS RECEIVABLE TURNOVER']);
    fputcsv($out, ['Description', 'Amount']);
    fputcsv($out, ['Credit Account Sales', 'PHP ' . number_format($credit_sales, 2)]);
    fputcsv($out, ['Fleet Card Sales', 'PHP ' . number_format($fleet_sales, 2)]);
    fputcsv($out, ['Outstanding Receivables', 'PHP ' . number_format($outstanding_receivables, 2)]);
    fputcsv($out, []);

    // Cash Turnover
    fputcsv($out, ['CASH TURNOVER']);
    fputcsv($out, ['Description', 'Amount']);
    fputcsv($out, ['Beginning Cash', 'PHP ' . number_format($beginning_cash, 2)]);
    fputcsv($out, ['Cash Sales', 'PHP ' . number_format($cash_sales, 2)]);
    fputcsv($out, ['Cash Collections', 'PHP ' . number_format($cash_collections, 2)]);
    fputcsv($out, ['Cash Turnover', 'PHP ' . number_format($cash_turnover, 2)]);
    fputcsv($out, ['Ending Cash', 'PHP ' . number_format($ending_cash, 2)]);
    fputcsv($out, []);

    // Fuel Summary
    fputcsv($out, ['FUEL SUMMARY']);
    fputcsv($out, ['Fuel Type', 'Liters Sold', 'Amount']);
    if ($fuel_summary) {
        $total_liters = 0;
        foreach ($fuel_summary as $f) {
            $total_liters += $f['liters'];
            fputcsv($out, [$f['fuel_type'], number_format($f['liters'], 2) . ' L', 'PHP ' . number_format($f['amount'], 2)]);
        }
        fputcsv($out, ['Total', number_format($total_liters, 2) . ' L', 'PHP ' . number_format($fuel_sales_total, 2)]);
    } else {
        fputcsv($out, ['No fuel transactions for this period']);
    }
    fputcsv($out, []);

    // Job Order Summary
    fputcsv($out, ['JOB ORDER SUMMARY']);
    fputcsv($out, ['Status', 'Count']);
    foreach ($jo_status_counts as $st => $cnt) {
        fputcsv($out, [$st, $cnt]);
    }
    fputcsv($out, ['Total', array_sum($jo_status_counts)]);
    fputcsv($out, []);

    // Merchandise Summary
    fputcsv($out, ['MERCHANDISE SUMMARY']);
    fputcsv($out, ['Description', 'Value']);
    fputcsv($out, ['Total Transactions', number_format($merch_tx_count)]);
    fputcsv($out, ['Total Items Sold', number_format($merch_items_sold)]);
    fputcsv($out, ['Total Merchandise Sales', 'PHP ' . number_format($merch_sales_total, 2)]);

    fclose($out);
    exit;
}

// ── Page title ─────────────────────────────────────────────────────────────────
$page_title = 'Shift Turnover Report - ' . $station_name;

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
.pagination-wrapper, .client-side-pagination, .petron-pagination-bar,
.petron-rows-select-wrap, .rows-per-page { display: none !important; }

/* Export Group */
.rpt-export-group { display: flex !important; align-items: center !important; gap: 6px !important; margin-left: auto !important; white-space: nowrap !important; }
.rpt-export-btn { padding: 7px 13px !important; font-size: 11px !important; font-weight: 700 !important; border-radius: 4px !important; cursor: pointer !important; display: inline-flex !important; align-items: center !important; gap: 5px !important; background: #ffffff !important; border: 1px solid !important; transition: all 0.18s !important; text-decoration: none !important; }
.rpt-btn-print  { color: #475569 !important; border-color: transparent !important; background: transparent !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

/* Section Cards */
.str-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.str-card h3 { font-size: 12px; font-weight: 800; color: #002F6C; text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px 0; padding-bottom: 8px; border-bottom: 2px solid #002F6C; }
.str-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.str-table th { padding: 8px 12px; background: #002F6C; color: #fff; font-size: 11px; font-weight: 700; text-align: left; }
.str-table th:last-child { text-align: right; }
.str-table td { padding: 7px 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; }
.str-table td:last-child { text-align: right; font-weight: 600; color: #002F6C; }
.str-table tr:last-child td { border-bottom: none; }
.str-table tr.str-total td { font-weight: 800; background: #e8f0fe; border-top: 2px solid #002F6C; color: #002F6C; }
.str-table td.str-center { text-align: center; }
.str-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.str-info-item { display: flex; flex-direction: column; gap: 2px; }
.str-info-label { font-size: 10px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
.str-info-value { font-size: 13px; font-weight: 700; color: #1e293b; }
.str-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .str-2col { grid-template-columns: 1fr; } }

/* Print CSS */
@media print {
    @page { size: A4 portrait; margin: 10mm 12mm; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }
    body > *:not(.sfss-print-only) { display: none !important; }
    .stock-page .controls, nav, header, footer, aside, .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
    #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-size: 10px !important; color: #333 !important; }
    .sfss-print-only .str-card { border: 1px solid #ccc !important; page-break-inside: avoid !important; padding: 8px 10px !important; margin-bottom: 8px !important; }
    .sfss-print-only .str-card h3 { font-size: 10px !important; margin-bottom: 6px !important; }
    .sfss-print-only .str-table th { font-size: 9px !important; padding: 4px 6px !important; background: #002F6C !important; color: #fff !important; }
    .sfss-print-only .str-table td { font-size: 9px !important; padding: 3px 6px !important; }
    .sfss-print-only .str-2col { grid-template-columns: 1fr 1fr !important; gap: 8px !important; }
    .sfss-print-only .str-signature-wrap { display: flex !important; justify-content: flex-end !important; page-break-inside: avoid !important; margin-top: 10px !important; padding: 0 !important; border: none !important; background: transparent !important; box-shadow: none !important; }
    .sfss-print-only .str-sig-line { border-top: 1.5px solid #002F6C !important; width: 100% !important; margin-bottom: 3px !important; }
    .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
    .sfss-print-only i, .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only [class*="fa-"] { display: none !important; }
}
</style>

<div class="stock-page" style="padding:20px;">

    <!-- CONTROLS BAR -->
    <div class="controls" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,0.03);">

        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;">From</label>
                <input type="date" id="date_start" value="<?= htmlspecialchars($date_start) ?>" max="<?= $today ?>"
                       style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            </div>

            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;">To</label>
                <input type="date" id="date_end" value="<?= htmlspecialchars($date_end) ?>" max="<?= $today ?>"
                       style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
            </div>

            <div style="display:flex;align-items:center;gap:6px;">
                <label style="font-weight:700;color:#002F6C;font-size:12px;text-transform:uppercase;">Shift</label>
                <select id="filter_shift" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;">
                    <option value="">All Shifts</option>
                    <option value="first"  <?= strtolower($filter_shift)==='first'  ? 'selected':'' ?>>Shift 1 (6AM–2PM)</option>
                    <option value="second" <?= strtolower($filter_shift)==='second' ? 'selected':'' ?>>Shift 2 (2PM–10PM)</option>
                    <option value="third"  <?= strtolower($filter_shift)==='third'  ? 'selected':'' ?>>Shift 3 (10PM–6AM)</option>
                </select>
            </div>

            <button type="button" onclick="applyFilters()" style="padding:6px 16px;background:#002F6C;color:#fff;font-weight:700;border:none;border-radius:6px;font-size:13px;cursor:pointer;">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>

        <!-- RIGHT: Print, PDF, Excel, CSV Export Buttons -->
        <div class="rpt-export-group">
            <button type="button" onclick="_strPrint()" class="rpt-export-btn rpt-btn-print" title="Print report">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="exportPDF(this)" class="rpt-export-btn rpt-btn-pdf" title="Export PDF">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
            <a href="?date_start=<?= urlencode($date_start) ?>&date_end=<?= urlencode($date_end) ?>&shift=<?= urlencode($filter_shift) ?>&export=excel" 
               class="rpt-export-btn rpt-btn-excel" title="Export to Excel">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <button type="button" onclick="strExportCSV()" class="rpt-export-btn rpt-btn-csv" title="Export to CSV">
                <i class="fas fa-file-csv"></i> CSV
            </button>
        </div>
    </div>

    <!-- PRINTABLE AREA -->
    <div class="print-area" id="strPrintArea">

        <!-- REPORT HEADER -->
        <div class="str-card" style="text-align:center;padding:18px 24px;">
            <h1 style="font-size:22px;font-weight:900;color:#002F6C;margin:0 0 4px 0;letter-spacing:0.5px;">SHIFT TURNOVER REPORT</h1>
            <div style="font-size:13px;font-weight:700;color:#1e293b;"><?= htmlspecialchars($station_name) ?><?= $station_location ? ' — ' . htmlspecialchars($station_location) : '' ?></div>
            <div style="font-size:12px;color:#475569;margin-top:4px;">
                <strong>Date Period:</strong> <?= date('F d, Y', strtotime($date_start)) ?><?= $date_start !== $date_end ? ' – ' . date('F d, Y', strtotime($date_end)) : '' ?>
            </div>
        </div>

        <!-- SHIFT INFORMATION -->
        <div class="str-card">
            <h3><i class="fas fa-id-card-alt" style="margin-right:6px;"></i>Shift Information</h3>
            <div class="str-info-grid">
                <div class="str-info-item">
                    <span class="str-info-label">Date Period</span>
                    <span class="str-info-value"><?= date('F d, Y', strtotime($date_start)) ?><?= $date_start !== $date_end ? ' – ' . date('F d, Y', strtotime($date_end)) : '' ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Shift</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_shift) ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Staff Name</span>
                    <span class="str-info-value"><?= htmlspecialchars(trim($display_staff) ?: '—') ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Time In</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_time_in) ?></span>
                </div>
                <div class="str-info-item">
                    <span class="str-info-label">Time Out</span>
                    <span class="str-info-value"><?= htmlspecialchars($display_time_out) ?></span>
                </div>
                <?php if (count($shift_sessions) > 1): ?>
                <div class="str-info-item" style="grid-column:1/-1;">
                    <span class="str-info-label">All Staff on Shift</span>
                    <span class="str-info-value" style="font-size:12px;">
                        <?= htmlspecialchars(implode(', ', array_filter(array_map(fn($s) => trim($s['staff_name']), $shift_sessions)))) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TWO COLUMNS: Sales Summary + Payment Collection -->
        <div class="str-2col">

            <!-- SALES SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-chart-bar" style="margin-right:6px;"></i>Sales Summary</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Category</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Fuel Sales</td><td>₱<?= number_format($fuel_sales_total, 2) ?></td></tr>
                        <tr><td>Merchandise Sales</td><td>₱<?= number_format($merch_sales_total, 2) ?></td></tr>
                        <tr><td>Labor Fee Revenue</td><td>₱<?= number_format($labor_fee_revenue, 2) ?></td></tr>
                        <tr><td>Service Fee Revenue</td><td>₱<?= number_format($service_fee_revenue, 2) ?></td></tr>
                        <tr><td>Parts Sales</td><td>₱<?= number_format($parts_sales, 2) ?></td></tr>
                        <tr class="str-total"><td>Overall Sales</td><td>₱<?= number_format($overall_sales, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- PAYMENT COLLECTION SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-money-bill-wave" style="margin-right:6px;"></i>Payment Collection Summary</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Payment Method</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_payment_methods as $pm => $amt): ?>
                        <tr><td><?= htmlspecialchars($pm) ?></td><td>₱<?= number_format($amt, 2) ?></td></tr>
                        <?php endforeach; ?>
                        <tr class="str-total"><td>Total Collections</td><td>₱<?= number_format(array_sum($all_payment_methods), 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TWO COLUMNS: Accounts Receivable + Cash Turnover -->
        <div class="str-2col">

            <!-- ACCOUNTS RECEIVABLE TURNOVER -->
            <div class="str-card">
                <h3><i class="fas fa-file-invoice-dollar" style="margin-right:6px;"></i>Accounts Receivable Turnover</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Description</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Credit Account Sales</td><td>₱<?= number_format($credit_sales, 2) ?></td></tr>
                        <tr><td>Fleet Card Sales</td><td>₱<?= number_format($fleet_sales, 2) ?></td></tr>
                        <tr><td>Outstanding Receivables</td><td>₱<?= number_format($outstanding_receivables, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- CASH TURNOVER -->
            <div class="str-card">
                <h3><i class="fas fa-cash-register" style="margin-right:6px;"></i>Cash Turnover</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Description</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Beginning Cash</td><td>₱<?= number_format($beginning_cash, 2) ?></td></tr>
                        <tr><td>Cash Sales</td><td>₱<?= number_format($cash_sales, 2) ?></td></tr>
                        <tr><td>Cash Collections</td><td>₱<?= number_format($cash_collections, 2) ?></td></tr>
                        <tr class="str-total"><td>Cash Turnover</td><td>₱<?= number_format($cash_turnover, 2) ?></td></tr>
                        <tr><td>Ending Cash</td><td>₱<?= number_format($ending_cash, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- FUEL SUMMARY -->
        <div class="str-card">
            <h3><i class="fas fa-gas-pump" style="margin-right:6px;"></i>Fuel Summary</h3>
            <table class="str-table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th style="text-align:right;">Liters Sold</th>
                        <th style="text-align:right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($fuel_summary): ?>
                        <?php $total_liters = 0; foreach ($fuel_summary as $f): $total_liters += $f['liters']; ?>
                        <tr>
                            <td><?= htmlspecialchars($f['fuel_type']) ?></td>
                            <td style="text-align:right;font-weight:600;color:#15803d;"><?= number_format($f['liters'], 2) ?> L</td>
                            <td>₱<?= number_format($f['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="str-total">
                            <td>Total</td>
                            <td style="text-align:right;"><?= number_format($total_liters, 2) ?> L</td>
                            <td>₱<?= number_format($fuel_sales_total, 2) ?></td>
                        </tr>
                    <?php else: ?>
                        <tr><td colspan="3" style="text-align:center;color:#6b7280;font-style:italic;padding:20px;">No fuel transactions for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- TWO COLUMNS: Job Orders + Merchandise Summary -->
        <div class="str-2col">

            <!-- JOB ORDER SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-tools" style="margin-right:6px;"></i>Job Order Summary</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Status</th><th style="text-align:center;">Count</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jo_status_counts as $st => $cnt): ?>
                        <tr>
                            <td><?= htmlspecialchars($st) ?></td>
                            <td class="str-center"><?= $cnt ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="str-total">
                            <td>Total</td>
                            <td class="str-center"><?= array_sum($jo_status_counts) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- MERCHANDISE SUMMARY -->
            <div class="str-card">
                <h3><i class="fas fa-shopping-cart" style="margin-right:6px;"></i>Merchandise Summary</h3>
                <table class="str-table">
                    <thead>
                        <tr><th>Description</th><th>Value</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Total Transactions</td><td><?= number_format($merch_tx_count) ?></td></tr>
                        <tr><td>Total Items Sold</td><td><?= number_format($merch_items_sold) ?></td></tr>
                        <tr class="str-total"><td>Total Merchandise Sales</td><td>₱<?= number_format($merch_sales_total, 2) ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- REPORT SIGNATURE: PREPARED BY ONLY (RIGHT-ALIGNED, SINGLE LINE) -->
        <?php 
            $clean_staff_display = trim($display_staff ?? '');
            if ($clean_staff_display === '—' || $clean_staff_display === '-' || $clean_staff_display === 'N/A') {
                $clean_staff_display = '';
            }
        ?>
        <div class="str-signature-wrap" style="display:flex; justify-content:flex-end; margin-top:20px; padding:0 4px;">
            <div style="display:inline-flex; flex-direction:column; align-items:center; text-align:center; width:fit-content; max-width:100%;">
                <div style="font-size:11px; font-weight:800; color:#002F6C; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:28px; align-self:flex-start;">
                    Prepared By:
                </div>
                <div class="str-sig-line" style="border-top:1.5px solid #002F6C; width:100%; margin-bottom:4px;"></div>
                <?php if ($clean_staff_display !== ''): ?>
                <div style="font-size:12px; font-weight:800; color:#1e293b; text-transform:uppercase; white-space:nowrap;">
                    <?= htmlspecialchars($clean_staff_display) ?>
                </div>
                <?php endif; ?>
                <div style="font-size:10px; color:#64748b; font-weight:600; margin-top:2px; white-space:nowrap;">
                    Signature over Printed Name
                </div>
            </div>
        </div>

    </div><!-- end print-area -->

</div>

<script>
function applyFilters() {
    const ds = document.getElementById('date_start').value;
    const de = document.getElementById('date_end').value;
    const sh = document.getElementById('filter_shift').value;
    if (!ds || !de) { alert('Please select both From and To dates.'); return; }
    if (de < ds) { alert('To Date cannot be earlier than From Date.'); return; }
    const url = new URL(window.location.href);
    url.searchParams.set('date_start', ds);
    url.searchParams.set('date_end', de);
    if (sh) url.searchParams.set('shift', sh); else url.searchParams.delete('shift');
    window.location.href = url.toString();
}

function strExportCSV() {
    const ds = document.getElementById('date_start').value;
    const de = document.getElementById('date_end').value;
    const sh = document.getElementById('filter_shift').value;
    let url  = window.location.pathname + '?export=csv&date_start=' + encodeURIComponent(ds) + '&date_end=' + encodeURIComponent(de);
    if (sh) url += '&shift=' + encodeURIComponent(sh);
    window.location.href = url;
}

function exportPDF(btn) {
    var origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
    btn.disabled  = true;
    _strPrint(function() {
        btn.innerHTML = origHTML;
        btn.disabled  = false;
    });
}

function _strPrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.getElementById('strPrintArea');
    if (!area) { window.print(); return; }

    var origTitle  = document.title;
    document.title = 'Shift Turnover Report';

    var printDiv           = document.createElement('div');
    printDiv.className     = 'sfss-print-only';
    printDiv.innerHTML     = area.innerHTML;
    printDiv.style.display = 'block';
    document.body.appendChild(printDiv);

    var scrollBtn = document.getElementById('toggleScrollBtn');
    if (scrollBtn) scrollBtn.style.setProperty('display', 'none', 'important');

    setTimeout(function() {
        window.print();
        var cleanup = function() {
            var p = document.querySelector('.sfss-print-only');
            if (p) p.remove();
            document.title = origTitle;
            if (scrollBtn) scrollBtn.style.setProperty('display', 'flex', 'important');
            window.removeEventListener('afterprint', cleanup);
            if (typeof afterPrint === 'function') afterPrint();
        };
        window.addEventListener('afterprint', cleanup);
        setTimeout(cleanup, 30000);
    }, 150);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
