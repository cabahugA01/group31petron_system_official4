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
    html, body {
        max-width: 100vw !important;
        overflow-x: hidden !important;
    }
    .pagination-wrapper,
    .client-side-pagination,
    .petron-pagination-bar,
    .petron-rows-select-wrap,
    .rows-per-page {
        display: none !important;
    }
    .main-content {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    .stock-page {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        padding: 16px !important;
        overflow-x: hidden !important;
    }
    .scr-card-container {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 20px !important;
        box-shadow: 0 2px 6px rgba(0,0,0,0.03) !important;
        overflow-x: hidden !important;
    }
    .controls {
        background: #ffffff !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 12px !important;
        padding: 14px 18px !important;
        margin-bottom: 16px !important;
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        gap: 12px !important;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03) !important;
        box-sizing: border-box !important;
        width: 100% !important;
        max-width: 100% !important;
    }
    .filter-controls {
        display: flex !important;
        gap: 10px !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        flex: 1 1 auto !important;
    }
    .filter-group {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 6px !important;
    }
    .filter-group label {
        font-weight: 800 !important;
        color: #002F6C !important;
        font-size: 13px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        white-space: nowrap !important;
    }
    .filter-group input,
    .filter-group select {
        height: 38px !important;
        padding: 6px 10px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 7px !important;
        font-size: 13.5px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        background: #ffffff !important;
        outline: none !important;
        box-sizing: border-box !important;
    }
    .filter-group input:focus,
    .filter-group select:focus {
        border-color: #002F6C !important;
        box-shadow: 0 0 0 3px rgba(0,47,108,0.12) !important;
    }
    .btn-apply-cust {
        height: 38px !important;
        padding: 0 18px !important;
        background: #002F6C !important;
        color: #ffffff !important;
        font-weight: 800 !important;
        border: none !important;
        border-radius: 7px !important;
        font-size: 13.5px !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        transition: background 0.15s !important;
    }
    .btn-apply-cust:hover {
        background: #001f4d !important;
    }
    .action-controls {
        display: flex !important;
        gap: 8px !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        margin-left: auto !important;
    }
    
    /* Export Buttons */
    .flt-btn {
        height: 38px !important;
        padding: 0 14px !important;
        font-size: 13px !important;
        font-weight: 800 !important;
        border-radius: 7px !important;
        cursor: pointer !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        background: #ffffff !important;
        border: 1.5px solid !important;
        transition: all 0.18s !important;
        text-decoration: none !important;
        box-sizing: border-box !important;
        white-space: nowrap !important;
    }
    .flt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
    .flt-btn-excel:hover { background: #16a34a !important; color: #ffffff !important; }
    .flt-btn-csv   { color: #0284c7 !important; border-color: #0284c7 !important; background: #ffffff !important; }
    .flt-btn-csv:hover   { background: #0284c7 !important; color: #ffffff !important; }
    .flt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
    .flt-btn-pdf:hover   { background: #dc2626 !important; color: #ffffff !important; }
    .flt-btn-print { color: #002F6C !important; border-color: #002F6C !important; background: #ffffff !important; }
    .flt-btn-print:hover { background: #002F6C !important; color: #ffffff !important; }
    
    .print-area { background: #fff; width: 100% !important; }
    .header {
        background: #fff;
        color: #000;
        padding: 16px 20px;
        text-align: center;
        border-bottom: 2.5px solid #002F6C;
        margin-bottom: 20px;
    }
    .header h1 { font-size: 24px; margin: 0 0 6px 0; font-weight: 900; color: #002F6C; font-family: 'Segoe UI', sans-serif; }
    .header p { font-size: 13.5px; color: #475569; margin: 3px 0; font-weight: 600; }
    .content { padding: 0 !important; width: 100% !important; }
    .section-title {
        font-size: 16px !important;
        font-weight: 900 !important;
        margin: 24px 0 12px !important;
        color: #002F6C !important;
        padding-bottom: 8px !important;
        border-bottom: 2px solid #002F6C !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
    }
    .summary-cards {
        display: grid !important;
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 12px !important;
        margin-bottom: 18px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    @media (max-width: 900px) {
        .summary-cards { grid-template-columns: repeat(2, 1fr) !important; }
    }
    .summary-card {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        padding: 14px 16px !important;
        background: #ffffff !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
        box-sizing: border-box !important;
    }
    .summary-card .label {
        font-size: 12.5px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        color: #475569 !important;
        letter-spacing: 0.3px !important;
    }
    .summary-card .value {
        font-size: 26px !important;
        font-weight: 900 !important;
        margin-top: 6px !important;
        color: #002F6C !important;
    }
    .table-container {
        overflow: hidden !important;
        margin-bottom: 18px !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        border-radius: 8px !important;
        border: 1.5px solid #cbd5e1 !important;
    }
    table.report-table {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        border-collapse: collapse !important;
        background: #ffffff !important;
        margin: 0 !important;
        table-layout: fixed !important;
        box-sizing: border-box !important;
    }
    table.report-table th,
    table.report-table td {
        white-space: normal !important;
        word-break: break-word !important;
        overflow-wrap: break-word !important;
        vertical-align: middle !important;
        box-sizing: border-box !important;
    }
    table.report-table th {
        background: #002F6C !important;
        color: #ffffff !important;
        font-size: 13.5px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        padding: 12px 8px !important;
        border: 1px solid #001f4d !important;
    }
    table.report-table td {
        padding: 10px 8px !important;
        border: 1px solid #e2e8f0 !important;
        color: #0f172a !important;
        font-size: 14px !important;
        background: #ffffff;
    }
    table.report-table tr:hover td {
        background: #f8fafc !important;
    }
    .text-right { text-align: right !important; }
    .text-center { text-align: center !important; }
    .font-bold { font-weight: 800 !important; }
    
    .two-col {
        display: grid !important;
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 14px !important;
        margin-bottom: 18px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .three-col {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 14px !important;
        margin-bottom: 18px !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .summary-box {
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        padding: 16px !important;
        background: #ffffff !important;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04) !important;
        box-sizing: border-box !important;
    }
    .summary-box h3 {
        font-size: 14px !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        margin: 0 0 10px !important;
        color: #002F6C !important;
        border-bottom: 1.5px solid #e2e8f0 !important;
        padding-bottom: 8px !important;
        letter-spacing: 0.3px !important;
    }
    .summary-box .count {
        font-size: 28px !important;
        font-weight: 900 !important;
        text-align: center !important;
        padding: 6px 0 !important;
        color: #002F6C !important;
    }
    .print-summary-table { display: none; }
    .print-only-signature { display: none !important; }
    .footer-table { max-width: 520px; margin: 0 auto; }

    @media print {
        @page { size: legal portrait; margin: 10mm 12mm; }
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; text-shadow: none !important; background-image: none !important; }
        html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; overflow: visible !important; height: auto !important; font-size: 10px !important; }

        /* Hide all page chrome — keep only sfss-print-only */
        body > *:not(.sfss-print-only) { display: none !important; }
        .controls, .action-controls, nav, header, footer, aside,
        .sidebar, .main-sidebar, .main-header, .navbar, .topbar,
        #toggleScrollBtn, .toggle-scroll-btn, .toast, .toast-container { display: none !important; }

        /* Print container */
        .sfss-print-only {
            display: block !important; position: static !important;
            width: 100% !important; max-width: 100% !important;
            margin: 0 !important; padding: 0 !important;
            background: #fff !important; font-size: 10px !important; color: #333 !important;
        }
        .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after { box-shadow: none !important; text-shadow: none !important; }

        /* Hide icons inside print container */
        .sfss-print-only i, .sfss-print-only svg,
        .sfss-print-only .fas, .sfss-print-only .far, .sfss-print-only .fab, .sfss-print-only .fa,
        .sfss-print-only [class*="fa-"], .sfss-print-only .icon, .sfss-print-only [class*="icon-"] {
            display: none !important; width: 0 !important; height: 0 !important;
            font-size: 0 !important; margin: 0 !important; padding: 0 !important;
        }

        .sfss-print-only .header { text-align: center !important; border-bottom: 2px solid #000 !important; padding: 6px 0 !important; margin: 0 0 8px 0 !important; }
        .sfss-print-only .header h1 { font-size: 16px !important; font-weight: 800 !important; color: #000 !important; margin: 0 0 3px 0 !important; }
        .sfss-print-only .header p { font-size: 10px !important; color: #000 !important; margin: 2px 0 !important; }
        .sfss-print-only .section-title { font-size: 12px !important; font-weight: 800 !important; margin: 8px 0 4px !important; border-bottom: 2px solid #000 !important; page-break-after: avoid !important; }
        .sfss-print-only .summary-cards { display: none !important; }
        .sfss-print-only .print-summary-table { display: table !important; }
        .sfss-print-only .table-container { overflow: visible !important; width: 100% !important; margin-bottom: 8px !important; }
        .sfss-print-only table { width: 100% !important; border-collapse: collapse !important; font-size: 8px !important; margin: 0 0 8px 0 !important; }
        .sfss-print-only thead { display: table-header-group !important; }
        .sfss-print-only tbody { display: table-row-group !important; }
        .sfss-print-only tr { page-break-inside: avoid !important; }
        .sfss-print-only th { font-size: 8px !important; padding: 4px 3px !important; border: 1px solid #000 !important; background: #00264D !important; color: #fff !important; font-weight: 800 !important; text-align: center !important; }
        .sfss-print-only td { font-size: 7px !important; padding: 3px 2px !important; border: 1px solid #ddd !important; vertical-align: top !important; }
        .sfss-print-only .two-col, .sfss-print-only .three-col { display: grid !important; grid-template-columns: repeat(2, 1fr) !important; gap: 6px !important; margin: 6px 0 !important; page-break-inside: avoid !important; }
        .sfss-print-only .summary-box { border: 1px solid #000 !important; padding: 5px !important; }
        .sfss-print-only .summary-box h3 { font-size: 9px !important; border-bottom: 1px solid #000 !important; padding-bottom: 2px !important; margin: 0 0 4px !important; }
        .sfss-print-only, .sfss-print-only * { min-height: 0 !important; height: auto !important; }
        .sfss-print-only .container, .sfss-print-only .scr-card-container, .sfss-print-only .content { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
        .print-only-signature, .sfss-print-only .print-only-signature { display: table !important; width: 100% !important; }
    }
</style>

<div class="stock-page">
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
        <button type="submit" class="btn-apply-cust">
            <i class="fas fa-filter"></i> Apply
        </button>
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
        <button type="button" onclick="exportPrintableAreaToPDF('.print-area', 'CUSTOMER REPORT', 'staff_customers_report', this)" class="flt-btn flt-btn-pdf" title="Export PDF">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <!-- Print -->
        <button type="button" onclick="_sfssDoNativePrint()" class="flt-btn flt-btn-print" title="Print report">
            <i class="fas fa-print"></i> Print
        </button>
    </div>
</div>

<div class="print-area">
    <div class="scr-card-container">
        <div class="header" style="text-align:center;">
            <h1 style="color:#002F6C; font-weight:900;">CUSTOMER REPORT</h1>
            <p class="rpt-address" style="color:#475569; font-size:14px; font-weight:700;"><?= staff_customer_report_h($station_location ?: $station_name) ?></p>
            <p class="rpt-date-range" style="color:#475569; font-size:13.5px; font-weight:600;"><strong>Period:</strong> <?= date('F d, Y', strtotime($filters['date_start'])) ?> – <?= date('F d, Y', strtotime($filters['date_end'])) ?></p>
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
            <table class="print-summary-table report-table no-min-width print-table">
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
                <table id="customersTable" class="report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 12%;">
                        <col style="width: 18%;">
                        <col style="width: 10%;">
                        <col style="width: 13%;">
                        <col style="width: 14%;">
                        <col style="width: 12%;">
                        <col style="width: 10%;">
                        <col style="width: 11%;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Type</th>
                            <th>Vehicle</th>
                            <th>Transaction Type</th>
                            <th style="text-align:right;">Total Amount</th>
                            <th>Date</th>
                            <th>Staff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                            <tr>
                                <td class="font-bold" style="color:#002F6C;"><?= staff_customer_report_h($row['customer_id_display']) ?></td>
                                <td class="font-bold" style="color:#1e293b;"><?= staff_customer_report_h($row['customer_name']) ?></td>
                                <td><?= staff_customer_report_h($row['customer_type']) ?></td>
                                <td><?= staff_customer_report_h($row['vehicle']) ?></td>
                                <td><?= staff_customer_report_h($row['transaction_type']) ?></td>
                                <td class="text-right font-bold" style="color:#002F6C; font-size:14.5px;">₱<?= number_format((float)$row['total_amount'], 2) ?></td>
                                <td><?= staff_customer_report_h(staff_customer_report_date($row['transaction_date'])) ?></td>
                                <td><?= staff_customer_report_h($row['staff_name']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="8" class="text-center" style="padding: 30px; font-weight:700; color:#64748b; font-size:15px; font-style:italic;">No customer transactions found for the selected filters.</td></tr>
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
                <table class="report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 65%;">
                        <col style="width: 35%;">
                    </colgroup>
                    <thead><tr><th>Staff</th><th style="text-align:right;">Customers Served</th></tr></thead>
                    <tbody>
                        <?php if (count($report['staff_summary']) > 0): foreach ($report['staff_summary'] as $staff): ?>
                            <tr>
                                <td class="font-bold" style="color:#002F6C;"><?= staff_customer_report_h($staff['staff']) ?></td>
                                <td class="text-right font-bold" style="font-size:14.5px;"><?= number_format($staff['customers_served']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="2" class="text-center" style="padding: 20px; font-weight:700; color:#64748b; font-size:14px;">No staff customer data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title">Daily Customer Summary</div>
            <div class="table-container">
                <table class="report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 28%;">
                        <col style="width: 24%;">
                        <col style="width: 24%;">
                        <col style="width: 24%;">
                    </colgroup>
                    <thead><tr><th>Date</th><th style="text-align:right;">Walk-in</th><th style="text-align:right;">Registered</th><th style="text-align:right;">Total</th></tr></thead>
                    <tbody>
                        <?php if (count($report['daily_summary']) > 0): foreach ($report['daily_summary'] as $day): ?>
                            <tr>
                                <td class="font-bold" style="color:#002F6C;"><?= staff_customer_report_h(staff_customer_report_date($day['date'])) ?></td>
                                <td class="text-right" style="font-size:14px;"><?= number_format($day['walkin']) ?></td>
                                <td class="text-right" style="font-size:14px;"><?= number_format($day['registered']) ?></td>
                                <td class="text-right font-bold" style="color:#002F6C; font-size:14.5px;"><?= number_format($day['total']) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="4" class="text-center" style="padding: 20px; font-weight:700; color:#64748b; font-size:14px;">No daily customer data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section-title">Repeat Customers</div>
            <div class="table-container">
                <table class="report-table no-min-width print-table">
                    <colgroup>
                        <col style="width: 50%;">
                        <col style="width: 25%;">
                        <col style="width: 25%;">
                    </colgroup>
                    <thead><tr><th>Customer</th><th style="text-align:right;">Visits</th><th>Last Visit</th></tr></thead>
                    <tbody>
                        <?php if (count($report['repeat_customers']) > 0): foreach ($report['repeat_customers'] as $repeat): ?>
                            <tr>
                                <td class="font-bold" style="color:#002F6C;"><?= staff_customer_report_h($repeat['customer']) ?></td>
                                <td class="text-right font-bold" style="font-size:14.5px;"><?= number_format($repeat['visits']) ?></td>
                                <td style="font-size:14px;"><?= staff_customer_report_h(staff_customer_report_date($repeat['last_visit'])) ?></td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-center" style="padding: 20px; font-weight:700; color:#64748b; font-size:14px;">No repeat customers found for the selected period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PREPARED BY SIGNATURE -->
            <table class="print-only-signature report-table no-min-width print-table" style="display:none; width:100%; margin-top:25px; page-break-inside:avoid; border:none; border-collapse:collapse; background:transparent !important;">
                <tr>
                    <td style="border:none; background:transparent !important;"></td>
                    <td style="border:none; width:220px; text-align:center; background:transparent !important;">
                        <div style="font-size:11px; font-weight:800; color:#002F6C; margin-bottom:28px;">PREPARED BY:</div>
                        <div style="border-top:1.5px solid #002F6C; padding-top:4px; font-weight:800; font-size:13px; color:#0f172a;">
                            <?= staff_customer_report_h($generated_by) ?>
                        </div>
                        <div style="font-size:11px; color:#64748b; font-weight:600; margin-top:2px;"><?= staff_customer_report_h(ucfirst($role)) ?></div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
</div>

<?php if (($_GET['print'] ?? '') === '1'): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { _sfssDoNativePrint(); }, 350);
});
</script>
<?php endif; ?>

<script>
function _sfssDoNativePrint(btn, label) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.querySelector('.print-area');
    if (!area) { window.print(); return; }

    var origTitle = document.title;
    document.title = 'Staff Customer Report';

    if (btn && label) {
        var origHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Opening PDF dialog...';
        btn.disabled = true;
    }

    var printDiv = document.createElement('div');
    printDiv.className     = 'sfss-print-only';
    printDiv.innerHTML     = area.innerHTML;
    printDiv.style.display = 'block';
    printDiv.style.visibility = 'visible';
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
            if (btn && label) { btn.innerHTML = origHTML; btn.disabled = false; }
            window.removeEventListener('afterprint', cleanup);
        };
        window.addEventListener('afterprint', cleanup);
        setTimeout(cleanup, 30000);
    }, 150);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
