<?php
require_once '../partials/header.php';
require_login();

$user = current_user();
$role = strtolower(trim($user['role'] ?? 'staff'));

if (!in_array($role, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}
?>


<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; width: 100%;">
                <h2 style="margin: 0; text-transform: uppercase;"><i class="fas fa-users-cog me-2"></i>Staff Oversight – Admin View</h2>
                <div style="display: flex; gap: 10px; margin-left: auto; margin-top: 10px;">
                    <button class="btn btn-sm" onclick="exportStaffList()" style="background:linear-gradient(135deg,#28a745 0%,#20c997 100%);border:2px solid #28a745;color:#fff;font-weight:600;padding:6px 12px;border-radius:5px;">
                        <i class="fas fa-file-export"></i> Export Staff List
                    </button>
                    <button class="btn btn-primary" onclick="loadStaffOversight()" style="font-weight:600;padding:6px 12px;border-radius:5px;">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                </div>
            </div>



            <div class="card">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Staff Activity & Oversight</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle" id="staffOversightTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Account ID / Name</th>
                                    <th>Assigned Role</th>
                                    <th>Station / Branch</th>
                                    <th>Account Status</th>
                                    <th>Recent Activity</th>
                                    <th>Activity Summary</th>
                                    <th>Remarks</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="staffOversightBody">
                                <!-- Data will be loaded via AJAX -->
                            </tbody>
                        </table>
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
                        <option value="suspended">Suspended</option>
                    </select>
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
document.addEventListener('DOMContentLoaded', function() {
    loadStaffOversight();
});

function loadStaffOversight() {
    fetch('../backend/api/admin_staff_oversight_api.php?action=fetch_staff_oversight')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const tbody = document.getElementById('staffOversightBody');
                tbody.innerHTML = '';
                
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No staff records found.</td></tr>';
                    return;
                }
                
                data.data.forEach(staff => {

                    const statusBadgeClass = {
                        'active': 'bg-success',
                        'inactive': 'bg-secondary',
                        'suspended': 'bg-danger'
                    }[staff.account_status] || 'bg-secondary';
                    
                    const lastLogin = staff.last_login ? new Date(staff.last_login).toLocaleString() : 'Never';
                    const isManager = staff.assigned_role.toLowerCase() === 'manager';
                    const roleLabel = isManager ? 'Manager' : 'Staff';
                    
                    const lastTxnDate = isManager ? staff.last_validated_transaction : staff.last_encoded_transaction;
                    const lastTxnLabel = isManager ? 'Validated' : 'Encoded';
                    const lastTxn = lastTxnDate ? new Date(lastTxnDate).toLocaleString() : 'None';
                    
                    const reqCount = isManager ? staff.total_requests_validated : staff.total_requests_encoded;
                    const delCount = isManager ? staff.total_deliveries_validated : staff.total_deliveries_encoded;
                    const reqLabel = isManager ? 'Requests Validated' : 'Requests Encoded';
                    const delLabel = isManager ? 'Deliveries Validated' : 'Deliveries Encoded';
                    
                    const remarks = staff.remarks || '<span class="text-muted fst-italic">No remarks</span>';
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
                            <div class="small"><strong>Login:</strong> ${lastLogin}</div>
                            <div class="small"><strong>${lastTxnLabel}:</strong> ${lastTxn}</div>
                        </td>
                        <td>
                            <span class="badge bg-info me-1" title="${reqLabel}">${reqCount} Requests</span>
                            <span class="badge bg-primary" title="${delLabel}">${delCount} Deliveries</span>
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
                                <button class="btn btn-sm" onclick="openEditModal('${staffJson}')" title="Edit Account" style="background:linear-gradient(135deg,#003d7a 0%,#0056b3 100%);border:2px solid #003d7a;color:#fff;padding:4px 10px;font-size:12px;border-radius:5px;cursor:pointer;font-weight:600; text-decoration:none; text-align:center; width:100px;">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                ${staff.account_status === 'active' ? 
                                    `<button class="btn btn-sm" onclick="openDeactivateModal(${staff.staff_id})" title="Deactivate" style="background:linear-gradient(135deg,#dc3545 0%,#c82333 100%);border:2px solid #dc3545;color:#fff;padding:4px 10px;font-size:12px;border-radius:5px;cursor:pointer;font-weight:600; width:100px;">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>` : 
                                    `<button class="btn btn-sm" onclick="toggleStatus(${staff.staff_id}, 'active')" title="Activate" style="background:linear-gradient(135deg,#003d7a 0%,#0056b3 100%);border:2px solid #003d7a;color:#fff;padding:4px 10px;font-size:12px;border-radius:5px;cursor:pointer;font-weight:600; width:100px;">
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
            alert('An error occurred while fetching staff data.');
        });
}

function toggleStatus(staffId, newStatus) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('staff_id', staffId);
    formData.append('status', newStatus);
    
    fetch('../backend/api/admin_staff_oversight_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadStaffOversight();
        } else {
            alert('Failed to update status: ' + data.error);
        }
    })
    .catch(err => {
        console.error(err);
        alert('Network error while updating status.');
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
    .then(response => response.json())
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
        alert('Network error while saving user.');
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
    .then(response => response.json())
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
        alert('Network error while deactivating user.');
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
    .then(response => response.json())
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
        alert('Network error while saving remarks.');
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
