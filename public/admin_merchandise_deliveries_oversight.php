<?php
$page_id = 'deliveries_oversight';
$status_param = $_GET['status'] ?? '';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin access required.';
    header('Location: dashboard.php');
    exit;
}
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

$station_name = 'Unknown Station';
try {
    $s = $pdo->prepare('SELECT name FROM stations WHERE id = ? LIMIT 1');
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

// Fetch unique suppliers for merchandise deliveries
$suppliers = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT supplier FROM deliveries_oversight WHERE delivery_type = 'merchandise' AND supplier IS NOT NULL AND supplier != '' ORDER BY supplier");
    $suppliers = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Fetch unique categories for merchandise deliveries
$categories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM deliveries_oversight WHERE delivery_type = 'merchandise' AND category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root {
    --blue: #002F70;
    --red: #dc3545;
    --green: #28a745;
    --orange: #fd7e14;
    --purple: #7c3aed;
    --gray: #64748b;
    --light: #f8fafc;
    --border: #e2e8f0;
}

/* == Page Header Layout == */
.int-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
    margin-top: -12px !important;
}
.int-head h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--blue) !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    display: flex;
    align-items: center;
    gap: 8px;
}
.int-head .sub {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
    text-transform: none !important;
}

/* == Summary Cards == */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.summary-card {
    background: #fff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    border-left: 5px solid var(--card-color);
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}
.summary-card-info {
    display: flex;
    flex-direction: column;
}
.summary-card-title {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.summary-card-value {
    font-size: 24px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1;
}
.summary-card-icon {
    font-size: 28px;
    color: var(--card-color);
    opacity: 0.8;
}

.card-blue { --card-color: var(--blue); }
.card-green { --card-color: var(--green); }
.card-red { --card-color: var(--red); }
.card-yellow { --card-color: var(--orange); }
.card-purple { --card-color: var(--purple); }

/* == Card & Table Container == */
.card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    margin-bottom: 24px;
    border: 1px solid var(--border);
}
.card-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.card-title {
    font-size: 15px;
    font-weight: 700;
    color: var(--blue);
    display: flex;
    align-items: center;
    gap: 8px;
}
.card-body {
    padding: 20px;
}

