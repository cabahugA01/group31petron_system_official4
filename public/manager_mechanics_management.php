<?php
$page_id = 'manager_mechanics_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me  = current_user();
$role  = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();

// RBAC Authorization
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied.';  header('Location: manager_dashboard.php'); exit;
}

$error_msg = '';
$success_msg = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {  $action = $_POST['action'] ?? '';  // AJAX status toggle  if ($action === 'toggle_status') {  header('Content-Type: application/json');  $id = (int)($_POST['id'] ?? 0);  $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';  try {  $stmt = $pdo->prepare("UPDATE mechanics SET status = ?, updated_at = NOW() WHERE id = ?");  $stmt->execute([$new_status, $id]);  echo json_encode(['success' => true, 'status' => $new_status]);  } catch (Exception $e) {  echo json_encode(['success' => false, 'error' => $e->getMessage()]);  }  exit;  }  // Add Mechanic  if ($action === 'add') {  $full_name = trim($_POST['full_name'] ?? '');  $contact_no = trim($_POST['contact_no'] ?? '');  $address = trim($_POST['address'] ?? '');  $specialization = trim($_POST['specialization'] ?? '');  $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';  if (empty($full_name)) {  $error_msg = 'Mechanic Name is required.';  } elseif (empty($contact_no)) {  $error_msg = 'Contact Number is required.';  } elseif (empty($specialization)) {  $error_msg = 'Specialty is required.';  } else {  try {  $stmt = $pdo->prepare("INSERT INTO mechanics (full_name, contact_no, address, specialization, status, station_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");  $stmt->execute([$full_name, $contact_no, $address, $specialization, $status, $station_id]);  $success_msg = 'New mechanic added successfully.';  } catch (Exception $e) {  $error_msg = 'Failed to add mechanic: ' . $e->getMessage();  }  }  }  // Edit Mechanic  if ($action === 'edit') {  $id = (int)($_POST['id'] ?? 0);  $full_name = trim($_POST['full_name'] ?? '');  $contact_no = trim($_POST['contact_no'] ?? '');  $address = trim($_POST['address'] ?? '');  $specialization = trim($_POST['specialization'] ?? '');  $status = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';  if (empty($full_name)) {  $error_msg = 'Mechanic Name is required.';  } elseif (empty($contact_no)) {  $error_msg = 'Contact Number is required.';  } elseif (empty($specialization)) {  $error_msg = 'Specialty is required.';  } else {  try {  $stmt = $pdo->prepare("UPDATE mechanics SET full_name = ?, contact_no = ?, address = ?, specialization = ?, status = ?, updated_at = NOW() WHERE id = ?");  $stmt->execute([$full_name, $contact_no, $address, $specialization, $status, $id]);  $success_msg = 'Mechanic details updated successfully.';  } catch (Exception $e) {  $error_msg = 'Failed to update mechanic: ' . $e->getMessage();  }  }  }
}

// Fetch KPIs
$total_mechanics = 0;
$active_mechanics = 0;
$inactive_mechanics = 0;
try {  $total_mechanics = (int)$pdo->query("SELECT COUNT(*) FROM mechanics")->fetchColumn();  $active_mechanics = (int)$pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'active'")->fetchColumn();  $inactive_mechanics = (int)$pdo->query("SELECT COUNT(*) FROM mechanics WHERE status = 'inactive'")->fetchColumn();
} catch (Exception $e) {}

