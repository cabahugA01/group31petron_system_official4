<?php
/**
 * Master Manager Reports System
 * Navigation and layout 100% aligned with Admin Reports.
 * Displays 7 categories including Audit Reports (operations-only: 4 sub-tabs).
 */

$page_id = 'manager_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Check if manager / admin / superadmin role
if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    die('Access denied. Manager privileges required.');
}

// Module gate
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

// Include data fetching & rendering modules
require_once __DIR__ . '/reports/admin_reports_data.php';
require_once __DIR__ . '/reports/admin_reports_render.php';

// Handle AJAX Request for Customer Details Modal
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_customer_details' && isset($_GET['customer_id'])) {
    header('Content-Type: application/json');
    $cid = (int)$_GET['customer_id'];
    $details = getAdminCustomerDetails($pdo, $cid);
    echo json_encode($details);
    exit;
}

// Definitions for the 6 Main Categories & Sub-Tabs (Audit Trail excluded for Manager)
$categories = [
    'sales' => [
        'title' => 'Sales Reports',
        'icon'  => 'fas fa-chart-line',
        'tabs'  => [
            'fuel_sales'          => 'Fuel Sales Report',
            'daily_merch_service' => 'Merchandise & Service Sales Report',
        ]
    ],
    'inventory' => [
        'title' => 'Inventory Reports',
        'icon'  => 'fas fa-boxes',
        'tabs'  => [
            'merch_inventory'     => 'Merchandise Inventory Report',
            'fuel_inventory'      => 'Fuel Inventory Report',
            'inventory_movement'  => 'Inventory Movement Report',
            'inventory_adjustment'=> 'Inventory Adjustment Report',
            'expired_damaged'     => 'Expired & Damaged Report',
        ]
    ],
    'operations' => [
        'title' => 'Operations Reports',
        'icon'  => 'fas fa-cogs',
        'tabs'  => [
            'job_order'            => 'Job Order Report',
            'mechanic_performance' => 'Mechanic Performance Report',
        ]
    ],
    'procurement' => [
        'title' => 'Procurement Reports',
        'icon'  => 'fas fa-truck-loading',
        'tabs'  => [
            'purchase_order'      => 'Purchase Order Report',
            'fuel_reconciliation' => 'Fuel Reconciliation Report',
            'po_vs_received'      => 'PO vs Received Report',
            'stock_in_approval'   => 'Stock-In Approval Report',
        ]
    ],
    'financial' => [
        'title' => 'Financial Reports',
        'icon'  => 'fas fa-file-invoice-dollar',
        'tabs'  => [
            'revenue_summary'     => 'Revenue Summary Report',
            'receivables'         => 'Accounts Receivable Report',
            'payment_collections' => 'Payment Collection Report',
            'sales_vs_collection' => 'Sales vs Collection Report',
        ]
    ],
    'customer' => [
        'title' => 'Customer Reports',
        'icon'  => 'fas fa-users',
        'tabs'  => [
            'customer_report' => 'Customer Report',
        ]
    ],
    'audit' => [
        'title' => 'Audit Reports',
        'icon'  => 'fas fa-history',
        'tabs'  => [
            'transaction_logs'     => 'Transaction Logs',
            'inventory_logs'       => 'Inventory Logs',
            'approval_logs'        => 'Approval Logs',
            'archived_deactivated' => 'Archived & Deactivated Logs',
        ]
    ],
];

// Active Category & Tab Selection
$active_cat = $_GET['cat'] ?? 'sales';
if (!isset($categories[$active_cat])) $active_cat = 'sales';

$valid_tabs = array_keys($categories[$active_cat]['tabs']);
$active_tab = $_GET['tab'] ?? $valid_tabs[0];
if (!in_array($active_tab, $valid_tabs, true)) $active_tab = $valid_tabs[0];

// Report title
$report_title_raw = $categories[$active_cat]['tabs'][$active_tab];
$cat_title        = $categories[$active_cat]['title'];
$cat_icon         = $categories[$active_cat]['icon'];

if (preg_match('/^(.*?)\s*\((24-Hour Summary)\)/i', $report_title_raw, $m)) {
    $main_title = trim($m[1]);
    $sub_title  = '24-HOUR SUMMARY';
} else {
    $main_title = $report_title_raw;
    $sub_title  = '';
}

// Date Filters — default to last 30 days
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// Station Name & Address
$station_name = 'Station';
$station_address = '';
if ($station_id > 0) {
    try {
        $s = $pdo->prepare("SELECT name, address FROM stations WHERE id=? LIMIT 1");
        $s->execute([$station_id]);
        $st = $s->fetch(PDO::FETCH_ASSOC);
        if ($st) {
            $station_name = $st['name'] ?? 'Station';
            $station_address = $st['address'] ?? '';
        }
    } catch (Exception $e) {}
}

