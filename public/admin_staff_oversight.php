<?php
// ── Auth & role gate MUST run before any output ──────────────────────────────
$page_id = 'staff_oversight_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$role = role_key($user['role'] ?? ''); // use canonical role_key(), not raw strtolower

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

// Header included AFTER auth is confirmed
require_once __DIR__ . '/../partials/header.php';
?>

<style>
    .action-btn { font-size:12px; padding:5px 8px; border:none; border-radius:4px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all .15s; font-weight:600; text-decoration:none; justify-content:center; width:100px;}
    .action-btn:hover { filter:brightness(.9); transform:translateY(-1px); }
    .btn-view    { background:#28a745; color:#fff; }
    .btn-edit    { background:#002F70; color:#fff; }
    .btn-reset   { background:#ffc107; color:#333; }
    .btn-danger  { background:#dc3545; color:#fff; }
    .btn-success { background:#28a745; color:#fff; }
    
    /* Modal scroll fix to prevent bottom from being covered */
    .modal { align-items: flex-start !important; overflow-y: auto !important; padding: 20px !important; }
    .modal-content { margin: 30px auto !important; max-height: calc(100vh - 60px) !important; display: flex !important; flex-direction: column !important; }
    .modal-body { overflow-y: auto !important; }
    
    /* Make table header sticky */
    .table-responsive thead th {
        position: sticky;
        top: 0;
        z-index: 1;
        background-color: #f8f9fa;
    }
</style>


<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; width: 100%;">
                <h2 style="margin: 0; text-transform: uppercase;"><i class="fas fa-users-cog me-2"></i>Staff Oversight – Admin View</h2>
            </div>

            <!-- Tab Content -->
            <div class="tab-content" id="shiftTabContent">
                <!-- Shift 1 Content -->
                <div class="tab-pane fade show active" id="shift1" role="tabpanel">
                    <div class="card">
                        <div class="card-header bg-white">
                            <h5 class="mb-0">Staff Activity & Oversight</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" style="max-height: calc(100vh - 350px); overflow-y: auto;">
                                <table class="table table-hover table-striped align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Account ID / Name</th>
                                            <th>Assigned Role</th>
                                            <th>Station / Branch</th>
                                            <th>Account Status</th>
                                            <th>Clock-in/out Logs</th>
                                            <th>Activity Summary</th>
                                            <th>Recent Actions</th>
                                            <th>Performance Metrics</th>
                                            <th>Remarks</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="shift1StaffBody">
                                        <!-- Data will be loaded via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shift 2 Content -->
                <div class="tab-pane fade" id="shift2" role="tabpanel">
                    <div class="card">
                        <div class="card-header bg-white">
                            <h5 class="mb-0">Staff Activity & Oversight</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive" style="max-height: calc(100vh - 350px); overflow-y: auto;">
                                <table class="table table-hover table-striped align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Account ID / Name</th>
                                            <th>Assigned Role</th>
                                            <th>Station / Branch</th>
                                            <th>Account Status</th>
                                            <th>Clock-in/out Logs</th>
                                            <th>Activity Summary</th>
                                            <th>Recent Actions</th>
                                            <th>Performance Metrics</th>
                                            <th>Remarks</th>
                                            <th class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="shift2StaffBody">
                                        <!-- Data will be loaded via AJAX -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Remark Modal -->
<div class="modal" id="remarkModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Edit Remarks</h3>
            <button class="modal-close" onclick="closeModal('remarkModal')">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="remarkStaffId">
            <div class="form-group mb-3">
                <label class="lbl">Remarks / Notes</label>
                <textarea class="inp full" id="staffRemarks" rows="3" placeholder="e.g., Flagged for review..."></textarea>
            </div>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:15px; border-top:1px solid #ddd;">
            <button type="button" class="btn ghost" onclick="closeModal('remarkModal')">Cancel</button>
            <button type="button" class="btn primary" onclick="saveRemark()">Save changes</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title">Edit Account</h3>
            <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <form id="editUserForm">
                <input type="hidden" id="editUserId" name="staff_id">
                <input type="hidden" name="action" value="edit_user">
                
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group mb-3">
                        <label class="lbl">Name</label>
                        <input type="text" class="inp full" id="editUserName" name="name" required>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="lbl">Email</label>
                        <input type="email" class="inp full" id="editUserEmail" name="email" required>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="lbl">Role</label>
                        <select class="inp full" id="editUserRole" name="role" required>
                            <option value="staff">Staff</option>
                            <option value="manager">Manager</option>
                        </select>
                    </div>
                    
                    <div class="form-group mb-3">
                        <label class="lbl">Station Assignment</label>
                        <input type="text" class="inp full bg-light" id="editUserStation" readonly>
                    </div>
                </div>
                
                <div class="form-group mb-3" style="margin-top: 15px;">
                    <label class="lbl">Account Status</label>
                    <select class="inp full" id="editUserStatus" name="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <small class="text-muted">Note: "Suspended" status will be available after database update.</small>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:15px; border-top:1px solid #ddd;">
            <button type="button" class="btn ghost" onclick="closeModal('editUserModal')">Cancel</button>
            <button type="button" class="btn primary" onclick="saveEditUser()">Save changes</button>
        </div>
    </div>
</div>

<!-- Deactivate User Modal -->
<div class="modal" id="deactivateUserModal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color:#dc3545;"><i class="fas fa-exclamation-triangle me-2"></i>Deactivate Account</h3>
            <button class="modal-close" onclick="closeModal('deactivateUserModal')">&times;</button>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate this account?</p>
            <input type="hidden" id="deactivateUserId">
        </div>
        <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px; padding:15px; border-top:1px solid #ddd;">
            <button type="button" class="btn ghost" onclick="closeModal('deactivateUserModal')">Cancel</button>
            <button type="button" class="btn warning" style="background:#dc3545; color:#fff;" onclick="confirmDeactivate()">Confirm</button>
        </div>
    </div>
</div>

<script>
// ── HTML Escaping Function (XSS Protection) ──────────────────────────────────
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', function() {
    loadStaffOversight();
});

function loadStaffOversight() {
    // Load both shifts
    loadShiftData(1);
    loadShiftData(2);
}

function loadShiftData(shiftNumber) {
    const targetBody = shiftNumber === 1 ? 'shift1StaffBody' : 'shift2StaffBody';
    
    fetch(`../backend/api/admin_staff_oversight_api.php?action=fetch_staff_oversight&shift=${shiftNumber}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById(targetBody);
                tbody.innerHTML = '';
                
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4">No staff records found for this shift.</td></tr>';
                    return;
                }
                
                data.data.forEach(staff => {

                    const statusBadgeClass = {
                        'active': 'bg-success',
                        'inactive': 'bg-secondary',
                        'suspended': 'bg-danger'
                    }[staff.account_status] || 'bg-secondary';
                    
                    const isManager = staff.assigned_role.toLowerCase() === 'manager';
                    const roleLabel = isManager ? 'Manager' : 'Staff';
                    
                    // Clock-in/out logs (today's shift)
                    const clockInTime = staff.clock_in_time ? new Date(staff.clock_in_time).toLocaleTimeString() : 'Not clocked in';
                    const clockOutTime = staff.clock_out_time ? new Date(staff.clock_out_time).toLocaleTimeString() : (staff.clock_in_time ? 'Still active' : '-');
                    const duration = staff.shift_duration ? staff.shift_duration : '-';
                    
                    // Recent actions (encodes, validations, exports)
                    const lastTxnDate = isManager ? staff.last_validated_transaction : staff.last_encoded_transaction;
                    const lastTxnLabel = isManager ? 'Validated' : 'Encoded';
                    const lastTxn = lastTxnDate ? new Date(lastTxnDate).toLocaleString() : 'None';
                    
                    // Activity summary
                    const reqCount = staff.shift_requests_count || 0;
                    const delCount = staff.shift_deliveries_count || 0;
                    const jobCount = staff.shift_jobs_count || 0;
                    
                    // Performance metrics
                    const salesTotal = staff.shift_sales_total ? '₱' + parseFloat(staff.shift_sales_total).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '₱0.00';
                    const serviceIncome = staff.shift_service_income ? '₱' + parseFloat(staff.shift_service_income).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '₱0.00';
                    
                    // XSS Protection: Escape HTML in remarks before displaying
                    const remarks = staff.remarks 
                        ? escapeHtml(staff.remarks)
                        : '<span class="text-muted fst-italic">No remarks</span>';
                    const staffJson = encodeURIComponent(JSON.stringify(staff)).replace(/'/g, "%27");
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>
                            <div class="fw-bold text-dark" style="font-size: 15px;">${staff.name}</div>
                            <div class="text-muted" style="font-size: 13.5px;">ID: ${staff.emp_id || staff.staff_id}</div>
                        </td>
                        <td>${roleLabel}</td>
                        <td>${staff.station_name || 'N/A'}</td>
                        <td>
                            <span class="badge ${statusBadgeClass} px-3 py-2">${staff.account_status.toUpperCase()}</span>
                        </td>
                        <td>
                            <div class="small"><strong>In:</strong> ${clockInTime}</div>
                            <div class="small"><strong>Out:</strong> ${clockOutTime}</div>
                            <div class="small text-muted"><strong>Duration:</strong> ${duration}</div>
                        </td>
                        <td>
                            <span class="badge bg-info me-1" title="Requests">${reqCount} Requests</span>
                            <span class="badge bg-primary me-1" title="Deliveries">${delCount} Deliveries</span>
                            <span class="badge bg-warning" title="Job Orders">${jobCount} Jobs</span>
                        </td>
                        <td>
                            <div class="small"><strong>${lastTxnLabel}:</strong> ${lastTxn}</div>
                        </td>
                        <td>
                            <div class="small"><strong>Sales:</strong> ${salesTotal}</div>
                            <div class="small"><strong>Service:</strong> ${serviceIncome}</div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <span class="me-2 remarks-text">${remarks}</span>
                                <button class="btn btn-sm btn-link text-primary p-0" onclick="openRemarkModal(${staff.staff_id}, \`${staff.remarks || ''}\`)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                        </td>
                        <td class="text-end">
                            <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-end;">
                                <button class="action-btn btn-edit" onclick="openEditModal('${staffJson}')" title="Edit Account">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                ${staff.account_status === 'active' ? 
                                    `<button class="action-btn btn-danger" onclick="openDeactivateModal(${staff.staff_id})" title="Deactivate">
                                        <i class="fas fa-times"></i> Deactivate
                                    </button>` : 
                                    `<button class="action-btn btn-success" onclick="toggleStatus(${staff.staff_id}, 'active')" title="Activate">
                                        <i class="fas fa-check"></i> Activate
                                    </button>`
                                }
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                alert('Error loading staff oversight data: ' + data.error);
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            const tbody = document.getElementById(targetBody);
            tbody.innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4">Error loading data: ' + err.message + '</td></tr>';
        });
}

function toggleStatus(staffId, newStatus) {
    // Get the button that triggered this action
    const btn = event.target.closest('button');
    if (!btn) return;
    
    // Disable button and show loading state
    btn.disabled = true;
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ...';
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('staff_id', staffId);
    formData.append('status', newStatus);
    
    fetch('../backend/api/admin_staff_oversight_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            loadStaffOversight();
        } else {
            alert('Failed to update status: ' + data.error);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error while updating status: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
}

function openModal(id) {
    document.getElementById(id).style.display = 'flex';
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openEditModal(staffJson) {
    const staff = JSON.parse(decodeURIComponent(staffJson));
    document.getElementById('editUserId').value = staff.staff_id;
    document.getElementById('editUserName').value = staff.name;
    document.getElementById('editUserEmail').value = staff.email || '';
    document.getElementById('editUserRole').value = staff.assigned_role.toLowerCase();
    document.getElementById('editUserStation').value = staff.station_name || 'N/A';
    document.getElementById('editUserStatus').value = staff.account_status.toLowerCase();
    
    openModal('editUserModal');
}

function saveEditUser() {
    const form = document.getElementById('editUserForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const formData = new FormData(form);
    
    fetch('../backend/api/admin_staff_oversight_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            closeModal('editUserModal');
            loadStaffOversight();
        } else {
            alert('Failed to update user: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error while saving user: ' + err.message);
    });
}

function openDeactivateModal(staffId) {
    document.getElementById('deactivateUserId').value = staffId;
    openModal('deactivateUserModal');
}

function confirmDeactivate() {
    const staffId = document.getElementById('deactivateUserId').value;
    
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('staff_id', staffId);
    formData.append('status', 'inactive');
    
    fetch('../backend/api/admin_staff_oversight_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            closeModal('deactivateUserModal');
            loadStaffOversight();
        } else {
            alert('Failed to deactivate user: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error while deactivating user: ' + err.message);
    });
}

function openRemarkModal(staffId, currentRemarks) {
    document.getElementById('remarkStaffId').value = staffId;
    document.getElementById('staffRemarks').value = currentRemarks;
    openModal('remarkModal');
}

function saveRemark() {
    const staffId = document.getElementById('remarkStaffId').value;
    const remarks = document.getElementById('staffRemarks').value;
    
    const formData = new FormData();
    formData.append('action', 'update_remark');
    formData.append('staff_id', staffId);
    formData.append('remarks', remarks);
    
    fetch('../backend/api/admin_staff_oversight_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            closeModal('remarkModal');
            loadStaffOversight();
        } else {
            alert('Failed to update remarks: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error while saving remarks: ' + err.message);
    });
}

function exportStaffList() {
    let table = document.getElementById("staffOversightTable");
    let cloneTable = table.cloneNode(true);
    
    // Remove the last column (Actions) from header and body rows
    let rows = cloneTable.rows;
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].cells.length > 0) {
            rows[i].deleteCell(-1);
        }
    }

    let uri = 'data:application/vnd.ms-excel;base64,';
    let template = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40"><head><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>{worksheet}</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--><meta charset="UTF-8"></head><body><table>{table}</table></body></html>';
    let base64 = function(s) { return window.btoa(unescape(encodeURIComponent(s))) };
    let format = function(s, c) { return s.replace(/{(\w+)}/g, function(m, p) { return c[p]; }) };

    let ctx = { worksheet: 'Staff Activity', table: cloneTable.innerHTML };
    
    let link = document.createElement("a");
    link.download = "staff_activity_export_" + new Date().toISOString().slice(0,10) + ".xls";
    link.href = uri + base64(format(template, ctx));
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
