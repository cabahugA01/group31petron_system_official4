<?php
// ============================================================
// SuperAdmin – Integration Settings
// public/superadmin_integration_settings.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php'); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// ── Active section ────────────────────────────────────────────
$section  = trim($_GET['section'] ?? 'pos_import');
$allowed  = ['pos_import', 'api_endpoints', 'sync_rules', 'audit_trail'];
if (!in_array($section, $allowed)) $section = 'pos_import';

$page_id = match($section) {
    'pos_import'    => 'int_pos_import',
    'api_endpoints' => 'int_api_endpoints',
    'sync_rules'    => 'int_sync_rules',
    'audit_trail'   => 'int_audit_trail',
    default         => 'int_pos_import',
};

// ── Bootstrap tables ─────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_pos_parsers (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        file_type   ENUM('csv','excel') NOT NULL DEFAULT 'csv',
        delimiter   VARCHAR(5) NOT NULL DEFAULT ',',
        has_header  TINYINT(1) NOT NULL DEFAULT 1,
        column_map  JSON NOT NULL,
        sample_data TEXT,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_by  INT NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_api_endpoints (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        name             VARCHAR(120) NOT NULL,
        endpoint_url     VARCHAR(500) NOT NULL,
        auth_type        ENUM('none','api_key','bearer','basic') NOT NULL DEFAULT 'api_key',
        auth_value       TEXT,
        allowed_methods  SET('GET','POST','PUT','DELETE') NOT NULL DEFAULT 'GET',
        module_target    VARCHAR(100) NOT NULL DEFAULT '',
        last_tested_at   DATETIME NULL,
        last_test_status ENUM('ok','fail','untested') NOT NULL DEFAULT 'untested',
        is_active        TINYINT(1) NOT NULL DEFAULT 1,
        created_by       INT NOT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_sync_rules (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(120) NOT NULL,
        module_key          VARCHAR(100) NOT NULL,
        frequency           ENUM('realtime','hourly','daily','weekly') NOT NULL DEFAULT 'daily',
        conflict_resolution ENUM('system_override','external_override') NOT NULL DEFAULT 'system_override',
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        last_synced_at      DATETIME NULL,
        created_by          INT NOT NULL,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_audit (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        action_type VARCHAR(80) NOT NULL,
        target_type ENUM('pos_parser','api_endpoint','sync_rule') NOT NULL,
        target_id   INT NOT NULL,
        target_name VARCHAR(200) NOT NULL DEFAULT '',
        details     TEXT,
        ip_address  VARCHAR(45),
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user    (user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* tables may already exist */ }

// ── Fetch data for each section ───────────────────────────────
$parsers   = $pdo->query("SELECT * FROM integration_pos_parsers ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$endpoints = $pdo->query("SELECT * FROM integration_api_endpoints ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$sync_rules = $pdo->query("SELECT * FROM integration_sync_rules ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$audit_rows = [];
try {
    $audit_rows = $pdo->query(
        "SELECT ia.*, u.name AS user_name
         FROM integration_audit ia
         LEFT JOIN users u ON u.user_id = ia.user_id
         ORDER BY ia.created_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Available modules for sync rules (DB-driven) ─────────────
$available_modules = [];
try {
    $available_modules = $pdo->query("SELECT module_key, module_name FROM module_settings WHERE is_enabled=1 ORDER BY module_order")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: use known module keys
    $available_modules = [
        ['module_key' => 'transactions',    'module_name' => 'Transactions'],
        ['module_key' => 'job_orders',      'module_name' => 'Job Orders'],
        ['module_key' => 'fuel_management', 'module_name' => 'Fuel Management'],
        ['module_key' => 'calendar',        'module_name' => 'Calendar'],
        ['module_key' => 'reports',         'module_name' => 'Reports'],
    ];
}

// ── System fields for POS column mapping (DB-driven) ─────────
$system_fields = [];
try {
    // Pull actual column names from key tables
    $field_tables = ['transactions', 'inventory', 'station_inventory', 'users'];
    foreach ($field_tables as $tbl) {
        try {
            $cols = $pdo->query("DESCRIBE `{$tbl}`")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $col) {
                $system_fields["{$tbl}.{$col}"] = "{$tbl} → {$col}";
            }
        } catch (Exception $e) {}
    }
} catch (Exception $e) {}
if (empty($system_fields)) {
    $system_fields = [
        'transactions.amount'      => 'transactions → amount',
        'inventory.product_name'   => 'inventory → product_name',
        'inventory.stock_level'    => 'inventory → stock_level',
        'users.name'               => 'users → name',
    ];
}

// Log this page view
log_activity($pdo, $me['id'], 'Integration Settings View', "Viewed section: {$section}");

include __DIR__ . '/../partials/header.php';
?>
<style>
.int-page{padding:28px 24px}
.int-head{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.int-head h1{font-size:22px!important;font-weight:700!important;color:var(--petron-blue)!important;margin:0!important;text-transform:uppercase!important}
.int-head .sub{font-size:13px;color:#666;margin-top:4px;text-transform:none!important}
.int-steps{display:flex;gap:0;margin-bottom:22px;background:#fff;border:1px solid #eaeaea;border-radius:14px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.int-step{flex:1;display:flex;align-items:center;gap:10px;padding:13px 16px;font-size:12px;font-weight:600;color:#aaa;border-right:1px solid #f0f0f0}
.int-step:last-child{border-right:none}
.int-step .sn{width:24px;height:24px;border-radius:50%;background:#eee;color:#aaa;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
.int-step.active{color:var(--petron-blue);background:rgba(0,38,77,.04)}
.int-step.active .sn{background:var(--petron-blue);color:#fff}
.int-step.done .sn{background:#28a745;color:#fff}
.int-step.done{color:#28a745}
.int-step-label .st{display:block;font-size:12px;font-weight:700}
.int-step-label .sd{display:block;font-size:10px;font-weight:400;opacity:.7;margin-top:1px}
@media(max-width:700px){.int-step-label .sd{display:none}.int-step{padding:10px 8px;gap:6px}}
.int-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:14px;margin-bottom:20px}
.int-stat{background:#fff;border:1px solid #eaeaea;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
.int-stat-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.int-stat-val{font-size:20px;font-weight:800;color:var(--petron-blue);line-height:1}
.int-stat-lbl{font-size:11px;color:#888;margin-top:2px}
.int-card{background:#fff;border:1px solid #eaeaea;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.04);overflow:hidden;margin-bottom:20px}
.int-card-header{padding:16px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.int-card-header h3{font-size:14px!important;font-weight:700!important;color:var(--petron-blue)!important;margin:0!important;text-transform:uppercase!important;display:flex;align-items:center;gap:8px}
.int-card-body{padding:18px 20px}
.int-table-wrap{overflow-x:auto;border-radius:10px;border:1px solid #eee}
.int-table{width:100%;border-collapse:collapse;font-size:12px;min-width:600px}
.int-table thead th{background:var(--petron-blue);color:#fff;padding:10px 14px;text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
.int-table tbody tr{border-bottom:1px solid #f5f5f5;transition:background .12s}
.int-table tbody tr:last-child{border-bottom:none}
.int-table tbody tr:hover{background:#f8fafc}
.int-table td{padding:9px 14px;vertical-align:middle;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.int-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .2s;background:none}
.int-btn-primary{background:var(--petron-blue);color:#fff;border-color:var(--petron-blue)}
.int-btn-primary:hover{background:#001a3d}
.int-btn-success{background:#28a745;color:#fff;border-color:#28a745}
.int-btn-success:hover{background:#1e7e34}
.int-btn-outline{color:var(--petron-blue);border-color:var(--petron-blue)}
.int-btn-outline:hover{background:rgba(0,38,77,.06)}
.int-btn-danger{color:#cc0000;border-color:#cc0000}
.int-btn-danger:hover{background:rgba(204,0,0,.06)}
.int-btn-sm{padding:5px 11px;font-size:11px;border-radius:7px}
.int-btn:disabled{opacity:.5;cursor:not-allowed}
.int-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.int-form-row.full{grid-template-columns:1fr}
.int-form-group{display:flex;flex-direction:column;gap:5px}
.int-form-group label{font-size:11px;font-weight:700;color:#444;text-transform:uppercase;letter-spacing:.3px}
.int-form-group input,.int-form-group select,.int-form-group textarea{padding:9px 12px;border:1px solid #ddd;border-radius:9px;font-size:13px;outline:none;transition:border-color .2s;font-family:inherit;background:#fff}
.int-form-group input:focus,.int-form-group select:focus,.int-form-group textarea:focus{border-color:var(--petron-blue);box-shadow:0 0 0 3px rgba(0,38,77,.08)}
.int-form-hint{font-size:11px;color:#888;margin-top:2px}
.int-badge{padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
.int-badge-ok{background:rgba(40,167,69,.12);color:#1a7a35}
.int-badge-fail{background:rgba(204,0,0,.1);color:#cc0000}
.int-badge-untested{background:rgba(108,117,125,.1);color:#555}
.int-badge-active{background:rgba(40,167,69,.12);color:#1a7a35}
.int-badge-inactive{background:rgba(204,0,0,.1);color:#cc0000}
.int-info{background:#f8fafc;border:1px solid #e8edf2;border-radius:10px;padding:11px 14px;font-size:12px;color:#555;display:flex;align-items:flex-start;gap:8px;margin-bottom:16px}
.int-info i{color:var(--petron-blue);flex-shrink:0;margin-top:1px}
.int-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:flex-start;justify-content:center;padding:24px 12px;overflow-y:auto}
.int-modal-overlay.open{display:flex}
.int-modal{background:#fff;border-radius:16px;width:min(580px,100%);display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:intSlide .25s ease;margin:auto}
@keyframes intSlide{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:translateY(0)}}
.int-modal-header{padding:18px 22px 14px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:sticky;top:0;background:#fff;z-index:2;border-radius:16px 16px 0 0}
.int-modal-header h2{font-size:15px!important;font-weight:700!important;color:var(--petron-blue)!important;margin:0!important;text-transform:uppercase!important}
.int-modal-close{background:none;border:none;font-size:20px;color:#999;cursor:pointer;padding:4px 8px;border-radius:6px}
.int-modal-close:hover{background:#f0f0f0;color:#333}
.int-modal-body{padding:20px 22px;flex:1 1 auto}
.int-modal-footer{padding:14px 22px;border-top:1px solid #eee;display:flex;justify-content:flex-end;gap:10px;flex-shrink:0;position:sticky;bottom:0;background:#fff;z-index:2;border-radius:0 0 16px 16px}
.int-flash{padding:10px 14px;border-radius:9px;margin-bottom:14px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:8px}
.int-flash.success{background:rgba(40,167,69,.1);border:1px solid rgba(40,167,69,.3);color:#1a7a35}
.int-flash.error{background:rgba(204,0,0,.08);border:1px solid rgba(204,0,0,.25);color:#cc0000}
.int-toggle{position:relative;display:inline-block;width:38px;height:20px;flex-shrink:0}
.int-toggle input{opacity:0;width:0;height:0}
.int-toggle-slider{position:absolute;inset:0;background:#ccc;border-radius:20px;cursor:pointer;transition:.3s}
.int-toggle-slider:before{content:'';position:absolute;width:14px;height:14px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.3s}
.int-toggle input:checked+.int-toggle-slider{background:#28a745}
.int-toggle input:checked+.int-toggle-slider:before{transform:translateX(18px)}
.col-map-row{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.col-map-row input{flex:1;padding:7px 10px;border:1px solid #ddd;border-radius:8px;font-size:12px;outline:none}
.col-map-row select{flex:1;padding:7px 10px;border:1px solid #ddd;border-radius:8px;font-size:12px;outline:none;background:#fff}
.col-map-row .rm-btn{background:none;border:none;color:#cc0000;cursor:pointer;font-size:14px;padding:4px}
</style>

<div class="int-page">

<!-- Page Header -->
<div class="int-head">
    <div>
        <h1><i class="fas fa-plug"></i> Integration Settings</h1>
        <div class="sub">Developer & System Connectivity Configuration</div>
    </div>
</div>


<!-- Stats Overview -->
<div class="int-stats">
    <div class="int-stat">
        <div class="int-stat-icon" style="background:rgba(0,38,77,.1);color:var(--petron-blue)">
            <i class="fas fa-file-csv"></i>
        </div>
        <div>
            <div class="int-stat-val"><?php echo count($parsers); ?></div>
            <div class="int-stat-lbl">POS Parsers</div>
        </div>
    </div>
    <div class="int-stat">
        <div class="int-stat-icon" style="background:rgba(40,167,69,.1);color:#28a745">
            <i class="fas fa-plug"></i>
        </div>
        <div>
            <div class="int-stat-val"><?php echo count($endpoints); ?></div>
            <div class="int-stat-lbl">API Endpoints</div>
        </div>
    </div>
    <div class="int-stat">
        <div class="int-stat-icon" style="background:rgba(255,193,7,.1);color:#ffc107">
            <i class="fas fa-sync"></i>
        </div>
        <div>
            <div class="int-stat-val"><?php echo count($sync_rules); ?></div>
            <div class="int-stat-lbl">Sync Rules</div>
        </div>
    </div>
    <div class="int-stat">
        <div class="int-stat-icon" style="background:rgba(108,117,125,.1);color:#6c757d">
            <i class="fas fa-history"></i>
        </div>
        <div>
            <div class="int-stat-val"><?php echo count($audit_rows); ?></div>
            <div class="int-stat-lbl">Audit Logs</div>
        </div>
    </div>
</div>

<!-- Section: POS Import Configuration -->
<?php if ($section === 'pos_import'): ?>
<div class="int-card">
    <div class="int-card-header">
        <h3><i class="fas fa-file-csv"></i> POS Import Configuration</h3>
        <button class="int-btn int-btn-primary int-btn-sm" onclick="openParserModal()">
            <i class="fas fa-plus"></i> Add Parser
        </button>
    </div>
    <div class="int-card-body">
        <div class="int-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Step 1:</strong> Configure CSV/Excel parser rules to auto-import POS data into system tables. 
                SuperAdmin configures parser only; actual encoding handled by Admin/Staff.
            </div>
        </div>
        
        <?php if (empty($parsers)): ?>
            <p style="text-align:center;color:#999;padding:40px 20px">
                <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3"></i>
                No POS parsers configured yet. Click "Add Parser" to create one.
            </p>
        <?php else: ?>
            <div class="int-table-wrap">
                <table class="int-table">
                    <thead>
                        <tr>
                            <th>Parser Name</th>
                            <th>File Type</th>
                            <th>Delimiter</th>
                            <th>Has Header</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($parsers as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
                            <td><?php echo strtoupper($p['file_type']); ?></td>
                            <td><code><?php echo htmlspecialchars($p['delimiter']); ?></code></td>
                            <td><?php echo $p['has_header'] ? 'Yes' : 'No'; ?></td>
                            <td>
                                <span class="int-badge <?php echo $p['is_active'] ? 'int-badge-active' : 'int-badge-inactive'; ?>">
                                    <?php echo $p['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                            <td>
                                <button class="int-btn int-btn-outline int-btn-sm" onclick="editParser(<?php echo $p['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="int-btn int-btn-danger int-btn-sm" onclick="deleteParser(<?php echo $p['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Section: API Endpoints Configuration -->
<?php if ($section === 'api_endpoints'): ?>
<div class="int-card">
    <div class="int-card-header">
        <h3><i class="fas fa-plug"></i> API Endpoints Configuration</h3>
        <button class="int-btn int-btn-primary int-btn-sm" onclick="openEndpointModal()">
            <i class="fas fa-plus"></i> Add Endpoint
        </button>
    </div>
    <div class="int-card-body">
        <div class="int-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Step 2:</strong> Define API connections for external systems. SuperAdmin cannot alter external system data, only configure connectivity.
            </div>
        </div>
        
        <?php if (empty($endpoints)): ?>
            <p style="text-align:center;color:#999;padding:40px 20px">
                <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3"></i>
                No API endpoints configured yet. Click "Add Endpoint" to create one.
            </p>
        <?php else: ?>
            <div class="int-table-wrap">
                <table class="int-table">
                    <thead>
                        <tr>
                            <th>Endpoint Name</th>
                            <th>URL</th>
                            <th>Auth Type</th>
                            <th>Methods</th>
                            <th>Module Target</th>
                            <th>Last Test</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($endpoints as $ep): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($ep['name']); ?></strong></td>
                            <td title="<?php echo htmlspecialchars($ep['endpoint_url']); ?>">
                                <?php echo htmlspecialchars(substr($ep['endpoint_url'], 0, 40)) . (strlen($ep['endpoint_url']) > 40 ? '...' : ''); ?>
                            </td>
                            <td><?php echo strtoupper($ep['auth_type']); ?></td>
                            <td><?php echo htmlspecialchars($ep['allowed_methods']); ?></td>
                            <td><?php echo htmlspecialchars($ep['module_target']); ?></td>
                            <td><?php echo $ep['last_tested_at'] ? date('M d, Y', strtotime($ep['last_tested_at'])) : 'Never'; ?></td>
                            <td>
                                <span class="int-badge int-badge-<?php echo $ep['last_test_status']; ?>">
                                    <?php echo ucfirst($ep['last_test_status']); ?>
                                </span>
                            </td>
                            <td>
                                <button class="int-btn int-btn-success int-btn-sm" onclick="testEndpoint(<?php echo $ep['id']; ?>)">
                                    <i class="fas fa-vial"></i> Test
                                </button>
                                <button class="int-btn int-btn-outline int-btn-sm" onclick="editEndpoint(<?php echo $ep['id']; ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="int-btn int-btn-danger int-btn-sm" onclick="deleteEndpoint(<?php echo $ep['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Section: Sync Rules Setup -->
<?php if ($section === 'sync_rules'): ?>
<div class="int-card">
    <div class="int-card-header">
        <h3><i class="fas fa-sync"></i> Sync Rules Setup</h3>
        <button class="int-btn int-btn-primary int-btn-sm" onclick="openSyncRuleModal()">
            <i class="fas fa-plus"></i> Add Sync Rule
        </button>
    </div>
    <div class="int-card-body">
        <div class="int-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Step 3:</strong> Set synchronization rules for calendar and reports. Sync is read-only for compliance; business ops data cannot be edited externally.
            </div>
        </div>
        
        <?php if (empty($sync_rules)): ?>
            <p style="text-align:center;color:#999;padding:40px 20px">
                <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3"></i>
                No sync rules configured yet. Click "Add Sync Rule" to create one.
            </p>
        <?php else: ?>
            <div class="int-table-wrap">
                <table class="int-table">
                    <thead>
                        <tr>
                            <th>Rule Name</th>
                            <th>Module</th>
                            <th>Frequency</th>
                            <th>Conflict Resolution</th>
                            <th>Last Synced</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sync_rules as $sr): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($sr['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($sr['module_key']); ?></td>
                            <td><?php echo ucfirst($sr['frequency']); ?></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $sr['conflict_resolution'])); ?></td>
                            <td><?php echo $sr['last_synced_at'] ? date('M d, Y H:i', strtotime($sr['last_synced_at'])) : 'Never'; ?></td>
                            <td>
                                <span class="int-badge <?php echo $sr['is_active'] ? 'int-badge-active' : 'int-badge-inactive'; ?>">
                                    <?php echo $sr['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <button class="int-btn int-btn-outline int-btn-sm" onclick="editSyncRule(<?php echo $sr['id']; ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="int-btn int-btn-danger int-btn-sm" onclick="deleteSyncRule(<?php echo $sr['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Section: Audit Trail Logging -->
<?php if ($section === 'audit_trail'): ?>
<div class="int-card">
    <div class="int-card-header">
        <h3><i class="fas fa-history"></i> Audit Trail Logging</h3>
    </div>
    <div class="int-card-body">
        <div class="int-info">
            <i class="fas fa-info-circle"></i>
            <div>
                <strong>Step 4:</strong> Every integration setup/change is logged for transparency, debugging, and compliance.
            </div>
        </div>
        
        <?php if (empty($audit_rows)): ?>
            <p style="text-align:center;color:#999;padding:40px 20px">
                <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:12px;opacity:.3"></i>
                No audit logs yet.
            </p>
        <?php else: ?>
            <div class="int-table-wrap">
                <table class="int-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Target Type</th>
                            <th>Target Name</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($audit_rows as $ar): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i:s', strtotime($ar['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($ar['user_name'] ?? 'Unknown'); ?></td>
                            <td><strong><?php echo htmlspecialchars($ar['action_type']); ?></strong></td>
                            <td><?php echo ucwords(str_replace('_', ' ', $ar['target_type'])); ?></td>
                            <td><?php echo htmlspecialchars($ar['target_name']); ?></td>
                            <td title="<?php echo htmlspecialchars($ar['details']); ?>">
                                <?php echo htmlspecialchars(substr($ar['details'], 0, 50)) . (strlen($ar['details']) > 50 ? '...' : ''); ?>
                            </td>
                            <td><?php echo htmlspecialchars($ar['ip_address']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</div>

<script>
// Modal management
function openParserModal() {
    alert('POS Parser modal - to be implemented');
}
function editParser(id) {
    alert('Edit parser #' + id + ' - to be implemented');
}
function deleteParser(id) {
    if (confirm('Delete this parser?')) {
        alert('Delete parser #' + id + ' - to be implemented');
    }
}
function openEndpointModal() {
    alert('API Endpoint modal - to be implemented');
}
function editEndpoint(id) {
    alert('Edit endpoint #' + id + ' - to be implemented');
}
function deleteEndpoint(id) {
    if (confirm('Delete this endpoint?')) {
        alert('Delete endpoint #' + id + ' - to be implemented');
    }
}
function testEndpoint(id) {
    alert('Test endpoint #' + id + ' - to be implemented');
}
function openSyncRuleModal() {
    alert('Sync Rule modal - to be implemented');
}
function editSyncRule(id) {
    alert('Edit sync rule #' + id + ' - to be implemented');
}
function deleteSyncRule(id) {
    if (confirm('Delete this sync rule?')) {
        alert('Delete sync rule #' + id + ' - to be implemented');
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