// Extra filters
$active_filters = [];
if ($active_tab === 'fuel_sales') {
    if (!empty($_GET['filter_fuel_type'])) $active_filters['fuel_type'] = $_GET['filter_fuel_type'];
    if (!empty($_GET['filter_pump_id']))   $active_filters['pump_id']   = (int)$_GET['filter_pump_id'];
    if (!empty($_GET['filter_shift']))     $active_filters['shift']     = $_GET['filter_shift'];
} elseif ($active_tab === 'daily_merch_service') {
    if (!empty($_GET['filter_pm']))     $active_filters['payment_method']  = $_GET['filter_pm'];
    if (!empty($_GET['filter_ttype']))  $active_filters['transaction_type'] = $_GET['filter_ttype'];
    if (!empty($_GET['filter_cust']))   $active_filters['customer']         = $_GET['filter_cust'];
    if (!empty($_GET['filter_mech']))   $active_filters['mechanic']         = $_GET['filter_mech'];
    if (!empty($_GET['filter_status'])) $active_filters['status']           = $_GET['filter_status'];
} elseif ($active_tab === 'merch_inventory') {
    if (!empty($_GET['filter_cat']))     $active_filters['category'] = $_GET['filter_cat'];
    if (!empty($_GET['filter_brand']))   $active_filters['brand']    = $_GET['filter_brand'];
    if (!empty($_GET['filter_status']))  $active_filters['status']   = $_GET['filter_status'];
    if (!empty($_GET['filter_batch']))   $active_filters['batch_id'] = $_GET['filter_batch'];
    if (!empty($_GET['filter_product'])) $active_filters['product']  = $_GET['filter_product'];
} elseif ($active_tab === 'fuel_inventory') {
    if (!empty($_GET['filter_fuel_type'])) $active_filters['fuel_type'] = $_GET['filter_fuel_type'];
    if (!empty($_GET['filter_ugt']))       $active_filters['ugt']       = $_GET['filter_ugt'];
} elseif ($active_tab === 'inventory_movement') {
    if (!empty($_GET['filter_ttype']))   $active_filters['transaction_type'] = $_GET['filter_ttype'];
    if (!empty($_GET['filter_product'])) $active_filters['product']          = $_GET['filter_product'];
    if (!empty($_GET['filter_batch']))   $active_filters['batch_id']         = $_GET['filter_batch'];
    if (!empty($_GET['filter_user']))    $active_filters['user']             = $_GET['filter_user'];
} elseif ($active_tab === 'inventory_adjustment') {
    if (!empty($_GET['filter_status']))   $active_filters['status']   = $_GET['filter_status'];
    if (!empty($_GET['filter_adj_type'])) $active_filters['adj_type'] = $_GET['filter_adj_type'];
    if (!empty($_GET['filter_batch']))    $active_filters['batch_id'] = $_GET['filter_batch'];
} elseif ($active_tab === 'expired_damaged') {
    if (!empty($_GET['filter_batch']))    $active_filters['batch_id'] = $_GET['filter_batch'];
    if (!empty($_GET['filter_product']))  $active_filters['product']  = $_GET['filter_product'];
    if (!empty($_GET['filter_adj_type'])) $active_filters['adj_type'] = $_GET['filter_adj_type'];
} elseif ($active_tab === 'job_order') {
    if (!empty($_GET['filter_status']))         $active_filters['status']         = $_GET['filter_status'];
    if (!empty($_GET['filter_mech']))           $active_filters['mechanic']       = $_GET['filter_mech'];
    if (!empty($_GET['filter_service_cat']))   $active_filters['service_cat']   = $_GET['filter_service_cat'];
    if (!empty($_GET['filter_cust']))           $active_filters['customer']       = $_GET['filter_cust'];
    if (!empty($_GET['filter_plate']))          $active_filters['plate']          = $_GET['filter_plate'];
    if (!empty($_GET['filter_payment_status'])) $active_filters['payment_status'] = $_GET['filter_payment_status'];
    if (!empty($_GET['filter_search']))         $active_filters['search']         = $_GET['filter_search'];
} elseif ($active_tab === 'mechanic_performance') {
    if (!empty($_GET['filter_mech']))         $active_filters['mechanic']     = $_GET['filter_mech'];
    if (!empty($_GET['filter_service_cat'])) $active_filters['service_cat'] = $_GET['filter_service_cat'];
    if (!empty($_GET['filter_status']))       $active_filters['status']      = $_GET['filter_status'];
    if (!empty($_GET['filter_search']))       $active_filters['search']      = $_GET['filter_search'];
} elseif ($active_tab === 'purchase_order') {
    if (!empty($_GET['filter_po_status'])) $active_filters['po_status'] = $_GET['filter_po_status'];
    if (!empty($_GET['filter_cat']))       $active_filters['category']  = $_GET['filter_cat'];
    if (!empty($_GET['filter_search']))    $active_filters['search']    = $_GET['filter_search'];
} elseif ($active_tab === 'fuel_reconciliation' || $active_tab === 'delivery_validation') {
    if (!empty($_GET['filter_fuel_type'])) $active_filters['fuel_type'] = $_GET['filter_fuel_type'];
    if (!empty($_GET['filter_ugt']))       $active_filters['ugt']       = $_GET['filter_ugt'];
    if (!empty($_GET['filter_status']))    $active_filters['status']    = $_GET['filter_status'];
} elseif ($active_tab === 'po_vs_received') {
    if (!empty($_GET['filter_po_no']))   $active_filters['po_no']  = $_GET['filter_po_no'];
    if (!empty($_GET['filter_search'])) $active_filters['search'] = $_GET['filter_search'];
} elseif ($active_tab === 'stock_in_approval') {
    if (!empty($_GET['filter_appr_status'])) $active_filters['appr_status'] = $_GET['filter_appr_status'];
    if (!empty($_GET['filter_product']))     $active_filters['product']     = $_GET['filter_product'];
    if (!empty($_GET['filter_search']))      $active_filters['search']      = $_GET['filter_search'];
} elseif ($active_tab === 'revenue_summary') {
    if (!empty($_GET['filter_search'])) $active_filters['search'] = $_GET['filter_search'];
} elseif ($active_tab === 'receivables') {
    if (!empty($_GET['filter_cust']))     $active_filters['customer'] = $_GET['filter_cust'];
    if (!empty($_GET['filter_status']))   $active_filters['status']   = $_GET['filter_status'];
    if (!empty($_GET['filter_due_date'])) $active_filters['due_date'] = $_GET['filter_due_date'];
} elseif ($active_tab === 'payment_collections') {
    if (!empty($_GET['filter_pm']))   $active_filters['payment_method'] = $_GET['filter_pm'];
    if (!empty($_GET['filter_cust'])) $active_filters['customer']       = $_GET['filter_cust'];
} elseif ($active_tab === 'sales_vs_collection') {
    if (!empty($_GET['filter_search'])) $active_filters['search'] = $_GET['filter_search'];
} elseif ($active_tab === 'customer_report') {
    if (!empty($_GET['filter_cust_name']))       $active_filters['cust_name']       = $_GET['filter_cust_name'];
    if (!empty($_GET['filter_cust_type']))       $active_filters['cust_type']       = $_GET['filter_cust_type'];
    if (!empty($_GET['filter_payment_status'])) $active_filters['payment_status'] = $_GET['filter_payment_status'];
    if (!empty($_GET['filter_plate']))          $active_filters['plate']          = $_GET['filter_plate'];
    if (!empty($_GET['filter_search']))         $active_filters['search']         = $_GET['filter_search'];
} elseif ($active_tab === 'transaction_logs') {
    if (!empty($_GET['filter_module']))  $active_filters['module']  = $_GET['filter_module'];
    if (!empty($_GET['filter_search'])) $active_filters['search']  = $_GET['filter_search'];
} elseif ($active_tab === 'inventory_logs') {
    if (!empty($_GET['filter_action']))   $active_filters['action']   = $_GET['filter_action'];
    if (!empty($_GET['filter_product']))  $active_filters['product']  = $_GET['filter_product'];
    if (!empty($_GET['filter_search']))   $active_filters['search']   = $_GET['filter_search'];
} elseif ($active_tab === 'approval_logs') {
    if (!empty($_GET['filter_status']))   $active_filters['status']   = $_GET['filter_status'];
    if (!empty($_GET['filter_search']))   $active_filters['search']   = $_GET['filter_search'];
} elseif ($active_tab === 'archived_deactivated') {
    if (!empty($_GET['filter_action']))  $active_filters['action']  = $_GET['filter_action'];
    if (!empty($_GET['filter_module']))  $active_filters['module']  = $_GET['filter_module'];
    if (!empty($_GET['filter_search'])) $active_filters['search']  = $_GET['filter_search'];
}

// Fetch report data
$report_data = getAdminReportData($pdo, $station_id, $date_from, $date_to, $active_cat, $active_tab, $active_filters);

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ============================================================
   MANAGER REPORTS - EXACT ADMIN ALIGNED LAYOUT
   ============================================================ */

.reports-wrapper {
    background: #ffffff !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    margin: 0 0 60px 0 !important;
    overflow: visible !important;
    border: 1px solid #e2e8f0 !important;
}

.rpt-content {
    padding: 22px 28px 60px 28px !important;
}

/* Filter & Export Bar */
.rpt-filter-bar {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    background: transparent !important;
    padding: 0 !important;
    border-radius: 0 !important;
    border: none !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
}

.rpt-filter-inputs {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-wrap: wrap !important;
}

.rpt-filter-bar label {
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #00264D !important;
    text-transform: uppercase !important;
    margin: 0 !important;
}

.rpt-filter-bar input[type="date"],
.rpt-filter-bar input[type="text"],
.rpt-filter-bar select {
    padding: 6px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    color: #334155 !important;
    background: #ffffff !important;
}

.rpt-btn-apply {
    padding: 7px 18px !important;
    background: #00264D !important;
    color: #ffffff !important;
    border: none !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.rpt-btn-apply:hover {
    background: #001a35 !important;
}

/* Export Group */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}

.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 4px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
}

