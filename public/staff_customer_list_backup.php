<?php
/**
 * Staff Customer Management Module
 * Complete customer CRUD with modals
 */

$page_id = 'customers';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

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
/* Modal Overlay */
.modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);display:none;align-items:center;justify-content:center;z-index:9999;}
.modal-overlay.active{display:flex;}
.modal-container{background:#fff;border-radius:12px;max-width:700px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
.modal-header{padding:20px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{margin:0;font-size:18px;font-weight:700;color:#002F70;}
.modal-close{background:none;border:none;font-size:24px;color:#6b7280;cursor:pointer;padding:0;width:30px;height:30px;display:flex;align-items:center;justify-content:center;}
.modal-close:hover{color:#002F70;}
.modal-body{padding:24px;}
.modal-footer{padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;background:#f9fafb;}

/* Form Styles */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
.form-grid.full{grid-template-columns:1fr;}
.form-group label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px;}
.form-group label .required{color:#dc2626;margin-left:2px;}
.form-group input, .form-group select, .form-group textarea{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;transition:0.2s;}
.form-group input:focus, .form-group select:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,0.1);}

/* Type Selector */
.type-selector{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin:16px 0;}
.type-option{border:2px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center;cursor:pointer;transition:0.2s;}
.type-option:hover{border-color:#002F70;background:#f8fafc;}
.type-option.selected{border-color:#002F70;background:#eff6ff;}
.type-option i{font-size:28px;color:#002F70;display:block;margin-bottom:8px;}
.type-option span{font-size:13px;font-weight:600;color:#374151;}

/* Summary Cards */
.summary-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.summary-card{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;display:flex;align-items:center;gap:16px;}
.summary-card-icon{width:50px;height:50px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:24px;}
.summary-card-icon.blue{background:#eff6ff;color:#1d4ed8;}
.summary-card-icon.green{background:#d1fae5;color:#059669;}
.summary-card-icon.yellow{background:#fef3c7;color:#d97706;}
.summary-card-icon.purple{background:#f5f3ff;color:#7c3aed;}
.summary-card-content h3{margin:0;font-size:32px;font-weight:700;color:#002F70;}
.summary-card-content p{margin:4px 0 0;font-size:13px;color:#6b7280;}

/* Filters styling */
.filters-bar{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;margin-bottom:20px;}
.filters-grid{display:grid;grid-template-columns:2fr 1.2fr 1.2fr 1.2fr 1.2fr 1.8fr;gap:12px;align-items:flex-end;}

/* Table */
.table-container{background:#fff;border-radius:10px;border:1px solid #e5e7eb;padding:20px;}
.customers-table{width:100%;border-collapse:collapse;}
.customers-table thead{background:#f8fafc;}
.customers-table th{padding:12px;text-align:left;font-size:12px;font-weight:700;color:#374151;border-bottom:2px solid #e5e7eb;}
.customers-table td{padding:12px;border-bottom:1px solid #f1f5f9;color:#374151;font-size:14px;}
.customers-table tbody tr:hover{background:#f8fafc;}

/* Badges */
.badge{display:inline-block;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;}
.badge-walkin{background:#e0e7ff;color:#3730a3;}
.badge-regular{background:#fef3c7;color:#92400e;}
.badge-fleet{background:#dbeafe;color:#1e40af;}
.badge-active{background:#d1fae5;color:#065f46;}
.badge-inactive{background:#fee2e2;color:#991b1b;}

/* Action Buttons */
.action-btns{display:flex;gap:6px;}
.btn-action{padding:6px 12px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;transition:0.2s;}
.btn-view{background:#eff6ff;color:#1d4ed8;}
.btn-view:hover{background:#dbeafe;}
.btn-edit{background:#fef3c7;color:#92400e;}
.btn-edit:hover{background:#fde68a;}
.btn-print{background:#f3f4f6;color:#374151;}
.btn-print:hover{background:#e5e7eb;}

/* Info Display in View Modal */
.info-section{margin-bottom:24px;}
.info-section h4{margin:0 0 12px;font-size:14px;font-weight:700;color:#002F70;border-bottom:2px solid #e5e7eb;padding-bottom:8px;}
.info-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;}
.info-row:last-child{border-bottom:none;}
.info-label{font-size:13px;font-weight:600;color:#6b7280;}
.info-value{font-size:14px;color:#1f2937;font-weight:500;}

/* Transaction Summary */
.tx-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin:16px 0;}
.tx-card{background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:8px;padding:14px;text-align:center;border:1px solid #e2e8f0;}
.tx-card .num{font-size:20px;font-weight:800;color:#002F70;}
.tx-card .lbl{font-size:10px;color:#64748b;margin-top:4px;text-transform:uppercase;font-weight:bold;}

/* Custom Export Buttons Outline to prevent layout breakage and color clash */
.btn-export-pdf {
    background: #fef2f2 !important;
    border: 1px solid #fca5a5 !important;
    color: #dc2626 !important;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-export-pdf:hover {
    background: #fee2e2 !important;
}

.btn-export-excel {
    background: #f0fdf4 !important;
    border: 1px solid #bbf7d0 !important;
    color: #16a34a !important;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-export-excel:hover {
    background: #dcfce7 !important;
}

.btn-export-csv {
    background: #f0f9ff !important;
    border: 1px solid #bae6fd !important;
    color: #002F70 !important;
    padding: 10px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-export-csv:hover {
    background: #e0f2fe !important;
}

/* Empty State */
.empty-state{text-align:center;padding:60px 20px;}
.empty-state i{font-size:48px;color:#d1d5db;margin-bottom:12px;}
.empty-state p{color:#9ca3af;margin:8px 0 0;}

/* Alert Messages */
.alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px;display:none;}
.alert.show{display:block;}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;}
.alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}

/* Loading */
.loading{text-align:center;padding:40px;color:#6b7280;}
.loading i{font-size:32px;color:#002F70;margin-bottom:12px;}

@media(max-width:992px){
    .filters-grid{grid-template-columns:1fr 1fr; gap:12px;}
}
@media(max-width:576px){
    .filters-grid{grid-template-columns:1fr;}
    .summary-cards{grid-template-columns:1fr;}
}
</style>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-top: 0; margin-top: 0;">
    <div>
        <h1 class="h1" style="margin: 0;"><i class="fas fa-users"></i> CUSTOMERS</h1>
        <div class="sub" style="margin-top: 4px;">VIEW AND MANAGE CUSTOMERS AT YOUR STATION</div>
    </div>
    <div class="header-actions" style="display: flex; gap: 8px; align-items: center;">
        <button class="btn btn-success" onclick="openCustomerAddModal()" style="background: #16a34a; color: white; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;"><i class="fas fa-plus"></i> Add Customer</button>
        <button class="btn-export-pdf" onclick="exportCustomerData('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
        <button class="btn-export-excel" onclick="exportCustomerData('excel')"><i class="fas fa-file-excel"></i> Excel</button>
        <button class="btn-export-csv" onclick="exportCustomerData('csv')"><i class="fas fa-file-csv"></i> CSV</button>
    </div>
</div>

<!-- Stats Cards -->
<div class="summary-cards">
    <div class="summary-card">
        <div class="summary-card-icon blue"><i class="fas fa-users"></i></div>
        <div class="summary-card-content">
            <h3 id="totalCustomersCount">—</h3>
            <p>👥 Total Customers</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon green"><i class="fas fa-user-plus"></i></div>
        <div class="summary-card-content">
            <h3 id="newCustomersCount">—</h3>
            <p>🆕 New Today</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon yellow"><i class="fas fa-star"></i></div>
        <div class="summary-card-content">
            <h3 id="regularCustomersCount">—</h3>
            <p>⭐ Regular Customers</p>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-card-icon purple"><i class="fas fa-building"></i></div>
        <div class="summary-card-content">
            <h3 id="fleetAccountsCount">—</h3>
            <p>🏢 Fleet Accounts</p>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="filters-bar">
    <div class="filters-grid">
        <div class="form-group" style="margin-bottom: 0;">
            <label>Search Customer</label>
            <input type="text" id="custSearchInput" placeholder="Search Customer ID, Name, or Contact Number...">
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Customer Type</label>
            <select id="custFilterType">
                <option value="">All Types</option>
                <option value="walk-in">Walk-in</option>
                <option value="regular">Regular</option>
                <option value="fleet">Fleet</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label>Status</label>
            <select id="custFilterStatus">
                <option value="">All Status</option>
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
        <div style="margin-bottom: 0; display: flex; gap: 8px; flex-direction: row !important; align-items: flex-end; width: 100%;">
            <button class="btn btn-primary" onclick="loadCustomerList()" style="flex: 1; height: 42px; padding: 0 16px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; background: #002F70; color: white; display: inline-flex; align-items: center; justify-content: center; gap: 6px;"><i class="fas fa-search"></i> Search</button>
            <button class="btn btn-secondary" onclick="resetCustomerFilters()" style="flex: 1; height: 42px; padding: 0 16px; border-radius: 8px; font-weight: 600; cursor: pointer; border: 1px solid #d1d5db; background: white; color: #374151; display: inline-flex; align-items: center; justify-content: center; gap: 6px;"><i class="fas fa-redo"></i> Reset</button>
        </div>
    </div>
</div>

<!-- Customers Table -->
<div class="table-container">
    <div id="tableContent">
        <div class="loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading customers...</p>
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
                <!-- Auto-generated ID Notice -->
                <div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#1e40af;">
                    <i class="fas fa-info-circle"></i> <strong>Customer ID</strong> will be auto-generated upon saving.
                </div>
                
                <!-- Name Fields -->
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
                        <input type="text" name="contact_number" id="addContact" placeholder="09XX-XXX-XXXX" required>
                    </div>
                </div>
                
                <!-- Address -->
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Address <span class="required">*</span></label>
                        <input type="text" name="address" id="addAddress" placeholder="Complete address" required>
                    </div>
                </div>
                
                <!-- Customer Type Selector -->
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">
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
                
                <!-- Government ID -->
                <div class="form-grid">
                    <div class="form-group">
                        <label>Government ID Type</label>
                        <select name="gov_id_type" id="addGovIdType">
                            <option value="">Select ID type...</option>
                            <option>PhilSys ID</option>
                            <option>Driver's License</option>
                            <option>Passport</option>
                            <option>Voter's ID</option>
                            <option>SSS ID</option>
                            <option>GSIS ID</option>
                            <option>PRC ID</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Upload Government ID</label>
                        <input type="file" name="gov_id_image" accept="image/*,.pdf">
                    </div>
                </div>
                
                <!-- CR Document -->
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Upload Certificate of Registration (CR)</label>
                        <input type="file" name="cr_document" accept="image/*,.pdf">
                    </div>
                </div>
                
                <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:12px;color:#92400e;margin-top:12px;">
                    <i class="fas fa-lock"></i> You can upload documents but cannot view or download them after saving.
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCustomerModal('addCustomerModal')"><i class="fas fa-times"></i> Cancel</button>
            <button class="btn btn-primary" onclick="submitCustomerAdd()" id="addSubmitBtn"><i class="fas fa-save"></i> Save Customer</button>
        </div>
    </div>
</div>

<!-- VIEW CUSTOMER MODAL -->
<div class="modal-overlay" id="viewCustomerModal">
    <div class="modal-container" style="max-width:800px;">
        <div class="modal-header">
            <h3><i class="fas fa-eye"></i> Customer Profile</h3>
            <button class="modal-close" onclick="closeCustomerModal('viewCustomerModal')">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading customer details...</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeCustomerModal('viewCustomerModal')"><i class="fas fa-times"></i> Close</button>
            <button class="btn btn-primary" onclick="printCustomerProfileFromModal()"><i class="fas fa-print"></i> Print Profile</button>
        </div>
    </div>
</div>

<!-- EDIT CUSTOMER MODAL -->
<div class="modal-overlay" id="editCustomerModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Customer</h3>
            <button class="modal-close" onclick="closeCustomerModal('editCustomerModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-success" id="editSuccess"></div>
            <div class="alert alert-error" id="editError"></div>
            
            <form id="editForm">
                <input type="hidden" name="customer_id" id="editCustomerId">
                
                <!-- Read-only Info -->
                <div style="background:#f0f9ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#1e40af;">
                    <div style="margin-bottom:6px;"><i class="fas fa-id-card"></i> <strong>Customer ID:</strong> <span id="editCustIdDisplay"></span></div>
                    <div><i class="fas fa-calendar"></i> <strong>Registered:</strong> <span id="editRegDateDisplay"></span></div>
                </div>
                
                <!-- Editable Name Fields -->
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
                
                <!-- Address -->
                <div class="form-grid full">
                    <div class="form-group">
                        <label>Address <span class="required">*</span></label>
                        <input type="text" name="address" id="editAddress" required>
                    </div>
                </div>
                
                <!-- Customer Type Selector -->
                <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:8px;">
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
            <button class="btn btn-secondary" onclick="closeCustomerModal('editCustomerModal')"><i class="fas fa-times"></i> Cancel</button>
            <button class="btn btn-primary" onclick="submitCustomerEdit()" id="editSubmitBtn"><i class="fas fa-save"></i> Update Customer</button>
        </div>
    </div>
</div>

<script>
const STATION_ID = <?= (int)$station_id ?>;
let currentCustomers = [];
let currentViewingCustomerId = null;

// Load customers on page load
document.addEventListener('DOMContentLoaded', () => {
    loadCustomerList();
});

// Load customers from API
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
        .then(res => {
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.success) {
                currentCustomers = data.customers || [];
                updateCustomerStats(data.stats || {});
                renderCustomerTable(currentCustomers);
            } else {
                showCustomerError('Failed to load customers: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => {
            console.error('Error loading customers:', err);
            showCustomerError('Error loading customers. Please try again. ' + err.message);
        });
}

// Update summary stats
function updateCustomerStats(stats) {
    document.getElementById('totalCustomersCount').textContent = formatNumber(stats.total || 0);
    document.getElementById('newCustomersCount').textContent = formatNumber(stats.new_today || 0);
    document.getElementById('regularCustomersCount').textContent = formatNumber(stats.regular || 0);
    document.getElementById('fleetAccountsCount').textContent = formatNumber(stats.fleet || 0);
}

// Render customers table
function renderCustomerTable(customers) {
    const container = document.getElementById('tableContent');
    
    if (!customers || customers.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <p>No customers found. Click "Add Customer" to get started.</p>
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
                        <button class="btn-action btn-view" onclick="viewCustomerDetail(${c.id})" title="View Profile">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button class="btn-action btn-edit" onclick="openCustomerEditModal(${c.id})" title="Edit Details">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action btn-print" onclick="printCustomerProfile(${c.id})" title="Print Profile">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

// Show error message
function showCustomerError(message) {
    const container = document.getElementById('tableContent');
    container.innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
            <p style="color:#dc2626;">${message}</p>
            <button class="btn btn-primary" onclick="loadCustomerList()" style="margin-top:16px;">
                <i class="fas fa-redo"></i> Try Again
            </button>
        </div>
    `;
}

// Modal functions
function openCustomerModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeCustomerModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    if (modalId === 'addCustomerModal') {
        document.getElementById('addForm').reset();
        selectCustomerType('add', 'walk-in');
        hideCustomerAlert('addSuccess');
        hideCustomerAlert('addError');
    } else if (modalId === 'editCustomerModal') {
        hideCustomerAlert('editSuccess');
        hideCustomerAlert('editError');
    }
}

// Customer type selector
function selectCustomerType(mode, value) {
    const selector = document.getElementById(`${mode}TypeSelector`);
    const hiddenInput = document.getElementById(`${mode}CustomerType`);
    
    selector.querySelectorAll('.type-option').forEach(opt => {
        if (opt.dataset.value === value) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });
    
    hiddenInput.value = value;
}

// Open add modal
function openCustomerAddModal() {
    document.getElementById('addForm').reset();
    selectCustomerType('add', 'walk-in');
    hideCustomerAlert('addSuccess');
    hideCustomerAlert('addError');
    openCustomerModal('addCustomerModal');
}

// Submit add customer
function submitCustomerAdd() {
    const form = document.getElementById('addForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'add');
    
    const btn = document.getElementById('addSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    fetch('staff_customer_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        return res.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Customer';
        
        if (data.success) {
            showCustomerAlert('addSuccess', data.message || 'Customer added successfully!');
            setTimeout(() => {
                closeCustomerModal('addCustomerModal');
                loadCustomerList();
            }, 1500);
        } else {
            showCustomerAlert('addError', data.error || 'Failed to add customer');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Customer';
        showCustomerAlert('addError', 'Error adding customer: ' + err.message);
    });
}

// View customer
function viewCustomerDetail(id) {
    currentViewingCustomerId = id;
    openCustomerModal('viewCustomerModal');
    document.getElementById('viewModalBody').innerHTML = `
        <div class="loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading customer details...</p>
        </div>
    `;
    
    fetch(`staff_customer_operations.php?action=view&id=${id}`)
        .then(res => {
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.success) {
                renderCustomerViewModal(data.customer, data.transactions || {}, data.recent_transactions || []);
            } else {
                document.getElementById('viewModalBody').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
                        <p style="color:#dc2626;">${data.error || 'Failed to load customer'}</p>
                    </div>
                `;
            }
        })
        .catch(err => {
            document.getElementById('viewModalBody').innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-exclamation-circle" style="color:#dc2626;"></i>
                    <p style="color:#dc2626;">Error loading customer: ${err.message}</p>
                </div>
            `;
        });
}

// Render view modal content
function renderCustomerViewModal(customer, transactions, recent) {
    const fullName = [customer.first_name, customer.middle_name, customer.last_name].filter(Boolean).join(' ');
    const typeClass = customer.customer_type === 'walk-in' ? 'walkin' : (customer.customer_type === 'regular' ? 'regular' : 'fleet');
    const statusClass = customer.status === 'active' ? 'active' : 'inactive';
    const typeLabel = customer.customer_type === 'fleet' ? 'Fleet' : capitalize(customer.customer_type);
    
    let txRows = '';
    if (recent && recent.length > 0) {
        recent.forEach(r => {
            txRows += `
                <tr>
                    <td>${formatDateTime(r.txn_date)}</td>
                    <td><strong>${escapeHtml(r.reference_no)}</strong></td>
                    <td><span class="badge badge-${r.module.toLowerCase()}">${escapeHtml(r.module)}</span></td>
                    <td><strong>₱${formatNumber(r.amount)}</strong></td>
                </tr>
            `;
        });
    } else {
        txRows = `<tr><td colspan="4" style="text-align:center;color:#6b7280;padding:16px;">No transactions found</td></tr>`;
    }

    let html = `
        <div style="background:linear-gradient(135deg,#002F70,#0056b3);color:#fff;padding:20px;border-radius:8px;margin-bottom:20px;text-align:center;">
            <div style="font-size:48px;margin-bottom:10px;">
                <i class="fas fa-user-circle"></i>
            </div>
            <h2 style="margin:0 0 8px;font-size:24px;color:white;">${escapeHtml(fullName)}</h2>
            <p style="margin:0;font-size:14px;opacity:0.9;">${escapeHtml(customer.customer_id || 'N/A')}</p>
            <div style="display:flex;gap:8px;justify-content:center;margin-top:12px;">
                <span class="badge badge-${typeClass}">${typeLabel}</span>
                <span class="badge badge-${statusClass}">${capitalize(customer.status)}</span>
            </div>
        </div>
        
        <div class="info-section">
            <h4><i class="fas fa-address-card"></i> Contact Information</h4>
            <div class="info-row">
                <span class="info-label">Contact Number</span>
                <span class="info-value">${escapeHtml(customer.contact_number || 'N/A')}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Address</span>
                <span class="info-value">${escapeHtml(customer.address || 'N/A')}</span>
            </div>
            ${customer.gov_id_type ? `
            <div class="info-row">
                <span class="info-label">Government ID Type</span>
                <span class="info-value">${escapeHtml(customer.gov_id_type)}</span>
            </div>
            ` : ''}
        </div>
        
        <div class="info-section">
            <h4><i class="fas fa-info-circle"></i> Registration Details</h4>
            <div class="info-row">
                <span class="info-label">Registered On</span>
                <span class="info-value">${customer.registered_at ? formatDateTime(customer.registered_at) : 'N/A'}</span>
            </div>
            ${customer.registered_by_name ? `
            <div class="info-row">
                <span class="info-label">Registered By</span>
                <span class="info-value">${escapeHtml(customer.registered_by_name)}</span>
            </div>
            ` : ''}
        </div>
        
        <div class="info-section">
            <h4><i class="fas fa-chart-bar"></i> Transaction Summary</h4>
            <div class="tx-summary">
                <div class="tx-card">
                    <div class="num">${transactions.fuel_count || 0}</div>
                    <div class="lbl">⛽ Fuel Trans</div>
                </div>
                <div class="tx-card">
                    <div class="num">${transactions.merch_count || 0}</div>
                    <div class="lbl">📦 Merch Trans</div>
                </div>
                <div class="tx-card">
                    <div class="num">${transactions.service_count || 0}</div>
                    <div class="lbl">🔧 Service Trans</div>
                </div>
                <div class="tx-card">
                    <div class="num">${transactions.service_count || 0}</div>
                    <div class="lbl">📋 Job Orders</div>
                </div>
                <div class="tx-card" style="grid-column: span 2; background: #ecfdf5;">
                    <div class="num" style="color: #059669;">₱${formatNumber(transactions.total_amount || 0)}</div>
                    <div class="lbl" style="color: #059669;">💰 Total Spent</div>
                </div>
            </div>
        </div>

        <div class="info-section">
            <h4><i class="fas fa-history"></i> Recent Transactions (Latest 10)</h4>
            <table class="customers-table" style="margin-top: 8px;">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Reference Number</th>
                        <th>Module</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    ${txRows}
                </tbody>
            </table>
        </div>
    `;
    
    document.getElementById('viewModalBody').innerHTML = html;
}

// Open edit modal
function openCustomerEditModal(id) {
    const customer = currentCustomers.find(c => c.id === id);
    if (!customer) {
        alert('Customer not found');
        return;
    }
    
    document.getElementById('editCustomerId').value = customer.id;
    document.getElementById('editCustIdDisplay').textContent = customer.customer_id || 'N/A';
    document.getElementById('editRegDateDisplay').textContent = customer.registered_at ? formatDate(customer.registered_at) : 'N/A';
    document.getElementById('editFirstName').value = customer.first_name || '';
    document.getElementById('editMiddleName').value = customer.middle_name || '';
    document.getElementById('editLastName').value = customer.last_name || '';
    document.getElementById('editContact').value = customer.contact_number || '';
    document.getElementById('editAddress').value = customer.address || '';
    
    selectCustomerType('edit', customer.customer_type || 'walk-in');
    
    hideCustomerAlert('editSuccess');
    hideCustomerAlert('editError');
    openCustomerModal('editCustomerModal');
}

// Submit edit customer
function submitCustomerEdit() {
    const form = document.getElementById('editForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'update');
    
    const btn = document.getElementById('editSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
    
    fetch('staff_customer_operations.php', {
        method: 'POST',
        body: formData
    })
    .then(res => {
        if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
        return res.json();
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Customer';
        
        if (data.success) {
            showCustomerAlert('editSuccess', data.message || 'Customer updated successfully!');
            setTimeout(() => {
                closeCustomerModal('editCustomerModal');
                loadCustomerList();
            }, 1500);
        } else {
            showCustomerAlert('editError', data.error || 'Failed to update customer');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Customer';
        showCustomerAlert('editError', 'Error updating customer: ' + err.message);
    });
}

// Print customer from action row
function printCustomerProfile(id) {
    fetch(`staff_customer_operations.php?action=view&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const c = data.customer;
                const tx = data.transactions;
                const rec = data.recent_transactions || [];
                
                const printWin = window.open('', '_blank');
                const fullName = [c.first_name, c.middle_name, c.last_name].filter(Boolean).join(' ');
                
                let txHtml = '';
                if (rec.length > 0) {
                    rec.forEach(r => {
                        txHtml += `
                            <tr>
                                <td>${formatDateTime(r.txn_date)}</td>
                                <td><strong>${escapeHtml(r.reference_no)}</strong></td>
                                <td>${escapeHtml(r.module)}</td>
                                <td style="text-align: right;">₱${formatNumber(r.amount)}</td>
                            </tr>
                        `;
                    });
                } else {
                    txHtml = '<tr><td colspan="4" style="text-align: center; color: #888; padding: 10px;">No transactions recorded</td></tr>';
                }
                
                printWin.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Print Customer Profile - ${c.customer_id}</title>
                        <style>
                            body { font-family: Arial, sans-serif; color: #333; margin: 20px; font-size: 13px; line-height: 1.4; }
                            .header { display: flex; justify-content: space-between; border-bottom: 2px solid #002F70; padding-bottom: 10px; margin-bottom: 20px; }
                            .header h1 { margin: 0; color: #002F70; font-size: 20px; }
                            .station-info { text-align: right; font-size: 11px; color: #666; }
                            .section { margin-bottom: 20px; }
                            .section-title { font-size: 14px; font-weight: bold; color: #002F70; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 10px; }
                            .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; }
                            .info-item { display: flex; justify-content: space-between; border-bottom: 1px solid #f1f1f1; padding: 4px 0; }
                            .info-label { font-weight: bold; color: #666; }
                            .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
                            .stats-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center; }
                            .stats-card .num { font-size: 18px; font-weight: bold; color: #002F70; }
                            .stats-card .lbl { font-size: 10px; color: #666; text-transform: uppercase; margin-top: 2px; }
                            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                            th { background: #002F70; color: white; padding: 8px; font-size: 11px; text-align: left; }
                            td { padding: 8px; border-bottom: 1px solid #ddd; font-size: 11px; }
                            tr:nth-child(even) { background: #f9f9f9; }
                            .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
                        </style>
                    </head>
                    <body>
                        <div class="header">
                            <div>
                                <h1>PETRON CUSTOMER PROFILE</h1>
                                <div><strong>ID:</strong> ${escapeHtml(c.customer_id)}</div>
                            </div>
                            <div class="station-info">
                                <strong>Petron Station</strong><br>
                                Station ID: ${STATION_ID}
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Customer Information</div>
                            <div class="info-grid">
                                <div class="info-item"><span class="info-label">Full Name:</span><span>${escapeHtml(fullName)}</span></div>
                                <div class="info-item"><span class="info-label">Customer Type:</span><span>${capitalize(c.customer_type)}</span></div>
                                <div class="info-item"><span class="info-label">Contact Number:</span><span>${escapeHtml(c.contact_number)}</span></div>
                                <div class="info-item"><span class="info-label">Status:</span><span>${capitalize(c.status)}</span></div>
                                <div class="info-item" style="grid-column: span 2;"><span class="info-label">Address:</span><span>${escapeHtml(c.address)}</span></div>
                                <div class="info-item"><span class="info-label">Registered On:</span><span>${formatDateTime(c.registered_at)}</span></div>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Transaction Overview</div>
                            <div class="stats-row">
                                <div class="stats-card"><div class="num">${tx.fuel_count}</div><div class="lbl">Fuel Trans.</div></div>
                                <div class="stats-card"><div class="num">${tx.merch_count}</div><div class="lbl">Merchandise</div></div>
                                <div class="stats-card"><div class="num">${tx.service_count}</div><div class="lbl">Services</div></div>
                                <div class="stats-card"><div class="num">₱${formatNumber(tx.total_amount)}</div><div class="lbl">Total Spent</div></div>
                            </div>
                        </div>
                        
                        <div class="section">
                            <div class="section-title">Recent Transactions (Latest 10)</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Reference No.</th>
                                        <th>Module</th>
                                        <th style="text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${txHtml}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="footer">
                            Printed on: ${new Date().toLocaleString()} | Petron Management System
                        </div>
                        <script>
                            window.onload = function() {
                                window.print();
                                setTimeout(function() { window.close(); }, 500);
                            };
                        <\/script>
                    </body>
                    </html>
                `);
                printWin.document.close();
            }
        })
        .catch(err => {
            console.error('Error printing customer profile:', err);
            alert('Failed to generate print layout');
        });
}

// Print profile from view modal
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

// Export data
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
    
    window.location.href = `staff_customer_export.php?${params.toString()}`;
}

// Alert helpers
function showCustomerAlert(id, message) {
    const alert = document.getElementById(id);
    alert.textContent = message;
    alert.classList.add('show');
}

function hideCustomerAlert(id) {
    const alert = document.getElementById(id);
    alert.classList.remove('show');
}

// Formatting utilities
function formatNumber(num) {
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(num);
}

function formatDate(dateStr) {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function capitalize(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}

// Close modals on ESC key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
