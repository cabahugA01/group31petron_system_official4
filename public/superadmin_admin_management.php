<?php

// ============================================================
// SuperAdmin – Admin Management
// public/superadmin_admin_management.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'admin_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php'); exit;
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// â”€â”€ Fetch all stations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$stations = [];
try {
    $stations = $pdo->query(
        "SELECT id, name, address, location, region, contact_number, status 
         FROM stations 
         ORDER BY name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch stations: " . $e->getMessage());
    $stations = [];
}

// â”€â”€ Fetch unique regions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$regions = [];
try {
    $regions = $pdo->query(
        "SELECT DISTINCT region 
         FROM stations 
         WHERE region IS NOT NULL AND region != '' 
         ORDER BY region"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Failed to fetch regions: " . $e->getMessage());
    $regions = [];
}

// â”€â”€ Fetch admin list â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$admins = [];
try {
    // Fetch all admin accounts
    $admins = $pdo->query(
        "SELECT u.id, u.first_name, u.last_name, u.email, u.phone_number, u.username, u.status, u.station_id, u.created_at, u.role,
                s.name AS station_name, s.region AS region,
                (SELECT MAX(created_at) FROM activity_logs WHERE user_id = u.id AND action = 'Login') AS last_login,
                (SELECT ip_address FROM activity_logs WHERE user_id = u.id AND action = 'Login' ORDER BY created_at DESC LIMIT 1) AS last_login_ip
         FROM users u
         LEFT JOIN stations s ON s.id = u.station_id
         WHERE u.role = 'admin'
         ORDER BY u.first_name, u.last_name"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log error for debugging
    error_log("Admin list fetch error: " . $e->getMessage());
    $admins = [];
}

// â”€â”€ Flash message â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$flash = $_SESSION['admin_mgmt_flash'] ?? null;
unset($_SESSION['admin_mgmt_flash']);

// Prevent caching
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// ── AJAX JSON POLLING ENDPOINT FOR SUPERADMIN ADMIN MANAGEMENT ────────────────
if (isset($_GET['ajax_sam']) && $_GET['ajax_sam'] == '1') {
    header('Content-Type: application/json');
    $total = count($admins);
    $active = count(array_filter($admins, fn($a) => strtolower($a['status']) === 'active'));
    $inactive = $total - $active;
    $stations_covered = count(array_unique(array_filter(array_column($admins, 'station_id'))));
    echo json_encode([
        'success'          => true,
        'admins_count'     => $total,
        'total'            => $total,
        'active'           => $active,
        'inactive'         => $inactive,
        'stations_covered' => $stations_covered
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* â”€â”€ Admin Management Page Styles - Updated v2.1 - June 15, 2026 â”€â”€ */
.am-page { padding: 0 !important; }
.am-page-head { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-top: 0 !important; margin-bottom: 25px !important; padding: 0 !important; border: none !important; width: 100%; }
.am-page-head h1 { margin: 0 !important; color: #002f70 !important; font-size: 24px !important; font-weight: 700 !important; text-transform: uppercase !important; letter-spacing: 0.5px !important; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important; display: flex !important; align-items: center !important; gap: 10px !important; line-height: 1.2 !important; }
.am-page-head .sub { font-size: 13px; color: #666; margin-top: 4px; text-transform: none !important; }

/* Stats row */
.am-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 24px; }
.am-stat-card { background: #fff; border: 1px solid #eaeaea; border-radius: 14px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.am-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.am-stat-icon.blue  { background: rgba(0,38,77,.1);  color: var(--petron-blue); }
.am-stat-icon.green { background: rgba(40,167,69,.1); color: #28a745; }
.am-stat-icon.red   { background: rgba(204,0,0,.1);   color: #cc0000; }
.am-stat-icon.amber { background: rgba(255,193,7,.15); color: #b8860b; }
.am-stat-val  { font-size: 26px; font-weight: 800; color: var(--petron-blue); line-height: 1; }
.am-stat-lbl  { font-size: 12px; color: #666; margin-top: 2px; }

/* Toolbar */
.am-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
.am-toolbar input, .am-toolbar select { padding: 9px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; background: #fff; outline: none; }
.am-toolbar input:focus, .am-toolbar select:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.am-toolbar input { width: 240px; }
.am-toolbar-right { margin-left: auto; }

/* Table */
.am-table-wrap { background: #fff; border: 1px solid #eaeaea; border-radius: 16px; overflow-x: auto; -webkit-overflow-scrolling: touch; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
.am-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.am-table thead th { background: var(--petron-blue); color: #fff; padding: 13px 16px; text-align: left; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
.am-table tbody tr { border-bottom: 1px solid #f0f0f0; transition: background .15s; }
.am-table tbody tr:last-child { border-bottom: none; }
.am-table tbody tr:hover { background: #f8fafc; }
.am-table td { padding: 13px 16px; vertical-align: middle; }
.am-table td .name { font-weight: 600; color: #1a1a1a; }
.am-table td .email { font-size: 12px; color: #666; margin-top: 2px; }

/* Badges */
.badge-active   { background: rgba(40,167,69,.12); color: #1a7a35; border: 1px solid rgba(40,167,69,.25); padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-inactive { background: rgba(204,0,0,.1);    color: #cc0000; border: 1px solid rgba(204,0,0,.2);    padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }

/* Action buttons */
.am-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; border: 1px solid transparent; transition: all .2s; text-decoration: none; }
.am-btn-primary { background: white !important; color: #00264D !important; border: 1px solid #00264D !important; }
.am-btn-primary:hover { background: #00264D !important; color: white !important; }
.am-btn-secondary { background: white !important; color: #00264D !important; border: 1px solid #00264D !important; }
.am-btn-secondary:hover { background: #00264D !important; color: white !important; }
.am-btn-edit { background: white !important; color: #00264D !important; border: 1px solid #00264D !important; }
.am-btn-edit:hover { background: #00264D !important; color: white !important; }
.am-btn-deact { background: white !important; color: #dc2626 !important; border: 1px solid #dc2626 !important; }
.am-btn-deact:hover { background: #dc2626 !important; color: white !important; }
.am-btn-activate { background: white !important; color: #16a34a !important; border: 1px solid #16a34a !important; }
.am-btn-activate:hover { background: #16a34a !important; color: white !important; }

/* Modal */
.am-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9000; align-items: center; justify-content: center; padding: 20px; }
.am-modal-overlay.open { display: flex; }
.am-modal { background: #fff; border-radius: 20px; width: min(560px, 95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.2); animation: amSlideIn .25s ease; position: relative; }
@keyframes amSlideIn { from { opacity:0; transform:translateY(-20px); } to { opacity:1; transform:translateY(0); } }
.am-modal-header { padding: 20px 24px 16px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: #fff; z-index: 20; }
.am-modal-header h2 { font-size: 17px !important; font-weight: 700 !important; color: var(--petron-blue) !important; margin: 0 !important; text-transform: uppercase !important; }
.am-modal-close { display: none !important; }
.am-modal-body { padding: 22px 24px 12px; }
.am-modal .am-combo-dropdown { position: relative !important; top: 6px !important; left: 0 !important; right: 0 !important; width: 100% !important; max-height: 220px !important; margin-bottom: 12px !important; z-index: 5 !important; }
.am-modal-footer { padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; position: sticky; bottom: 0; background: #fff; z-index: 20; box-shadow: 0 -4px 12px rgba(0,0,0,0.03); }

/* Cancel & Primary Button Styling - High Contrast Visibility */
.am-btn-cancel,
.am-modal-footer button:not(.am-btn-primary):not(.am-btn-export-excel):not(.am-btn-export-pdf) {
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #00264D !important;
    -webkit-text-fill-color: #00264D !important;
    border: 1.5px solid #00264D !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 8px 22px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    opacity: 1 !important;
    visibility: visible !important;
    transition: all .15s ease !important;
}
.am-btn-cancel:hover,
.am-modal-footer button:not(.am-btn-primary):not(.am-btn-export-excel):not(.am-btn-export-pdf):hover {
    background: #f0f4f8 !important;
    background-color: #f0f4f8 !important;
    color: #001a33 !important;
    -webkit-text-fill-color: #001a33 !important;
    border-color: #001a33 !important;
}

.am-btn-primary,
.am-modal-footer button.am-btn-primary {
    background: #00264D !important;
    background-color: #00264D !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.5px solid #00264D !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    padding: 8px 22px !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    transition: all .15s ease !important;
}
.am-btn-primary:hover,
.am-modal-footer button.am-btn-primary:hover {
    background: #001a33 !important;
    background-color: #001a33 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* Form */
.am-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
.am-form-row.full { grid-template-columns: 1fr; }
.am-form-group { display: flex; flex-direction: column; gap: 5px; }
.am-form-group label { font-size: 12px; font-weight: 600; color: #444; text-transform: uppercase; letter-spacing: .3px; }
.am-form-group input, .am-form-group select { padding: 10px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .2s; }
.am-form-group input:focus, .am-form-group select:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.am-form-hint { font-size: 11px; color: #888; margin-top: 2px; }

/* Toolbar combo variant — matches toolbar height */
.am-combo-toolbar .am-combo-input { padding-top: 9px; padding-bottom: 9px; font-size: 13px; }
.am-combo { position: relative; }
.am-combo-input {
    width: 100% !important;
    padding: 10px 65px 10px 13px !important;
    border: 1px solid #ddd;
    border-radius: 10px;
    font-size: 13px;
    outline: none;
    transition: border-color .2s;
    background: #fff;
    box-sizing: border-box;
    cursor: text;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    overflow: hidden !important;
}
.am-combo-input:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
.am-combo-input.has-value { border-color: var(--petron-blue); }
.am-combo-arrow { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 12px; pointer-events: none; transition: transform .2s; z-index: 2; }
.am-combo.open .am-combo-arrow { transform: translateY(-50%) rotate(180deg); }
.am-combo-clear,
button.am-combo-clear {
    position: absolute !important;
    right: 32px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    color: #94a3b8 !important;
    font-size: 13px !important;
    cursor: pointer !important;
    display: none;
    background: transparent !important;
    background-color: transparent !important;
    border: none !important;
    box-shadow: none !important;
    outline: none !important;
    padding: 3px 6px !important;
    margin: 0 !important;
    line-height: 1 !important;
    z-index: 5 !important;
    width: auto !important;
    height: auto !important;
    border-radius: 4px !important;
}
.am-combo-clear i,
button.am-combo-clear i {
    color: #94a3b8 !important;
    font-size: 13px !important;
    transition: color 0.15s ease !important;
}
.am-combo-clear:hover i,
button.am-combo-clear:hover i {
    color: #dc2626 !important;
}
.am-combo-dropdown { display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: #fff; border: 1px solid #ddd; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 9999; max-height: 220px; overflow: hidden; flex-direction: column; }
.am-combo.open .am-combo-dropdown { display: flex; }
.am-combo-search { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.am-combo-search i { color: #bbb; font-size: 13px; }
.am-combo-search input { border: none; outline: none; font-size: 13px; flex: 1; background: transparent; }
.am-combo-list { overflow-y: auto; flex: 1; }
.am-combo-option { padding: 10px 14px; font-size: 13px; cursor: pointer; transition: background .12s; display: flex; align-items: flex-start; gap: 8px; }
.am-combo-option:hover, .am-combo-option.focused { background: #f0f5ff; color: var(--petron-blue); }
.am-combo-option.selected { background: rgba(0,38,77,.08); font-weight: 600; color: var(--petron-blue); }
.am-combo-option .opt-icon { color: #bbb; font-size: 11px; flex-shrink: 0; }
.am-combo-option.selected .opt-icon { color: var(--petron-blue); }
.am-combo-empty { padding: 18px 14px; font-size: 13px; color: #bbb; text-align: center; }
.am-combo-hidden { display: none !important; }

/* Flash */
.am-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.am-flash.success { background: rgba(40,167,69,.1); border: 1px solid rgba(40,167,69,.3); color: #1a7a35; }
.am-flash.error   { background: rgba(204,0,0,.08);  border: 1px solid rgba(204,0,0,.25);  color: #cc0000; }

/* Empty state */
.am-empty { text-align: center; padding: 60px 20px; color: #999; }
.am-empty i { font-size: 40px; margin-bottom: 12px; opacity: .4; display: block; }

/* Confirm modal */
.am-confirm-body { padding: 28px 24px; text-align: center; }
.am-confirm-body i { font-size: 44px; margin-bottom: 14px; display: block; }
.am-confirm-body p { font-size: 15px; color: #333; margin: 0 0 6px; }
.am-confirm-body .sub { font-size: 13px; color: #888; }

@media (max-width: 640px) {
    .am-form-row { grid-template-columns: 1fr; }
    .am-toolbar input { width: 100%; }
    .am-table thead th:nth-child(4), .am-table td:nth-child(4),
    .am-table thead th:nth-child(7), .am-table td:nth-child(7) { display: none; }
}



/* Footer and toggle scroll button styles are provided by partials/footer.php */
</style>

<div class="am-page">

<?php if ($flash): ?>
<div class="am-flash <?php echo $flash['type'] === 'success' ? 'success' : 'error'; ?>">
    <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
    <?php echo htmlspecialchars($flash['msg']); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="am-page-head">
    <div>
        <h1><i class="fas fa-user-shield" style="margin-right:8px;"></i>Admin Management</h1>
    </div>
    <div style="display:flex;gap:10px;">
        <a href="superadmin_admin_map.php" class="am-btn am-btn-secondary" style="text-decoration:none;">
            <i class="fas fa-map-marked-alt"></i> Map View
        </a>
        <button class="am-btn am-btn-primary" onclick="openAddStationModal()" title="Add New Station">
            <i class="fas fa-plus"></i> Add Station
        </button>
        <button class="am-btn am-btn-primary" onclick="openCreateModal()">
            <i class="fas fa-plus"></i> Create Admin Account
        </button>
    </div>
</div>



<?php
// â”€â”€ Compute stats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$total   = count($admins);
$active  = count(array_filter($admins, fn($a) => strtolower($a['status']) === 'active'));
$inactive = $total - $active;
$stations_covered = count(array_unique(array_filter(array_column($admins, 'station_id'))));
?>

<!-- Stats -->
<div class="am-stats">
    <div class="am-stat-card">
        <div class="am-stat-icon blue"><i class="fas fa-users"></i></div>
        <div><div class="am-stat-val"><?php echo $total; ?></div><div class="am-stat-lbl">Total Admins</div></div>
    </div>
    <div class="am-stat-card">
        <div class="am-stat-icon green"><i class="fas fa-user-check"></i></div>
        <div><div class="am-stat-val"><?php echo $active; ?></div><div class="am-stat-lbl">Active</div></div>
    </div>
    <div class="am-stat-card">
        <div class="am-stat-icon red"><i class="fas fa-user-slash"></i></div>
        <div><div class="am-stat-val"><?php echo $inactive; ?></div><div class="am-stat-lbl">Inactive</div></div>
    </div>
    <div class="am-stat-card">
        <div class="am-stat-icon amber"><i class="fas fa-building"></i></div>
        <div><div class="am-stat-val"><?php echo $stations_covered; ?></div><div class="am-stat-lbl">Stations Covered</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="am-toolbar">
    <input type="text" id="adminSearchInput" placeholder="Search by first name, last name, email or station..." oninput="filterTable()">
    <select id="filterStatus" onchange="filterTable()">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
    </select>
    <select id="filterRegion" onchange="filterTable()">
        <option value="">All Regions</option>
        <?php foreach ($regions as $reg): ?>
        <option value="<?php echo htmlspecialchars(strtolower($reg)); ?>"><?php echo htmlspecialchars($reg); ?></option>
        <?php endforeach; ?>
    </select>
    <!-- Searchable station filter -->
    <div class="am-combo am-combo-toolbar" id="tb_station_combo" style="width:280px; position: relative; z-index: 100;">
        <input type="text" class="am-combo-input" id="tb_station_display" placeholder="Type to search stations..." autocomplete="off" style="padding-right:45px; cursor: text;">
        <button type="button" class="am-combo-clear" id="tb_station_clear" tabindex="-1" title="Clear filter"><i class="fas fa-times"></i></button>
        <i class="fas fa-chevron-down am-combo-arrow"></i>
        <input type="hidden" id="tb_station_val">
        <div class="am-combo-dropdown" id="tb_station_dropdown" style="position: fixed; z-index: 99999;">
            <div class="am-combo-list" id="tb_station_list"></div>
        </div>
    </div>
    <div class="am-toolbar-right">
        <span id="rowCount" style="font-size:12px;color:#888;"></span>
    </div>
</div>

<!-- Table -->
<div class="am-table-wrap">
    <table class="am-table" id="adminTable">
        <thead>
            <tr>
                <th>#</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Email</th>
                <th>Station</th>
                <th>Status</th>
                <th>Last Login</th>
                <th style="text-align:center;">Actions</th>
            </tr>
        </thead>
        <tbody id="adminTableBody">
        <?php if (empty($admins)): ?>
            <tr><td colspan="8"><div class="am-empty"><i class="fas fa-user-shield"></i>No admin accounts found.</div></td></tr>
        <?php else: ?>
        <?php foreach ($admins as $i => $adm): 
            // Parse first and last name from name field or use separate fields if available
            $first_name = $adm['first_name'] ?? '';
            $last_name = $adm['last_name'] ?? '';
            if (empty($first_name) && !empty($adm['name'])) {
                $name_parts = explode(' ', trim($adm['name']));
                if (count($name_parts) > 1) {
                    $last_name = array_pop($name_parts);
                    $first_name = implode(' ', $name_parts);
                } else {
                    $first_name = $adm['name'];
                }
            }
        ?>
        <tr data-firstname="<?php echo strtolower(htmlspecialchars($first_name)); ?>"
            data-lastname="<?php echo strtolower(htmlspecialchars($last_name)); ?>"
            data-email="<?php echo strtolower(htmlspecialchars($adm['email'] ?? '')); ?>"
            data-station="<?php echo htmlspecialchars($adm['station_name'] ?? ''); ?>"
            data-region="<?php echo strtolower(htmlspecialchars($adm['region'] ?? '')); ?>"
            data-status="<?php echo strtolower($adm['status']); ?>">
            <td style="color:#999;font-size:12px;"><?php echo $i + 1; ?></td>
            <td style="font-weight:600;color:#1a1a1a;"><?php echo htmlspecialchars($first_name); ?></td>
            <td style="font-weight:600;color:#1a1a1a;"><?php echo htmlspecialchars($last_name); ?></td>
            <td style="font-size:13px;color:#666;"><?php echo htmlspecialchars($adm['email'] ?? '—'); ?></td>
            <td>
                <?php if ($adm['station_name']): ?>
                <div style="font-size:13px;">
                    <div style="font-weight:600;"><i class="fas fa-building" style="color:#999;font-size:11px;margin-right:4px;"></i><?php echo htmlspecialchars($adm['station_name']); ?></div>
                    <?php if (!empty($adm['region'])): ?>
                    <div style="font-size:11px;color:#888;margin-top:2px;margin-left:15px;"><i class="fas fa-globe-asia" style="font-size:10px;margin-right:3px;"></i><?php echo htmlspecialchars($adm['region']); ?></div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <span style="color:#bbb;font-size:12px;">Unassigned</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (strtolower($adm['status']) === 'active'): ?>
                <span class="badge-active"><i class="fas fa-circle" style="font-size:7px;"></i> Active</span>
                <?php else: ?>
                <span class="badge-inactive"><i class="fas fa-circle" style="font-size:7px;"></i> Inactive</span>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#666;line-height:1.4;">
                <?php if ($adm['last_login']): ?>
                    <div><?php echo date('M d, Y g:i A', strtotime($adm['last_login'])); ?></div>
                    <?php if (!empty($adm['last_login_ip'])): ?>
                        <div style="font-size:11px;color:#999;font-family:monospace;">
                            <i class="fas fa-desktop" style="font-size:10px;margin-right:3px;"></i><?php echo htmlspecialchars($adm['last_login_ip']); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:#bbb;">Never</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;white-space:nowrap;">
                <?php 
                $admin_user_id = $adm['id'] ?? 0;
                $admin_display_name = $first_name . ' ' . $last_name;
                ?>
                <button class="am-btn am-btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($adm)); ?>)" title="Edit">
                    <i class="fas fa-pen"></i> Edit
                </button>
                <?php if (strtolower($adm['status']) === 'active'): ?>
                <button class="am-btn am-btn-deact" onclick="confirmDeactivate(<?php echo (int)$admin_user_id; ?>, '<?php echo htmlspecialchars(addslashes($admin_display_name)); ?>')" title="Deactivate">
                    <i class="fas fa-ban"></i> Deactivate
                </button>
                <?php else: ?>
                <button class="am-btn am-btn-activate" onclick="confirmActivate(<?php echo (int)$admin_user_id; ?>, '<?php echo htmlspecialchars(addslashes($admin_display_name)); ?>')" title="Activate">
                    <i class="fas fa-check-circle"></i> Activate
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</div><!-- /.am-page -->

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     CREATE MODAL
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="am-modal-overlay" id="createModal">
  <div class="am-modal">
    <div class="am-modal-header">
      <h2><i class="fas fa-user-plus" style="margin-right:8px;"></i>Create Admin Account</h2>
      <button class="am-modal-close" onclick="closeModal('createModal')">&times;</button>
    </div>
    <form id="createForm" onsubmit="submitCreate(event)">
      <div class="am-modal-body">
        <div id="createAlert" style="display:none;" class="am-flash error"></div>

        <div class="am-form-row">
          <div class="am-form-group">
            <label>First Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="first_name" id="c_first_name" placeholder="e.g. Juan" required>
          </div>
          <div class="am-form-group">
            <label>Last Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="last_name" id="c_last_name" placeholder="e.g. Dela Cruz" required>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Email Address <span style="color:#cc0000;">*</span></label>
            <input type="email" name="email" id="c_email" placeholder="admin@petron.com" required>
            <span class="am-form-hint">Credentials will be sent to this email. Admin will use this to login.</span>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Station Assignment <span style="color:#cc0000;">*</span></label>
            <div class="am-combo" id="c_station_combo">
              <input type="text" class="am-combo-input" id="c_station_display" placeholder="Select a station—¦" autocomplete="off" readonly>
              <button type="button" class="am-combo-clear" id="c_station_clear" tabindex="-1" title="Clear"><i class="fas fa-times"></i></button>
              <i class="fas fa-chevron-down am-combo-arrow"></i>
              <input type="hidden" name="station_id" id="c_station_id" required>
              <div class="am-combo-dropdown" id="c_station_dropdown">
                <div class="am-combo-search">
                  <i class="fas fa-search"></i>
                  <input type="text" id="c_station_search" placeholder="Search station—¦" autocomplete="off">
                </div>
                <div class="am-combo-list" id="c_station_list">
                  <div class="am-combo-option" data-value="" data-label="— Select Station —" style="color:#bbb;">— Select Station —</div>
                  <?php foreach ($stations as $st): ?>
                  <div class="am-combo-option" data-value="<?php echo (int)$st['id']; ?>" data-label="<?php echo htmlspecialchars($st['name']); ?>">
                    <i class="fas fa-building opt-icon" style="margin-top:2px;"></i>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                      <div style="font-weight:600;color:#1a1a1a;"><?php echo htmlspecialchars($st['name']); ?> <span style="font-weight:normal;color:#888;font-size:11px;">(ID: <?php echo (int)$st['id']; ?>)</span></div>
                      <div style="font-size:11px;color:#777;line-height:1.3;">
                        <?php if(!empty($st['address'])): ?>
                          <i class="fas fa-map-marker-alt" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['address']); ?>
                        <?php elseif(!empty($st['location'])): ?>
                          <i class="fas fa-map-marker-alt" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['location']); ?>
                        <?php endif; ?>
                        <?php if(!empty($st['region'])): ?>
                          &nbsp;|&nbsp; Region: <?php echo htmlspecialchars($st['region']); ?>
                        <?php endif; ?>
                        <?php if(!empty($st['contact_number'])): ?>
                          &nbsp;|&nbsp; <i class="fas fa-phone" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['contact_number']); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="am-modal-footer">
        <button type="button" class="am-btn" style="border-color:#ddd;" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="am-btn am-btn-primary" id="createSubmitBtn">
          <i class="fas fa-user-plus"></i> Create Admin
        </button>
      </div>
    </form>
  </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     EDIT MODAL
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="am-modal-overlay" id="editModal">
  <div class="am-modal">
    <div class="am-modal-header">
      <h2><i class="fas fa-user-edit" style="margin-right:8px;"></i>Edit Admin Account</h2>
      <button class="am-modal-close" onclick="closeModal('editModal')">&times;</button>
    </div>
    <form id="editForm" onsubmit="submitEdit(event)">
      <input type="hidden" name="admin_id" id="e_admin_id">
      <div class="am-modal-body">
        <div id="editAlert" style="display:none;" class="am-flash error"></div>

        <div class="am-form-row">
          <div class="am-form-group">
            <label>First Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="first_name" id="e_first_name" placeholder="e.g. Juan" required>
          </div>
          <div class="am-form-group">
            <label>Last Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="last_name" id="e_last_name" placeholder="e.g. Dela Cruz" required>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Email Address</label>
            <input type="text" id="e_email_display" readonly style="background:#f5f5f5;color:#888;cursor:not-allowed;border-color:#e0e0e0;">
            <span class="am-form-hint"><i class="fas fa-lock" style="font-size:10px;"></i> Email address is fixed and cannot be changed. Admin uses this to login.</span>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Station Assignment <span style="color:#cc0000;">*</span></label>
            <div class="am-combo" id="e_station_combo">
              <input type="text" class="am-combo-input" id="e_station_display" placeholder="Type to search station—¦" autocomplete="off" readonly>
              <button type="button" class="am-combo-clear" id="e_station_clear" tabindex="-1" title="Clear"><i class="fas fa-times"></i></button>
              <i class="fas fa-chevron-down am-combo-arrow"></i>
              <input type="hidden" name="station_id" id="e_station_id" required>
              <div class="am-combo-dropdown" id="e_station_dropdown">
                <div class="am-combo-search">
                  <i class="fas fa-search"></i>
                  <input type="text" id="e_station_search" placeholder="Search station—¦" autocomplete="off">
                </div>
                <div class="am-combo-list" id="e_station_list">
                  <div class="am-combo-option" data-value="" data-label="— Select Station —" style="color:#bbb;">— Select Station —</div>
                  <?php foreach ($stations as $st): ?>
                  <div class="am-combo-option" data-value="<?php echo (int)$st['id']; ?>" data-label="<?php echo htmlspecialchars($st['name']); ?>">
                    <i class="fas fa-building opt-icon" style="margin-top:2px;"></i>
                    <div style="display:flex;flex-direction:column;gap:2px;">
                      <div style="font-weight:600;color:#1a1a1a;"><?php echo htmlspecialchars($st['name']); ?> <span style="font-weight:normal;color:#888;font-size:11px;">(ID: <?php echo (int)$st['id']; ?>)</span></div>
                      <div style="font-size:11px;color:#777;line-height:1.3;">
                        <?php if(!empty($st['address'])): ?>
                          <i class="fas fa-map-marker-alt" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['address']); ?>
                        <?php elseif(!empty($st['location'])): ?>
                          <i class="fas fa-map-marker-alt" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['location']); ?>
                        <?php endif; ?>
                        <?php if(!empty($st['region'])): ?>
                          &nbsp;|&nbsp; Region: <?php echo htmlspecialchars($st['region']); ?>
                        <?php endif; ?>
                        <?php if(!empty($st['contact_number'])): ?>
                          &nbsp;|&nbsp; <i class="fas fa-phone" style="font-size:9px;margin-right:2px;"></i><?php echo htmlspecialchars($st['contact_number']); ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Account Status</label>
            <select name="status" id="e_status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="am-modal-footer">
        <button type="button" class="am-btn" style="border-color:#ddd;" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="am-btn am-btn-primary" id="editSubmitBtn">
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     CONFIRM DEACTIVATE / ACTIVATE MODAL
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="am-modal-overlay" id="confirmModal">
  <div class="am-modal" style="max-width:420px;">
    <div class="am-modal-header">
      <h2 id="confirmTitle">Confirm Action</h2>
      <button class="am-modal-close" onclick="closeModal('confirmModal')">&times;</button>
    </div>
    <div class="am-confirm-body">
      <i id="confirmIcon" class="fas fa-ban" style="color:#cc0000;"></i>
      <p id="confirmMsg">Are you sure?</p>
      <p class="sub" id="confirmSub"></p>
    </div>
    <div class="am-modal-footer">
      <button type="button" class="am-btn" style="border-color:#ddd;" onclick="closeModal('confirmModal')">Cancel</button>
      <button type="button" class="am-btn" id="confirmActionBtn" onclick="executeStatusChange()">Confirm</button>
    </div>
  </div>
</div>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     ADD STATION MODAL
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="am-modal-overlay" id="addStationModal">
  <div class="am-modal">
    <div class="am-modal-header">
      <h2><i class="fas fa-building" style="margin-right:8px;"></i>Add New Station</h2>
      <button class="am-modal-close" onclick="closeModal('addStationModal')">&times;</button>
    </div>
    <form id="addStationForm" onsubmit="submitAddStation(event)">
      <div class="am-modal-body">
        <div id="addStationAlert" style="display:none;" class="am-flash error"></div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Station Name <span style="color:#cc0000;">*</span></label>
            <input type="text" name="station_name" id="station_name" placeholder="e.g. Petron Quezon City - Commonwealth Ave" required>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Complete Address <span style="color:#cc0000;">*</span></label>
            <textarea name="location" id="station_location" rows="3"
              placeholder="e.g. Commonwealth Ave., Quezon City, Metro Manila"
              required
              style="padding:10px 13px;border:1px solid #ddd;border-radius:10px;font-size:13px;outline:none;resize:vertical;font-family:inherit;transition:border-color .2s;"
              onfocus="this.style.borderColor='var(--petron-blue)'"
              onblur="this.style.borderColor='#ddd'"></textarea>
          </div>
        </div>

        <div class="am-form-row">
          <div class="am-form-group">
            <label>Region <span style="color:#cc0000;">*</span></label>
            <select name="region" id="station_region" required
              style="padding:10px 13px;border:1px solid #ddd;border-radius:10px;font-size:13px;outline:none;background:#fff;transition:border-color .2s;"
              onfocus="this.style.borderColor='var(--petron-blue)'"
              onblur="this.style.borderColor='#ddd'">
              <option value="">— Select Region —</option>
              <option value="NCR">NCR (National Capital Region)</option>
              <option value="CAR">CAR (Cordillera Administrative Region)</option>
              <option value="Region I">Region I (Ilocos Region)</option>
              <option value="Region II">Region II (Cagayan Valley)</option>
              <option value="Region III">Region III (Central Luzon)</option>
              <option value="Region IV-A">Region IV-A (CALABARZON)</option>
              <option value="Region IV-B">Region IV-B (MIMAROPA)</option>
              <option value="Region V">Region V (Bicol Region)</option>
              <option value="Region VI">Region VI (Western Visayas)</option>
              <option value="Region VII">Region VII (Central Visayas)</option>
              <option value="Region VIII">Region VIII (Eastern Visayas)</option>
              <option value="Region IX">Region IX (Zamboanga Peninsula)</option>
              <option value="Region X">Region X (Northern Mindanao)</option>
              <option value="Region XI">Region XI (Davao Region)</option>
              <option value="Region XII">Region XII (SOCCSKSARGEN)</option>
              <option value="Region XIII">Region XIII (Caraga)</option>
              <option value="BARMM">BARMM (Bangsamoro Autonomous Region)</option>
            </select>
          </div>
          <div class="am-form-group">
            <label>Outlet Type</label>
            <select name="outlet_type" id="station_outlet_type"
              style="padding:10px 13px;border:1px solid #ddd;border-radius:10px;font-size:13px;outline:none;background:#fff;transition:border-color .2s;"
              onfocus="this.style.borderColor='var(--petron-blue)'"
              onblur="this.style.borderColor='#ddd'">
              <option value="SERVICE STATION">Service Station</option>
              <option value="TREATS STORE">Treats Store</option>
              <option value="CAR CARE CENTER">Car Care Center</option>
            </select>
          </div>
        </div>

        <div class="am-form-row full">
          <div class="am-form-group">
            <label>Contact Number</label>
            <input type="text" name="contact" id="station_contact" placeholder="e.g. (02) 1234-5678 or 09XX-XXX-XXXX">
          </div>
        </div>

        <div style="background:#f0f9f0;border:1px solid #c6e6c6;border-radius:10px;padding:14px 16px;margin-top:6px;font-size:12px;color:#1a5c1a;">
          <i class="fas fa-check-circle" style="color:#16a34a;margin-right:6px;"></i>
          The new station will appear in the station dropdown <strong>immediately</strong> after creation and will be available for admin assignment.
        </div>
      </div>
      <div class="am-modal-footer">
        <button type="button" class="am-btn" style="border-color:#ddd;" onclick="closeModal('addStationModal')">Cancel</button>
        <button type="submit" class="am-btn am-btn-primary" id="addStationSubmitBtn">
          <i class="fas fa-building"></i> Create Station
        </button>
      </div>
    </form>
  </div>
</div>


<script>
const STATION_DATA = <?php echo json_encode(array_map(function($s) {
    return ['id' => (int)$s['id'], 'name' => $s['name']];
}, $stations), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

function initVirtualStationFilter() {
    const combo   = document.getElementById('tb_station_combo');
    const list    = document.getElementById('tb_station_list');
    const display = document.getElementById('tb_station_display');
    const hidden  = document.getElementById('tb_station_val');
    const clear   = document.getElementById('tb_station_clear');
    if (!combo || !display || !list || !hidden || !clear) return;

    const MAX = 50;
    let currentVal = '';
    let currentLabel = 'All Stations';

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function render(q) {
        const lq = (q || '').toLowerCase().trim();
        list.innerHTML = '';

        if (!lq) {
            const all = document.createElement('div');
            all.className = 'am-combo-option' + (!currentVal ? ' selected' : '');
            all.dataset.value = '';
            all.dataset.label = 'All Stations';
            all.style.cssText = 'font-style:italic;color:#888;';
            all.textContent   = 'All Stations';
            list.appendChild(all);
        }

        const filtered = lq
            ? STATION_DATA.filter(s => s.name.toLowerCase().includes(lq))
            : STATION_DATA;

        function appendMore() {
            const currentCount = list.querySelectorAll('.am-combo-option[data-value]').length;
            const batch = filtered.slice(currentCount, currentCount + 100);
            batch.forEach(s => {
                const div = document.createElement('div');
                div.className    = 'am-combo-option' + (currentVal === s.name ? ' selected' : '');
                div.dataset.value = s.name;
                div.dataset.label = s.name;
                div.innerHTML    = '<i class="fas fa-building opt-icon"></i> ' + esc(s.name);
                list.appendChild(div);
            });
        }

        appendMore();

        list.onscroll = () => {
            if (list.scrollTop + list.clientHeight >= list.scrollHeight - 50) {
                appendMore();
            }
        };

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className  = 'am-combo-empty';
            empty.textContent = `No station matching "${q}"`;
            list.appendChild(empty);
        }
    }

    function pick(value, label) {
        currentVal    = value;
        currentLabel  = value ? label : 'All Stations';
        hidden.value  = value;
        display.value = value ? label : 'All Stations';
        display.classList.toggle('has-value', !!value);
        clear.style.display = value ? 'block' : 'none';
        combo.classList.remove('open');
        filterTable();
    }

    function open() {
        combo.classList.add('open');
        display.value = '';
        const dd = combo.querySelector('.am-combo-dropdown');
        if (dd) {
            const rect = combo.getBoundingClientRect();
            dd.style.left  = rect.left + 'px';
            dd.style.top   = (rect.bottom + 4) + 'px';
            dd.style.width = rect.width + 'px';
        }
        render('');
    }

    function close() {
        combo.classList.remove('open');
        display.value = currentVal ? currentLabel : 'All Stations';
    }

    display.addEventListener('click', () => combo.classList.contains('open') ? close() : open());
    display.addEventListener('focus', () => { if (!combo.classList.contains('open')) open(); });

    let dbt;
    display.addEventListener('input', () => {
        if (!combo.classList.contains('open')) combo.classList.add('open');
        clearTimeout(dbt);
        dbt = setTimeout(() => render(display.value), 130);
    });

    display.addEventListener('keydown', e => {
        if (!combo.classList.contains('open') && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            e.preventDefault();
            open();
            return;
        }
        const opts = [...list.querySelectorAll('.am-combo-option[data-value]')];
        const foc  = list.querySelector('.am-combo-option.focused');
        let idx    = foc ? opts.indexOf(foc) : -1;
        if      (e.key === 'ArrowDown')           { e.preventDefault(); idx = Math.min(idx + 1, opts.length - 1); }
        else if (e.key === 'ArrowUp')             { e.preventDefault(); idx = Math.max(idx - 1, 0); }
        else if (e.key === 'Enter' && foc)        { e.preventDefault(); pick(foc.dataset.value, foc.dataset.label); return; }
        else if (e.key === 'Escape')              { close(); return; }
        else                                      { return; }
        opts.forEach(o => o.classList.remove('focused'));
        if (opts[idx]) { opts[idx].classList.add('focused'); opts[idx].scrollIntoView({ block: 'nearest' }); }
    });

    list.addEventListener('click', e => {
        const opt = e.target.closest('.am-combo-option');
        if (opt) pick(opt.dataset.value, opt.dataset.label);
    });

    list.addEventListener('mouseover', e => {
        const opt = e.target.closest('.am-combo-option');
        if (opt) {
            list.querySelectorAll('.am-combo-option').forEach(o => o.classList.remove('focused'));
            opt.classList.add('focused');
        }
    });

    clear.addEventListener('click', e => { e.stopPropagation(); pick('', ''); });

    document.addEventListener('click', e => { if (!combo.contains(e.target)) close(); });

    pick('', '');
}

// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
// Searchable Station Combobox
// â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
function initCombo(comboId, searchId, listId, displayId, hiddenId, clearId, onChange) {
    const combo   = document.getElementById(comboId);
    const search  = document.getElementById(searchId);
    const list    = document.getElementById(listId);
    const display = document.getElementById(displayId);
    const hidden  = document.getElementById(hiddenId);
    const clear   = document.getElementById(clearId);
    if (!combo) return;

    function openCombo() {
        combo.classList.add('open');
        search.value = '';
        filterOptions('');
        search.focus();
        const modal = combo.closest('.am-modal');
        if (modal) {
            setTimeout(() => {
                combo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 60);
        }
    }
    function closeCombo() {
        combo.classList.remove('open');
    }
    function selectOption(value, label) {
        hidden.value  = value;
        display.value = value ? label : '';
        display.classList.toggle('has-value', !!value);
        clear.style.display = value ? 'block' : 'none';
        // mark selected
        list.querySelectorAll('.am-combo-option').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
        closeCombo();
        if (typeof onChange === 'function') onChange();
    }
    function filterOptions(q) {
        const lq = q.toLowerCase().trim();
        let any = false;
        list.querySelectorAll('.am-combo-option').forEach(o => {
            if (!o.dataset.value) { o.style.display = lq ? 'none' : ''; return; } // placeholder
            const match = !lq || o.dataset.label.toLowerCase().includes(lq) || (o.textContent || '').toLowerCase().includes(lq);
            o.style.display = match ? '' : 'none';
            if (match) any = true;
        });
        let empty = list.querySelector('.am-combo-empty');
        if (!any && lq) {
            if (!empty) { empty = document.createElement('div'); empty.className = 'am-combo-empty'; list.appendChild(empty); }
            empty.textContent = `No station matching "${q}"`;
            empty.style.display = '';
        } else if (empty) {
            empty.style.display = 'none';
        }
    }

    // Toggle open on display click
    display.addEventListener('click', () => combo.classList.contains('open') ? closeCombo() : openCombo());

    // Live filter as user types in search box
    search.addEventListener('input', () => filterOptions(search.value));

    // Keyboard nav in search
    search.addEventListener('keydown', e => {
        const visible = [...list.querySelectorAll('.am-combo-option[data-value]:not([style*="display: none"])')];
        const focused = list.querySelector('.am-combo-option.focused');
        let idx = focused ? visible.indexOf(focused) : -1;
        if (e.key === 'ArrowDown') { e.preventDefault(); idx = Math.min(idx + 1, visible.length - 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); idx = Math.max(idx - 1, 0); }
        else if (e.key === 'Enter' && focused) { e.preventDefault(); selectOption(focused.dataset.value, focused.dataset.label); return; }
        else if (e.key === 'Escape') { closeCombo(); return; }
        list.querySelectorAll('.am-combo-option').forEach(o => o.classList.remove('focused'));
        if (visible[idx]) { visible[idx].classList.add('focused'); visible[idx].scrollIntoView({ block: 'nearest' }); }
    });

    // Click option
    list.addEventListener('click', e => {
        const opt = e.target.closest('.am-combo-option');
        if (opt) selectOption(opt.dataset.value, opt.dataset.label);
    });

    // Clear button
    clear.addEventListener('click', e => { e.stopPropagation(); selectOption('', ''); });

    // Close on outside click
    document.addEventListener('click', e => {
        if (!combo.contains(e.target)) closeCombo();
    });

    // Expose setter for programmatic use
    combo._setValue = (value, label) => selectOption(value, label);
    combo._reset    = () => selectOption('', '');
}

document.addEventListener('DOMContentLoaded', () => {
    initCombo('c_station_combo', 'c_station_search', 'c_station_list', 'c_station_display', 'c_station_id', 'c_station_clear');
    initCombo('e_station_combo', 'e_station_search', 'e_station_list', 'e_station_display', 'e_station_id', 'e_station_clear');
    // Toolbar station filter using the new virtual/searchable combobox
    initVirtualStationFilter();

    // Listen to adminSearchInput
    const searchEl = document.getElementById('adminSearchInput');
    if (searchEl) {
        searchEl.addEventListener('input', filterTable);
    }

    // Connect top header search if user types into it
    const headerSearch = document.getElementById('searchInput');
    if (headerSearch && headerSearch !== searchEl) {
        headerSearch.addEventListener('input', () => {
            if (searchEl) searchEl.value = headerSearch.value;
            filterTable();
        });
    }

    filterTable();
});

// ── Table filter ─────────────────────────────────────────────────────────────
function filterTable() {
    const searchEl = document.getElementById('adminSearchInput') || document.querySelector('.am-toolbar input[type="text"]');
    const q       = searchEl ? searchEl.value.toLowerCase().trim() : '';
    const status  = (document.getElementById('filterStatus') ? document.getElementById('filterStatus').value : '').toLowerCase();
    const region  = (document.getElementById('filterRegion') ? document.getElementById('filterRegion').value : '').toLowerCase();
    const station = (document.getElementById('tb_station_val') ? document.getElementById('tb_station_val').value : '').toLowerCase();
    const rows    = document.querySelectorAll('#adminTableBody tr[data-firstname]');
    const tbody   = document.getElementById('adminTableBody');
    let visible   = 0;

    // Split search query into individual search tokens for flexible multi-word matching
    const tokens = q ? q.split(/\s+/).filter(t => t.length > 0) : [];

    rows.forEach(row => {
        const firstName = row.dataset.firstname || '';
        const lastName  = row.dataset.lastname  || '';
        const fullName  = (firstName + ' ' + lastName).trim();
        const email     = row.dataset.email     || '';
        const st        = (row.dataset.station || '').toLowerCase();
        const rowStat   = row.dataset.status    || '';
        const reg       = (row.dataset.region   || '').toLowerCase();
        const fullRowText = (row.textContent || '').toLowerCase();

        // Match all tokens across name, email, station, region, or row text
        let matchQ = true;
        if (tokens.length > 0) {
            matchQ = tokens.every(tok => 
                firstName.includes(tok) || 
                lastName.includes(tok) || 
                fullName.includes(tok) || 
                email.includes(tok) || 
                st.includes(tok) || 
                reg.includes(tok) || 
                fullRowText.includes(tok)
            );
        }

        const matchSt = !status || (status === 'inactive' && (rowStat === 'disabled' || rowStat === 'inactive')) || (status === 'active' && rowStat === 'active');
        const matchStn= !station || st.includes(station);
        const matchReg= !region || reg === region;

        const show = matchQ && matchSt && matchStn && matchReg;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // Handle empty state row
    let noMatchRow = document.getElementById('amNoMatchRow');
    if (visible === 0 && rows.length > 0) {
        if (!noMatchRow) {
            noMatchRow = document.createElement('tr');
            noMatchRow.id = 'amNoMatchRow';
            noMatchRow.innerHTML = `<td colspan="8" style="text-align:center;padding:36px 20px;color:#888;">
                <i class="fas fa-search" style="font-size:28px;color:#ccc;margin-bottom:10px;display:block;"></i>
                <strong style="color:#555;font-size:14px;">No admin accounts matching your filter</strong>
                <div style="font-size:12px;margin-top:4px;">Try checking for spelling errors or clearing some filters.</div>
            </td>`;
            if (tbody) tbody.appendChild(noMatchRow);
        } else {
            noMatchRow.style.display = '';
        }
    } else if (noMatchRow) {
        noMatchRow.style.display = 'none';
    }

    const total = rows.length;
    const rowCountEl = document.getElementById('rowCount');
    if (rowCountEl) {
        rowCountEl.textContent = visible === total
            ? `Showing all ${total} admin${total !== 1 ? 's' : ''}`
            : `Showing ${visible} of ${total} admin${total !== 1 ? 's' : ''}`;
    }
}

// â”€â”€ Modal helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.am-modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.classList.remove('open'); });
});

// â”€â”€ Create modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openCreateModal() {
    document.getElementById('createForm').reset();
    document.getElementById('createAlert').style.display = 'none';
    const combo = document.getElementById('c_station_combo');
    if (combo && combo._reset) combo._reset();
    openModal('createModal');
}

async function submitCreate(e) {
    e.preventDefault();
    const btn   = document.getElementById('createSubmitBtn');
    const alert = document.getElementById('createAlert');
    alert.style.display = 'none';

    const firstName = document.getElementById('c_first_name').value.trim();
    const lastName  = document.getElementById('c_last_name').value.trim();
    const email     = document.getElementById('c_email').value.trim();

    if (!firstName) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> First name is required.';
        alert.style.display = 'flex'; return;
    }
    if (!lastName) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Last name is required.';
        alert.style.display = 'flex'; return;
    }
    if (!email) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Email address is required.';
        alert.style.display = 'flex'; return;
    }

    // Validate station
    if (!document.getElementById('c_station_id').value) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select a station.';
        alert.style.display = 'flex'; return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating—¦';

    const fd = new FormData(document.getElementById('createForm'));
    fd.set('first_name', firstName);
    fd.set('last_name', lastName);
    fd.set('email', email);
    fd.append('action', 'create_admin');
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_admin_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeModal('createModal');
            const msg = data.message || 'Admin account created successfully.';
            showPageFlash('success', msg, true);
            setTimeout(() => location.reload(), 600);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to create admin.');
            alert.style.display = 'flex';
        }
    } catch (err) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        alert.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-user-plus"></i> Create Admin';
}

// ── Edit modal ─────────────────────────────────────────────────────────────
function openEditModal(adm) {
    document.getElementById('editAlert').style.display = 'none';
    
    // Use id column
    const adminId = adm.id;
    document.getElementById('e_admin_id').value = adminId;

    // Parse first and last name from name field or use separate fields if available
    let firstName = adm.first_name || '';
    let lastName = adm.last_name || '';
    if (!firstName && adm.name) {
        const parts = adm.name.trim().split(' ');
        if (parts.length > 1) {
            lastName = parts.pop();
            firstName = parts.join(' ');
        } else {
            firstName = adm.name;
        }
    }

    document.getElementById('e_first_name').value = firstName;
    document.getElementById('e_last_name').value = lastName;

    // Email is read-only — show in display field only
    document.getElementById('e_email_display').value = adm.email || '';

    const dbStatus = (adm.status || '').toLowerCase();
    document.getElementById('e_status').value = (dbStatus === 'disabled' || dbStatus === 'inactive') ? 'inactive' : 'active';

    // Set station combobox
    const combo = document.getElementById('e_station_combo');
    if (combo && combo._setValue && adm.station_id) {
        combo._setValue(String(adm.station_id), adm.station_name || '');
    } else if (combo && combo._reset) {
        combo._reset();
    }

    openModal('editModal');
}

async function submitEdit(e) {
    e.preventDefault();
    const btn   = document.getElementById('editSubmitBtn');
    const alert = document.getElementById('editAlert');
    alert.style.display = 'none';

    const firstName = document.getElementById('e_first_name').value.trim();
    const lastName = document.getElementById('e_last_name').value.trim();
    
    if (!firstName) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> First name is required.';
        alert.style.display = 'flex'; return;
    }
    if (!lastName) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Last name is required.';
        alert.style.display = 'flex'; return;
    }

    if (!document.getElementById('e_station_id').value) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select a station.';
        alert.style.display = 'flex'; return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(document.getElementById('editForm'));
    fd.set('first_name', firstName);
    fd.set('last_name', lastName);
    fd.append('action', 'edit_admin');
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_admin_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            closeModal('editModal');
            const msg = data.message || 'Admin account updated successfully.';
            showPageFlash('success', msg, true);
            setTimeout(() => location.reload(), 600);
        } else {
            alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to update admin.');
            alert.style.display = 'flex';
        }
    } catch (err) {
        alert.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        alert.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
}

// ── Confirm status change ───────────────────────────────────────────────────
let _pendingId = null, _pendingAction = null;

function confirmDeactivate(id, name) {
    _pendingId     = id;
    _pendingAction = 'deactivate';
    document.getElementById('confirmTitle').textContent = 'Deactivate Admin';
    document.getElementById('confirmIcon').className    = 'fas fa-ban';
    document.getElementById('confirmIcon').style.color  = '#cc0000';
    document.getElementById('confirmMsg').textContent   = `Deactivate "${name}"?`;
    document.getElementById('confirmSub').textContent   = 'This will disable their login access. Records are preserved for compliance.';
    const btn = document.getElementById('confirmActionBtn');
    btn.className   = 'am-btn am-btn-deact';
    btn.textContent = 'Deactivate';
    openModal('confirmModal');
}

function confirmActivate(id, name) {
    _pendingId     = id;
    _pendingAction = 'activate';
    document.getElementById('confirmTitle').textContent = 'Activate Admin';
    document.getElementById('confirmIcon').className    = 'fas fa-check-circle';
    document.getElementById('confirmIcon').style.color  = '#28a745';
    document.getElementById('confirmMsg').textContent   = `Activate "${name}"?`;
    document.getElementById('confirmSub').textContent   = 'This will restore their login access.';
    const btn = document.getElementById('confirmActionBtn');
    btn.className   = 'am-btn am-btn-activate';
    btn.textContent = 'Activate';
    openModal('confirmModal');
}

async function executeStatusChange() {
    if (!_pendingId || !_pendingAction) return;
    const btn = document.getElementById('confirmActionBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('action',     _pendingAction === 'deactivate' ? 'deactivate_admin' : 'activate_admin');
    fd.append('admin_id',   _pendingId);
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_admin_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        closeModal('confirmModal');
        if (data.ok) {
            const msg = data.message || 'Status updated successfully.';
            showPageFlash('success', msg, true);
            setTimeout(() => location.reload(), 600);
        } else {
            showPageFlash('error', data.error || 'Action failed.');
        }
    } catch (err) {
        closeModal('confirmModal');
        showPageFlash('error', 'Network error. Please try again.');
    }
}

// ── Page Right-Side Toast / Flash Notification ──────────────────────────────
function showPageFlash(type, msg, persist = false) {
    if (persist) {
        try {
            sessionStorage.setItem('admin_mgmt_flash', JSON.stringify({ type: type, msg: msg }));
        } catch(e) {}
    }
    
    // Prefer shared top-right toast system
    if (typeof window.showToast === 'function') {
        window.showToast(msg, type, 5000, type === 'success' ? 'Success' : 'Notice');
    } else if (typeof window.showPetronFlash === 'function') {
        window.showPetronFlash(msg, type, 5000);
    } else {
        // Fallback top-right toast banner
        let container = document.getElementById('petron-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'petron-toast-container';
            container.style.cssText = 'position:fixed;top:84px;right:22px;z-index:2147483000;display:flex;flex-direction:column;gap:10px;width:min(390px,calc(100vw - 32px));pointer-events:none;';
            document.body.appendChild(container);
        }
        container.style.display = 'flex';
        const toast = document.createElement('div');
        const isSuccess = type === 'success';
        toast.className = 'petron-toast toast-' + type;
        toast.style.cssText = 'position:relative;width:100%;padding:14px 18px;border-radius:10px;border:1px solid ' + (isSuccess ? '#bbf7d0' : '#fecaca') + ';background:' + (isSuccess ? '#ffffff' : '#ffffff') + ';color:#0f172a;font:600 13px/1.4 system-ui,-apple-system,sans-serif;box-shadow:0 12px 28px rgba(15,23,42,.14);pointer-events:auto;display:flex;align-items:flex-start;gap:12px;border-left:4px solid ' + (isSuccess ? '#16a34a' : '#dc2626') + ';animation:slideInRight .3s ease;';
        toast.innerHTML = `
            <i class="fas fa-${isSuccess ? 'check-circle' : 'exclamation-circle'}" style="color:${isSuccess ? '#16a34a' : '#dc2626'};font-size:18px;margin-top:2px;"></i>
            <div style="flex:1;">
                <strong style="display:block;font-size:13px;color:#0f172a;margin-bottom:2px;">${isSuccess ? 'Success' : 'Notice'}</strong>
                <span style="font-size:12px;color:#475569;font-weight:400;">${msg}</span>
            </div>
        `;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(40px)';
            toast.style.transition = 'all .35s ease';
            setTimeout(() => toast.remove(), 400);
        }, 4500);
    }
}

// Automatically check and display persisted flash messages on page load
document.addEventListener('DOMContentLoaded', () => {
    const savedFlash = sessionStorage.getItem('admin_mgmt_flash');
    if (savedFlash) {
        sessionStorage.removeItem('admin_mgmt_flash');
        try {
            const parsed = JSON.parse(savedFlash);
            if (parsed && parsed.msg) {
                setTimeout(() => {
                    showPageFlash(parsed.type || 'success', parsed.msg);
                }, 100);
            }
        } catch(e) {}
    }
});

// ── Add Station Modal ──────────────────────────────────────────────────────
function openAddStationModal() {
    document.getElementById('addStationAlert').style.display = 'none';
    document.getElementById('addStationForm').reset();
    openModal('addStationModal');
}

async function submitAddStation(e) {
    e.preventDefault();
    const btn = document.getElementById('addStationSubmitBtn');
    const alertEl = document.getElementById('addStationAlert');
    alertEl.style.display = 'none';

    const stationName = document.getElementById('station_name').value.trim();
    const location    = document.getElementById('station_location').value.trim();
    const region      = document.getElementById('station_region').value.trim();

    if (!stationName) {
        alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Station name is required.';
        alertEl.style.display = 'flex'; return;
    }
    if (!location) {
        alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Complete address is required.';
        alertEl.style.display = 'flex'; return;
    }
    if (!region) {
        alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select a region.';
        alertEl.style.display = 'flex'; return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating…';

    const fd = new FormData(document.getElementById('addStationForm'));
    fd.append('action', 'add_station');
    fd.append('csrf_token', '<?php echo $csrf; ?>');

    try {
        const res  = await fetch('../backend/api/superadmin_admin_management_api.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            // Inject into STATION_DATA so Create Admin modal picks it up immediately
            if (data.station_id && data.station_name) {
                STATION_DATA.push({ id: data.station_id, name: data.station_name });
                STATION_DATA.sort((a, b) => a.name.localeCompare(b.name));
            }
            closeModal('addStationModal');
            const msg = data.message || 'Station created successfully.';
            showPageFlash('success', msg, true);
            setTimeout(() => location.reload(), 600);
        } else {
            alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Failed to create station.');
            alertEl.style.display = 'flex';
        }
    } catch (err) {
        alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error. Please try again.';
        alertEl.style.display = 'flex';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-building"></i> Create Station';
}

// â”€â”€ Export PDF â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function exportAdminsPDF() {
    const rows = [...document.querySelectorAll('#adminTableBody tr[data-firstname]')].filter(r => r.style.display !== 'none');
    if (!rows.length) { alert('No records to export.'); return; }

    const now = new Date().toLocaleString('en-PH', { dateStyle: 'long', timeStyle: 'short' });
    let html = `<!DOCTYPE html><html><head><meta charset="utf-8">
<title>Admin List – Petron Management System</title>
<style>
  body { font-family: Arial, sans-serif; font-size: 11px; color: #111; margin: 20px; }
  h2 { color: #00264d; font-size: 16px; margin: 0 0 2px; }
  .sub { font-size: 10px; color: #666; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #00264d; color: #fff; padding: 7px 10px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; }
  td { padding: 6px 10px; border-bottom: 1px solid #eee; vertical-align: top; }
  tr:nth-child(even) td { background: #f7f9fc; }
  .badge-a { color: #1a7a35; font-weight: 700; } .badge-i { color: #cc0000; font-weight: 700; }
  @media print { body { margin: 10px; } }
</style></head><body>
<h2><span style="color:#cc0000;">Petron</span> Station Management System</h2>
<div class="sub">Admin Accounts & Station Coverage &nbsp;|&nbsp; Exported: ${now}</div>
<table><thead><tr>
  <th>#</th><th>First Name</th><th>Last Name</th><th>Email</th><th>Station</th><th>Region</th><th>Status</th><th>Last Login</th>
</tr></thead><tbody>`;

    rows.forEach((row, i) => {
        const cells = row.querySelectorAll('td');
        const status  = row.dataset.status || '';
        const region  = row.dataset.region || '—';
        const badgeClass = status === 'active' ? 'badge-a' : 'badge-i';
        const statusText  = status === 'active' ? 'Active' : 'Inactive';
        html += `<tr>
          <td>${i + 1}</td>
          <td>${cells[1]?.innerText || ''}</td>
          <td>${cells[2]?.innerText || ''}</td>
          <td>${cells[3]?.innerText || ''}</td>
          <td>${cells[4]?.innerText.trim().split('\n')[0] || '—'}</td>
          <td>${region}</td>
          <td class="${badgeClass}">${statusText}</td>
          <td>${cells[6]?.innerText.trim().split('\n')[0] || 'Never'}</td>
        </tr>`;
    });

    html += `</tbody></table></body></html>`;

    const win = window.open('', '_blank', 'width=1000,height=700');
    win.document.write(html);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 500);
}
</script>


<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastSuperadminAdminsCount = null;
function autoRefreshSuperadminAdminManagement() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_sam', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                if (document.getElementById('kpi_total_admins')) document.getElementById('kpi_total_admins').textContent = data.total;
                if (document.getElementById('kpi_active_admins')) document.getElementById('kpi_active_admins').textContent = data.active;
                if (document.getElementById('kpi_inactive_admins')) document.getElementById('kpi_inactive_admins').textContent = data.inactive;
                if (document.getElementById('kpi_stations_covered')) document.getElementById('kpi_stations_covered').textContent = data.stations_covered;

                if (lastSuperadminAdminsCount !== null && lastSuperadminAdminsCount !== data.admins_count) {
                    window.location.reload();
                }
                lastSuperadminAdminsCount = data.admins_count;
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshSuperadminAdminManagement, 10000);
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
