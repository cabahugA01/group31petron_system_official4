<?php
// Admin Reports Module
$page_id = 'reports_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: admin_dashboard.php'); exit;
}

$station_id   = (int) user_station_id();
if ($station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}
$station_name = 'Station';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

// Active tab
$active_tab = $_GET['tab'] ?? 'sales';
$allowed_tabs = ['sales','job_orders','balances','deliveries','staff','variance','receivable'];
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'sales';

// Date range defaults
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ── Reports Page Styles ─────────────────────────────────── */
.rpt-page { padding: 0; }

.rpt-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 20px;
}
.rpt-header-left h1 { margin: 0 0 4px; }
.rpt-header-left .sub { color: var(--muted); font-size: 13px; }

/* Date range bar */
.rpt-filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 10px 16px;
}
.rpt-filter-bar label { font-size: 12px; color: var(--muted); white-space: nowrap; }
.rpt-filter-bar input[type=date] {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 13px;
    outline: none;
}
.rpt-filter-bar input[type=date]:focus { border-color: var(--blue); }

/* Section heading */
.rpt-section-head {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 2px solid var(--line);
}
.rpt-section-head > i {
    font-size: 22px;
    color: var(--blue);
    flex-shrink: 0;
}
.rpt-section-head h2 {
    margin: 0 0 2px;
    font-size: 18px !important;
    font-weight: 700;
    color: var(--blue);
}
.rpt-section-sub {
    font-size: 12px;
    color: var(--muted);
}