.rpt-btn-print  { color: #475569 !important; border-color: transparent !important; background: transparent !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #dc2626 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; }
.rpt-btn-csv   { color: #16a34a !important; border-color: #16a34a !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdf4 !important; }

/* Sub-Tab Nav - Horizontal strip matching Admin design */
.rpt-subtab-nav {
    display: flex !important;
    flex-wrap: wrap !important;
    margin-bottom: 22px !important;
    border: 1px solid #d1d9e6 !important;
    border-radius: 0 !important;
    overflow: hidden !important;
    border-bottom: 3px solid #00264D !important;
}

.rpt-subtab-btn {
    flex: 1 !important;
    min-width: 140px !important;
    padding: 12px 16px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    background: #ffffff !important;
    border: none !important;
    border-right: 1px solid #d1d9e6 !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    text-align: center !important;
}

.rpt-subtab-btn:last-child {
    border-right: none !important;
}

.rpt-subtab-btn:hover {
    background: #f1f5f9 !important;
    color: #00264D !important;
    text-decoration: none !important;
}

.rpt-subtab-btn.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
}

.rpt-subtab-btn i {
    font-size: 13px !important;
}

/* Table Styling - Exact Match with Admin Reports */
.rpt-table {
    width: 100% !important;
    border-collapse: collapse !important;
    font-size: 12px !important;
    margin-bottom: 0 !important;
}
.rpt-table thead th {
    background: #f1f5f9 !important;
    color: #00264D !important;
    font-weight: 800 !important;
    text-transform: uppercase !important;
    padding: 11px 10px !important;
    border-bottom: 2px solid #00264D !important;
    font-size: 11px !important;
    letter-spacing: 0.3px !important;
}
.rpt-table tbody td {
    padding: 10px !important;
    border-bottom: 1px solid #e2e8f0 !important;
    color: #334155 !important;
}
.rpt-table tbody tr:hover {
    background: #f8fafc !important;
}
.rpt-table tfoot td {
    padding: 11px 10px !important;
    background: #f1f5f9 !important;
    font-weight: 800 !important;
    border-top: 2px solid #00264D !important;
    color: #00264D !important;
}

/* Summary Cards */
.rpt-summary-cards {
    display: grid !important;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)) !important;
    gap: 16px !important;
    margin-bottom: 24px !important;
}
.rpt-summary-card {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 8px !important;
    padding: 16px !important;
    text-align: center !important;
}
.rpt-summary-card .card-label {
    font-size: 11px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    color: #64748b !important;
    font-weight: 600 !important;
    margin-bottom: 6px !important;
    display: block !important;
}
.rpt-summary-card .card-value {
    font-size: 22px !important;
    font-weight: 800 !important;
    color: #00264D !important;
}

