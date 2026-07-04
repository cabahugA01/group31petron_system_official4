<?php
// ── Auth & role gate ──────────────────────────────────────────────────────────
$page_id = 'admin_customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$user = current_user();
$role = role_key($user['role'] ?? '');

if (!in_array($role, ['admin', 'superadmin', 'developer'])) {
    $_SESSION['error'] = 'Access denied. Administrator privileges required.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// Header
require_once __DIR__ . '/../partials/header.php';
?>

<!-- Custom styles to maintain Petron-clean aesthetics and layout -->
<style>
    /* Summary Cards */
    .summary-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:16px; margin-bottom:20px; }
    .summary-card { background:#fff; border-radius:10px; padding:16px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:14px; box-shadow:0 1px 3px rgba(0,0,0,.05); transition:transform .15s; }
    .summary-card:hover { transform:translateY(-2px); }
    .summary-icon { width:48px; height:48px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; }
    .bg-primary-dark { background:#002F70; }
    .bg-success-dark { background:#15803d; }
    .bg-info-dark { background:#0369a1; }
    .bg-warning-dark { background:#b45309; }
    .bg-danger-dark { background:#b91c1c; }
    .bg-emerald { background:#10b981; }
    .bg-secondary-dark { background:#4b5563; }
    .summary-info h4 { font-size:22px; font-weight:700; color:#0f172a; margin:0 0 2px; line-height:1.2; }
    .summary-info p { font-size:11px; font-weight:600; color:#64748b; margin:0; text-transform:uppercase; letter-spacing:.3px; }

    /* Search & Filter Bar - Horizontal Professional Layout */
    .filter-bar { background:#fff; border-radius:10px; border:1px solid #e2e8f0; padding:16px 20px; margin-bottom:16px; }
    .filter-row-top { display:grid; grid-template-columns:2fr 1fr 1fr 1fr; gap:12px; margin-bottom:12px; }
    .filter-row-bottom { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; margin-bottom:14px; }
    .filter-group { display:flex; flex-direction:column; }
    .filter-group label { font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; margin-bottom:5px; letter-spacing:.3px; }
    .filter-group input, .filter-group select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; outline:none; transition:border-color .15s; }
    .filter-group input:focus, .filter-group select:focus { border-color:#002F70; }
    .filter-actions { display:flex; justify-content:space-between; gap:8px; align-items:center; border-top:1px solid #f1f5f9; padding-top:12px; }
    .filter-actions-left { display:flex; gap:8px; }
    .filter-actions-right { display:flex; gap:8px; }

    /* Custom Table Style */
    .table-container { background:#fff; border-radius:10px; border:1px solid #e2e8f0; overflow:hidden; margin-bottom:20px; }
    .table-header { padding:14px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc; }
    .table-header h3 { font-size:13px; font-weight:700; color:#002F70; margin:0; text-transform:uppercase; }
    .cust-table { width:100%; border-collapse:collapse; }
    .cust-table thead tr { background:#002F70; }
    .cust-table thead th { padding:10px 12px; text-align:left; font-size:11px; font-weight:700; color:#fff; text-transform:uppercase; white-space:nowrap; }
    .cust-table tbody tr { border-bottom:1px solid #f1f5f9; }
    .cust-table tbody tr:hover td { background:#f8faff; }
    .cust-table tbody td { padding:10px 12px; color:#334155; font-size:12px; vertical-align:middle; background:#fff; }
    .cust-table tfoot td { padding:10px 12px; font-size:11px; color:#64748b; background:#f8fafc; }

    /* Badges */
    .badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
    .badge-verified { background:#d1fae5; color:#065f46; }
    .badge-pending { background:#fef3c7; color:#92400e; }
    .badge-rejected { background:#fee2e2; color:#991b1b; }
    .badge-active { background:#dcfce7; color:#166534; }
    .badge-inactive { background:#f1f5f9; color:#64748b; }
    .badge-walk-in { background:#eff6ff; color:#1d4ed8; }
    .badge-regular { background:#f0fdf4; color:#15803d; }
    .badge-fleet { background:#faf5ff; color:#7c3aed; }
    .badge-paid { background:#ecfdf5; color:#059669; }
    .badge-partial { background:#eff6ff; color:#1d4ed8; }
    .badge-unpaid { background:#fff1f2; color:#e11d48; }

    /* Buttons */
    .btn-clean { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 14px; border-radius:6px; border:none; cursor:pointer; font-size:12px; font-weight:600; transition:all .15s; white-space:nowrap; }
    .btn-primary-blue { background:#002F70; color:#fff; }
    .btn-primary-blue:hover { background:#001f4d; }
    .btn-slate { background:#64748b; color:#fff; }
    .btn-slate:hover { background:#475569; }
    .btn-outline-blue { background:transparent; border:1px solid #002F70; color:#002F70; }
    .btn-outline-blue:hover { background:#002F70; color:#fff; }
    .btn-emerald { background:#10b981; color:#fff; }
    .btn-emerald:hover { background:#059669; }

    .act-btn { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; border:none; cursor:pointer; font-size:12px; transition:all .15s; color:#fff; }
    .act-view { background:#3b82f6; } .act-view:hover { background:#2563eb; }
    .act-print { background:#6b7280; } .act-print:hover { background:#4b5563; }

    /* Profile Overlay */
    .profile-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.6); z-index:9998; align-items:flex-start; justify-content:center; overflow-y:auto; padding:20px 0; }
    .profile-overlay.open { display:flex; }
    .profile-box { background:#fff; border-radius:12px; width:95%; max-width:1080px; margin:auto; box-shadow:0 20px 50px rgba(0,0,0,.25); overflow:hidden; }
    .profile-header { background:linear-gradient(135deg,#002F70,#0047b3); color:#fff; padding:24px 28px; display:flex; align-items:center; justify-content:space-between; }
    .profile-header h2 { font-size:18px; font-weight:700; margin:0; }
    .profile-header .sub { font-size:12px; opacity:.8; margin-top:4px; }
    .profile-body { padding:24px 28px; }
    .profile-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
    .info-block { background:#f8fafc; border-radius:8px; padding:16px; border:1px solid #e2e8f0; }
    .info-block h4 { font-size:11px; font-weight:700; color:#002F70; text-transform:uppercase; letter-spacing:.5px; margin:0 0 12px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; gap:6px; }
    .info-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #f1f5f9; font-size:12px; }
    .info-row:last-child { border-bottom:none; }
    .info-row .label { color:#64748b; font-weight:600; }
    .info-row .value { color:#1e293b; font-weight:500; text-align:right; }
    
    .doc-link { color:#002F70; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
    .doc-link:hover { text-decoration:underline; }

    /* Transaction History Table Area */
    .txn-history-wrap { margin-top:24px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; background:#fff; }
    .txn-history-hdr { background:#f8fafc; padding:14px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    .txn-history-hdr h4 { font-size:12px; font-weight:700; color:#002F70; margin:0; text-transform:uppercase; display:flex; align-items:center; gap:6px; }
    .txn-history-filters { display:flex; gap:8px; flex-wrap:wrap; padding:12px 20px; background:#fdfdfd; border-bottom:1px solid #f1f5f9; }
    .txn-history-filters input, .txn-history-filters select { padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:11px; outline:none; }
    
    .txn-table { width:100%; border-collapse:collapse; font-size:12px; }
    .txn-table thead th { background:#f1f5f9; padding:10px 14px; text-align:left; font-size:10px; font-weight:700; color:#475569; text-transform:uppercase; border-bottom:1px solid #e2e8f0; }
    .txn-table tbody td { padding:10px 14px; border-bottom:1px solid #f1f5f9; color:#334155; }
    .txn-table tbody tr:hover td { background:#f8faff; }

    .txn-pagination { padding:10px 20px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; font-size:12px; }
    .pagination-btns { display:flex; gap:4px; }
    .pagination-btn { padding:5px 10px; border:1px solid #cbd5e1; background:#fff; border-radius:4px; cursor:pointer; font-size:11px; }
    .pagination-btn:hover { background:#f1f5f9; }
    .pagination-btn:disabled { opacity:.5; cursor:not-allowed; }

    .profile-footer-actions { display:flex; gap:10px; padding:16px 28px; background:#f8fafc; border-top:1px solid #e2e8f0; justify-content:flex-end; flex-wrap:wrap; }

    /* Modal dialog */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:9999; align-items:center; justify-content:center; }
    .modal-overlay.open { display:flex; }
    .modal-box { background:#fff; border-radius:12px; width:90%; max-width:800px; max-height:92vh; overflow-y:auto; box-shadow:0 20px 40px rgba(0,0,0,.2); padding:24px; }
    .modal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:10px; border-bottom:1px solid #e9ecef; }
    .modal-title { font-size:14px; font-weight:700; color:#002F70; text-transform:uppercase; }
    .modal-close { background:none; border:none; font-size:1.4rem; cursor:pointer; color:#888; }

    /* Toast */
    #toast-container { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:8px; }
    .toast { padding:10px 16px; border-radius:6px; font-size:12px; font-weight:600; min-width:260px; box-shadow:0 4px 10px rgba(0,0,0,.15); animation:slideIn .2s ease; }
    .toast-success { background:#16a34a; color:#fff; }
    .toast-error { background:#dc2626; color:#fff; }
    @keyframes slideIn { from{transform:translateX(50px);opacity:0} to{transform:none;opacity:1} }
</style>

<div id="toast-container"></div>

<div class="container-fluid">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h2 style="margin:0; text-transform:uppercase; font-weight:700; color:#002F70; font-size:18px;">
            <i class="fas fa-users me-2"></i>Customer Registry Oversight
        </h2>
    </div>

    <!-- Summary Cards Grid -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-icon bg-primary-dark"><i class="fas fa-users"></i></div>
            <div class="summary-info">
                <h4 id="stat-total">0</h4>
                <p>Total Customers</p>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon bg-success-dark"><i class="fas fa-user-plus"></i></div>
            <div class="summary-info">
                <h4 id="stat-new">0</h4>
                <p>New Today</p>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon bg-info-dark"><i class="fas fa-money-bill-wave"></i></div>
            <div class="summary-info">
                <h4 id="stat-cash">0</h4>
                <p>Cash Customers</p>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon bg-warning-dark"><i class="fas fa-credit-card"></i></div>
            <div class="summary-info">
                <h4 id="stat-credit">0</h4>
                <p>Credit Accounts</p>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon bg-emerald"><i class="fas fa-check-circle"></i></div>
            <div class="summary-info">
                <h4 id="stat-active">0</h4>
                <p>Active</p>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon bg-danger-dark"><i class="fas fa-ban"></i></div>
            <div class="summary-info">
                <h4 id="stat-inactive">0</h4>
                <p>Inactive / Susp</p>
            </div>
        </div>
    </div>

    <!-- Search & Filter Panel - Professional Horizontal Layout -->
    <div class="filter-bar">
        <!-- Row 1: Search (wide) + 2 Dropdowns -->
        <div class="filter-row-top">
            <div class="filter-group">
                <label>Search Customer</label>
                <input type="text" id="filter-search" placeholder="Name / Contact Number...">
            </div>
            <div class="filter-group">
                <label>Customer Type</label>
                <select id="filter-type">
                    <option value="">All Types</option>
                    <option value="cash">Cash</option>
                    <option value="credit">Credit</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select id="filter-status">
                    <option value="">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>
            <div class="filter-group">
                <!-- Empty space for alignment -->
            </div>
        </div>
        
        <!-- Row 2: Date Filters (4 equal columns) -->
        <div class="filter-row-bottom">
            <div class="filter-group">
                <label>Date Registered (From)</label>
                <input type="date" id="filter-reg-from">
            </div>
            <div class="filter-group">
                <label>Date Registered (To)</label>
                <input type="date" id="filter-reg-to">
            </div>
            <div class="filter-group">
                <label>Last Transaction (From)</label>
                <input type="date" id="filter-tx-from">
            </div>
            <div class="filter-group">
                <label>Last Transaction (To)</label>
                <input type="date" id="filter-tx-to">
            </div>
        </div>
        
        <!-- Action Buttons: Left (Apply/Reset/Refresh) | Right (Export) -->
        <div class="filter-actions">
            <div class="filter-actions-left">
                <button class="btn-clean btn-primary-blue" onclick="loadCustomers()"><i class="fas fa-search"></i> Apply Filters</button>
                <button class="btn-clean btn-slate" onclick="resetFilters()"><i class="fas fa-times"></i> Reset</button>
                <button class="btn-clean btn-slate" onclick="loadCustomers()"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
            <div class="filter-actions-right">
                <button class="btn-clean btn-outline-blue" onclick="exportData('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
                <button class="btn-clean btn-outline-blue" onclick="exportData('excel')"><i class="fas fa-file-excel"></i> Excel</button>
                <button class="btn-clean btn-outline-blue" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</button>
            </div>
        </div>
    </div>

    <!-- Customer Registry Table -->
    <div class="table-container">
        <div class="table-header">
            <h3>Customer Registry (Oversight View)</h3>
            <span id="count-label" style="font-size:11px; font-weight:700; color:#64748b;">0 records found</span>
        </div>
        <div style="overflow-x:auto;">
            <table class="cust-table">
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Contact Number</th>
                        <th>Customer Type</th>
                        <th>Date Registered</th>
                        <th>Last Transaction</th>
                        <th>Status</th>
                        <th style="width:80px; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="cust-tbody">
                    <!-- Loaded dynamically -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- PROFILE OVERLAY (FULL VIEW) -->
<div class="profile-overlay" id="profile-modal">
    <div class="profile-box">
        <div class="profile-header">
            <div>
                <h2 id="prof-name">Customer Name</h2>
                <div class="sub" id="prof-id-badge">CUSTOMER ID — Station</div>
            </div>
            <button class="btn-clean btn-slate" onclick="closeProfile()" style="padding:6px 12px; background:rgba(255,255,255,0.2);"><i class="fas fa-times"></i> Close Profile</button>
        </div>
        <div class="profile-body">
            <div class="profile-grid">
                <!-- Customer Details Block -->
                <div class="info-block">
                    <h4><i class="fas fa-info-circle"></i> Customer Information</h4>
                    <div class="info-row"><span class="label">Customer Name</span><span class="value" id="info-name">-</span></div>
                    <div class="info-row"><span class="label">Contact Number</span><span class="value" id="info-contact">-</span></div>
                    <div class="info-row"><span class="label">Address</span><span class="value" id="info-address">-</span></div>
                    <div class="info-row"><span class="label">Customer Type</span><span class="value" id="info-type">-</span></div>
                    <div class="info-row"><span class="label">Date Registered</span><span class="value" id="info-registered">-</span></div>
                    <div class="info-row"><span class="label">Status</span><span class="value" id="info-status">-</span></div>
                </div>

                <!-- Spend Summary & Documents Block -->
                <div>
                    <div class="info-block" style="margin-bottom:16px;">
                        <h4><i class="fas fa-chart-line"></i> Transaction Summary</h4>
                        <div class="info-row"><span class="label">Total Merchandise Transactions</span><span class="value" id="sum-merch-count">0</span></div>
                        <div class="info-row"><span class="label">Total Job Orders</span><span class="value" id="sum-jo-count">0</span></div>
                        <div class="info-row"><span class="label">Total Amount Spent</span><span class="value" style="font-weight:700; color:#0f172a;" id="sum-total-spent">\u20B10.00</span></div>
                        <div class="info-row"><span class="label">Last Transaction Date</span><span class="value" id="sum-last-txn">-</span></div>
                        <div class="info-row"><span class="label">Outstanding Balance</span><span class="value" style="color:#b91c1c; font-weight:700;" id="info-outstanding">\u20B10.00</span></div>
                    </div>

                    <div class="info-block">
                        <h4><i class="fas fa-file-alt"></i> Submitted Documents</h4>
                        <div class="info-row">
                            <span class="label">Government ID (<span id="info-gov-id-type">None</span>)</span>
                            <span class="value" id="doc-govid-container">None Submitted</span>
                        </div>
                        <div class="info-row" id="doc-cr-row">
                            <span class="label">Certificate of Registration (CR)</span>
                            <span class="value" id="doc-cr-container">None Submitted</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transaction History Section with Independent Filters & Pagination -->
            <div class="txn-history-wrap">
                <div class="txn-history-hdr">
                    <h4><i class="fas fa-history"></i> Complete Transaction History</h4>
                    <span style="font-size:11px; font-weight:700; color:#64748b;" id="history-total-count">0 items total</span>
                </div>
                
                <!-- History Filters -->
                <div class="txn-history-filters">
                    <input type="text" id="hist-search" placeholder="Search Ref No...">
                    <select id="hist-module">
                        <option value="">All Modules</option>
                        <option value="Merchandise">Merchandise</option>
                        <option value="Job Order">Job Order</option>
                    </select>
                    <select id="hist-status">
                        <option value="">All Statuses</option>
                        <option value="Completed">Completed</option>
                        <option value="Pending">Pending</option>
                        <option value="Approved">Approved</option>
                        <option value="Rejected">Rejected</option>
                    </select>
                    <input type="date" id="hist-date-from" placeholder="From Date">
                    <input type="date" id="hist-date-to" placeholder="To Date">
                    <button class="btn-clean btn-primary-blue" onclick="loadHistory(1)" style="padding:4px 10px; font-size:11px;"><i class="fas fa-filter"></i> Apply</button>
                    <button class="btn-clean btn-slate" onclick="resetHistoryFilters()" style="padding:4px 10px; font-size:11px;"><i class="fas fa-times"></i> Clear</button>
                </div>

                <!-- History Table -->
                <div style="overflow-x:auto;">
                    <table class="txn-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference No.</th>
                                <th>Module</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Processed By</th>
                            </tr>
                        </thead>
                        <tbody id="history-tbody">
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                </div>

                <!-- History Pagination -->
                <div class="txn-pagination">
                    <div>
                        <label>Rows per page: </label>
                        <select id="hist-limit" onchange="loadHistory(1)">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                    <div id="pagination-info" style="font-weight:600; color:#475569;">Page 1 of 1</div>
                    <div class="pagination-btns">
                        <button class="pagination-btn" id="pagination-prev" onclick="changeHistoryPage(-1)"><i class="fas fa-chevron-left"></i> Prev</button>
                        <button class="pagination-btn" id="pagination-next" onclick="changeHistoryPage(1)">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="profile-footer-actions">
            <button class="btn-clean btn-outline-blue" onclick="exportHistory('pdf')"><i class="fas fa-file-pdf"></i> Export History PDF</button>
            <button class="btn-clean btn-outline-blue" onclick="exportHistory('excel')"><i class="fas fa-file-excel"></i> Export History Excel</button>
            <button class="btn-clean btn-outline-blue" onclick="exportHistory('csv')"><i class="fas fa-file-csv"></i> Export History CSV</button>
            <button class="btn-clean btn-emerald" onclick="printProfilePdf()"><i class="fas fa-print"></i> Print Profile</button>
            <button class="btn-clean btn-slate" onclick="closeProfile()"><i class="fas fa-arrow-left"></i> Back</button>
        </div>
    </div>
</div>

<!-- DOCUMENT PREVIEW MODAL -->
<div class="modal-overlay" id="doc-modal">
    <div class="modal-box">
        <div class="modal-head">
            <h3 class="modal-title" id="doc-modal-title">Document Preview</h3>
            <button class="modal-close" onclick="closeDocModal()">&times;</button>
        </div>
        <div id="doc-preview-content" style="text-align:center; padding:10px; overflow:hidden;">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<script>
    let customersList = [];
    let activeCustomerId = null;
    let historyPage = 1;
    let historyTotalPages = 1;

    document.addEventListener('DOMContentLoaded', () => {
        loadCustomers();
    });

    // Helper functions
    const esc = (s) => {
        if (!s) return '';
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    };
    const fmt = (n) => parseFloat(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtDate = (d) => {
        if (!d) return '—';
        return new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    };
    const fmtDateTime = (d) => {
        if (!d) return '—';
        return new Date(d).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    };

    function showToast(msg, type = 'success') {
        const c = document.getElementById('toast-container');
        const t = document.createElement('div');
        t.className = `toast toast-${type}`;
        t.textContent = msg;
        c.appendChild(t);
        setTimeout(() => t.remove(), 3500);
    }

    // Load customers and summary cards
    function loadCustomers() {
        const tbody = document.getElementById('cust-tbody');
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Loading customer records...</td></tr>`;

        const params = new URLSearchParams({
            action: 'list',
            search: document.getElementById('filter-search').value,
            type: document.getElementById('filter-type').value,
            status: document.getElementById('filter-status').value,
            date_reg_from: document.getElementById('filter-reg-from').value,
            date_reg_to: document.getElementById('filter-reg-to').value,
            date_tx_from: document.getElementById('filter-tx-from').value,
            date_tx_to: document.getElementById('filter-tx-to').value
        });

        fetch(`admin_customer_operations.php?${params.toString()}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.error || 'Failed to fetch customers', 'error');
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#dc2626;">Error: ${data.error}</td></tr>`;
                    return;
                }
                
                customersList = data.customers;
                
                // Update stats
                document.getElementById('stat-total').textContent = data.stats.total_customers;
                document.getElementById('stat-new').textContent = data.stats.new_registered;
                document.getElementById('stat-cash').textContent = data.stats.cash_customers;
                document.getElementById('stat-credit').textContent = data.stats.credit_customers;
                document.getElementById('stat-active').textContent = data.stats.active_customers;
                document.getElementById('stat-inactive').textContent = data.stats.inactive_customers;

                renderTable(data.customers);
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#dc2626;">Connection error.</td></tr>`;
            });
    }

    function renderTable(list) {
        const tbody = document.getElementById('cust-tbody');
        document.getElementById('count-label').textContent = `${list.length} records found`;

        if (!list.length) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:40px; color:#94a3b8;"><i class="fas fa-users-slash" style="font-size:24px; margin-bottom:8px; display:block;"></i>No customers found matching filters.</td></tr>`;
            return;
        }

        tbody.innerHTML = list.map(c => {
            const typeBadge = {'cash':'badge-walk-in', 'credit':'badge-regular'}[c.customer_type] || 'badge-walk-in';
            const statusBadge = {'active':'badge-active', 'inactive':'badge-inactive', 'suspended':'badge-inactive'}[c.status] || 'badge-inactive';
            const nameDisplay = c.display_name || 'Unnamed Customer';
            const lastTx = c.last_transaction_date ? fmtDate(c.last_transaction_date) : '<span style="color:#94a3b8;">None</span>';

            return `<tr>
                <td><strong>${esc(nameDisplay)}</strong></td>
                <td>${esc(c.contact_number || 'N/A')}</td>
                <td><span class="badge ${typeBadge}">${c.customer_type}</span></td>
                <td>${fmtDate(c.registered_at)}</td>
                <td>${lastTx}</td>
                <td><span class="badge ${statusBadge}">${c.status}</span></td>
                <td style="text-align:center;">
                    <div style="display:flex; gap:6px; justify-content:center;">
                        <button class="act-btn act-view" onclick="viewProfile(${c.id})" title="View Profile"><i class="fas fa-eye"></i></button>
                        <button class="act-btn act-print" onclick="printCustomer(${c.id})" title="Print Profile"><i class="fas fa-print"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    function resetFilters() {
        document.getElementById('filter-search').value = '';
        document.getElementById('filter-type').value = '';
        document.getElementById('filter-status').value = '';
        document.getElementById('filter-reg-from').value = '';
        document.getElementById('filter-reg-to').value = '';
        document.getElementById('filter-tx-from').value = '';
        document.getElementById('filter-tx-to').value = '';
        loadCustomers();
    }

    // General Customer Registry Export
    function exportData(format) {
        const params = new URLSearchParams({
            format: format,
            search: document.getElementById('filter-search').value,
            type: document.getElementById('filter-type').value,
            status: document.getElementById('filter-status').value,
            date_reg_from: document.getElementById('filter-reg-from').value,
            date_reg_to: document.getElementById('filter-reg-to').value,
            date_tx_from: document.getElementById('filter-tx-from').value,
            date_tx_to: document.getElementById('filter-tx-to').value
        });
        window.open(`admin_customer_export.php?${params.toString()}`, '_blank');
    }

    // View Single Profile
    function viewProfile(id) {
        activeCustomerId = id;
        
        fetch(`admin_customer_operations.php?action=view&id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.error || 'Failed to fetch details', 'error');
                    return;
                }

                const c = data.customer;
                const sum = data.summary;

                const nameDisplay = c.name || 'Unnamed Customer';

                // Bind fields
                document.getElementById('prof-name').textContent = nameDisplay;
                document.getElementById('prof-id-badge').textContent = `${c.type.toUpperCase()} CUSTOMER`;

                document.getElementById('info-name').textContent = nameDisplay;
                document.getElementById('info-contact').textContent = c.contact_number || c.phone || 'N/A';
                document.getElementById('info-address').textContent = c.address || 'N/A';
                document.getElementById('info-type').textContent = c.type.toUpperCase();
                document.getElementById('info-registered').textContent = fmtDate(c.created_at);
                document.getElementById('info-status').innerHTML = `<span class="badge ${c.status === 'active' ? 'badge-active':'badge-inactive'}">${c.status}</span>`;

                document.getElementById('sum-merch-count').textContent = sum.total_merchandise_txns;
                document.getElementById('sum-jo-count').textContent = sum.total_job_orders;
                document.getElementById('sum-total-spent').textContent = '\u20B1' + fmt(sum.total_amount_spent);
                document.getElementById('sum-last-txn').textContent = sum.last_transaction_date ? fmtDateTime(sum.last_transaction_date) : 'No transactions yet';
                document.getElementById('info-outstanding').textContent = '\u20B1' + fmt(c.balance || c.current_balance || 0);

                // Handle documents (if fields exist)
                const govIdCont = document.getElementById('doc-govid-container');
                if (c.id_type && c.id_number) {
                    govIdCont.innerHTML = `<span>${c.id_type}: ${c.id_number}</span>`;
                } else {
                    govIdCont.innerHTML = '<span style="color:#94a3b8; font-style:italic;">None Submitted</span>';
                }

                const crCont = document.getElementById('doc-cr-container');
                crCont.innerHTML = '<span style="color:#94a3b8; font-style:italic;">N/A</span>';

                // Open overlay and load first page of history
                document.getElementById('profile-modal').classList.add('open');
                resetHistoryFilters();
                loadHistory(1);
            })
            .catch(err => {
                console.error(err);
                showToast('Failed to connect to operation API', 'error');
            });
    }

    function closeProfile() {
        document.getElementById('profile-modal').classList.remove('open');
        activeCustomerId = null;
    }

    // Handle single profile PDF print
    function printCustomer(id) {
        window.open(`admin_customer_export.php?profile_id=${id}`, '_blank');
    }
    function printProfilePdf() {
        if (activeCustomerId) printCustomer(activeCustomerId);
    }

    // Single Customer Transaction History Logic
    function loadHistory(page) {
        if (!activeCustomerId) return;
        historyPage = page;
        
        const tbody = document.getElementById('history-tbody');
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:16px; color:#64748b;"><i class="fas fa-spinner fa-spin"></i> Fetching records...</td></tr>`;

        const limit = document.getElementById('hist-limit').value;
        const search = document.getElementById('hist-search').value;
        const module = document.getElementById('hist-module').value;
        const status = document.getElementById('hist-status').value;
        const dateFrom = document.getElementById('hist-date-from').value;
        const dateTo = document.getElementById('hist-date-to').value;

        const params = new URLSearchParams({
            action: 'transaction_history',
            id: activeCustomerId,
            page: page,
            limit: limit,
            search: search,
            module: module,
            status: status,
            date_from: dateFrom,
            date_to: dateTo
        });

        fetch(`admin_customer_operations.php?${params.toString()}`)
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#dc2626;">Error: ${data.error}</td></tr>`;
                    return;
                }

                document.getElementById('history-total-count').textContent = `${data.total_rows} items total`;
                
                if (!data.history.length) {
                    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:24px; color:#94a3b8;">No transactions match criteria.</td></tr>`;
                    document.getElementById('pagination-info').textContent = 'Page 0 of 0';
                    document.getElementById('pagination-prev').disabled = true;
                    document.getElementById('pagination-next').disabled = true;
                    return;
                }

                tbody.innerHTML = data.history.map(t => {
                    const modBadge = {'Fuel':'badge-walk-in', 'Merchandise':'badge-regular', 'Job Order':'badge-fleet'}[t.module] || 'badge-walk-in';
                    return `<tr>
                        <td>${fmtDateTime(t.txn_date)}</td>
                        <td><strong>${esc(t.reference_no)}</strong></td>
                        <td><span class="badge ${modBadge}">${t.module}</span></td>
                        <td>${esc(t.description)}</td>
                        <td><strong>\u20B1${fmt(t.amount)}</strong></td>
                        <td><span class="badge ${t.status.toLowerCase() === 'completed' ? 'badge-verified':'badge-pending'}">${t.status}</span></td>
                        <td>${esc(t.processed_by)}</td>
                    </tr>`;
                }).join('');

                historyTotalPages = data.total_pages;
                document.getElementById('pagination-info').textContent = `Page ${data.current_page} of ${data.total_pages}`;
                document.getElementById('pagination-prev').disabled = (data.current_page === 1);
                document.getElementById('pagination-next').disabled = (data.current_page === data.total_pages);
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#dc2626;">Error connecting.</td></tr>`;
            });
    }

    function changeHistoryPage(dir) {
        const targetPage = historyPage + dir;
        if (targetPage >= 1 && targetPage <= historyTotalPages) {
            loadHistory(targetPage);
        }
    }

    function resetHistoryFilters() {
        document.getElementById('hist-search').value = '';
        document.getElementById('hist-module').value = '';
        document.getElementById('hist-status').value = '';
        document.getElementById('hist-date-from').value = '';
        document.getElementById('hist-date-to').value = '';
        loadHistory(1);
    }

    // Export Specific Customer's Transaction History table
    function exportHistory(format) {
        if (!activeCustomerId) return;
        const params = new URLSearchParams({
            format: format,
            profile_id: activeCustomerId,
            export_type: 'history',
            search: document.getElementById('hist-search').value,
            module: document.getElementById('hist-module').value,
            status: document.getElementById('hist-status').value,
            date_from: document.getElementById('hist-date-from').value,
            date_to: document.getElementById('hist-date-to').value
        });
        window.open(`admin_customer_export.php?${params.toString()}`, '_blank');
    }

    // Document Preview
    function previewDocument(id, filepath, docType) {
        // Log access first
        const form = new FormData();
        form.append('action', 'log_document_access');
        form.append('id', id);
        form.append('doc_type', docType);

        fetch('admin_customer_operations.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const ext = filepath.split('.').pop().toLowerCase();
                    const container = document.getElementById('doc-preview-content');
                    document.getElementById('doc-modal-title').textContent = docType === 'gov_id' ? 'Government ID Verification Document' : 'Certificate of Registration (CR)';
                    
                    const absoluteUrl = '../' + filepath;

                    if (ext === 'pdf') {
                        container.innerHTML = `<iframe src="${absoluteUrl}" style="width:100%; height:550px; border:none; border-radius:6px;"></iframe>`;
                    } else if (['jpg', 'jpeg', 'png'].includes(ext)) {
                        container.innerHTML = `<img src="${absoluteUrl}" style="max-width:100%; max-height:550px; border-radius:6px; object-fit:contain; box-shadow:0 4px 10px rgba(0,0,0,0.1);">`;
                    } else {
                        container.innerHTML = `<div class="empty-state"><i class="fas fa-file-alt"></i><p>Preview unavailable. <a href="${absoluteUrl}" class="doc-link" download>Download File</a> instead.</p></div>`;
                    }
                    document.getElementById('doc-modal').classList.add('open');
                } else {
                    showToast('Document access denied', 'error');
                }
            })
            .catch(err => console.error(err));
    }

    function closeDocModal() {
        document.getElementById('doc-modal').classList.remove('open');
        document.getElementById('doc-preview-content').innerHTML = '';
    }
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