/* Sub-tabs */
.sub-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.sub-tab {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    border: 1px solid var(--line);
    background: #fff;
    color: var(--muted);
    cursor: pointer;
    transition: all .15s;
}
.sub-tab:hover { border-color: var(--blue); color: var(--blue); }
.sub-tab.active { background: var(--blue); color: #fff; border-color: var(--blue); }

/* Export buttons */
.export-bar {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.export-bar .lbl { font-size: 12px; color: var(--muted); }
.btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--line);
    background: #fff;
    cursor: pointer;
    text-decoration: none;
    color: var(--text);
    transition: all .15s;
}
.btn-export:hover { background: #f1f5f9; }
.btn-export.excel { border-color: #16a34a; color: #16a34a; }
.btn-export.excel:hover { background: #f0fdf4; }
.btn-export.pdf   { border-color: #dc2626; color: #dc2626; }
.btn-export.pdf:hover   { background: #fef2f2; }

/* Table wrapper */
.rpt-table-wrap {
    overflow-x:hidden;
    border-radius: 12px;
    border: 1px solid var(--line);
}
.rpt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.rpt-table thead th {
    background: var(--blue);
    color: #fff;
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.rpt-table tbody td {
    padding: 9px 14px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
}
.rpt-table tbody tr:last-child td { border-bottom: none; }
.rpt-table tbody tr:hover td { background: #f8fafc; }
.rpt-table tfoot td {
    padding: 9px 14px;
    background: #f1f5f9;
    font-weight: 700;
    border-top: 2px solid var(--blue);
}
.rpt-table .tr { text-align: right; }
.rpt-table .tc { text-align: center; }

/* Status badges */
.badge-status {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.badge-status.approved, .badge-status.validated, .badge-status.completed, .badge-status.active, .badge-status.settled {
    background: #dcfce7; color: #16a34a;
}
.badge-status.pending {
    background: #fef9c3; color: #ca8a04;
}
.badge-status.rejected, .badge-status.failed, .badge-status.overdue, .badge-status.over-limit {
    background: #fee2e2; color: #dc2626;
}
.badge-status.in-progress {
    background: #dbeafe; color: #2563eb;
}

/* Loading / empty states */
.rpt-loading {
    text-align: center;
    padding: 40px;
    color: var(--muted);
    font-size: 14px;
}
.rpt-loading i { font-size: 28px; margin-bottom: 10px; display: block; }
.rpt-empty {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}
.rpt-empty i { font-size: 32px; margin-bottom: 10px; display: block; opacity: .4; }

/* Variance highlight */
.var-pos { color: #dc2626; font-weight: 600; }
.var-neg { color: #d97706; font-weight: 600; }
.var-ok  { color: #16a34a; }

/* Summary cards row */
.rpt-summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 18px;
}
.rpt-summary-card {
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 14px 16px;
}
.rpt-summary-card .sc-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
.rpt-summary-card .sc-value { font-size: 20px; font-weight: 700; color: var(--blue); margin-top: 4px; }
.rpt-summary-card .sc-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }
</style>

<div class="page-content rpt-page">

  <!-- ── Page Header ──────────────────────────────────────────────────────── -->
  <div class="rpt-header">
    <div class="rpt-header-left">
      <h1><i class="fas fa-chart-bar" style="color:var(--blue);margin-right:8px"></i>Reports Center</h1>
      <div class="sub"><?php echo htmlspecialchars($station_name); ?> &mdash; Operational and financial reporting hub</div>
    </div>

    <!-- Date range filter (shared across all tabs) -->
    <form method="get" class="rpt-filter-bar" id="dateRangeForm">
      <input type="hidden" name="tab" id="hiddenTab" value="<?php echo htmlspecialchars($active_tab); ?>">
      <label>From</label>
      <input type="date" name="date_from" id="dateFrom" value="<?php echo htmlspecialchars($date_from); ?>">
      <label>To</label>
      <input type="date" name="date_to" id="dateTo" value="<?php echo htmlspecialchars($date_to); ?>">
      <button type="submit" class="btn primary" style="padding:7px 16px;font-size:13px">
        <i class="fas fa-filter"></i> Apply
      </button>
      <!-- Quick ranges -->
      <button type="button" class="btn ghost" style="font-size:12px" onclick="setRange('today')">Today</button>
      <button type="button" class="btn ghost" style="font-size:12px" onclick="setRange('week')">This Week</button>
      <button type="button" class="btn ghost" style="font-size:12px" onclick="setRange('month')">This Month</button>
    </form>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: SALES REPORTS
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'sales'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-gas-pump"></i>
      <div>
        <h2>Sales Reports</h2>
        <div class="rpt-section-sub">Fuel sales, merchandise transactions, and daily consolidated totals</div>
      </div>
    </div>

    <!-- Sub-tabs -->
    <div class="sub-tabs">
      <button class="sub-tab active" onclick="showSalesSection('fuel',this)">
        <i class="fas fa-gas-pump"></i> Fuel Sales
      </button>
      <button class="sub-tab" onclick="showSalesSection('merch',this)">
        <i class="fas fa-shopping-bag"></i> Merchandise Sales
      </button>
      <button class="sub-tab" onclick="showSalesSection('daily',this)">
        <i class="fas fa-calendar-day"></i> Daily Summary
      </button>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a id="salesExcelBtn" href="#" class="btn-export excel" target="_blank">
        <i class="fas fa-file-csv"></i> Excel / CSV
      </a>
      <a id="salesPdfBtn" href="#" class="btn-export pdf" target="_blank">
        <i class="fas fa-file-pdf"></i> PDF
      </a>
    </div>

    <!-- Fuel Sales -->
    <div id="sales-fuel" class="sales-section">
      <div class="rpt-summary-cards" id="fuelSummaryCards"></div>
      <div class="rpt-table-wrap">
        <table class="rpt-table" id="fuelTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Fuel Type</th>
              <th class="tr">Transactions</th>
              <th class="tr">Liters Sold</th>
              <th class="tr">Revenue (&#8369;)</th>
              <th class="tr">Variance (L)</th>
            </tr>
          </thead>
          <tbody id="fuelTbody">
            <tr><td colspan="6" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
          </tbody>
          <tfoot id="fuelTfoot"></tfoot>
        </table>
      </div>
    </div>

    <!-- Merchandise Sales -->
    <div id="sales-merch" class="sales-section" style="display:none">
      <div class="rpt-summary-cards" id="merchSummaryCards"></div>
      <div class="rpt-table-wrap">
        <table class="rpt-table" id="merchTable">
          <thead>
            <tr>
              <th>Date</th>
              <th>Payment Method</th>
              <th>Status</th>
              <th class="tr">Transactions</th>
              <th class="tr">Revenue (&#8369;)</th>
              <th class="tr">Cash</th>
              <th class="tr">Card</th>
              <th class="tr">E-Wallet</th>
              <th class="tr">E-Fuel Card</th>
              <th class="tr">Credit</th>
            </tr>
          </thead>
          <tbody id="merchTbody">
            <tr><td colspan="10" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
          </tbody>
          <tfoot id="merchTfoot"></tfoot>
        </table>
      </div>
    </div>

    <!-- Daily Summary -->
    <div id="sales-daily" class="sales-section" style="display:none">
      <div class="rpt-summary-cards" id="dailySummaryCards"></div>
      <div class="rpt-table-wrap">
        <table class="rpt-table" id="dailyTable">
          <thead>
            <tr>
              <th>Date</th>
              <th class="tr">Fuel Liters Sold</th>
              <th class="tr">Fuel Revenue (&#8369;)</th>
              <th class="tr">Merch Revenue (&#8369;)</th>
              <th class="tr">Total Revenue (&#8369;)</th>
              <th>Variance</th>
            </tr>
          </thead>
          <tbody id="dailyTbody">
            <tr><td colspan="6" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
          </tbody>
          <tfoot id="dailyTfoot"></tfoot>
        </table>
      </div>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: JOB ORDERS
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'job_orders'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-wrench"></i>
      <div>
        <h2>Job Orders Report</h2>
        <div class="rpt-section-sub">Customer, service type, status, cost, staff, technician, and date</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_job_orders&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_job_orders&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="joSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Service Type</th>
            <th>Description</th>
            <th>Status</th>
            <th class="tr">Cost (&#8369;)</th>
            <th>Staff</th>
            <th>Technician</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody id="joTbody">
          <tr><td colspan="9" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
        <tfoot id="joTfoot"></tfoot>
      </table>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: CUSTOMER BALANCES
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'balances'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-balance-scale"></i>
      <div>
        <h2>Customer Balances Report</h2>
        <div class="rpt-section-sub">Outstanding balances, credit limits, usage, due dates, and status</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_balances&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_balances&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="balSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>Customer ID</th>
            <th>Name</th>
            <th class="tr">Outstanding Balance (&#8369;)</th>
            <th class="tr">Credit Limit (&#8369;)</th>
            <th class="tr">Credit Used (&#8369;)</th>
            <th>Due Date</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody id="balTbody">
          <tr><td colspan="8" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
        <tfoot id="balTfoot"></tfoot>
      </table>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: DELIVERIES
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'deliveries'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-truck"></i>
      <div>
        <h2>Deliveries Report</h2>
        <div class="rpt-section-sub">Delivery ID, supplier, product, quantity, encoder, status, and remarks</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_deliveries&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_deliveries&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="delSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>Delivery ID</th>
            <th>Supplier</th>
            <th>Product</th>
            <th class="tr">Quantity</th>
            <th>Date</th>
            <th>Encoder</th>
            <th>Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody id="delTbody">
          <tr><td colspan="8" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
        <tfoot id="delTfoot"></tfoot>
      </table>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: STAFF PERFORMANCE
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'staff'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-users"></i>
      <div>
        <h2>Staff Performance Report</h2>
        <div class="rpt-section-sub">Transactions, job orders, deliveries, and activity summary per staff member</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_staff&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_staff&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="staffSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>Staff ID</th>
            <th>Name</th>
            <th>Role</th>
            <th class="tr">Transactions</th>
            <th class="tr">Job Orders</th>
            <th class="tr">Deliveries</th>
            <th class="tr">Shifts Logged</th>
            <th>Performance</th>
          </tr>
        </thead>
        <tbody id="staffTbody">
          <tr><td colspan="8" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: VARIANCE REPORTS
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'variance'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-balance-scale"></i>
      <div>
        <h2>Variance Reports</h2>
        <div class="rpt-section-sub">Reconciliation logs for expected vs actual stock levels and fuel pump variance</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_variance&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_variance&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="varSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>Report Date</th>
            <th>Fuel Type</th>
            <th class="tr">Expected Stock (L)</th>
            <th class="tr">Actual Stock (L)</th>
            <th class="tr">Variance (L)</th>
            <th class="tr">Variance (%)</th>
            <th>Reason</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="varTbody">
          <tr><td colspan="8" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

  <!-- ════════════════════════════════════════════════════════════════════════
       SECTION: ACCOUNTS RECEIVABLE
  ═══════════════════════════════════════════════════════════════════════════ -->
  <?php if ($active_tab === 'receivable'): ?>

    <div class="rpt-section-head">
      <i class="fas fa-file-invoice-dollar"></i>
      <div>
        <h2>Accounts Receivable Report</h2>
        <div class="rpt-section-sub">Detailed log of credit transactions, due dates, and settlement status</div>
      </div>
    </div>

    <div class="export-bar">
      <span class="lbl"><i class="fas fa-download"></i> Export:</span>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_receivable&format=csv&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export excel" target="_blank"><i class="fas fa-file-csv"></i> Excel / CSV</a>
      <a href="<?php echo '../backend/api/admin_reports_audit_api.php?action=export_receivable&format=pdf&date_from='.urlencode($date_from).'&date_to='.urlencode($date_to); ?>"
         class="btn-export pdf" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
    <div class="rpt-summary-cards" id="arSummaryCards"></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>Created Date</th>
            <th>Transaction ID</th>
            <th>Customer Name</th>
            <th>Details</th>
            <th class="tr">Amount (&#8369;)</th>
            <th>Due Date</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="arTbody">
          <tr><td colspan="7" class="rpt-loading"><i class="fas fa-spinner fa-spin"></i>Loading...</td></tr>
        </tbody>
      </table>
    </div>

  <?php endif; ?>

</div><!-- /.page-content rpt-page -->

<script>
(function () {
'use strict';

const API   = '../backend/api/admin_reports_audit_api.php';
const FROM  = document.getElementById('dateFrom').value;
const TO    = document.getElementById('dateTo').value;
const ACTIVE_TAB = '<?php echo $active_tab; ?>';

function fmt(n, dec = 2) {
    return parseFloat(n || 0).toLocaleString('en-PH', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec
    });
}
function fmtInt(n) { return parseInt(n || 0).toLocaleString('en-PH'); }
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
function statusBadge(s) {
    const sl = (s || '').toLowerCase();
    let cls = 'pending';
    if (['approved','validated','completed','active','success','verified','settled','paid'].includes(sl)) cls = 'approved';
    else if (['rejected','failed','cancelled','overdue','over limit','over-limit'].includes(sl)) cls = 'rejected';
    else if (['in progress','in-progress'].includes(sl)) cls = 'in-progress';
    return `<span class="badge-status ${cls}">${esc(s)}</span>`;
}
function perfLabel(txn, jo, del) {
    const total = txn + jo + del;
    if (total >= 50) return '<span style="color:#16a34a;font-weight:600"><i class="fas fa-star"></i> Excellent</span>';
    if (total >= 20) return '<span style="color:#2563eb;font-weight:600"><i class="fas fa-thumbs-up"></i> Good</span>';
    if (total >= 5)  return '<span style="color:#d97706;font-weight:600"><i class="fas fa-minus"></i> Average</span>';
    return '<span style="color:#94a3b8"><i class="fas fa-circle"></i> Low</span>';
}
function summaryCard(label, value, sub = '') {
    return `<div class="rpt-summary-card">
        <div class="sc-label">${label}</div>
        <div class="sc-value">${value}</div>
        ${sub ? `<div class="sc-sub">${sub}</div>` : ''}
    </div>`;
}
function emptyRow(cols, msg = 'No data found for this period.') {
    return `<tr><td colspan="${cols}" class="rpt-empty"><i class="fas fa-inbox"></i>${msg}</td></tr>`;
}
function apiUrl(action, extra = {}) {
    const p = new URLSearchParams({ action, date_from: FROM, date_to: TO, ...extra });
    return `${API}?${p}`;
}

function apiFetch(url) {
    return fetch(url)
        .then(r => r.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                const firstLine = text.replace(/<[^>]+>/g, ' ').trim().split('\n')
                    .map(l => l.trim()).filter(l => l.length > 0)[0] || 'Server error';
                return { ok: false, error: firstLine.substring(0, 120) };
            }
        }))
        .catch(err => ({ ok: false, error: err.message || 'Network error' }));
}

window.setRange = function (range) {
    const today = new Date();
    let from, to = today.toISOString().slice(0, 10);
    if (range === 'today') {
        from = to;
    } else if (range === 'week') {
        const d = new Date(today);
        d.setDate(d.getDate() - d.getDay() + 1);
        from = d.toISOString().slice(0, 10);
    } else if (range === 'month') {
        from = today.toISOString().slice(0, 8) + '01';
    }
    document.getElementById('dateFrom').value = from;
    document.getElementById('dateTo').value   = to;
    document.getElementById('dateRangeForm').submit();
};

// Sales Tab handling
let currentSalesSection = 'fuel';
window.showSalesSection = function (section, btn) {
    document.querySelectorAll('.sales-section').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.sub-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('sales-' + section).style.display = '';
    btn.classList.add('active');
    currentSalesSection = section;
    updateSalesExportLinks();
};
function updateSalesExportLinks() {
    const excelBtn = document.getElementById('salesExcelBtn');
    const pdfBtn   = document.getElementById('salesPdfBtn');
    if (!excelBtn || !pdfBtn) return;
    const base = `${API}?action=export_sales&date_from=${FROM}&date_to=${TO}`;
    excelBtn.href = base + '&format=csv';
    pdfBtn.href   = base + '&format=pdf';
}

function loadFuelSales() {
    apiFetch(apiUrl('sales_fuel'))
        .then(res => {
            if (!res.ok) { document.getElementById('fuelTbody').innerHTML = emptyRow(6, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('fuelTbody').innerHTML = emptyRow(6); return; }

            let html = '', totLiters = 0, totRev = 0, totTxn = 0;
            rows.forEach(r => {
                const v = parseFloat(r.avg_variance || 0);
                const vCls = v > 0 ? 'var-pos' : v < 0 ? 'var-neg' : 'var-ok';
                const vStr = (v > 0 ? '+' : '') + fmt(v) + ' L';
                totLiters += parseFloat(r.total_liters || 0);
                totRev    += parseFloat(r.total_revenue || 0);
                totTxn    += parseInt(r.txn_count || 0);
                html += `<tr>
                    <td>${esc(r.sale_date)}</td>
                    <td><strong>${esc(r.fuel_type)}</strong></td>
                    <td class="tr">${fmtInt(r.txn_count)}</td>
                    <td class="tr">${fmt(r.total_liters)} L</td>
                    <td class="tr"><strong>&#8369;${fmt(r.total_revenue)}</strong></td>
                    <td class="tr ${vCls}">${vStr}</td>
                </tr>`;
            });
            document.getElementById('fuelTbody').innerHTML = html;
            document.getElementById('fuelTfoot').innerHTML = `<tr>
                <td colspan="2"><strong>TOTAL</strong></td>
                <td class="tr"><strong>${fmtInt(totTxn)}</strong></td>
                <td class="tr"><strong>${fmt(totLiters)} L</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totRev)}</strong></td>
                <td></td>
            </tr>`;
            document.getElementById('fuelSummaryCards').innerHTML =
                summaryCard('Total Transactions', fmtInt(totTxn)) +
                summaryCard('Total Liters Sold', fmt(totLiters) + ' L') +
                summaryCard('Total Fuel Revenue', '&#8369;' + fmt(totRev));
        })
        .catch(e => { document.getElementById('fuelTbody').innerHTML = emptyRow(6, 'Error: ' + (e.message||e)); });
}

function loadMerchSales() {
    apiFetch(apiUrl('sales_merch'))
        .then(res => {
            if (!res.ok) { document.getElementById('merchTbody').innerHTML = emptyRow(10, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('merchTbody').innerHTML = emptyRow(10); return; }

            let html = '', totRev = 0, totTxn = 0, totCash = 0, totCard = 0, totEw = 0, totEf = 0, totCr = 0;
            rows.forEach(r => {
                totRev  += parseFloat(r.total_revenue || 0);
                totTxn  += parseInt(r.txn_count || 0);
                totCash += parseFloat(r.pay_cash || 0);
                totCard += parseFloat(r.pay_card || 0);
                totEw   += parseFloat(r.pay_ewallet || 0);
                totEf   += parseFloat(r.pay_efuel || 0);
                totCr   += parseFloat(r.pay_credit || 0);
                html += `<tr>
                    <td>${esc(r.sale_date)}</td>
                    <td>${esc(r.payment_method || 'Mixed')}</td>
                    <td>${statusBadge(r.validation_status || 'Pending')}</td>
                    <td class="tr">${fmtInt(r.txn_count)}</td>
                    <td class="tr"><strong>&#8369;${fmt(r.total_revenue)}</strong></td>
                    <td class="tr">&#8369;${fmt(r.pay_cash)}</td>
                    <td class="tr">&#8369;${fmt(r.pay_card)}</td>
                    <td class="tr">&#8369;${fmt(r.pay_ewallet)}</td>
                    <td class="tr">&#8369;${fmt(r.pay_efuel)}</td>
                    <td class="tr">&#8369;${fmt(r.pay_credit)}</td>
                </tr>`;
            });
            document.getElementById('merchTbody').innerHTML = html;
            document.getElementById('merchTfoot').innerHTML = `<tr>
                <td colspan="3"><strong>TOTAL</strong></td>
                <td class="tr"><strong>${fmtInt(totTxn)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totRev)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totCash)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totCard)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totEw)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totEf)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totCr)}</strong></td>
            </tr>`;
            document.getElementById('merchSummaryCards').innerHTML =
                summaryCard('Total Transactions', fmtInt(totTxn)) +
                summaryCard('Total Revenue', '&#8369;' + fmt(totRev)) +
                summaryCard('Cash', '&#8369;' + fmt(totCash)) +
                summaryCard('Card', '&#8369;' + fmt(totCard)) +
                summaryCard('E-Wallet', '&#8369;' + fmt(totEw)) +
                summaryCard('Credit', '&#8369;' + fmt(totCr));
        })
        .catch(e => { document.getElementById('merchTbody').innerHTML = emptyRow(10, 'Error: ' + (e.message||e)); });
}

function loadDailySummary() {
    apiFetch(apiUrl('sales_daily_summary'))
        .then(res => {
            if (!res.ok) { document.getElementById('dailyTbody').innerHTML = emptyRow(6, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('dailyTbody').innerHTML = emptyRow(6); return; }

            let html = '', totFuelL = 0, totFuelR = 0, totMerch = 0, totAll = 0;
            rows.forEach(r => {
                const v = parseFloat(r.fuel_variance || 0);
                const vHtml = v !== 0
                    ? `<span class="${v > 0 ? 'var-pos' : 'var-neg'}">Variance: ${(v > 0 ? '+' : '')}${fmt(v)} L</span>`
                    : '<span class="var-ok">No variance</span>';
                totFuelL += parseFloat(r.total_fuel_liters || 0);
                totFuelR += parseFloat(r.fuel_revenue || 0);
                totMerch += parseFloat(r.merch_revenue || 0);
                totAll   += parseFloat(r.total_revenue || 0);
                html += `<tr>
                    <td><strong>${esc(r.sale_date)}</strong></td>
                    <td class="tr">${fmt(r.total_fuel_liters)} L</td>
                    <td class="tr"><strong>&#8369;${fmt(r.fuel_revenue)}</strong></td>
                    <td class="tr"><strong>&#8369;${fmt(r.merch_revenue)}</strong></td>
                    <td class="tr" style="color:var(--blue);font-weight:700">&#8369;${fmt(r.total_revenue)}</td>
                    <td>${vHtml}</td>
                </tr>`;
            });
            document.getElementById('dailyTbody').innerHTML = html;
            document.getElementById('dailyTfoot').innerHTML = `<tr>
                <td><strong>TOTAL</strong></td>
                <td class="tr"><strong>${fmt(totFuelL)} L</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totFuelR)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totMerch)}</strong></td>
                <td class="tr" style="color:var(--blue)"><strong>&#8369;${fmt(totAll)}</strong></td>
                <td></td>
            </tr>`;
            document.getElementById('dailySummaryCards').innerHTML =
                summaryCard('Days with Sales', fmtInt(rows.length)) +
                summaryCard('Total Fuel Liters', fmt(totFuelL) + ' L') +
                summaryCard('Total Fuel Revenue', '&#8369;' + fmt(totFuelR)) +
                summaryCard('Total Merch Revenue', '&#8369;' + fmt(totMerch)) +
                summaryCard('Combined Revenue', '&#8369;' + fmt(totAll));
        })
        .catch(e => { document.getElementById('dailyTbody').innerHTML = emptyRow(6, 'Error: ' + (e.message||e)); });
}

function loadJobOrders() {
    apiFetch(apiUrl('job_orders'))
        .then(res => {
            if (!res.ok) { document.getElementById('joTbody').innerHTML = emptyRow(9, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('joTbody').innerHTML = emptyRow(9); return; }

            let html = '', totCost = 0;
            rows.forEach(r => {
                totCost += parseFloat(r.cost || 0);
                html += `<tr>
                    <td><strong>#${esc(r.id)}</strong></td>
                    <td>${esc(r.customer_name)}</td>
                    <td>${esc(r.service_type)}</td>
                    <td style="max-width:180px;word-break:break-word">${esc(r.description)}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td class="tr"><strong>&#8369;${fmt(r.cost)}</strong></td>
                    <td>${esc(r.staff_name)}</td>
                    <td>${esc(r.technician_name)}</td>
                    <td>${esc(r.order_date)}</td>
                </tr>`;
            });
            document.getElementById('joTbody').innerHTML = html;
            document.getElementById('joTfoot').innerHTML = `<tr>
                <td colspan="5"><strong>TOTAL (${fmtInt(rows.length)} records)</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totCost)}</strong></td>
                <td colspan="3"></td>
            </tr>`;
            document.getElementById('joSummaryCards').innerHTML =
                summaryCard('Total Job Orders', fmtInt(rows.length)) +
                summaryCard('Total Cost', '&#8369;' + fmt(totCost)) +
                summaryCard('Completed', fmtInt(rows.filter(r => r.status?.toLowerCase() === 'completed').length)) +
                summaryCard('Pending', fmtInt(rows.filter(r => r.status?.toLowerCase() === 'pending').length));
        })
        .catch(e => { document.getElementById('joTbody').innerHTML = emptyRow(9, 'Error: ' + (e.message||e)); });
}

function loadBalances() {
    apiFetch(apiUrl('customer_balances'))
        .then(res => {
            if (!res.ok) { document.getElementById('balTbody').innerHTML = emptyRow(8, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('balTbody').innerHTML = emptyRow(8, 'No outstanding customer balances.'); return; }

            let html = '', totBal = 0, totLimit = 0, totUsed = 0;
            rows.forEach(r => {
                totBal   += parseFloat(r.outstanding_balance || 0);
                totLimit += parseFloat(r.credit_limit || 0);
                totUsed  += parseFloat(r.credit_used || 0);
                const overLimit = parseFloat(r.credit_used || 0) > parseFloat(r.credit_limit || 0);
                html += `<tr>
                    <td><strong>${esc(r.id)}</strong></td>
                    <td>${esc(r.name)}</td>
                    <td class="tr" style="${overLimit ? 'color:#dc2626;font-weight:700' : ''}">&#8369;${fmt(r.outstanding_balance)}</td>
                    <td class="tr">&#8369;${fmt(r.credit_limit)}</td>
                    <td class="tr">&#8369;${fmt(r.credit_used)}</td>
                    <td>${esc(r.due_date || '—')}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td style="max-width:160px;word-break:break-word">${esc(r.remarks || '—')}</td>
                </tr>`;
            });
            document.getElementById('balTbody').innerHTML = html;
            document.getElementById('balTfoot').innerHTML = `<tr>
                <td colspan="2"><strong>TOTAL (${fmtInt(rows.length)} customers)</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totBal)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totLimit)}</strong></td>
                <td class="tr"><strong>&#8369;${fmt(totUsed)}</strong></td>
                <td colspan="3"></td>
            </tr>`;
            document.getElementById('balSummaryCards').innerHTML =
                summaryCard('Customers with Balance', fmtInt(rows.length)) +
                summaryCard('Total Outstanding', '&#8369;' + fmt(totBal)) +
                summaryCard('Total Credit Limit', '&#8369;' + fmt(totLimit)) +
                summaryCard('Total Credit Used', '&#8369;' + fmt(totUsed));
        })
        .catch(e => { document.getElementById('balTbody').innerHTML = emptyRow(8, 'Error: ' + (e.message||e)); });
}

function loadDeliveries() {
    apiFetch(apiUrl('deliveries'))
        .then(res => {
            if (!res.ok) { document.getElementById('delTbody').innerHTML = emptyRow(8, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('delTbody').innerHTML = emptyRow(8); return; }

            let html = '', totQty = 0;
            rows.forEach(r => {
                totQty += parseFloat(r.quantity || 0);
                html += `<tr>
                    <td><strong>#${esc(r.id)}</strong></td>
                    <td>${esc(r.supplier)}</td>
                    <td>${esc(r.product)}</td>
                    <td class="tr">${fmt(r.quantity)} ${esc(r.unit)}</td>
                    <td>${esc(r.delivery_date)}</td>
                    <td>${esc(r.encoder_name || '—')}</td>
                    <td>${statusBadge(r.status)}</td>
                    <td style="max-width:160px;word-break:break-word">${esc(r.remarks || '—')}</td>
                </tr>`;
            });
            document.getElementById('delTbody').innerHTML = html;
            document.getElementById('delTfoot').innerHTML = `<tr>
                <td colspan="3"><strong>TOTAL (${fmtInt(rows.length)} deliveries)</strong></td>
                <td class="tr"><strong>${fmt(totQty)}</strong></td>
                <td colspan="4"></td>
            </tr>`;
            const approved = rows.filter(r => ['validated','confirmed','approved'].includes((r.status||'').toLowerCase())).length;
            const pending  = rows.filter(r => (r.status||'').toLowerCase().includes('pending')).length;
            document.getElementById('delSummaryCards').innerHTML =
                summaryCard('Total Deliveries', fmtInt(rows.length)) +
                summaryCard('Approved', fmtInt(approved)) +
                summaryCard('Pending', fmtInt(pending)) +
                summaryCard('Total Quantity', fmt(totQty));
        })
        .catch(e => { document.getElementById('delTbody').innerHTML = emptyRow(8, 'Error: ' + (e.message||e)); });
}

function loadStaffPerformance() {
    apiFetch(apiUrl('staff_performance'))
        .then(res => {
            if (!res.ok) { document.getElementById('staffTbody').innerHTML = emptyRow(8, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('staffTbody').innerHTML = emptyRow(8); return; }

            let html = '', totTxn = 0, totJo = 0, totDel = 0;
            rows.forEach(r => {
                const txn = parseInt(r.fuel_txn_count || 0) + parseInt(r.merch_txn_count || 0);
                totTxn += txn;
                totJo  += parseInt(r.job_orders || 0);
                totDel += parseInt(r.deliveries || 0);
                html += `<tr>
                    <td><strong>${esc(r.id)}</strong></td>
                    <td>${esc(r.name)}</td>
                    <td><span class="badge-status approved">${esc(r.role)}</span></td>
                    <td class="tr">${fmtInt(txn)}<br><small style="color:var(--muted)">${fmtInt(r.fuel_txn_count)} fuel · ${fmtInt(r.merch_txn_count)} merch</small></td>
                    <td class="tr">${fmtInt(r.job_orders)}</td>
                    <td class="tr">${fmtInt(r.deliveries)}</td>
                    <td class="tr">${fmtInt(r.shift_count || 0)}</td>
                    <td>${perfLabel(txn, parseInt(r.job_orders||0), parseInt(r.deliveries||0))}</td>
                </tr>`;
            });
            document.getElementById('staffTbody').innerHTML = html;
            document.getElementById('staffSummaryCards').innerHTML =
                summaryCard('Active Staff', fmtInt(rows.length)) +
                summaryCard('Total Transactions', fmtInt(totTxn)) +
                summaryCard('Total Job Orders', fmtInt(totJo)) +
                summaryCard('Total Deliveries', fmtInt(totDel));
        })
        .catch(e => { document.getElementById('staffTbody').innerHTML = emptyRow(8, 'Error: ' + (e.message||e)); });
}

function loadVarianceReports() {
    apiFetch(apiUrl('variance_reports'))
        .then(res => {
            if (!res.ok) { document.getElementById('varTbody').innerHTML = emptyRow(8, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('varTbody').innerHTML = emptyRow(8, 'No variance records found.'); return; }

            let html = '', totExp = 0, totAct = 0, totVar = 0;
            rows.forEach(r => {
                const exp = parseFloat(r.expected_stock || 0);
                const act = parseFloat(r.actual_stock || 0);
                const v = parseFloat(r.variance_liters || 0);
                totExp += exp;
                totAct += act;
                totVar += v;

                const vCls = v > 0 ? 'var-pos' : (v < 0 ? 'var-neg' : 'var-ok');
                const vStr = (v > 0 ? '+' : '') + fmt(v) + ' L';

                html += `<tr>
                    <td><strong>${esc(r.report_date)}</strong></td>
                    <td>${esc(r.fuel_type)}</td>
                    <td class="tr">${fmt(exp)} L</td>
                    <td class="tr">${fmt(act)} L</td>
                    <td class="tr ${vCls}">${vStr}</td>
                    <td class="tr">${fmt(r.variance_percent)}%</td>
                    <td>${esc(r.reason)}</td>
                    <td>${statusBadge(r.status)}</td>
                </tr>`;
            });
            document.getElementById('varTbody').innerHTML = html;
            
            const openAlerts = rows.filter(r => ['open', 'under investigation'].includes((r.status||'').toLowerCase())).length;
            document.getElementById('varSummaryCards').innerHTML =
                summaryCard('Total Logs', fmtInt(rows.length)) +
                summaryCard('Expected Vol', fmt(totExp) + ' L') +
                summaryCard('Actual Vol', fmt(totAct) + ' L') +
                summaryCard('Total Variance', fmt(totVar) + ' L') +
                summaryCard('Open Inquiries', fmtInt(openAlerts));
        })
        .catch(e => { document.getElementById('varTbody').innerHTML = emptyRow(8, 'Error: ' + (e.message||e)); });
}

function loadAccountsReceivable() {
    apiFetch(apiUrl('accounts_receivable'))
        .then(res => {
            if (!res.ok) { document.getElementById('arTbody').innerHTML = emptyRow(7, res.error); return; }
            const rows = res.data || [];
            if (!rows.length) { document.getElementById('arTbody').innerHTML = emptyRow(7, 'No accounts receivable records.'); return; }

            let html = '', totAmt = 0;
            rows.forEach(r => {
                const amt = parseFloat(r.amount || 0);
                totAmt += amt;
                html += `<tr>
                    <td><strong>${esc(r.created_date)}</strong></td>
                    <td><code>${esc(r.transaction_id)}</code></td>
                    <td>${esc(r.customer_name)}</td>
                    <td>${esc(r.type_details)}</td>
                    <td class="tr"><strong>&#8369;${fmt(amt)}</strong></td>
                    <td>${esc(r.due_date)}</td>
                    <td>${statusBadge(r.status)}</td>
                </tr>`;
            });
            document.getElementById('arTbody').innerHTML = html;

            const pendingAmt = rows.reduce((acc, curr) => acc + (['pending','overdue'].includes(curr.status?.toLowerCase()) ? parseFloat(curr.amount||0) : 0), 0);
            const overdueAmt = rows.reduce((acc, curr) => acc + (curr.status?.toLowerCase() === 'overdue' ? parseFloat(curr.amount||0) : 0), 0);

            document.getElementById('arSummaryCards').innerHTML =
                summaryCard('Total Receivables', '&#8369;' + fmt(totAmt)) +
                summaryCard('Pending Collections', '&#8369;' + fmt(pendingAmt)) +
                summaryCard('Overdue Credit', '&#8369;' + fmt(overdueAmt));
        })
        .catch(e => { document.getElementById('arTbody').innerHTML = emptyRow(7, 'Error: ' + (e.message||e)); });
}

if (ACTIVE_TAB === 'sales') {
    updateSalesExportLinks();
    loadFuelSales();
    loadMerchSales();
    loadDailySummary();
} else if (ACTIVE_TAB === 'job_orders') {
    loadJobOrders();
} else if (ACTIVE_TAB === 'balances') {
    loadBalances();
} else if (ACTIVE_TAB === 'deliveries') {
    loadDeliveries();
} else if (ACTIVE_TAB === 'staff') {
    loadStaffPerformance();
} else if (ACTIVE_TAB === 'variance') {
    loadVarianceReports();
} else if (ACTIVE_TAB === 'receivable') {
    loadAccountsReceivable();
}

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
