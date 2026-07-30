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
.cust-page { color:#0f172a; padding-bottom:40px; }
.cust-head { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-top:20px; margin-bottom:18px; border-bottom:2px solid #e2e8f0; padding-bottom:14px; }
.cust-head h1 { margin:0; color:#002f70; font-size:26px; font-weight:800; text-transform:uppercase; display:flex; align-items:center; gap:10px; }

/* Dashboard Cards - 6 Cards Grid */
.cust-cards { display:grid; grid-template-columns:repeat(6, minmax(130px, 1fr)); gap:12px; margin-bottom:18px; }
.cust-card { background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:14px 16px; box-shadow:0 2px 6px rgba(15,23,42,.04); }
.cust-card .label { color:#64748b; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.3px; }
.cust-card .value { color:#002f70; font-size:26px; line-height:1.1; font-weight:900; margin-top:6px; }
.cust-card.green .value { color:#16a34a; }
.cust-card.gray .value { color:#64748b; }
.cust-card.amber .value { color:#d97706; }
.cust-card.blue .value { color:#2563eb; }

/* Filter Toolbar */
.cust-toolbar { background:#fff; border:1px solid #cbd5e1; border-radius:8px; padding:14px 18px; display:grid; grid-template-columns:2fr 1.2fr 1fr 1fr 1fr auto; gap:12px; align-items:end; margin-bottom:18px; box-shadow:0 2px 6px rgba(15,23,42,.04); }
.cust-field label { display:block; font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; margin-bottom:4px; }
.cust-field input, .cust-field select, .cust-field textarea { width:100%; height:38px; border:1px solid #cbd5e1; border-radius:6px; padding:6px 10px; font-size:13px; color:#0f172a; background:#fff; font-family:inherit; }
.cust-field textarea { height:70px; resize:vertical; }
.cust-field input:focus, .cust-field select:focus, .cust-field textarea:focus { outline:none; border-color:#002f70; box-shadow:0 0 0 3px rgba(0,47,112,.12); }

/* Navigation Tabs */
.cust-tabs { display:flex; gap:6px; background:#f1f5f9; border-radius:8px; padding:4px; width:fit-content; }
.cust-tab, button.cust-tab { display:flex; align-items:center; gap:8px; padding:8px 16px; background:#ffffff !important; background-color:#ffffff !important; border:1px solid #cbd5e1 !important; border-radius:6px; font-size:13px; font-weight:700; color:#334155 !important; cursor:pointer; transition:all .18s; white-space:nowrap; }
.cust-tab:hover, button.cust-tab:hover { color:#0f172a !important; background:#f8fafc !important; border-color:#94a3b8 !important; }
.cust-tab.active, button.cust-tab.active { background:#002f70 !important; background-color:#002f70 !important; color:#ffffff !important; border:1px solid #002f70 !important; box-shadow:0 2px 6px rgba(0,47,112,.2); }
.cust-tab-badge { font-size:11px; font-weight:800; padding:2px 7px; border-radius:20px; min-width:18px; text-align:center; background:rgba(255,255,255,.3); color:#fff; }
.cust-tab:not(.active) .cust-tab-badge { background:#cbd5e1 !important; color:#334155 !important; }
.cust-tab.active .cust-tab-badge { background:rgba(255,255,255,.25) !important; color:#ffffff !important; }


/* Buttons - Plain Outline Uniform Style */
.btn-plain { border:1px solid #cbd5e1; background:transparent !important; color:#002f70; border-radius:6px; height:38px; padding:0 14px; font-weight:700; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; text-decoration:none; transition:all 0.15s ease; box-shadow:none !important; }
.btn-plain:hover { background:#f1f5f9 !important; border-color:#002f70; }
.btn-plain.primary { border-color:#002f70; color:#002f70; }
.btn-plain.primary:hover { background:#002f70 !important; color:#fff; }
.btn-plain.success { border-color:#16a34a; color:#16a34a; }
.btn-plain.success:hover { background:#16a34a !important; color:#fff; }
.btn-plain.danger { border-color:#dc2626; color:#dc2626; }
.btn-plain.danger:hover { background:#dc2626 !important; color:#fff; }
.btn-plain.warning { border-color:#d97706; color:#d97706; }
.btn-plain.warning:hover { background:#d97706 !important; color:#fff; }
.btn-plain.muted { border-color:#94a3b8; color:#64748b; }
.btn-plain.muted:hover { background:#e2e8f0 !important; color:#0f172a; }

/* Section & Table */
.cust-section { background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 2px 6px rgba(15,23,42,.04); margin-bottom:18px; overflow:hidden; }
.cust-section-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 18px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.cust-section-head h2 { margin:0; color:#002f70; font-size:16px; font-weight:800; display:flex; align-items:center; gap:8px; }
.cust-count { color:#64748b; font-weight:700; font-size:12px; }

.cust-table { width:100%; border-collapse:collapse; table-layout:fixed; }
.cust-table th { background:#002f70; color:#fff; padding:10px 12px; text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.3px; }
.cust-table td { padding:10px 12px; border-bottom:1px solid #f1f5f9; font-size:13px; vertical-align:middle; word-break:break-word; }
.cust-table tr:hover td { background:#f8fafc; }
.cust-table .empty { text-align:center; color:#64748b; padding:32px 16px; font-weight:600; }
.cust-actions { display:flex; gap:5px; flex-wrap:wrap; justify-content:flex-end; }

/* Pills */
.pill { display:inline-flex; align-items:center; justify-content:center; padding:3px 8px; border-radius:999px; font-size:11px; font-weight:800; text-transform:uppercase; }
.pill.active { background:#dcfce7; color:#166534; }
.pill.inactive { background:#f1f5f9; color:#64748b; }
.pill.archived { background:#fee2e2; color:#991b1b; }
.pill.walk-in { background:#eff6ff; color:#1d4ed8; }
.pill.regular { background:#f0fdf4; color:#15803d; }
.pill.credit { background:#fff7ed; color:#c2410c; }
.pill.fleet { background:#faf5ff; color:#6b21a8; }
.pill.corporate { background:#f0f9ff; color:#0369a1; }

/* Modals - Framed between Top Header and Bottom Footer */
.modal-backdrop { display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:99999; align-items:center; justify-content:center; padding-top:70px; padding-bottom:50px; overflow:hidden; }
.modal-backdrop.open { display:flex; }
.cust-modal { width:min(920px, calc(100% - 32px)); max-height:calc(100vh - 130px); background:#fff; border-radius:10px; border:1px solid #e2e8f0; box-shadow:0 20px 50px rgba(0,0,0,.25); display:flex; flex-direction:column; overflow:hidden; margin:0 auto; }
.cust-modal.sm { width:min(480px, 94vw); }
.cust-modal > form { display:flex; flex-direction:column; min-height:0; flex:1 1 auto; }
.modal-head { padding:14px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex:0 0 auto; }
.modal-head h3 { margin:0; color:#002f70; font-size:17px; font-weight:800; display:flex; align-items:center; gap:8px; }
.modal-close { border:0; background:transparent; font-size:22px; line-height:1; color:#64748b; cursor:pointer; }
.modal-body { padding:18px 22px; overflow-y:auto; flex:1 1 auto; }
.modal-actions { padding:12px 20px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:flex-end; gap:8px; flex:0 0 auto; }
body.modal-open { overflow:hidden; }

.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.form-title { grid-column:1 / -1; color:#002f70; font-weight:800; font-size:12px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px; margin-top:10px; margin-bottom:2px; }

/* Dynamic Vehicle Box */
.vehicle-card { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:12px; margin-bottom:10px; position:relative; }
.vehicle-card .remove-v-btn { position:absolute; top:8px; right:10px; border:0; background:none; color:#dc2626; font-size:16px; cursor:pointer; }

/* View Grid */
.view-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.view-box { border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px; background:#fff; }
.view-box h4 { margin:0 0 8px; color:#002f70; font-size:13px; font-weight:800; text-transform:uppercase; border-bottom:1px solid #f1f5f9; padding-bottom:4px; }
.info-row { display:flex; justify-content:space-between; gap:10px; border-bottom:1px solid #f1f5f9; padding:5px 0; font-size:13px; }
.info-row:last-child { border-bottom:0; }
.info-row span:first-child { color:#64748b; font-weight:600; }
.info-row span:last-child { font-weight:700; text-align:right; }

/* Timeline UI */
.timeline { position:relative; padding-left:20px; border-left:2px solid #cbd5e1; margin-top:8px; }
.timeline-item { position:relative; margin-bottom:12px; font-size:13px; }
.timeline-item::before { content:''; position:absolute; left:-25px; top:3px; width:10px; height:10px; border-radius:50%; background:#002f70; border:2px solid #fff; }
.timeline-item .t-time { font-size:11px; color:#64748b; font-weight:600; }
.timeline-item .t-title { font-weight:700; color:#0f172a; }

/* Toasts */
.toast-stack { position:fixed; top:18px; right:18px; z-index:110000; display:flex; flex-direction:column; gap:8px; }
.toast { min-width:260px; max-width:380px; color:#fff; border-radius:8px; padding:12px 36px 12px 14px; box-shadow:0 8px 24px rgba(15,23,42,.2); font-weight:600; font-size:13px; position:relative; }
.toast.success { background:#16a34a; }
.toast.error { background:#dc2626; }
.toast button { position:absolute; top:6px; right:9px; border:0; background:transparent; color:#fff; font-size:18px; cursor:pointer; }

@media (max-width:1100px) {
    .cust-cards { grid-template-columns:repeat(3, 1fr); }
    .cust-toolbar { grid-template-columns:1fr 1fr 1fr; }
}
@media (max-width:768px) {
    .cust-cards, .cust-toolbar, .form-grid, .view-grid { grid-template-columns:1fr; }
}
</style>

<div class="cust-page">
    <div class="cust-head">
        <div>
            <h1><i class="fas fa-users"></i> Customers</h1>
        </div>
    </div>

    <!-- 6 Dashboard Cards -->
    <div class="cust-cards">
        <div class="cust-card"><div class="label">Total Customers</div><div class="value" id="statTotal">0</div></div>
        <div class="cust-card green"><div class="label">Active Customers</div><div class="value" id="statActive">0</div></div>
        <div class="cust-card gray"><div class="label">Inactive Customers</div><div class="value" id="statInactive">0</div></div>
        <div class="cust-card amber"><div class="label">Credit Customers</div><div class="value" id="statCredit">0</div></div>
        <div class="cust-card blue"><div class="label">Pending Requests</div><div class="value" id="statRequests">0</div></div>
        <div class="cust-card green"><div class="label">New This Month</div><div class="value" id="statNewMonth">0</div></div>
    </div>

    <!-- Search & Filters Toolbar -->
    <div class="cust-toolbar">
        <div class="cust-field">
            <label>Search</label>
            <input type="search" id="filterSearch" placeholder="Customer Name / ID / Contact / Plate" oninput="queueLoad()">
        </div>
        <div class="cust-field">
            <label>Customer Type</label>
            <select id="filterType" onchange="loadManagerCustomers()">
                <option value="">All Types</option>
                <option value="walk-in">Walk-in</option>
                <option value="regular">Regular</option>
                <option value="credit">Credit</option>
                <option value="fleet">Fleet</option>
                <option value="corporate">Corporate</option>
            </select>
        </div>
        <div class="cust-field">
            <label>Status</label>
            <select id="filterStatus" onchange="loadManagerCustomers()">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="cust-field">
            <label>Date Registered From</label>
            <input type="date" id="filterDateFrom" onchange="loadManagerCustomers()">
        </div>
        <div class="cust-field">
            <label>Date Registered To</label>
            <input type="date" id="filterDateTo" onchange="loadManagerCustomers()">
        </div>
        <div style="display:flex; gap:6px;">
            <button type="button" class="btn-plain primary" onclick="loadManagerCustomers()"><i class="fas fa-filter"></i> Filter</button>
            <button type="button" class="btn-plain muted" onclick="resetFilters()"><i class="fas fa-undo"></i> Reset</button>
        </div>
    </div>

    <!-- Tabs Navigation & Add New Customer Button -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
        <div class="cust-tabs">
            <button class="cust-tab active" id="tab-list" onclick="switchCustTab('list')">
                <i class="fas fa-list"></i> Customer List
                <span class="cust-tab-badge" id="tabBadgeList">0</span>
            </button>
            <button class="cust-tab" id="tab-pending" onclick="switchCustTab('pending')">
                <i class="fas fa-inbox"></i> Pending Customer Requests
                <span class="cust-tab-badge" id="tabBadgePending">0</span>
            </button>
            <button class="cust-tab" id="tab-archived" onclick="switchCustTab('archived')">
                <i class="fas fa-archive"></i> Archived Customers
                <span class="cust-tab-badge" id="tabBadgeArchived">0</span>
            </button>
        </div>
        <button type="button" class="btn-plain primary" onclick="openCustomerForm('add')">
            <i class="fas fa-user-plus"></i> Add New Customer
        </button>
    </div>

    <!-- TAB 1: CUSTOMER LIST -->
    <div class="cust-section" id="section-list">
        <div class="cust-section-head">
            <h2><i class="fas fa-list"></i> Customer List</h2>
            <span class="cust-count" id="customerCount">0 records</span>
        </div>
        <table class="cust-table">
            <colgroup>
                <col style="width:10%">
                <col style="width:14%">
                <col style="width:7%">
                <col style="width:9%">
                <col style="width:13%">
                <col style="width:8%">
                <col style="width:8%">
                <col style="width:9%">
                <col style="width:9%">
                <col style="width:6%">
                <col style="width:7%">
            </colgroup>
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Type</th>
                    <th>Contact No.</th>
                    <th>Vehicles</th>
                    <th>Credit Limit</th>
                    <th>Outstanding</th>
                    <th>Last Transaction</th>
                    <th>Customer Since</th>
                    <th>Status</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="customersBody">
                <tr><td colspan="11" class="empty">Loading customers...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- TAB 2: PENDING CUSTOMER REQUESTS -->
    <div class="cust-section" id="section-pending" style="display:none;">
        <div class="cust-section-head">
            <h2><i class="fas fa-inbox"></i> Pending Customer Requests (Staff Requests)</h2>
            <span class="cust-count" id="requestCount">0 pending</span>
        </div>
        <table class="cust-table">
            <colgroup>
                <col style="width:20%">
                <col style="width:15%">
                <col style="width:15%">
                <col style="width:18%">
                <col style="width:17%">
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
                <tr><td colspan="6" class="empty">Loading pending requests...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- TAB 3: ARCHIVED CUSTOMERS -->
    <div class="cust-section" id="section-archived" style="display:none;">
        <div class="cust-section-head">
            <h2><i class="fas fa-archive"></i> Archived Customers</h2>
            <span class="cust-count" id="archivedCount">0 records</span>
        </div>
        <table class="cust-table">
            <colgroup>
                <col style="width:14%">
                <col style="width:22%">
                <col style="width:12%">
                <col style="width:16%">
                <col style="width:21%">
                <col style="width:15%">
            </colgroup>
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Type</th>
                    <th>Archived Date</th>
                    <th>Reason</th>
                    <th style="text-align:center;">Actions</th>
                </tr>
            </thead>
            <tbody id="archivedBody">
                <tr><td colspan="6" class="empty">Loading archived customers...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="toast-stack" id="toastStack"></div>

<!-- MODAL: ADD / EDIT CUSTOMER -->
<div class="modal-backdrop" id="customerFormModal">
    <div class="cust-modal">
        <div class="modal-head">
            <h3><i class="fas fa-user-edit"></i> <span id="formTitle">Add New Customer</span></h3>
            <button type="button" class="modal-close" onclick="closeModal('customerFormModal')">&times;</button>
        </div>
        <form id="customerForm" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" id="formMode" value="add">
                <input type="hidden" id="recordId" name="id">

                <div class="form-grid">
                    <!-- Basic Information -->
                    <div class="form-title">Basic Information</div>
                    <div class="cust-field">
                        <label>Customer Type <span style="color:red;">*</span></label>
                        <select id="customerType" name="customer_type" required onchange="toggleCreditSection()">
                            <option value="walk-in">Walk-in</option>
                            <option value="regular">Regular</option>
                            <option value="credit">Credit</option>
                            <option value="fleet">Fleet</option>
                            <option value="corporate">Corporate</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>Status <span style="color:red;">*</span></label>
                        <select id="custStatus" name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>First Name / Name <span style="color:red;">*</span></label>
                        <input type="text" id="firstName" name="first_name" required placeholder="e.g. Juan">
                    </div>
                    <div class="cust-field">
                        <label>Middle Name</label>
                        <input type="text" id="middleName" name="middle_name" placeholder="e.g. Santos">
                    </div>
                    <div class="cust-field">
                        <label>Last Name</label>
                        <input type="text" id="lastName" name="last_name" placeholder="e.g. Dela Cruz">
                    </div>
                    <div class="cust-field">
                        <label>Contact Number</label>
                        <input type="text" id="contactNumber" name="contact_number" placeholder="0917xxxxxxx">
                    </div>
                    <div class="cust-field">
                        <label>Address</label>
                        <input type="text" id="address" name="address" placeholder="Complete Address">
                    </div>
                    <div class="cust-field">
                        <label>Email (Optional)</label>
                        <input type="email" id="email" name="email" placeholder="customer@example.com">
                    </div>

                    <!-- Registered Vehicles -->
                    <div class="form-title" style="display:flex; justify-content:space-between; align-items:center;">
                        <span>Registered Vehicles</span>
                        <button type="button" class="btn-plain success" style="height:28px; padding:0 8px; font-size:11px;" onclick="addVehicleRow()">
                            <i class="fas fa-plus"></i> Add Another Vehicle
                        </button>
                    </div>

                    <div id="vehiclesContainer" style="grid-column:1 / -1;">
                        <!-- Dynamic vehicle rows will render here -->
                    </div>

                    <!-- Verification Documents -->
                    <div class="form-title">Verification Documents</div>
                    <div class="cust-field">
                        <label>Government ID Type</label>
                        <select id="govIdType" name="gov_id_type">
                            <option value="">Select ID Type</option>
                            <option value="Driver's License">Driver's License</option>
                            <option value="UMID">UMID</option>
                            <option value="Passport">Passport</option>
                            <option value="PhilSys">PhilSys</option>
                            <option value="TIN">TIN</option>
                            <option value="Others">Others</option>
                        </select>
                    </div>
                    <div class="cust-field">
                        <label>Government ID Upload</label>
                        <input type="file" id="govIdFile" name="gov_id_file" accept="image/*,.pdf">
                    </div>
                    <div class="cust-field">
                        <label>Certificate of Registration (CR)</label>
                        <input type="file" id="crFile" name="cr_file" accept="image/*,.pdf">
                    </div>
                    <div class="cust-field">
                        <label>OR Upload (Optional)</label>
                        <input type="file" id="orFile" name="or_file" accept="image/*,.pdf">
                    </div>

                    <!-- Financial Information (Credit Customer Only) -->
                    <div class="form-title credit-only">Credit Information (Credit Customer Only)</div>
                    <div class="cust-field credit-only">
                        <label>Credit Limit (₱) <span style="color:red;">*</span></label>
                        <input type="number" id="creditLimit" name="credit_limit" step="0.01" min="0" value="0.00" placeholder="e.g. 50000" oninput="calcAvailableCredit()">
                    </div>
                    <div class="cust-field credit-only">
                        <label>Payment Terms</label>
                        <select id="creditTerms" name="credit_terms">
                            <option value="15 Days">15 Days</option>
                            <option value="30 Days" selected>30 Days</option>
                            <option value="45 Days">45 Days</option>
                            <option value="60 Days">60 Days</option>
                        </select>
                    </div>
                    <div class="cust-field credit-only">
                        <label>Outstanding Balance <small style="color:#64748b; font-weight:normal;">(Read Only - System Generated)</small></label>
                        <input type="text" id="outstandingBalance" readonly value="₱0.00" style="background:#f1f5f9; font-weight:700; color:#475569; cursor:not-allowed;">
                    </div>
                    <div class="cust-field credit-only">
                        <label>Available Credit <small style="color:#166534; font-weight:normal;">(Read Only - Credit Limit − Outstanding)</small></label>
                        <input type="text" id="availableCredit" readonly value="₱0.00" style="background:#f0fdf4; font-weight:700; color:#166534; cursor:not-allowed; border:1px solid #bbf7d0;">
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain muted" onclick="closeModal('customerFormModal')">Cancel</button>
                <button type="submit" class="btn-plain primary"><i class="fas fa-save"></i> Save Customer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: VIEW CUSTOMER -->
<div class="modal-backdrop" id="customerViewModal">
    <div class="cust-modal">
        <div class="modal-head">
            <h3><i class="fas fa-id-card"></i> View Customer Profile</h3>
            <button type="button" class="modal-close" onclick="closeModal('customerViewModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="view-grid">
                <!-- Summary -->
                <div class="view-box">
                    <h4>Customer Summary</h4>
                    <div class="info-row"><span>Customer ID</span><span id="vCustId">-</span></div>
                    <div class="info-row"><span>Customer Since</span><span id="vCustSince">-</span></div>
                    <div class="info-row"><span>Customer Type</span><span id="vType">-</span></div>
                    <div class="info-row"><span>Status</span><span id="vStatus">-</span></div>
                    <div class="info-row"><span>Contact No.</span><span id="vContact">-</span></div>
                    <div class="info-row"><span>Email</span><span id="vEmail">-</span></div>
                    <div class="info-row"><span>Address</span><span id="vAddress">-</span></div>
                </div>

                <!-- Credit Summary -->
                <div class="view-box">
                    <h4>Credit Summary</h4>
                    <div class="info-row"><span>Credit Limit</span><span id="vCreditLimit">₱0.00</span></div>
                    <div class="info-row"><span>Outstanding Balance</span><span id="vOutstanding">₱0.00</span></div>
                    <div class="info-row"><span>Available Credit</span><span id="vAvailableCredit">₱0.00</span></div>
                    <div class="info-row"><span>Payment Terms</span><span id="vTerms">30 Days</span></div>
                </div>

                <!-- Vehicles Table -->
                <div class="view-box" style="grid-column:1 / -1;">
                    <h4>Registered Vehicles</h4>
                    <table class="cust-table" style="margin-top:6px;">
                        <thead>
                            <tr>
                                <th>Plate No.</th>
                                <th>Vehicle Type</th>
                                <th>Brand</th>
                                <th>Model</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="vVehiclesBody">
                            <tr><td colspan="6" class="empty">No vehicles registered.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Transactions History -->
                <div class="view-box" style="grid-column:1 / -1;">
                    <h4>Transaction History</h4>
                    <table class="cust-table" style="margin-top:6px;">
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="vTxBody">
                            <tr><td colspan="5" class="empty">No recent transactions.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Job Order History -->
                <div class="view-box" style="grid-column:1 / -1;">
                    <h4>Job Order History</h4>
                    <table class="cust-table" style="margin-top:6px;">
                        <thead>
                            <tr>
                                <th>JO No.</th>
                                <th>Vehicle</th>
                                <th>Service</th>
                                <th>Mechanic</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="vJoBody">
                            <tr><td colspan="5" class="empty">No job orders found.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Uploaded Documents -->
                <div class="view-box">
                    <h4>Uploaded Documents</h4>
                    <div class="info-row"><span>Govt ID (<span id="vGovType">ID</span>)</span><span id="vGovFileLink">-</span></div>
                    <div class="info-row"><span>Certificate of Reg. (CR)</span><span id="vCrFileLink">-</span></div>
                    <div class="info-row"><span>Official Receipt (OR)</span><span id="vOrFileLink">-</span></div>
                </div>

                <!-- Timeline Log -->
                <div class="view-box">
                    <h4>Activity Timeline</h4>
                    <div class="timeline" id="vTimeline">
                        <!-- Timeline events render here -->
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-plain muted" onclick="closeModal('customerViewModal')">Close</button>
            <button type="button" class="btn-plain primary" onclick="editFromCurrentView()"><i class="fas fa-edit"></i> Edit Customer</button>
            <button type="button" class="btn-plain danger" onclick="openArchiveFromView()"><i class="fas fa-archive"></i> Archive Customer</button>
        </div>
    </div>
</div>

<!-- MODAL: ARCHIVE CUSTOMER -->
<div class="modal-backdrop" id="archiveModal">
    <div class="cust-modal sm">
        <div class="modal-head">
            <h3><i class="fas fa-archive"></i> Archive Customer</h3>
            <button type="button" class="modal-close" onclick="closeModal('archiveModal')">&times;</button>
        </div>
        <form id="archiveForm">
            <div class="modal-body">
                <input type="hidden" id="archiveCustId">

                <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:6px; font-size:13px; font-weight:600; margin-bottom:14px;">
                    Archived customers cannot receive new transactions. History remains safely stored.
                </div>

                <div class="cust-field mb-3">
                    <label>Reason for Archiving <span style="color:red;">*</span></label>
                    <select id="archiveReason" required>
                        <option value="Duplicate">Duplicate</option>
                        <option value="Inactive">Inactive</option>
                        <option value="Requested by Customer">Requested by Customer</option>
                        <option value="Fleet Closed">Fleet Closed</option>
                        <option value="Others">Others</option>
                    </select>
                </div>

                <div class="cust-field">
                    <label>Remarks</label>
                    <textarea id="archiveRemarks" placeholder="Enter optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain muted" onclick="closeModal('archiveModal')">Cancel</button>
                <button type="submit" class="btn-plain danger"><i class="fas fa-archive"></i> Confirm Archive</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: REVIEW PENDING REQUEST -->
<div class="modal-backdrop" id="reviewModal">
    <div class="cust-modal sm">
        <div class="modal-head">
            <h3><i class="fas fa-tasks"></i> Review Customer Request</h3>
            <button type="button" class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <form id="reviewForm">
            <div class="modal-body">
                <input type="hidden" id="reviewReqId">
                <div class="info-row"><span>Customer Name</span><span id="revName">-</span></div>
                <div class="info-row"><span>Contact No.</span><span id="revContact">-</span></div>
                <div class="info-row"><span>Plate No.</span><span id="revPlate">-</span></div>
                <div class="info-row"><span>Requested By</span><span id="revReqBy">-</span></div>

                <div class="cust-field" style="margin-top:14px;">
                    <label>Manager Remarks (Optional)</label>
                    <textarea id="reviewRemarks" placeholder="Add approval/rejection notes..."></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-plain danger" onclick="submitReviewAction('reject')"><i class="fas fa-times"></i> Reject</button>
                <button type="button" class="btn-plain success" onclick="submitReviewAction('approve')"><i class="fas fa-check"></i> Approve & Create Customer</button>
            </div>
        </form>
    </div>
</div>

<script>
const apiUrl = 'manager_customer_operations.php';
let activeTab = 'list';
let currentCustomer = null;
let currentRequests = [];
let loadTimer = null;
let vehicleIndex = 0;

function h(val) {
    return String(val ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function money(val) {
    return '₱' + Number(val || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function toast(msg, type = 'success') {
    const stack = document.getElementById('toastStack');
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = `${h(msg)}<button type="button">&times;</button>`;
    el.querySelector('button').onclick = () => el.remove();
    stack.appendChild(el);
    setTimeout(() => el.remove(), 4000);
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
    modal.classList.add('open');
    document.body.classList.add('modal-open');
    const body = modal.querySelector('.modal-body');
    if (body) body.scrollTop = 0;
}

function queueLoad() {
    clearTimeout(loadTimer);
    loadTimer = setTimeout(loadManagerCustomers, 250);
}

function switchCustTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.cust-tab').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tab)?.classList.add('active');

    document.getElementById('section-list').style.display = tab === 'list' ? 'block' : 'none';
    document.getElementById('section-pending').style.display = tab === 'pending' ? 'block' : 'none';
    document.getElementById('section-archived').style.display = tab === 'archived' ? 'block' : 'none';

    if (tab === 'pending') {
        loadCustomerRequests();
    } else {
        loadManagerCustomers();
    }
}

function resetFilters() {
    document.getElementById('filterSearch').value = '';
    document.getElementById('filterType').value = '';
    document.getElementById('filterStatus').value = '';
    document.getElementById('filterDateFrom').value = '';
    document.getElementById('filterDateTo').value = '';
    loadManagerCustomers();
}

function loadManagerCustomers() {
    const search = document.getElementById('filterSearch').value;
    const type = document.getElementById('filterType').value;
    const status = document.getElementById('filterStatus').value;
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;

    const params = new URLSearchParams({
        action: 'list',
        tab: activeTab,
        search, type, status, date_from: dateFrom, date_to: dateTo
    });

    const bodyId = activeTab === 'archived' ? 'archivedBody' : 'customersBody';
    document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Loading customers...</td></tr>`;

    fetch(`${apiUrl}?${params.toString()}`)
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON API output:", text);
                document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Error loading customers. Please refresh page.</td></tr>`;
                return;
            }
            if (!res.success) {
                toast(res.error || 'Failed to load customers.', 'error');
                return;
            }
            updateDashboardCards(res.stats || {});
            if (activeTab === 'archived') {
                renderArchivedTable(res.customers || []);
            } else {
                renderCustomerListTable(res.customers || []);
            }
        })
        .catch(err => {
            document.getElementById(bodyId).innerHTML = `<tr><td colspan="10" class="empty">Network error loading customers.</td></tr>`;
            toast('Network error loading customers.', 'error');
        });
}

function updateDashboardCards(stats) {
    document.getElementById('statTotal').innerText = stats.total || 0;
    document.getElementById('statActive').innerText = stats.active || 0;
    document.getElementById('statInactive').innerText = stats.inactive || 0;
    document.getElementById('statCredit').innerText = stats.credit || 0;
    document.getElementById('statRequests').innerText = stats.pending_requests || 0;
    document.getElementById('statNewMonth').innerText = stats.new_this_month || 0;

    document.getElementById('tabBadgePending').innerText = stats.pending_requests || 0;
    document.getElementById('tabBadgeArchived').innerText = stats.archived || 0;
    document.getElementById('tabBadgeList').innerText = stats.total || 0;
}

function formatVehicleCell(c) {
    if (c.vehicles && c.vehicles.length > 0) {
        return c.vehicles.map(v => {
            const plate = h(v.plate_number || '');
            const model = h([v.brand, v.model].filter(Boolean).join(' '));
            return `<div style="font-size:11px; white-space:nowrap; margin-bottom:2px;"><span class="pill regular" style="font-size:10px; padding:2px 5px; background:#eef2ff; color:#3730a3; font-weight:700;">${plate}</span> ${model ? '<small style="color:#555; font-weight:600;">(' + model + ')</small>' : ''}</div>`;
        }).join('');
    }
    if (c.plate_no) {
        const plate = h(c.plate_no);
        const model = h([c.vehicle_make, c.vehicle_model].filter(Boolean).join(' '));
        return `<div style="font-size:11px; white-space:nowrap;"><span class="pill regular" style="font-size:10px; padding:2px 5px; background:#eef2ff; color:#3730a3; font-weight:700;">${plate}</span> ${model ? '<small style="color:#555; font-weight:600;">(' + model + ')</small>' : ''}</div>`;
    }
    return `<span style="color:#888; font-size:12px;">No Vehicle</span>`;
}

function formatDateCell(dtStr) {
    if (!dtStr) return '-';
    const d = new Date(dtStr);
    if (isNaN(d.getTime())) return h(String(dtStr).split(' ')[0]);
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function renderCustomerListTable(customers) {
    const tbody = document.getElementById('customersBody');
    document.getElementById('customerCount').innerText = customers.length + ' records';

    if (!customers.length) {
        tbody.innerHTML = `<tr><td colspan="11" class="empty">No customers found matching filters.</td></tr>`;
        return;
    }

    tbody.innerHTML = customers.map(c => `
        <tr>
            <td><strong>${h(c.customer_id)}</strong></td>
            <td><strong>${h(c.customer_name)}</strong></td>
            <td><span class="pill ${h(c.customer_type)}">${h(c.customer_type)}</span></td>
            <td>${h(c.contact_number)}</td>
            <td>${formatVehicleCell(c)}</td>
            <td>${c.customer_type === 'credit' ? money(c.credit_limit) : '-'}</td>
            <td>${c.customer_type === 'credit' ? money(c.outstanding_balance) : '-'}</td>
            <td>${c.last_transaction ? h(c.last_transaction) : 'No transactions'}</td>
            <td><small style="color:#475569; font-weight:600;">${formatDateCell(c.registered_at)}</small></td>
            <td><span class="pill ${h(c.status)}">${h(c.status)}</span></td>
            <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                    <button type="button" class="btn-plain primary" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="viewCustomer(${c.id})"><i class="fas fa-eye"></i> View</button>
                    <button type="button" class="btn-plain success" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="openCustomerForm('edit', ${c.id})"><i class="fas fa-edit"></i> Edit</button>
                    <button type="button" class="btn-plain danger" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="openArchiveModal(${c.id})"><i class="fas fa-archive"></i> Archive</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function renderArchivedTable(customers) {
    const tbody = document.getElementById('archivedBody');
    document.getElementById('archivedCount').innerText = customers.length + ' records';

    if (!customers.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty">No archived customers found.</td></tr>`;
        return;
    }

    tbody.innerHTML = customers.map(c => `
        <tr>
            <td><strong>${h(c.customer_id)}</strong></td>
            <td><strong>${h(c.customer_name)}</strong></td>
            <td><span class="pill ${h(c.customer_type)}">${h(c.customer_type)}</span></td>
            <td>${c.archived_at ? h(c.archived_at) : '-'}</td>
            <td>${h(c.archive_reason || 'Inactive')}</td>
            <td style="text-align:center;">
                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                    <button type="button" class="btn-plain primary" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="viewCustomer(${c.id})"><i class="fas fa-eye"></i> View</button>
                    <button type="button" class="btn-plain success" style="height:30px; padding:0 8px; font-size:11px; width:80px;" onclick="restoreCustomer(${c.id})"><i class="fas fa-undo"></i> Restore</button>
                </div>
            </td>
        </tr>
    `).join('');
}

function loadCustomerRequests() {
    document.getElementById('requestsBody').innerHTML = `<tr><td colspan="6" class="empty">Loading pending requests...</td></tr>`;

    fetch(`${apiUrl}?action=requests`)
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON requests output:", text);
                document.getElementById('requestsBody').innerHTML = `<tr><td colspan="6" class="empty">Error loading pending requests.</td></tr>`;
                return;
            }
            if (!res.success) {
                toast(res.error || 'Failed to load requests.', 'error');
                return;
            }
            currentRequests = res.requests || [];
            document.getElementById('requestCount').innerText = currentRequests.length + ' pending';
            document.getElementById('statRequests').innerText = currentRequests.length;
            document.getElementById('tabBadgePending').innerText = currentRequests.length;
            const tbody = document.getElementById('requestsBody');

            if (!currentRequests.length) {
                tbody.innerHTML = `<tr><td colspan="6" class="empty">No pending customer requests.</td></tr>`;
                return;
            }

            tbody.innerHTML = currentRequests.map(r => `
                <tr>
                    <td><strong>${h(r.first_name + ' ' + (r.middle_name ? r.middle_name + ' ' : '') + r.last_name)}</strong></td>
                    <td>${h(r.contact_number)}</td>
                    <td><span class="pill regular">${h(r.vehicle_plate || 'N/A')}</span></td>
                    <td>${h(r.requested_by_name || 'Staff')}</td>
                    <td>${h(r.created_at)}</td>
                    <td style="text-align:right;">
                        <div class="cust-actions">
                            <button type="button" class="btn-plain primary" style="height:30px; padding:0 8px; font-size:11px;" onclick="openReviewModal(${r.id})"><i class="fas fa-tasks"></i> Review</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        })
        .catch(err => {
            document.getElementById('requestsBody').innerHTML = `<tr><td colspan="6" class="empty">Network error loading requests.</td></tr>`;
        });
}

let currentRawOutstanding = 0;

function calcAvailableCredit() {
    const limit = parseFloat(document.getElementById('creditLimit').value) || 0;
    const available = Math.max(0, limit - currentRawOutstanding);
    document.getElementById('availableCredit').value = money(available);
}

function toggleCreditSection() {
    const type = document.getElementById('customerType').value;
    const isCredit = (type === 'credit');
    document.querySelectorAll('.credit-only').forEach(el => {
        el.style.display = isCredit ? 'block' : 'none';
    });
    if (!isCredit) {
        document.getElementById('creditLimit').value = '0.00';
        currentRawOutstanding = 0;
        document.getElementById('outstandingBalance').value = '₱0.00';
        document.getElementById('availableCredit').value = '₱0.00';
    } else {
        calcAvailableCredit();
    }
}

function addVehicleRow(v = {}) {
    vehicleIndex++;
    const container = document.getElementById('vehiclesContainer');
    const div = document.createElement('div');
    div.className = 'vehicle-card';
    div.id = 'vehicleRow_' + vehicleIndex;
    div.innerHTML = `
        <button type="button" class="remove-v-btn" onclick="document.getElementById('vehicleRow_${vehicleIndex}').remove()">&times;</button>
        <div class="form-grid" style="gap:8px;">
            <div class="cust-field">
                <label>Plate Number</label>
                <input type="text" class="v-plate" value="${h(v.plate_number || '')}" placeholder="ABC-1234">
            </div>
            <div class="cust-field">
                <label>Vehicle Type</label>
                <input type="text" class="v-type" list="vtype-list-${v.id||0}" value="${h(v.vehicle_type || '')}" placeholder="Sedan, SUV, Truck...">
                <datalist id="vtype-list-${v.id||0}">
                    <option value="Sedan">
                    <option value="SUV">
                    <option value="Pickup">
                    <option value="Motorcycle">
                    <option value="Truck">
                    <option value="Van">
                    <option value="Others">
                </datalist>
            </div>
            <div class="cust-field">
                <label>Brand</label>
                <input type="text" class="v-brand" value="${h(v.brand || '')}" placeholder="Toyota, Honda, etc.">
            </div>
            <div class="cust-field">
                <label>Model</label>
                <input type="text" class="v-model" value="${h(v.model || '')}" placeholder="Corolla, Vios, etc.">
            </div>
            <div class="cust-field">
                <label>Year Model</label>
                <input type="text" class="v-year" value="${h(v.year_model || '')}" placeholder="2024">
            </div>
            <div class="cust-field">
                <label>Color</label>
                <input type="text" class="v-color" value="${h(v.color || '')}" placeholder="Red, Black, White">
            </div>
            <div class="cust-field">
                <label>Engine No. (Optional)</label>
                <input type="text" class="v-engine" value="${h(v.engine_no || '')}">
            </div>
            <div class="cust-field">
                <label>Chassis No. (Optional)</label>
                <input type="text" class="v-chassis" value="${h(v.chassis_no || '')}">
            </div>
        </div>
    `;
    container.appendChild(div);
}

function openCustomerForm(mode = 'add', id = 0) {
    document.getElementById('formMode').value = mode;
    document.getElementById('formTitle').innerText = mode === 'add' ? 'Add New Customer' : 'Edit Customer';
    document.getElementById('customerForm').reset();
    document.getElementById('vehiclesContainer').innerHTML = '';
    vehicleIndex = 0;

    if (mode === 'add') {
        currentRawOutstanding = 0;
        document.getElementById('creditLimit').value = '0.00';
        document.getElementById('creditTerms').value = '30 Days';
        document.getElementById('outstandingBalance').value = '₱0.00';
        document.getElementById('availableCredit').value = '₱0.00';
        addVehicleRow(); // default 1 vehicle row
        toggleCreditSection();
        openModal('customerFormModal');
    } else {
        fetch(`${apiUrl}?action=view&id=${id}`)
            .then(r => r.json())
            .then(res => {
                if (!res.success) { toast(res.error, 'error'); return; }
                const c = res.customer;
                document.getElementById('recordId').value = c.id;
                document.getElementById('customerType').value = c.customer_type || 'walk-in';
                document.getElementById('custStatus').value = c.status || 'active';
                document.getElementById('firstName').value = c.first_name || '';
                document.getElementById('middleName').value = c.middle_name || '';
                document.getElementById('lastName').value = c.last_name || '';
                document.getElementById('contactNumber').value = c.contact_number || '';
                document.getElementById('address').value = c.address || '';
                document.getElementById('email').value = c.email || '';
                document.getElementById('govIdType').value = c.gov_id_type || '';
                document.getElementById('creditLimit').value = c.credit_limit || 0;
                document.getElementById('creditTerms').value = c.credit_terms || '30 Days';
                currentRawOutstanding = parseFloat(c.outstanding_balance || 0);
                document.getElementById('outstandingBalance').value = money(currentRawOutstanding);
                calcAvailableCredit();

                const vehicles = res.vehicles || [];
                if (vehicles.length > 0) {
                    vehicles.forEach(v => addVehicleRow(v));
                } else {
                    addVehicleRow({ plate_number: c.plate_no, brand: c.vehicle_make, model: c.vehicle_model, vehicle_type: c.vehicle_type });
                }

                toggleCreditSection();
                openModal('customerFormModal');
            });
    }
}

document.getElementById('customerForm').onsubmit = function(e) {
    e.preventDefault();
    const mode = document.getElementById('formMode').value;
    const formData = new FormData(this);
    formData.append('action', mode === 'add' ? 'add' : 'update');

    // Collect vehicle details
    const vehicles = [];
    document.querySelectorAll('.vehicle-card').forEach(row => {
        const plate = row.querySelector('.v-plate').value.trim();
        if (plate) {
            vehicles.push({
                plate_number: plate,
                vehicle_type: row.querySelector('.v-type').value,
                brand: row.querySelector('.v-brand').value,
                model: row.querySelector('.v-model').value,
                year_model: row.querySelector('.v-year').value,
                color: row.querySelector('.v-color').value,
                engine_no: row.querySelector('.v-engine').value,
                chassis_no: row.querySelector('.v-chassis').value
            });
        }
    });

    formData.append('vehicles_json', JSON.stringify(vehicles));
    if (vehicles.length > 0) {
        formData.append('plate_no', vehicles[0].plate_number);
        formData.append('vehicle_make', vehicles[0].brand);
        formData.append('vehicle_model', vehicles[0].model);
        formData.append('vehicle_type', vehicles[0].vehicle_type);
    }

    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                console.error("Non-JSON submit output:", text);
                toast('Error saving customer. Response was invalid JSON.', 'error');
                return;
            }
            if (!res.success) { toast(res.error || 'Failed to save customer.', 'error'); return; }
            toast(res.message || 'Customer saved successfully!');
            closeModal('customerFormModal');
            document.getElementById('filterSearch').value = '';
            document.getElementById('filterType').value = '';
            document.getElementById('filterStatus').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            switchCustTab('list');
        })
        .catch(err => toast('Network error saving customer.', 'error'));
};

function viewCustomer(id) {
    fetch(`${apiUrl}?action=view&id=${id}`)
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error, 'error'); return; }
            currentCustomer = res.customer;
            const c = res.customer;
            const s = res.summary || {};

            document.getElementById('vCustId').innerText = c.customer_id || '-';
            document.getElementById('vCustSince').innerText = c.registered_at ? c.registered_at.split(' ')[0] : '-';
            document.getElementById('vType').innerHTML = `<span class="pill ${h(c.customer_type)}">${h(c.customer_type)}</span>`;
            document.getElementById('vStatus').innerHTML = `<span class="pill ${h(c.status)}">${h(c.status)}</span>`;
            document.getElementById('vContact').innerText = c.contact_number || '-';
            document.getElementById('vEmail').innerText = c.email || '-';
            document.getElementById('vAddress').innerText = c.address || '-';

            document.getElementById('vCreditLimit').innerText = money(c.credit_limit);
            document.getElementById('vOutstanding').innerText = money(c.outstanding_balance);
            document.getElementById('vAvailableCredit').innerText = money(c.available_credit);
            document.getElementById('vTerms').innerText = c.credit_terms || '30 Days';

            // Vehicles
            const vBody = document.getElementById('vVehiclesBody');
            const vehicles = res.vehicles || [];
            if (!vehicles.length) {
                vBody.innerHTML = `<tr><td colspan="6" class="empty">No vehicles registered.</td></tr>`;
            } else {
                vBody.innerHTML = vehicles.map(v => `
                    <tr>
                        <td><strong>${h(v.plate_number)}</strong></td>
                        <td>${h(v.vehicle_type || 'N/A')}</td>
                        <td>${h(v.brand || 'N/A')}</td>
                        <td>${h(v.model || 'N/A')}</td>
                        <td><span class="pill ${h(v.status || 'active')}">${h(v.status || 'active')}</span></td>
                        <td style="text-align:right;">
                            ${v.id ? `<button type="button" class="btn-plain danger" style="height:26px; padding:0 6px; font-size:10px;" onclick="archiveVehicle(${v.id})"><i class="fas fa-trash"></i> Archive</button>` : '-'}
                        </td>
                    </tr>
                `).join('');
            }

            // Transactions
            const tBody = document.getElementById('vTxBody');
            const txs = res.transactions || [];
            if (!txs.length) {
                tBody.innerHTML = `<tr><td colspan="5" class="empty">No recent transactions.</td></tr>`;
            } else {
                tBody.innerHTML = txs.map(t => `
                    <tr>
                        <td><strong>${h(t.transaction_id || '-')}</strong></td>
                        <td>${h(t.date || '-')}</td>
                        <td>${h(t.type || 'Merchandise')}</td>
                        <td>${money(t.amount)}</td>
                        <td><span class="pill active">${h(t.status || 'Completed')}</span></td>
                    </tr>
                `).join('');
            }

            // Job Orders
            const jBody = document.getElementById('vJoBody');
            const jos = res.job_orders || [];
            if (!jos.length) {
                jBody.innerHTML = `<tr><td colspan="5" class="empty">No job orders found.</td></tr>`;
            } else {
                jBody.innerHTML = jos.map(j => `
                    <tr>
                        <td><strong>${h(j.jo_no || '-')}</strong></td>
                        <td>${h(j.vehicle || '-')}</td>
                        <td>${h(j.service || '-')}</td>
                        <td>Mechanic #${h(j.mechanic || 'N/A')}</td>
                        <td><span class="pill active">${h(j.status || 'Completed')}</span></td>
                    </tr>
                `).join('');
            }

            // Documents
            document.getElementById('vGovType').innerText = c.gov_id_type || 'ID';
            document.getElementById('vGovFileLink').innerHTML = c.gov_id_file ? `<a href="../${h(c.gov_id_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';
            document.getElementById('vCrFileLink').innerHTML = c.cr_file ? `<a href="../${h(c.cr_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';
            document.getElementById('vOrFileLink').innerHTML = c.or_file ? `<a href="../${h(c.or_file)}" target="_blank" class="btn-plain primary" style="height:24px; padding:0 8px; font-size:11px;"><i class="fas fa-download"></i> Download</a>` : 'Not uploaded';

            // Timeline
            const tmEl = document.getElementById('vTimeline');
            const timeline = res.timeline || [];
            tmEl.innerHTML = timeline.map(t => `
                <div class="timeline-item">
                    <div class="t-title">${h(t.event_type)}</div>
                    <div>${h(t.description)}</div>
                    <div class="t-time">${h(t.created_at)}</div>
                </div>
            `).join('');

            openModal('customerViewModal');
        });
}

function editFromCurrentView() {
    if (currentCustomer) {
        closeModal('customerViewModal');
        openCustomerForm('edit', currentCustomer.id);
    }
}

function openArchiveFromView() {
    if (currentCustomer) {
        closeModal('customerViewModal');
        openArchiveModal(currentCustomer.id);
    }
}

function openArchiveModal(id) {
    document.getElementById('archiveCustId').value = id;
    document.getElementById('archiveReason').value = 'Inactive';
    document.getElementById('archiveRemarks').value = '';
    openModal('archiveModal');
}

document.getElementById('archiveForm').onsubmit = function(e) {
    e.preventDefault();
    const id = document.getElementById('archiveCustId').value;
    const reason = document.getElementById('archiveReason').value;
    const remarks = document.getElementById('archiveRemarks').value;

    const body = new URLSearchParams({ action: 'archive', id, reason, remarks });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error, 'error'); return; }
            toast(res.message);
            closeModal('archiveModal');
            loadManagerCustomers();
        });
};

function restoreCustomer(id) {
    if (!confirm('Are you sure you want to restore this customer to Active status?')) return;
    const body = new URLSearchParams({ action: 'restore', id });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error, 'error'); return; }
            toast(res.message);
            loadManagerCustomers();
        });
}

function archiveVehicle(vId) {
    if (!confirm('Archive this vehicle?')) return;
    const body = new URLSearchParams({ action: 'archive_vehicle', vehicle_id: vId });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { toast(res.error, 'error'); return; }
            toast(res.message);
            if (currentCustomer) viewCustomer(currentCustomer.id);
        });
}

function openReviewModal(reqId) {
    const req = currentRequests.find(r => r.id == reqId);
    if (!req) return;
    document.getElementById('reviewReqId').value = reqId;
    document.getElementById('revName').innerText = req.first_name + ' ' + (req.middle_name ? req.middle_name + ' ' : '') + req.last_name;
    document.getElementById('revContact').innerText = req.contact_number;
    document.getElementById('revPlate').innerText = req.vehicle_plate || 'N/A';
    document.getElementById('revReqBy').innerText = req.requested_by_name || 'Staff';
    document.getElementById('reviewRemarks').value = '';
    openModal('reviewModal');
}

function submitReviewAction(type) {
    const id = document.getElementById('reviewReqId').value;
    const remarks = document.getElementById('reviewRemarks').value;
    const action = type === 'approve' ? 'approve_request' : 'reject_request';

    const body = new URLSearchParams({ action, id, remarks });
    fetch(apiUrl, { method: 'POST', body })
        .then(r => r.text())
        .then(text => {
            let res;
            try {
                const cleanText = text.replace(/^\uFEFF/, '').trim();
                res = JSON.parse(cleanText);
            } catch (e) {
                toast('Error reviewing request.', 'error');
                return;
            }
            if (!res.success) { toast(res.error, 'error'); return; }
            toast(res.message);
            closeModal('reviewModal');
            loadCustomerRequests();
            loadManagerCustomers();
        });
}

// Initial Load
document.addEventListener('DOMContentLoaded', function() {
    loadManagerCustomers();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