/* Section heading inside report */
.rpt-section-heading {
    font-size: 13px !important;
    font-weight: 800 !important;
    color: #00264D !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    margin: 24px 0 12px !important;
    padding-bottom: 8px !important;
    border-bottom: 2px solid #00264D !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

/* Print Styles — direct native in-page print (matching staff reports flow) */
@media print {
    @page { size: A4 landscape; margin: 0.4in 0.5in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-shadow: none !important; }

    /* Hide all page chrome except sfss-print-only */
    body > *:not(.sfss-print-only) { display: none !important; }
    
    .sfss-print-only { display: block !important; position: static !important; width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; font-family: Arial, sans-serif !important; font-size: 10px !important; color: #000 !important; }
    .sfss-print-only *, .sfss-print-only *::before, .sfss-print-only *::after { box-shadow: none !important; text-shadow: none !important; }

    /* Report typography & layout */
    .sfss-print-only .rpt-header-title { text-align: center !important; margin-bottom: 16px !important; }
    .sfss-print-only .rpt-header-title h2 { font-size: 16px !important; font-weight: 800 !important; color: #00264D !important; text-transform: uppercase !important; margin: 0 0 2px !important; }
    .sfss-print-only .rpt-header-title h4 { font-size: 11px !important; font-weight: 700 !important; color: #00264D !important; text-transform: uppercase !important; margin: 0 0 4px !important; }
    .sfss-print-only .rpt-header-title p { font-size: 10px !important; color: #555 !important; margin: 1px 0 !important; }
    .sfss-print-only .rpt-section-heading { font-size: 10px !important; font-weight: 800 !important; color: #00264D !important; text-transform: uppercase !important; border-bottom: 2px solid #00264D !important; padding: 6px 0 3px !important; margin: 14px 0 6px !important; }

    /* Table styles */
    .sfss-print-only .rpt-table, .sfss-print-only table { width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important; font-size: 8.5px !important; }
    .sfss-print-only .rpt-table th, .sfss-print-only table th { background: #00264D !important; color: #fff !important; font-weight: 700 !important; font-size: 8px !important; text-transform: uppercase !important; padding: 5px 6px !important; border: 1px solid #001a36 !important; }
    .sfss-print-only .rpt-table td, .sfss-print-only table td { padding: 4px 6px !important; border: 1px solid #ccc !important; vertical-align: middle !important; }
    .sfss-print-only tr:nth-child(even) td { background: #f8fafc !important; }
    .sfss-print-only tfoot td { background: #e8f0fe !important; font-weight: 700 !important; border-top: 2px solid #00264D !important; }
    .sfss-print-only tr { page-break-inside: avoid !important; }
    .sfss-print-only .table-responsive { overflow: visible !important; }

    /* Utility */
    .sfss-print-only .text-end { text-align: right !important; }
    .sfss-print-only .text-center { text-align: center !important; }
    .sfss-print-only .fw-bold { font-weight: 700 !important; }
    .sfss-print-only .text-success { color: #15803d !important; }
    .sfss-print-only .text-danger  { color: #b91c1c !important; }
    .sfss-print-only .text-warning { color: #a16207 !important; }
    .sfss-print-only .text-muted   { color: #6b7280 !important; }
    .sfss-print-only .badge { display: inline-block !important; padding: 2px 5px !important; border-radius: 3px !important; font-size: 7.5px !important; font-weight: 700 !important; }
    .sfss-print-only code  { font-family: monospace !important; font-size: 8px !important; }
    .sfss-print-only .mb-4, .sfss-print-only .mb-2 { margin-bottom: 8px !important; }
    .sfss-print-only .rpt-summary-cards { display: flex !important; flex-wrap: wrap !important; gap: 6px !important; margin-bottom: 10px !important; }
    .sfss-print-only .rpt-summary-card  { border: 1px solid #cbd5e1 !important; border-radius: 4px !important; padding: 6px !important; background: #fff !important; text-align: center !important; min-width: 80px !important; }
    .sfss-print-only .rpt-summary-card .card-label { font-size: 7px !important; font-weight: 700 !important; text-transform: uppercase !important; color: #64748b !important; display: block !important; }
    .sfss-print-only .rpt-summary-card .card-value { font-size: 11px !important; font-weight: 800 !important; display: block !important; }
}
</style>

<div class="reports-wrapper">
    <!-- Main Content Area -->
    <div class="rpt-content">
        <!-- Date Filter & Export Bar -->
        <form method="GET" action="manager_reports.php" class="rpt-filter-bar no-print">
            <div class="rpt-filter-inputs">
                <input type="hidden" name="cat" value="<?= htmlspecialchars($active_cat) ?>">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">

                <label><i class="far fa-calendar-alt me-1"></i> FROM</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>

                <label class="ms-2"><i class="far fa-calendar-alt me-1"></i> TO</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>

                <?php if ($active_tab === 'fuel_sales'): ?>
                    <?php
                    $fuel_types_list = $report_data['fuel_types'] ?? [];
                    $pump_list       = $report_data['pump_list'] ?? [];
                    $sel_ft  = htmlspecialchars($active_filters['fuel_type'] ?? '');
                    $sel_pid = (int)($active_filters['pump_id'] ?? 0);
                    ?>
                    <?php if (!empty($fuel_types_list)): ?>
                    <label class="ms-2"><i class="fas fa-gas-pump me-1"></i> Fuel Type</label>
                    <select name="filter_fuel_type" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Types</option>
                        <?php foreach ($fuel_types_list as $ft): ?>
                            <option value="<?= htmlspecialchars($ft) ?>" <?= ($sel_ft === htmlspecialchars($ft)) ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($pump_list)): ?>
                    <label class="ms-1"><i class="fas fa-tachometer-alt me-1"></i> UGT/Pump</label>
                    <select name="filter_pump_id" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All UGTs</option>
                        <?php foreach ($pump_list as $p): ?>
                            <option value="<?= (int)$p['pump_id'] ?>" <?= ($sel_pid === (int)$p['pump_id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php $sel_shift = htmlspecialchars($active_filters['shift'] ?? ''); ?>
                    <label class="ms-1"><i class="fas fa-clock me-1"></i> Shift</label>
                    <select name="filter_shift" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Shifts</option>
                        <option value="Shift 1" <?= ($sel_shift === 'Shift 1') ? 'selected' : '' ?>>Shift 1</option>
                        <option value="Shift 2" <?= ($sel_shift === 'Shift 2') ? 'selected' : '' ?>>Shift 2</option>
                    </select>

                <?php elseif ($active_tab === 'daily_merch_service'): ?>
                    <?php
                    $sel_pm     = htmlspecialchars($active_filters['payment_method'] ?? '');
                    $sel_ttype  = htmlspecialchars($active_filters['transaction_type'] ?? '');
                    $sel_cust   = htmlspecialchars($active_filters['customer'] ?? '');
                    $sel_mech   = htmlspecialchars($active_filters['mechanic'] ?? '');
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    $mechs_list = $report_data['mechanics'] ?? [];
                    ?>
                    <label class="ms-1"><i class="fas fa-credit-card me-1"></i> Payment Method</label>
                    <select name="filter_pm" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Methods</option>
                        <option value="Cash" <?= $sel_pm === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="GCash" <?= $sel_pm === 'GCash' ? 'selected' : '' ?>>GCash</option>
                        <option value="Credit Card" <?= $sel_pm === 'Credit Card' ? 'selected' : '' ?>>Credit Card</option>
                        <option value="Debit Card" <?= $sel_pm === 'Debit Card' ? 'selected' : '' ?>>Debit Card</option>
                        <option value="Maya" <?= $sel_pm === 'Maya' ? 'selected' : '' ?>>Maya</option>
                        <option value="Petron Fleet Card" <?= $sel_pm === 'Petron Fleet Card' ? 'selected' : '' ?>>Petron Fleet Card</option>
                        <option value="Credit Account" <?= $sel_pm === 'Credit Account' ? 'selected' : '' ?>>Credit Account (AR)</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-filter me-1"></i> Type</label>
                    <select name="filter_ttype" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Transactions</option>
                        <option value="merchandise" <?= $sel_ttype === 'merchandise' ? 'selected' : '' ?>>Merchandise Only</option>
                        <option value="job_order" <?= $sel_ttype === 'job_order' ? 'selected' : '' ?>>Job Order Only</option>
                        <option value="both" <?= $sel_ttype === 'both' ? 'selected' : '' ?>>Both</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-user me-1"></i> Customer</label>
                    <input type="text" name="filter_cust" value="<?= $sel_cust ?>" placeholder="Customer name..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                    <?php if (!empty($mechs_list)): ?>
                    <label class="ms-1"><i class="fas fa-wrench me-1"></i> Mechanic</label>
                    <select name="filter_mech" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Mechanics</option>
                        <?php foreach ($mechs_list as $m): ?>
                            <option value="<?= htmlspecialchars($m['name']) ?>" <?= $sel_mech === htmlspecialchars($m['name']) ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Completed" <?= $sel_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Released" <?= $sel_status === 'Released' ? 'selected' : '' ?>>Released</option>
                        <option value="Pending" <?= $sel_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    </select>

                <?php elseif ($active_tab === 'merch_inventory'): ?>
                    <?php
                    $sel_cat    = htmlspecialchars($active_filters['category'] ?? '');
                    $sel_brand  = htmlspecialchars($active_filters['brand'] ?? '');
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    $sel_batch  = htmlspecialchars($active_filters['batch_id'] ?? '');
                    $sel_prod   = htmlspecialchars($active_filters['product'] ?? '');
                    $cats_list  = $report_data['categories'] ?? [];
                    $brands_list= $report_data['brands'] ?? [];
                    ?>
                    <?php if (!empty($cats_list)): ?>
                    <label class="ms-1"><i class="fas fa-tag me-1"></i> Category</label>
                    <select name="filter_cat" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Categories</option>
                        <?php foreach ($cats_list as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= $sel_cat === htmlspecialchars($c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($brands_list)): ?>
                    <label class="ms-1"><i class="fas fa-copyright me-1"></i> Brand</label>
                    <select name="filter_brand" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Brands</option>
                        <?php foreach ($brands_list as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>" <?= $sel_brand === htmlspecialchars($b) ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Stock Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="In Stock" <?= $sel_status === 'In Stock' ? 'selected' : '' ?>>In Stock</option>
                        <option value="Low Stock" <?= $sel_status === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                        <option value="Out of Stock" <?= $sel_status === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
                        <option value="Overstock" <?= $sel_status === 'Overstock' ? 'selected' : '' ?>>Overstock</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-barcode me-1"></i> Batch ID</label>
                    <input type="text" name="filter_batch" value="<?= $sel_batch ?>" placeholder="Batch ID..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:110px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Product</label>
                    <input type="text" name="filter_product" value="<?= $sel_prod ?>" placeholder="Product/SKU..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'fuel_inventory'): ?>
                    <?php
                    $sel_ft  = htmlspecialchars($active_filters['fuel_type'] ?? '');
                    $sel_ugt = htmlspecialchars($active_filters['ugt'] ?? '');
                    $fuel_types_list = $report_data['fuel_types'] ?? [];
                    $ugt_list        = $report_data['ugt_list'] ?? [];
                    ?>
                    <?php if (!empty($fuel_types_list)): ?>
                    <label class="ms-1"><i class="fas fa-gas-pump me-1"></i> Fuel Type</label>
                    <select name="filter_fuel_type" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Types</option>
                        <?php foreach ($fuel_types_list as $ft): ?>
                            <option value="<?= htmlspecialchars($ft) ?>" <?= $sel_ft === htmlspecialchars($ft) ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php if (!empty($ugt_list)): ?>
                    <label class="ms-1"><i class="fas fa-database me-1"></i> Tank/UGT</label>
                    <select name="filter_ugt" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Tanks</option>
                        <?php foreach ($ugt_list as $u): ?>
                            <option value="<?= htmlspecialchars($u['name']) ?>" <?= $sel_ugt === htmlspecialchars($u['name']) ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                <?php elseif ($active_tab === 'inventory_movement'): ?>
                    <?php
                    $sel_ttype = htmlspecialchars($active_filters['transaction_type'] ?? '');
                    $sel_prod  = htmlspecialchars($active_filters['product'] ?? '');
                    $sel_batch = htmlspecialchars($active_filters['batch_id'] ?? '');
                    $sel_user  = htmlspecialchars($active_filters['user'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-exchange-alt me-1"></i> Movement Type</label>
                    <select name="filter_ttype" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Types</option>
                        <option value="Stock In" <?= $sel_ttype === 'Stock In' ? 'selected' : '' ?>>Stock In</option>
                        <option value="Sales" <?= $sel_ttype === 'Sales' ? 'selected' : '' ?>>Sales</option>
                        <option value="Return" <?= $sel_ttype === 'Return' ? 'selected' : '' ?>>Return</option>
                        <option value="Expired" <?= $sel_ttype === 'Expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="Damaged" <?= $sel_ttype === 'Damaged' ? 'selected' : '' ?>>Damaged</option>
                        <option value="Physical Count Adjustment" <?= $sel_ttype === 'Physical Count Adjustment' ? 'selected' : '' ?>>Physical Count Adjustment</option>
                        <option value="Manual Adjustment" <?= $sel_ttype === 'Manual Adjustment' ? 'selected' : '' ?>>Manual Adjustment</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-box me-1"></i> Product</label>
                    <input type="text" name="filter_product" value="<?= $sel_prod ?>" placeholder="Product..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:110px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-barcode me-1"></i> Batch ID</label>
                    <input type="text" name="filter_batch" value="<?= $sel_batch ?>" placeholder="Batch ID..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:100px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-user me-1"></i> User</label>
                    <input type="text" name="filter_user" value="<?= $sel_user ?>" placeholder="Performed By..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:110px;color:#334155;">

                <?php elseif ($active_tab === 'inventory_adjustment'): ?>
                    <?php
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    $sel_atype  = htmlspecialchars($active_filters['adj_type'] ?? '');
                    $sel_batch  = htmlspecialchars($active_filters['batch_id'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $sel_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $sel_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-sliders-h me-1"></i> Adjustment Type</label>
                    <select name="filter_adj_type" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Types</option>
                        <option value="Expired Product" <?= $sel_atype === 'Expired Product' ? 'selected' : '' ?>>Expired Product</option>
                        <option value="Damaged Product" <?= $sel_atype === 'Damaged Product' ? 'selected' : '' ?>>Damaged Product</option>
                        <option value="Physical Count" <?= $sel_atype === 'Physical Count' ? 'selected' : '' ?>>Physical Count</option>
                        <option value="Encoding Correction" <?= $sel_atype === 'Encoding Correction' ? 'selected' : '' ?>>Encoding Correction</option>
                        <option value="Stock Correction" <?= $sel_atype === 'Stock Correction' ? 'selected' : '' ?>>Stock Correction</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-barcode me-1"></i> Batch ID</label>
                    <input type="text" name="filter_batch" value="<?= $sel_batch ?>" placeholder="Batch ID..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:110px;color:#334155;">

                <?php elseif ($active_tab === 'job_order'): ?>
                    <?php
                    $sel_status  = htmlspecialchars($active_filters['status'] ?? '');
                    $sel_mech    = htmlspecialchars($active_filters['mechanic'] ?? '');
                    $sel_scat    = htmlspecialchars($active_filters['service_cat'] ?? '');
                    $sel_cust    = htmlspecialchars($active_filters['customer'] ?? '');
                    $sel_plate   = htmlspecialchars($active_filters['plate'] ?? '');
                    $sel_payst   = htmlspecialchars($active_filters['payment_status'] ?? '');
                    $sel_search  = htmlspecialchars($active_filters['search'] ?? '');
                    $mechs_list  = $report_data['mechanics'] ?? [];
                    $scats_list  = $report_data['service_categories'] ?? [];
                    ?>
                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="In Progress" <?= $sel_status === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="Awaiting Parts" <?= $sel_status === 'Awaiting Parts' ? 'selected' : '' ?>>Awaiting Parts</option>
                        <option value="Completed" <?= $sel_status === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Released" <?= $sel_status === 'Released' ? 'selected' : '' ?>>Released</option>
                        <option value="Cancelled" <?= $sel_status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-wrench me-1"></i> Mechanic</label>
                    <select name="filter_mech" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Mechanics</option>
                        <?php foreach ($mechs_list as $m): ?>
                            <option value="<?= htmlspecialchars($m['name']) ?>" <?= $sel_mech === htmlspecialchars($m['name']) ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="ms-1"><i class="fas fa-car me-1"></i> Plate No.</label>
                    <input type="text" name="filter_plate" value="<?= $sel_plate ?>" placeholder="Plate..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:100px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="JO No./Customer..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:130px;color:#334155;">

                <?php elseif ($active_tab === 'purchase_order'): ?>
                    <?php
                    $sel_post = htmlspecialchars($active_filters['po_status'] ?? '');
                    $sel_cat  = htmlspecialchars($active_filters['category'] ?? '');
                    $sel_search = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> PO Status</label>
                    <select name="filter_po_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_post === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $sel_post === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Partially Delivered" <?= $sel_post === 'Partially Delivered' ? 'selected' : '' ?>>Partially Delivered</option>
                        <option value="Completed" <?= $sel_post === 'Completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="Cancelled" <?= $sel_post === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search PO No..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'fuel_reconciliation'): ?>
                    <?php
                    $sel_ftype  = htmlspecialchars($active_filters['fuel_type'] ?? '');
                    $sel_ugt    = htmlspecialchars($active_filters['ugt'] ?? '');
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-gas-pump me-1"></i> Fuel Type</label>
                    <select name="filter_fuel_type" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Fuel Types</option>
                        <?php foreach (['Diesel', 'Turbo Diesel', 'XCS Plus', 'Xtra UNL', 'Kerosene'] as $ft): ?>
                            <option value="<?= htmlspecialchars($ft) ?>" <?= $sel_ftype === $ft ? 'selected' : '' ?>><?= htmlspecialchars($ft) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="ms-1"><i class="fas fa-oil-can me-1"></i> UGT</label>
                    <select name="filter_ugt" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All UGTs</option>
                        <?php foreach (['UGT #1', 'UGT #2', 'UGT #3', 'UGT #4', 'UGT #5', 'UGT #6', 'UGT #7'] as $ug): ?>
                            <option value="<?= htmlspecialchars($ug) ?>" <?= $sel_ugt === $ug ? 'selected' : '' ?>><?= htmlspecialchars($ug) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Submitted" <?= $sel_status === 'Submitted' ? 'selected' : '' ?>>Submitted</option>
                    </select>

                <?php elseif ($active_tab === 'stock_in_approval'): ?>
                    <?php
                    $sel_ast  = htmlspecialchars($active_filters['appr_status'] ?? '');
                    $sel_search = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_appr_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_ast === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $sel_ast === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $sel_ast === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search batch/product..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:130px;color:#334155;">

                <?php elseif ($active_tab === 'revenue_summary' || $active_tab === 'sales_vs_collection'): ?>
                    <?php $sel_search = htmlspecialchars($active_filters['search'] ?? ''); ?>
                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:130px;color:#334155;">

                <?php elseif ($active_tab === 'receivables'): ?>
                    <?php
                    $sel_cust   = htmlspecialchars($active_filters['customer'] ?? '');
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    $sel_due    = htmlspecialchars($active_filters['due_date'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-user me-1"></i> Customer</label>
                    <input type="text" name="filter_cust" value="<?= $sel_cust ?>" placeholder="Search customer..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Current" <?= $sel_status === 'Current' ? 'selected' : '' ?>>Current</option>
                        <option value="Due Today" <?= $sel_status === 'Due Today' ? 'selected' : '' ?>>Due Today</option>
                        <option value="Overdue" <?= $sel_status === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="Paid" <?= $sel_status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    </select>

                    <label class="ms-1"><i class="far fa-calendar-alt me-1"></i> Due Date</label>
                    <input type="date" name="filter_due_date" value="<?= $sel_due ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">

                <?php elseif ($active_tab === 'payment_collections'): ?>
                    <?php
                    $sel_pm   = htmlspecialchars($active_filters['payment_method'] ?? '');
                    $sel_cust = htmlspecialchars($active_filters['customer'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-credit-card me-1"></i> Payment Method</label>
                    <select name="filter_pm" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Methods</option>
                        <option value="Cash" <?= $sel_pm === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="Credit Card" <?= $sel_pm === 'Credit Card' ? 'selected' : '' ?>>Credit Card</option>
                        <option value="Debit Card" <?= $sel_pm === 'Debit Card' ? 'selected' : '' ?>>Debit Card</option>
                        <option value="GCash" <?= $sel_pm === 'GCash' ? 'selected' : '' ?>>GCash</option>
                        <option value="Maya" <?= $sel_pm === 'Maya' ? 'selected' : '' ?>>Maya</option>
                        <option value="Petron Fleet Card" <?= $sel_pm === 'Petron Fleet Card' ? 'selected' : '' ?>>Petron Fleet Card</option>
                        <option value="Credit Account" <?= $sel_pm === 'Credit Account' ? 'selected' : '' ?>>Credit Account (AR)</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-user me-1"></i> Customer</label>
                    <input type="text" name="filter_cust" value="<?= $sel_cust ?>" placeholder="Search customer..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'customer_report'): ?>
                    <?php
                    $sel_cname   = htmlspecialchars($active_filters['cust_name'] ?? '');
                    $sel_ctype   = htmlspecialchars($active_filters['cust_type'] ?? '');
                    $sel_pstatus = htmlspecialchars($active_filters['payment_status'] ?? '');
                    $sel_plate   = htmlspecialchars($active_filters['plate'] ?? '');
                    $sel_search  = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-user me-1"></i> Customer Name</label>
                    <input type="text" name="filter_cust_name" value="<?= $sel_cname ?>" placeholder="Customer name..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                    <label class="ms-1"><i class="fas fa-id-badge me-1"></i> Customer Type</label>
                    <select name="filter_cust_type" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Types</option>
                        <option value="Walk-in" <?= $sel_ctype === 'Walk-in' ? 'selected' : '' ?>>Walk-in</option>
                        <option value="Credit Account" <?= $sel_ctype === 'Credit Account' ? 'selected' : '' ?>>Credit Account</option>
                        <option value="Fleet Card" <?= $sel_ctype === 'Fleet Card' ? 'selected' : '' ?>>Fleet Card</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-credit-card me-1"></i> Payment Status</label>
                    <select name="filter_payment_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Paid" <?= $sel_pstatus === 'Paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="Pending" <?= $sel_pstatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Unpaid" <?= $sel_pstatus === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="Overdue" <?= $sel_pstatus === 'Overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-car me-1"></i> Plate No.</label>
                    <input type="text" name="filter_plate" value="<?= $sel_plate ?>" placeholder="Plate no..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:100px;color:#334155;">

                     <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search customer/ID..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'transaction_logs'): ?>
                    <?php
                    $sel_module = htmlspecialchars($active_filters['module'] ?? '');
                    $sel_search = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-filter me-1"></i> Module</label>
                    <select name="filter_module" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Modules</option>
                        <option value="Merchandise Sale" <?= $sel_module === 'Merchandise Sale' ? 'selected' : '' ?>>Merchandise Sale</option>
                        <option value="Job Order" <?= $sel_module === 'Job Order' ? 'selected' : '' ?>>Job Order</option>
                        <option value="Return" <?= $sel_module === 'Return' ? 'selected' : '' ?>>Return</option>
                        <option value="Void" <?= $sel_module === 'Void' ? 'selected' : '' ?>>Void Transaction</option>
                        <option value="Refund" <?= $sel_module === 'Refund' ? 'selected' : '' ?>>Refund</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search transaction..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'inventory_logs'): ?>
                    <?php
                    $sel_action  = htmlspecialchars($active_filters['action'] ?? '');
                    $sel_product = htmlspecialchars($active_filters['product'] ?? '');
                    $sel_search  = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-filter me-1"></i> Action Type</label>
                    <select name="filter_action" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Actions</option>
                        <option value="Stock In" <?= $sel_action === 'Stock In' ? 'selected' : '' ?>>Stock In</option>
                        <option value="Stock Out" <?= $sel_action === 'Stock Out' ? 'selected' : '' ?>>Stock Out</option>
                        <option value="Inventory Adjustment" <?= $sel_action === 'Inventory Adjustment' ? 'selected' : '' ?>>Inventory Adjustment</option>
                        <option value="Expired Adjustment" <?= $sel_action === 'Expired Adjustment' ? 'selected' : '' ?>>Expired Products</option>
                        <option value="Damaged Adjustment" <?= $sel_action === 'Damaged Adjustment' ? 'selected' : '' ?>>Damaged Products</option>
                        <option value="Physical Count" <?= $sel_action === 'Physical Count' ? 'selected' : '' ?>>Physical Count</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search product/SKU..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'approval_logs'): ?>
                    <?php
                    $sel_status = htmlspecialchars($active_filters['status'] ?? '');
                    $sel_search = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-info-circle me-1"></i> Status</label>
                    <select name="filter_status" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Statuses</option>
                        <option value="Pending" <?= $sel_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Approved" <?= $sel_status === 'Approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="Rejected" <?= $sel_status === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search reference..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php elseif ($active_tab === 'archived_deactivated'): ?>
                    <?php
                    $sel_action = htmlspecialchars($active_filters['action'] ?? '');
                    $sel_module = htmlspecialchars($active_filters['module'] ?? '');
                    $sel_search = htmlspecialchars($active_filters['search'] ?? '');
                    ?>
                    <label class="ms-1"><i class="fas fa-filter me-1"></i> Action</label>
                    <select name="filter_action" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;color:#334155;">
                        <option value="">All Actions</option>
                        <option value="Archived" <?= $sel_action === 'Archived' ? 'selected' : '' ?>>Archived</option>
                        <option value="Deactivated" <?= $sel_action === 'Deactivated' ? 'selected' : '' ?>>Deactivated</option>
                        <option value="Reactivated" <?= $sel_action === 'Reactivated' ? 'selected' : '' ?>>Reactivated</option>
                    </select>

                    <label class="ms-1"><i class="fas fa-search me-1"></i> Search</label>
                    <input type="text" name="filter_search" value="<?= $sel_search ?>" placeholder="Search record/module..." style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;width:120px;color:#334155;">

                <?php endif; ?>

                <button type="submit" class="rpt-btn-apply"><i class="fas fa-sync-alt"></i> Apply</button>
                <?php if (!empty($active_filters)): ?>
                    <a href="manager_reports.php?cat=<?= $active_cat ?>&tab=<?= $active_tab ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
                       style="padding:7px 14px;border-radius:4px;font-size:12px;font-weight:600;color:#64748b;border:1px solid #cbd5e1;background:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                        <i class="fas fa-times"></i> Reset
                    </a>
                <?php endif; ?>
            </div>

            <!-- Export Buttons -->
            <div class="rpt-export-group">
                <button type="button" class="rpt-export-btn rpt-btn-print" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="rpt-export-btn rpt-btn-pdf" onclick="exportReport('pdf')">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button type="button" class="rpt-export-btn rpt-btn-excel" onclick="exportReport('excel')">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button type="button" class="rpt-export-btn rpt-btn-csv" onclick="exportReport('csv')">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
            </div>
        </form>

        <?php
        // Icon map for sub-tabs
        $tab_icons = [
            'fuel_sales'           => 'fas fa-gas-pump',
            'daily_merch_service'  => 'fas fa-shopping-cart',
            'merch_inventory'      => 'fas fa-boxes',
            'fuel_inventory'       => 'fas fa-oil-can',
            'inventory_movement'   => 'fas fa-exchange-alt',
            'inventory_adjustment' => 'fas fa-sliders-h',
            'expired_damaged'      => 'fas fa-exclamation-triangle',
            'job_order'            => 'fas fa-tools',
            'mechanic_performance' => 'fas fa-user-cog',
            'purchase_order'       => 'fas fa-file-alt',
            'fuel_reconciliation'  => 'fas fa-gas-pump',
            'po_vs_received'       => 'fas fa-balance-scale',
            'stock_in_approval'    => 'fas fa-check-circle',
            'revenue_summary'      => 'fas fa-chart-line',
            'receivables'          => 'fas fa-file-invoice-dollar',
            'payment_collections' => 'fas fa-hand-holding-usd',
            'sales_vs_collection' => 'fas fa-balance-scale-right',
            'customer_report'      => 'fas fa-users',
            'transaction_logs'     => 'fas fa-exchange-alt',
            'inventory_logs'       => 'fas fa-boxes',
            'approval_logs'        => 'fas fa-check-circle',
            'archived_deactivated' => 'fas fa-archive',
        ];
        ?>
        <?php if (count($categories[$active_cat]['tabs']) > 1): ?>
        <!-- Sub-Tab Navigation -->
        <div class="rpt-subtab-nav">
            <?php foreach ($categories[$active_cat]['tabs'] as $tab_key => $tab_title): ?>
                <a href="manager_reports.php?cat=<?= $active_cat ?>&tab=<?= $tab_key ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>"
                   class="rpt-subtab-btn <?= ($active_tab === $tab_key) ? 'active' : '' ?>">
                    <i class="<?= $tab_icons[$tab_key] ?? 'fas fa-file' ?>"></i>
                    <?= htmlspecialchars($tab_title) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Printable Report Content -->
        <div class="rpt-printable-area" id="adminReportPrintable">
            <!-- Manager-Style Centered Header Title -->
            <div class="rpt-header-title text-center py-2 mb-4" style="text-align: center !important;">
                <h2 style="font-size: 22px !important; font-weight: 800 !important; color: #00264D !important; text-transform: uppercase !important; margin: 0 0 2px !important; letter-spacing: 0.5px !important; text-align: center !important;">
                    <?= htmlspecialchars($main_title) ?>
                </h2>
                <?php if (!empty($sub_title)): ?>
                    <h4 style="font-size: 14px !important; font-weight: 800 !important; color: #00264D !important; text-transform: uppercase !important; margin: 0 0 6px !important; letter-spacing: 0.3px !important; text-align: center !important;">
                        <?= htmlspecialchars($sub_title) ?>
                    </h4>
                <?php endif; ?>
                <?php if (!empty($station_address)): ?>
                    <p style="font-size: 12px !important; color: #64748b !important; margin: 2px 0 !important; text-align: center !important;">
                        <?= htmlspecialchars($station_address) ?>
                    </p>
                <?php elseif (!empty($station_name) && $station_name !== 'Station'): ?>
                    <p style="font-size: 12px !important; color: #64748b !important; margin: 2px 0 !important; text-align: center !important;">
                        <?= htmlspecialchars($station_name) ?>
                    </p>
                <?php endif; ?>
                <p style="font-size: 12px !important; color: #64748b !important; margin: 2px 0 !important; text-align: center !important;">
                    <strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= ($date_from !== $date_to) ? ' – ' . date('F j, Y', strtotime($date_to)) : '' ?>
                </p>
            </div>

            <?php renderAdminReportContent($active_cat, $active_tab, $report_data); ?>
        </div>
    </div>
</div>

<!-- Customer Details Modal -->
<div class="modal fade" id="customerDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-id-card me-2"></i>Complete Customer Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="customerModalBody">
                <div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Loading profile...</p></div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Vendor Scripts -->
<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function buildPrintHTML() {
    const printableArea = document.getElementById('adminReportPrintable');
    if (!printableArea) return null;

    let reportCSS = `
        body { font-family: Arial, sans-serif; font-size: 10px; margin: 0; padding: 20px; background: white; color: #000; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th { background: #00264D !important; color: #fff !important; font-weight: 700; font-size: 9px; text-transform: uppercase; padding: 6px 8px; border: 1px solid #001a36; text-align: left; }
        td { padding: 5px 8px; border: 1px solid #ccc; font-size: 9px; vertical-align: middle; }
        tr:nth-child(even) td { background: #f8fafc; }
        tfoot td { background: #e8f0fe !important; font-weight: 700 !important; border-top: 2px solid #00264D !important; }
        .rpt-header-title { text-align: center; margin-bottom: 16px; }
        .rpt-header-title h2 { font-size: 18px; font-weight: 800; color: #00264D; text-transform: uppercase; margin: 0 0 2px; }
        .rpt-header-title h4 { font-size: 12px; font-weight: 800; color: #00264D; text-transform: uppercase; margin: 0 0 6px; }
        .rpt-header-title p { font-size: 11px; color: #555; margin: 2px 0; }
        .rpt-section-heading { font-size: 11px; font-weight: 700; color: #00264D; text-transform: uppercase; border-bottom: 2px solid #00264D; padding: 8px 0 4px; margin: 18px 0 8px; }
        .mb-4, .mb-2 { margin-bottom: 8px; }
        .text-end { text-align: right !important; }
        .text-center { text-align: center !important; }
        .fw-bold { font-weight: 700 !important; }
        .text-danger { color: #dc2626 !important; }
        .text-success { color: #16a34a !important; }
        .text-warning { color: #d97706 !important; }
        .text-info { color: #0369a1 !important; }
        .text-primary { color: #1d4ed8 !important; }
        .text-dark { color: #111827 !important; }
        .text-muted { color: #6b7280 !important; }
        code { font-family: monospace; font-size: 9px; }
        .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 8.5px; font-weight: 700; }
        .bg-success { background: #dcfce7 !important; color: #15803d !important; border: 1px solid #bbf7d0; }
        .bg-warning { background: #fef9c3 !important; color: #a16207 !important; border: 1px solid #fef08a; }
        .bg-danger { background: #fee2e2 !important; color: #b91c1c !important; border: 1px solid #fecaca; }
        .bg-secondary { background: #f3f4f6 !important; color: #4b5563 !important; border: 1px solid #e5e7eb; }
        .bg-primary { background: #dbeafe !important; color: #1d4ed8 !important; border: 1px solid #bfdbfe; }
        .bg-info { background: #e0f2fe !important; color: #0369a1 !important; border: 1px solid #bae6fd; }
        @page { size: A4 landscape; margin: 0.4in 0.5in; }
        tr { page-break-inside: avoid; }
        .table-responsive { overflow: visible; }
        .rpt-summary-cards { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .rpt-summary-card { border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px; background: #fff; text-align: center; min-width: 100px; }
        .rpt-summary-card .card-label { font-size: 7.5px; font-weight: 700; text-transform: uppercase; color: #64748b; display: block; margin-bottom: 3px; }
        .rpt-summary-card .card-value { font-size: 12px; font-weight: 800; display: block; }
        .no-print { display: none !important; }
    `;

    return `<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manager Report</title>
    <style>${reportCSS}</style>
</head>
<body>
    ${printableArea.innerHTML}
</body>
</html>`;
}

function printReport() {
    _doDirectNativePrint();
}

function _doDirectNativePrint(afterPrint) {
    var old = document.querySelector('.sfss-print-only');
    if (old) old.remove();

    var area = document.getElementById('adminReportPrintable');
    if (!area) { window.print(); return; }

    var origTitle = document.title;
    const headerTitle = area.querySelector('.rpt-header-title h2, .rpt-header-title h4');
    if (headerTitle) {
        document.title = headerTitle.innerText.trim();
    } else {
        document.title = 'Manager Report';
    }

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

function exportReport(type) {
    const printableArea = document.getElementById('adminReportPrintable');
    if (!printableArea) { alert('No printable report content available.'); return; }

    const cat      = "<?= htmlspecialchars($active_cat) ?>";
    const tab      = "<?= htmlspecialchars($active_tab) ?>";
    const dateFrom = "<?= htmlspecialchars($date_from) ?>";
    const dateTo   = "<?= htmlspecialchars($date_to) ?>";
    const filename = `Manager_Report_${cat}_${tab}_${dateFrom}_to_${dateTo}`;

    if (type === 'pdf') {
        const pdfBtn = document.querySelector('.rpt-btn-pdf');
        const origHTML = pdfBtn ? pdfBtn.innerHTML : '';
        if (pdfBtn) {
            pdfBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
            pdfBtn.disabled  = true;
        }

        const headerElem = printableArea.querySelector('.rpt-header-title');
        let title = '';
        const metaLines = [];
        if (headerElem) {
            const h2 = headerElem.querySelector('h2');
            if (h2) title = h2.innerText.trim();
            headerElem.querySelectorAll('h3, h4, p').forEach(el => {
                const txt = el.innerText.trim();
                if (txt) metaLines.push(txt);
            });
        }

        const sections = [];
        const tables = Array.from(printableArea.querySelectorAll('table'));
        tables.forEach((tbl) => {
            let sectionTitle = '';
            let prev = tbl.closest('.table-responsive')?.previousElementSibling;
            if (prev && prev.classList.contains('rpt-section-heading')) {
                sectionTitle = prev.innerText.trim();
            }

            const headers = [];
            const headerRow = tbl.querySelector('thead tr');
            if (headerRow) {
                headerRow.querySelectorAll('th').forEach(th => headers.push(th.innerText.trim()));
            }

            const rows = [];
            tbl.querySelectorAll('tbody tr').forEach(tr => {
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => rowData.push(td.innerText.trim()));
                if (rowData.length) rows.push(rowData);
            });

            tbl.querySelectorAll('tfoot tr').forEach(tr => {
                const rowData = [];
                tr.querySelectorAll('td').forEach(td => rowData.push(td.innerText.trim()));
                if (rowData.length) rows.push(rowData);
            });

            sections.push({
                title: sectionTitle,
                headers: headers,
                rows: rows
            });
        });

        const pdfPayload = {
            filename: filename + '.pdf',
            title: title || 'MANAGER REPORT',
            metaLines: metaLines,
            sections: sections
        };

        fetch('report_pdf_download.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(pdfPayload)
        })
        .then(response => {
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return response.blob();
        })
        .then(blob => {
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename + '.pdf';
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { document.body.removeChild(a); window.URL.revokeObjectURL(url); }, 200);
        })
        .catch(err => {
            console.error('PDF download error:', err);
            _doDirectNativePrint();
        })
        .finally(() => {
            if (pdfBtn) {
                pdfBtn.innerHTML = origHTML;
                pdfBtn.disabled  = false;
            }
        });
        return;
    }

    const headerElem = printableArea.querySelector('.rpt-header-title');
    const headerLines = [];
    if (headerElem) {
        headerElem.querySelectorAll('h2, h3, h4, p').forEach(el => {
            const txt = el.innerText.trim();
            if (txt) headerLines.push(txt);
        });
    }

    const tables = Array.from(printableArea.querySelectorAll('table'));
    if (!tables.length) { alert('No data table found to export.'); return; }

    if (type === 'csv') {
        let csv = '';
        headerLines.forEach(line => {
            csv += '"' + line.replace(/"/g, '""') + '"\n';
        });
        csv += '\n';

        tables.forEach((tbl) => {
            let sectionHeading = '';
            let prev = tbl.closest('.table-responsive')?.previousElementSibling;
            if (prev && prev.classList.contains('rpt-section-heading')) {
                sectionHeading = prev.innerText.trim();
            }
            if (sectionHeading) {
                csv += '"' + sectionHeading.replace(/"/g, '""') + '"\n';
            }

            tbl.querySelectorAll('tr').forEach(r => {
                const cols = r.querySelectorAll('th, td');
                const rowData = [];
                cols.forEach(c => rowData.push('"' + c.innerText.replace(/"/g, '""').trim() + '"'));
                if (rowData.length) csv += rowData.join(',') + '\n';
            });
            csv += '\n';
        });

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);

    } else if (type === 'excel') {
        if (typeof XLSX === 'undefined') {
            alert('Excel export library not loaded. Please refresh the page.');
            return;
        }

        const wb = XLSX.utils.book_new();
        const masterData = [];
        headerLines.forEach(line => { masterData.push([line]); });
        masterData.push([]);

        tables.forEach((tbl) => {
            let sectionHeading = '';
            let prev = tbl.closest('.table-responsive')?.previousElementSibling;
            if (prev && prev.classList.contains('rpt-section-heading')) {
                sectionHeading = prev.innerText.trim();
            }
            if (sectionHeading) { masterData.push([sectionHeading]); }

            tbl.querySelectorAll('tr').forEach(r => {
                const cols = r.querySelectorAll('th, td');
                const rowData = [];
                cols.forEach(c => rowData.push(c.innerText.trim()));
                if (rowData.length) masterData.push(rowData);
            });
            masterData.push([]);
        });

        const ws = XLSX.utils.aoa_to_sheet(masterData);
        const sheetName = tab.replace(/_/g, ' ').substring(0, 30).toUpperCase();
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        XLSX.writeFile(wb, filename + '.xlsx');
    }
}

function openCustomerModal(customerId) {
    const modal = new bootstrap.Modal(document.getElementById('customerDetailModal'));
    const body = document.getElementById('customerModalBody');
    body.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Loading profile...</p></div>';
    modal.show();

    fetch(`manager_reports.php?ajax_action=get_customer_details&customer_id=${customerId}`)
        .then(r => r.json())
        .then(d => {
            const info = d.info || {};
            let html = `
                <div class="p-3 bg-light border rounded">
                    <h5 class="fw-bold text-primary mb-2">${info.name || 'N/A'}</h5>
                    <div class="row text-muted small">
                        <div class="col-md-3"><strong>ID:</strong> ${info.customer_id || 'N/A'}</div>
                        <div class="col-md-3"><strong>Contact:</strong> ${info.contact_number || info.phone || 'N/A'}</div>
                        <div class="col-md-3"><strong>Type:</strong> ${info.customer_type || 'Walk-in'}</div>
                        <div class="col-md-3"><strong>Registered:</strong> ${info.registered_at || 'N/A'}</div>
                    </div>
                </div>
            `;
            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = '<div class="alert alert-danger">Failed to load customer profile.</div>';
        });
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
