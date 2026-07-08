<?php
/**
 * Staff Customer Management Module
 * Standardized Fuel Management UI/UX and data retrieval integration
 */
$page_id = 'customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    header('Location: dashboard.php');
    exit;
}

if (!in_array($role, ['superadmin', 'developer']) && !$station_id) {
    render_no_station_page('staff_dashboard.php');
}

$page_title = "Customers";
include __DIR__ . '/../partials/header.php';
?>

<style>
/* Header margin standardize */
.int-head {
    margin-top: 0px !important;
}

/* Modal Overlay */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(4px);
}
.modal-overlay.active {
    display: flex;
}
.modal-container {
    background: #ffffff;
    border-radius: 12px;
    max-width: 750px;
    width: 95%;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    border: 1px solid #e2e8f0;
}
.modal-header {
    padding: 18px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8fafc;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}
.modal-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #0f172a;
}
.modal-close {
    background: none !important;
    background-color: transparent !important;
    border: none !important;
    font-size: 24px;
    color: #64748b !important;
    cursor: pointer;
    box-shadow: none !important;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-close:hover {
    color: #0f172a !important;
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    background: #f8fafc;
    border-bottom-left-radius: 12px;
    border-bottom-right-radius: 12px;
}

/* Global Button Override Fixes - excluding export buttons */
.modal .btn,
.modal-body .btn,
.modal-footer .btn,
.show .modal-footer .btn,
.table .btn,
.table-wrap .btn,
.filter-bar .btn,
.card-head .btn,
.form-grid .btn,
.action-btns .btn,
.filters-bar button,
.header-actions button:not(.btn-export-excel):not(.btn-export-csv):not(.btn-export-pdf) {
    background-color: #ffffff !important;
    background: #ffffff !important;
    color: #334155 !important;
    border: 1px solid #cbd5e1 !important;
    font-weight: 600 !important;
    transition: all 0.15s ease-in-out !important;
    border-radius: 6px;
    padding: 8px 14px;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.modal .btn:hover,
.modal-body .btn:hover,
.modal-footer .btn:hover,
.table .btn:hover,
.table-wrap .btn:hover,
.filter-bar .btn:hover,
.card-head .btn:hover,
.form-grid .btn:hover,
.action-btns .btn:hover,
.filters-bar button:hover,
.header-actions button:not(.btn-export-excel):not(.btn-export-csv):not(.btn-export-pdf):hover {
    background-color: #f8fafc !important;
    background: #f8fafc !important;
    color: #1e293b !important;
    border-color: #94a3b8 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
}

/* Save / Primary Button Color exception inside modals & top bar */
.modal .btn-primary,
.modal-footer .btn-primary,
.btn-save-customer,
.btn-update-customer {
    background-color: #002F70 !important;
    background: #002F70 !important;
    color: #ffffff !important;
    border: 1px solid #002F70 !important;
}
.modal .btn-primary:hover,
.modal-footer .btn-primary:hover,
.btn-save-customer:hover,
.btn-update-customer:hover {
    background-color: #001f4d !important;
    background: #001f4d !important;
    border-color: #001f4d !important;
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.form-grid.full {
    grid-template-columns: 1fr;
}
.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.form-group label .required {
    color: #ef4444;
    margin-left: 2px;
}
.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 14px;
    transition: 0.2s;
    background-color: #ffffff;
}
.form-group input:focus, .form-group select:focus {
    outline: none;
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.15);
}

/* Customer Type Selector Options */
.type-selector {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 14px 0 20px 0;
}
.type-option {
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 14px;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s;
    background: #ffffff;
}
.type-option:hover {
    border-color: #002F70;
    background: #f8fafc;
}
.type-option.selected {
    border-color: #002F70;
    background: #eff6ff;
    box-shadow: 0 0 0 1px #002F70;
}
.type-option i {
    font-size: 24px;
    color: #002F70;
    display: block;
    margin-bottom: 6px;
}
.type-option span {
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}

/* Stats Cards Section */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.summary-card {
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 18px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.summary-card-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.summary-card-icon.blue { background: #eff6ff; color: #1d4ed8; }
.summary-card-icon.green { background: #ecfdf5; color: #059669; }
.summary-card-icon.yellow { background: #fef3c7; color: #d97706; }
.summary-card-icon.purple { background: #f5f3ff; color: #7c3aed; }
.summary-card-content h3 { margin: 0; font-size: 26px; font-weight: 700; color: #002F70; }
.summary-card-content p { margin: 4px 0 0; font-size: 12px; color: #64748b; font-weight: 500; }

/* Filters Area styling */
.filters-bar {
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 16px 20px;
    margin-bottom: 20px;
}
.filters-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.2fr 1.2fr auto;
    gap: 12px;
    align-items: flex-end;
}

/* Customers Main List Table */
.table-container {
    background: #ffffff;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    padding: 16px;
    overflow-x: auto;
}
.customers-table {
    width: 100%;
    border-collapse: collapse;
}
.customers-table thead {
    background: #f8fafc;
}
.customers-table th {
    padding: 12px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    border-bottom: 2px solid #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.customers-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #0f172a;
    font-size: 13.5px;
}
.customers-table tbody tr:hover {
    background: #f8fafc;
}

/* Type Badges */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.badge-walkin { background: #e0e7ff; color: #3730a3; }
.badge-regular { background: #fef3c7; color: #92400e; }
.badge-fleet { background: #dbeafe; color: #1e40af; }
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #991b1b; }

/* Table Action Buttons row */
.action-btns {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.action-btns button {
    padding: 6px 10px !important;
    font-size: 12px !important;
    width: 100%;
    background-color: #ffffff !important;
    background: #ffffff !important;
    color: #334155 !important;
    border: 1px solid #cbd5e1 !important;
}
.action-btns button:hover {
    background-color: #f8fafc !important;
    background: #f8fafc !important;
    color: #1e293b !important;
    border-color: #94a3b8 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05) !important;
}

/* Specific button colors for action buttons */
.action-btns .btn-view,
.action-btns button:first-child {
    color: #64748b !important;
    border-color: #64748b !important;
}
.action-btns .btn-view:hover,
.action-btns button:first-child:hover {
    background-color: #64748b !important;
    color: #ffffff !important;
    border-color: #64748b !important;
}

.action-btns .btn-edit,
.action-btns button:nth-child(2) {
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.action-btns .btn-edit:hover,
.action-btns button:nth-child(2):hover {
    background-color: #dc2626 !important;
    color: #ffffff !important;
    border-color: #dc2626 !important;
}

.action-btns .btn-print,
.action-btns button:nth-child(3),
.action-btns button:last-child {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.action-btns .btn-print:hover,
.action-btns button:nth-child(3):hover,
.action-btns button:last-child:hover {
    background-color: #002F70 !important;
    color: #ffffff !important;
    border-color: #002F70 !important;
}

/* Info Section inside View Modal */
.info-section {
    margin-bottom: 24px;
}
.info-section h4 {
    margin: 0 0 12px;
    font-size: 14px;
    font-weight: 700;
    color: #002F70;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 8px;
}
.info-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.info-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
}
.info-label {
    font-size: 12.5px;
    font-weight: 600;
    color: #64748b;
}
.info-value {
    font-size: 13px;
    color: #0f172a;
    font-weight: 500;
}

/* Transaction Card Summary */
.tx-summary {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin: 14px 0;
}
.tx-card {
    background: #f8fafc;
    border-radius: 6px;
    padding: 12px;
    text-align: center;
    border: 1px solid #e2e8f0;
}
.tx-card .num {
    font-size: 20px;
    font-weight: 800;
    color: #002F70;
}
.tx-card .lbl {
    font-size: 10px;
    color: #64748b;
    margin-top: 4px;
    text-transform: uppercase;
    font-weight: bold;
    letter-spacing: 0.5px;
}

/* Alerts styling */
.alert {
    padding: 12px 16px;
    border-radius: 6px;
    margin-bottom: 16px;
    font-size: 13px;
    display: none;
    align-items: center;
    gap: 8px;
}
.alert.show {
    display: flex;
}
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* Empty Table State */
.empty-state {
    text-align: center;
    padding: 48px 16px;
}
.empty-state i {
    font-size: 40px;
    color: #cbd5e1;
    margin-bottom: 12px;
}
.empty-state p {
    color: #64748b;
    margin: 8px 0 0;
    font-size: 14px;
}

/* Client Side Pagination for Transaction History */
.pagination-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
}
.pagination-info {
    font-size: 12.5px;
    color: #64748b;
}
.pagination-pages {
    display: flex;
    gap: 6px;
    align-items: center;
}
.pagination-pages button {
    padding: 5px 10px !important;
    font-size: 12px !important;
}

/* Nested Detail Popup Styles */
.detail-popup {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #ffffff;
    border-radius: 8px;
    box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.4);
    z-index: 10000;
    width: 90%;
    max-width: 500px;
    display: none;
    border: 1px solid #cbd5e1;
}
.detail-popup.active {
    display: block;
}
.detail-popup-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top-left-radius: 8px;
    border-top-right-radius: 8px;
}
.detail-popup-header h4 {
    margin: 0;
    color: #0f172a;
    font-size: 14px;
    font-weight: 700;
}
.detail-popup-body {
    padding: 20px;
    max-height: 400px;
    overflow-y: auto;
}
.detail-popup-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 12px 20px;
    display: flex;
    justify-content: flex-end;
    border-bottom-left-radius: 8px;
    border-bottom-right-radius: 8px;
}

@media(max-width:992px){
    .filters-grid{grid-template-columns: 1fr 1fr; gap:12px;}
    .summary-cards{grid-template-columns: 1fr 1fr;}
}
@media(max-width:576px){
    .filters-grid{grid-template-columns:1fr;}
    .summary-cards{grid-template-columns:1fr;}
    .type-selector{grid-template-columns:1fr;}
}

/* txn-btn styles for Add Customer button */
.txn-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    border: 1px solid transparent !important;
    transition: all 0.2s !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    background: #fff !important;
}
.txn-btn.primary {
    color: #00264D !important;
    border-color: #00264D !important;
}
.txn-btn.primary:hover {
    background: #00264D !important;
    color: #fff !important;
}

/* Export Buttons - Merchandise Inventory Style */
.btn-export-pdf,
.btn-export-excel,
.btn-export-csv {
    background: #ffffff !important;
    border: 1px solid transparent !important;
    border-radius: 7px !important;
    padding: 0 16px !important;
    height: 36px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    transition: all 0.15s !important;
    min-width: 90px !important;
    white-space: nowrap !important;
    text-decoration: none !important;
}

.btn-export-excel {
    color: #1d6f42 !important;
    border-color: #1d6f42 !important;
}
.btn-export-excel:hover {
    background: #1d6f42 !important;
    color: #ffffff !important;
}

.btn-export-csv {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.btn-export-csv:hover {
    background: #002F70 !important;
    color: #ffffff !important;
}

.btn-export-pdf {
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.btn-export-pdf:hover {
    background: #dc2626 !important;
    color: #ffffff !important;
}

/* ── Print CSS for PDF Export ── */
@media print {
    @page {
        margin: 0.5in;
    }
    
    /* Hide all UI elements */
    .sidebar, .int-head, .header-actions, .filters-bar, .action-btns,
    .modal, .no-print, nav, header, footer, button, .btn,
    #sidebar, .menu-toggle, .hamburger, [class*="toggle"],
    .summary-cards, .stats-cards, .card, .cust-stats,
    [class*="summary"], [class*="stats"], [class*="card"],
    #btnAddCustomer, .btn-add, [class*="btn-"] {
        display: none !important;
    }
    
    /* Hide Actions column - last column */
    table th:last-child,
    table td:last-child {
        display: none !important;
    }
    
    /* Hide all Font Awesome icons */
    i, svg, .fas, .far, .fab, .fa, [class*="fa-"], .icon {
        display: none !important;
    }
    
    /* Full width for table */
    body, html {
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: hidden !important;
        width: 100% !important;
    }
    
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }
    
    /* Print Header */
    body::before {
        content: "";
        display: block;
        text-align: center;
        margin-bottom: 20px;
    }
    
    /* Show only the customer table */
    #customersTableContainer {
        display: block !important;
        width: 100% !important;
        overflow: visible !important;
        padding: 20px 0 !important;
    }
    
    /* Add proper header before table */
    #customersTableContainer::before {
        content: "PETRON STATION MANAGEMENT SYSTEM";
        display: block;
        text-align: center;
        font-size: 16px;
        font-weight: bold;
        margin-bottom: 5px;
        color: #000;
    }
    
    #customersTableContainer::after {
        content: "CUSTOMER DIRECTORY";
        display: block;
        text-align: center;
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 2px solid #000;
        color: #000;
    }
    
    table {
        width: 100% !important;
        font-size: 9px !important;
        border-collapse: collapse !important;
        margin-top: 15px !important;
    }
    
    thead {
        display: table-header-group !important;
    }
    
    th {
        background: #fff !important;
        color: #000 !important;
        border: 1px solid #000 !important;
        padding: 6px 4px !important;
        font-size: 8px !important;
        font-weight: bold !important;
        text-align: center !important;
    }
    
    td {
        border: 1px solid #000 !important;
        padding: 4px 3px !important;
        font-size: 7px !important;
        text-align: left !important;
    }
    
    tr {
        page-break-inside: avoid !important;
    }
}

</style>

<div class="int-head" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #e9ecef;">
    <div>
        <h1 class="h1"><i class="fas fa-users"></i> CUSTOMERS</h1>
        <div class="sub">VIEW AND MANAGE CUSTOMERS AT YOUR STATION</div>
    </div>
    <div class="header-actions" style="display: flex; gap: 10px; align-items: center;">
        <button onclick="exportCustomerData('excel')" class="btn-export-excel"><i class="fas fa-file-excel"></i> Excel</button>
        <button onclick="exportCustomerData('csv')" class="btn-export-csv"><i class="fas fa-file-csv"></i> CSV</button>
        <button onclick="exportCustomerData('pdf')" class="btn-export-pdf"><i class="fas fa-file-pdf"></i> PDF</button>
    </div>
</div>

<!-- Stats Cards -->
<div class="summary-cards">
    <div class="summary-card">
        <div class="summary-card-icon blue"><i class="fas fa-users"></i></div>
        <div class="summary-card-content">
            <h3 id="totalCustomersCount">—</h3>
            <p>Total Customers</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon green"><i class="fas fa-user-plus"></i></div>
        <div class="summary-card-content">
            <h3 id="newCustomersCount">—</h3>
            <p>New Today</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon yellow"><i class="fas fa-star"></i></div>
        <div class="summary-card-content">
            <h3 id="regularCustomersCount">—</h3>
            <p>Regular Customers</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon purple"><i class="fas fa-building"></i></div>
        <div class="summary-card-content">
            <h3 id="fleetAccountsCount">—</h3>
            <p>Fleet Accounts</p>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="filters-bar">
    <div class="filters-grid">
        <div class="form-group" style="margin-bottom: 0;">
            <label>Search Customer</label>
            <input type="text" id="custSearchInput" placeholder="Customer ID / Name / Contact Number...">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Customer Type</label>
            <select id="custFilterType">
                <option value="">All</option>
                <option value="walk-in">Walk-in</option>
                <option value="regular">Regular</option>
                <option value="fleet">Fleet / Company</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Status</label>
            <select id="custFilterStatus">
                <option value="">All</option>
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Date Registered From</label>
            <input type="date" id="custFilterDateFrom">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Date Registered To</label>
            <input type="date" id="custFilterDateTo">
        </div>
        <div style="margin-bottom: 0; display: flex; gap: 8px;">
            <button class="btn-primary" onclick="loadCustomerList()"><i class="fas fa-search"></i> Search</button>
            <button onclick="resetCustomerFilters()"><i class="fas fa-redo"></i> Reset</button>
        </div>
    </div>
</div>

<!-- Customers Table Container -->
<div class="table-container">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid #e5e7eb;">
        <div style="font-size:15px;font-weight:600;color:#1e293b;">
            <i class="fas fa-users"></i> Customer Records
        </div>
        <div>
            <button onclick="openCustomerModal('addCustomerModal')" class="txn-btn primary" style="height:36px;">
                <i class="fas fa-user-plus"></i> Add Customer
            </button>
        </div>
    </div>
    <div id="tableContent">
        <div class="loading" style="text-align:center; padding: 40px;">
            <i class="fas fa-spinner fa-spin" style="font-size:24px; color:#002F70; margin-bottom:8px;"></i>
            <p style="color:#64748b; margin:0;">Loading customers...</p>
        </div>
    </div>
</div>

<!-- ADD CUSTOMER MODAL -->
<div class="modal-overlay" id="addCustomerModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
            <button class="modal-close" onclick="closeCustomerModal('addCustomerModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success" id="addSuccess"></div>
            <div class="alert alert-error" id="addError"></div>
            
            <form id="addForm" enctype="multipart/form-data">
                <div style="background:#f0f9ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; margin-bottom:16px; font-size:13px; color:#1e40af;">
                    <i class="fas fa-info-circle"></i> <strong>Customer ID</strong> will be auto-generated.
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="addFirstName" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" id="addMiddleName">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" id="addLastName" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="text" name="contact_number" id="addContact" placeholder="e.g. 09123456789" required>
                    </div>
                </div>
                
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Address <span class="required">*</span></label>
                        <input type="text" name="address" id="addAddress" placeholder="Complete Address" required>
                    </div>
                </div>
                
                <label style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:8px;">
                    Customer Type <span class="required">*</span>
                </label>
                <div class="type-selector" id="addTypeSelector">
                    <div class="type-option selected" data-value="walk-in" onclick="selectCustomerType('add', 'walk-in')">
                        <i class="fas fa-walking"></i>
                        <span>Walk-in</span>
                    </div>
                    <div class="type-option" data-value="regular" onclick="selectCustomerType('add', 'regular')">
                        <i class="fas fa-star"></i>
                        <span>Regular</span>
                    </div>
                    <div class="type-option" data-value="fleet" onclick="selectCustomerType('add', 'fleet')">
                        <i class="fas fa-building"></i>
                        <span>Fleet/Company</span>
                    </div>
                </div>
                <input type="hidden" name="customer_type" id="addCustomerType" value="walk-in">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Government ID Type</label>
                        <select name="gov_id_type" id="addGovIdType">
                            <option value="">Select ID Type...</option>
                            <option>PhilSys ID</option>
                            <option>Driver's License</option>
                            <option>Passport</option>
                            <option>SSS / GSIS ID</option>
                            <option>PRC ID</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Upload Government ID</label>
                        <input type="file" name="gov_id_image" accept="image/*,.pdf">
                    </div>
                </div>
                
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Upload Certificate of Registration (CR)</label>
                        <input type="file" name="cr_document" accept="image/*,.pdf">
                    </div>
                </div>
                
                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:10px 14px; font-size:12px; color:#92400e; margin-top:12px;">
                    <i class="fas fa-lock"></i> Staff can upload the documents but cannot preview, open, or download them after saving.
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button onclick="closeCustomerModal('addCustomerModal')"><i class="fas fa-times"></i> Cancel</button>
            <button onclick="submitCustomerAdd()" id="addSubmitBtn" class="btn-primary"><i class="fas fa-save"></i> Save Customer</button>
        </div>
    </div>
</div>

<!-- EDIT CUSTOMER MODAL -->
<div class="modal-overlay" id="editCustomerModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Customer Details</h3>
            <button class="modal-close" onclick="closeCustomerModal('editCustomerModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success" id="editSuccess"></div>
            <div class="alert alert-error" id="editError"></div>
            
            <form id="editForm">
                <input type="hidden" name="customer_id" id="editCustomerId">
                
                <div style="background:#f0f9ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px; margin-bottom:16px; font-size:13px; color:#1e40af;">
                    <div style="margin-bottom:6px;"><i class="fas fa-id-card"></i> <strong>Customer ID:</strong> <span id="editCustIdDisplay"></span></div>
                    <div><i class="fas fa-calendar"></i> <strong>Date Registered:</strong> <span id="editRegDateDisplay"></span></div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="editFirstName" required>
                    </div>
                    <div class="form-group">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" id="editMiddleName">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" id="editLastName" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number <span class="required">*</span></label>
                        <input type="text" name="contact_number" id="editContact" required>
                    </div>
                </div>
                
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Address <span class="required">*</span></label>
                        <input type="text" name="address" id="editAddress" required>
                    </div>
                </div>
                
                <label style="display:block; font-size:13px; font-weight:600; color:#334155; margin-bottom:8px;">
                    Customer Type <span class="required">*</span>
                </label>
                <div class="type-selector" id="editTypeSelector">
                    <div class="type-option" data-value="walk-in" onclick="selectCustomerType('edit', 'walk-in')">
                        <i class="fas fa-walking"></i>
                        <span>Walk-in</span>
                    </div>
                    <div class="type-option" data-value="regular" onclick="selectCustomerType('edit', 'regular')">
                        <i class="fas fa-star"></i>
                        <span>Regular</span>
                    </div>
                    <div class="type-option" data-value="fleet" onclick="selectCustomerType('edit', 'fleet')">
                        <i class="fas fa-building"></i>
                        <span>Fleet/Company</span>
                    </div>
                </div>
                <input type="hidden" name="customer_type" id="editCustomerType" value="walk-in">
            </form>
        </div>
        <div class="modal-footer">
            <button onclick="closeCustomerModal('editCustomerModal')"><i class="fas fa-times"></i> Cancel</button>
            <button onclick="submitCustomerEdit()" id="editSubmitBtn" class="btn-primary"><i class="fas fa-save"></i> Update Customer</button>
        </div>
    </div>
</div>

<!-- VIEW CUSTOMER PROFILE MODAL -->
<div class="modal-overlay" id="viewCustomerModal">
    <div class="modal-container" style="max-width:850px;">
        <div class="modal-header">
            <h3><i class="fas fa-id-card"></i> Customer Profile View</h3>
            <button class="modal-close" onclick="closeCustomerModal('viewCustomerModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding-bottom:10px;">
            <div id="viewModalLoader" style="text-align:center; padding:40px;">
                <i class="fas fa-spinner fa-spin" style="font-size:24px; color:#002F70; margin-bottom:8px;"></i>
                <p style="color:#64748b; margin:0;">Fetching profile data...</p>
            </div>
            
            <div id="viewModalContent" style="display:none;">
                <!-- Profile Header -->
                <div style="background:linear-gradient(135deg,#002F70,#004c99); color:#ffffff; padding:20px; border-radius:8px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <h2 style="margin:0 0 4px; font-size:22px; color:#ffffff; font-weight:700;" id="profFullName"></h2>
                            <p style="margin:0 0 10px; font-size:13px; opacity:0.9;" id="profCustId"></p>
                            <div style="display:flex; gap:8px;">
                                <span class="badge" id="profTypeBadge"></span>
                                <span class="badge" id="profStatusBadge"></span>
                            </div>
                        </div>
                        <div style="text-align:right; font-size:12px; opacity:0.85;">
                            <div><strong>Registered:</strong> <span id="profRegDate"></span></div>
                            <div style="margin-top:2px;"><strong>Last Visit:</strong> <span id="profLastDate"></span></div>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Details -->
                <div class="info-section">
                    <h4><i class="fas fa-user"></i> Customer Information</h4>
                    <div class="info-grid-2">
                        <div>
                            <div class="info-row"><span class="info-label">Customer ID</span><span class="info-value" id="valCustId"></span></div>
                            <div class="info-row"><span class="info-label">Full Name</span><span class="info-value" id="valFullName"></span></div>
                            <div class="info-row"><span class="info-label">Contact Number</span><span class="info-value" id="valContact"></span></div>
                        </div>
                        <div>
                            <div class="info-row"><span class="info-label">Address</span><span class="info-value" id="valAddress"></span></div>
                            <div class="info-row"><span class="info-label">Customer Type</span><span class="info-value" id="valType"></span></div>
                            <div class="info-row"><span class="info-label">Status</span><span class="info-value" id="valStatus"></span></div>
                        </div>
                    </div>
                </div>
                
                <!-- Transaction Summary Cards -->
                <div class="info-section">
                    <h4><i class="fas fa-chart-bar"></i> Transaction Summary</h4>
                    <div class="tx-summary">
                        <div class="tx-card">
                            <div class="num" id="statFuelCount">0</div>
                            <div class="lbl">Fuel Transactions</div>
                        </div>
                        <div class="tx-card">
                            <div class="num" id="statMerchCount">0</div>
                            <div class="lbl">Merchandise</div>
                        </div>
                        <div class="tx-card">
                            <div class="num" id="statServiceCount">0</div>
                            <div class="lbl">Job Orders</div>
                        </div>
                        <div class="tx-card" style="background:#ecfdf5; border-color:#a7f3d0;">
                            <div class="num" style="color:#059669;" id="statTotalSpent">\u20B10.00</div>
                            <div class="lbl" style="color:#059669;">Total Amount Spent</div>
                        </div>
                    </div>
                </div>

                <!-- Staff Restricted Fields Notice -->
                <div style="background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin: 4px 0 12px; font-size:12px; color:#92400e;">
                    <div style="display:flex; align-items:center; gap:8px; font-weight:700; margin-bottom:6px;">
                        <i class="fas fa-lock"></i> Staff-Restricted Information
                    </div>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:3px 16px;">
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Government ID Image</span>
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Certificate of Registration (CR)</span>
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Outstanding Balance</span>
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Credit Limit</span>
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Payment History</span>
                        <span><i class="fas fa-times-circle" style="color:#dc2626;"></i> Verification Status</span>
                    </div>
                    <div style="margin-top:6px; font-style:italic;">These fields are accessible to Managers and Administrators only.</div>
                </div>
                
                <!-- History Table with Filters -->
                <div class="info-section">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                        <h4 style="margin:0; border:none; padding:0;"><i class="fas fa-history"></i> Transaction History</h4>
                        
                        <!-- Mini Inline Filters -->
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" id="txFilterSearch" placeholder="Search Ref No..." style="padding:4px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px; width:130px;" oninput="onTxFilterChange()">
                            <select id="txFilterModule" style="padding:4px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px;" onchange="onTxFilterChange()">
                                <option value="">Module: All</option>
                                <option value="Merchandise">Merchandise</option>
                                <option value="Job Order">Job Order</option>
                                <option value="Fuel">Fuel</option>
                            </select>
                            <select id="txFilterStatus" style="padding:4px 8px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px;" onchange="onTxFilterChange()">
                                <option value="">Status: All</option>
                                <option value="Completed">Completed</option>
                                <option value="Pending">Pending</option>
                            </select>
                            <input type="date" id="txFilterDateFrom" style="padding:3px 6px; font-size:11px; border:1px solid #cbd5e1; border-radius:4px; width:105px;" onchange="onTxFilterChange()">
                            <input type="date" id="txFilterDateTo" style="padding:3px 6px; font-size:11px; border:1px solid #cbd5e1; border-radius:4px; width:105px;" onchange="onTxFilterChange()">
                        </div>
                    </div>
                    
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 6px;">
                        <table class="customers-table" style="font-size:12px;">
                            <thead style="position:sticky; top:0; z-index:10; background:#f8fafc;">
                                <tr>
                                    <th>Date</th>
                                    <th>Reference No.</th>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="txHistoryTableBody">
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination footer -->
                    <div class="pagination-controls">
                        <div class="pagination-info" id="txPaginationInfo">Showing 0–0 of 0 transactions</div>
                        <div class="pagination-pages">
                            <span style="font-size:12px; color:#64748b; margin-right:4px;">Rows per page:</span>
                            <select id="txRowsPerPage" style="padding:3px; font-size:12px; border:1px solid #cbd5e1; border-radius:4px; margin-right:12px;" onchange="onTxLimitChange()">
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <button onclick="prevTxPage()"><i class="fas fa-chevron-left"></i> Previous</button>
                            <button onclick="nextTxPage()">Next <i class="fas fa-chevron-right"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="printCustomerProfileFromModal()"><i class="fas fa-print"></i> Print Customer Profile</button>
            <button onclick="closeCustomerModal('viewCustomerModal')"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- READ-ONLY TRANSACTION DETAILS POPUP -->
<div class="detail-popup" id="transactionDetailPopup">
    <div class="detail-popup-header">
        <h4 id="popTxTitle">Transaction Details</h4>
        <button class="modal-close" onclick="closeTxDetailPopup()">&times;</button>
    </div>
    <div class="detail-popup-body">
        <div id="popTxLoader" style="text-align:center; padding:20px; display:none;">
            <i class="fas fa-spinner fa-spin" style="font-size:18px; color:#002F70;"></i>
        </div>
        <div id="popTxContent">
            <!-- Dynamic fields -->
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:14px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                <div><strong>Ref No:</strong> <span id="popRefNo"></span></div>
                <div><strong>Date:</strong> <span id="popDate"></span></div>
                <div><strong>Module:</strong> <span id="popModule"></span></div>
                <div><strong>Status:</strong> <span id="popStatus"></span></div>
                <div><strong>Amount:</strong> <span id="popAmount" style="font-weight:700; color:#002F70;"></span></div>
            </div>
            
            <div style="font-weight:700; font-size:12px; color:#64748b; margin-bottom:6px; text-transform:uppercase;">Item Breakdown / description</div>
            <div id="popBreakdownContent" style="max-height:160px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:4px; padding:8px; font-size:12.5px;">
                <!-- Table / list -->
            </div>
        </div>
    </div>
    <div class="detail-popup-footer">
        <button onclick="closeTxDetailPopup()">Close</button>
    </div>
</div>

<script>
const STATION_ID = <?= (int)$station_id ?>;
let currentCustomers = [];
let currentViewingCustomerId = null;

// Modal transaction variables
let modalTransactions = [];
let filteredTransactions = [];
let txCurrentPage = 1;
let txLimit = 10;

document.addEventListener('DOMContentLoaded', () => {
    loadCustomerList();
});

// Load customer directory list
function loadCustomerList() {
    const search = document.getElementById('custSearchInput').value;
    const type = document.getElementById('custFilterType').value;
    const status = document.getElementById('custFilterStatus').value;
    const dateFrom = document.getElementById('custFilterDateFrom').value;
    const dateTo = document.getElementById('custFilterDateTo').value;
    
    const params = new URLSearchParams({
        action: 'list',
        search: search,
        type: type,
        status: status,
        date_from: dateFrom,
        date_to: dateTo
    });
    
    fetch(`staff_customer_operations.php?${params}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentCustomers = data.customers || [];
                updateCustomerStats(data.stats || {});
                renderCustomerTable(currentCustomers);
            } else {
                showCustomerError('Failed to load customer list.');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showCustomerError('Network error while loading customer records.');
        });
}

function updateCustomerStats(stats) {
    document.getElementById('totalCustomersCount').textContent = formatNumber(stats.total || 0);
    document.getElementById('newCustomersCount').textContent = formatNumber(stats.new_today || 0);
    document.getElementById('regularCustomersCount').textContent = formatNumber(stats.regular || 0);
    document.getElementById('fleetAccountsCount').textContent = formatNumber(stats.fleet || 0);
}

function renderCustomerTable(customers) {
    const container = document.getElementById('tableContent');
    
    if (!customers || customers.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No customers found matching the search/filter criteria.</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <table class="customers-table">
            <thead>
                <tr>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Contact Number</th>
                    <th>Customer Type</th>
                    <th>Total Transactions</th>
                    <th>Last Transaction</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    customers.forEach(c => {
        const fullName = [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ');
        const typeClass = c.customer_type === 'walk-in' ? 'walkin' : (c.customer_type === 'regular' ? 'regular' : 'fleet');
        const statusClass = c.status === 'active' ? 'active' : 'inactive';
        const lastTxDate = c.last_transaction ? formatDate(c.last_transaction) : 'Never';
        const typeLabel = c.customer_type === 'fleet' ? 'Fleet' : capitalize(c.customer_type);
        
        html += `
            <tr>
                <td><strong>${escapeHtml(c.customer_id || '—')}</strong></td>
                <td>${escapeHtml(fullName)}</td>
                <td>${escapeHtml(c.contact_number || '—')}</td>
                <td><span class="badge badge-${typeClass}">${typeLabel}</span></td>
                <td><strong>${formatNumber(c.total_transactions || 0)}</strong></td>
                <td>${lastTxDate}</td>
                <td><span class="badge badge-${statusClass}">${capitalize(c.status)}</span></td>
                <td>
                    <div class="action-btns">
                        <button onclick="viewCustomerDetail(${c.id})"><i class="fas fa-eye"></i> View Profile</button>
                        <button onclick="openCustomerEditModal(${c.id})"><i class="fas fa-edit"></i> Edit</button>
                        <button onclick="printCustomerProfile(${c.id})"><i class="fas fa-print"></i> Print</button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

function showCustomerError(message) {
    document.getElementById('tableContent').innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-circle" style="color:#ef4444;"></i>
            <p style="color:#ef4444;">${message}</p>
        </div>
    `;
}

// Modal helpers
function openCustomerModal(id) {
    document.getElementById(id).classList.add('active');
}
function closeCustomerModal(id) {
    document.getElementById(id).classList.remove('active');
}

// Select Customer Type in Modal
function selectCustomerType(mode, value) {
    const container = document.getElementById(`${mode}TypeSelector`);
    container.querySelectorAll('.type-option').forEach(opt => {
        if (opt.dataset.value === value) opt.classList.add('selected');
        else opt.classList.remove('selected');
    });
    document.getElementById(`${mode}CustomerType`).value = value;
}

// Open Add Customer Modal
function openCustomerAddModal() {
    document.getElementById('addForm').reset();
    selectCustomerType('add', 'walk-in');
    document.getElementById('addSuccess').classList.remove('show');
    document.getElementById('addError').classList.remove('show');
    openCustomerModal('addCustomerModal');
}

function submitCustomerAdd() {
    const form = document.getElementById('addForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    
    const formData = new FormData(form);
    formData.append('action', 'add');
    
    const btn = document.getElementById('addSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('staff_customer_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Customer';
        if (data.success) {
            document.getElementById('addSuccess').textContent = data.message;
            document.getElementById('addSuccess').classList.add('show');
            setTimeout(() => {
                closeCustomerModal('addCustomerModal');
                loadCustomerList();
            }, 1200);
        } else {
            document.getElementById('addError').textContent = data.error;
            document.getElementById('addError').classList.add('show');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Customer';
    });
}

// Open Edit Customer Modal
function openCustomerEditModal(id) {
    const c = currentCustomers.find(item => item.id === id);
    if (!c) return;
    
    document.getElementById('editCustomerId').value = c.id;
    document.getElementById('editCustIdDisplay').textContent = c.customer_id;
    document.getElementById('editRegDateDisplay').textContent = formatDate(c.registered_at);
    document.getElementById('editFirstName').value = c.first_name;
    document.getElementById('editMiddleName').value = c.middle_name;
    document.getElementById('editLastName').value = c.last_name;
    document.getElementById('editContact').value = c.contact_number;
    document.getElementById('editAddress').value = c.address || '';
    
    selectCustomerType('edit', c.customer_type);
    
    document.getElementById('editSuccess').classList.remove('show');
    document.getElementById('editError').classList.remove('show');
    openCustomerModal('editCustomerModal');
}

function submitCustomerEdit() {
    const form = document.getElementById('editForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    
    const formData = new FormData(form);
    formData.append('action', 'update');
    
    const btn = document.getElementById('editSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('staff_customer_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Customer';
        if (data.success) {
            document.getElementById('editSuccess').textContent = data.message;
            document.getElementById('editSuccess').classList.add('show');
            setTimeout(() => {
                closeCustomerModal('editCustomerModal');
                loadCustomerList();
            }, 1200);
        } else {
            document.getElementById('editError').textContent = data.error;
            document.getElementById('editError').classList.add('show');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Customer';
    });
}

// View Customer Profile Modal with complete Transaction History
function viewCustomerDetail(id) {
    currentViewingCustomerId = id;
    openCustomerModal('viewCustomerModal');
    
    document.getElementById('viewModalLoader').style.display = 'block';
    document.getElementById('viewModalContent').style.display = 'none';
    
    // Clear filters
    document.getElementById('txFilterSearch').value = '';
    document.getElementById('txFilterModule').value = '';
    document.getElementById('txFilterStatus').value = '';
    document.getElementById('txFilterDateFrom').value = '';
    document.getElementById('txFilterDateTo').value = '';
    
    fetch(`staff_customer_operations.php?action=view&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const c = data.customer;
                const tx = data.transactions;
                
                const fullName = [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ');
                
                // Set text fields
                document.getElementById('profFullName').textContent = fullName;
                document.getElementById('profCustId').textContent = 'ID: ' + c.customer_id;
                document.getElementById('profRegDate').textContent = formatDate(c.registered_at);
                document.getElementById('profLastDate').textContent = tx.last_transaction ? formatDate(tx.last_transaction) : 'Never';
                
                // Badges
                const typeClass = c.customer_type === 'walk-in' ? 'badge-walkin' : (c.customer_type === 'regular' ? 'badge-regular' : 'badge-fleet');
                const statusClass = c.status === 'active' ? 'badge-active' : 'badge-inactive';
                
                document.getElementById('profTypeBadge').className = 'badge ' + typeClass;
                document.getElementById('profTypeBadge').textContent = c.customer_type === 'fleet' ? 'Fleet' : capitalize(c.customer_type);
                
                document.getElementById('profStatusBadge').className = 'badge ' + statusClass;
                document.getElementById('profStatusBadge').textContent = capitalize(c.status);
                
                // Profile Information Table
                document.getElementById('valCustId').textContent = c.customer_id;
                document.getElementById('valFullName').textContent = fullName;
                document.getElementById('valContact').textContent = c.contact_number || '—';
                document.getElementById('valAddress').textContent = c.address || '—';
                document.getElementById('valType').textContent = c.customer_type === 'fleet' ? 'Fleet' : capitalize(c.customer_type);
                document.getElementById('valStatus').textContent = capitalize(c.status);
                
                // Stats summary
                document.getElementById('statFuelCount').textContent = formatNumber(tx.fuel_count || 0);
                document.getElementById('statMerchCount').textContent = formatNumber(tx.merch_count || 0);
                document.getElementById('statServiceCount').textContent = formatNumber(tx.service_count || 0);
                document.getElementById('statTotalSpent').textContent = '\u20B1' + formatNumber(tx.total_amount || 0);
                
                // Save history to memory
                modalTransactions = data.all_transactions || [];
                filteredTransactions = [...modalTransactions];
                txCurrentPage = 1;
                
                renderModalTransactionTable();
                
                document.getElementById('viewModalLoader').style.display = 'none';
                document.getElementById('viewModalContent').style.display = 'block';
            }
        });
}

// Filter transaction list inside Modal
function onTxFilterChange() {
    const search = document.getElementById('txFilterSearch').value.toLowerCase();
    const module = document.getElementById('txFilterModule').value;
    const status = document.getElementById('txFilterStatus').value;
    const dfrom = document.getElementById('txFilterDateFrom').value;
    const dto = document.getElementById('txFilterDateTo').value;
    
    filteredTransactions = modalTransactions.filter(t => {
        if (search && !(t.reference_no.toLowerCase().includes(search) || t.description.toLowerCase().includes(search))) return false;
        if (module && t.module !== module) return false;
        if (status && t.status.toLowerCase() !== status.toLowerCase()) return false;
        if (dfrom && t.txn_date.substring(0, 10) < dfrom) return false;
        if (dto && t.txn_date.substring(0, 10) > dto) return false;
        return true;
    });
    
    txCurrentPage = 1;
    renderModalTransactionTable();
}

function onTxLimitChange() {
    txLimit = parseInt(document.getElementById('txRowsPerPage').value);
    txCurrentPage = 1;
    renderModalTransactionTable();
}

function prevTxPage() {
    if (txCurrentPage > 1) {
        txCurrentPage--;
        renderModalTransactionTable();
    }
}

function nextTxPage() {
    const totalPages = Math.ceil(filteredTransactions.length / txLimit);
    if (txCurrentPage < totalPages) {
        txCurrentPage++;
        renderModalTransactionTable();
    }
}

function renderModalTransactionTable() {
    const tbody = document.getElementById('txHistoryTableBody');
    tbody.innerHTML = '';
    
    if (filteredTransactions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:#64748b; padding:16px;">No transactions recorded.</td></tr>';
        document.getElementById('txPaginationInfo').textContent = 'Showing 0–0 of 0 transactions';
        return;
    }
    
    const start = (txCurrentPage - 1) * txLimit;
    const end = Math.min(start + txLimit, filteredTransactions.length);
    const paginated = filteredTransactions.slice(start, end);
    
    paginated.forEach(t => {
        const tr = document.createElement('tr');
        const stClass = t.status.toLowerCase().includes('complete') ? 'badge-active' : 'badge-inactive';
        
        tr.innerHTML = `
            <td>${formatDate(t.txn_date)}</td>
            <td><strong>${escapeHtml(t.reference_no)}</strong></td>
            <td><span class="badge badge-regular" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">${escapeHtml(t.module)}</span></td>
            <td>${escapeHtml(t.description)}</td>
            <td style="font-weight:700;">\u20B1${formatNumber(t.amount)}</td>
            <td><span class="badge ${stClass}">${escapeHtml(t.status)}</span></td>
            <td>
                <button class="btn" style="padding:4px 8px !important; font-size:11px !important;" onclick="viewTxDetailPopup('${t.module}', ${t.source_id}, '${escapeHtml(t.reference_no)}', '${formatDate(t.txn_date)}', '\u20B1${formatNumber(t.amount)}', '${escapeHtml(t.status)}')">
                    <i class="fas fa-eye"></i> View Transaction
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
    
    document.getElementById('txPaginationInfo').textContent = `Showing ${start + 1}–${end} of ${filteredTransactions.length} transactions`;
}

// Read-Only Transaction Item Breakdown details Popup
function viewTxDetailPopup(module, id, ref, date, amt, status) {
    document.getElementById('popRefNo').textContent = ref;
    document.getElementById('popDate').textContent = date;
    document.getElementById('popModule').textContent = module;
    document.getElementById('popStatus').textContent = status;
    document.getElementById('popAmount').textContent = amt;
    
    document.getElementById('popTxLoader').style.display = 'block';
    document.getElementById('popBreakdownContent').innerHTML = '';
    document.getElementById('transactionDetailPopup').classList.add('active');
    
    let sourceParam = 'merchandise_transactions';
    if (module === 'Job Order') sourceParam = 'job_orders';
    
    if (module === 'Fuel') {
        document.getElementById('popTxLoader').style.display = 'none';
        document.getElementById('popBreakdownContent').innerHTML = `
            <div style="padding:10px; background:#f8fafc; border-radius:4px; font-weight:600; text-align:center;">
                Fuel Transaction Sale Details: ${ref}
            </div>
        `;
        return;
    }
    
    fetch(`get_transaction_items.php?id=${id}&source=${sourceParam}`)
        .then(res => res.json())
        .then(data => {
            document.getElementById('popTxLoader').style.display = 'none';
            if (data.items && data.items.length > 0) {
                let listHtml = '<table style="width:100%; border-collapse:collapse; font-size:12px;">';
                listHtml += '<tr style="border-bottom:1px solid #cbd5e1; font-weight:700;"><td style="padding:4px;">Item / Service</td><td style="padding:4px; text-align:center;">Qty</td><td style="padding:4px; text-align:right;">Subtotal</td></tr>';
                data.items.forEach(item => {
                    listHtml += `<tr style="border-bottom:1px solid #f1f5f9;"><td style="padding:4px;">${escapeHtml(item.product_name)}</td><td style="padding:4px; text-align:center;">${item.quantity}</td><td style="padding:4px; text-align:right;">\u20B1${formatNumber(item.subtotal)}</td></tr>`;
                });
                listHtml += '</table>';
                document.getElementById('popBreakdownContent').innerHTML = listHtml;
            } else {
                document.getElementById('popBreakdownContent').innerHTML = '<div style="color:#64748b; text-align:center; padding:10px;">No breakdown details available.</div>';
            }
        })
        .catch(() => {
            document.getElementById('popTxLoader').style.display = 'none';
            document.getElementById('popBreakdownContent').innerHTML = '<div style="color:#ef4444; text-align:center; padding:10px;">Error loading items.</div>';
        });
}

function closeTxDetailPopup() {
    document.getElementById('transactionDetailPopup').classList.remove('active');
}

// Print Customer Profile with all transactions included (Print layout)
function printCustomerProfile(id) {
    fetch(`staff_customer_operations.php?action=view&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const c = data.customer;
                const tx = data.transactions;
                const rec = data.all_transactions || [];
                
                const fullName = [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ');
                
                let txHtml = '';
                if (rec.length > 0) {
                    rec.forEach(r => {
                        txHtml += `
                            <tr>
                                <td>${formatDate(r.txn_date)}</td>
                                <td><strong>${escapeHtml(r.reference_no)}</strong></td>
                                <td>${escapeHtml(r.module)}</td>
                                <td>${escapeHtml(r.description)}</td>
                                <td style="text-align: right; font-weight: bold;">\u20B1${formatNumber(r.amount)}</td>
                                <td>${escapeHtml(r.status)}</td>
                            </tr>
                        `;
                    });
                } else {
                    txHtml = '<tr><td colspan="6" style="text-align: center; color: #64748b; padding: 12px;">No transaction records found.</td></tr>';
                }
                
                const printWin = window.open('', '_blank');
                printWin.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Customer Profile Summary - ${c.customer_id}</title>
                        <style>
                            body { font-family: 'Segoe UI', Arial, sans-serif; color: #1e293b; margin: 30px; font-size: 13px; line-height: 1.4; }
                            .header { border-bottom: 2px solid #002F70; padding-bottom: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; }
                            .header h1 { margin: 0; color: #002F70; font-size: 20px; text-transform: uppercase; }
                            .station-info { text-align: right; font-size: 11px; color: #64748b; }
                            .section { margin-bottom: 24px; }
                            .section-title { font-size: 13px; font-weight: 700; color: #002F70; border-bottom: 1.5px solid #cbd5e1; padding-bottom: 5px; margin-bottom: 12px; text-transform: uppercase; }
                            .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; }
                            .info-item { display: flex; justify-content: space-between; border-bottom: 1px solid #f1f5f9; padding: 5px 0; }
                            .info-label { font-weight: 600; color: #475569; }
                            .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 15px; }
                            .stats-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center; }
                            .stats-card .num { font-size: 18px; font-weight: 800; color: #002F70; }
                            .stats-card .lbl { font-size: 9px; color: #64748b; text-transform: uppercase; margin-top: 2px; font-weight: bold; }
                            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                            th { background: #002F70; color: white; padding: 7px 10px; font-size: 11px; text-align: left; text-transform: uppercase; }
                            td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
                            tr:nth-child(even) { background: #f8fafc; }
                            .footer { margin-top: 40px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <div>
                                <h1>PETRON CUSTOMER PROFILE REPORT</h1>
                                <div style="margin-top:4px;"><strong>ID:</strong> ${escapeHtml(c.customer_id)}</div>
                            </div>
                            <div class="station-info">
                                <strong>Petron Service Station</strong><br>
                                Station ID: ${STATION_ID}
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Customer Information</div>
                            <div class="info-grid">
                                <div class="info-item"><span class="info-label">Customer Name:</span><span>${escapeHtml(fullName)}</span></div>
                                <div class="info-item"><span class="info-label">Customer Type:</span><span>${capitalize(c.customer_type)}</span></div>
                                <div class="info-item"><span class="info-label">Contact Number:</span><span>${escapeHtml(c.contact_number)}</span></div>
                                <div class="info-item"><span class="info-label">Status:</span><span>${capitalize(c.status)}</span></div>
                                <div class="info-item" style="grid-column: span 2;"><span class="info-label">Address:</span><span>${escapeHtml(c.address)}</span></div>
                                <div class="info-item"><span class="info-label">Date Registered:</span><span>${formatDate(c.registered_at)}</span></div>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Transaction Summary Overview</div>
                            <div class="stats-row">
                                <div class="stats-card"><div class="num">${tx.fuel_count || 0}</div><div class="lbl">Fuel Transactions</div></div>
                                <div class="stats-card"><div class="num">${tx.merch_count || 0}</div><div class="lbl">Merchandise</div></div>
                                <div class="stats-card"><div class="num">${tx.service_count || 0}</div><div class="lbl">Job Orders</div></div>
                                <div class="stats-card" style="background: #ecfdf5; border-color:#a7f3d0;"><div class="num" style="color: #059669;">\u20B1${formatNumber(tx.total_amount || 0)}</div><div class="lbl" style="color: #059669;">Total Amount Spent</div></div>
                            </div>
                            ${tx.last_transaction ? `<div style="text-align: center; font-size:12px; color:#475569; padding:6px; background:#f8fafc; border-radius:4px;"><strong>Last Transaction Date:</strong> ${formatDate(tx.last_transaction)}</div>` : ''}
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Transaction History</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Reference No.</th>
                                        <th>Module</th>
                                        <th>Description</th>
                                        <th style="text-align: right;">Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${txHtml}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="footer">
                            Printed By: Staff User | Print Date: ${new Date().toLocaleString()} | System Generated Report
                        </div>
                        <script>
                            window.onload = function() {
                                window.print();
                                setTimeout(function() { window.close(); }, 800);
                            };
                        <\/script>
                    </body>
                    </html>
                `);
                printWin.document.close();
            }
        });
}

function printCustomerProfileFromModal() {
    if (currentViewingCustomerId) {
        printCustomerProfile(currentViewingCustomerId);
    }
}

// Reset filters
function resetCustomerFilters() {
    document.getElementById('custSearchInput').value = '';
    document.getElementById('custFilterType').value = '';
    document.getElementById('custFilterStatus').value = 'active';
    document.getElementById('custFilterDateFrom').value = '';
    document.getElementById('custFilterDateTo').value = '';
    loadCustomerList();
}

// Export redirect to server-side script
function exportCustomerData(format) {
    const search = document.getElementById('custSearchInput').value;
    const type = document.getElementById('custFilterType').value;
    const status = document.getElementById('custFilterStatus').value;
    const dateFrom = document.getElementById('custFilterDateFrom').value;
    const dateTo = document.getElementById('custFilterDateTo').value;
    
    const params = new URLSearchParams({
        format: format,
        search: search,
        type: type,
        status: status,
        date_from: dateFrom,
        date_to: dateTo
    });
    
    // For PDF, open the print-optimized page
    if (format === 'pdf') {
        window.open(`staff_customer_export.php?${params.toString()}&format=pdf`, '_blank');
        return;
    }
    
    // For Excel/CSV, use the export script
    window.open(`staff_customer_export.php?${params.toString()}`, '_blank');
}

// Formatting helpers
function formatNumber(num) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(num);
}
function formatDate(dateStr) {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}
function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modals on ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        closeTxDetailPopup();
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
