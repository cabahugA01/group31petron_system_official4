<?php
$page_id = 'database_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');
if ($my_role !== 'superadmin') { header("Location: dashboard.php"); exit; }

$msg = ''; $success = '';

// ── Helper: get/set system_config ────────────────────────────────────
function cfg_get(PDO $pdo, string $key, string $default = ''): string {
    $r = $pdo->prepare("SELECT config_value FROM system_config WHERE config_key = ?");
    $r->execute([$key]);
    $v = $r->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function cfg_set(PDO $pdo, string $key, string $value, int $uid): void {
    $pdo->prepare("INSERT INTO system_config (config_key,config_value,updated_by)
        VALUES(?,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value),updated_by=VALUES(updated_by),updated_at=NOW()")
        ->execute([$key, $value, $uid]);
}

// ── Fetch stations ────────────────────────────────────────────────────
$stations = $pdo->query("SELECT id, name FROM stations ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ── Load current config values ────────────────────────────────────────
$cfg_backup_frequency  = cfg_get($pdo, 'backup_frequency',  'manual');
$cfg_storage_location  = cfg_get($pdo, 'backup_storage',    'local');
$cfg_retention_days    = cfg_get($pdo, 'backup_retention_days', '30');
$cfg_sync_freq         = cfg_get($pdo, 'replication_frequency', 'realtime');
$cfg_conflict          = cfg_get($pdo, 'replication_conflict',  'overwrite');

// ── POST handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Backup config ─────────────────────────────────────────────────
    if ($action === 'save_backup_config') {
        cfg_set($pdo, 'backup_frequency',       $_POST['backup_frequency']   ?? 'manual', $me['id']);
        cfg_set($pdo, 'backup_storage',         trim($_POST['storage_location'] ?? 'local'), $me['id']);
        cfg_set($pdo, 'backup_retention_days',  (int)($_POST['retention_days'] ?? 30), $me['id']);
        log_activity($pdo, $me['id'], 'Database Management', 'Saved backup configuration');
        $success = "Backup configuration saved successfully.";
        $cfg_backup_frequency = $_POST['backup_frequency']   ?? 'manual';
        $cfg_storage_location = trim($_POST['storage_location'] ?? 'local');
        $cfg_retention_days   = (int)($_POST['retention_days'] ?? 30);
    }

    // ── Trigger manual backup ─────────────────────────────────────────
    elseif ($action === 'run_backup') {
        $fname = 'backup_' . date('Y_m_d_His') . '.sql';
        $bpath = cfg_get($pdo, 'backup_storage', 'local');
        $pdo->prepare("INSERT INTO database_backups (filename,file_size,created_by) VALUES(?,?,?)")
            ->execute([$fname, 0, $me['id']]);
        $bid = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO system_backups (backup_name,backup_type,file_path,status,created_by) VALUES(?,?,?,?,?)")
            ->execute([$fname, 'database', $bpath.'/'.$fname, 'completed', $me['id']]);
        log_activity($pdo, $me['id'], 'Database Management', "Manual backup triggered: $fname");
        $success = "Backup <strong>$fname</strong> recorded. (Configure your cron/mysqldump separately for actual file export.)";
    }

    // ── Restore ───────────────────────────────────────────────────────
    elseif ($action === 'restore') {
        $backup_filename = $_POST['backup_filename'] ?? '';
        $scope           = $_POST['restore_scope']   ?? 'full';
        $confirmed       = isset($_POST['confirm_restore']) && $_POST['confirm_restore'] === '1';
        if (!$confirmed) {
            $msg = "You must confirm the restore before proceeding.";
        } elseif (!$backup_filename) {
            $msg = "Please select a backup file.";
        } else {
            $pdo->prepare("INSERT INTO database_restores (backup_filename,restored_by) VALUES(?,?)")
                ->execute([$backup_filename, $me['id']]);
            $pdo->prepare("INSERT INTO restore_logs (backup_name,restored_by,status,details) VALUES(?,?,?,?)")
                ->execute([$backup_filename, $me['id'], 'success', "Scope: $scope"]);
            log_activity($pdo, $me['id'], 'Database Management', "Restore initiated: $backup_filename (scope=$scope)");
            $success = "Restore of <strong>$backup_filename</strong> has been logged. (Actual DB restore requires server-side execution.)";
        }
    }

    // ── Schema / Migration ────────────────────────────────────────────
    elseif ($action === 'apply_migration') {
        $tbl     = trim($_POST['table_name']   ?? '');
        $col     = trim($_POST['column_name']  ?? '');
        $dtype   = $_POST['data_type']         ?? 'VARCHAR(255)';
        $maction = $_POST['migration_action']  ?? 'add_column';
        $desc    = trim($_POST['description']  ?? '');
        if (!$tbl || !$col) { $msg = "Table name and column name are required."; }
        else {
            $mname = "migration_{$maction}_{$tbl}_{$col}_" . date('YmdHis');
            $pdo->prepare("INSERT INTO schema_migrations (migration_name,table_name,action,description,executed_by) VALUES(?,?,?,?,?)")
                ->execute([$mname, $tbl, $maction, $desc ?: "$maction $col ($dtype) on $tbl", $me['id']]);
            // Attempt actual DDL
            try {
                if ($maction === 'add_column') {
                    $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $dtype");
                    $success = "Column <strong>$col</strong> added to <strong>$tbl</strong>.";
                } elseif ($maction === 'remove_column') {
                    $pdo->exec("ALTER TABLE `$tbl` DROP COLUMN `$col`");
                    $success = "Column <strong>$col</strong> removed from <strong>$tbl</strong>.";
                }
                $pdo->prepare("INSERT INTO schema_versions (version,description,applied_by) VALUES(?,?,?)")
                    ->execute([date('v.YmdHis'), $success, $me['id']]);
            } catch (Exception $ex) {
                $pdo->prepare("UPDATE schema_migrations SET description=? WHERE migration_name=?")
                    ->execute(["FAILED: " . $ex->getMessage(), $mname]);
                $msg = "Migration recorded but DDL failed: " . htmlspecialchars($ex->getMessage());
            }
            log_activity($pdo, $me['id'], 'Schema Migration', $mname);
        }
    }

    // ── Replication ───────────────────────────────────────────────────
    elseif ($action === 'save_replication') {
        $station_id  = (int)($_POST['rep_station_id'] ?? 0);
        $sync_freq   = $_POST['sync_frequency']       ?? 'realtime';
        $conflict    = $_POST['conflict_resolution']  ?? 'overwrite';
        $enabled     = isset($_POST['rep_enabled'])   ? 'enabled' : 'disabled';
        cfg_set($pdo, 'replication_frequency', $sync_freq, $me['id']);
        cfg_set($pdo, 'replication_conflict',  $conflict,  $me['id']);
        cfg_set($pdo, 'replication_enabled',   $enabled === 'enabled' ? '1' : '0', $me['id']);
        if ($station_id) {
            $pdo->prepare("INSERT INTO replication_status (station_id,status,sync_frequency,conflict_resolution)
                VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),sync_frequency=VALUES(sync_frequency),conflict_resolution=VALUES(conflict_resolution)")
                ->execute([$station_id, $enabled, $sync_freq, $conflict]);
        }
        $cfg_sync_freq = $sync_freq;
        $cfg_conflict  = $conflict;
        log_activity($pdo, $me['id'], 'Database Management', "Replication config updated (station=$station_id, freq=$sync_freq)");
        $success = "Replication configuration saved.";
    }
}

// ── Data for views ────────────────────────────────────────────────────
// Backup history
$backup_history = $pdo->query(
    "SELECT * FROM database_backups ORDER BY created_at DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

// Restore history
$restore_history = $pdo->query(
    "SELECT r.*, u.first_name, u.last_name
     FROM restore_logs r LEFT JOIN users u ON u.id = r.restored_by
     ORDER BY r.restored_at DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

// Migration history
$migration_history = $pdo->query(
    "SELECT m.*, u.first_name, u.last_name
     FROM schema_migrations m LEFT JOIN users u ON u.id = m.executed_by
     ORDER BY m.executed_at DESC LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

// Current schema version
$current_version = $pdo->query(
    "SELECT version, applied_at FROM schema_versions ORDER BY applied_at DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

// Replication status per station
$rep_status = $pdo->query(
    "SELECT r.*, s.name AS station_name
     FROM replication_status r LEFT JOIN stations s ON s.id = r.station_id
     ORDER BY s.name"
)->fetchAll(PDO::FETCH_ASSOC);

// Security logs — filters
$sec_date_from = $_GET['date_from'] ?? '';
$sec_date_to   = $_GET['date_to']   ?? '';
$sec_user_id   = $_GET['user_id']   ?? '';
$sec_where = "1=1";
$sec_params = [];
if ($sec_date_from) { $sec_where .= " AND a.created_at >= ?"; $sec_params[] = $sec_date_from . ' 00:00:00'; }
if ($sec_date_to)   { $sec_where .= " AND a.created_at <= ?"; $sec_params[] = $sec_date_to . ' 23:59:59'; }
if ($sec_user_id)   { $sec_where .= " AND a.user_id = ?";      $sec_params[] = (int)$sec_user_id; }
$sec_stmt = $pdo->prepare(
    "SELECT a.id, a.user_id, a.action, a.details, a.ip_address, a.created_at,
            u.first_name, u.last_name
     FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
     WHERE $sec_where ORDER BY a.created_at DESC LIMIT 200"
);
$sec_stmt->execute($sec_params);
$security_logs = $sec_stmt->fetchAll(PDO::FETCH_ASSOC);

// Users list for filter
$users_list = $pdo->query("SELECT id, first_name, last_name, role FROM users ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);

// Available backup files for restore
$available_backups = $pdo->query("SELECT filename, file_size, created_at FROM database_backups ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// DB tables for schema editor
$all_tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../partials/header.php';?>
<style>
/* ── Searchable Combobox (identical to module_configuration.php) ── */
.am-combo-toolbar .am-combo-input { padding-top:9px; padding-bottom:9px; font-size:13px; }
.am-combo { position:relative; }
.am-combo-input { width:100%; padding:10px 36px 10px 13px; border:1px solid #ddd; border-radius:10px; font-size:13px; outline:none; transition:border-color .2s; background:#fff; box-sizing:border-box; cursor:text; }
.am-combo-input:focus { border-color:var(--petron-blue); box-shadow:0 0 0 3px rgba(0,38,77,.08); }
.am-combo-input.has-value { border-color:var(--petron-blue); }
.am-combo-arrow { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:#999; font-size:12px; pointer-events:none; transition:transform .2s; z-index:1; }
.am-combo.open .am-combo-arrow { transform:translateY(-50%) rotate(180deg); }
.am-combo-clear { position:absolute; right:30px; top:50%; transform:translateY(-50%); color:#bbb; font-size:13px; cursor:pointer; display:none; background:none; border:none; padding:2px 4px; line-height:1; z-index:2; }
.am-combo-clear:hover { color:#cc0000; }
.am-combo-dropdown { display:none; position:fixed; background:#fff; border:1px solid #ddd; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:99999; max-height:260px; overflow:hidden; flex-direction:column; }
.am-combo.open .am-combo-dropdown { display:flex; }
.am-combo-list { overflow-y:auto; flex:1; }
.am-combo-option { padding:10px 14px; font-size:13px; cursor:pointer; transition:background .12s; display:flex; align-items:flex-start; gap:8px; }
.am-combo-option:hover, .am-combo-option.focused { background:#f0f5ff; color:var(--petron-blue); }
.am-combo-option.selected { background:rgba(0,38,77,.08); font-weight:600; color:var(--petron-blue); }
.am-combo-option .opt-icon { color:#bbb; font-size:11px; flex-shrink:0; }
.am-combo-option.selected .opt-icon { color:var(--petron-blue); }
.am-combo-empty { padding:18px 14px; font-size:13px; color:#bbb; text-align:center; }
#tb_station_combo_card, #tb_station_combo_card .card-body { overflow:visible !important; }

.db-page { padding: 0 4px 40px; }
.db-page-head { margin-bottom: 20px; padding-top: 0 !important; margin-top: -12px !important; }
.db-page-head h1 { font-size: 22px; font-weight: 700; color: var(--petron-blue); margin: 0; text-transform: uppercase; }
.db-page-head .sub { font-size: 13px; color: #666; margin-top: 4px; }

/* Station card */
.db-station-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; margin-bottom:22px; overflow:visible!important; }
.db-station-card .card-header { background:#f8f9fa; border-bottom:2px solid #e9ecef; padding:14px 20px; border-radius:12px 12px 0 0; }
.db-station-card .card-header h3 { margin:0; font-size:14px; font-weight:700; color:var(--petron-blue); text-transform:uppercase; letter-spacing:.5px; }
.db-station-card .card-body { padding:16px 20px; overflow:visible!important; }
.db-station-card .card-body .label { font-weight:600; color:#374151; font-size:12px; text-transform:uppercase; letter-spacing:.5px; min-width:120px; }

/* Flash */
.db-flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; }
.db-flash.success { background:rgba(40,167,69,.1); border:1px solid rgba(40,167,69,.3); color:#1a7a35; }
.db-flash.error   { background:rgba(204,0,0,.08);  border:1px solid rgba(204,0,0,.25);  color:#cc0000; }

/* Tabs */
.db-tabs { display:flex !important; gap:0 !important; background:#f0f2f5 !important; border:1px solid #d1d5db !important; border-radius:10px 10px 0 0 !important; overflow:hidden !important; margin-bottom:0 !important; }
.db-tab-btn { flex:1 !important; padding:13px 10px !important; font-size:13px !important; font-weight:600 !important; border:none !important; background:#f0f2f5 !important; color:#374151 !important; cursor:pointer !important; border-bottom:3px solid transparent !important; transition:all .2s !important; display:flex !important; align-items:center !important; justify-content:center !important; gap:6px !important; box-shadow:none !important; }
.db-tab-btn:hover { background:#e5e7eb !important; color:#111827 !important; }
.db-tab-btn.active { color:#00264D !important; border-bottom:3px solid #00264D !important; background:#ffffff !important; font-weight:700 !important; }

/* Tab content */
.db-tab-panel { display:none; background:#fff; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 12px 12px; }
.db-tab-panel.active { display:block; }

/* Form table */
.db-form-table { width:100%; border-collapse:collapse; }
.db-form-table thead tr { background:var(--petron-blue); color:#fff; }
.db-form-table thead th { padding:12px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; text-align:left; color:#fff; }
.db-form-table tbody tr { border-bottom:1px solid #f0f0f0; }
.db-form-table tbody tr:last-child { border-bottom:none; }
.db-form-table tbody td { padding:16px; vertical-align:top; }
.db-form-table .field-label { font-weight:600; font-size:14px; color:#1f2937; min-width:200px; }
.db-form-table .field-type-badge { display:inline-block; font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
.db-form-table .field-hint { font-size:11px; color:#9ca3af; margin-top:5px; }

/* Inputs */
.db-input { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; outline:none; transition:border-color .2s; box-sizing:border-box; }
.db-input:focus { border-color:var(--petron-blue); box-shadow:0 0 0 3px rgba(0,38,77,.08); }
.db-select { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; outline:none; background:#fff; cursor:pointer; box-sizing:border-box; }
.db-select:focus { border-color:var(--petron-blue); }
.db-textarea { width:100%; padding:10px 14px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; outline:none; resize:vertical; box-sizing:border-box; font-family:inherit; }
.db-textarea:focus { border-color:var(--petron-blue); }

/* Radio group */
.db-radio-group { display:flex; gap:16px; flex-wrap:wrap; margin-top:6px; }
.db-radio-label { display:flex; align-items:center; gap:6px; font-size:13px; cursor:pointer; }
.db-radio-label input { accent-color:var(--petron-blue); width:15px; height:15px; }

/* Action bar */
.db-action-bar { padding:16px 20px; background:#f9fafb; border-top:1px solid #e5e7eb; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.db-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .2s; text-decoration:none; }
.db-btn-primary { background:white !important; color:#00264D !important; border:1px solid #00264D !important; }
.db-btn-primary:hover { background:#00264D !important; color:white !important; }
.db-btn-danger  { background:white !important; color:#dc2626 !important; border:1px solid #dc2626 !important; }
.db-btn-danger:hover  { background:#dc2626 !important; color:white !important; }
.db-btn-success { background:white !important; color:#16a34a !important; border:1px solid #16a34a !important; }
.db-btn-success:hover { background:#16a34a !important; color:white !important; }
.db-btn-secondary { background:white !important; color:#374151 !important; border:1px solid #d1d5db !important; }
.db-btn-secondary:hover { background:#f1f5f9 !important; color:#111827 !important; }

/* History table */
.db-history-table { width:100%; border-collapse:collapse; font-size:13px; }
.db-history-table thead th { background:#f9fafb; padding:10px 14px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; color:#6b7280; border-bottom:1px solid #e5e7eb; }
.db-history-table tbody td { padding:11px 14px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
.db-history-table tbody tr:last-child td { border-bottom:none; }
.db-history-table tbody tr:hover { background:#f9fafb; }
.badge-ok   { background:#d1fae5; color:#065f46; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-fail { background:#fee2e2; color:#991b1b; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; }
.badge-info { background:#dbeafe; color:#1e40af; padding:3px 8px; border-radius:20px; font-size:11px; font-weight:600; }

/* Section header inside panel */
.db-panel-header { padding:14px 20px; background:#f1f5f9; color:var(--petron-blue); border-bottom:2px solid #e2e8f0; display:flex; align-items:center; gap:8px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.db-panel-header i { color:#00264D; }
.db-section-title { padding:14px 20px; background:#f9fafb; border-bottom:1px solid #e5e7eb; font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.4px; margin:0; }
</style>

<div class="db-page">

<!-- Flash messages -->
<?php if ($success): ?>
<div class="db-flash success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="db-flash error"><i class="fas fa-exclamation-circle"></i> <?php echo $msg; ?></div>
<?php endif; ?>

<!-- Page head -->
<div class="db-page-head">
    <h1><i class="fas fa-database" style="margin-right:8px;"></i>Database Management</h1>
    <div class="sub">Complete database control panel for backup, restore, schema updates, replication, and security monitoring.</div>
</div>

<!-- Station Selection -->
<div class="db-station-card card" id="tb_station_combo_card">
    <div class="card-header">
        <h3><i class="fas fa-map-marker-alt" style="color:#3b82f6;margin-right:6px;"></i> Station-Dependent Configuration</h3>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:15px;position:relative;">
            <span class="label">Search Station</span>
            <div class="am-combo am-combo-toolbar" id="tb_station_combo" style="width:450px;position:relative;z-index:100;">
                <input type="text" class="am-combo-input" id="tb_station_display" placeholder="Type to search stations or select All Stations..." autocomplete="off" style="padding-right:35px;cursor:text;">
                <button type="button" class="am-combo-clear" id="tb_station_clear" tabindex="-1" title="Clear filter"><i class="fas fa-times"></i></button>
                <i class="fas fa-chevron-down am-combo-arrow"></i>
                <input type="hidden" id="tb_station_val">
                <div class="am-combo-dropdown" id="tb_station_dropdown">
                    <div class="am-combo-list" id="tb_station_list"></div>
                </div>
            </div>
        </div>
        <select id="stationSelect" style="display:none;">
            <option value="">All Stations (Global Operations)</option>
            <?php foreach($stations as $st): ?>
            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Tabs -->
<div class="db-tabs">
    <button class="db-tab-btn active" onclick="dbTab('backup',this)"><i class="fas fa-save"></i> Backup</button>
    <button class="db-tab-btn" onclick="dbTab('restore',this)"><i class="fas fa-undo"></i> Restore</button>
    <button class="db-tab-btn" onclick="dbTab('schema',this)"><i class="fas fa-table"></i> Schema &amp; Migrations</button>
    <button class="db-tab-btn" onclick="dbTab('replication',this)"><i class="fas fa-sync"></i> Replication</button>
    <button class="db-tab-btn" onclick="dbTab('security',this)"><i class="fas fa-shield-alt"></i> Security Logs</button>
</div>
<!-- ═══════════════ BACKUP TAB ═══════════════ -->
<div id="panel-backup" class="db-tab-panel active">
    <div class="db-panel-header"><i class="fas fa-save"></i> Database Backup Configuration</div>
    <form method="POST" action="">
        <input type="hidden" name="action" value="save_backup_config">
        <table class="db-form-table">
            <thead><tr><th style="width:30%">Field Name</th><th>Value / Input</th></tr></thead>
            <tbody>
                <tr>
                    <td class="field-label">Backup Frequency</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="backup_frequency" class="db-select" style="max-width:400px;">
                            <option value="manual" <?php if($cfg_backup_frequency==='manual') echo 'selected'; ?>>Manual Only</option>
                            <option value="daily"  <?php if($cfg_backup_frequency==='daily')  echo 'selected'; ?>>Daily</option>
                            <option value="weekly" <?php if($cfg_backup_frequency==='weekly') echo 'selected'; ?>>Weekly</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Storage Location</td>
                    <td>
                        <span class="field-type-badge">Text Field</span>
                        <input type="text" name="storage_location" class="db-input" style="max-width:500px;"
                            value="<?php echo htmlspecialchars($cfg_storage_location); ?>"
                            placeholder="e.g. /var/backups/petron or cloud://bucket-name">
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Retention Period</td>
                    <td>
                        <span class="field-type-badge">Numeric Field</span>
                        <input type="number" name="retention_days" class="db-input" style="max-width:180px;"
                            value="<?php echo (int)$cfg_retention_days; ?>" min="1" max="3650">
                        <div class="field-hint">days to keep backup</div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="db-action-bar">
            <button type="submit" class="db-btn db-btn-primary"><i class="fas fa-save"></i> Save Configuration</button>
        </div>
    </form>

    <!-- Trigger Manual Backup -->
    <div class="db-section-title"><i class="fas fa-play-circle" style="margin-right:6px;"></i>Run Manual Backup Now</div>
    <div style="padding:18px 20px;">
        <p style="font-size:13px;color:#555;margin:0 0 14px;">Click the button below to record a manual backup entry. Ensure your server has mysqldump configured for actual file creation.</p>
        <form method="POST" action="" onsubmit="return confirm('Trigger a manual backup now?');">
            <input type="hidden" name="action" value="run_backup">
            <button type="submit" class="db-btn db-btn-success"><i class="fas fa-database"></i> Trigger Backup Now</button>
        </form>
    </div>

    <!-- Backup History -->
    <div class="db-section-title"><i class="fas fa-history" style="margin-right:6px;"></i>Backup History</div>
    <div style="padding:0 20px 20px;">
        <?php if(empty($backup_history)): ?>
            <p style="color:#9ca3af;text-align:center;padding:30px 0;font-size:13px;"><i class="fas fa-inbox" style="display:block;font-size:32px;margin-bottom:10px;opacity:.4;"></i>No backups recorded yet.</p>
        <?php else: ?>
        <table class="db-history-table">
            <thead><tr><th>#</th><th>Filename</th><th>Size</th><th>Created At</th></tr></thead>
            <tbody>
            <?php foreach($backup_history as $i=>$bk): ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td><i class="fas fa-file-archive" style="color:#6b7280;margin-right:6px;"></i><?php echo htmlspecialchars($bk['filename']); ?></td>
                <td><?php echo $bk['file_size'] > 0 ? number_format($bk['file_size']/1048576,2).' MB' : '—'; ?></td>
                <td><?php echo date('M d, Y g:i A', strtotime($bk['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════ RESTORE TAB ═══════════════ -->
<div id="panel-restore" class="db-tab-panel">
    <div class="db-panel-header"><i class="fas fa-undo"></i> Database Restore</div>
    <form method="POST" action="" id="restoreForm"
          onsubmit="return confirm('⚠️ WARNING: This will log a restore request from the selected backup.\n\nAre you absolutely sure?');">
        <input type="hidden" name="action" value="restore">
        <table class="db-form-table">
            <thead><tr><th style="width:30%">Field Name</th><th>Value / Input</th></tr></thead>
            <tbody>
                <tr>
                    <td class="field-label">Backup File</td>
                    <td>
                        <span class="field-type-badge">File Selector</span>
                        <?php if(empty($available_backups)): ?>
                            <p style="color:#9ca3af;font-size:13px;"><i class="fas fa-exclamation-triangle"></i> No backup files found. Run a backup first.</p>
                        <?php else: ?>
                        <select name="backup_filename" class="db-select" style="max-width:600px;" required>
                            <option value="">— Select a backup to restore —</option>
                            <?php foreach($available_backups as $bk): ?>
                            <option value="<?php echo htmlspecialchars($bk['filename']); ?>">
                                <?php echo htmlspecialchars($bk['filename']); ?>
                                (<?php echo $bk['file_size'] > 0 ? number_format($bk['file_size']/1048576,2).' MB' : 'size unknown'; ?> —
                                <?php echo date('M d, Y g:i A', strtotime($bk['created_at'])); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Restore Scope</td>
                    <td>
                        <span class="field-type-badge">Radio Button</span>
                        <div class="db-radio-group">
                            <label class="db-radio-label"><input type="radio" name="restore_scope" value="full" checked> Full Database</label>
                            <label class="db-radio-label"><input type="radio" name="restore_scope" value="specific"> Specific Tables Only</label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Confirmation</td>
                    <td>
                        <span class="field-type-badge">Required Checkbox</span>
                        <label class="db-radio-label" style="align-items:flex-start;gap:10px;">
                            <input type="checkbox" name="confirm_restore" value="1" style="margin-top:2px;accent-color:#cc0000;width:15px;height:15px;">
                            <span style="font-size:13px;color:#cc0000;font-weight:600;">I understand this will overwrite existing data. This action cannot be undone.</span>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="db-action-bar">
            <button type="submit" class="db-btn db-btn-danger"><i class="fas fa-undo"></i> Restore Database</button>
            <span style="font-size:12px;color:#9ca3af;"><i class="fas fa-info-circle"></i> Actual file restore requires server-side access.</span>
        </div>
    </form>

    <!-- Restore History -->
    <div class="db-section-title"><i class="fas fa-history" style="margin-right:6px;"></i>Restore History</div>
    <div style="padding:0 20px 20px;">
        <?php if(empty($restore_history)): ?>
            <p style="color:#9ca3af;text-align:center;padding:30px 0;font-size:13px;"><i class="fas fa-inbox" style="display:block;font-size:32px;margin-bottom:10px;opacity:.4;"></i>No restore records yet.</p>
        <?php else: ?>
        <table class="db-history-table">
            <thead><tr><th>#</th><th>Backup File</th><th>Status</th><th>Details</th><th>Restored By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach($restore_history as $i=>$rl): ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td><?php echo htmlspecialchars($rl['backup_name']); ?></td>
                <td><span class="badge-<?php echo $rl['status']==='success'?'ok':'fail'; ?>"><?php echo ucfirst($rl['status']); ?></span></td>
                <td style="font-size:12px;color:#6b7280;"><?php echo htmlspecialchars($rl['details'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars(($rl['first_name']??'').' '.($rl['last_name']??'')); ?></td>
                <td><?php echo date('M d, Y g:i A', strtotime($rl['restored_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<!-- ═══════════════ SCHEMA TAB ═══════════════ -->
<div id="panel-schema" class="db-tab-panel">
    <div class="db-panel-header"><i class="fas fa-table"></i> Schema Updates &amp; Migrations</div>
    <form method="POST" action="" onsubmit="return confirm('Apply this migration to the database?');">
        <input type="hidden" name="action" value="apply_migration">
        <table class="db-form-table">
            <thead><tr><th style="width:30%">Field Name</th><th>Value / Input</th></tr></thead>
            <tbody>
                <tr>
                    <td class="field-label">Target Table</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="table_name" class="db-select" style="max-width:400px;" required>
                            <option value="">— Select Table —</option>
                            <?php foreach($all_tables as $t): ?>
                            <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Action</td>
                    <td>
                        <span class="field-type-badge">Radio Button</span>
                        <div class="db-radio-group">
                            <label class="db-radio-label"><input type="radio" name="migration_action" value="add_column" checked> Add Column</label>
                            <label class="db-radio-label"><input type="radio" name="migration_action" value="remove_column"> Remove Column</label>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Column Name</td>
                    <td>
                        <span class="field-type-badge">Text Field</span>
                        <input type="text" name="column_name" class="db-input" style="max-width:300px;" placeholder="e.g. discount_rate" required>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Data Type</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="data_type" class="db-select" style="max-width:280px;">
                            <option value="INT">INT</option>
                            <option value="BIGINT">BIGINT</option>
                            <option value="VARCHAR(255)" selected>VARCHAR(255)</option>
                            <option value="VARCHAR(100)">VARCHAR(100)</option>
                            <option value="TEXT">TEXT</option>
                            <option value="LONGTEXT">LONGTEXT</option>
                            <option value="DATE">DATE</option>
                            <option value="DATETIME">DATETIME</option>
                            <option value="TIMESTAMP">TIMESTAMP</option>
                            <option value="DECIMAL(10,2)">DECIMAL(10,2)</option>
                            <option value="FLOAT">FLOAT</option>
                            <option value="TINYINT(1)">TINYINT(1) (Boolean)</option>
                            <option value="ENUM('active','inactive')">ENUM (active/inactive)</option>
                        </select>
                        <div class="field-hint">Used only for Add Column action</div>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Description / Notes</td>
                    <td>
                        <span class="field-type-badge">Text Area</span>
                        <textarea name="description" class="db-textarea" style="max-width:500px;" rows="2" placeholder="Optional: describe what this migration does"></textarea>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="db-action-bar">
            <button type="submit" class="db-btn db-btn-primary"><i class="fas fa-code-branch"></i> Apply Migration</button>
            <span style="font-size:12px;color:#9ca3af;"><i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i> This runs live DDL on the database. Backup first.</span>
        </div>
    </form>

    <!-- Migration History -->
    <div class="db-section-title"><i class="fas fa-history" style="margin-right:6px;"></i>Migration History
        <?php if($current_version): ?>
        <span class="badge-info" style="float:right;margin-top:-2px;">Current Version: <?php echo htmlspecialchars($current_version['version']); ?></span>
        <?php endif; ?>
    </div>
    <div style="padding:0 20px 20px;">
        <?php if(empty($migration_history)): ?>
            <p style="color:#9ca3af;text-align:center;padding:30px 0;font-size:13px;"><i class="fas fa-inbox" style="display:block;font-size:32px;margin-bottom:10px;opacity:.4;"></i>No migrations recorded yet.</p>
        <?php else: ?>
        <table class="db-history-table">
            <thead><tr><th>#</th><th>Migration Name</th><th>Table</th><th>Action</th><th>Description</th><th>By</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach($migration_history as $i=>$mh): ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td style="font-size:12px;font-family:monospace;"><?php echo htmlspecialchars($mh['migration_name']); ?></td>
                <td><span class="badge-info"><?php echo htmlspecialchars($mh['table_name']??'—'); ?></span></td>
                <td><?php echo htmlspecialchars($mh['action']??'—'); ?></td>
                <td style="font-size:12px;color:#6b7280;max-width:200px;"><?php echo htmlspecialchars($mh['description']??''); ?></td>
                <td><?php echo htmlspecialchars(($mh['first_name']??'').' '.($mh['last_name']??'')); ?></td>
                <td><?php echo date('M d, Y g:i A', strtotime($mh['executed_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════ REPLICATION TAB ═══════════════ -->
<div id="panel-replication" class="db-tab-panel">
    <div class="db-panel-header"><i class="fas fa-sync"></i> Replication Control</div>
    <form method="POST" action="">
        <input type="hidden" name="action" value="save_replication">
        <table class="db-form-table">
            <thead><tr><th style="width:30%">Field Name</th><th>Value / Input</th></tr></thead>
            <tbody>
                <tr>
                    <td class="field-label">Enable Replication</td>
                    <td>
                        <span class="field-type-badge">Toggle</span>
                        <label class="db-radio-label">
                            <input type="checkbox" name="rep_enabled" value="1"
                                <?php echo cfg_get($pdo,'replication_enabled','0')==='1'?'checked':''; ?>
                                style="accent-color:var(--petron-blue);width:16px;height:16px;">
                            <span style="font-size:13px;">Enable station data synchronisation</span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Station ID</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="rep_station_id" class="db-select" style="max-width:450px;">
                            <option value="">— Global (All Stations) —</option>
                            <?php foreach($stations as $st): ?>
                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="field-hint">Select a specific station or leave blank for global replication settings</div>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Sync Frequency</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="sync_frequency" class="db-select" style="max-width:300px;">
                            <option value="realtime"  <?php if($cfg_sync_freq==='realtime')  echo 'selected'; ?>>Real-time</option>
                            <option value="hourly"    <?php if($cfg_sync_freq==='hourly')    echo 'selected'; ?>>Hourly</option>
                            <option value="daily"     <?php if($cfg_sync_freq==='daily')     echo 'selected'; ?>>Scheduled (Daily)</option>
                            <option value="weekly"    <?php if($cfg_sync_freq==='weekly')    echo 'selected'; ?>>Scheduled (Weekly)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Conflict Resolution</td>
                    <td>
                        <span class="field-type-badge">Radio Button</span>
                        <div class="db-radio-group">
                            <label class="db-radio-label"><input type="radio" name="conflict_resolution" value="overwrite" <?php if($cfg_conflict==='overwrite') echo 'checked'; ?>> Overwrite (Server wins)</label>
                            <label class="db-radio-label"><input type="radio" name="conflict_resolution" value="merge"     <?php if($cfg_conflict==='merge')     echo 'checked'; ?>> Merge (Keep both)</label>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="db-action-bar">
            <button type="submit" class="db-btn db-btn-primary"><i class="fas fa-save"></i> Save Replication Settings</button>
        </div>
    </form>

    <!-- Per-station replication status -->
    <div class="db-section-title"><i class="fas fa-list" style="margin-right:6px;"></i>Per-Station Replication Status</div>
    <div style="padding:0 20px 20px;">
        <?php if(empty($rep_status)): ?>
            <p style="color:#9ca3af;text-align:center;padding:30px 0;font-size:13px;"><i class="fas fa-inbox" style="display:block;font-size:32px;margin-bottom:10px;opacity:.4;"></i>No replication records yet.</p>
        <?php else: ?>
        <table class="db-history-table">
            <thead><tr><th>Station</th><th>Status</th><th>Sync Frequency</th><th>Conflict Resolution</th><th>Last Sync</th></tr></thead>
            <tbody>
            <?php foreach($rep_status as $rs): ?>
            <tr>
                <td><i class="fas fa-building" style="color:#9ca3af;margin-right:6px;"></i><?php echo htmlspecialchars($rs['station_name']??'Unknown'); ?></td>
                <td><?php if($rs['status']==='enabled'): ?><span class="badge-ok">Enabled</span><?php else: ?><span class="badge-fail">Disabled</span><?php endif; ?></td>
                <td><?php echo htmlspecialchars(ucfirst($rs['sync_frequency']??'—')); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($rs['conflict_resolution']??'—')); ?></td>
                <td><?php echo $rs['last_sync_at'] ? date('M d, Y g:i A', strtotime($rs['last_sync_at'])) : '<span style="color:#9ca3af;">Never</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
<!-- ═══════════════ SECURITY LOGS TAB ═══════════════ -->
<div id="panel-security" class="db-tab-panel">
    <div class="db-panel-header"><i class="fas fa-shield-alt"></i> Security Logs</div>

    <!-- Filter form -->
    <form method="GET" action="" id="sec-filter-form">
        <table class="db-form-table">
            <thead><tr><th style="width:30%">Field Name</th><th>Value / Input</th></tr></thead>
            <tbody>
                <tr>
                    <td class="field-label">Date Range</td>
                    <td>
                        <span class="field-type-badge">Date Picker</span>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <input type="date" name="date_from" class="db-input" style="max-width:180px;"
                                value="<?php echo htmlspecialchars($sec_date_from); ?>">
                            <span style="color:#9ca3af;font-size:13px;">to</span>
                            <input type="date" name="date_to" class="db-input" style="max-width:180px;"
                                value="<?php echo htmlspecialchars($sec_date_to); ?>">
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="field-label">Filter by User</td>
                    <td>
                        <span class="field-type-badge">Dropdown</span>
                        <select name="user_id" class="db-select" style="max-width:350px;">
                            <option value="">— All Users —</option>
                            <?php foreach($users_list as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php if($sec_user_id==$u['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($u['first_name'].' '.$u['last_name'].' ('.$u['role'].')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="db-action-bar">
            <button type="submit" class="db-btn db-btn-primary"><i class="fas fa-search"></i> Filter Logs</button>
            <a href="database_management.php?tab=security" class="db-btn db-btn-secondary"><i class="fas fa-times"></i> Clear</a>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <button type="button" onclick="exportSecLogs('csv')" class="db-btn db-btn-success"><i class="fas fa-file-excel"></i> Export Excel</button>
                <button type="button" onclick="window.print()" class="db-btn db-btn-secondary"><i class="fas fa-file-pdf"></i> Print / PDF</button>
            </div>
        </div>
    </form>

    <!-- Results -->
    <div class="db-section-title">
        <i class="fas fa-list" style="margin-right:6px;"></i>Activity Log Results
        <span style="float:right;font-size:12px;color:#9ca3af;font-weight:400;text-transform:none;">Showing <?php echo count($security_logs); ?> records</span>
    </div>
    <div style="padding:0 20px 20px;overflow-x:auto;">
        <?php if(empty($security_logs)): ?>
            <p style="color:#9ca3af;text-align:center;padding:30px 0;font-size:13px;"><i class="fas fa-inbox" style="display:block;font-size:32px;margin-bottom:10px;opacity:.4;"></i>No log entries match your filters.</p>
        <?php else: ?>
        <table class="db-history-table" id="sec-logs-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date &amp; Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($security_logs as $i=>$log): ?>
            <tr>
                <td style="color:#9ca3af;"><?php echo $i+1; ?></td>
                <td style="white-space:nowrap;"><?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?></td>
                <td>
                    <?php if($log['first_name']): ?>
                        <div style="font-weight:600;font-size:13px;"><?php echo htmlspecialchars($log['first_name'].' '.$log['last_name']); ?></div>
                        <div style="font-size:11px;color:#9ca3af;">ID: <?php echo $log['user_id']; ?></div>
                    <?php else: ?>
                        <span style="color:#9ca3af;">System</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge-info"><?php echo htmlspecialchars($log['action']); ?></span></td>
                <td style="font-size:12px;color:#6b7280;max-width:300px;"><?php echo htmlspecialchars($log['details']??''); ?></td>
                <td style="font-family:monospace;font-size:12px;color:#9ca3af;"><?php echo htmlspecialchars($log['ip_address']??'—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.db-page -->

<!-- ═══════════════ JAVASCRIPT ═══════════════ -->
<script>
// ── Tab switching ─────────────────────────────────────────────────────
function dbTab(name, btn) {
    document.querySelectorAll('.db-tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.db-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    btn.classList.add('active');
}

// Auto-open correct tab on load (e.g. after form POST with #tab anchor or ?tab= param)
(function() {
    var tab = new URLSearchParams(window.location.search).get('tab');
    if (!tab) {
        var hash = window.location.hash.replace('#','');
        if (hash) tab = hash;
    }
    if (tab) {
        var btn = document.querySelector('.db-tab-btn[onclick*="' + tab + '"]');
        if (btn) dbTab(tab, btn);
    }
})();

// ── Export security logs to CSV ───────────────────────────────────────
function exportSecLogs(fmt) {
    var table = document.getElementById('sec-logs-table');
    if (!table) { alert('No data to export.'); return; }
    var rows = table.querySelectorAll('tr');
    var csv = [];
    rows.forEach(function(row) {
        var cols = row.querySelectorAll('th,td');
        var line = [];
        cols.forEach(function(c) { line.push('"' + c.innerText.replace(/"/g,'""') + '"'); });
        csv.push(line.join(','));
    });
    var blob = new Blob([csv.join('\n')], {type:'text/csv'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'security_logs_<?php echo date('Ymd_His'); ?>.csv';
    a.click();
}

// ══════════════════════════════════════════════════════════════
// STATION COMBOBOX (same as module_configuration.php)
// ══════════════════════════════════════════════════════════════
const STATION_DATA = <?php echo json_encode(
    array_map(fn($s) => ['id'=>(int)$s['id'],'name'=>$s['name']], $stations),
    JSON_UNESCAPED_UNICODE|JSON_HEX_TAG
); ?>;

(function initStationCombo() {
    const combo   = document.getElementById('tb_station_combo');
    const list    = document.getElementById('tb_station_list');
    const display = document.getElementById('tb_station_display');
    const hidden  = document.getElementById('tb_station_val');
    const clear   = document.getElementById('tb_station_clear');
    const selEl   = document.getElementById('stationSelect');
    if (!combo||!display||!list||!hidden||!clear) return;

    let currentVal='', currentLabel='All Stations (Global Operations)';
    const MAX=100;

    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

    function render(q){
        const lq=(q||'').toLowerCase().trim();
        list.innerHTML='';
        if(!lq){
            const all=document.createElement('div');
            all.className='am-combo-option'+(!currentVal?' selected':'');
            all.dataset.value=''; all.dataset.label='All Stations (Global Operations)';
            all.style.cssText='font-style:italic;color:#888;';
            all.textContent='All Stations (Global Operations)';
            list.appendChild(all);
        }
        const filtered=lq?STATION_DATA.filter(s=>s.name.toLowerCase().includes(lq)):STATION_DATA;
        function appendMore(){
            const cur=list.querySelectorAll('.am-combo-option[data-value]').length;
            filtered.slice(cur,cur+MAX).forEach(s=>{
                const div=document.createElement('div');
                div.className='am-combo-option'+(currentVal==s.id?' selected':'');
                div.dataset.value=s.id; div.dataset.label=s.name;
                div.innerHTML='<i class="fas fa-building opt-icon"></i> '+esc(s.name);
                list.appendChild(div);
            });
        }
        appendMore();
        list.onscroll=()=>{ if(list.scrollTop+list.clientHeight>=list.scrollHeight-50) appendMore(); };
        if(filtered.length===0){
            const e=document.createElement('div');
            e.className='am-combo-empty';
            e.textContent='No station matching "'+q+'"';
            list.appendChild(e);
        }
    }

    function pick(val,lbl){
        currentVal=val; currentLabel=val?lbl:'All Stations (Global Operations)';
        hidden.value=val; display.value=val?lbl:'All Stations (Global Operations)';
        display.classList.toggle('has-value',!!val);
        clear.style.display=val?'block':'none';
        combo.classList.remove('open');
        if(selEl){selEl.value=val; selEl.dispatchEvent(new Event('change'));}
    }

    function open(){
        combo.classList.add('open');
        display.value='';
        const dd=combo.querySelector('.am-combo-dropdown');
        if(dd){const r=combo.getBoundingClientRect();dd.style.left=r.left+'px';dd.style.top=(r.bottom+4)+'px';dd.style.width=r.width+'px';}
        render('');
    }
    function close(){combo.classList.remove('open');display.value=currentVal?currentLabel:'All Stations (Global Operations)';}

    display.addEventListener('click',()=>combo.classList.contains('open')?close():open());
    display.addEventListener('focus',()=>{if(!combo.classList.contains('open'))open();});
    let dbt;
    display.addEventListener('input',()=>{
        if(!combo.classList.contains('open'))combo.classList.add('open');
        clearTimeout(dbt); dbt=setTimeout(()=>render(display.value),130);
    });
    display.addEventListener('keydown',e=>{
        if(!combo.classList.contains('open')&&(e.key==='ArrowDown'||e.key==='ArrowUp')){e.preventDefault();open();return;}
        const opts=[...list.querySelectorAll('.am-combo-option[data-value]')];
        const foc=list.querySelector('.am-combo-option.focused');
        let idx=foc?opts.indexOf(foc):-1;
        if(e.key==='ArrowDown'){e.preventDefault();idx=Math.min(idx+1,opts.length-1);}
        else if(e.key==='ArrowUp'){e.preventDefault();idx=Math.max(idx-1,0);}
        else if(e.key==='Enter'&&foc){e.preventDefault();pick(foc.dataset.value,foc.dataset.label);return;}
        else if(e.key==='Escape'){close();return;}
        else return;
        opts.forEach(o=>o.classList.remove('focused'));
        if(opts[idx]){opts[idx].classList.add('focused');opts[idx].scrollIntoView({block:'nearest'});}
    });
    list.addEventListener('click',e=>{const o=e.target.closest('.am-combo-option');if(o)pick(o.dataset.value,o.dataset.label);});
    list.addEventListener('mouseover',e=>{const o=e.target.closest('.am-combo-option');if(o){list.querySelectorAll('.am-combo-option').forEach(x=>x.classList.remove('focused'));o.classList.add('focused');}});
    clear.addEventListener('click',e=>{e.stopPropagation();pick('','');});
    document.addEventListener('click',e=>{if(!combo.contains(e.target))close();});
    pick('','');
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>