/* == Filter Bar == */
.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
    margin-bottom: 20px;
    background: #f8fafc;
    padding: 16px;
    border-radius: 8px;
    border: 1px solid var(--border);
}
.filter-bar .fg {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.filter-bar label {
    font-size: 11px;
    font-weight: 700;
    color: var(--gray);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}
.filter-bar input, .filter-bar select {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    background: #fff;
    min-height: 38px;
    box-sizing: border-box;
}
.filter-bar input:focus, .filter-bar select:focus {
    border-color: var(--blue);
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
}

/* == Buttons (filter bar / modals) == */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    text-decoration: none;
    min-height: 38px;
    box-sizing: border-box;
}
.btn-primary { background: var(--blue) !important; color: #fff !important; border: none !important; }
.btn-primary:hover { background: #002250 !important; }
.btn-success { background: var(--green) !important; color: #fff !important; border: none !important; }
.btn-success:hover { background: #1e7e34 !important; }
.btn-danger { background: var(--red) !important; color: #fff !important; border: none !important; }
.btn-danger:hover { background: #bd2130 !important; }
.btn-warning { background: var(--orange) !important; color: #fff !important; border: none !important; }
.btn-warning:hover { background: #d3680c !important; }
.btn-outline { background: #fff !important; color: var(--blue) !important; border: 1px solid var(--blue) !important; }
.btn-outline:hover { background: #f0f5ff !important; }
.btn-sm { padding: 6px 10px; font-size: 12px; min-height: 30px; }

/* == Action buttons — matches manager module vertical stack style == */
.act-wrap { display: flex; gap: 4px; flex-direction: column; }
.btn-act {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 6px;
    width: 100%;
    height: 26px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    background: #fff !important;
    box-sizing: border-box;
    white-space: nowrap;
    text-decoration: none;
    border: 1px solid transparent;
    overflow: hidden;
}
.btn-act:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,.08); }
.btn-act-view   { border-color: #002F70 !important; color: #002F70 !important; }
.btn-act-view:hover   { background: #002F70 !important; color: #fff !important; }
.btn-act-history{ border-color: #6f42c1 !important; color: #6f42c1 !important; }
.btn-act-history:hover{ background: #6f42c1 !important; color: #fff !important; }
.btn-act-process{ border-color: #28a745 !important; color: #28a745 !important; }
.btn-act-process:hover{ background: #28a745 !important; color: #fff !important; }
.btn-act-reopen { border-color: #fd7e14 !important; color: #fd7e14 !important; }
.btn-act-reopen:hover { background: #fd7e14 !important; color: #fff !important; }
.btn-act-print  { border-color: #6c757d !important; color: #6c757d !important; }
.btn-act-print:hover  { background: #6c757d !important; color: #fff !important; }
/* manager-compatible color aliases */
.btn-approve { border: 1px solid #28a745 !important; color: #28a745 !important; }
.btn-approve:hover { background: #28a745 !important; color: #fff !important; }
.btn-reject  { border: 1px solid #dc3545 !important; color: #dc3545 !important; }
.btn-reject:hover  { background: #dc3545 !important; color: #fff !important; }
.btn-view    { border: 1px solid #002F70 !important; color: #002F70 !important; }
.btn-view:hover    { background: #002F70 !important; color: #fff !important; }

/* == Filter bar buttons — matches manager module .flt-btn style == */
.flt-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    white-space: nowrap;
    transition: all .15s;
    background: white !important;
    border: 1px solid transparent;
    box-sizing: border-box;
}
.flt-btn-search { color: #00264D !important; border-color: #00264D !important; }
.flt-btn-search:hover { background: #00264D !important; color: #fff !important; }
.flt-btn-reset  { color: #6b7280 !important; border-color: #6b7280 !important; }
.flt-btn-reset:hover  { background: #6b7280 !important; color: #fff !important; }
.flt-btn-excel  { color: #1d6f42 !important; border-color: #1d6f42 !important; }
.flt-btn-excel:hover  { background: #1d6f42 !important; color: #fff !important; }
.flt-btn-pdf    { color: #dc2626 !important; border-color: #dc2626 !important; }
.flt-btn-pdf:hover    { background: #dc2626 !important; color: #fff !important; }

/* == Table — fixed layout, no horizontal scroll == */
.table-wrap { width: 100%; overflow: visible; }
body { overflow-x: hidden !important; max-width: 100vw !important; }
table.dt {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    table-layout: fixed;
}
table.dt th {
    background: var(--blue);
    color: #fff;
    padding: 8px 6px;
    text-align: left;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border: none;
    word-wrap: break-word;
    overflow: hidden;
    text-overflow: ellipsis;
}
table.dt td {
    padding: 8px 6px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    word-wrap: break-word;
    word-break: break-word;
}
table.dt tr:hover td { background: #f1f7ff; }

/* == Badges == */
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
}
.badge-expected { background: #e0f2fe; color: #0369a1; }
.badge-pending { background: #fef3c7; color: #b45309; }
.badge-approved { background: #d1fae5; color: #065f46; }
.badge-flagged { background: #fee2e2; color: #b91c1c; }
.badge-partial { background: #ffedd5; color: #c2410c; }
.badge-damaged { background: #fee2e2; color: #991b1b; }
.badge-rejected { background: #f1f5f9; color: #475569; }

/* == Empty State == */
.empty-state {
    text-align: center;
    padding: 48px 20px;
    color: var(--gray);
}
.empty-state i {
    font-size: 40px;
    margin-bottom: 12px;
    opacity: 0.5;
    display: block;
}

/* == Toast Notification == */
.toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    padding: 14px 20px;
    border-radius: 8px;
    color: #fff;
    font-weight: 600;
    font-size: 13px;
    z-index: 9999;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
    display: none;
}
.toast.show { display: block; animation: slideUp 0.3s ease; }
.toast-success { background: var(--green); }
.toast-error { background: var(--red); }
@keyframes slideUp {
    from { transform: translateY(20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* == Modal Framework == */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1050;
    opacity: 0;
    transition: opacity 0.15s linear;
}
.modal.show { display: flex; opacity: 1; }
.modal-dialog {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    animation: modalSlide 0.3s ease-out;
}
.modal-dialog-large { max-width: 800px; }
@keyframes modalSlide {
    from { transform: translateY(-30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-title {
    font-size: 16px;
    font-weight: 700;
    color: var(--blue);
    margin: 0;
}
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: var(--gray);
    cursor: pointer;
    line-height: 1;
}
.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
.modal-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* == Details UI == */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 16px;
}
.detail-item {
    background: #f8fafc;
    padding: 12px 14px;
    border-radius: 8px;
    border-left: 4px solid var(--blue);
}
.detail-label {
    font-size: 10px;
    font-weight: 700;
    color: var(--gray);
    text-transform: uppercase;
    margin-bottom: 4px;
    letter-spacing: 0.5px;
}
.detail-value {
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
}

/* == Audit Logs == */
.audit-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.audit-item {
    background: #f8fafc;
    border-radius: 8px;
    padding: 12px 16px;
    border-left: 4px solid #cbd5e1;
}
.audit-item.success { border-left-color: var(--green); }
.audit-item.warning { border-left-color: var(--orange); }
.audit-item.danger { border-left-color: var(--red); }
.audit-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--gray);
    margin-bottom: 6px;
}
.audit-action { font-weight: 700; color: #1e293b; }
.audit-details { font-size: 12px; color: #475569; line-height: 1.5; }

.text-truncate {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<div class="int-head">
  <div>
    <h1><i class="fas fa-truck"></i> Merchandise Deliveries Oversight</h1>
    <div class="sub">
      Verify, validate, and monitor supplier merchandise deliveries, track quantities, and process payment calculations.
    </div>
  </div>
  <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <button class="flt-btn flt-btn-reset" onclick="loadDeliveries()"><i class="fas fa-sync-alt"></i> Refresh</button>
  </div>
</div>

<!-- Summary Cards Grid -->
<div class="summary-grid">
  <!-- Total Deliveries Card (Blue) -->
  <div class="summary-card card-blue">
    <div class="summary-card-info">
      <span class="summary-card-title">Total Deliveries</span>
      <span class="summary-card-value" id="statTotal">0</span>
    </div>
    <div class="summary-card-icon"><i class="fas fa-truck-loading"></i></div>
  </div>
  
  <!-- Verified Deliveries Card (Green) -->
  <div class="summary-card card-green">
    <div class="summary-card-info">
      <span class="summary-card-title">Verified Deliveries</span>
      <span class="summary-card-value" id="statVerified">0</span>
    </div>
    <div class="summary-card-icon"><i class="fas fa-check-circle"></i></div>
  </div>
  
  <!-- Rejected Deliveries Card (Red) -->
  <div class="summary-card card-red">
    <div class="summary-card-info">
      <span class="summary-card-title">Rejected Deliveries</span>
      <span class="summary-card-value" id="statRejected">0</span>
    </div>
    <div class="summary-card-icon"><i class="fas fa-times-circle"></i></div>
  </div>
  
  <!-- Pending Deliveries Card (Yellow) -->
  <div class="summary-card card-yellow">
    <div class="summary-card-info">
      <span class="summary-card-title">Pending Deliveries</span>
      <span class="summary-card-value" id="statPending">0</span>
    </div>
    <div class="summary-card-icon"><i class="fas fa-clock"></i></div>
  </div>
  
  <!-- Total Items Received Card (Purple) -->
  <div class="summary-card card-purple">
    <div class="summary-card-info">
      <span class="summary-card-title">Total Items Received</span>
      <span class="summary-card-value" id="statItemsReceived">0</span>
    </div>
    <div class="summary-card-icon"><i class="fas fa-boxes"></i></div>
  </div>
</div>

<!-- Main Table Card -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-filter"></i> Search & Filters</div>
    <div id="recordCount" style="font-size:12px;color:var(--gray);">Loading…</div>
  </div>
  <div class="card-body">
    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="fg">
        <label>Date From</label>
        <input type="date" id="fStart" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
      </div>
      <div class="fg">
        <label>Date To</label>
        <input type="date" id="fEnd" value="<?php echo date('Y-m-d'); ?>">
      </div>
      <div class="fg">
        <label>Supplier</label>
        <select id="fSupplier" style="min-width: 150px;">
          <option value="">All Suppliers</option>
          <?php foreach ($suppliers as $sup): ?>
            <option value="<?php echo htmlspecialchars($sup); ?>"><?php echo htmlspecialchars($sup); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Category</label>
        <select id="fCategory" style="min-width: 140px;">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Status</label>
        <select id="fStatus" style="min-width: 150px;">
          <option value="">All Statuses</option>
          <option value="expected">Expected Delivery</option>
          <option value="pending">Pending Validation</option>
          <option value="approved">Cleared / Validated</option>
          <option value="flagged">Flagged / Discrepancy</option>
          <option value="partial">Partial Delivery</option>
          <option value="damaged">Damaged Items</option>
          <option value="rejected">Rejected Delivery</option>
        </select>
      </div>
      <div class="fg">
        <label>DR Number</label>
        <input type="text" id="fDrNumber" placeholder="Search DR Number…">
      </div>
      <div style="margin-top:auto; display:flex; gap:8px; align-items:center;">
        <button class="flt-btn flt-btn-search" onclick="loadDeliveries()"><i class="fas fa-search"></i> Search</button>
        <button class="flt-btn flt-btn-reset" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
      </div>
      <div style="margin-top:auto; margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <button class="flt-btn flt-btn-excel" onclick="exportReport('excel')"><i class="fas fa-file-excel"></i> Export Excel</button>
        <button class="flt-btn flt-btn-pdf" onclick="exportReport('pdf')"><i class="fas fa-file-pdf"></i> Export PDF</button>
      </div>
    </div>
    
    <!-- Table -->
    <div class="table-wrap">
      <table class="dt" id="del-table">
        <colgroup>
          <col style="width:7%">  <!-- Delivery ID -->
          <col style="width:5%">  <!-- Date -->
          <col style="width:6%">  <!-- Batch ID -->
          <col style="width:6%">  <!-- DR No. -->
          <col style="width:9%">  <!-- Supplier -->
          <col style="width:10%"> <!-- Item Name -->
          <col style="width:6%">  <!-- Category -->
          <col style="width:5%">  <!-- Qty -->
          <col style="width:3%">  <!-- Unit -->
          <col style="width:6%">  <!-- Staff -->
          <col style="width:6%">  <!-- Manager -->
          <col style="width:7%">  <!-- Status -->
          <col style="width:7%">  <!-- Verified On -->
          <col style="width:7%">  <!-- Remarks -->
          <col style="width:9%">  <!-- Actions -->
        </colgroup>
        <thead>
          <tr>
            <th>Delivery ID</th>
            <th>Date</th>
            <th>Batch ID</th>
            <th>DR No.</th>
            <th>Supplier</th>
            <th>Item Name</th>
            <th>Category</th>
            <th>Qty</th>
            <th>Unit</th>
            <th>Staff</th>
            <th>Manager</th>
            <th>Status</th>
            <th>Verified On</th>
            <th>Remarks</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="deliveriesBody">
          <tr><td colspan="15"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading…</div></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Details Modal -->
<div id="detailModal" class="modal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-info-circle"></i> Delivery Details</h5>
      <button class="modal-close" onclick="closeModal('detailModal')">&times;</button>
    </div>
    <div class="modal-body" id="detailContent">
      <!-- Dynamic Details -->
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('detailModal')">Close</button>
    </div>
  </div>
</div>

<!-- Audit & History Modal -->
<div id="auditModal" class="modal">
  <div class="modal-dialog modal-dialog-large">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-history"></i> Verification History & Audit Trail</h5>
      <button class="modal-close" onclick="closeModal('auditModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="auditContent">
        <!-- Dynamic Audit Trail -->
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('auditModal')">Close</button>
    </div>
  </div>
</div>

<!-- Reopen Modal -->
<div id="reopenModal" class="modal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Reopen Delivery Record</h5>
      <button class="modal-close" onclick="closeModal('reopenModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p>Are you sure you want to reopen this delivery record? This will:</p>
      <ul style="margin-left: 20px; margin-bottom: 15px;">
        <li>Reset the status to <strong>Pending Validation</strong></li>
        <li>Clear the Admin approval action, notes, and timestamp</li>
        <li>Log this action in the verification history/audit trail</li>
      </ul>
      <p style="font-weight:600; color:var(--red);">This action cannot be undone without re-validating the record.</p>
      <input type="hidden" id="reopenRecordId">
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('reopenModal')">Cancel</button>
      <button class="btn btn-danger" onclick="submitReopen()">Reopen Record</button>
    </div>
  </div>
</div>

<!-- Process Delivery Modal -->
<div id="processModal" class="modal">
  <div class="modal-dialog">
    <div class="modal-header">
      <h5 class="modal-title"><i class="fas fa-check-circle"></i> Process & Compute Payment</h5>
      <button class="modal-close" onclick="closeModal('processModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div id="processInfo" class="detail-item" style="margin-bottom:15px; border-left-color:var(--orange);">
        <!-- Delivery info -->
      </div>
      <form id="processForm" onsubmit="event.preventDefault();">
        <input type="hidden" id="proc_id">
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="fg">
            <label>Expected Qty (PO)</label>
            <input type="number" step="any" id="proc_expected" readonly style="background:#f1f5f9;">
          </div>
          <div class="fg">
            <label>Actual Received Qty</label>
            <input type="number" step="any" id="proc_actual" oninput="recalcPayment()">
          </div>
        </div>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-bottom:12px;">
          <div class="fg">
            <label>Damaged/Unusable Qty</label>
            <input type="number" step="any" id="proc_damaged" oninput="recalcPayment()">
          </div>
          <div class="fg">
            <label id="proc_price_label">Unit Price (₱)</label>
            <input type="number" step="any" id="proc_unit_price" oninput="recalcPayment()">
          </div>
        </div>
        <div class="fg" style="margin-bottom:12px;">
          <label>Discrepancy / Action Type</label>
          <select id="proc_type">
            <option value="">No Discrepancy (Standard Delivery)</option>
            <option value="Partial">Partial Delivery</option>
            <option value="Damaged">Damaged Items</option>
            <option value="Rejected">Rejected Delivery</option>
            <option value="Mixed">Mixed Discrepancy</option>
          </select>
        </div>
        <div class="fg" style="margin-bottom:15px;">
          <label>Remarks / Notes <span style="color:var(--red);">*</span></label>
          <textarea id="proc_remarks" rows="3" placeholder="Explain validation decisions, damages, shortfalls..." style="padding:8px 10px; border:1px solid #dee2e6; border-radius:6px; font-size:13px; width:100%; box-sizing:border-box;"></textarea>
        </div>
        
        <!-- Live Payment Preview -->
        <div id="paymentSummary" class="detail-item" style="border-left-color:var(--green); display:none; background:#f0fdf4;">
          <div class="detail-label" style="color:#16a34a;">Payment Computation Preview</div>
          <div style="font-size:12px; color:#374151; display:flex; flex-direction:column; gap:6px;">
            <div style="display:flex; justify-content:space-between;">
              <span>Expected Amount:</span>
              <strong id="pay_expected">₱0.00</strong>
            </div>
            <div style="display:flex; justify-content:space-between;">
              <span>Actual Received Amount (<span id="pay_actual_qty">0</span> @ ₱<span id="pay_unit_price">0</span>):</span>
              <strong id="pay_actual_amt">₱0.00</strong>
            </div>
            <div id="damagedRow" style="display:none; justify-content:space-between; color:var(--red);">
              <span>Less Damaged Items (<span id="pay_damaged_qty">0</span> @ ₱<span id="pay_damaged_price">0</span>):</span>
              <strong id="pay_damaged_amt">-₱0.00</strong>
            </div>
            <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700; border-top:1px dashed #bbf7d0; padding-top:6px; color:#16a34a;">
              <span>TOTAL PAYABLE AMOUNT:</span>
              <span>₱<span id="pay_total">0.00</span></span>
            </div>
          </div>
        </div>
        
        <div id="discrepancyAlert" class="detail-item" style="border-left-color:var(--red); display:none; background:#fef2f2; margin-top:10px;">
          <div class="detail-label" style="color:var(--red);"><i class="fas fa-exclamation-circle"></i> Discrepancy Detected</div>
          <div id="discrepancyMsg" style="font-size:11px; color:#991b1b;"></div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('processModal')">Cancel</button>
      <button class="btn btn-success" onclick="submitProcess('save')"><i class="fas fa-save"></i> Save Verification</button>
      <button class="btn btn-primary" onclick="submitProcess('print')"><i class="fas fa-print"></i> Save & Print</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const API = '../backend/api/admin_deliveries_oversight_api.php';
let currentId = null;
let currentRec = null;

// Helper: Escape HTML
function esc(s){
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Helper: Format Toast Notifications
function toast(msg, type){
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-' + (type || 'success') + ' show';
    setTimeout(function(){ t.classList.remove('show'); }, 3800);
}

// Helper: Format Date
function fmtDate(d){
    if(!d) return '—';
    try {
        return new Date(d).toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
    } catch(e) {
        return d;
    }
}

// Helper: Format Date & Time
function fmtDateTime(d){
    if(!d) return '—';
    try {
        return new Date(d).toLocaleString('en-PH', {
            month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    } catch(e) {
        return d;
    }
}

// Helper: Close Modals
function closeModal(id) {
    const el = document.getElementById(id);
    if(el) el.classList.remove('show');
}

// Load deliveries and metrics
async function loadDeliveries() {
    const start = document.getElementById('fStart').value;
    const end = document.getElementById('fEnd').value;
    const status = document.getElementById('fStatus').value;
    const supplier = document.getElementById('fSupplier').value;
    const category = document.getElementById('fCategory').value;
    const drNumber = document.getElementById('fDrNumber').value.trim();

    document.getElementById('deliveriesBody').innerHTML =
        '<tr><td colspan="15"><div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading deliveries...</div></td></tr>';

    try {
        const url = `${API}?action=list&start=${start}&end=${end}&status=${encodeURIComponent(status)}&type=merchandise&supplier=${encodeURIComponent(supplier)}&category=${encodeURIComponent(category)}&dr_number=${encodeURIComponent(drNumber)}`;
        const res = await fetch(url);
        const data = await res.json();
        
        if(!data.success) {
            toast(data.message, 'error');
            return;
        }

        const rows = data.data || [];
        document.getElementById('recordCount').textContent = rows.length + ' record(s) listed';

        if(rows.length === 0) {
            document.getElementById('deliveriesBody').innerHTML =
                '<tr><td colspan="15"><div class="empty-state"><i class="fas fa-truck"></i> No merchandise delivery records found matching the filters.</div></td></tr>';
        } else {
            document.getElementById('deliveriesBody').innerHTML = rows.map(r => buildRow(r)).join('');
        }

        // Update Summary Card Counts
        const counts = data.counts || { Total: 0, Verified: 0, Rejected: 0, Pending: 0, TotalItemsReceived: 0 };
        document.getElementById('statTotal').textContent = counts.Total;
        document.getElementById('statVerified').textContent = counts.Verified;
        document.getElementById('statRejected').textContent = counts.Rejected;
        document.getElementById('statPending').textContent = counts.Pending;
        document.getElementById('statItemsReceived').textContent = parseFloat(counts.TotalItemsReceived).toLocaleString('en-PH', { maximumFractionDigits: 1 });
        
    } catch(e) {
        toast('Error loading deliveries: ' + e.message, 'error');
    }
}

// Build table row
function buildRow(r) {
    const statusMap = {
        'Expected Delivery': 'Expected',
        'Pending Manager Approval': 'Pending Manager',
        'Pending Manager Confirmation': 'Pending Confirmation',
        'Pending Validation': 'Pending Validation',
        'Pending Admin Oversight': 'Pending Oversight',
        'Confirmed': 'Cleared',
        'Validated': 'Cleared',
        'Discrepancy': 'Flagged',
        'Flagged': 'Flagged',
        'Partial Delivery': 'Partial',
        'Damaged Items': 'Damaged',
        'Rejected Delivery': 'Rejected',
    };
    const displayStatus = statusMap[r.status] || r.status;

    const statusBadge = {
        'Expected': '<span class="badge badge-expected">Expected</span>',
        'Pending Manager': '<span class="badge badge-pending">Pending Manager</span>',
        'Pending Confirmation': '<span class="badge badge-pending">Pending Confirmation</span>',
        'Pending Validation': '<span class="badge badge-pending">Pending Validation</span>',
        'Pending Oversight': '<span class="badge badge-pending">Pending Oversight</span>',
        'Cleared': '<span class="badge badge-approved">Cleared</span>',
        'Flagged': '<span class="badge badge-flagged">Flagged</span>',
        'Partial': '<span class="badge badge-partial">Partial</span>',
        'Damaged': '<span class="badge badge-damaged">Damaged</span>',
        'Rejected': '<span class="badge badge-rejected">Rejected</span>',
    }[displayStatus] || `<span class="badge">${esc(displayStatus)}</span>`;

    const qty = parseFloat(r.actual_quantity || r.quantity || 0);
    const qtyDelivered = qty.toFixed(1);
    const category = r.category || '—';
    const managerName = r.manager_name || '—';
    const timestamp = r.manager_action_at ? fmtDateTime(r.manager_action_at) : '—';
    const remarks = r.remarks || r.manager_notes || r.admin_notes || '—';
    const staff = r.received_by_name || r.encoded_by_name || '—';
    const dateDelivered = fmtDate(r.delivery_date);
    
    // Action flags
    const canReopen = ['Confirmed', 'Validated', 'Partial Delivery', 'Damaged Items', 'Rejected Delivery', 'Flagged', 'Discrepancy'].includes(r.status);
    const canPrint = ['Confirmed', 'Validated', 'Partial Delivery', 'Damaged Items', 'Rejected Delivery', 'Flagged', 'Discrepancy'].includes(r.status);
    const canProcess = ['Pending Validation', 'Pending Admin Oversight', 'Pending Manager Confirmation'].includes(r.status);

    // Build vertical act-wrap — matched to manager_merchandise_deliveries.php pattern
    var acts = '';
    acts += `<button class="btn-act btn-view" onclick="showDetail(${r.id})"><i class="fas fa-eye"></i> View</button>`;
    acts += `<button class="btn-act" style="border:1px solid #6f42c1!important;color:#6f42c1!important;" onclick="showAudit(${r.id})" onmouseover="this.style.background='#6f42c1';this.style.color='#fff'" onmouseout="this.style.background='#fff';this.style.color='#6f42c1'"><i class="fas fa-history"></i> Log</button>`;
    if (canProcess) {
        acts += `<button class="btn-act btn-approve" onclick="openProcess(${r.id})"><i class="fas fa-check-double"></i> Verify</button>`;
    }
    if (canReopen) {
        acts += `<button class="btn-act" style="border:1px solid #fd7e14!important;color:#fd7e14!important;" onclick="confirmReopen(${r.id})" onmouseover="this.style.background='#fd7e14';this.style.color='#fff'" onmouseout="this.style.background='#fff';this.style.color='#fd7e14'"><i class="fas fa-undo"></i> Reopen</button>`;
    }
    if (canPrint) {
        acts += `<button class="btn-act" style="border:1px solid #6c757d!important;color:#6c757d!important;" onclick="printDeliveryReport(${r.id})" onmouseover="this.style.background='#6c757d';this.style.color='#fff'" onmouseout="this.style.background='#fff';this.style.color='#6c757d'"><i class="fas fa-print"></i> Print</button>`;
    }
    const actionsHtml = `<div class="act-wrap" style="flex-direction:column;gap:4px;align-items:stretch;">${acts}</div>`;

    return `<tr>
        <td><span style="font-size:10px;color:#6c757d;word-break:break-all;">${esc(r.delivery_ref)}</span></td>
        <td style="font-size:10px;">${dateDelivered}</td>
        <td><span style="font-family:monospace;font-size:10px;font-weight:700;color:#002F70;">${esc(r.batch_id||'—')}</span></td>
        <td style="font-weight:600;font-size:10px;">${esc(r.dr_number||'—')}</td>
        <td style="font-size:10px;">${esc(r.supplier)}</td>
        <td style="font-weight:600;font-size:10px;">${esc(r.product)}</td>
        <td style="font-size:10px;">${esc(category)}</td>
        <td style="font-weight:700;color:#002F70;font-size:10px;">${qtyDelivered}</td>
        <td style="font-size:10px;">${esc(r.unit)}</td>
        <td style="font-size:10px;">${esc(staff)}</td>
        <td style="font-size:10px;">${esc(managerName)}</td>
        <td>${statusBadge}</td>
        <td style="color:#6c757d;font-size:10px;">${timestamp}</td>
        <td><div style="font-size:10px;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(remarks)}">${esc(remarks)}</div></td>
        <td>${actionsHtml}</td>
    </tr>`;
}

// Reset filters
function resetFilters() {
    document.getElementById('fStart').value = "<?php echo date('Y-m-d', strtotime('-30 days')); ?>";
    document.getElementById('fEnd').value = "<?php echo date('Y-m-d'); ?>";
    document.getElementById('fSupplier').value = "";
    document.getElementById('fCategory').value = "";
    document.getElementById('fStatus').value = "";
    document.getElementById('fDrNumber').value = "";
    loadDeliveries();
}

// Export excel or pdf
function exportReport(format) {
    const start = document.getElementById('fStart').value;
    const end = document.getElementById('fEnd').value;
    const status = document.getElementById('fStatus').value;
    const supplier = document.getElementById('fSupplier').value;
    const category = document.getElementById('fCategory').value;
    const drNumber = document.getElementById('fDrNumber').value.trim();
    
    const url = `${API}?action=export_${format}&start=${start}&end=${end}&status=${encodeURIComponent(status)}&type=merchandise&supplier=${encodeURIComponent(supplier)}&category=${encodeURIComponent(category)}&dr_number=${encodeURIComponent(drNumber)}`;
    window.open(url, '_blank');
}

// Show details modal
async function showDetail(id) {
    try {
        const res = await fetch(`${API}?action=detail&id=${id}`);
        const data = await res.json();
        if (!data.success) {
            toast(data.message, 'error');
            return;
        }
        const r = data.data;
        
        let html = `
          <div class="detail-grid">
            <div class="detail-item">
              <div class="detail-label">Delivery ID</div>
              <div class="detail-value">${esc(r.delivery_ref)}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Delivery Date</div>
              <div class="detail-value">${fmtDate(r.delivery_date)}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Batch ID</div>
              <div class="detail-value">${esc(r.batch_id || '—')}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">DR Number</div>
              <div class="detail-value">${esc(r.dr_number || '—')}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Supplier</div>
              <div class="detail-value">${esc(r.supplier)}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Item Name</div>
              <div class="detail-value">${esc(r.product)}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Category</div>
              <div class="detail-value">${esc(r.category || '—')}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Status</div>
              <div class="detail-value" style="font-weight:700;">${esc(r.status)}</div>
            </div>
          </div>
          
          <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <div class="detail-item" style="border-left-color: var(--blue);">
              <div class="detail-label">Expected Qty</div>
              <div class="detail-value">${parseFloat(r.expected_quantity || r.quantity || 0).toFixed(1)} ${esc(r.unit)}</div>
            </div>
            <div class="detail-item" style="border-left-color: var(--green);">
              <div class="detail-label">Actual Received Qty</div>
              <div class="detail-value">${parseFloat(r.actual_quantity || r.quantity || 0).toFixed(1)} ${esc(r.unit)}</div>
            </div>
            <div class="detail-item" style="border-left-color: var(--red);">
              <div class="detail-label">Damaged Qty</div>
              <div class="detail-value">${parseFloat(r.damaged_quantity || 0).toFixed(1)} ${esc(r.unit)}</div>
            </div>
          </div>
          
          <div class="detail-grid" style="grid-template-columns: 1fr 1fr 1fr;">
            <div class="detail-item" style="border-left-color: var(--blue);">
              <div class="detail-label">Unit Price</div>
              <div class="detail-value">₱${parseFloat(r.unit_price || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
            </div>
            <div class="detail-item" style="border-left-color: var(--blue);">
              <div class="detail-label">Expected Amount</div>
              <div class="detail-value">₱${parseFloat(r.expected_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
            </div>
            <div class="detail-item" style="border-left-color: var(--green);">
              <div class="detail-label">Payable Amount</div>
              <div class="detail-value" style="font-size:14px; font-weight:700; color:#16a34a;">₱${parseFloat(r.payable_amount || 0).toLocaleString('en-PH', {minimumFractionDigits:2})}</div>
            </div>
          </div>
          
          <div class="detail-grid">
            <div class="detail-item">
              <div class="detail-label">Staff Receiver</div>
              <div class="detail-value">${esc(r.received_by_name || r.encoded_by_name || '—')}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Manager Verifier</div>
              <div class="detail-value">${esc(r.manager_name || '—')}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Verification Date</div>
              <div class="detail-value">${r.manager_action_at ? fmtDateTime(r.manager_action_at) : '—'}</div>
            </div>
            <div class="detail-item">
              <div class="detail-label">Admin Oversight Actor</div>
              <div class="detail-value">${esc(r.admin_name || '—')}</div>
            </div>
          </div>
          
          <div class="detail-item" style="border-left-color: var(--orange); margin-top: 10px;">
            <div class="detail-label">Remarks / Notes</div>
            <div class="detail-value" style="font-weight:normal; line-height: 1.6; white-space: pre-wrap;">${esc(r.remarks || r.manager_notes || r.admin_notes || 'No remarks provided.')}</div>
          </div>
        `;
        
        document.getElementById('detailContent').innerHTML = html;
        document.getElementById('detailModal').classList.add('show');
    } catch(e) {
        toast('Error loading details: ' + e.message, 'error');
    }
}

// Show Verification History & Audit Trail
async function showAudit(id) {
    try {
        const res = await fetch(`${API}?action=detail&id=${id}`);
        const data = await res.json();
        if (!data.success) {
            toast(data.message, 'error');
            return;
        }
        const r = data.data;
        const audit = r.audit || [];
        
        let html = `
          <div style="margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:15px;">
            <h6 style="margin:0 0 8px 0; font-size:14px; color:var(--blue);">Delivery Record: ${esc(r.delivery_ref)}</h6>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; font-size:12px; color:#475569;">
              <div><strong>Supplier:</strong> ${esc(r.supplier)}</div>
              <div><strong>Item Name:</strong> ${esc(r.product)}</div>
              <div><strong>DR Number:</strong> ${esc(r.dr_number || 'N/A')}</div>
              <div><strong>Current Status:</strong> ${esc(r.status)}</div>
            </div>
          </div>
          <h6 style="margin:0 0 12px 0; font-size:13px; font-weight:700; text-transform:uppercase; color:#64748b;">Verification History & Audit Log</h6>
        `;
        
        if (audit.length === 0) {
            html += `
              <div class="empty-state">
                <i class="fas fa-info-circle"></i> No verification history found for this delivery record.
              </div>
            `;
        } else {
            html += `<div class="audit-list">`;
            audit.forEach(item => {
                let typeClass = '';
                if (item.action_type.includes('Validate') || item.action_type.includes('Process')) {
                    typeClass = 'success';
                } else if (item.action_type.includes('Flag')) {
                    typeClass = 'warning';
                } else if (item.action_type.includes('Reopen')) {
                    typeClass = 'danger';
                }
                
                html += `
                  <div class="audit-item ${typeClass}">
                    <div class="audit-meta">
                      <span class="audit-action">${esc(item.action_type)}</span>
                      <span>${new Date(item.timestamp).toLocaleString('en-PH')}</span>
                    </div>
                    <div class="audit-details">
                      <strong>Actor:</strong> ${esc(item.actor_name)} <br>
                      <strong>Status Change:</strong> <span style="text-decoration:line-through; color:#ef4444;">${esc(item.old_value)}</span> &rarr; <span style="color:#22c55e; font-weight:600;">${esc(item.new_value)}</span>
                    </div>
                  </div>
                `;
            });
            html += `</div>`;
        }
        
        document.getElementById('auditContent').innerHTML = html;
        document.getElementById('auditModal').classList.add('show');
    } catch (e) {
        toast('Error loading audit log: ' + e.message, 'error');
    }
}

// Confirm Reopen Record
function confirmReopen(id) {
    document.getElementById('reopenRecordId').value = id;
    document.getElementById('reopenModal').classList.add('show');
}

// Submit Reopen Action
async function submitReopen() {
    const id = parseInt(document.getElementById('reopenRecordId').value);
    if (!id) return;
    
    try {
        const res = await fetch(`${API}?action=reopen`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: id })
        });
        const data = await res.json();
        
        closeModal('reopenModal');
        toast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            loadDeliveries();
        }
    } catch (e) {
        toast('Error reopening record: ' + e.message, 'error');
    }
}

// Print Delivery Report
function printDeliveryReport(id) {
    window.open(`${API}?action=print_payment_report&id=${id}`, '_blank');
}

// Open Process Delivery Verification Modal
async function openProcess(id) {
    currentId = id;
    document.getElementById('processModal').classList.add('show');
    document.getElementById('paymentSummary').style.display = 'none';
    document.getElementById('discrepancyAlert').style.display = 'none';
    
    try {
        const res = await fetch(`${API}?action=detail&id=${id}`);
        const data = await res.json();
        if (!data.success) {
            toast('Failed to load delivery details', 'error');
            return;
        }
        
        const r = data.data;
        currentRec = r;
        
        document.getElementById('processInfo').innerHTML = `
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12px;">
            <div><strong>Delivery Ref:</strong> ${esc(r.delivery_ref)}</div>
            <div><strong>DR Number:</strong> ${esc(r.dr_number || 'N/A')}</div>
            <div><strong>Supplier:</strong> ${esc(r.supplier)}</div>
            <div><strong>Product:</strong> ${esc(r.product)}</div>
            <div><strong>Unit:</strong> ${esc(r.unit)}</div>
            <div><strong>Delivery Date:</strong> ${fmtDate(r.delivery_date)}</div>
          </div>
        `;
        
        let unitPrice = 0;
        let priceSource = 'Manual Input Required';
        
        if (r.unit_price && parseFloat(r.unit_price) > 0) {
            unitPrice = parseFloat(r.unit_price);
            priceSource = 'From Delivery Record';
        }
        
        if (r.source_ref && r.source_ref !== '') {
            try {
                const poRes = await fetch(`${API}?action=get_po_price&source_ref=${encodeURIComponent(r.source_ref)}`);
                const poData = await poRes.json();
                if (poData.success && poData.unit_price > 0) {
                    unitPrice = parseFloat(poData.unit_price);
                    priceSource = 'From Purchase Order (PO)';
                }
            } catch (e) {
                console.log('Could not fetch PO price:', e);
            }
        }
        
        document.getElementById('proc_id').value = r.id;
        document.getElementById('proc_expected').value = parseFloat(r.quantity).toFixed(2);
        document.getElementById('proc_actual').value = parseFloat(r.quantity).toFixed(2);
        document.getElementById('proc_damaged').value = '0.00';
        document.getElementById('proc_unit_price').value = unitPrice.toFixed(2);
        document.getElementById('proc_type').value = '';
        document.getElementById('proc_remarks').value = '';
        
        const priceInput = document.getElementById('proc_unit_price');
        const priceLabel = document.getElementById('proc_price_label');
        
        if (unitPrice > 0) {
            priceInput.readOnly = true;
            priceInput.style.background = '#e8f4fd';
            priceInput.style.color = '#002F70';
            priceInput.style.fontWeight = '600';
            priceInput.title = priceSource;
            priceLabel.innerHTML = `Unit Price (₱) <span style="color:var(--green);font-size:10px;margin-left:5px;"><i class="fas fa-check-circle"></i> ${priceSource}</span>`;
        } else {
            priceInput.readOnly = false;
            priceInput.style.background = '#fff';
            priceInput.style.color = '';
            priceInput.style.fontWeight = '';
            priceInput.title = 'Enter unit price manually';
            priceLabel.innerHTML = `Unit Price (₱) <span style="color:var(--red);">*</span> <span style="color:var(--orange);font-size:10px;margin-left:5px;"><i class="fas fa-exclamation-triangle"></i> Manual input required</span>`;
        }
        
        if (unitPrice > 0) {
            recalcPayment();
        }
        
    } catch (e) {
        toast('Error loading delivery: ' + e.message, 'error');
    }
}

// Recalculate Payment Preview
function recalcPayment() {
    const expected = parseFloat(document.getElementById('proc_expected').value) || 0;
    const actual = parseFloat(document.getElementById('proc_actual').value) || 0;
    const damaged = parseFloat(document.getElementById('proc_damaged').value) || 0;
    const unitPrice = parseFloat(document.getElementById('proc_unit_price').value) || 0;
    
    if (unitPrice <= 0) {
        document.getElementById('paymentSummary').style.display = 'none';
        return;
    }
    
    document.getElementById('paymentSummary').style.display = 'block';
    
    const expectedAmt = expected * unitPrice;
    const actualAmt = actual * unitPrice;
    const damagedAmt = damaged * unitPrice;
    const payableAmt = actualAmt - damagedAmt;
    
    document.getElementById('pay_expected').textContent = '₱' + expectedAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('pay_actual_qty').textContent = actual.toFixed(2);
    document.getElementById('pay_unit_price').textContent = unitPrice.toFixed(2);
    document.getElementById('pay_actual_amt').textContent = '₱' + actualAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('pay_total').textContent = payableAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    
    if (damaged > 0) {
        document.getElementById('damagedRow').style.display = 'flex';
        document.getElementById('pay_damaged_qty').textContent = damaged.toFixed(2);
        document.getElementById('pay_damaged_price').textContent = unitPrice.toFixed(2);
        document.getElementById('pay_damaged_amt').textContent = '₱' + damagedAmt.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
    } else {
        document.getElementById('damagedRow').style.display = 'none';
    }
    
    const discrepancyEl = document.getElementById('discrepancyAlert');
    const discrepancyMsg = document.getElementById('discrepancyMsg');
    
    if (actual < expected || damaged > 0) {
        discrepancyEl.style.display = 'block';
        let msgs = [];
        
        if (actual < expected) {
            const shortfall = expected - actual;
            msgs.push(`Partial Delivery: ${shortfall.toFixed(2)} units short (Expected: ${expected.toFixed(2)}, Received: ${actual.toFixed(2)})`);
        }
        
        if (damaged > 0) {
            msgs.push(`Damaged Items: ${damaged.toFixed(2)} units damaged/unusable`);
        }
        
        discrepancyMsg.innerHTML = msgs.join('<br>');
        
        const typeSelect = document.getElementById('proc_type');
        if (actual < expected && damaged > 0) {
            typeSelect.value = 'Mixed';
        } else if (actual < expected) {
            typeSelect.value = 'Partial';
        } else if (damaged > 0) {
            typeSelect.value = 'Damaged';
        }
    } else {
        discrepancyEl.style.display = 'none';
        document.getElementById('proc_type').value = '';
    }
}

// Submit verification processing
async function submitProcess(mode) {
    const id = parseInt(document.getElementById('proc_id').value);
    const expected = parseFloat(document.getElementById('proc_expected').value) || 0;
    const actual = parseFloat(document.getElementById('proc_actual').value) || 0;
    const damaged = parseFloat(document.getElementById('proc_damaged').value) || 0;
    const unitPrice = parseFloat(document.getElementById('proc_unit_price').value) || 0;
    const discrepancyType = document.getElementById('proc_type').value;
    const remarks = document.getElementById('proc_remarks').value.trim();
    
    if (!id || actual <= 0 || unitPrice <= 0) {
        toast('Please fill in all required fields with valid values', 'error');
        return;
    }
    
    if (!remarks) {
        toast('Please provide remarks explaining the delivery details', 'error');
        return;
    }
    
    if (damaged > actual) {
        toast('Damaged quantity cannot exceed actual received quantity', 'error');
        return;
    }
    
    const expectedAmt = expected * unitPrice;
    const actualAmt = actual * unitPrice;
    const damagedAmt = damaged * unitPrice;
    const payableAmt = actualAmt - damagedAmt;
    
    try {
        const res = await fetch(`${API}?action=process_delivery`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: id,
                expected_quantity: expected,
                actual_quantity: actual,
                damaged_quantity: damaged,
                unit_price: unitPrice,
                expected_amount: expectedAmt,
                payable_amount: payableAmt,
                discrepancy_type: discrepancyType,
                remarks: remarks
            })
        });
        
        const data = await res.json();
        
        if (!data.success) {
            toast(data.message, 'error');
            return;
        }
        
        closeModal('processModal');
        toast(data.message, 'success');
        loadDeliveries();
        
        if (mode === 'print') {
            setTimeout(() => {
                printDeliveryReport(id);
            }, 500);
        }
        
    } catch (e) {
        toast('Error processing delivery: ' + e.message, 'error');
    }
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
    // Move modals to body root to avoid layout/overflow bugs
    ['detailModal', 'auditModal', 'reopenModal', 'processModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });
    
    // Set status filter if passed in URL
    const urlStatus = '<?php echo addslashes($status_param); ?>';
    if(urlStatus === 'expected' || urlStatus === 'pending' || urlStatus === 'approved' || urlStatus === 'flagged') {
        document.getElementById('fStatus').value = urlStatus;
    }
    
    loadDeliveries();
});

// Close modals on clicking backdrop
window.addEventListener('click', function(e) {
    ['detailModal', 'auditModal', 'reopenModal', 'processModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el && e.target === el) {
            closeModal(id);
        }
    });
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
