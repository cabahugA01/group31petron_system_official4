<?php
$page_id = 'mgr_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

$station_name = '';
try {
    $sn = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
    $sn->execute([$station_id]);
    $station_name = $sn->fetchColumn() ?: 'Station';
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Page Layout ──────────────────────────────────────────── */
.int-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.int-head h1 { font-size:22px!important; font-weight:700!important; color:#00264D!important; margin:0!important; text-transform:uppercase!important; display:flex; align-items:center; gap:8px; }
.int-head .sub { font-size:13px; color:#64748b; margin-top:4px; }

/* ── Summary Cards ────────────────────────────────────────── */
.cust-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
.cust-card { background:#fff; border-radius:10px; padding:16px 18px; box-shadow:0 1px 4px rgba(0,0,0,.08); border-left:4px solid #002F70; display:flex; flex-direction:column; gap:4px; }
.cust-card .cc-label { font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.cust-card .cc-val { font-size:26px; font-weight:800; color:#002F70; line-height:1; }
.cust-card .cc-icon { font-size:18px; margin-bottom:4px; }
.cc-blue   { border-left-color:#002F70; } .cc-blue   .cc-val { color:#002F70; }
.cc-green  { border-left-color:#16a34a; } .cc-green  .cc-val { color:#16a34a; }
.cc-amber  { border-left-color:#d97706; } .cc-amber  .cc-val { color:#d97706; }
.cc-red    { border-left-color:#dc2626; } .cc-red    .cc-val { color:#dc2626; }
.cc-teal   { border-left-color:#0891b2; } .cc-teal   .cc-val { color:#0891b2; }
.cc-purple { border-left-color:#7c3aed; } .cc-purple .cc-val { color:#7c3aed; }

/* ── Filter Bar ───────────────────────────────────────────── */
.cust-filter-bar { background:#fff; border-radius:10px; padding:14px 16px; box-shadow:0 1px 4px rgba(0,0,0,.07); margin-bottom:16px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.cust-filter-bar .fg { display:flex; flex-direction:column; gap:4px; }
.cust-filter-bar label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
.cust-filter-bar input, .cust-filter-bar select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; color:#1e293b; font-family:inherit; outline:none; min-width:130px; }
.cust-filter-bar input:focus, .cust-filter-bar select:focus { border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.1); }
.fg-search input { min-width:220px; }

/* ── Top Buttons ──────────────────────────────────────────── */
.cust-top-btns { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:16px; }
.cust-btn { display:inline-flex; align-items:center; gap:6px; padding:0 14px; height:34px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; border:1px solid transparent; text-decoration:none; transition:all .15s; white-space:nowrap; }
.cust-btn-primary   { background:#002F70; color:#fff!important; border-color:#002F70; }
.cust-btn-primary:hover { background:#001a45; }
.cust-btn-outline   { background:#fff; color:#002F70!important; border-color:#002F70; }
.cust-btn-outline:hover { background:#002F70; color:#fff!important; }
.cust-btn-success   { background:#16a34a; color:#fff!important; border-color:#16a34a; }
.cust-btn-success:hover { background:#15803d; }
.cust-btn-amber     { background:#d97706; color:#fff!important; border-color:#d97706; }
.cust-btn-amber:hover { background:#b45309; }
.cust-btn-gray      { background:#64748b; color:#fff!important; border-color:#64748b; }
.cust-btn-gray:hover { background:#475569; }

/* ── Table ────────────────────────────────────────────────── */
.cust-table-wrap { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.08); overflow:hidden; }
.cust-table-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px 12px; border-bottom:1px solid #f1f5f9; }
.cust-table-header h3 { font-size:14px; font-weight:700; color:#002F70; margin:0; }
.cust-table { width:100%; border-collapse:collapse; }
.cust-table thead tr { background:#002F70; }
.cust-table thead th { padding:10px 12px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; letter-spacing:.4px; white-space:nowrap; }
.cust-table tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.cust-table tbody tr:hover td { background:#f8faff; }
.cust-table tbody td { padding:10px 12px; color:#334155; font-size:12px; vertical-align:middle; white-space:nowrap; background:#fff; }
.cust-table tfoot td { padding:10px 12px; font-size:11px; color:#64748b; background:#f8fafc; }

/* ── Badges ───────────────────────────────────────────────── */
.badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
.badge-verified  { background:#d1fae5; color:#065f46; }
.badge-pending   { background:#fef3c7; color:#92400e; }
.badge-rejected  { background:#fee2e2; color:#991b1b; }
.badge-active    { background:#dcfce7; color:#166534; }
.badge-inactive  { background:#f1f5f9; color:#64748b; }
.badge-walk-in   { background:#eff6ff; color:#1d4ed8; }
.badge-regular   { background:#f0fdf4; color:#15803d; }
.badge-fleet     { background:#faf5ff; color:#7c3aed; }
.badge-paid      { background:#ecfdf5; color:#059669; }
.badge-partial   { background:#eff6ff; color:#1d4ed8; }
.badge-unpaid    { background:#fff1f2; color:#e11d48; }

/* ── Action Buttons ───────────────────────────────────────── */
.act-btn { display:inline-flex; align-items:center; justify-content:center; gap:4px; padding:8px 12px; height:auto; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:600; transition:all .15s; color:#fff; text-decoration:none; white-space:nowrap; }
.act-view    { background:#3b82f6; } .act-view:hover    { background:#2563eb; }
.act-edit    { background:#f59e0b; } .act-edit:hover    { background:#d97706; }
.act-verify  { background:#16a34a; } .act-verify:hover  { background:#15803d; }
.act-print   { background:#6b7280; } .act-print:hover   { background:#4b5563; }

/* ── Modals ───────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:9999; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:12px; width:90%; max-width:640px; max-height:92vh; overflow-y:auto; box-shadow:0 20px 40px rgba(0,0,0,.2); padding:28px; }
.modal-box.modal-lg { max-width:900px; }
.modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #e9ecef; }
.modal-title { font-size:15px; font-weight:700; color:#002F70; display:flex; align-items:center; gap:8px; text-transform:uppercase; }
.modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888; line-height:1; padding:0 4px; }
.modal-close:hover { color:#333; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-size:11px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.4px; margin-bottom:5px; }
.form-group input, .form-group select, .form-group textarea { width:100%; padding:9px 11px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; box-sizing:border-box; outline:none; font-family:inherit; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#002F70; box-shadow:0 0 0 2px rgba(0,47,112,.1); }
.form-section-title { font-size:12px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #e2e8f0; padding-bottom:6px; margin:16px 0 12px; }
.modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:20px; padding-top:14px; border-top:1px solid #f1f5f9; }
.fleet-fields { display:none; }
.fleet-fields.show { display:block; }

/* ── Profile View ─────────────────────────────────────────── */
.profile-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9998; align-items:flex-start; justify-content:center; overflow-y:auto; padding:20px 0; }
.profile-overlay.open { display:flex; }
.profile-box { background:#fff; border-radius:12px; width:95%; max-width:1060px; margin:auto; box-shadow:0 20px 50px rgba(0,0,0,.25); overflow:hidden; }
.profile-header { background:linear-gradient(135deg,#002F70,#0047b3); color:#fff; padding:24px 28px; display:flex; align-items:center; justify-content:space-between; }
.profile-header h2 { font-size:18px; font-weight:700; margin:0; }
.profile-header .sub { font-size:12px; opacity:.8; margin-top:4px; }
.profile-body { padding:24px 28px; }
.profile-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.info-block { background:#f8fafc; border-radius:8px; padding:16px; }
.info-block h4 { font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin:0 0 12px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:6px; }
.info-row { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
.info-row:last-child { border-bottom:none; }
.info-row .label { color:#64748b; font-weight:600; }
.info-row .value { color:#1e293b; font-weight:500; text-align:right; }
.txn-history-wrap { margin-top:20px; }
.txn-history-wrap h4 { font-size:13px; font-weight:700; color:#002F70; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.txn-mini-table { width:100%; border-collapse:collapse; font-size:11px; }
.txn-mini-table thead th { background:#f1f5f9; padding:7px 10px; text-align:left; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; border-bottom:1px solid #e2e8f0; }
.txn-mini-table tbody td { padding:7px 10px; border-bottom:1px solid #f8fafc; color:#334155; }
.txn-mini-table tbody tr:hover td { background:#f8faff; }
.profile-actions { display:flex; gap:10px; padding:16px 28px; background:#f8fafc; border-top:1px solid #e2e8f0; justify-content:flex-end; flex-wrap:wrap; }

/* ── Toast ────────────────────────────────────────────────── */
#toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:8px; }
.toast { padding:12px 18px; border-radius:8px; font-size:13px; font-weight:600; min-width:280px; box-shadow:0 4px 12px rgba(0,0,0,.15); animation:slideIn .3s ease; }
.toast-success { background:#16a34a; color:#fff; }
.toast-error   { background:#dc2626; color:#fff; }
@keyframes slideIn { from{transform:translateX(60px);opacity:0} to{transform:none;opacity:1} }

.empty-state { text-align:center; padding:40px; color:#94a3b8; }
.empty-state i { font-size:36px; margin-bottom:10px; display:block; }
#loading-row td { text-align:center; padding:30px; color:#94a3b8; }
</style>

<!-- CACHE BUSTER v2.0 - BUTTON LAYOUT UPDATE -->
<div class="int-head" style="display: flex !important; justify-content: space-between !important; align-items: flex-start !important;">
    <div>
        <h1><i class="fas fa-users"></i> Customer Management</h1>
        <div class="sub">Manage, verify, and monitor customer accounts — <?php echo htmlspecialchars($station_name); ?></div>
    </div>
    <div style="display: flex !important; flex-direction: column !important; gap: 8px !important; align-items: flex-end !important;">
        <!-- Export Buttons Row -->
        <div style="display: flex !important; gap: 8px !important;">
            <a class="cust-btn" href="manager_customer_export.php?format=pdf" target="_blank" onclick="passFiltersToExport(this,'pdf')" style="background:#dc2626 !important;border:none !important;color:white !important;padding:0 14px;height:34px;display:inline-flex;align-items:center;gap:6px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><i class="fas fa-file-pdf"></i> PDF</a>
            <a class="cust-btn" href="manager_customer_export.php?format=excel" onclick="passFiltersToExport(this,'excel')" style="background:#16a34a !important;border:none !important;color:white !important;padding:0 14px;height:34px;display:inline-flex;align-items:center;gap:6px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><i class="fas fa-file-excel"></i> Excel</a>
            <a class="cust-btn" href="manager_customer_export.php?format=csv" onclick="passFiltersToExport(this,'csv')" style="background:#6b7280 !important;border:none !important;color:white !important;padding:0 14px;height:34px;display:inline-flex;align-items:center;gap:6px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;"><i class="fas fa-file-csv"></i> CSV</a>
        </div>
        <!-- Add Customer Button Below -->
        <button class="cust-btn" onclick="openAddModal()" style="width: 100% !important;background:#3b82f6 !important;border:none !important;color:white !important;padding:0 14px;height:34px;display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;"><i class="fas fa-plus"></i> Add Customer</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="cust-cards" id="summary-cards">
    <div class="cust-card cc-blue">
        <span class="cc-icon"><i class="fas fa-users"></i></span>
        <span class="cc-label">Total Customers</span>
        <span class="cc-val" id="stat-total">—</span>
    </div>
    <div class="cust-card cc-teal">
        <span class="cc-icon"><i class="fas fa-user-plus"></i></span>
        <span class="cc-label">New Today</span>
        <span class="cc-val" id="stat-new">—</span>
    </div>
    <div class="cust-card cc-green">
        <span class="cc-icon"><i class="fas fa-star"></i></span>
        <span class="cc-label">Regular</span>
        <span class="cc-val" id="stat-regular">—</span>
    </div>
    <div class="cust-card cc-purple">
        <span class="cc-icon"><i class="fas fa-building"></i></span>
        <span class="cc-label">Fleet / Company</span>
        <span class="cc-val" id="stat-fleet">—</span>
    </div>
    <div class="cust-card cc-red">
        <span class="cc-icon"><i class="fas fa-exclamation-circle"></i></span>
        <span class="cc-label">With Balance</span>
        <span class="cc-val" id="stat-outstanding">—</span>
    </div>
    <div class="cust-card cc-amber">
        <span class="cc-icon"><i class="fas fa-check-circle"></i></span>
        <span class="cc-label">Active</span>
        <span class="cc-val" id="stat-active">—</span>
    </div>
</div>

<!-- Filter Bar -->
<div class="cust-filter-bar">
    <div class="fg fg-search">
        <label>Search</label>
        <input type="text" id="filter-search" placeholder="ID / Name / Contact…" oninput="debounceLoad()">
    </div>
    <div class="fg">
        <label>Customer Type</label>
        <select id="filter-type" onchange="loadManagerCustomers()">
            <option value="">All Types</option>
            <option value="walk-in">Walk-in</option>
            <option value="regular">Regular</option>
            <option value="fleet">Fleet / Company</option>
        </select>
    </div>
    <div class="fg">
        <label>Status</label>
        <select id="filter-status" onchange="loadManagerCustomers()">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="fg">
        <label>Verification</label>
        <select id="filter-verification" onchange="loadManagerCustomers()">
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="verified">Verified</option>
            <option value="rejected">Rejected</option>
        </select>
    </div>
    <div class="fg">
        <label>Payment</label>
        <select id="filter-payment" onchange="loadManagerCustomers()">
            <option value="">All</option>
            <option value="paid">Paid</option>
            <option value="partial">Partial</option>
            <option value="unpaid">Unpaid</option>
        </select>
    </div>
    <div class="fg">
        <label>Date From</label>
        <input type="date" id="filter-date-from" onchange="loadManagerCustomers()">
    </div>
    <div class="fg">
        <label>Date To</label>
        <input type="date" id="filter-date-to" onchange="loadManagerCustomers()">
    </div>
</div>

<!-- Top Buttons - Now in header -->

<!-- Customer Table -->
<div class="cust-table-wrap">
    <div class="cust-table-header">
        <h3><i class="fas fa-list"></i> Customer Registry</h3>
        <span id="count-label" style="font-size:12px;color:#64748b;"></span>
    </div>
    <div style="overflow-x:auto;">
        <table class="cust-table">
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Type</th>
                    <th>Contact No.</th>
                    <th>Outstanding Balance</th>
                    <th>Verification</th>
                    <th>Last Transaction</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="cust-tbody">
                <tr id="loading-row"><td colspan="9"><i class="fas fa-spinner fa-spin"></i> Loading customers…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div id="toast-container"></div>

<!-- -- ADD / EDIT CUSTOMER MODAL -- -->
<div class="modal-overlay" id="modal-customer">
  <div class="modal-box modal-lg">
    <div class="modal-head">
      <span class="modal-title"><i class="fas fa-user-plus"></i> <span id="modal-cust-title">Add Customer</span></span>
      <button class="modal-close" onclick="closeModal('modal-customer')">&times;</button>
    </div>
    <form id="form-customer" enctype="multipart/form-data">
      <input type="hidden" id="fc-id" name="customer_id" value="">
      <input type="hidden" id="fc-mode" value="add">
      <div class="form-section-title"><i class="fas fa-user"></i> Basic Information</div>
      <div class="form-row">
        <div class="form-group"><label>First Name *</label><input type="text" name="first_name" id="fc-fname" required></div>
        <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" id="fc-mname"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" id="fc-lname" required></div>
        <div class="form-group"><label>Contact Number *</label><input type="text" name="contact_number" id="fc-contact" required></div>
      </div>
      <div class="form-group"><label>Address *</label><textarea name="address" id="fc-address" rows="2" required></textarea></div>
      <div class="form-row">
        <div class="form-group">
          <label>Customer Type *</label>
          <select name="customer_type" id="fc-type" onchange="toggleFleetFields()" required>
            <option value="walk-in">Walk-in</option>
            <option value="regular">Regular</option>
            <option value="fleet">Fleet / Company</option>
          </select>
        </div>
        <div class="form-group">
          <label>Account Status</label>
          <select name="status" id="fc-status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="fleet-fields" id="fleet-section">
        <div class="form-section-title"><i class="fas fa-building"></i> Fleet / Company Information</div>
        <div class="form-row">
          <div class="form-group"><label>Company Name</label><input type="text" name="company_name" id="fc-company"></div>
          <div class="form-group"><label>Company Contact Number</label><input type="text" name="company_contact_number" id="fc-company-contact"></div>
        </div>
        <div class="form-group"><label>Company Address</label><textarea name="company_address" id="fc-company-address" rows="2"></textarea></div>
        <div class="form-group"><label>Contact Person</label><input type="text" name="company_contact_person" id="fc-contact-person"></div>
      </div>
      <div class="form-section-title"><i class="fas fa-id-card"></i> Verification Documents</div>
      <div class="form-row">
        <div class="form-group">
          <label>Government ID Type</label>
          <select name="gov_id_type" id="fc-gov-type">
            <option value="">� Select �</option>
            <option>Philippine Passport</option>
            <option>Driver's License</option>
            <option>SSS ID</option>
            <option>PhilHealth ID</option>
            <option>Voter's ID</option>
            <option>Postal ID</option>
            <option>PRC ID</option>
            <option>UMID</option>
            <option>National ID (PhilSys)</option>
          </select>
        </div>
        <div class="form-group"><label>Government ID Image (JPG/PNG/PDF, max 5MB)</label><input type="file" name="gov_id_image" id="fc-gov-file" accept=".jpg,.jpeg,.png,.pdf"></div>
      </div>
      <div class="form-group"><label>Certificate of Registration (CR) � Fleet only</label><input type="file" name="cr_document" id="fc-cr-file" accept=".jpg,.jpeg,.png,.pdf"></div>
      <div class="form-section-title"><i class="fas fa-wallet"></i> Financial Information</div>
      <div class="form-row">
        <div class="form-group"><label>Credit Limit (?)</label><input type="number" step="0.01" min="0" name="credit_limit" id="fc-credit-limit" value="0"></div>
        <div class="form-group"><label>Outstanding Balance (?)</label><input type="number" step="0.01" min="0" name="outstanding_balance" id="fc-outstanding" value="0"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="cust-btn cust-btn-gray" onclick="closeModal('modal-customer')">Cancel</button>
        <button type="submit" class="cust-btn cust-btn-primary" id="fc-submit-btn"><i class="fas fa-save"></i> Save Customer</button>
      </div>
    </form>
  </div>
</div>

<!-- -- VERIFY CUSTOMER MODAL -- -->
<div class="modal-overlay" id="modal-verify">
  <div class="modal-box">
    <div class="modal-head">
      <span class="modal-title"><i class="fas fa-shield-alt"></i> Verify Customer</span>
      <button class="modal-close" onclick="closeModal('modal-verify')">&times;</button>
    </div>
    <p style="font-size:13px;color:#334155;margin-bottom:16px;">
      Reviewing verification for: <strong id="verify-name"></strong>
    </p>
    <form id="form-verify">
      <input type="hidden" id="fv-id" name="id">
      <div class="form-group">
        <label>Verification Decision *</label>
        <select name="status" id="fv-status" required>
          <option value="verified">? Approve / Verify</option>
          <option value="rejected">? Reject</option>
        </select>
      </div>
      <div class="form-group">
        <label>Remarks / Notes</label>
        <textarea name="remarks" id="fv-remarks" rows="3" placeholder="Enter reason or notes�"></textarea>
      </div>
      <div id="verify-doc-preview" style="margin-bottom:14px;display:none;">
        <div class="form-section-title"><i class="fas fa-file-alt"></i> Uploaded Documents</div>
        <div id="verify-doc-links" style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="cust-btn cust-btn-gray" onclick="closeModal('modal-verify')">Cancel</button>
        <button type="submit" class="cust-btn cust-btn-success"><i class="fas fa-check"></i> Submit Decision</button>
      </div>
    </form>
  </div>
</div>

<!-- -- CUSTOMER PROFILE OVERLAY -- -->
<div class="profile-overlay" id="profile-overlay">
  <div class="profile-box">
    <div class="profile-header">
      <div>
        <h2 id="prof-name">Loading�</h2>
        <div class="sub" id="prof-sub"></div>
      </div>
      <button onclick="closeProfile()" style="background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 14px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
    <div class="profile-body" id="profile-body">
      <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading profile�</p></div>
    </div>
    <div class="profile-actions">
      <button class="cust-btn cust-btn-success" id="prof-verify-btn" onclick="openVerifyFromProfile()"><i class="fas fa-shield-alt"></i> Verify Customer</button>
      <button class="cust-btn cust-btn-gray" onclick="printProfile()"><i class="fas fa-print"></i> Print Profile</button>
      <button class="cust-btn cust-btn-gray" onclick="closeProfile()"><i class="fas fa-arrow-left"></i> Back to List</button>
    </div>
  </div>
</div>

<script>
let debounceTimer = null;
let currentProfileId = null;
let currentProfileData = null;

// --- LOAD CUSTOMERS ------------------------------------------------
function loadManagerCustomers() {
  const tbody = document.getElementById('cust-tbody');
  tbody.innerHTML = '<tr id="loading-row"><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;"><i class="fas fa-spinner fa-spin"></i> Loading�</td></tr>';

  const params = new URLSearchParams({
    action: 'list',
    search: document.getElementById('filter-search').value,
    type: document.getElementById('filter-type').value,
    status: document.getElementById('filter-status').value,
    verification: document.getElementById('filter-verification').value,
    payment: document.getElementById('filter-payment').value,
    date_from: document.getElementById('filter-date-from').value,
    date_to: document.getElementById('filter-date-to').value
  });

  fetch('manager_customer_operations.php?' + params.toString())
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showToast(data.error || 'Failed to load customers', 'error'); tbody.innerHTML = '<tr><td colspan="9" class="empty-state"><i class="fas fa-exclamation-circle"></i> Error loading data</td></tr>'; return; }
      updateStats(data.stats);
      renderTable(data.customers);
    })
    .catch(() => { tbody.innerHTML = '<tr><td colspan="9" class="empty-state"><i class="fas fa-wifi"></i> Network error</td></tr>'; });
}

function debounceLoad() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(loadManagerCustomers, 400);
}

function updateStats(s) {
  document.getElementById('stat-total').textContent = s.total || 0;
  document.getElementById('stat-new').textContent = s.new_today || 0;
  document.getElementById('stat-regular').textContent = s.regular || 0;
  document.getElementById('stat-fleet').textContent = s.fleet || 0;
  document.getElementById('stat-outstanding').textContent = s.outstanding || 0;
  document.getElementById('stat-active').textContent = s.active || 0;
}

function renderTable(customers) {
  const tbody = document.getElementById('cust-tbody');
  document.getElementById('count-label').textContent = customers.length + ' record(s) found';
  if (!customers.length) {
    tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="fas fa-users-slash"></i><p>No customers found matching the criteria</p></div></td></tr>';
    return;
  }
  tbody.innerHTML = customers.map(c => {
    const fullName = c.display_name || [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ') || c.name || 'Unknown';
    const typeBadge = {'walk-in':'badge-walk-in','regular':'badge-regular','fleet':'badge-fleet'}[c.customer_type] || 'badge-walk-in';
    const verBadge = {'verified':'badge-verified','pending':'badge-pending','rejected':'badge-rejected'}[c.verification_status] || 'badge-pending';
    const statusBadge = c.status === 'active' ? 'badge-active' : 'badge-inactive';
    const payBadge = {'paid':'badge-paid','partial':'badge-partial','unpaid':'badge-unpaid'}[c.payment_status] || '';
    const balance = parseFloat(c.outstanding_balance || 0);
    const balHtml = balance > 0 ? `<span style="color:#dc2626;font-weight:700;">?${fmt(balance)}</span>` : `<span style="color:#16a34a;">?0.00</span>`;
    const lastTx = c.last_transaction ? fmtDate(c.last_transaction) : '<span style="color:#94a3b8;">�</span>';
    return `<tr>
      <td><strong>${esc(c.customer_id)}</strong></td>
      <td>${esc(fullName)}</td>
      <td><span class="badge ${typeBadge}">${esc(c.customer_type)}</span></td>
      <td>${esc(c.contact_number)}</td>
      <td>${balHtml}</td>
      <td><span class="badge ${verBadge}">${c.verification_status}</span></td>
      <td>${lastTx}</td>
      <td><span class="badge ${statusBadge}">${c.status}</span></td>
      <td>
        <div style="display:flex;flex-direction:column;gap:6px;">
          <button class="act-btn act-view" onclick="viewProfile(${c.id})" title="View Profile"><i class="fas fa-eye"></i> View</button>
          <button class="act-btn act-edit" onclick="openEditModal(${c.id})" title="Edit Customer"><i class="fas fa-edit"></i> Edit</button>
          <button class="act-btn act-verify" data-cid="${c.id}" data-name="${esc(fullName).replace(/"/g,'&quot;')}" data-govid="${c.gov_id_image||''}" data-crdoc="${c.cr_document||''}" onclick="openVerifyFromBtn(this)" title="Verify Customer"><i class="fas fa-check-circle"></i> Verify</button>
          <button class="act-btn act-print" onclick="printCustomer(${c.id})" title="Print Profile"><i class="fas fa-print"></i> Print</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

// --- HELPERS -------------------------------------------------------
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }
function fmt(n) { return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function fmtDate(d) { if (!d) return '�'; const dt = new Date(d); return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); }

function showToast(msg, type='success') {
  const tc = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  tc.appendChild(t);
  setTimeout(() => t.remove(), 4000);
}

function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function passFiltersToExport(el, fmt) {
  const url = new URL('manager_customer_export.php', window.location.href);
  url.searchParams.set('format', fmt);
  ['search','type','status','verification','payment','date_from','date_to'].forEach(k => {
    const el2 = document.getElementById('filter-' + k.replace('_','-'));
    if (el2) url.searchParams.set(k, el2.value);
  });
  el.href = url.toString();
}
</script>

<script>
// --- ADD / EDIT MODAL ----------------------------------------------
function toggleFleetFields() {
  const t = document.getElementById('fc-type').value;
  document.getElementById('fleet-section').classList.toggle('show', t === 'fleet');
}

function openAddModal() {
  document.getElementById('form-customer').reset();
  document.getElementById('fc-id').value = '';
  document.getElementById('fc-mode').value = 'add';
  document.getElementById('modal-cust-title').textContent = 'Add New Customer';
  document.getElementById('fc-submit-btn').innerHTML = '<i class="fas fa-save"></i> Save Customer';
  document.getElementById('fleet-section').classList.remove('show');
  document.getElementById('modal-customer').classList.add('open');
}

function openEditModal(id) {
  fetch(`manager_customer_operations.php?action=view&id=${id}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) { showToast(data.error || 'Failed to load customer', 'error'); return; }
      const c = data.customer;
      document.getElementById('fc-id').value = c.id;
      document.getElementById('fc-mode').value = 'edit';
      document.getElementById('modal-cust-title').textContent = 'Edit Customer';
      document.getElementById('fc-submit-btn').innerHTML = '<i class="fas fa-save"></i> Update Customer';
      document.getElementById('fc-fname').value = c.first_name || '';
      document.getElementById('fc-mname').value = c.middle_name || '';
      document.getElementById('fc-lname').value = c.last_name || '';
      document.getElementById('fc-contact').value = c.contact_number || '';
      document.getElementById('fc-address').value = c.address || '';
      document.getElementById('fc-type').value = c.customer_type || 'walk-in';
      document.getElementById('fc-status').value = c.status || 'active';
      document.getElementById('fc-gov-type').value = c.gov_id_type || '';
      document.getElementById('fc-company').value = c.company_name || '';
      document.getElementById('fc-company-address').value = c.company_address || '';
      document.getElementById('fc-contact-person').value = c.company_contact_person || '';
      document.getElementById('fc-company-contact').value = c.company_contact_number || '';
      document.getElementById('fc-credit-limit').value = c.credit_limit || 0;
      document.getElementById('fc-outstanding').value = c.outstanding_balance || 0;
      toggleFleetFields();
      document.getElementById('modal-customer').classList.add('open');
    })
    .catch(() => showToast('Network error', 'error'));
}

document.getElementById('form-customer').addEventListener('submit', function(e) {
  e.preventDefault();
  const mode = document.getElementById('fc-mode').value;
  const action = mode === 'add' ? 'add' : 'update';
  const fd = new FormData(this);
  fd.append('action', action);
  const btn = document.getElementById('fc-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving�';

  fetch('manager_customer_operations.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-save"></i> Save Customer';
      if (data.success) {
        showToast(data.message || 'Customer saved!', 'success');
        closeModal('modal-customer');
        loadManagerCustomers();
      } else {
        showToast(data.error || 'Failed to save customer', 'error');
      }
    })
    .catch(() => { btn.disabled = false; showToast('Network error', 'error'); });
});

// --- VERIFY MODAL -------------------------------------------------
// Called from table row button via data-attributes (avoids inline string-injection of paths)
function openVerifyFromBtn(btn) {
  openVerifyModal(
    btn.getAttribute('data-cid'),
    btn.getAttribute('data-name'),
    btn.getAttribute('data-govid'),
    btn.getAttribute('data-crdoc')
  );
}

function openVerifyModal(id, name, govId, crDoc) {
  document.getElementById('fv-id').value = id;
  document.getElementById('fv-status').value = 'verified';
  document.getElementById('fv-remarks').value = '';
  document.getElementById('verify-name').textContent = name;
  const docWrap = document.getElementById('verify-doc-preview');
  const docLinks = document.getElementById('verify-doc-links');
  if (govId || crDoc) {
    docLinks.innerHTML = '';
    if (govId) docLinks.innerHTML += `<a href="${govId}" target="_blank" class="cust-btn cust-btn-outline" style="font-size:11px;"><i class="fas fa-id-card"></i> Preview Gov ID</a>`;
    if (crDoc) docLinks.innerHTML += `<a href="${crDoc}" target="_blank" class="cust-btn cust-btn-outline" style="font-size:11px;"><i class="fas fa-file"></i> Preview CR</a>`;
    docWrap.style.display = 'block';
  } else {
    docWrap.style.display = 'none';
  }
  document.getElementById('modal-verify').classList.add('open');
}

function openVerifyFromProfile() {
  if (!currentProfileData) return;
  const c = currentProfileData.customer;
  const name = [c.first_name, c.last_name].filter(Boolean).join(' ') || c.name || 'Unknown';
  openVerifyModal(c.id, name, c.gov_id_image, c.cr_document);
}

document.getElementById('form-verify').addEventListener('submit', function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  fd.append('action', 'verify');
  const btn = this.querySelector('[type=submit]');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing�';

  fetch('manager_customer_operations.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check"></i> Submit Decision';
      if (data.success) {
        showToast(data.message, 'success');
        closeModal('modal-verify');
        if (currentProfileId) viewProfile(currentProfileId);
        loadManagerCustomers();
      } else {
        showToast(data.error || 'Verification failed', 'error');
      }
    })
    .catch(() => { btn.disabled = false; showToast('Network error', 'error'); });
});

// --- PROFILE VIEW -------------------------------------------------
function viewProfile(id) {
  currentProfileId = id;
  document.getElementById('prof-name').textContent = 'Loading�';
  document.getElementById('prof-sub').textContent = '';
  document.getElementById('profile-body').innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Loading profile�</p></div>';
  document.getElementById('profile-overlay').classList.add('open');

  fetch(`manager_customer_operations.php?action=view&id=${id}`)
    .then(r => r.json())
    .then(data => {
      if (!data.success) { document.getElementById('profile-body').innerHTML = '<p style="padding:20px;color:#dc2626;">Failed to load profile</p>'; return; }
      currentProfileData = data;
      renderProfile(data);
    })
    .catch(() => { document.getElementById('profile-body').innerHTML = '<p style="padding:20px;">Network error</p>'; });
}

function renderProfile(data) {
  const c = data.customer;
  const tx = data.transactions;
  const fin = data.financials;
  const history = data.transaction_history || [];
  const fullName = c.display_name || [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ') || c.name || 'Unknown';

  document.getElementById('prof-name').textContent = fullName;
  document.getElementById('prof-sub').textContent = `${c.customer_id} � ${c.customer_type} � ${c.verification_status}`;

  const payBadge = {'paid':'badge-paid','partial':'badge-partial','unpaid':'badge-unpaid'}[fin.payment_status] || '';

  let fleetHtml = '';
  if (c.customer_type === 'fleet') {
    fleetHtml = `<div class="info-block">
      <h4><i class="fas fa-building"></i> Fleet / Company</h4>
      <div class="info-row"><span class="label">Company Name</span><span class="value">${esc(c.company_name||'�')}</span></div>
      <div class="info-row"><span class="label">Company Address</span><span class="value">${esc(c.company_address||'�')}</span></div>
      <div class="info-row"><span class="label">Contact Person</span><span class="value">${esc(c.company_contact_person||'�')}</span></div>
      <div class="info-row"><span class="label">Company Contact</span><span class="value">${esc(c.company_contact_number||'�')}</span></div>
    </div>`;
  }

  let docHtml = '';
  if (c.gov_id_image || c.cr_document) {
    const govLink = c.gov_id_image ? `<a href="../${c.gov_id_image}" target="_blank" class="cust-btn cust-btn-outline" style="font-size:11px;"><i class="fas fa-id-card"></i> Preview Gov ID</a>` : '';
    const crLink = c.cr_document ? `<a href="../${c.cr_document}" target="_blank" class="cust-btn cust-btn-outline" style="font-size:11px;"><i class="fas fa-file-alt"></i> Preview CR</a>` : '';
    docHtml = `<div class="info-block" style="margin-top:12px;">
      <h4><i class="fas fa-file-alt"></i> Verification Documents</h4>
      <div class="info-row"><span class="label">Gov ID Type</span><span class="value">${esc(c.gov_id_type||'�')}</span></div>
      <div class="info-row"><span class="label">Verification Status</span><span class="value"><span class="badge badge-${c.verification_status}">${c.verification_status}</span></span></div>
      <div class="info-row"><span class="label">Verified By</span><span class="value">${esc(c.verified_by_name||'�')}</span></div>
      <div class="info-row"><span class="label">Verified At</span><span class="value">${c.verified_at ? fmtDate(c.verified_at) : '�'}</span></div>
      <div class="info-row"><span class="label">Remarks</span><span class="value">${esc(c.verification_remarks||'�')}</span></div>
      <div style="margin-top:10px;display:flex;gap:8px;">${govLink}${crLink}</div>
    </div>`;
  }

  const txRows = history.map(h => `<tr>
    <td>${fmtDate(h.txn_date)}</td>
    <td><strong>${esc(h.reference_no)}</strong></td>
    <td><span class="badge badge-${h.module==='Fuel'?'active':h.module==='Merchandise'?'walk-in':'fleet'}">${h.module}</span></td>
    <td>${esc(h.description)}</td>
    <td style="text-align:right;">?${fmt(h.amount)}</td>
  </tr>`).join('');

  document.getElementById('profile-body').innerHTML = `
    <div class="profile-grid">
      <div class="info-block">
        <h4><i class="fas fa-user"></i> Customer Information</h4>
        <div class="info-row"><span class="label">Customer ID</span><span class="value"><strong>${esc(c.customer_id)}</strong></span></div>
        <div class="info-row"><span class="label">Full Name</span><span class="value">${esc(fullName)}</span></div>
        <div class="info-row"><span class="label">Contact Number</span><span class="value">${esc(c.contact_number)}</span></div>
        <div class="info-row"><span class="label">Address</span><span class="value">${esc(c.address||'�')}</span></div>
        <div class="info-row"><span class="label">Type</span><span class="value"><span class="badge badge-${c.customer_type}">${c.customer_type}</span></span></div>
        <div class="info-row"><span class="label">Registered</span><span class="value">${fmtDate(c.registered_at)}</span></div>
        <div class="info-row"><span class="label">Status</span><span class="value"><span class="badge badge-${c.status}">${c.status}</span></span></div>
      </div>
      <div>
        <div class="info-block">
          <h4><i class="fas fa-wallet"></i> Financial Information</h4>
          <div class="info-row"><span class="label">Outstanding Balance</span><span class="value" style="color:#dc2626;font-weight:700;">?${fmt(fin.outstanding_balance)}</span></div>
          <div class="info-row"><span class="label">Credit Limit</span><span class="value">?${fmt(fin.credit_limit)}</span></div>
          <div class="info-row"><span class="label">Total Payments</span><span class="value">?${fmt(fin.total_payments)}</span></div>
          <div class="info-row"><span class="label">Payment Status</span><span class="value"><span class="badge ${payBadge}">${fin.payment_status}</span></span></div>
        </div>
        <div class="info-block" style="margin-top:12px;">
          <h4><i class="fas fa-chart-bar"></i> Transaction Summary</h4>
          <div class="info-row"><span class="label">Fuel Transactions</span><span class="value">${tx.fuel_count} (?${fmt(tx.fuel_amount)})</span></div>
          <div class="info-row"><span class="label">Merchandise</span><span class="value">${tx.merch_count} (?${fmt(tx.merch_amount)})</span></div>
          <div class="info-row"><span class="label">Job Orders</span><span class="value">${tx.service_count} (?${fmt(tx.service_amount)})</span></div>
          <div class="info-row"><span class="label">Total Transactions</span><span class="value"><strong>${tx.total_count}</strong></span></div>
          <div class="info-row"><span class="label">Total Purchased</span><span class="value"><strong>?${fmt(tx.total_amount)}</strong></span></div>
        </div>
      </div>
    </div>
    ${fleetHtml}
    ${docHtml}
    <div class="txn-history-wrap">
      <h4><i class="fas fa-history"></i> Recent Transaction History</h4>
      ${history.length ? `<div style="overflow-x:auto;"><table class="txn-mini-table">
        <thead><tr><th>Date</th><th>Reference</th><th>Module</th><th>Description</th><th style="text-align:right;">Amount</th></tr></thead>
        <tbody>${txRows}</tbody>
      </table></div>` : '<p style="color:#94a3b8;font-size:12px;padding:12px 0;">No transactions recorded.</p>'}
    </div>`;
}

function closeProfile() {
  document.getElementById('profile-overlay').classList.remove('open');
  currentProfileId = null;
  currentProfileData = null;
}

function printProfile() {
  if (!currentProfileId) return;
  window.open(`manager_customer_export.php?format=pdf&profile_id=${currentProfileId}`, '_blank');
}

function printCustomer(id) {
  window.open(`manager_customer_export.php?format=pdf&profile_id=${id}`, '_blank');
}

// --- INIT ---------------------------------------------------------
document.addEventListener('DOMContentLoaded', loadManagerCustomers);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>