// Fetch Mechanics list
$mechanics_list = [];
try {  $stmt = $pdo->query("SELECT * FROM mechanics ORDER BY full_name ASC");  $mechanics_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
.page-head.txn-page-head {  display: flex;  align-items: flex-start;  justify-content: space-between;  flex-wrap: wrap;  gap: 12px;  margin-bottom: 20px;  padding-bottom: 16px;  border-bottom: 1px solid #e2e8f0;
}
.page-head.txn-page-head h1 {  font-size: 22px !important;  font-weight: 700 !important;  color: var(--petron-blue, #00264D) !important;  margin: 0 !important;  display: flex;  align-items: center;  gap: 8px;
}
.page-head.txn-page-head .sub {  font-size: 13px;  color: #64748b;  margin-top: 4px;
}

/* == KPI Cards == */
.txn-kpi-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));  gap: 12px;  margin-bottom: 18px;
}
.txn-kpi-card {  background: #fff;  border: 1px solid #e2e8f0;  border-radius: 10px;  padding: 14px 16px;  box-shadow: 0 1px 4px rgba(0, 0, 0, .05);  transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {  transform: translateY(-2px);  box-shadow: 0 4px 8px rgba(0, 0, 0, .08);
}
.txn-kpi-lbl {  font-size: 11px;  font-weight: 700;  text-transform: uppercase;  letter-spacing: .5px;  color: #64748b;  margin-bottom: 4px;  display: flex;  align-items: center;  gap: 6px;
}
.txn-kpi-val {  font-size: 24px;  font-weight: 800;  color: #002F70;  line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val { color: #0369a1; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }

/* == Table Styles == */
.table-card {  background: #fff;  border: 1px solid #e2e8f0;  border-radius: 12px;  overflow: hidden;  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.table-card-head {  padding: 16px 20px;  border-bottom: 1px solid #f1f5f9;  background: #f8fafc;  display: flex;  align-items: center;  justify-content: space-between;
}
.table-card-title {  font-size: 14px;  font-weight: 700;  color: #0f172a;
}
.table-responsive {  width: 100%;  overflow-x: auto;
}
.tbl-requests {  width: 100%;  border-collapse: collapse;  font-size: 13px;  text-align: left;
}
.tbl-requests th {  background: #002F70;  color: #fff;  font-weight: 700;  text-transform: uppercase;  font-size: 11px;  letter-spacing: 0.5px;  padding: 12px 18px;  border-bottom: 2px solid #001a3d;
}
.tbl-requests td {  padding: 14px 18px;  border-bottom: 1px solid #f1f5f9;  color: #334155;  vertical-align: middle;
}
.tbl-requests tr:hover {  background: #f8fafc;
}

/* == Buttons == */
.btn-action {  display: inline-flex;  align-items: center;  justify-content: center;  gap: 6px;  padding: 0 14px;  height: 38px;  border-radius: 8px;  font-size: 13px;  font-weight: 600;  cursor: pointer;  border: 1px solid transparent;  transition: all 0.15s;  background: #fff;  text-decoration: none;
}
.btn-primary {  background: #002F70;  color: #fff;  border-color: #002F70;
}
.btn-primary:hover {  background: #001f4d;  border-color: #001f4d;
}
.btn-secondary {  background: #f1f5f9;  color: #475569;  border-color: #cbd5e1;
}
.btn-secondary:hover {  background: #e2e8f0;
}
.btn-header-add {  background: white;  color: #16a34a !important;  border: 1px solid #16a34a;  display: inline-flex;  align-items: center;  gap: 6px;  padding: 0 16px;  height: 36px;  border-radius: 7px;  font-size: 13px;  font-weight: 600;  cursor: pointer;  transition: all .15s;  white-space: nowrap;
}
.btn-header-add:hover {  background: #16a34a !important;  color: #fff !important;
}

/* == Action buttons — matches All Transactions vt-btn-action style == */
.tbl-btn {  background: white !important;  display: flex;  align-items: center;  justify-content: center;  gap: 4px;  width: 100%;  min-width: 90px;  height: 24px;  border-radius: 4px;  border: 1px solid transparent;  cursor: pointer;  font-size: 10px;  font-weight: 600;  padding: 0 8px;  white-space: nowrap;  transition: all .15s;  margin-bottom: 3px;
}
.tbl-btn:last-child { margin-bottom: 0; }
.tbl-btn.view  { color: #16a34a !important; border-color: #16a34a !important; }
.tbl-btn.view:hover  { background: #16a34a !important; color: #fff !important; }
.tbl-btn.edit  { color: #002F70 !important; border-color: #002F70 !important; }
.tbl-btn.edit:hover  { background: #002F70 !important; color: #fff !important; }
.tbl-btn.deact { color: #dc2626 !important; border-color: #dc2626 !important; }
.tbl-btn.deact:hover { background: #dc2626 !important; color: #fff !important; }
.tbl-btn.activ { color: #16a34a !important; border-color: #16a34a !important; }
.tbl-btn.activ:hover { background: #16a34a !important; color: #fff !important; }

.badge {  display: inline-flex;  align-items: center;  gap: 4px;  padding: 2px 8px;  border-radius: 999px;  font-size: 11px;  font-weight: 600;
}
.badge-active { background: #d1fae5; color: #065f46; }
.badge-inactive { background: #fee2e2; color: #dc2626; }

/* == Modal == */
.modal-backdrop {  display: none;  position: fixed;  inset: 0;  z-index: 10000;  background: rgba(15, 23, 42, 0.6);  backdrop-filter: blur(4px);  align-items: center;  justify-content: center;  padding: 16px;
}
.modal-content {  background: #fff;  border-radius: 16px;  width: 100%;  max-width: 520px;  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);  overflow: hidden;  animation: modalSlideUp 0.2s ease-out;
}
@keyframes modalSlideUp {  from { transform: translateY(20px); opacity: 0; }  to { transform: translateY(0); opacity: 1; }
}
.modal-header {  padding: 16px 20px;  border-bottom: 1px solid #f1f5f9;  background: #f8fafc;  display: flex;  align-items: center;  justify-content: space-between;
}
.modal-title {  font-size: 15px;  font-weight: 700;  color: #0f172a;
}
.modal-body {  padding: 20px;  font-size: 13px;  color: #334155;
}
.modal-footer {  padding: 14px 20px;  border-top: 1px solid #f1f5f9;  background: #f8fafc;  display: flex;  justify-content: flex-end;  gap: 10px;
}

/* == Search Filters Form == */
.filters-form {  display: flex;  align-items: flex-end;  gap: 12px;  flex-wrap: wrap;  background: #fff;  border: 1px solid #e2e8f0;  border-radius: 12px;  padding: 14px 20px;  margin-bottom: 24px;  box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.filters-form > div {  display: flex;  flex-direction: column;  gap: 4px;
}
.filters-form label {  font-size: 11px;  font-weight: 700;  color: #475569;  text-transform: uppercase;  letter-spacing: 0.4px;
}
.filters-form .inp {  height: 38px;  padding: 0 12px;  border: 1px solid #cbd5e1;  border-radius: 8px;  font-size: 13px;  color: #1e293b;  background: #fff;  outline: none;  min-width: 140px;  transition: border-color 0.15s, box-shadow 0.15s;
}
.filters-form .inp:focus {  border-color: #002F70;  box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}

/* Form Styles */
.form-field {  display: flex;  flex-direction: column;  gap: 6px;  margin-bottom: 14px;
}
.form-field label {  font-weight: 600;  color: #475569;  font-size: 12px;
}
.form-field input, .form-field textarea, .form-field select {  height: 38px;  padding: 0 12px;  border: 1px solid #cbd5e1;  border-radius: 8px;  font-size: 13px;  color: #1e293b;  outline: none;
}
.form-field textarea {  height: 80px;  padding: 8px 12px;  resize: none;
}
.form-field input:focus, .form-field textarea:focus, .form-field select:focus {  border-color: #002F70;  box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}
</style>

<!-- Header -->
<div class="page-head txn-page-head">  <div>  <h1><i class="fas fa-wrench"></i> Mechanics Management</h1>  <div class="sub">Oversee service technicians, contact details, specialty categories, and availability status.</div>  </div>  <div>  <button onclick="openAddModal()" class="btn-header-add"><i class="fas fa-plus"></i> Add New Mechanic</button>  </div>
</div>

<!-- Alert Boxes -->
<?php if (!empty($success_msg)): ?>  <div style="background:#d1fae5; border:1px solid #a7f3d0; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px;">  <i class="fas fa-check-circle" style="margin-right:6px;"></i> <?= htmlspecialchars($success_msg) ?>  </div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>  <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px;">  <i class="fas fa-exclamation-circle" style="margin-right:6px;"></i> <?= htmlspecialchars($error_msg) ?>  </div>
<?php endif; ?>

<!-- KPIs -->
<div class="txn-kpi-grid">  <div class="txn-kpi-card blue">  <div class="txn-kpi-lbl"><i class="fas fa-users"></i> Total Mechanics</div>  <div class="txn-kpi-val"><?= number_format($total_mechanics) ?></div>  </div>  <div class="txn-kpi-card green">  <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Active Mechanics</div>  <div class="txn-kpi-val"><?= number_format($active_mechanics) ?></div>  </div>  <div class="txn-kpi-card danger">  <div class="txn-kpi-lbl"><i class="fas fa-times-circle"></i> Inactive Mechanics</div>  <div class="txn-kpi-val"><?= number_format($inactive_mechanics) ?></div>  </div>
</div>

<!-- Search Filter -->
<div class="filters-form">  <div style="flex: 1; min-width: 200px;">  <label>Search Mechanics</label>  <input type="text" id="tableSearch" onkeyup="searchMechanicsTable()" class="inp" style="width: 100%;" placeholder="Search by name, specialty, contact...">  </div>
</div>

<!-- Table Card -->
<div class="table-card">  <div class="table-card-head">  <div class="table-card-title"><i class="fas fa-list" style="margin-right: 6px;"></i> Mechanics List</div>  </div>  <div class="table-responsive">  <table class="tbl-requests" id="mechanicsTable">  <thead>  <tr>  <th>Mechanic ID</th>  <th>Mechanic Name</th>  <th>Contact No.</th>  <th>Specialty</th>  <th>Status</th>  <th style="width: 130px;">Actions</th>  </tr>  </thead>  <tbody>  <?php if (empty($mechanics_list)): ?>  <tr>  <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">  <i class="fas fa-user-slash" style="font-size: 28px; display: block; margin-bottom: 10px;"></i>  No mechanics registered. Click "Add New Mechanic" to begin.  </td>  </tr>  <?php else: ?>  <?php foreach ($mechanics_list as $row):  $formattedId = sprintf("MEC-%03d", $row['id']);  $statusClass = $row['status'] === 'active' ? 'badge-active' : 'badge-inactive';  ?>  <tr data-id="<?= (int)$row['id'] ?>">  <td><strong><?= htmlspecialchars($formattedId) ?></strong></td>  <td class="mech-name"><strong><?= htmlspecialchars($row['full_name']) ?></strong></td>  <td class="mech-contact"><?= htmlspecialchars($row['contact_no'] ?: '—') ?></td>  <td class="mech-spec"><?= htmlspecialchars($row['specialization'] ?: 'General Mechanic') ?></td>  <td>  <span class="badge <?= $statusClass ?>" id="status-badge-<?= (int)$row['id'] ?>">  <?= htmlspecialchars(ucfirst($row['status'])) ?>  </span>  </td>  <td style="vertical-align: middle;">  <button class="tbl-btn view" onclick='openViewModal(<?= json_encode($row) ?>)'>  <i class="fas fa-eye"></i> View  </button>  <button class="tbl-btn edit" onclick='openEditModal(<?= json_encode($row) ?>)'>  <i class="fas fa-pen"></i> Edit  </button>  <?php if ($row['status'] === 'active'): ?>  <button class="tbl-btn deact" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'inactive')">  <i class="fas fa-times"></i> Deactivate  </button>  <?php else: ?>  <button class="tbl-btn activ" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'active')">  <i class="fas fa-check"></i> Activate  </button>  <?php endif; ?>  </td>  </tr>  <?php endforeach; ?>  <?php endif; ?>  </tbody>  </table>  </div>
</div>

<!-- View Modal -->
<div id="viewModal" class="modal-backdrop">  <div class="modal-content">  <div class="modal-header">  <span class="modal-title"><i class="fas fa-user"></i> Mechanic Details</span>  <button onclick="closeViewModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#94a3b8;">&times;</button>  </div>  <div class="modal-body" style="line-height: 1.6;">  <table style="width: 100%; border-collapse: collapse; font-size: 13px;">  <tr>  <td style="padding: 6px 0; color: #64748b; width: 35%;"><strong>Mechanic ID:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewMecId">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Full Name:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewName">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Contact Number:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewContact">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Address:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewAddress">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Specialty:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewSpecialty">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Status:</strong></td>  <td style="padding: 6px 0;" id="viewStatusContainer">—</td>  </tr>  <tr>  <td style="padding: 6px 0; color: #64748b;"><strong>Registered At:</strong></td>  <td style="padding: 6px 0; color: #1e293b;" id="viewCreatedAt">—</td>  </tr>  </table>  </div>  <div class="modal-footer">  <button onclick="closeViewModal()" style="  background: white; color: #4b5563; border: 1px solid #6b7280;  display: inline-flex; align-items: center; gap: 6px;  padding: 0 16px; height: 36px; border-radius: 7px;  font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;"  onmouseover="this.style.background='#6b7280';this.style.color='#fff';"  onmouseout="this.style.background='white';this.style.color='#4b5563';">Close</button>  </div>  </div>
</div>

<!-- Add / Edit Modal -->
<div id="addEditModal" class="modal-backdrop">  <div class="modal-content">  <form method="POST" id="mechanicForm">  <input type="hidden" name="action" id="formAction" value="add">  <input type="hidden" name="id" id="formId" value="">  <div class="modal-header">  <span class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Add New Mechanic</span>  <button type="button" onclick="closeAddEditModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#94a3b8;">&times;</button>  </div>  <div class="modal-body">  <div class="form-field">  <label>Mechanic Name <span style="color:#dc2626;">*</span></label>  <input type="text" name="full_name" id="field_name" required placeholder="Enter mechanic full name...">  </div>  <div class="form-field">  <label>Contact Number <span style="color:#dc2626;">*</span></label>  <input type="text" name="contact_no" id="field_contact" required placeholder="Enter 11-digit mobile number or landline...">  </div>  <div class="form-field">  <label>Address</label>  <textarea name="address" id="field_address" placeholder="Enter residential address (optional)..."></textarea>  </div>  <div class="form-field">  <label>Specialty <span style="color:#dc2626;">*</span></label>  <select name="specialization" id="field_specialty" required>  <option value="Engine">Engine</option>  <option value="PMS">PMS</option>  <option value="Electrical">Electrical</option>  <option value="Underchassis">Underchassis</option>  <option value="Aircon">Aircon</option>  <option value="General Mechanic">General Mechanic</option>  <option value="Tires/Wheels">Tires/Wheels</option>  </select>  </div>  <div class="form-field">  <label>Status <span style="color:#dc2626;">*</span></label>  <div style="display:flex; gap:16px; margin-top:4px;">  <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal;">  <input type="radio" name="status" id="status_active" value="active" checked> Active  </label>  <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:normal;">  <input type="radio" name="status" id="status_inactive" value="inactive"> Inactive  </label>  </div>  </div>  </div>  <div class="modal-footer">  <button type="button" onclick="closeAddEditModal()" style="  background: white; color: #4b5563; border: 1px solid #6b7280;  display: inline-flex; align-items: center; gap: 6px;  padding: 0 16px; height: 36px; border-radius: 7px;  font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;"  onmouseover="this.style.background='#6b7280';this.style.color='#fff';"  onmouseout="this.style.background='white';this.style.color='#4b5563';">Cancel</button>  <button type="submit" id="btnSave" style="  background: #002F70; color: #fff; border: 1px solid #002F70;  display: inline-flex; align-items: center; gap: 6px;  padding: 0 16px; height: 36px; border-radius: 7px;  font-size: 13px; font-weight: 600; cursor: pointer; transition: all .15s;"  onmouseover="this.style.background='#001f4d';this.style.borderColor='#001f4d';"  onmouseout="this.style.background='#002F70';this.style.borderColor='#002F70';"><i class="fas fa-save"></i> Save</button>  </div>  </form>  </div>
</div>

<script>
// Search mechanics table filter
function searchMechanicsTable() {  const input = document.getElementById('tableSearch');  const filter = input.value.toLowerCase();  const table = document.getElementById('mechanicsTable');  const tr = table.getElementsByTagName('tr');  for (let i = 1; i < tr.length; i++) {  const tdName = tr[i].getElementsByClassName('mech-name')[0];  const tdContact = tr[i].getElementsByClassName('mech-contact')[0];  const tdSpec = tr[i].getElementsByClassName('mech-spec')[0];  if (tdName || tdContact || tdSpec) {  const txtName = (tdName ? tdName.textContent : '');  const txtContact = (tdContact ? tdContact.textContent : '');  const txtSpec = (tdSpec ? tdSpec.textContent : '');  if (txtName.toLowerCase().indexOf(filter) > -1 ||  txtContact.toLowerCase().indexOf(filter) > -1 ||  txtSpec.toLowerCase().indexOf(filter) > -1) {  tr[i].style.display = "";  } else {  tr[i].style.display = "none";  }  }  }
}

// View Modal functions
function openViewModal(mec) {  const formattedId = "MEC-" + String(mec.id).padStart(3, '0');  document.getElementById('viewMecId').textContent = formattedId;  document.getElementById('viewName').textContent = mec.full_name;  document.getElementById('viewContact').textContent = mec.contact_no || '—';  document.getElementById('viewAddress').textContent = mec.address || '—';  document.getElementById('viewSpecialty').textContent = mec.specialization || 'General Mechanic';  const badgeClass = mec.status === 'active' ? 'badge-active' : 'badge-inactive';  const statusText = mec.status.charAt(0).toUpperCase() + mec.status.slice(1);  document.getElementById('viewStatusContainer').innerHTML = `<span class="badge ${badgeClass}">${statusText}</span>`;  document.getElementById('viewCreatedAt').textContent = mec.created_at || '—';  document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {  document.getElementById('viewModal').style.display = 'none';
}

// Add/Edit Modal functions
function openAddModal() {  document.getElementById('formAction').value = 'add';  document.getElementById('formId').value = '';  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Add New Mechanic';  // Clear fields  document.getElementById('field_name').value = '';  document.getElementById('field_contact').value = '';  document.getElementById('field_address').value = '';  document.getElementById('field_specialty').value = 'General Mechanic';  document.getElementById('status_active').checked = true;  document.getElementById('addEditModal').style.display = 'flex';
}

function openEditModal(mec) {  document.getElementById('formAction').value = 'edit';  document.getElementById('formId').value = mec.id;  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Mechanic';  // Populate fields  document.getElementById('field_name').value = mec.full_name;  document.getElementById('field_contact').value = mec.contact_no || '';  document.getElementById('field_address').value = mec.address || '';  document.getElementById('field_specialty').value = mec.specialization || 'General Mechanic';  if (mec.status === 'active') {  document.getElementById('status_active').checked = true;  } else {  document.getElementById('status_inactive').checked = true;  }  document.getElementById('addEditModal').style.display = 'flex';
}

function closeAddEditModal() {  document.getElementById('addEditModal').style.display = 'none';
}

// Inline toggle status (Activate / Deactivate)
async function toggleStatus(id, newStatus) {  const label = newStatus === 'inactive' ? 'Deactivate' : 'Activate';  if (!confirm(`Are you sure you want to ${label.toLowerCase()} this mechanic?`)) {  return;  }  const formData = new FormData();  formData.append('action', 'toggle_status');  formData.append('id', id);  formData.append('status', newStatus);  try {  const response = await fetch('manager_mechanics_management.php', {  method: 'POST',  body: formData  });  const result = await response.json();  if (result.success) {  // Update UI badge  const badge = document.getElementById('status-badge-' + id);  if (badge) {  badge.className = newStatus === 'active' ? 'badge badge-active' : 'badge badge-inactive';  badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);  }  // Update Toggle Button  const btn = document.getElementById('toggle-btn-' + id);  if (btn) {  if (newStatus === 'active') {  btn.className = 'tbl-btn deact';  btn.innerHTML = '<i class="fas fa-times"></i> Deactivate';  btn.setAttribute('onclick', `toggleStatus(${id}, 'inactive')`);  } else {  btn.className = 'tbl-btn activ';  btn.innerHTML = '<i class="fas fa-check"></i> Activate';  btn.setAttribute('onclick', `toggleStatus(${id}, 'active')`);  }  }  // Reload window to refresh KPI counts and JSON data representation cleanly  window.location.reload();  } else {  alert('Error updating status: ' + (result.error || 'Unknown error'));  }  } catch (err) {  alert('Network error: ' + err.message);  }
}

// Close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(modal => {  modal.addEventListener('click', function(e) {  if (e.target === this) {  this.style.display = 'none';  }  });
});
</script>
<?php
require_once __DIR__ . '/../partials/footer.php';
?>
