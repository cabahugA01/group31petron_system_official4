<?php
$page_id = 'mgr_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'superadmin', 'developer'], true)) {
    $_SESSION['error'] = 'Only managers can access customer management.';
    header('Location: dashboard.php');
    exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    render_no_station_page('manager_dashboard.php');
}

customer_ensure_optional_columns($pdo);
customer_ensure_request_table($pdo);

$station_name = 'Petron Station';
try {
    $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $station_name = $stmt->fetchColumn() ?: $station_name;
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>

<style>
.cust-page { color:#0f172a; }
.cust-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding-bottom:16px; margin-bottom:18px; border-bottom:2px solid #e2e8f0; }
.cust-head h1 { margin:0; color:#002f70; font-size:28px; font-weight:800; text-transform:uppercase; display:flex; align-items:center; gap:10px; }
.cust-head p { margin:5px 0 0; color:#64748b; font-size:15px; }
.cust-btn { border:1px solid #cbd5e1; background:#fff; color:#002f70; border-radius:6px; min-height:38px; padding:8px 14px; font-weight:700; font-size:14px; display:inline-flex; align-items:center; justify-content:center; gap:7px; cursor:pointer; text-decoration:none; }
.cust-btn:hover { background:#f8fafc; }
.cust-btn.primary { background:#002f70; border-color:#002f70; color:#fff; }
.cust-btn.primary:hover { background:#001f4d; }
.cust-btn.success { background:#16a34a; border-color:#16a34a; color:#fff; }
.cust-btn.danger { background:#fff; border-color:#dc2626; color:#dc2626; }
.cust-btn.danger:hover { background:#fef2f2; }
.cust-btn.muted { color:#475569; }
.cust-btn:disabled { opacity:.55; cursor:not-allowed; }
.cust-cards { display:grid; grid-template-columns:repeat(5,minmax(150px,1fr)); gap:14px; margin-bottom:18px; }
.cust-card { background:#fff; border:1px solid #dbe4f0; border-radius:8px; padding:16px 18px; box-shadow:0 2px 8px rgba(15,23,42,.06); }
.cust-card .label { color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
.cust-card .value { color:#002f70; font-size:30px; line-height:1.1; font-weight:900; margin-top:6px; }
.cust-card.green .value { color:#16a34a; }
.cust-card.gray .value { color:#64748b; }
.cust-card.amber .value { color:#d97706; }
.cust-toolbar { background:#fff; border:1px solid #dbe4f0; border-radius:8px; padding:14px; display:grid; grid-template-columns:minmax(240px,1fr) 190px 170px auto; gap:12px; align-items:end; margin-bottom:16px; box-shadow:0 2px 8px rgba(15,23,42,.05); }
.cust-field label { display:block; font-size:12px; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:6px; }
.cust-field input, .cust-field select, .cust-field textarea { width:100%; min-height:42px; border:1px solid #cbd5e1; border-radius:6px; padding:9px 11px; font-size:15px; color:#0f172a; background:#fff; font-family:inherit; }
.cust-field textarea { min-height:82px; resize:vertical; }
.cust-field input:focus, .cust-field select:focus, .cust-field textarea:focus { outline:none; border-color:#002f70; box-shadow:0 0 0 3px rgba(0,47,112,.12); }
.cust-section { background:#fff; border:1px solid #dbe4f0; border-radius:8px; box-shadow:0 2px 8px rgba(15,23,42,.06); margin-bottom:18px; overflow:hidden; }
.cust-section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid #e2e8f0; }
.cust-section-head h2 { margin:0; color:#002f70; font-size:17px; font-weight:800; display:flex; align-items:center; gap:8px; }

/* ── Customer Tabs ── */
.cust-tabs { display:flex; gap:4px; margin-bottom:16px; background:#f1f5f9; border-radius:8px; padding:4px; width:fit-content; }
.cust-tab { display:flex; align-items:center; gap:8px; padding:8px 16px; background:none; border:none; border-radius:6px; font-size:13px; font-weight:600; color:#475569; cursor:pointer; transition:all .18s; white-space:nowrap; }
.cust-tab:hover { color:#002f70; background:#e2e8f0; }
.cust-tab.active { background:#002f70; color:#fff; box-shadow:0 2px 6px rgba(0,47,112,.25); }
.cust-tab-badge { font-size:11px; font-weight:700; padding:2px 6px; border-radius:20px; min-width:18px; text-align:center; background:rgba(255,255,255,.25); color:#fff; }
.cust-tab:not(.active) .cust-tab-badge { background:#cbd5e1; color:#475569; }
.cust-count { color:#64748b; font-weight:700; font-size:13px; }
.cust-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.cust-table th { background:#002f70; color:#fff; padding:12px 10px; text-align:left; font-size:13px; text-transform:uppercase; }
.cust-table td { padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:14px; vertical-align:middle; word-break:break-word; }
.cust-table tr:hover td { background:#f8fbff; }
.cust-table .empty { text-align:center; color:#64748b; padding:34px 16px; }
.cust-actions { display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
.pill { display:inline-flex; align-items:center; justify-content:center; padding:4px 9px; border-radius:999px; font-size:12px; font-weight:800; text-transform:uppercase; }
.pill.active { background:#dcfce7; color:#166534; }
.pill.inactive { background:#f1f5f9; color:#64748b; }
.pill.walk-in { background:#eff6ff; color:#1d4ed8; }
.pill.regular { background:#f0fdf4; color:#15803d; }
.pill.credit { background:#fff7ed; color:#c2410c; }
.modal-backdrop { display:none; position:absolute; inset:0; background:rgba(15,23,42,.62); z-index:12000; align-items:center; justify-content:center; padding:24px 16px 72px; overflow:hidden; overscroll-behavior:contain; pointer-events:auto!important; }
.modal-backdrop.open { display:flex; }
.cust-modal { width:min(1080px, calc(100% - 32px)); max-height:min(860px, calc(100% - 96px)); background:#fff; border-radius:10px; box-shadow:0 24px 70px rgba(0,0,0,.28); display:flex; flex-direction:column; overflow:hidden; margin:0 auto; pointer-events:auto!important; }
.cust-modal.sm { width:min(560px, 96vw); }
.cust-modal > form { display:flex; flex-direction:column; min-height:0; flex:1 1 auto; }
.modal-head { padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex:0 0 auto; }
.modal-head h3 { margin:0; color:#002f70; font-size:18px; font-weight:800; display:flex; align-items:center; gap:8px; }
.modal-close { border:0; background:transparent; font-size:24px; line-height:1; color:#64748b; cursor:pointer; }
.modal-body { padding:20px 24px 24px; overflow-y:auto; max-height:none; min-height:0; flex:1 1 auto; }
.modal-actions { padding:14px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; flex:0 0 auto; box-shadow:0 -6px 18px rgba(15,23,42,.06); }
body.modal-open { overflow:hidden; }
body.modal-open main.main { overflow:hidden!important; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-grid.full { grid-template-columns:1fr; }
.form-title { grid-column:1 / -1; color:#002f70; font-weight:900; font-size:13px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:7px; margin-top:8px; }
.readonly-box { min-height:42px; border:1px solid #e2e8f0; background:#f8fafc; border-radius:6px; padding:10px 11px; color:#64748b; font-size:15px; }
.view-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.view-box { border:1px solid #e2e8f0; border-radius:8px; padding:14px; background:#fff; }
.view-box h4 { margin:0 0 10px; color:#002f70; font-size:14px; font-weight:900; text-transform:uppercase; }
.info-row { display:flex; justify-content:space-between; gap:12px; border-bottom:1px solid #f1f5f9; padding:7px 0; font-size:14px; }
.info-row:last-child { border-bottom:0; }
.info-row span:first-child { color:#64748b; font-weight:700; }
.info-row span:last-child { text-align:right; font-weight:700; }
.toast-stack { position:fixed; top:18px; right:18px; z-index:11000; display:flex; flex-direction:column; gap:8px; }
.toast { min-width:270px; max-width:380px; color:#fff; border-radius:8px; padding:12px 38px 12px 14px; box-shadow:0 10px 30px rgba(15,23,42,.2); font-weight:700; position:relative; }
.toast.success { background:#16a34a; }
.toast.error { background:#dc2626; }
.toast.info { background:#2563eb; }
.toast button { position:absolute; top:6px; right:9px; border:0; background:transparent; color:#fff; font-size:18px; cursor:pointer; }
@media (max-width:1200px) {
    .cust-cards { grid-template-columns:repeat(3,1fr); }
    .cust-toolbar { grid-template-columns:1fr 1fr; }
}
@media (max-width:760px) {
    .cust-head { flex-direction:column; }
    .cust-cards, .cust-toolbar, .form-grid, .view-grid { grid-template-columns:1fr; }
    .cust-table { min-width:0; }
    .cust-actions { justify-content:flex-start; }
    .modal-backdrop { align-items:stretch; padding:12px; }
    .cust-modal { width:100%; max-height:calc(100% - 24px); }
    .modal-body { padding:16px; }
    .modal-actions { justify-content:stretch; }
    .modal-actions .cust-btn { flex:1 1 auto; }
}
</style>

<div class="cust-page">
    <div class="cust-head">
        <div>
            <h1><i class="fas fa-users"></i> Customers</h1>
        </div>
    </div>

    <div class="cust-cards">
        <div class="cust-card"><div class="label">Total Customers</div><div class="value" id="statTotal">0</div></div>
        <div class="cust-card green"><div class="label">Active Customers</div><div class="value" id="statActive">0</div></div>
        <div class="cust-card gray"><div class="label">Inactive Customers</div><div class="value" id="statInactive">0</div></div>
        <div class="cust-card amber"><div class="label">Credit Customers</div><div class="value" id="statCredit">0</div></div>
        <div class="cust-card amber"><div class="label">Pending Requests</div><div class="value" id="statRequests">0</div></div>
    </div>

    <div class="cust-toolbar">
        <div class="cust-field">
            <label>Search</label>
            <input type="search" id="filterSearch" placeholder="Customer name, contact number, or plate number" oninput="queueCustomerLoad()">
        </div>
        <div class="cust-field">
            <label>Customer Type</label>
            <select id="filterType" onchange="loadCustomers()">
                <option value="">All Types</option>
                <option value="walk-in">Walk-in</option>
                <option value="regular">Regular</option>
                <option value="credit">Credit</option>
            </select>
        </div>
        <div class="cust-field">
            <label>Status</label>
            <select id="filterStatus" onchange="loadCustomers()">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <button type="button" class="cust-btn muted" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
    </div>

    <!-- Tab Navigation -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <div class="cust-tabs" style="margin-bottom:0;">
            <button class="cust-tab active" id="tab-pending" onclick="switchCustTab('pending')">
                <i class="fas fa-inbox"></i> Pending Customer Requests
                <span class="cust-tab-badge" id="tabBadgePending">0</span>
            </button>
            <button class="cust-tab" id="tab-list" onclick="switchCustTab('list')">
                <i class="fas fa-list"></i> Customer List
                <span class="cust-tab-badge" id="tabBadgeList">0</span>
            </button>
        </div>
        <button type="button" class="cust-btn primary" onclick="openCustomerForm()">
            <i class="fas fa-user-plus"></i> Add New Customer
        </button>
    </div>

    <div class="cust-section" id="section-pending">
        <div class="cust-section-head">
            <h2><i class="fas fa-inbox"></i> Pending Customer Requests</h2>
            <span class="cust-count" id="requestCount">0 pending</span>
        </div>
        <table class="cust-table">
            <colgroup>
                <col style="width:18%">
                <col style="width:17%">
                <col style="width:16%">
                <col style="width:18%">
                <col style="width:16%">
                <col style="width:15%">
            </colgroup>
            <thead>
                <tr>
                    <th>Customer Name</th>
                    <th>Contact No.</th>
                    <th>Plate No.</th>
                    <th>Requested By</th>
                    <th>Date Requested</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="requestsBody">
                <tr><td colspan="6" class="empty">Loading customer requests...</td></tr>
            </tbody>
        </table>
    </div>

    <div class="cust-section" id="section-list" style="display:none;">
        <div class="cust-section-head">
            <h2><i class="fas fa-list"></i> Customer List</h2>
            <span class="cust-count" id="customerCount">0 records</span>
        </div>
        <table class="cust-table">
            <colgroup>
                <col style="width:14%">
                <col style="width:22%">
                <col style="width:14%">
                <col style="width:13%">
                <col style="width:12%">
                <col style="width:10%">
                <col style="width:15%">
            </colgroup>
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Contact No.</th>
                    <th>Plate No.</th>
                    <th>Customer Type</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="customersBody">
                <tr><td colspan="7" class="empty">Loading customers...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="toast-stack" id="toastStack"></div>

<div class="modal-backdrop" id="customerFormModal">
    <div class="cust-modal">
        <div class="modal-head">
            <h3><i class="fas fa-user-edit"></i> <span id="formTitle">Add New Customer</span></h3>
            <button type="button" class="modal-close" onclick="closeModal('customerFormModal')">&times;</button>
        </div>
        <form id="customerForm">
            <div class="modal-body">
                <input type="hidden" id="formMode" value="add">
                <input type="hidden" id="recordId" name="id">
                <div class="form-grid">
                    <div class="form-title">Basic Information</div>
                    <div class="cust-field">
                        <label>Customer ID</label>
                        <div class="readonly-box" id="customerIdLabel">Auto-generated</div>
                    </div>
                    <div class="cust-field">
                        <label>Status</label>
                        <select id="status" name="status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>First Name *</label>
                        <input type="text" id="firstName" name="first_name" required>
                    </div>
                    <div class="cust-field">
                        <label>Middle Name</label>
                        <input type="text" id="middleName" name="middle_name">
                    </div>
                    <div class="cust-field">
                        <label>Last Name *</label>
                        <input type="text" id="lastName" name="last_name" required>
                    </div>
                    <div class="cust-field">
                        <label>Contact Number *</label>
                        <input type="text" id="contactNumber" name="contact_number" required>
                    </div>
                    <div class="cust-field">
                        <label>Customer Type *</label>
                        <select id="customerType" name="customer_type" onchange="toggleCreditFields()">
                            <option value="walk-in">Walk-in</option>
                            <option value="regular">Regular</option>
                            <option value="credit">Credit</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>Address</label>
                        <input type="text" id="address" name="address">
                    </div>

                    <div class="form-title">Vehicle Information</div>
                    <div class="cust-field">
                        <label>Plate Number</label>
                        <input type="text" id="plateNo" name="plate_no" placeholder="ABC-1234">
                    </div>
                    <div class="cust-field">
                        <label>Vehicle Make</label>
                        <input type="text" id="vehicleMake" name="vehicle_make" placeholder="Toyota, Honda, etc.">
                    </div>
                    <div class="cust-field">
                        <label>Vehicle Model</label>
                        <input type="text" id="vehicleModel" name="vehicle_model" placeholder="Corolla Altis, XRM, etc.">
                    </div>
                    <div class="cust-field">
                        <label>Vehicle Type</label>
                        <input type="text" id="vehicleType" name="vehicle_type" placeholder="Sedan, SUV, Motorcycle, Truck">
                    </div>

                    <div class="form-title" id="creditTitle">Credit Information</div>
                    <div class="cust-field credit-only">
                        <label>Credit Limit</label>
                        <input type="number" id="creditLimit" name="credit_limit" step="0.01" min="0" value="0">
                    </div>
                    <div class="cust-field credit-only">
                        <label>Outstanding Balance</label>
                        <div class="readonly-box" id="outstandingLabel">System-generated</div>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="cust-btn muted" onclick="closeModal('customerFormModal')">Cancel</button>
                <button type="submit" class="cust-btn primary"><i class="fas fa-save"></i> Save Customer</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="customerViewModal">
    <div class="cust-modal">
        <div class="modal-head">
            <h3><i class="fas fa-id-card"></i> View Customer</h3>
            <button type="button" class="modal-close" onclick="closeModal('customerViewModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="view-grid">
                <div class="view-box">
                    <h4>Customer Information</h4>
                    <div class="info-row"><span>Customer ID</span><span id="viewCustomerId">-</span></div>
                    <div class="info-row"><span>Full Name</span><span id="viewFullName">-</span></div>
                    <div class="info-row"><span>Contact Number</span><span id="viewContact">-</span></div>
                    <div class="info-row"><span>Address</span><span id="viewAddress">-</span></div>
                    <div class="info-row"><span>Customer Type</span><span id="viewType">-</span></div>
                    <div class="info-row"><span>Status</span><span id="viewStatus">-</span></div>
                </div>
                <div class="view-box">
                    <h4>Vehicle Information</h4>
                    <div class="info-row"><span>Plate Number</span><span id="viewPlate">-</span></div>
                    <div class="info-row"><span>Vehicle Make</span><span id="viewMake">-</span></div>
                    <div class="info-row"><span>Vehicle Model</span><span id="viewModel">-</span></div>
                    <div class="info-row"><span>Vehicle Type</span><span id="viewVehicleType">-</span></div>
                </div>
                <div class="view-box">
                    <h4>Credit Information</h4>
                    <div class="info-row"><span>Credit Limit</span><span id="viewCreditLimit">-</span></div>
                    <div class="info-row"><span>Outstanding Balance</span><span id="viewOutstanding">-</span></div>
                    <div class="info-row"><span>Available Credit</span><span id="viewAvailable">-</span></div>
                </div>
                <div class="view-box">
                    <h4>Transaction Summary</h4>
                    <div class="info-row"><span>Total Transactions</span><span id="viewTotalTx">0</span></div>
                    <div class="info-row"><span>Total Merchandise Purchases</span><span id="viewMerchTx">0</span></div>
                    <div class="info-row"><span>Total Job Orders</span><span id="viewJobTx">0</span></div>
                    <div class="info-row"><span>Last Transaction Date</span><span id="viewLastTx">-</span></div>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="cust-btn muted" onclick="closeModal('customerViewModal')">Close</button>
            <button type="button" class="cust-btn primary" onclick="openEditFromCurrent()"><i class="fas fa-edit"></i> Edit Customer</button>
            <button type="button" class="cust-btn danger" id="viewDeactivateBtn" onclick="deactivateCurrentCustomer()"><i class="fas fa-ban"></i> Deactivate Customer</button>
        </div>
    </div>
</div>

<script>
const apiUrl = 'manager_customer_operations.php';
let customerRows = [];
let currentCustomer = null;
let loadTimer = null;

function h(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function labelize(value) {
    value = String(value || '').replace(/-/g, ' ');
    return value ? value.replace(/\b\w/g, ch => ch.toUpperCase()) : '-';
}

function money(value) {
    return '₱' + Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function niceDate(value) {
    if (!value) return '-';
    const d = new Date(String(value).replace(' ', 'T'));
    return isNaN(d.getTime()) ? value : d.toLocaleDateString(undefined, {year:'numeric', month:'short', day:'2-digit'});
}

function toast(message, type = 'success') {
    const stack = document.getElementById('toastStack');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = `${h(message)}<button type="button" aria-label="Close">&times;</button>`;
    el.querySelector('button').onclick = () => el.remove();
    stack.appendChild(el);
    setTimeout(() => el.remove(), 4200);
}

function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
    if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
    }
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    const main = document.querySelector('main.main');
    if (main && modal.parentNode !== main) {
        main.appendChild(modal);
    }
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    const body = modal.querySelector('.modal-body');
    if (body) body.scrollTop = 0;
}

function queueCustomerLoad() {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(loadCustomers, 250);
}

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterType').value = '';
    document.getElementById('filterStatus').value = '';
    loadCustomers();
}

async function loadCustomers() {
    const params = new URLSearchParams({
        action: 'list',
        search: document.getElementById('filterSearch').value.trim(),
        type: document.getElementById('filterType').value,
        status: document.getElementById('filterStatus').value
    });

    const body = document.getElementById('customersBody');
    body.innerHTML = '<tr><td colspan="7" class="empty">Loading customers...</td></tr>';

    try {
        const res = await fetch(`${apiUrl}?${params.toString()}`, {credentials: 'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to load customers.');
        customerRows = data.customers || [];
        renderStats(data.stats || {});
        renderCustomers(customerRows);
    } catch (err) {
        body.innerHTML = `<tr><td colspan="7" class="empty">${h(err.message)}</td></tr>`;
        toast(err.message, 'error');
    }
}

function renderStats(stats) {
    document.getElementById('statTotal').textContent = stats.total || 0;
    document.getElementById('statActive').textContent = stats.active || 0;
    document.getElementById('statInactive').textContent = stats.inactive || 0;
    document.getElementById('statCredit').textContent = stats.credit || 0;
    document.getElementById('statRequests').textContent = stats.pending_requests || 0;
}

function renderCustomers(rows) {
    const body = document.getElementById('customersBody');
    document.getElementById('customerCount').textContent = `${rows.length} record${rows.length === 1 ? '' : 's'}`;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="empty">No customers found.</td></tr>';
        return;
    }
    body.innerHTML = rows.map(row => `
        <tr>
            <td><strong>${h(row.customer_id)}</strong></td>
            <td>${h(row.customer_name)}</td>
            <td>${h(row.contact_number || '-')}</td>
            <td>${h(row.plate_no || '-')}</td>
            <td><span class="pill ${h(row.customer_type)}">${h(labelize(row.customer_type))}</span></td>
            <td><span class="pill ${h(row.status)}">${h(labelize(row.status))}</span></td>
            <td>
                <div class="cust-actions">
                    <button type="button" class="cust-btn" onclick="viewCustomer(${Number(row.id)})"><i class="fas fa-eye"></i> View</button>
                    <button type="button" class="cust-btn" onclick="openCustomerForm(${Number(row.id)})"><i class="fas fa-edit"></i> Edit</button>
                    <button type="button" class="cust-btn danger" onclick="deactivateCustomer(${Number(row.id)})" ${row.status === 'inactive' ? 'disabled' : ''}><i class="fas fa-ban"></i> Deactivate</button>
                </div>
            </td>
        </tr>
    `).join('');
}

async function loadRequests() {
    const body = document.getElementById('requestsBody');
    try {
        const res = await fetch(`${apiUrl}?action=requests`, {credentials: 'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to load requests.');
        const rows = data.requests || [];
        document.getElementById('requestCount').textContent = `${rows.length} pending`;
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" class="empty">No pending customer requests.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(row => {
            const name = [row.first_name, row.middle_name, row.last_name].filter(Boolean).join(' ');
            return `
                <tr>
                    <td><strong>${h(name)}</strong></td>
                    <td>${h(row.contact_number || '-')}</td>
                    <td>${h(row.vehicle_plate || '-')}</td>
                    <td>${h(row.requested_by_name || 'Staff')}</td>
                    <td>${h(niceDate(row.created_at))}</td>
                    <td>
                        <div class="cust-actions">
                            <button type="button" class="cust-btn success" onclick="reviewRequest(${Number(row.id)}, 'approve_request')"><i class="fas fa-check"></i> Approve</button>
                            <button type="button" class="cust-btn danger" onclick="reviewRequest(${Number(row.id)}, 'reject_request')"><i class="fas fa-times"></i> Reject</button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        body.innerHTML = `<tr><td colspan="6" class="empty">${h(err.message)}</td></tr>`;
    }
}

async function reviewRequest(id, action) {
    const verb = action === 'approve_request' ? 'approve' : 'reject';
    if (!confirm(`Are you sure you want to ${verb} this customer request?`)) return;
    const form = new FormData();
    form.append('action', action);
    form.append('id', id);
    try {
        const res = await fetch(apiUrl, {method:'POST', body:form, credentials:'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Request review failed.');
        toast(data.message || 'Request updated.');
        await Promise.all([loadRequests(), loadCustomers()]);
    } catch (err) {
        toast(err.message, 'error');
    }
}

function toggleCreditFields() {
    const isCredit = document.getElementById('customerType').value === 'credit';
    document.querySelectorAll('.credit-only').forEach(el => el.style.display = isCredit ? '' : 'none');
    document.getElementById('creditTitle').style.display = isCredit ? '' : 'none';
    if (!isCredit) document.getElementById('creditLimit').value = '0';
}

function openCustomerForm(id = null) {
    const form = document.getElementById('customerForm');
    form.reset();
    document.getElementById('formMode').value = id ? 'edit' : 'add';
    document.getElementById('formTitle').textContent = id ? 'Edit Customer' : 'Add New Customer';
    document.getElementById('recordId').value = '';
    document.getElementById('customerIdLabel').textContent = 'Auto-generated';
    document.getElementById('outstandingLabel').textContent = 'System-generated';

    if (id) {
        const row = customerRows.find(c => Number(c.id) === Number(id));
        if (!row) return;
        currentCustomer = row;
        document.getElementById('recordId').value = row.id;
        document.getElementById('customerIdLabel').textContent = row.customer_id || '-';
        document.getElementById('firstName').value = row.first_name || '';
        document.getElementById('middleName').value = row.middle_name || '';
        document.getElementById('lastName').value = row.last_name || '';
        document.getElementById('contactNumber').value = row.contact_number || '';
        document.getElementById('address').value = row.address || '';
        document.getElementById('customerType').value = row.customer_type || 'walk-in';
        document.getElementById('status').value = row.status || 'active';
        document.getElementById('plateNo').value = row.plate_no || '';
        document.getElementById('vehicleMake').value = row.vehicle_make || '';
        document.getElementById('vehicleModel').value = row.vehicle_model || '';
        document.getElementById('vehicleType').value = row.vehicle_type || '';
        document.getElementById('creditLimit').value = row.credit_limit || 0;
        document.getElementById('outstandingLabel').textContent = money(row.outstanding_balance);
    }

    toggleCreditFields();
    openModal('customerFormModal');
}

document.getElementById('customerForm').addEventListener('submit', async event => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const mode = document.getElementById('formMode').value;
    form.append('action', mode === 'edit' ? 'update' : 'add');
    try {
        const res = await fetch(apiUrl, {method:'POST', body:form, credentials:'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to save customer.');
        closeModal('customerFormModal');
        toast(data.message || 'Customer saved.');
        await loadCustomers();
    } catch (err) {
        toast(err.message, 'error');
    }
});

async function viewCustomer(id) {
    try {
        const res = await fetch(`${apiUrl}?action=view&id=${Number(id)}`, {credentials:'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to load customer.');
        currentCustomer = data.customer;
        const summary = data.summary || {};

        document.getElementById('viewCustomerId').textContent = currentCustomer.customer_id || '-';
        document.getElementById('viewFullName').textContent = currentCustomer.customer_name || '-';
        document.getElementById('viewContact').textContent = currentCustomer.contact_number || '-';
        document.getElementById('viewAddress').textContent = currentCustomer.address || '-';
        document.getElementById('viewType').textContent = labelize(currentCustomer.customer_type);
        document.getElementById('viewStatus').textContent = labelize(currentCustomer.status);
        document.getElementById('viewPlate').textContent = currentCustomer.plate_no || '-';
        document.getElementById('viewMake').textContent = currentCustomer.vehicle_make || '-';
        document.getElementById('viewModel').textContent = currentCustomer.vehicle_model || '-';
        document.getElementById('viewVehicleType').textContent = currentCustomer.vehicle_type || '-';
        document.getElementById('viewCreditLimit').textContent = money(currentCustomer.credit_limit);
        document.getElementById('viewOutstanding').textContent = money(currentCustomer.outstanding_balance);
        document.getElementById('viewAvailable').textContent = money(currentCustomer.available_credit);
        document.getElementById('viewTotalTx').textContent = summary.total_transactions || 0;
        document.getElementById('viewMerchTx').textContent = summary.merchandise_purchases || 0;
        document.getElementById('viewJobTx').textContent = summary.job_orders || 0;
        document.getElementById('viewLastTx').textContent = niceDate(summary.last_transaction);
        document.getElementById('viewDeactivateBtn').disabled = currentCustomer.status === 'inactive';
        openModal('customerViewModal');
    } catch (err) {
        toast(err.message, 'error');
    }
}

function openEditFromCurrent() {
    if (!currentCustomer) return;
    closeModal('customerViewModal');
    openCustomerForm(currentCustomer.id);
}

function deactivateCurrentCustomer() {
    if (currentCustomer) deactivateCustomer(currentCustomer.id);
}

async function deactivateCustomer(id) {
    if (!confirm('Deactivate this customer?')) return;
    const form = new FormData();
    form.append('action', 'deactivate');
    form.append('id', id);
    try {
        const res = await fetch(apiUrl, {method:'POST', body:form, credentials:'same-origin'});
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to deactivate customer.');
        closeModal('customerViewModal');
        toast(data.message || 'Customer deactivated.');
        await loadCustomers();
    } catch (err) {
        toast(err.message, 'error');
    }
}

// ── Tab switching ────────────────────────────────────────────
function switchCustTab(tab) {
    document.getElementById('section-pending').style.display = tab === 'pending' ? '' : 'none';
    document.getElementById('section-list').style.display    = tab === 'list'    ? '' : 'none';
    document.getElementById('tab-pending').classList.toggle('active', tab === 'pending');
    document.getElementById('tab-list').classList.toggle('active',    tab === 'list');
}

document.addEventListener('DOMContentLoaded', () => {
    loadCustomers();
    loadRequests();
});

// Keep tab badges in sync after data loads
const _origLoadCustomers = loadCustomers;
loadCustomers = async function() {
    await _origLoadCustomers.apply(this, arguments);
    const rows = document.querySelectorAll('#customersBody tr:not(.empty-row)');
    const badge = document.getElementById('tabBadgeList');
    if (badge) badge.textContent = document.getElementById('customerCount')?.textContent?.replace(' records','') || '0';
};
const _origLoadRequests = loadRequests;
loadRequests = async function() {
    await _origLoadRequests.apply(this, arguments);
    const badge = document.getElementById('tabBadgePending');
    if (badge) badge.textContent = document.getElementById('requestCount')?.textContent?.replace(' pending','') || '0';
};
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
