<?php
// ============================================================
// SuperAdmin – Database Management
// public/superadmin_database_management.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'database_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/database_management.php';
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

// ── Active section from URL ───────────────────────────────────
$active_tab = $_GET['section'] ?? ($_GET['tab'] ?? 'view_tables'); // support both params
$allowed_tabs = ['view_tables', 'maintenance', 'soft_delete'];
if (!in_array($active_tab, $allowed_tabs)) $active_tab = 'view_tables';

// Set page_id to match the active sub-item so sidebar highlights correctly
$page_id = match($active_tab) {
    'view_tables'  => 'dbm_view_tables',
    'maintenance'  => 'dbm_maintenance',
    'soft_delete'  => 'dbm_soft_delete',
    default        => 'dbm_view_tables',
};

// ── Handle POST actions ───────────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_csrf = $_POST['csrf_token'] ?? '';
    if (empty($post_csrf) || $post_csrf !== $csrf) {
        $flash = ['type' => 'error', 'msg' => 'Invalid CSRF token.'];
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'backup') {
            $backup_type = in_array($_POST['backup_type'] ?? '', ['full', 'partial']) ? $_POST['backup_type'] : 'full';
            $result = DatabaseManagement::createBackup($backup_type);
            if ($result['success']) {
                log_activity($pdo, $me['id'], 'DB Backup', "Created {$backup_type} backup: {$result['filename']}");
                $flash = ['type' => 'success', 'msg' => "Backup created: <strong>{$result['filename']}</strong> ({$result['size']})"];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Backup failed: ' . htmlspecialchars($result['error'])];
            }
            $active_tab = 'maintenance';

        } elseif ($action === 'restore') {
            $filename = basename($_POST['backup_file'] ?? '');
            if (empty($filename)) {
                $flash = ['type' => 'error', 'msg' => 'No backup file selected.'];
            } else {
                $result = DatabaseManagement::restoreFromBackup($filename);
                if ($result['success']) {
                    log_activity($pdo, $me['id'], 'DB Restore', "Restored from backup: {$filename}");
                    $flash = ['type' => 'success', 'msg' => "Restore complete from <strong>{$filename}</strong>. {$result['statements_executed']} statements executed."];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Restore failed: ' . htmlspecialchars($result['error'])];
                }
            }
            $active_tab = 'maintenance';

        } elseif ($action === 'optimize') {
            $result = DatabaseManagement::optimizeDatabase();
            if ($result['success']) {
                log_activity($pdo, $me['id'], 'DB Optimize', "Optimized {$result['tables_processed']} tables");
                $flash = ['type' => 'success', 'msg' => "Optimization complete. <strong>{$result['tables_processed']}</strong> tables processed."];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Optimization failed: ' . htmlspecialchars($result['error'])];
            }
            $active_tab = 'maintenance';

        } elseif ($action === 'indexing') {
            $result = DatabaseManagement::runIndexing();
            if ($result['success']) {
                log_activity($pdo, $me['id'], 'DB Indexing', "Re-indexed {$result['indexes_processed']} tables");
                $flash = ['type' => 'success', 'msg' => "Indexing complete. <strong>{$result['indexes_processed']}</strong> tables re-indexed."];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Indexing failed: ' . htmlspecialchars($result['error'])];
            }
            $active_tab = 'maintenance';

        } elseif ($action === 'delete_backup') {
            $filename = basename($_POST['backup_file'] ?? '');
            $result = DatabaseManagement::deleteBackup($filename);
            if ($result['success']) {
                log_activity($pdo, $me['id'], 'DB Backup Deleted', "Deleted backup: {$filename}");
                $flash = ['type' => 'success', 'msg' => "Backup <strong>{$filename}</strong> deleted."];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Delete failed: ' . htmlspecialchars($result['error'])];
            }
            $active_tab = 'maintenance';

        } elseif ($action === 'soft_delete') {
            $table    = trim($_POST['table'] ?? '');
            $rec_id   = (int)($_POST['record_id'] ?? 0);
            if (empty($table) || $rec_id <= 0) {
                $flash = ['type' => 'error', 'msg' => 'Invalid table or record ID.'];
            } else {
                $result = DatabaseManagement::softDelete($table, $rec_id, $me['id'], $role);
                if ($result['success']) {
                    log_activity($pdo, $me['id'], 'DB Soft Delete', "Soft-deleted record #{$rec_id} from {$table}");
                    $flash = ['type' => 'success', 'msg' => "Record <strong>#{$rec_id}</strong> in <strong>{$table}</strong> marked as inactive."];
                } else {
                    $flash = ['type' => 'error', 'msg' => 'Soft delete failed: ' . htmlspecialchars($result['error'])];
                }
            }
            $active_tab = 'soft_delete';

        } elseif ($action === 'restore_soft_deleted') {
            $table  = trim($_POST['table'] ?? '');
            $rec_id = (int)($_POST['record_id'] ?? 0);
            $result = DatabaseManagement::restoreSoftDeleted($table, $rec_id, $me['id']);
            if ($result['success']) {
                log_activity($pdo, $me['id'], 'DB Restore Record', "Restored record #{$rec_id} in {$table}");
                $flash = ['type' => 'success', 'msg' => "Record <strong>#{$rec_id}</strong> restored to active."];
            } else {
                $flash = ['type' => 'error', 'msg' => 'Restore failed: ' . htmlspecialchars($result['error'])];
            }
            $active_tab = 'soft_delete';
        }
    }
}

