<?php
$page_id = 'manager_mechanics_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int) user_station_id();
$mechanic_station_where  = $station_id > 0 ? 'WHERE m.station_id = ?' : '';
$mechanic_station_params = $station_id > 0 ? [$station_id] : [];

// RBAC Authorization
if (!in_array($role, ['manager', 'supervisor', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: manager_dashboard.php'); exit;
}

// Auto-migrate first_name, middle_name, last_name columns if not present
try {
    $check_cols = $pdo->query("DESCRIBE mechanics")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('first_name', $check_cols)) {
        $pdo->exec("ALTER TABLE mechanics ADD COLUMN first_name VARCHAR(100) NULL AFTER id");
        $pdo->exec("ALTER TABLE mechanics ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name");
        $pdo->exec("ALTER TABLE mechanics ADD COLUMN last_name VARCHAR(100) NULL AFTER middle_name");
    }
} catch (Exception $e) {}

$error_msg   = '';
$success_msg = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // AJAX status toggle
    if ($action === 'toggle_status') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $new_status = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        
        // Deactivate Rule Validation: Check if mechanic has active job orders
        if ($new_status === 'inactive') {
            try {
                $stmt_check = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM job_orders 
                    WHERE assigned_mechanic_id = ? 
                      AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
                ");
                $stmt_check->execute([$id]);
                $active_jos = (int)$stmt_check->fetchColumn();
                
                if ($active_jos > 0) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Cannot deactivate this mechanic. This mechanic has an active job order assignment.'
                    ]);
                    exit;
                }
            } catch (Exception $e) {}
        }
        
        try {
            if ($station_id > 0) {
                $stmt = $pdo->prepare("UPDATE mechanics SET status = ?, updated_at = NOW() WHERE id = ? AND station_id = ?");
                $stmt->execute([$new_status, $id, $station_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE mechanics SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $id]);
            }
            echo json_encode(['success' => true, 'status' => $new_status]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    
    // Add Mechanic
    if ($action === 'add') {
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = trim($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $contact_no     = trim($_POST['contact_no'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $status         = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        
        $full_name = trim($first_name . ($middle_name !== '' ? ' ' . $middle_name : '') . ' ' . $last_name);

        if (empty($first_name)) {
            $error_msg = 'First Name is required.';
        } elseif (empty($last_name)) {
            $error_msg = 'Last Name is required.';
        } elseif (empty($contact_no)) {
            $error_msg = 'Contact Number is required.';
        } elseif (empty($specialization)) {
            $error_msg = 'Specialty is required.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO mechanics 
                    (first_name, middle_name, last_name, full_name, contact_no, specialization, status, station_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$first_name, $middle_name, $last_name, $full_name, $contact_no, $specialization, $status, $station_id]);
                $success_msg = 'New mechanic added successfully.';
            } catch (Exception $e) {
                $error_msg = 'Failed to add mechanic: ' . $e->getMessage();
            }
        }
    }
    
    // Edit Mechanic
    if ($action === 'edit') {
        $id             = (int)($_POST['id'] ?? 0);
        $first_name     = trim($_POST['first_name'] ?? '');
        $middle_name    = trim($_POST['middle_name'] ?? '');
        $last_name      = trim($_POST['last_name'] ?? '');
        $contact_no     = trim($_POST['contact_no'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $status         = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';
        
        $full_name = trim($first_name . ($middle_name !== '' ? ' ' . $middle_name : '') . ' ' . $last_name);

        if (empty($first_name)) {
            $error_msg = 'First Name is required.';
        } elseif (empty($last_name)) {
            $error_msg = 'Last Name is required.';
        } elseif (empty($contact_no)) {
            $error_msg = 'Contact Number is required.';
        } elseif (empty($specialization)) {
            $error_msg = 'Specialty is required.';
        } else {
            // Check Deactivate Rule if setting status to inactive
            $can_update = true;
            if ($status === 'inactive') {
                try {
                    $stmt_check = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM job_orders 
                        WHERE assigned_mechanic_id = ? 
                          AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
                    ");
                    $stmt_check->execute([$id]);
                    if ((int)$stmt_check->fetchColumn() > 0) {
                        $can_update = false;
                        $error_msg = 'Cannot deactivate this mechanic. This mechanic has an active job order assignment.';
                    }
                } catch (Exception $e) {}
            }
            
            if ($can_update) {
                try {
                    if ($station_id > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE mechanics 
                            SET first_name = ?, middle_name = ?, last_name = ?, full_name = ?, contact_no = ?, specialization = ?, status = ?, updated_at = NOW() 
                            WHERE id = ? AND station_id = ?
                        ");
                        $stmt->execute([$first_name, $middle_name, $last_name, $full_name, $contact_no, $specialization, $status, $id, $station_id]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE mechanics 
                            SET first_name = ?, middle_name = ?, last_name = ?, full_name = ?, contact_no = ?, specialization = ?, status = ?, updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([$first_name, $middle_name, $last_name, $full_name, $contact_no, $specialization, $status, $id]);
                    }
                    $success_msg = 'Mechanic details updated successfully.';
                } catch (Exception $e) {
                    $error_msg = 'Failed to update mechanic: ' . $e->getMessage();
                }
            }
        }
    }
}

// Fetch KPIs
$total_mechanics    = 0;
$active_mechanics   = 0;
$inactive_mechanics = 0;
$assigned_today     = 0;

try {
    $st_where = $station_id > 0 ? 'WHERE station_id = ?' : '';
    $st_params = $station_id > 0 ? [$station_id] : [];

    // Total
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics {$st_where}");
    $stmt->execute($st_params);
    $total_mechanics = (int)$stmt->fetchColumn();

    // Active
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics " . ($st_where ? "{$st_where} AND" : "WHERE") . " status = 'active'");
    $stmt->execute($st_params);
    $active_mechanics = (int)$stmt->fetchColumn();

    // Inactive
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mechanics " . ($st_where ? "{$st_where} AND" : "WHERE") . " status = 'inactive'");
    $stmt->execute($st_params);
    $inactive_mechanics = (int)$stmt->fetchColumn();

    // Assigned Today (Distinct mechanics assigned to active JOs today or active JOs)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT assigned_mechanic_id) 
        FROM job_orders 
        WHERE assigned_mechanic_id IS NOT NULL 
          AND status IN ('Pending','Reviewed','In Progress','Awaiting Parts')
    ");
    $stmt->execute();
    $assigned_today = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Fetch Mechanics list with Job Orders metrics
$mechanics_list = [];
$next_mech_id = 1;
try {
    // Get max ID for auto-generating new mechanic ID display
    $stmt_max = $pdo->query("SELECT MAX(id) FROM mechanics");
    $next_mech_id = ((int)$stmt_max->fetchColumn()) + 1;

    $query = "
        SELECT m.*,
            (SELECT COUNT(*) FROM job_orders jo WHERE jo.assigned_mechanic_id = m.id AND jo.status IN ('Pending','Reviewed','In Progress','Awaiting Parts')) AS assigned_jo_count,
            (SELECT COUNT(*) FROM job_orders jo WHERE jo.assigned_mechanic_id = m.id AND jo.status IN ('Completed','Verified','finalized')) AS completed_jo_count
        FROM mechanics m
        {$mechanic_station_where}
        ORDER BY m.id DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($mechanic_station_params);
    $mechanics_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>
<style>
/* Page Header */
.stock-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e2e8f0;
}
.stock-title {
    font-size: 22px !important;
    font-weight: 800 !important;
    color: #002F70 !important;
    margin: 0 !important;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* KPI Cards (4 Column Grid) */
.txn-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
@media (max-width: 992px) {
    .txn-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 576px) {
    .txn-kpi-grid {
        grid-template-columns: 1fr;
    }
}
.txn-kpi-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 16px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
    transition: transform .15s, box-shadow .15s;
}
.txn-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.06);
}
.txn-kpi-lbl {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #64748b;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.txn-kpi-val {
    font-size: 26px;
    font-weight: 800;
    color: #002F70;
    line-height: 1.1;
}
.txn-kpi-card.blue .txn-kpi-val { color: #0284c7; }
.txn-kpi-card.green .txn-kpi-val { color: #16a34a; }
.txn-kpi-card.danger .txn-kpi-val { color: #dc2626; }
.txn-kpi-card.orange .txn-kpi-val { color: #d97706; }

/* Filter Form Bar */
.filters-form {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex-wrap: wrap;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.filters-form > div {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.filters-form label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.filters-form .inp {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    background: #fff;
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.filters-form .inp:focus {
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}

/* Table Card & High Density Table */
.table-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.table-card-head {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.table-card-title {
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.tbl-requests {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}
.tbl-requests th {
    background: #002F70;
    color: #fff;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 12px 16px;
    border-bottom: 2px solid #001a3d;
    white-space: nowrap;
}
.tbl-requests td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}
.tbl-requests tr:hover {
    background: #f8fafc;
}

/* Buttons */
.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 0 14px;
    height: 38px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    background: #fff;
    text-decoration: none;
}
.btn-primary {
    background: #002F70;
    color: #fff;
    border-color: #002F70;
}
.btn-primary:hover {
    background: #001f4d;
    border-color: #001f4d;
}
.btn-secondary {
    background: #f1f5f9;
    color: #475569;
    border-color: #cbd5e1;
}
.btn-secondary:hover {
    background: #e2e8f0;
}
.btn-header-add {
    background: #002F70 !important;
    color: #fff !important;
    border: 1px solid #002F70;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 0 16px;
    height: 36px;
    border-radius: 7px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    white-space: nowrap;
}
.btn-header-add:hover {
    background: #001f4d !important;
    border-color: #001f4d !important;
}

/* Table Action Buttons */
.tbl-btn-group {
    display: flex;
    align-items: center;
    gap: 6px;
}
.tbl-btn {
    background: white !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    font-size: 11px;
    font-weight: 700;
    padding: 0 10px;
    white-space: nowrap;
    transition: all .15s;
}
.tbl-btn.view  { color: #0284c7 !important; border-color: #0284c7 !important; }
.tbl-btn.view:hover  { background: #0284c7 !important; color: #fff !important; }
.tbl-btn.edit  { color: #002F70 !important; border-color: #002F70 !important; }
.tbl-btn.edit:hover  { background: #002F70 !important; color: #fff !important; }
.tbl-btn.deact { color: #dc2626 !important; border-color: #dc2626 !important; }
.tbl-btn.deact:hover { background: #dc2626 !important; color: #fff !important; }
.tbl-btn.activ { color: #16a34a !important; border-color: #16a34a !important; }
.tbl-btn.activ:hover { background: #16a34a !important; color: #fff !important; }

/* Status Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}
.badge-active { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.badge-inactive { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* Modal Styling */
.modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 10000;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.modal-content {
    background: #fff;
    border-radius: 14px;
    width: 100%;
    max-width: 540px;
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
    overflow: hidden;
    animation: modalSlideUp 0.2s ease-out;
}
@keyframes modalSlideUp {
    from { transform: translateY(16px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.modal-title {
    font-size: 15px;
    font-weight: 800;
    color: #002F70;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-body {
    padding: 20px;
    font-size: 13px;
    color: #334155;
    max-height: calc(100vh - 200px);
    overflow-y: auto;
}
.modal-footer {
    padding: 14px 20px;
    border-top: 1px solid #f1f5f9;
    background: #f8fafc;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.form-section-title {
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #002F70;
    margin: 16px 0 10px 0;
    padding-bottom: 4px;
    border-bottom: 1px solid #e2e8f0;
}
.form-section-title:first-child {
    margin-top: 0;
}
.form-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 480px) {
    .form-grid-2 {
        grid-template-columns: 1fr;
    }
}
.form-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
    margin-bottom: 12px;
}
.form-field label {
    font-weight: 700;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
.form-field input, .form-field select {
    height: 38px;
    padding: 0 12px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    font-size: 13px;
    color: #1e293b;
    outline: none;
    background: #fff;
    transition: border-color 0.15s, box-shadow 0.15s;
}
.form-field input:focus, .form-field select:focus {
    border-color: #002F70;
    box-shadow: 0 0 0 3px rgba(0, 47, 112, 0.1);
}
</style>

<!-- Main Container -->
<div class="stock-page">

<!-- Header -->
<div class="stock-head">
    <div>
        <h1 class="stock-title"><i class="fas fa-wrench"></i> Mechanics Management</h1>
    </div>
</div>

<!-- Alert Boxes -->
<?php if (!empty($success_msg)): ?>
    <div style="background:#d1fae5; border:1px solid #a7f3d0; color:#065f46; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-check-circle" style="font-size:16px;"></i> <span><?= htmlspecialchars($success_msg) ?></span>
    </div>
<?php endif; ?>
<?php if (!empty($error_msg)): ?>
    <div style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; padding:12px 16px; border-radius:8px; margin-bottom:20px; font-size:13px; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-exclamation-circle" style="font-size:16px;"></i> <span><?= htmlspecialchars($error_msg) ?></span>
    </div>
<?php endif; ?>

<!-- Summary Cards (4 Cards) -->
<div class="txn-kpi-grid">
    <div class="txn-kpi-card blue">
        <div class="txn-kpi-lbl"><i class="fas fa-users"></i> Total Mechanics</div>
        <div class="txn-kpi-val"><?= number_format($total_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card green">
        <div class="txn-kpi-lbl"><i class="fas fa-check-circle"></i> Active Mechanics</div>
        <div class="txn-kpi-val"><?= number_format($active_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card danger">
        <div class="txn-kpi-lbl"><i class="fas fa-times-circle"></i> Inactive Mechanics</div>
        <div class="txn-kpi-val"><?= number_format($inactive_mechanics) ?></div>
    </div>
    <div class="txn-kpi-card orange">
        <div class="txn-kpi-lbl"><i class="fas fa-wrench"></i> Assigned Today</div>
        <div class="txn-kpi-val"><?= number_format($assigned_today) ?></div>
    </div>
</div>

<!-- Search & Filters Bar -->
<div class="filters-form">
    <div style="flex: 2; min-width: 220px;">
        <label><i class="fas fa-search"></i> Search</label>
        <input type="text" id="tableSearch" class="inp" style="width: 100%;" 
               placeholder="Search by name, specialty, contact..."
               onkeyup="filterMechanicsTable()">
    </div>
    <div style="flex: 1; min-width: 150px;">
        <label><i class="fas fa-toggle-on"></i> Status</label>
        <select id="statusFilter" class="inp" style="width: 100%;" onchange="filterMechanicsTable()">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div style="flex: 1; min-width: 180px;">
        <label><i class="fas fa-tools"></i> Specialty</label>
        <select id="specialtyFilter" class="inp" style="width: 100%;" onchange="filterMechanicsTable()">
            <option value="">All Specialties</option>
            <option value="Oil Change">Oil Change</option>
            <option value="Brake System">Brake System</option>
            <option value="Air Conditioning">Air Conditioning</option>
            <option value="Engine Repair">Engine Repair</option>
            <option value="Electrical">Electrical</option>
            <option value="Tire Services">Tire Services</option>
            <option value="General Mechanic">General Mechanic</option>
        </select>
    </div>
    <div style="display: flex; gap: 8px; align-items: flex-end;">
        <button type="button" onclick="filterMechanicsTable()" class="btn-action btn-primary" style="height:38px;">
            <i class="fas fa-filter"></i> Filter
        </button>
        <button type="button" onclick="resetFilters()" class="btn-action btn-secondary" style="height:38px;">
            <i class="fas fa-undo"></i> Reset
        </button>
    </div>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-head">
        <div class="table-card-title"><i class="fas fa-list"></i> Mechanics List</div>
        <button onclick="openAddModal()" class="btn-header-add"><i class="fas fa-plus"></i> Add New Mechanic</button>
    </div>
    <div class="table-responsive">
        <table class="tbl-requests" id="mechanicsTable">
            <thead>
                <tr>
                    <th>Mechanic ID</th>
                    <th>Mechanic Name</th>
                    <th>Contact No.</th>
                    <th>Specialty</th>
                    <th style="text-align: center;">Assigned JO</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center; width: 220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mechanics_list)): ?>
                    <tr id="emptyDbRow">
                        <td colspan="7" style="text-align: center; padding: 50px 20px; color: #64748b;">
                            <i class="fas fa-user-slash" style="font-size: 44px; color: #cbd5e1; display: block; margin: 0 auto 12px auto;"></i>
                            <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 6px 0;">No mechanics available.</h3>
                            <p style="font-size: 13px; color: #64748b; margin: 0;">Click "Add New Mechanic" to register your first mechanic.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mechanics_list as $row): 
                        $formattedId = sprintf("MEC-%04d", $row['id']);
                        $statusClass = $row['status'] === 'active' ? 'badge-active' : 'badge-inactive';
                        $assignedCount = (int)($row['assigned_jo_count'] ?? 0);
                        $completedCount = (int)($row['completed_jo_count'] ?? 0);
                        
                        // Parse first/middle/last if missing in record
                        $fname = $row['first_name'] ?? '';
                        $mname = $row['middle_name'] ?? '';
                        $lname = $row['last_name'] ?? '';
                        if (empty($fname) && !empty($row['full_name'])) {
                            $parts = explode(' ', trim($row['full_name']));
                            if (count($parts) >= 2) {
                                $fname = array_shift($parts);
                                $lname = array_pop($parts);
                                $mname = implode(' ', $parts);
                            } else {
                                $fname = $row['full_name'];
                            }
                        }
                        $row['first_name']  = $fname;
                        $row['middle_name'] = $mname;
                        $row['last_name']   = $lname;
                    ?>
                        <tr data-id="<?= (int)$row['id'] ?>"
                            data-status="<?= htmlspecialchars($row['status']) ?>"
                            data-specialty="<?= htmlspecialchars($row['specialization'] ?: 'General Mechanic') ?>">
                            <td style="font-family: monospace; font-weight: 700; color: #002F70; font-size: 13px;"><?= htmlspecialchars($formattedId) ?></td>
                            <td class="mech-name" style="font-weight: 700; color: #0f172a;"><?= htmlspecialchars($row['full_name']) ?></td>
                            <td class="mech-contact" style="color: #475569;"><?= htmlspecialchars($row['contact_no'] ?: '—') ?></td>
                            <td class="mech-spec" style="font-weight: 600; color: #334155;"><?= htmlspecialchars($row['specialization'] ?: 'General Mechanic') ?></td>
                            <td style="text-align: center;">
                                <?php if ($assignedCount > 0): ?>
                                    <span style="background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; font-weight: 700; padding: 2px 10px; border-radius: 12px; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="fas fa-wrench"></i> <?= $assignedCount ?> Active
                                    </span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 12px; font-weight: 600;">0 Active</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge <?= $statusClass ?>" id="status-badge-<?= (int)$row['id'] ?>">
                                    <i class="fas <?= $row['status'] === 'active' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
                                    <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                </span>
                            </td>
                            <td style="text-align: center; vertical-align: middle;">
                                <div class="tbl-btn-group" style="justify-content: center;">
                                    <button class="tbl-btn view" onclick='openViewModal(<?= json_encode($row) ?>)'>
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <button class="tbl-btn edit" onclick='openEditModal(<?= json_encode($row) ?>)'>
                                        <i class="fas fa-pen"></i> Edit
                                    </button>
                                    <?php if ($row['status'] === 'active'): ?>
                                        <button class="tbl-btn deact" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'inactive', <?= $assignedCount ?>)">
                                            <i class="fas fa-sync-alt"></i> Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button class="tbl-btn activ" id="toggle-btn-<?= (int)$row['id'] ?>" onclick="toggleStatus(<?= (int)$row['id'] ?>, 'active', 0)">
                                            <i class="fas fa-sync-alt"></i> Activate
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Dynamic Empty Search Result Row -->
                <tr id="noFilterRow" style="display: none;">
                    <td colspan="7" style="text-align: center; padding: 50px 20px; color: #64748b;">
                        <i class="fas fa-user-slash" style="font-size: 44px; color: #cbd5e1; display: block; margin: 0 auto 12px auto;"></i>
                        <h3 style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0 0 6px 0;">No mechanics available.</h3>
                        <p style="font-size: 13px; color: #64748b; margin: 0;">Click "Add New Mechanic" to register your first mechanic.</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- =========================================================================
     VIEW MECHANIC MODAL
     ========================================================================= -->
<div id="viewModal" class="modal-backdrop">
    <div class="modal-content">
        <div class="modal-header">
            <span class="modal-title"><i class="fas fa-user-gear"></i> Mechanic Information</span>
            <button onclick="closeViewModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#94a3b8;">×</button>
        </div>
        <div class="modal-body">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:16px; display:flex; align-items:center; gap:14px;">
                <div style="width:48px; height:48px; border-radius:50%; background:#002F70; color:#fff; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800;" id="viewAvatar">
                    M
                </div>
                <div>
                    <div style="font-size:16px; font-weight:800; color:#0f172a;" id="viewName">—</div>
                    <div style="font-size:12px; color:#64748b; font-family:monospace; font-weight:700;" id="viewMecId">MEC-0000</div>
                </div>
                <div style="margin-left:auto;" id="viewStatusContainer">
                    <span class="badge badge-active">Active</span>
                </div>
            </div>

            <div class="form-section-title"><i class="fas fa-address-card"></i> Contact & Specialty</div>
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px;">
                <tr>
                    <td style="padding: 6px 0; color: #64748b; width: 40%;"><strong>Contact Number:</strong></td>
                    <td style="padding: 6px 0; color: #1e293b; font-weight:600;" id="viewContact">—</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #64748b;"><strong>Specialty:</strong></td>
                    <td style="padding: 6px 0; color: #1e293b; font-weight:600;" id="viewSpecialty">—</td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; color: #64748b;"><strong>Registered At:</strong></td>
                    <td style="padding: 6px 0; color: #1e293b;" id="viewCreatedAt">—</td>
                </tr>
            </table>

            <div class="form-section-title"><i class="fas fa-tasks"></i> Job Orders Overview</div>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:8px;">
                <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:12px; text-align:center;">
                    <div style="font-size:11px; font-weight:700; color:#1d4ed8; text-transform:uppercase;">Assigned Job Orders</div>
                    <div style="font-size:22px; font-weight:800; color:#1e40af; margin-top:4px;" id="viewAssignedCount">0</div>
                </div>
                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px; text-align:center;">
                    <div style="font-size:11px; font-weight:700; color:#15803d; text-transform:uppercase;">Completed Job Orders</div>
                    <div style="font-size:22px; font-weight:800; color:#166534; margin-top:4px;" id="viewCompletedCount">0</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button onclick="closeViewModal()" class="btn-action btn-secondary" style="height:36px; min-width:90px;">Close</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     ADD / EDIT MECHANIC MODAL
     ========================================================================= -->
<div id="addEditModal" class="modal-backdrop">
    <div class="modal-content">
        <form method="POST" id="mechanicForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="formId" value="">
            
            <div class="modal-header">
                <span class="modal-title" id="modalTitle"><i class="fas fa-plus-circle"></i> Add New Mechanic</span>
                <button type="button" onclick="closeAddEditModal()" style="background:none; border:none; cursor:pointer; font-size:20px; color:#94a3b8;">×</button>
            </div>
            <div class="modal-body">
                
                <div class="form-section-title"><i class="fas fa-id-card"></i> Personal Information</div>
                
                <div class="form-field">
                    <label>Mechanic ID <span style="color:#64748b; font-weight:normal;">(Auto-generated)</span></label>
                    <input type="text" id="field_mechanic_id" disabled 
                           style="background:#f8fafc; font-family:monospace; font-weight:800; color:#002F70;" 
                           value="MEC-<?= sprintf('%04d', $next_mech_id) ?>">
                </div>

                <div class="form-grid-2">
                    <div class="form-field">
                        <label>First Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="first_name" id="field_first_name" required placeholder="First name...">
                    </div>
                    <div class="form-field">
                        <label>Middle Name <span style="color:#94a3b8;">(Optional)</span></label>
                        <input type="text" name="middle_name" id="field_middle_name" placeholder="Middle name...">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-field">
                        <label>Last Name <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="last_name" id="field_last_name" required placeholder="Last name...">
                    </div>
                    <div class="form-field">
                        <label>Contact Number <span style="color:#dc2626;">*</span></label>
                        <input type="text" name="contact_no" id="field_contact" required placeholder="e.g. 09171234567...">
                    </div>
                </div>

                <div class="form-section-title"><i class="fas fa-briefcase"></i> Work Information</div>

                <div class="form-grid-2">
                    <div class="form-field">
                        <label>Specialty <span style="color:#dc2626;">*</span></label>
                        <select name="specialization" id="field_specialty" required>
                            <option value="Oil Change">Oil Change</option>
                            <option value="Brake System">Brake System</option>
                            <option value="Air Conditioning">Air Conditioning</option>
                            <option value="Engine Repair">Engine Repair</option>
                            <option value="Electrical">Electrical</option>
                            <option value="Tire Services">Tire Services</option>
                            <option value="General Mechanic" selected>General Mechanic</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>Status <span style="color:#dc2626;">*</span></label>
                        <select name="status" id="field_status" required>
                            <option value="active" selected>Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeAddEditModal()" class="btn-action btn-secondary" style="height:36px; min-width:90px;">Cancel</button>
                <button type="submit" id="btnSave" class="btn-action btn-primary" style="height:36px; min-width:100px;"><i class="fas fa-save"></i> Save</button>
            </div>
        </form>
    </div>
</div>

<script>
// Filter mechanics table by Search, Status, and Specialty
function filterMechanicsTable() {
    const searchVal   = document.getElementById('tableSearch').value.toLowerCase().trim();
    const statusVal   = document.getElementById('statusFilter').value.toLowerCase().trim();
    const specVal     = document.getElementById('specialtyFilter').value.toLowerCase().trim();
    
    const table       = document.getElementById('mechanicsTable');
    const rows        = table.querySelectorAll('tbody tr:not(#noFilterRow):not(#emptyDbRow)');
    let visibleCount  = 0;

    rows.forEach(tr => {
        const txtName    = (tr.querySelector('.mech-name')?.textContent || '').toLowerCase();
        const txtContact = (tr.querySelector('.mech-contact')?.textContent || '').toLowerCase();
        const txtSpec    = (tr.querySelector('.mech-spec')?.textContent || '').toLowerCase();
        const rowStatus  = (tr.getAttribute('data-status') || '').toLowerCase();
        const rowSpec    = (tr.getAttribute('data-specialty') || '').toLowerCase();
        const rowId      = (tr.querySelector('td:first-child')?.textContent || '').toLowerCase();

        const matchesSearch    = !searchVal || (txtName.includes(searchVal) || txtContact.includes(searchVal) || txtSpec.includes(searchVal) || rowId.includes(searchVal));
        const matchesStatus    = !statusVal || rowStatus === statusVal;
        const matchesSpecialty = !specVal || rowSpec === specVal;

        if (matchesSearch && matchesStatus && matchesSpecialty) {
            tr.style.display = "";
            visibleCount++;
        } else {
            tr.style.display = "none";
        }
    });

    const noFilterRow = document.getElementById('noFilterRow');
    if (noFilterRow) {
        noFilterRow.style.display = (visibleCount === 0 && rows.length > 0) ? "" : "none";
    }
}

function resetFilters() {
    document.getElementById('tableSearch').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('specialtyFilter').value = '';
    filterMechanicsTable();
}

// View Modal functions
function openViewModal(mec) {
    const formattedId = "MEC-" + String(mec.id).padStart(4, '0');
    document.getElementById('viewMecId').textContent = formattedId;
    document.getElementById('viewName').textContent = mec.full_name;
    document.getElementById('viewAvatar').textContent = (mec.full_name || 'M').charAt(0).toUpperCase();
    document.getElementById('viewContact').textContent = mec.contact_no || '—';
    document.getElementById('viewSpecialty').textContent = mec.specialization || 'General Mechanic';
    
    const isAct = mec.status === 'active';
    const badgeClass = isAct ? 'badge-active' : 'badge-inactive';
    const statusText = isAct ? 'Active' : 'Inactive';
    const statusIcon = isAct ? 'fa-check-circle' : 'fa-times-circle';
    document.getElementById('viewStatusContainer').innerHTML = `<span class="badge ${badgeClass}"><i class="fas ${statusIcon}"></i> ${statusText}</span>`;
    
    document.getElementById('viewCreatedAt').textContent = mec.created_at || '—';
    document.getElementById('viewAssignedCount').textContent = mec.assigned_jo_count || 0;
    document.getElementById('viewCompletedCount').textContent = mec.completed_jo_count || 0;
    
    document.getElementById('viewModal').style.display = 'flex';
}

function closeViewModal() {
    document.getElementById('viewModal').style.display = 'none';
}

// Add/Edit Modal functions
function openAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Mechanic';
    
    document.getElementById('field_mechanic_id').value = 'MEC-<?= sprintf('%04d', $next_mech_id) ?>';
    document.getElementById('field_first_name').value = '';
    document.getElementById('field_middle_name').value = '';
    document.getElementById('field_last_name').value = '';
    document.getElementById('field_contact').value = '';
    document.getElementById('field_specialty').value = 'General Mechanic';
    document.getElementById('field_status').value = 'active';
    
    document.getElementById('addEditModal').style.display = 'flex';
}

function openEditModal(mec) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = mec.id;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-pen"></i> Edit Mechanic';
    
    const formattedId = "MEC-" + String(mec.id).padStart(4, '0');
    document.getElementById('field_mechanic_id').value = formattedId;
    
    document.getElementById('field_first_name').value = mec.first_name || '';
    document.getElementById('field_middle_name').value = mec.middle_name || '';
    document.getElementById('field_last_name').value = mec.last_name || '';
    document.getElementById('field_contact').value = mec.contact_no || '';
    document.getElementById('field_specialty').value = mec.specialization || 'General Mechanic';
    document.getElementById('field_status').value = mec.status || 'active';
    
    document.getElementById('addEditModal').style.display = 'flex';
}

function closeAddEditModal() {
    document.getElementById('addEditModal').style.display = 'none';
}

// Inline Toggle Status with Deactivate Rule
async function toggleStatus(id, newStatus, assignedJoCount) {
    // Check Deactivate Rule in frontend first
    if (newStatus === 'inactive' && assignedJoCount > 0) {
        alert("Cannot deactivate this mechanic. This mechanic has an active job order assignment.\n\nKinahanglan mahuman o ma-reassign una ang job order.");
        return;
    }

    const label = newStatus === 'inactive' ? 'Deactivate' : 'Activate';
    if (!confirm(`Are you sure you want to ${label.toLowerCase()} this mechanic?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    formData.append('status', newStatus);
    
    try {
        const response = await fetch('manager_mechanics_management.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            window.location.reload();
        } else {
            alert(result.error || 'Failed to update mechanic status.');
        }
    } catch (err) {
        alert('Network error: ' + err.message);
    }
}

// Close modals on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>
</div>
<?php
require_once __DIR__ . '/../partials/footer.php';
?>
