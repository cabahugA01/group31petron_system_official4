<?php
// ── Auth & role gate MUST run before any output ──────────────────────────────
$page_id = 'staff_oversight_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$user = current_user();
$role = role_key($user['role'] ?? '');

if (!in_array($role, ['admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin privileges required.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();
if ((int)$station_id <= 0 && $role === 'admin') {
    render_no_station_page('admin_dashboard.php');
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
    /* ── Action Buttons ── */
    .action-btn {
        font-size: 13px;
        padding: 6px 12px;
        border-radius: 4px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all .2s;
        font-weight: 600;
        text-decoration: none;
        justify-content: center;
        width: 110px;
        background: white !important;
        border: 1px solid transparent;
    }
    .action-btn:hover { filter: none; transform: none; }
    .btn-edit    { color: #00264D !important; border-color: #00264D !important; }
    .btn-edit:hover { background: #00264D !important; color: #fff !important; }
    .btn-danger  { color: #dc2626 !important; border-color: #dc2626 !important; }
    .btn-danger:hover { background: #dc2626 !important; color: #fff !important; }
    .btn-success { color: #16a34a !important; border-color: #16a34a !important; }
    .btn-success:hover { background: #16a34a !important; color: #fff !important; }

    /* ── Shift Tab Navigation ── */
    .shift-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }
    .shift-tab-btn {
        font-size: 13px;
        padding: 8px 20px;
        border-radius: 4px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all .2s;
        font-weight: 600;
        text-decoration: none;
        justify-content: center;
        background: #ffffff !important;
        color: #00264D !important;
        border: 1px solid #00264D !important;
    }
    .shift-tab-btn:hover {
        background: #00264D !important;
        color: #ffffff !important;
    }
    .shift-tab-btn.active {
        background: #00264D !important;
        color: #ffffff !important;
        border-color: #00264D !important;
    }
    .shift-panel { display: none; }
    .shift-panel.active { display: block; }

    /* ── Modal centering & scroll fix ── */
    .modal { align-items: center !important; justify-content: center !important; overflow-y: auto !important; padding: 20px !important; z-index: 99999 !important; }
    .modal-content { margin: auto !important; max-height: calc(100vh - 40px) !important; display: flex !important; flex-direction: column !important; overflow: hidden !important; }
    .modal-body { overflow-y: auto !important; }

    /* ── Sticky table headers ── */
    .staff-table-wrap { overflow-x: auto; }
    .staff-table-wrap thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #002F70;
        color: #fff;
        white-space: nowrap;
    }

    /* ── Loading state ── */
    .loading-row td { text-align: center; padding: 32px; color: #64748b; }
</style>

<div class="page-head" style="padding-top:16px;">
    <div>
        <h1 class="h1">STAFF OVERSIGHT – ADMIN VIEW</h1>
    </div>
</div>

<div class="card" style="padding: 20px;">
    <!-- Shift Tab Buttons -->
    <div class="shift-tabs">
        <button class="shift-tab-btn active" id="tabBtn1" onclick="switchShift(1)">
            <i class="fas fa-sun"></i> Shift 1 (6:00 AM – 2:00 PM)
        </button>
        <button class="shift-tab-btn" id="tabBtn2" onclick="switchShift(2)">
            <i class="fas fa-moon"></i> Shift 2 (2:00 PM – 12:00 AM)
        </button>
    </div>

    <!-- Shift 1 Panel -->
    <div class="shift-panel active" id="panel1">
        <div class="staff-table-wrap">
            <table class="table table-hover table-striped align-middle" style="width:100%;border-collapse:separate;border-spacing:0 6px;">
                <thead>
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
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="shift1StaffBody">
                    <tr class="loading-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Shift 2 Panel -->
    <div class="shift-panel" id="panel2">
        <div class="staff-table-wrap">
            <table class="table table-hover table-striped align-middle" style="width:100%;border-collapse:separate;border-spacing:0 6px;">
                <thead>
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
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="shift2StaffBody">
                    <tr class="loading-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Remarks Modal -->
<div class="modal" id="remarkModal">
    <div class="modal-content" style="max-width:500px;">
        <div class="modal-header" style="background: #ffffff; border-bottom: 1px solid #e8ecf0; padding: 18px 24px;">
            <div>
                <h3 class="modal-title" style="color: #0f172a; font-weight: 700; font-size: 16px; margin: 0;">Edit Remarks</h3>
                <p style="margin: 2px 0 0; font-size: 12px; color: #94a3b8;">Modify the performance or status remarks for this staff member.</p>
            </div>
        </div>
        <div class="modal-body">
            <input type="hidden" id="remarkStaffId">
            <div class="form-group mb-3">
                <label class="lbl">Remarks / Notes</label>
                <textarea class="inp full" id="staffRemarks" rows="3" placeholder="e.g., Flagged for review..."></textarea>
            </div>
        </div>
        <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #e8ecf0;background:#f8fafc;">
            <button type="button" onclick="closeModal('remarkModal')" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#6b7280;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" onclick="saveRemark()" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#002F70;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- Edit Account Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-content" style="max-width:620px;">
        <div class="modal-header" style="background: #ffffff; border-bottom: 1px solid #e8ecf0; padding: 18px 24px;">
            <div>
                <h3 class="modal-title" style="color: #0f172a; font-weight: 700; font-size: 16px; margin: 0;">Edit Account</h3>
                <p style="margin: 2px 0 0; font-size: 12px; color: #94a3b8;">Update user credentials, role, or station assignment.</p>
            </div>
        </div>
        <div class="modal-body">
            <form id="editUserForm">
                <input type="hidden" id="editUserId" name="staff_id">
                <input type="hidden" name="action" value="edit_user">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group mb-3">
                        <label class="lbl">First Name</label>
                        <input type="text" class="inp full" id="editFirstName" name="first_name" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Last Name</label>
                        <input type="text" class="inp full" id="editLastName" name="last_name" required>
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
                        <input type="text" class="inp full" id="editUserStation" readonly style="background:#f3f4f6;">
                    </div>
                    <div class="form-group mb-3">
                        <label class="lbl">Account Status</label>
                        <select class="inp full" id="editUserStatus" name="status" required>
                            <option value="Active">Active</option>
                            <option value="Disabled">Disabled</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #e8ecf0;background:#f8fafc;">
            <button type="button" onclick="closeModal('editUserModal')" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#6b7280;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" onclick="saveEditUser()" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#002F70;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- Deactivate Confirmation Modal -->
<div class="modal" id="deactivateUserModal">
    <div class="modal-content" style="max-width:400px;">
        <div class="modal-header" style="background: #ffffff; border-bottom: 1px solid #e8ecf0; padding: 18px 24px;">
            <div>
                <h3 class="modal-title" style="color: #dc2626; font-weight: 700; font-size: 16px; margin: 0;">Deactivate Account</h3>
                <p style="margin: 2px 0 0; font-size: 12px; color: #94a3b8;">Confirm the deactivation of this staff account.</p>
            </div>
        </div>
        <div class="modal-body">
            <p>Are you sure you want to deactivate this account? The staff member will no longer be able to log in.</p>
            <input type="hidden" id="deactivateUserId">
        </div>
        <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;padding:14px 20px;border-top:1px solid #e8ecf0;background:#f8fafc;">
            <button type="button" onclick="closeModal('deactivateUserModal')" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#6b7280;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-times"></i> Cancel</button>
            <button type="button" onclick="confirmDeactivate()" style="font-size:13px;font-weight:600;padding:6px 16px;border-radius:4px;cursor:pointer;border:none;background:#dc2626;color:#fff;display:inline-flex;align-items:center;gap:5px;"><i class="fas fa-user-times"></i> Confirm Deactivate</button>
        </div>
    </div>
</div>

<script>
// ── XSS protection ──────────────────────────────────────────────────────────
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

// ── Shift tab switching ─────────────────────────────────────────────────────
let loadedShifts = {};

function switchShift(shiftNum) {
    document.querySelectorAll('.shift-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.shift-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('panel' + shiftNum).classList.add('active');
    document.getElementById('tabBtn' + shiftNum).classList.add('active');

    if (!loadedShifts[shiftNum]) {
        loadShiftData(shiftNum);
    }
}

// ── Initial load ────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    loadShiftData(1); // load shift 1 on page load
});

// ── Fetch and render shift data ─────────────────────────────────────────────
function loadShiftData(shiftNum) {
    const tbody = document.getElementById('shift' + shiftNum + 'StaffBody');
    tbody.innerHTML = '<tr class="loading-row"><td colspan="10"><i class="fas fa-spinner fa-spin"></i> Loading shift ' + shiftNum + ' data...</td></tr>';

    fetch('../backend/api/admin_staff_oversight_api.php?action=fetch_staff_oversight&shift=' + shiftNum)
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(data => {
            if (!data.success) throw new Error(data.error || 'Unknown error');
            loadedShifts[shiftNum] = true;
            renderStaffRows(tbody, data.data);
        })
        .catch(err => {
            console.error('Shift ' + shiftNum + ' load error:', err);
            tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#dc2626;padding:24px;">Error loading data: ' + escapeHtml(err.message) + '</td></tr>';
        });
}

function renderStaffRows(tbody, staffList) {
    tbody.innerHTML = '';
    if (!staffList || staffList.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#64748b;padding:24px;">No staff records found for this shift.</td></tr>';
        return;
    }

    staffList.forEach(staff => {
        // Status badge — match actual DB values (Active / Disabled)
        const statusVal = (staff.account_status || '').toLowerCase();
        const statusColor = { 'active': '#16a34a', 'disabled': '#dc2626', 'inactive': '#6b7280' }[statusVal] || '#6b7280';
        const statusLabel = (staff.account_status || 'Unknown').toUpperCase();

        const isManager = (staff.assigned_role || '').toLowerCase() === 'manager';
        const roleLabel = isManager ? 'Manager' : 'Staff';

        // Clock-in/out
        const clockIn  = staff.clock_in_time  ? new Date(staff.clock_in_time).toLocaleTimeString()  : 'Not clocked in';
        const clockOut = staff.clock_out_time ? new Date(staff.clock_out_time).toLocaleTimeString() : (staff.clock_in_time ? 'Still active' : '—');
        const duration = staff.shift_duration || '—';

        // Recent action
        const lastActionDate = isManager ? staff.last_validated_transaction : staff.last_encoded_transaction;
        const lastActionLabel = isManager ? 'Validated' : 'Encoded';
        const lastAction = lastActionDate ? new Date(lastActionDate).toLocaleString() : 'None';

        // Activity counts
        const reqCount = staff.shift_requests_count  || 0;
        const delCount = staff.shift_deliveries_count || 0;
        const jobCount = staff.shift_jobs_count       || 0;

        // Performance
        const sales   = staff.shift_sales_total    ? '₱' + parseFloat(staff.shift_sales_total).toLocaleString('en-US', {minimumFractionDigits:2}) : '₱0.00';
        const service = staff.shift_service_income  ? '₱' + parseFloat(staff.shift_service_income).toLocaleString('en-US', {minimumFractionDigits:2}) : '₱0.00';

        // Remarks
        const remarksHtml = staff.remarks
            ? '<span>' + escapeHtml(staff.remarks) + '</span>'
            : '<span style="color:#94a3b8;font-style:italic;">No remarks</span>';

        // Activate / Deactivate button
        const isActive = statusVal === 'active';
        const toggleBtn = isActive
            ? `<button class="action-btn btn-danger" onclick="openDeactivateModal(${staff.staff_id})" title="Deactivate"><i class="fas fa-times"></i> Deactivate</button>`
            : `<button class="action-btn btn-success" onclick="setStatus(${staff.staff_id}, 'Active')" title="Activate"><i class="fas fa-check"></i> Activate</button>`;

        // Safe JSON for edit modal
        const safeJson = encodeURIComponent(JSON.stringify(staff));

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>
                <div style="font-weight:700;font-size:14px;color:#0f172a;">${escapeHtml(staff.name)}</div>
                <div style="font-size:12px;font-family:monospace;font-weight:700;color:#475569;margin-top:2px;">${escapeHtml(staff.emp_id || '—')}</div>
                <div style="font-size:11px;color:#64748b;margin-top:1px;"><i class="fas fa-clock" style="font-size:10px;"></i> ${escapeHtml(staff.assigned_shift || '—')}</div>
            </td>
            <td><span style="font-weight:600;">${escapeHtml(roleLabel)}</span></td>
            <td style="font-size:13px;">${escapeHtml(staff.station_name || 'N/A')}</td>
            <td>
                <span style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#fff;background:${statusColor};">${statusLabel}</span>
            </td>
            <td style="font-size:13px;">
                <div><strong>In:</strong> ${escapeHtml(clockIn)}</div>
                <div><strong>Out:</strong> ${escapeHtml(clockOut)}</div>
                <div style="color:#64748b;"><strong>Duration:</strong> ${escapeHtml(duration)}</div>
            </td>
            <td style="font-size:13px;">
                <div>${reqCount} Requests</div>
                <div>${delCount} Deliveries</div>
                <div>${jobCount} Jobs</div>
            </td>
            <td style="font-size:13px;">
                <div><strong>${escapeHtml(lastActionLabel)}:</strong></div>
                <div style="color:#475569;">${escapeHtml(lastAction)}</div>
            </td>
            <td style="font-size:13px;">
                <div><strong>Sales:</strong> ${sales}</div>
                <div><strong>Service:</strong> ${service}</div>
            </td>
            <td style="font-size:13px;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span>${remarksHtml}</span>
                    <button onclick="openRemarkModal(${staff.staff_id}, '${escapeHtml((staff.remarks||'').replace(/'/g,"\\'"))}')" style="background:none;border:none;color:#64748b;cursor:pointer;padding:2px 4px;font-size:12px;" title="Edit Remarks"><i class="fas fa-edit"></i></button>
                </div>
            </td>
            <td style="text-align:right;">
                <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-end;">
                    <button class="action-btn btn-edit" onclick="openEditModal('${safeJson}')" title="Edit Account"><i class="fas fa-edit"></i> Edit</button>
                    ${toggleBtn}
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// ── Status toggle ───────────────────────────────────────────────────────────
function setStatus(staffId, newStatus) {
    const fd = new FormData();
    fd.append('action', 'update_status');
    fd.append('staff_id', staffId);
    fd.append('status', newStatus);

    fetch('../backend/api/admin_staff_oversight_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadedShifts = {}; // reset cache
                const active = document.querySelector('.shift-tab-btn.active');
                const shiftNum = active ? (active.id === 'tabBtn1' ? 1 : 2) : 1;
                loadShiftData(shiftNum);
            } else {
                alert('Failed to update status: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

function openDeactivateModal(staffId) {
    document.getElementById('deactivateUserId').value = staffId;
    openModal('deactivateUserModal');
}

function confirmDeactivate() {
    const staffId = document.getElementById('deactivateUserId').value;
    closeModal('deactivateUserModal');
    setStatus(staffId, 'Disabled');
}

// ── Edit modal ──────────────────────────────────────────────────────────────
function openEditModal(encodedJson) {
    const staff = JSON.parse(decodeURIComponent(encodedJson));
    document.getElementById('editUserId').value      = staff.staff_id;
    // Split name into first/last for separate fields
    const nameParts = (staff.name || '').trim().split(/\s+/);
    document.getElementById('editFirstName').value   = nameParts[0] || '';
    document.getElementById('editLastName').value    = nameParts.slice(1).join(' ') || '';
    document.getElementById('editUserEmail').value   = staff.email || '';
    document.getElementById('editUserRole').value    = (staff.assigned_role || 'staff').toLowerCase();
    document.getElementById('editUserStation').value = staff.station_name || 'N/A';
    document.getElementById('editUserStatus').value  = staff.account_status || 'Active';
    openModal('editUserModal');
}

function saveEditUser() {
    const form = document.getElementById('editUserForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const fd = new FormData(form);

    fetch('../backend/api/admin_staff_oversight_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal('editUserModal');
                loadedShifts = {};
                const active = document.querySelector('.shift-tab-btn.active');
                const shiftNum = active ? (active.id === 'tabBtn1' ? 1 : 2) : 1;
                loadShiftData(shiftNum);
            } else {
                alert('Failed to save: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

// ── Remarks ─────────────────────────────────────────────────────────────────
function openRemarkModal(staffId, currentRemarks) {
    document.getElementById('remarkStaffId').value = staffId;
    document.getElementById('staffRemarks').value  = currentRemarks || '';
    openModal('remarkModal');
}

function saveRemark() {
    const fd = new FormData();
    fd.append('action',   'update_remark');
    fd.append('staff_id', document.getElementById('remarkStaffId').value);
    fd.append('remarks',  document.getElementById('staffRemarks').value);

    fetch('../backend/api/admin_staff_oversight_api.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal('remarkModal');
                loadedShifts = {};
                const active = document.querySelector('.shift-tab-btn.active');
                const shiftNum = active ? (active.id === 'tabBtn1' ? 1 : 2) : 1;
                loadShiftData(shiftNum);
            } else {
                alert('Failed to save remarks: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Network error: ' + err.message));
}

// ── Modal helpers ───────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