// ── Data for View Tables tab ──────────────────────────────────
$all_tables    = DatabaseManagement::getAllTables();
$current_table = trim($_GET['table'] ?? '');
$search_q      = trim($_GET['search'] ?? '');   // always defined
$table_data    = null;
$table_struct  = null;
if ($current_table !== '' && in_array($current_table, $all_tables, true)) {
    $page_num   = max(1, (int)($_GET['page'] ?? 1));
    $table_data   = DatabaseManagement::getTableData($current_table, $page_num, 50, $search_q);
    $table_struct = DatabaseManagement::getTableStructure($current_table);
}

// ── Data for Maintenance tab ──────────────────────────────────
$backups = DatabaseManagement::getBackups();

// ── Data for Soft Delete tab ──────────────────────────────────
$sd_table  = trim($_GET['sd_table'] ?? '');
$sd_data   = null;
if ($sd_table !== '' && in_array($sd_table, $all_tables, true)) {
    $sd_data = DatabaseManagement::getSoftDeletedRecords($sd_table, 1, 50);
}

// ── Data for Audit Trail tab ──────────────────────────────────
$audit_rows = [];
try {
    $audit_rows = $pdo->query(
        "SELECT al.id, al.user_id, al.action, al.details, al.created_at, COALESCE(NULLIF(CONCAT(u.first_name,' ',u.last_name),' '), u.username, 'Unknown') AS user_name
         FROM activity_logs al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action LIKE 'DB%'
         ORDER BY al.created_at DESC LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── DB Management Styles (dbm- prefix) ── */
.dbm-page { padding: 28px 24px; }
.dbm-head { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:24px; }
.dbm-head h1 { font-size:22px!important; font-weight:700!important; color:var(--petron-blue)!important; margin:0!important; text-transform:uppercase!important; }
.dbm-head .sub { font-size:13px; color:#666; margin-top:4px; text-transform:none!important; }

/* Steps */
.dbm-steps { display:flex; gap:0; margin-bottom:24px; background:#fff; border:1px solid #eaeaea; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.dbm-step { flex:1; display:flex; align-items:center; gap:10px; padding:14px 18px; font-size:12px; font-weight:600; color:#aaa; border-right:1px solid #f0f0f0; cursor:pointer; transition:all .2s; }
.dbm-step:last-child { border-right:none; }
.dbm-step .sn { width:26px; height:26px; border-radius:50%; background:#eee; color:#aaa; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; transition:all .2s; }
.dbm-step.active { color:var(--petron-blue); background:rgba(0,38,77,.04); }
.dbm-step.active .sn { background:var(--petron-blue); color:#fff; }
.dbm-step.done .sn { background:#28a745; color:#fff; }
.dbm-step.done { color:#28a745; }
.dbm-step-label .st { display:block; font-size:12px; font-weight:700; }
.dbm-step-label .sd { display:block; font-size:10px; font-weight:400; opacity:.7; margin-top:1px; }
@media(max-width:700px){ .dbm-step-label .sd { display:none; } .dbm-step { padding:12px 10px; gap:7px; } }



/* Card */
.dbm-card { background:#fff; border:1px solid #eaeaea; border-radius:14px; box-shadow:0 2px 8px rgba(0,0,0,.04); overflow:hidden; }
.dbm-card-header { padding:18px 22px; border-bottom:1px solid #eee; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.dbm-card-header h3 { font-size:14px!important; font-weight:700!important; color:var(--petron-blue)!important; margin:0!important; text-transform:uppercase!important; display:flex; align-items:center; gap:8px; }
.dbm-card-body { padding:20px 22px; }

/* Table */
.dbm-table-wrap { overflow-x:auto; border-radius:10px; border:1px solid #eee; }
.dbm-table { width:100%; border-collapse:collapse; font-size:12px; min-width:600px; }
.dbm-table thead th { background:var(--petron-blue); color:#fff; padding:10px 14px; text-align:left; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.3px; white-space:nowrap; }
.dbm-table tbody tr { border-bottom:1px solid #f5f5f5; transition:background .12s; }
.dbm-table tbody tr:last-child { border-bottom:none; }
.dbm-table tbody tr:hover { background:#f8fafc; }
.dbm-table td { padding:9px 14px; vertical-align:middle; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* Stats row */
.dbm-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:20px; }
.dbm-stat { background:#fff; border:1px solid #eaeaea; border-radius:12px; padding:16px 18px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 6px rgba(0,0,0,.04); }
.dbm-stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
.dbm-stat-val { font-size:22px; font-weight:800; color:var(--petron-blue); line-height:1; }
.dbm-stat-lbl { font-size:11px; color:#888; margin-top:2px; }

/* Maintenance action cards */
.dbm-actions { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; }
.dbm-action-card { background:#fff; border:1px solid #eaeaea; border-radius:14px; padding:20px; display:flex; flex-direction:column; gap:10px; box-shadow:0 2px 6px rgba(0,0,0,.04); transition:box-shadow .2s; }
.dbm-action-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.dbm-action-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; }
.dbm-action-title { font-size:14px; font-weight:700; color:#1a1a1a; }
.dbm-action-desc { font-size:12px; color:#888; line-height:1.5; flex:1; }
.dbm-action-limit { font-size:11px; color:#aaa; font-style:italic; }

/* Buttons */
.dbm-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:9px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; transition:all .2s; background:none; }
.dbm-btn-primary { background:var(--petron-blue); color:#fff; border-color:var(--petron-blue); }
.dbm-btn-primary:hover { background:#001a3d; }
.dbm-btn-success { background:#28a745; color:#fff; border-color:#28a745; }
.dbm-btn-success:hover { background:#1e7e34; }
.dbm-btn-warning { background:#e07b00; color:#fff; border-color:#e07b00; }
.dbm-btn-warning:hover { background:#b86200; }
.dbm-btn-danger  { color:#cc0000; border-color:#cc0000; }
.dbm-btn-danger:hover { background:rgba(204,0,0,.06); }
.dbm-btn-outline { color:var(--petron-blue); border-color:var(--petron-blue); }
.dbm-btn-outline:hover { background:rgba(0,38,77,.06); }
.dbm-btn-sm { padding:5px 11px; font-size:11px; border-radius:7px; }
.dbm-btn:disabled { opacity:.5; cursor:not-allowed; }

/* Flash */
.dbm-flash { padding:12px 16px; border-radius:10px; margin-bottom:18px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; }
.dbm-flash.success { background:rgba(40,167,69,.1); border:1px solid rgba(40,167,69,.3); color:#1a7a35; }
.dbm-flash.error   { background:rgba(204,0,0,.08);  border:1px solid rgba(204,0,0,.25); color:#cc0000; }
.dbm-flash.info    { background:rgba(0,38,77,.06);   border:1px solid rgba(0,38,77,.15); color:var(--petron-blue); }

/* Form controls */
.dbm-select, .dbm-input { padding:9px 13px; border:1px solid #ddd; border-radius:9px; font-size:13px; outline:none; background:#fff; transition:border-color .2s; }
.dbm-select:focus, .dbm-input:focus { border-color:var(--petron-blue); box-shadow:0 0 0 3px rgba(0,38,77,.08); }

/* Backup list */
.dbm-backup-row { display:flex; align-items:center; gap:12px; padding:11px 16px; border-bottom:1px solid #f5f5f5; font-size:13px; }
.dbm-backup-row:last-child { border-bottom:none; }
.dbm-backup-row:hover { background:#f8fafc; }
.dbm-backup-name { flex:1; font-weight:600; font-family:monospace; font-size:12px; color:#333; }
.dbm-backup-meta { font-size:11px; color:#888; }

/* Pagination */
.dbm-pagination { display:flex; align-items:center; gap:8px; padding:12px 0 0; flex-wrap:wrap; }
.dbm-page-btn { padding:5px 11px; border:1px solid #ddd; border-radius:7px; font-size:12px; cursor:pointer; background:#fff; transition:all .15s; }
.dbm-page-btn:hover { border-color:var(--petron-blue); color:var(--petron-blue); }
.dbm-page-btn.active { background:var(--petron-blue); color:#fff; border-color:var(--petron-blue); }

/* Info box */
.dbm-info { background:#f8fafc; border:1px solid #e8edf2; border-radius:10px; padding:11px 14px; font-size:12px; color:#555; display:flex; align-items:flex-start; gap:8px; margin-bottom:16px; }
.dbm-info i { color:var(--petron-blue); flex-shrink:0; margin-top:1px; }

/* Badge */
.dbm-badge { padding:2px 9px; border-radius:20px; font-size:11px; font-weight:600; }
.dbm-badge-active   { background:rgba(40,167,69,.12); color:#1a7a35; }
.dbm-badge-inactive { background:rgba(204,0,0,.1);    color:#cc0000; }
.dbm-badge-full     { background:rgba(0,38,77,.1);    color:var(--petron-blue); }
.dbm-badge-partial  { background:rgba(255,193,7,.15); color:#b8860b; }
</style>

<div class="dbm-page">

<?php if ($flash): ?>
<div class="dbm-flash <?php echo $flash['type']; ?>">
  <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':($flash['type']==='error'?'exclamation-circle':'info-circle'); ?>"></i>
  <?php echo $flash['msg']; ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="dbm-head">
  <div>
    <h1><i class="fas fa-database" style="margin-right:8px;"></i>Database Management
      <span style="font-size:14px;font-weight:500;color:#888;text-transform:none;margin-left:10px;">
        / <?php echo match($active_tab) {
            'view_tables'  => 'View Tables',
            'maintenance'  => 'Maintenance Scripts',
            'soft_delete'  => 'Soft Delete Records',
            'audit_trail'  => 'Audit Trail',
            default        => 'View Tables',
        }; ?>
      </span>
    </h1>
    <div class="sub">Maintain, monitor, and secure all system database operations. SuperAdmin / Developer only.</div>
  </div>
</div>

<!-- Step Progress -->
<div class="dbm-steps">
  <div class="dbm-step <?php echo $active_tab==='view_tables'?'active':'done'; ?>">
    <div class="sn"><?php echo $active_tab==='view_tables'?'1':'<i class="fas fa-check" style="font-size:9px;"></i>'; ?></div>
    <div class="dbm-step-label"><span class="st">View Tables</span><span class="sd">Read-only oversight</span></div>
  </div>
  <div class="dbm-step <?php echo $active_tab==='maintenance'?'active':($active_tab==='view_tables'?'':'done'); ?>">
    <div class="sn"><?php echo $active_tab==='maintenance'?'2':($active_tab==='view_tables'?'2':'<i class="fas fa-check" style="font-size:9px;"></i>'); ?></div>
    <div class="dbm-step-label"><span class="st">Maintenance</span><span class="sd">Backup, restore, optimize</span></div>
  </div>
  <div class="dbm-step <?php echo $active_tab==='soft_delete'?'active':($active_tab==='audit_trail'?'done':''); ?>">
    <div class="sn"><?php echo $active_tab==='soft_delete'?'3':($active_tab==='audit_trail'?'<i class="fas fa-check" style="font-size:9px;"></i>':'3'); ?></div>
    <div class="dbm-step-label"><span class="st">Soft Delete</span><span class="sd">Flag inactive records</span></div>
  </div>
  <div class="dbm-step <?php echo $active_tab==='audit_trail'?'active':''; ?>">
    <div class="sn"><?php echo $active_tab==='audit_trail'?'4':'4'; ?></div>
    <div class="dbm-step-label"><span class="st">Audit Trail</span><span class="sd">Full action log</span></div>
  </div>
</div>

<!-- Stats -->
<div class="dbm-stats">
  <div class="dbm-stat">
    <div class="dbm-stat-icon" style="background:rgba(0,38,77,.1);color:var(--petron-blue);"><i class="fas fa-table"></i></div>
    <div><div class="dbm-stat-val"><?php echo count($all_tables); ?></div><div class="dbm-stat-lbl">Managed Tables</div></div>
  </div>
  <div class="dbm-stat">
    <div class="dbm-stat-icon" style="background:rgba(40,167,69,.1);color:#28a745;"><i class="fas fa-save"></i></div>
    <div><div class="dbm-stat-val"><?php echo count($backups); ?></div><div class="dbm-stat-lbl">Backups Available</div></div>
  </div>
  <div class="dbm-stat">
    <div class="dbm-stat-icon" style="background:rgba(224,123,0,.1);color:#e07b00;"><i class="fas fa-history"></i></div>
    <div><div class="dbm-stat-val"><?php echo count($audit_rows); ?></div><div class="dbm-stat-lbl">Audit Records</div></div>
  </div>
  <div class="dbm-stat">
    <div class="dbm-stat-icon" style="background:rgba(111,66,193,.1);color:#6f42c1;"><i class="fas fa-user-shield"></i></div>
    <div><div class="dbm-stat-val"><?php echo htmlspecialchars($me['name']??'—'); ?></div><div class="dbm-stat-lbl">Logged In As</div></div>
  </div>
</div>

<!-- Main Card -->
<div class="dbm-card">

  <!-- ══ VIEW TABLES ══ -->
  <div id="tab-view_tables" class="tab-pane" style="display:<?php echo $active_tab==='view_tables'?'block':'none'; ?>;">
    <div class="dbm-card-header">
      <h3><i class="fas fa-table"></i> View Tables</h3>
    </div>
      <div class="dbm-info">
        <i class="fas fa-info-circle"></i>
        <span>Read-only view of all system tables. No data can be modified here. Use filters to inspect records for data integrity monitoring.</span>
      </div>
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
        <input type="hidden" name="section" value="view_tables">
        <select name="table" class="dbm-select" onchange="this.form.submit()">
          <option value="">— Select Table —</option>
          <?php foreach ($all_tables as $t): ?>
          <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $current_table===$t?'selected':''; ?>>
            <?php echo htmlspecialchars($t); ?>
          </option>
          <?php endforeach; ?>
        </select>
        <?php if ($current_table): ?>
        <input type="text" name="search" class="dbm-input" placeholder="Search records…" value="<?php echo htmlspecialchars($search_q??''); ?>" style="width:220px;">
        <button type="submit" class="dbm-btn dbm-btn-outline"><i class="fas fa-search"></i> Search</button>
        <?php endif; ?>
      </form>

      <?php if ($table_struct): ?>
      <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
        <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:10px 16px;font-size:12px;color:#555;">
          <strong>Engine:</strong> <?php echo htmlspecialchars($table_struct['engine']??'—'); ?>
        </div>
        <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:10px 16px;font-size:12px;color:#555;">
          <strong>Rows:</strong> <?php echo number_format($table_struct['rows']??0); ?>
        </div>
        <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:10px 16px;font-size:12px;color:#555;">
          <strong>Size:</strong> <?php echo htmlspecialchars($table_struct['size']??'—'); ?>
        </div>
        <div style="background:#f8fafc;border:1px solid #eee;border-radius:10px;padding:10px 16px;font-size:12px;color:#555;">
          <strong>Created:</strong> <?php echo htmlspecialchars($table_struct['created']??'—'); ?>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($table_data && !empty($table_data['data'])): ?>
      <div style="font-size:12px;color:#888;margin-bottom:8px;">
        Showing <?php echo count($table_data['data']); ?> of <?php echo number_format($table_data['total']); ?> records
        (Page <?php echo $table_data['page']; ?> of <?php echo $table_data['pages']; ?>)
      </div>
      <div class="dbm-table-wrap">
        <table class="dbm-table">
          <thead>
            <tr>
              <?php foreach (array_keys($table_data['data'][0]) as $col): ?>
              <th><?php echo htmlspecialchars($col); ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($table_data['data'] as $row): ?>
            <tr>
              <?php foreach ($row as $val): ?>
              <td title="<?php echo htmlspecialchars((string)($val??'')); ?>"><?php echo htmlspecialchars(mb_strimwidth((string)($val??''), 0, 60, '…')); ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <?php if ($table_data['pages'] > 1): ?>
      <div class="dbm-pagination">
        <?php for ($p = 1; $p <= min($table_data['pages'], 20); $p++): ?>
        <a href="?section=view_tables&table=<?php echo urlencode($current_table); ?>&page=<?php echo $p; ?>&search=<?php echo urlencode($search_q??''); ?>"
           class="dbm-page-btn <?php echo $p==$table_data['page']?'active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <?php elseif ($current_table): ?>
      <div style="text-align:center;padding:40px;color:#bbb;font-size:13px;">
        <i class="fas fa-table" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
        No records found<?php echo !empty($search_q) ? ' for "'.htmlspecialchars($search_q).'"' : ''; ?>.
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:40px;color:#bbb;font-size:13px;">
        <i class="fas fa-hand-point-up" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3;"></i>
        Select a table above to view its records.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ MAINTENANCE ══ -->
  <div id="tab-maintenance" class="tab-pane" style="display:<?php echo $active_tab==='maintenance'?'block':'none'; ?>;">
    <div class="dbm-card-header">
      <h3><i class="fas fa-tools"></i> Maintenance Scripts</h3>
    </div>
      <div class="dbm-info">
        <i class="fas fa-info-circle"></i>
        <span>Execute system maintenance commands. These operations affect DB structure and performance only — business data content is never altered.</span>
      </div>

      <div class="dbm-actions">
        <!-- Backup -->
        <div class="dbm-action-card">
          <div class="dbm-action-icon" style="background:rgba(0,38,77,.1);color:var(--petron-blue);"><i class="fas fa-save"></i></div>
          <div class="dbm-action-title">Backup</div>
          <div class="dbm-action-desc">Create a snapshot of the current DB state. Stored as a .sql file with timestamp.</div>
          <div class="dbm-action-limit"><i class="fas fa-lock" style="font-size:9px;"></i> Cannot alter business data content.</div>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="backup">
            <select name="backup_type" class="dbm-select" style="flex:1;min-width:100px;">
              <option value="full">Full Backup</option>
              <option value="partial">Partial Backup</option>
            </select>
            <button type="submit" class="dbm-btn dbm-btn-primary"><i class="fas fa-save"></i> Run</button>
          </form>
        </div>

        <!-- Restore -->
        <div class="dbm-action-card">
          <div class="dbm-action-icon" style="background:rgba(40,167,69,.1);color:#28a745;"><i class="fas fa-undo"></i></div>
          <div class="dbm-action-title">Restore</div>
          <div class="dbm-action-desc">Roll back to a previous snapshot. Select a backup file to restore from.</div>
          <div class="dbm-action-limit"><i class="fas fa-lock" style="font-size:9px;"></i> Only restores structure, not live transactions.</div>
          <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;" onsubmit="return confirm('Restore from this backup? This will overwrite current data.');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="restore">
            <select name="backup_file" class="dbm-select" style="flex:1;min-width:100px;" required>
              <option value="">— Select Backup —</option>
              <?php foreach ($backups as $b): ?>
              <option value="<?php echo htmlspecialchars($b['filename']); ?>"><?php echo htmlspecialchars($b['filename']); ?> (<?php echo $b['size']; ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="dbm-btn dbm-btn-success"><i class="fas fa-undo"></i> Restore</button>
          </form>
        </div>

        <!-- Optimize -->
        <div class="dbm-action-card">
          <div class="dbm-action-icon" style="background:rgba(224,123,0,.1);color:#e07b00;"><i class="fas fa-tachometer-alt"></i></div>
          <div class="dbm-action-title">Optimize</div>
          <div class="dbm-action-desc">Run ANALYZE + OPTIMIZE on all managed tables for faster query performance.</div>
          <div class="dbm-action-limit"><i class="fas fa-lock" style="font-size:9px;"></i> Structural optimization only.</div>
          <form method="POST" style="margin-top:4px;" onsubmit="return confirm('Run database optimization? This may take a moment.');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="optimize">
            <button type="submit" class="dbm-btn dbm-btn-warning"><i class="fas fa-tachometer-alt"></i> Run Optimization</button>
          </form>
        </div>

        <!-- Indexing -->
        <div class="dbm-action-card">
          <div class="dbm-action-icon" style="background:rgba(111,66,193,.1);color:#6f42c1;"><i class="fas fa-sitemap"></i></div>
          <div class="dbm-action-title">Re-Index</div>
          <div class="dbm-action-desc">Rebuild table indexes with REPAIR + ANALYZE to improve query speed and data integrity.</div>
          <div class="dbm-action-limit"><i class="fas fa-lock" style="font-size:9px;"></i> Index structure only, no data changes.</div>
          <form method="POST" style="margin-top:4px;" onsubmit="return confirm('Run re-indexing on all tables?');">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="indexing">
            <button type="submit" class="dbm-btn" style="color:#6f42c1;border-color:#6f42c1;"><i class="fas fa-sitemap"></i> Run Indexing</button>
          </form>
        </div>
      </div>

      <!-- Backup List -->
      <?php if (!empty($backups)): ?>
      <div style="margin-top:24px;">
        <div class="dbm-card-header" style="padding:14px 0 10px;border-bottom:1px solid #eee;margin-bottom:0;">
          <h3 style="font-size:13px!important;"><i class="fas fa-archive"></i> Existing Backups</h3>
        </div>
        <div style="border:1px solid #eee;border-radius:10px;overflow:hidden;margin-top:10px;">
          <?php foreach ($backups as $b): ?>
          <div class="dbm-backup-row">
            <i class="fas fa-file-code" style="color:#888;flex-shrink:0;"></i>
            <div class="dbm-backup-name"><?php echo htmlspecialchars($b['filename']); ?></div>
            <span class="dbm-badge <?php echo $b['type']==='Full'?'dbm-badge-full':'dbm-badge-partial'; ?>"><?php echo $b['type']; ?></span>
            <span class="dbm-backup-meta"><?php echo htmlspecialchars($b['size']); ?></span>
            <span class="dbm-backup-meta"><?php echo htmlspecialchars($b['created']); ?></span>
            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this backup permanently?');">
              <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
              <input type="hidden" name="action" value="delete_backup">
              <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($b['filename']); ?>">
              <button type="submit" class="dbm-btn dbm-btn-danger dbm-btn-sm"><i class="fas fa-trash"></i></button>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ SOFT DELETE ══ -->
  <div id="tab-soft_delete" class="tab-pane" style="display:<?php echo $active_tab==='soft_delete'?'block':'none'; ?>;">
    <div class="dbm-card-header">
      <h3><i class="fas fa-trash-restore"></i> Soft Delete Records</h3>
    </div>
      <div class="dbm-info">
        <i class="fas fa-info-circle"></i>
        <span>Flag records as <strong>Inactive</strong> instead of permanent deletion. Records are preserved in the DB for compliance and audit traceability. No data is ever permanently erased here.</span>
      </div>

      <!-- Soft Delete Form -->
      <div style="background:#fff8f0;border:1px solid #ffe0b2;border-radius:10px;padding:16px 18px;margin-bottom:20px;">
        <div style="font-size:13px;font-weight:700;color:#e07b00;margin-bottom:12px;"><i class="fas fa-trash-alt" style="margin-right:6px;"></i>Soft Delete a Record</div>
        <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;" onsubmit="return confirm('Mark this record as inactive? It will remain in the database.');">
          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
          <input type="hidden" name="action" value="soft_delete">
          <div>
            <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:4px;text-transform:uppercase;">Table</label>
            <select name="table" class="dbm-select" required>
              <option value="">— Select Table —</option>
              <?php foreach ($all_tables as $t): ?>
              <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:4px;text-transform:uppercase;">Record ID</label>
            <input type="number" name="record_id" class="dbm-input" placeholder="e.g. 42" min="1" required style="width:120px;">
          </div>
          <button type="submit" class="dbm-btn" style="background:#e07b00;color:#fff;border-color:#e07b00;"><i class="fas fa-ban"></i> Mark Inactive</button>
        </form>
      </div>

      <!-- View Soft Deleted Records -->
      <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:12px;"><i class="fas fa-list" style="margin-right:6px;color:#888;"></i>View Soft-Deleted Records</div>
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="hidden" name="section" value="soft_delete">
        <select name="sd_table" class="dbm-select" onchange="this.form.submit()">
          <option value="">— Select Table —</option>
          <?php foreach ($all_tables as $t): ?>
          <option value="<?php echo htmlspecialchars($t); ?>" <?php echo $sd_table===$t?'selected':''; ?>><?php echo htmlspecialchars($t); ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <?php if ($sd_data && !empty($sd_data['data'])): ?>
      <div class="dbm-table-wrap">
        <table class="dbm-table">
          <thead>
            <tr>
              <?php foreach (array_keys($sd_data['data'][0]) as $col): ?>
              <th><?php echo htmlspecialchars($col); ?></th>
              <?php endforeach; ?>
              <th>Restore</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sd_data['data'] as $row): ?>
            <tr>
              <?php foreach ($row as $val): ?>
              <td title="<?php echo htmlspecialchars((string)($val??'')); ?>"><?php echo htmlspecialchars(mb_strimwidth((string)($val??''), 0, 50, '…')); ?></td>
              <?php endforeach; ?>
              <td>
                <form method="POST" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                  <input type="hidden" name="action" value="restore_soft_deleted">
                  <input type="hidden" name="table" value="<?php echo htmlspecialchars($sd_table); ?>">
                  <input type="hidden" name="record_id" value="<?php echo (int)($row['id']??0); ?>">
                  <button type="submit" class="dbm-btn dbm-btn-success dbm-btn-sm"><i class="fas fa-undo"></i> Restore</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="font-size:12px;color:#888;margin-top:8px;"><?php echo number_format($sd_data['total']); ?> soft-deleted record(s) in <strong><?php echo htmlspecialchars($sd_table); ?></strong>.</div>
      <?php elseif ($sd_table): ?>
      <div style="text-align:center;padding:30px;color:#bbb;font-size:13px;">
        <i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#28a745;opacity:.5;"></i>
        No soft-deleted records in <strong><?php echo htmlspecialchars($sd_table); ?></strong>.
      </div>
      <?php else: ?>
      <div style="text-align:center;padding:30px;color:#bbb;font-size:13px;">Select a table to view its soft-deleted records.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ AUDIT TRAIL ══ -->
  <div id="tab-audit_trail" class="tab-pane" style="display:<?php echo $active_tab==='audit_trail'?'block':'none'; ?>;">
    <div class="dbm-card-header">
      <h3><i class="fas fa-history"></i> Audit Trail</h3>
    </div>
      <div class="dbm-info">
        <i class="fas fa-info-circle"></i>
        <span>Every DB action (view, backup, restore, soft delete, optimize, indexing) is automatically logged here with SuperAdmin ID, timestamp, and action details.</span>
      </div>
      <div class="dbm-table-wrap">
        <table class="dbm-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Timestamp</th>
              <th>SuperAdmin</th>
              <th>Action</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($audit_rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:30px;color:#bbb;">No database audit records yet.</td></tr>
            <?php else: ?>
            <?php foreach ($audit_rows as $i => $a): ?>
            <tr>
              <td style="color:#999;font-size:11px;"><?php echo $i+1; ?></td>
              <td style="white-space:nowrap;"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($a['created_at']))); ?></td>
              <td><?php echo htmlspecialchars($a['user_name']??'System'); ?></td>
              <td>
                <span style="padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;
                  background:<?php
                    $ac = strtolower($a['action']??'');
                    if (str_contains($ac,'backup')) echo 'rgba(0,38,77,.1)';
                    elseif (str_contains($ac,'restore')) echo 'rgba(40,167,69,.12)';
                    elseif (str_contains($ac,'delete')) echo 'rgba(204,0,0,.1)';
                    elseif (str_contains($ac,'optim')) echo 'rgba(224,123,0,.1)';
                    else echo 'rgba(108,117,125,.1)';
                  ?>;color:<?php
                    if (str_contains($ac,'backup')) echo 'var(--petron-blue)';
                    elseif (str_contains($ac,'restore')) echo '#1a7a35';
                    elseif (str_contains($ac,'delete')) echo '#cc0000';
                    elseif (str_contains($ac,'optim')) echo '#e07b00';
                    else echo '#555';
                  ?>;">
                  <?php echo htmlspecialchars($a['action']); ?>
                </span>
              </td>
              <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($a['details']??''); ?>">
                <?php echo htmlspecialchars($a['details']??'—'); ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.dbm-card -->
</div><!-- /.dbm-page -->

<?php include __DIR__ . '/../partials/footer.php'; ?>
