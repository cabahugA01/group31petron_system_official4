<?php
/**
 * STAFF CUSTOMER REPORT
 * Customer counts and transaction summaries for staff reports.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

$page_id = 'report_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/staff_customer_report_data.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

if (!in_array($role, ['superadmin', 'developer']) && function_exists('is_module_enabled') && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) die('Error: You are not assigned to a station.');

$station_name = 'Petron Station Management System';
$station_location = '';
try {
    $stmt = $pdo->prepare("SELECT name, location FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($station) {
        $station_name = $station['name'] ?: $station_name;
        $station_location = $station['location'] ?? '';
    }
} catch (Exception $e) {}

$report = staff_customer_report_build($pdo, (int)$station_id, $_GET);
$filters = $report['filters'];
$rows = $report['rows'];
$summary = $report['summary'];
$generated_by = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
if ($generated_by === '') $generated_by = $me['username'] ?? 'Staff';

function staff_customer_report_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function staff_customer_report_date($date, string $format = 'M d'): string {
    $timestamp = strtotime((string)$date);
    return $timestamp ? date($format, $timestamp) : 'N/A';
}

function staff_customer_report_query(array $filters, array $extra = []): string {
    return http_build_query(array_merge([
        'date_start' => $filters['date_start'],
        'date_end' => $filters['date_end'],
        'customer_type' => $filters['customer_type'],
        'transaction_type' => $filters['transaction_type'],
        'staff_id' => $filters['staff_id'] ?: 'all',
    ], $extra));
}

$excel_url = 'staff_customer_export.php?' . staff_customer_report_query($filters, ['format' => 'excel']);
$pdf_url = 'staff_customers_report.php?' . staff_customer_report_query($filters, ['print' => '1']);

$page_title = 'Customer Report';
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    body { margin: 0; padding: 0; background: white; }
    .main-content { width: 100%; max-width: 100%; padding: 0; margin: 0; }
    .container { max-width: 100%; margin: 0; padding: 0; background: white; }
    .controls {
        padding: 12px 20px;
        background: #fff;
        border-bottom: 1px solid #000;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 10px;
    }
    .filter-controls { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; font-size: 12px; }
    .filter-group { display: flex; flex-direction: column; gap: 4px; min-width: 130px; }
    .filter-group label { font-weight: 700; color: #000; }
    .filter-group input,
    .filter-group select {
        padding: 6px 10px;
        border: 1px solid #000;
        background: #fff;
        color: #000;
        font-size: 12px;
        min-height: 32px;
    }
    .action-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .btn {
        padding: 7px 12px;
        border: 1px solid #000;
        background: #fff;
        cursor: pointer;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        color: #000;
        min-height: 32px;
    }
    .btn:hover { background: #f5f5f5; }
    .btn-primary { background: #000; color: #fff; }
    .btn-primary:hover { background: #333; }
    
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
    
    .print-area { background: #fff; }
    .header {
        background: #fff;
        color: #000;
        padding: 16px 20px;
        text-align: center;
        border-bottom: 2px solid #000;
        margin-bottom: 0;
    }
    .header h1 { font-size: 24px; margin: 0 0 8px 0; font-weight: 800; color: #000; }
    .header p { font-size: 12px; color: #000; margin: 3px 0; }
    .content { padding: 16px 20px 24px; }
    .section-title {
        font-size: 16px;
        font-weight: 800;
        margin: 20px 0 10px;
        color: #000;
        padding-bottom: 8px;
        border-bottom: 2px solid #000;
        text-transform: uppercase;
    }
    .summary-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 10px;
        margin-bottom: 14px;
    }
    .summary-card { border: 1px solid #000; padding: 12px; background: #fff; min-height: 72px; }
    .summary-card .label { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #000; }
    .summary-card .value { font-size: 24px; font-weight: 800; margin-top: 8px; color: #000; }
    .table-container { overflow-x: auto; margin-bottom: 18px; width: 100%; }
    table { width: 100%; border-collapse: collapse; background: white; border: 1px solid #000; font-size: 10px; table-layout: fixed; }
    thead { background: #fff; color: #000; }
    th { padding: 7px 5px; text-align: left; font-weight: 800; font-size: 9px; text-transform: uppercase; border: 1px solid #000; white-space: nowrap; }
    td { padding: 6px 5px; border: 1px solid #000; font-size: 10px; vertical-align: top; }
    tbody tr { background: #fff; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
    .font-bold { font-weight: 800; }
    .two-col { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .three-col { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
    .summary-box { border: 1px solid #000; padding: 12px; background: #fff; }
    .summary-box h3 { font-size: 13px; text-transform: uppercase; margin: 0 0 8px; color: #000; border-bottom: 1px solid #000; padding-bottom: 6px; }
    .summary-box .count { font-size: 24px; font-weight: 800; text-align: center; padding: 8px 0; }
    .print-summary-table { display: none; }
    .footer-table { max-width: 520px; margin: 0 auto; }

    @media print {
        @page { size: legal portrait; margin: 0.5in 0.4in; }
        
        body * { visibility: hidden !important; }
        .print-area, .print-area * { visibility: visible !important; }
        .print-area {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
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
        
        /* Hide sidebar, navigation, hamburger menu, ALL UI controls */
        .controls, .sidebar, .header-nav, .top-nav, nav, .menu-toggle, .hamburger, 
        #sidebar, #header, #menu-toggle, .nav, .navbar, .menu-btn,
        .toggle-btn, .sidebar-toggle, [class*="toggle"], [class*="menu-btn"],
        .btn, button, .action-controls, .filter-controls {
            display: none !important;
            visibility: hidden !important;
        }
        
        /* ── Kill ALL icons everywhere ── */
        i, svg, .fas, .far, .fab, .fa, [class*="fa-"], .fa-solid, .fa-regular, .fa-brands,
        .icon, [class*="icon-"] {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            font-size: 0 !important;
            line-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            visibility: hidden !important;
        }
        
        /* Re-show print area but keep icons hidden */
        .print-area i, .print-area svg, .print-area .fas, .print-area .far,
        .print-area .fab, .print-area .fa, .print-area [class*="fa-"],
        .print-area .icon, .print-area [class*="icon-"] {
            display: none !important;
            visibility: hidden !important;
        }
        
        .container, .content { 
            margin: 0 !important; 
            padding: 0 !important; 
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
        }
        
        .header { 
            text-align: center !important; 
            border-bottom: 2px solid #000 !important; 
            padding: 6px 0 !important; 
            margin: 0 0 8px 0 !important; 
        }
        .header h1 { 
            font-size: 16px !important; 
            font-weight: 800 !important; 
            color: #000 !important; 
            margin: 0 0 3px 0 !important; 
            padding: 0 !important;
        }
        .header p { 
            font-size: 10px !important; 
            color: #000 !important; 
            margin: 2px 0 !important; 
            padding: 0 !important;
        }
        .section-title { font-size: 12px !important; font-weight: 800 !important; margin: 8px 0 4px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .summary-cards { display: none !important; }
        .print-summary-table { display: table !important; }
        .table-container { 
            overflow: hidden !important; 
            overflow-x: hidden !important; 
            width: 100% !important; 
            max-width: 100% !important; 
            text-align: center !important; 
            margin-bottom: 8px !important; 
        }
        table { 
            width: 100% !important; 
            max-width: 100% !important; 
            border-collapse: collapse !important; 
            font-size: 8px !important; 
            table-layout: fixed !important; 
            margin: 0 auto 8px !important; 
        }
        thead { display: table-header-group !important; }
        tbody { display: table-row-group !important; }
        tr { page-break-inside: avoid !important; }
        th { 
            font-size: 8px !important; 
            padding: 4px 3px !important; 
            border: 1px solid #000 !important; 
            background: #fff !important; 
            color: #000 !important; 
            font-weight: 800 !important; 
            text-align: center !important; 
            white-space: nowrap !important; 
            overflow: hidden !important;
            text-overflow: ellipsis !important;
        }
        td { 
            font-size: 7px !important; 
            padding: 3px 2px !important; 
            border: 1px solid #000 !important; 
            white-space: nowrap !important; 
            vertical-align: top !important; 
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            word-wrap: break-word !important;
        }
        .two-col, .three-col { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .summary-box { border: 1px solid #000 !important; padding: 5px !important; }
        .summary-box h3 { font-size: 9px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px !important; }
        .summary-box .count { font-size: 16px !important; padding: 3px 0 !important; }
    }
</style>

<div class="controls">
    <form method="GET" class="filter-controls" id="customerReportFilters">
        <div class="filter-group">
            <label for="date_start">From</label>
            <input type="date" id="date_start" name="date_start" value="<?= staff_customer_report_h($filters['date_start']) ?>" required>
        </div>
        <div class="filter-group">
            <label for="date_end">To</label>
            <input type="date" id="date_end" name="date_end" value="<?= staff_customer_report_h($filters['date_end']) ?>" required>
        </div>
        <div class="filter-group">
            <label for="customer_type">Customer Type</label>
            <select id="customer_type" name="customer_type">
                <option value="all" <?= $filters['customer_type'] === 'all' ? 'selected' : '' ?>>All Customers</option>
                <option value="walkin" <?= $filters['customer_type'] === 'walkin' ? 'selected' : '' ?>>Walk-in</option>
                <option value="registered" <?= $filters['customer_type'] === 'registered' ? 'selected' : '' ?>>Registered</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="transaction_type">Transaction Type</label>
            <select id="transaction_type" name="transaction_type">
                <option value="all" <?= $filters['transaction_type'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="merchandise" <?= $filters['transaction_type'] === 'merchandise' ? 'selected' : '' ?>>Merchandise</option>
                <option value="job_order" <?= $filters['transaction_type'] === 'job_order' ? 'selected' : '' ?>>Job Order</option>
                <option value="fuel" <?= $filters['transaction_type'] === 'fuel' ? 'selected' : '' ?>>Fuel</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="staff_id">Staff</label>
            <select id="staff_id" name="staff_id">
                <option value="all" <?= !$filters['staff_id'] ? 'selected' : '' ?>>All Staff</option>
                <?php foreach ($report['staff_options'] as $staff): ?>
                    <option value="<?= (int)$staff['id'] ?>" <?= $filters['staff_id'] === (int)$staff['id'] ? 'selected' : '' ?>>
                        <?= staff_customer_report_h($staff['staff_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Apply</button>
    </form>
    <div class="action-controls">
        <!-- Excel -->
        <a href="<?= staff_customer_report_h($excel_url) ?>" 
           class="flt-btn flt-btn-excel" title="Export to Excel">
            <i class="fas fa-file-excel"></i> Excel
        </a>
        <!-- CSV -->
        <button onclick="exportTableToCSV('customersTable','customers_report_<?= date('Ymd') ?>.csv')"
                class="flt-btn flt-btn-csv" title="Export to CSV">
            <i class="fas fa-file-csv"></i> CSV
        </button>
        <!-- PDF -->
        <button type="button" onclick="window.print()" class="flt-btn flt-btn-pdf" title="Print / Export PDF">
            <i class="fas fa-file-pdf"></i> PDF
        </button>
    </div>
</div>

<div class="print-area">
    <div class="container">
        <div class="header">
            <h1>CUSTOMER REPORT</h1>
            <p>Petron Station Management System</p>
            <p><?= staff_customer_report_h($station_location ?: $station_name) ?></p>
            <p><strong>Period:</strong> <?= date('F d, Y', strtotime($filters['date_start'])) ?> - <?= date('F d, Y', strtotime($filters['date_end'])) ?></p>
        </div>

        <div class="content">
            <div class="section-title">Customer Summary</div>
            <div class="summary-cards">
                <div class="summary-card"><div class="label">Total Customers Served</div><div class="value"><?= number_format($summary['total_served']) ?></div></div>
                <div class="summary-card"><div class="label">Walk-in Customers</div><div class="value"><?= number_format($summary['walkin']) ?></div></div>
                <div class="summary-card"><div class="label">Registered Customers</div><div class="value"><?= number_format($summary['registered']) ?></div></div>
                <div class="summary-card"><div class="label">New Registered Customers</div><div class="value"><?= number_format($summary['new_registered']) ?></div></div>
                <div class="summary-card"><div class="label">Returning Customers</div><div class="value"><?= number_format($summary['returning']) ?></div></div>
            </div>
            <table class="print-summary-table">
                <tbody>
                    <tr><td class="font-bold">Total Customers Served</td><td class="text-right font-bold"><?= number_format($summary['total_served']) ?></td></tr>
                    <tr><td class="font-bold">Walk-in Customers</td><td class="text-right font-bold"><?= number_format($summary['walkin']) ?></td></tr>
                    <tr><td class="font-bold">Registered Customers</td><td class="text-right font-bold"><?= number_format($summary['registered']) ?></td></tr>
                    <tr><td class="font-bold">New Registered Customers</td><td class="text-right font-bold"><?= number_format($summary['new_registered']) ?></td></tr>
                    <tr><td class="font-bold">Returning Customers</td><td class="text-right font-bold"><?= number_format($summary['returning']) ?></td></tr>
                </tbody>
            </table>

            <div class="section-title">Customer Transaction Report</div>
            <div class="table-container">
                <table id="customersTable">
                    <thead>
                        <tr>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Type</th>
                            <th>Vehicle</th>
                            <th>Transaction Type</th>
                            <th>Total Amount</th>
                            <th>Date</th>
                            <th>Staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                            <tr>
                                <td class="font-bold"><?= staff_customer_report_h($row['customer_id_display']) ?></td>
                                <td><?= staff_customer_report_h($row['customer_name']) ?></td>
                                <td><?= staff_customer_report_h($row['customer_type']) ?></td>
                                <td><?= staff_customer_report_h($row['vehicle']) ?></td>
                                <td><?= staff_customer_report_h($row['transaction_type']) ?></td>
                                <td class="text-right font-bold">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                                <td><?= staff_customer_report_h(staff_customer_report_date($row['transaction_date'])) ?></td>
                                <td><?= staff_customer_report_h($row['staff_name']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center" style="padding: 30px;">No customer transactions found for the selected filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title">Customer Type Summary</div>
            <div class="two-col">
                <div class="summary-box"><h3>Walk-in Customers</h3><div class="count"><?= number_format($report['customer_type_summary']['Walk-in']) ?></div></div>
                <div class="summary-box"><h3>Registered Customers</h3><div class="count"><?= number_format($report['customer_type_summary']['Registered']) ?></div></div>
            </div>

            <div class="section-title">Transaction Type Summary</div>
            <div class="three-col">
                <div class="summary-box"><h3>Merchandise Customers</h3><div class="count"><?= number_format($report['transaction_type_summary']['Merchandise']) ?></div></div>
                <div class="summary-box"><h3>Job Order Customers</h3><div class="count"><?= number_format($report['transaction_type_summary']['Job Order']) ?></div></div>
                <div class="summary-box"><h3>Fuel Customers</h3><div class="count"><?= number_format($report['transaction_type_summary']['Fuel']) ?></div></div>
            </div>

            <div class="section-title">Staff Customer Summary</div>
            <div class="table-container">
                <table>
                    <thead><tr><th>Staff</th><th>Customers Served</th></tr></thead>
                    <tbody>
                        <?php if (count($report['staff_summary']) > 0): foreach ($report['staff_summary'] as $staff): ?>
                            <tr>
                                <td class="font-bold"><?= staff_customer_report_h($staff['staff']) ?></td>
                                <td class="text-right font-bold"><?= number_format($staff['customers_served']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="2" class="text-center" style="padding: 20px;">No staff customer data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title">Daily Customer Summary</div>
            <div class="table-container">
                <table>
                    <thead><tr><th>Date</th><th>Walk-in</th><th>Registered</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php if (count($report['daily_summary']) > 0): foreach ($report['daily_summary'] as $day): ?>
                            <tr>
                                <td class="font-bold"><?= staff_customer_report_h(staff_customer_report_date($day['date'])) ?></td>
                                <td class="text-right"><?= number_format($day['walkin']) ?></td>
                                <td class="text-right"><?= number_format($day['registered']) ?></td>
                                <td class="text-right font-bold"><?= number_format($day['total']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center" style="padding: 20px;">No daily customer data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title">Repeat Customers</div>
            <div class="table-container">
                <table>
                    <thead><tr><th>Customer</th><th>Visits</th><th>Last Visit</th></tr></thead>
                    <tbody>
                        <?php if (count($report['repeat_customers']) > 0): foreach ($report['repeat_customers'] as $repeat): ?>
                            <tr>
                                <td class="font-bold"><?= staff_customer_report_h($repeat['customer']) ?></td>
                                <td class="text-right"><?= number_format($repeat['visits']) ?></td>
                                <td><?= staff_customer_report_h(staff_customer_report_date($repeat['last_visit'])) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-center" style="padding: 20px;">No repeat customers found for the selected period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (($_GET['print'] ?? '') === '1'): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 350);
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
