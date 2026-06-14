<?php
// ============================================================
// Database Management - Complete Functions
// public/database_management.php
// SuperAdmin Database Control Panel
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'database_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

// Only allow SuperAdmin access
$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');

if ($my_role !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

// Get database statistics
$db_stats = [
    'size' => '0 MB',
    'tables' => 0,
    'records' => 0,
    'last_backup' => 'Never'
];

try {
    // Database size
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
                         FROM information_schema.tables 
                         WHERE table_schema = DATABASE()");
    $size = $stmt->fetchColumn();
    $db_stats['size'] = $size ? $size . ' MB' : '0 MB';
    
    // Table count
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $db_stats['tables'] = $stmt->fetchColumn();
    
    // Total records (approximate)
    $stmt = $pdo->query("SELECT SUM(table_rows) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $db_stats['records'] = number_format($stmt->fetchColumn());
    
} catch (Exception $e) {
    error_log("DB Stats Error: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
/* Database Management Styles */
.db-page { padding: 0 24px 28px; }
.db-page-head { margin-bottom: 24px; padding-top: 16px; }
.db-page-head h1 { font-size: 22px !important; font-weight: 700 !important; color: var(--petron-blue) !important; margin: 0 !important; text-transform: uppercase !important; }
.db-page-head .sub { font-size: 13px; color: #666; margin-top: 4px; }

/* Stats Cards */
.db-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
.db-stat-card { background: #fff; border: 1px solid #eaeaea; border-radius: 14px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
.db-stat-icon { width: 52px; height: 52px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.db-stat-icon.blue { background: rgba(0,38,77,.1); color: var(--petron-blue); }
.db-stat-icon.green { background: rgba(40,167,69,.1); color: #28a745; }
.db-stat-icon.orange { background: rgba(255,152,0,.1); color: #ff9800; }
.db-stat-icon.purple { background: rgba(156,39,176,.1); color: #9c27b0; }
.db-stat-val { font-size: 24px; font-weight: 800; color: var(--petron-blue); line-height: 1; }
.db-stat-lbl { font-size: 12px; color: #666; margin-top: 4px; text-transform: uppercase; letter-spacing: .4px; }

/* Tabs */
.db-tabs { display: flex; gap: 4px; margin-bottom: 24px; border-bottom: 2px solid #e0e0e0; }
.db-tab { padding: 12px 24px; font-size: 14px; font-weight: 600; color: #666; cursor: pointer; background: transparent; border: none; border-bottom: 3px solid transparent; transition: all .2s; position: relative; bottom: -2px; }
.db-tab:hover { color: var(--petron-blue); background: rgba(0,38,77,.05); }
.db-tab.active { color: var(--petron-blue); border-bottom-color: var(--petron-blue); }
.db-tab i { margin-right: 8px; }

/* Tab Content */
.db-tab-content { display: none; }
.db-tab-content.active { display: block; animation: fadeIn .3s; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Section Cards */
.db-section { background: #fff; border: 1px solid #eaeaea; border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.05); }
.db-section-head { display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
.db-section-head i { font-size: 18px; color: var(--petron-blue); }
.db-section-head h2 { font-size: 16px; font-weight: 700; color: var(--petron-blue); margin: 0; text-transform: uppercase; letter-spacing: .5px; }
.db-section-desc { font-size: 13px; color: #666; margin-bottom: 18px; line-height: 1.5; }

/* Action Buttons */
.db-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.db-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all .2s; text-decoration: none; }
.db-btn-primary { background: var(--petron-blue); color: #fff; }
.db-btn-primary:hover { background: #001a3d; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,38,77,.3); }
.db-btn-success { background: #28a745; color: #fff; }
.db-btn-success:hover { background: #218838; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(40,167,69,.3); }
.db-btn-warning { background: #ff9800; color: #fff; }
.db-btn-warning:hover { background: #e68900; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(255,152,0,.3); }
.db-btn-danger { background: #dc3545; color: #fff; }
.db-btn-danger:hover { background: #c82333; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,53,69,.3); }
.db-btn-secondary { background: #6c757d; color: #fff; }
.db-btn-secondary:hover { background: #5a6268; }

/* Config Form */
.db-config { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-top: 16px; }
.db-config-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 14px; }
.db-config-group { display: flex; flex-direction: column; gap: 6px; }
.db-config-group label { font-size: 11px; font-weight: 600; color: #444; text-transform: uppercase; letter-spacing: .3px; }
.db-config-group input, .db-config-group select { padding: 8px 12px; border: 1px solid #cbd5e0; border-radius: 8px; font-size: 13px; outline: none; }
.db-config-group input:focus, .db-config-group select:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }

/* Info Box */
.db-info { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.db-info i { color: #2196f3; margin-right: 8px; }
.db-info-text { font-size: 12px; color: #0d47a1; }

/* Modal */
.db-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9000; align-items: center; justify-content: center; }
.db-modal-overlay.open { display: flex; }
.db-modal { background: #fff; border-radius: 20px; width: min(600px, 95vw); max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
.db-modal-header { padding: 20px 24px; border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
.db-modal-header h3 { font-size: 17px; font-weight: 700; color: var(--petron-blue); margin: 0; text-transform: uppercase; }
.db-modal-close { background: none; border: none; font-size: 20px; color: #999; cursor: pointer; padding: 4px 8px; border-radius: 6px; }
.db-modal-close:hover { background: #f0f0f0; color: #333; }
.db-modal-body { padding: 24px; }
.db-modal-footer { padding: 16px 24px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }

/* Toast */
.db-toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; padding: 14px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,.2); z-index: 10000; font-size: 13px; font-weight: 600; display: none; }
.db-toast.success { background: #10b981; }
.db-toast.error { background: #ef4444; }
.db-toast.warning { background: #f59e0b; }
</style>

<div class="db-page">

    <!-- Page Header -->
    <div class="db-page-head">
        <h1><i class="fas fa-database" style="margin-right:10px;"></i>Database Management</h1>
        <div class="sub">Complete database control panel for backup, restore, schema updates, replication, and security monitoring</div>
    </div>

    <!-- Database Statistics -->
    <div class="db-stats">
        <div class="db-stat-card">
            <div class="db-stat-icon blue">
                <i class="fas fa-database"></i>
            </div>
            <div>
                <div class="db-stat-val"><?php echo $db_stats['size']; ?></div>
                <div class="db-stat-lbl">Database Size</div>
            </div>
        </div>
        
        <div class="db-stat-card">
            <div class="db-stat-icon green">
                <i class="fas fa-table"></i>
            </div>
            <div>
                <div class="db-stat-val"><?php echo $db_stats['tables']; ?></div>
                <div class="db-stat-lbl">Total Tables</div>
            </div>
        </div>
        
        <div class="db-stat-card">
            <div class="db-stat-icon orange">
                <i class="fas fa-file-alt"></i>
            </div>
            <div>
                <div class="db-stat-val"><?php echo $db_stats['records']; ?></div>
                <div class="db-stat-lbl">Total Records</div>
            </div>
        </div>
        
        <div class="db-stat-card">
            <div class="db-stat-icon purple">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <div class="db-stat-val"><?php echo $db_stats['last_backup']; ?></div>
                <div class="db-stat-lbl">Last Backup</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="db-tabs">
        <button class="db-tab active" onclick="switchTab('backup')">
            <i class="fas fa-save"></i> Backup
        </button>
        <button class="db-tab" onclick="switchTab('restore')">
            <i class="fas fa-history"></i> Restore
        </button>
        <button class="db-tab" onclick="switchTab('schema')">
            <i class="fas fa-code-branch"></i> Schema & Migrations
        </button>
        <button class="db-tab" onclick="switchTab('replication')">
            <i class="fas fa-sync-alt"></i> Replication
        </button>
        <button class="db-tab" onclick="switchTab('logs')">
            <i class="fas fa-shield-alt"></i> Security Logs
        </button>
    </div>

    <!-- Tab Content: Backup -->
    <div id="tabBackup" class="db-tab-content active">
        <div class="db-section">
            <div class="db-section-head">
                <i class="fas fa-save"></i>
                <h2>Database Backup</h2>
            </div>
            <div class="db-section-desc">
                Create backup copies of your database. Configure automatic backup schedules and retention policies.
            </div>
            
            <!-- Backup Configuration Form (Always Visible) -->
            <div class="db-config" style="display:block;margin-top:20px;">
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Backup Frequency</label>
                        <select id="backupFrequency">
                            <option value="manual">Manual Only</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Storage Location</label>
                        <input type="text" id="backupStoragePath" placeholder="e.g., C:\backups\ or cloud://bucket-name" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                    
                    <div class="db-config-group">
                        <label>Retention Period (days)</label>
                        <input type="number" id="retentionDays" value="30" min="1" max="365">
                    </div>
                </div>
                
                <div class="db-actions" style="margin-top:16px;">
                    <button class="db-btn db-btn-primary" onclick="backupNow()">
                        <i class="fas fa-save"></i> Backup Now
                    </button>
                    <button class="db-btn db-btn-success" onclick="saveBackupConfig()">
                        <i class="fas fa-check"></i> Save Configuration
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="viewBackups()">
                        <i class="fas fa-list"></i> View Backup History
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /.db-tab-content (Backup) -->

    <!-- Tab Content: Restore -->
    <div id="tabRestore" class="db-tab-content">
        <div class="db-section">
            <div class="db-section-head">
                <i class="fas fa-history"></i>
                <h2>Database Restore</h2>
            </div>
            <div class="db-section-desc">
                Restore database from backup files. Select full database restore or specific tables only.
            </div>
            
            <div class="db-info">
                <i class="fas fa-exclamation-triangle"></i>
                <span class="db-info-text"><strong>Warning:</strong> Restoring will overwrite current data. Make sure to create a backup before restoring.</span>
            </div>
            
            <!-- Restore Form (Always Visible) -->
            <div class="db-config" style="display:block;margin-top:20px;">
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Backup File</label>
                        <select id="restoreBackupFile" style="width:100%;">
                            <option value="">Select a backup file...</option>
                            <!-- Dynamically loaded from backend -->
                        </select>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Restore Scope</label>
                        <div style="display:flex;gap:16px;margin-top:8px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="radio" name="restoreScope" value="full" checked> Full Database
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="radio" name="restoreScope" value="partial"> Specific Tables
                            </label>
                        </div>
                    </div>
                </div>
                
                <div id="tableSelection" style="display:none;margin-top:16px;">
                    <label style="font-size:11px;font-weight:600;color:#444;text-transform:uppercase;display:block;margin-bottom:8px;">Select Tables to Restore</label>
                    <div id="tableCheckboxes" style="max-height:200px;overflow-y:auto;border:1px solid #cbd5e0;border-radius:8px;padding:12px;background:#fff;">
                        <p style="text-align:center;color:#999;">Loading tables...</p>
                    </div>
                </div>
                
                <div class="db-actions" style="margin-top:16px;">
                    <button class="db-btn db-btn-warning" onclick="confirmRestore()">
                        <i class="fas fa-undo"></i> Restore Database
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="viewRestoreHistory()">
                        <i class="fas fa-history"></i> Restore History
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /.db-tab-content (Restore) -->

    <!-- Tab Content: Schema & Migrations -->
    <div id="tabSchema" class="db-tab-content">
        <div class="db-section">
            <div class="db-section-head">
                <i class="fas fa-code-branch"></i>
                <h2>Schema Updates & Migrations</h2>
            </div>
            <div class="db-section-desc">
                Manage database schema changes, add/remove columns, modify relationships, and optimize indexing.
            </div>
            
            <!-- Schema Form (Always Visible) -->
            <div class="db-config" style="display:block;margin-top:20px;">
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Select Table</label>
                        <select id="schemaTable" style="width:100%;" onchange="loadTableSchema()">
                            <option value="">Select a table...</option>
                            <!-- Dynamically loaded from database -->
                        </select>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Column Name</label>
                        <input type="text" id="columnName" placeholder="Enter column name" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                    
                    <div class="db-config-group">
                        <label>Data Type</label>
                        <select id="columnType" style="width:100%;">
                            <option value="">Select data type...</option>
                            <option value="INT">INT</option>
                            <option value="VARCHAR">VARCHAR</option>
                            <option value="TEXT">TEXT</option>
                            <option value="DATE">DATE</option>
                            <option value="TIMESTAMP">TIMESTAMP</option>
                            <option value="DECIMAL">DECIMAL</option>
                            <option value="BOOLEAN">BOOLEAN</option>
                        </select>
                    </div>
                </div>
                
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Column Length (for VARCHAR)</label>
                        <input type="number" id="columnLength" placeholder="e.g., 255" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                    
                    <div class="db-config-group">
                        <label>Constraints</label>
                        <div style="display:flex;gap:16px;margin-top:8px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="checkbox" id="columnNull"> Allow NULL
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="checkbox" id="columnPrimaryKey"> Primary Key
                            </label>
                        </div>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Table Relationships (Foreign Key)</label>
                        <input type="text" id="foreignKeyTable" placeholder="Referenced table name" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                </div>
                
                <div class="db-actions" style="margin-top:16px;">
                    <button class="db-btn db-btn-primary" onclick="addColumnToTable()">
                        <i class="fas fa-plus"></i> Add Column
                    </button>
                    <button class="db-btn db-btn-warning" onclick="modifyColumn()">
                        <i class="fas fa-edit"></i> Modify Column
                    </button>
                    <button class="db-btn db-btn-danger" onclick="removeColumn()">
                        <i class="fas fa-minus"></i> Remove Column
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="viewSchemaHistory()">
                        <i class="fas fa-history"></i> Migration History
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="optimizeDatabase()">
                        <i class="fas fa-tachometer-alt"></i> Optimize Database
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /.db-tab-content (Schema) -->

    <!-- Tab Content: Replication -->
    <div id="tabReplication" class="db-tab-content">
        <div class="db-section">
            <div class="db-section-head">
                <i class="fas fa-sync-alt"></i>
                <h2>Replication Control</h2>
            </div>
            <div class="db-section-desc">
                Configure database replication between stations. Manage sync frequency and conflict resolution rules.
            </div>
            
            <!-- Replication Form (Always Visible) -->
            <div class="db-config" style="display:block;margin-top:20px;">
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Station ID</label>
                        <input type="text" id="replicationStationID" placeholder="Enter Station ID or leave blank for all" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                        <small style="color:#666;font-size:11px;margin-top:4px;display:block;">Leave blank to sync all stations</small>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Sync Frequency</label>
                        <select id="syncFrequency" style="width:100%;">
                            <option value="realtime">Real-time</option>
                            <option value="5min">Every 5 minutes</option>
                            <option value="15min">Every 15 minutes</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="scheduled">Scheduled (Custom)</option>
                        </select>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Conflict Resolution</label>
                        <div style="display:flex;gap:16px;margin-top:8px;">
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="radio" name="conflictResolution" value="overwrite" checked> Overwrite
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="radio" name="conflictResolution" value="merge"> Merge
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:400;text-transform:none;">
                                <input type="radio" name="conflictResolution" value="manual"> Manual
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="db-actions" style="margin-top:16px;">
                    <button class="db-btn db-btn-success" onclick="enableReplication()">
                        <i class="fas fa-play"></i> Enable Sync
                    </button>
                    <button class="db-btn db-btn-danger" onclick="disableReplication()">
                        <i class="fas fa-stop"></i> Disable Sync
                    </button>
                    <button class="db-btn db-btn-primary" onclick="saveReplicationConfig()">
                        <i class="fas fa-check"></i> Save Configuration
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="viewSyncStatus()">
                        <i class="fas fa-info-circle"></i> View Sync Status
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /.db-tab-content (Replication) -->

    <!-- Tab Content: Security Logs -->
    <div id="tabLogs" class="db-tab-content">
        <div class="db-section">
            <div class="db-section-head">
                <i class="fas fa-shield-alt"></i>
                <h2>Security Logs Monitoring</h2>
            </div>
            <div class="db-section-desc">
                Monitor database access, track suspicious activities, and export security audit logs.
            </div>
            
            <!-- Security Logs Form (Always Visible) -->
            <div class="db-config" style="display:block;margin-top:20px;">
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Date Range (From)</label>
                        <input type="date" id="filterDateFrom" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                    
                    <div class="db-config-group">
                        <label>Date Range (To)</label>
                        <input type="date" id="filterDateTo" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                    
                    <div class="db-config-group">
                        <label>User ID</label>
                        <input type="text" id="filterUserId" placeholder="Enter User ID to filter" style="width:100%;padding:8px 12px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                    </div>
                </div>
                
                <div class="db-config-row">
                    <div class="db-config-group">
                        <label>Station Filter</label>
                        <select id="filterStation" style="width:100%;">
                            <option value="">All Stations</option>
                            <?php
                            try {
                                $stations = $pdo->query("SELECT id, name FROM stations WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($stations as $st) {
                                    echo "<option value='{$st['id']}'>" . htmlspecialchars($st['name']) . "</option>";
                                }
                            } catch (Exception $e) {
                                echo "<option value=''>Error loading stations</option>";
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="db-config-group">
                        <label>Action Type</label>
                        <select id="filterAction" style="width:100%;">
                            <option value="">All Actions</option>
                            <option value="Login">Login</option>
                            <option value="Logout">Logout</option>
                            <option value="Database Access">Database Access</option>
                            <option value="Configuration Change">Configuration Change</option>
                            <option value="Schema Update">Schema Update</option>
                            <option value="Backup">Backup</option>
                            <option value="Restore">Restore</option>
                        </select>
                    </div>
                    
                    <div class="db-config-group" style="display:flex;align-items:flex-end;">
                        <button class="db-btn db-btn-primary" onclick="filterAndLoadLogs()" style="width:100%;">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
                
                <!-- Logs Display Area -->
                <div id="logsDisplayArea" style="margin-top:20px;border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#fff;min-height:300px;">
                    <div style="text-align:center;color:#999;padding:40px;">
                        <i class="fas fa-search" style="font-size:48px;margin-bottom:16px;opacity:0.3;"></i>
                        <p>Click "Apply Filters" or "View All Logs" to load security logs</p>
                    </div>
                </div>
                
                <div class="db-actions" style="margin-top:16px;">
                    <button class="db-btn db-btn-primary" onclick="viewSecurityLogs()">
                        <i class="fas fa-eye"></i> View All Logs
                    </button>
                    <button class="db-btn db-btn-success" onclick="exportLogsToExcel()">
                        <i class="fas fa-file-excel"></i> Export to Excel
                    </button>
                    <button class="db-btn db-btn-warning" onclick="exportLogsToPDF()">
                        <i class="fas fa-file-pdf"></i> Export to PDF
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="openAlertSetup()">
                        <i class="fas fa-bell"></i> Alert Setup
                    </button>
                </div>
            </div>
        </div>
    </div><!-- /.db-tab-content (Logs) -->

</div><!-- /.db-page -->

<!-- Restore Point Modal -->
<div class="db-modal-overlay" id="restoreModal">
    <div class="db-modal">
        <div class="db-modal-header">
            <h3><i class="fas fa-undo"></i> Restore Database</h3>
            <button class="db-modal-close" onclick="closeModal('restoreModal')">&times;</button>
        </div>
        <div class="db-modal-body">
            <div class="db-info">
                <i class="fas fa-exclamation-triangle"></i>
                <span class="db-info-text">This will restore database from selected backup. Current data will be overwritten!</span>
            </div>
            
            <div class="db-config-group" style="margin-bottom:16px;">
                <label>Select Backup File</label>
                <select id="restoreBackupFile" style="width:100%;">
                    <option value="">Select a backup file...</option>
                    <option value="backup_2026_06_14_full.sql">backup_2026_06_14_full.sql (Today, 50 MB)</option>
                    <option value="backup_2026_06_13_full.sql">backup_2026_06_13_full.sql (Yesterday, 48 MB)</option>
                    <option value="backup_2026_06_12_full.sql">backup_2026_06_12_full.sql (2 days ago, 47 MB)</option>
                </select>
            </div>
            
            <div class="db-config-group" style="margin-bottom:16px;">
                <label>Restore Scope</label>
                <select id="restoreScope" style="width:100%;">
                    <option value="full">Full Database</option>
                    <option value="partial">Specific Tables Only</option>
                </select>
            </div>
            
            <div id="tableSelection" style="display:none;margin-bottom:16px;">
                <label style="font-size:11px;font-weight:600;color:#444;text-transform:uppercase;display:block;margin-bottom:8px;">Select Tables</label>
                <div id="tableCheckboxes" style="max-height:200px;overflow-y:auto;border:1px solid #cbd5e0;border-radius:8px;padding:12px;">
                    <p style="text-align:center;color:#999;">Loading tables...</p>
                </div>
            </div>
            
            <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px;border-radius:8px;margin-top:16px;">
                <i class="fas fa-exclamation-triangle" style="color:#856404;"></i>
                <span style="font-size:12px;color:#856404;"><strong>Important:</strong> Make sure to backup current database before proceeding!</span>
            </div>
        </div>
        <div class="db-modal-footer">
            <button class="db-btn db-btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
            <button class="db-btn db-btn-warning" onclick="confirmRestore()">
                <i class="fas fa-undo"></i> Restore Now
            </button>
        </div>
    </div>
</div>

<!-- Schema Update Modal -->
<div class="db-modal-overlay" id="schemaModal">
    <div class="db-modal">
        <div class="db-modal-header">
            <h3><i class="fas fa-code-branch"></i> Update Database Schema</h3>
            <button class="db-modal-close" onclick="closeModal('schemaModal')">&times;</button>
        </div>
        <div class="db-modal-body">
            <div class="db-config-group" style="margin-bottom:16px;">
                <label>Select Table</label>
                <select id="schemaTable" style="width:100%;" onchange="loadTableSchema()">
                    <option value="">Select a table...</option>
                </select>
            </div>
            
            <div id="schemaActions" style="display:none;">
                <h4 style="font-size:14px;margin-bottom:12px;color:var(--petron-blue);">Schema Actions</h4>
                
                <div style="margin-bottom:16px;">
                    <button class="db-btn db-btn-secondary" onclick="addColumn()">
                        <i class="fas fa-plus"></i> Add Column
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="modifyColumn()">
                        <i class="fas fa-edit"></i> Modify Column
                    </button>
                    <button class="db-btn db-btn-danger" onclick="removeColumn()">
                        <i class="fas fa-minus"></i> Remove Column
                    </button>
                </div>
                
                <div style="margin-bottom:16px;">
                    <button class="db-btn db-btn-secondary" onclick="addIndex()">
                        <i class="fas fa-bolt"></i> Add Index
                    </button>
                    <button class="db-btn db-btn-secondary" onclick="addForeignKey()">
                        <i class="fas fa-link"></i> Add Foreign Key
                    </button>
                </div>
                
                <div id="schemaForm" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-top:16px;display:none;">
                    <!-- Dynamic form will load here -->
                </div>
            </div>
        </div>
        <div class="db-modal-footer">
            <button class="db-btn db-btn-secondary" onclick="closeModal('schemaModal')">Cancel</button>
            <button class="db-btn db-btn-primary" onclick="applySchemaChanges()">
                <i class="fas fa-check"></i> Apply Changes
            </button>
        </div>
    </div>
</div>

<!-- Security Logs Modal -->
<div class="db-modal-overlay" id="logsModal">
    <div class="db-modal" style="width:min(900px,95vw);">
        <div class="db-modal-header">
            <h3><i class="fas fa-shield-alt"></i> Security Logs</h3>
            <button class="db-modal-close" onclick="closeModal('logsModal')">&times;</button>
        </div>
        <div class="db-modal-body">
            <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                <input type="date" id="filterDateFrom" style="padding:8px;border:1px solid #cbd5e0;border-radius:8px;" placeholder="From Date">
                <input type="date" id="filterDateTo" style="padding:8px;border:1px solid #cbd5e0;border-radius:8px;" placeholder="To Date">
                <input type="text" id="filterUserId" style="padding:8px;border:1px solid #cbd5e0;border-radius:8px;width:150px;" placeholder="User ID">
                <select id="filterStation" style="padding:8px;border:1px solid #cbd5e0;border-radius:8px;">
                    <option value="">All Stations</option>
                </select>
                <button class="db-btn db-btn-primary" onclick="filterLogs()">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
            
            <div style="max-height:400px;overflow-y:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Timestamp</th>
                            <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">User</th>
                            <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Action</th>
                            <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">IP Address</th>
                            <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="logsTableBody">
                        <tr><td colspan="5" style="text-align:center;padding:30px;color:#999;">Loading logs...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="db-modal-footer">
            <button class="db-btn db-btn-secondary" onclick="closeModal('logsModal')">Close</button>
            <button class="db-btn db-btn-primary" onclick="exportLogs()">
                <i class="fas fa-file-export"></i> Export
            </button>
        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="dbToast" class="db-toast"></div>

<script>
// ══════════════════════════════════════════════════════════════
// DATABASE MANAGEMENT JAVASCRIPT
// ══════════════════════════════════════════════════════════════

// Tab Switching
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.db-tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.db-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab content
    const tabId = 'tab' + tabName.charAt(0).toUpperCase() + tabName.slice(1);
    document.getElementById(tabId).classList.add('active');
    
    // Set active class on clicked tab
    event.target.closest('.db-tab').classList.add('active');
}

// Show Toast
function showToast(msg, type = 'success') {
    const toast = document.getElementById('dbToast');
    toast.textContent = msg;
    toast.className = 'db-toast ' + type;
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 3000);
}

// Close Modal
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// ══════════════════════════════════════════════════════════════
// INITIALIZATION - Load data on page load
// ══════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    // Load backup files for restore tab
    loadBackupFilesForRestore();
    
    // Load tables for schema tab
    loadTablesForSchema();
    
    // Setup restore scope radio buttons
    setupRestoreScopeToggle();
    
    // Set default date range (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
    document.getElementById('filterDateTo').valueAsDate = today;
    document.getElementById('filterDateFrom').valueAsDate = thirtyDaysAgo;
});

function setupRestoreScopeToggle() {
    const radioButtons = document.querySelectorAll('input[name="restoreScope"]');
    radioButtons.forEach(radio => {
        radio.addEventListener('change', function() {
            const tableSelection = document.getElementById('tableSelection');
            if (this.value === 'partial') {
                tableSelection.style.display = 'block';
                loadTablesForRestore();
            } else {
                tableSelection.style.display = 'none';
            }
        });
    });
}

async function loadTablesForSchema() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_tables&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        const select = document.getElementById('schemaTable');
        if (data.ok && data.tables && data.tables.length > 0) {
            select.innerHTML = '<option value="">Select a table...</option>';
            data.tables.forEach(table => {
                const option = document.createElement('option');
                option.value = table;
                option.textContent = table;
                select.appendChild(option);
            });
        }
    } catch (err) {
        console.error('Error loading tables:', err);
    }
}

function addColumnToTable() {
    const table = document.getElementById('schemaTable').value;
    const columnName = document.getElementById('columnName').value.trim();
    const columnType = document.getElementById('columnType').value;
    const columnLength = document.getElementById('columnLength').value;
    const allowNull = document.getElementById('columnNull').checked;
    
    if (!table) {
        showToast('Please select a table', 'error');
        return;
    }
    if (!columnName) {
        showToast('Please enter column name', 'error');
        return;
    }
    if (!columnType) {
        showToast('Please select data type', 'error');
        return;
    }
    
    if (!confirm(`Add column "${columnName}" (${columnType}) to table "${table}"?`)) return;
    
    applySchemaChanges();
}

async function filterAndLoadLogs() {
    const dateFrom = document.getElementById('filterDateFrom').value;
    const dateTo = document.getElementById('filterDateTo').value;
    const userId = document.getElementById('filterUserId').value;
    const station = document.getElementById('filterStation').value;
    const action = document.getElementById('filterAction').value;
    
    showToast('Loading filtered logs...', 'warning');
    
    try {
        let url = `../backend/api/database_api.php?action=get_security_logs&csrf_token=<?php echo $csrf; ?>`;
        if (dateFrom) url += `&date_from=${dateFrom}`;
        if (dateTo) url += `&date_to=${dateTo}`;
        if (userId) url += `&user_id=${userId}`;
        if (station) url += `&station=${station}`;
        if (action) url += `&action_type=${action}`;
        
        const res = await fetch(url);
        const data = await res.json();
        
        if (data.ok && data.logs) {
            displayLogsInArea(data.logs);
            showToast(`✓ Loaded ${data.logs.length} log entries`);
        } else {
            document.getElementById('logsDisplayArea').innerHTML = '<div style="text-align:center;padding:40px;color:#999;">No logs found matching the filters</div>';
            showToast('No logs found', 'warning');
        }
    } catch (err) {
        showToast('Error loading logs', 'error');
    }
}

function displayLogsInArea(logs) {
    const area = document.getElementById('logsDisplayArea');
    
    let html = `
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead>
                <tr style="background:#f1f5f9;">
                    <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Timestamp</th>
                    <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">User</th>
                    <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Action</th>
                    <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">IP Address</th>
                    <th style="padding:10px;text-align:center;border-bottom:2px solid #cbd5e0;">Status</th>
                </tr>
            </thead>
            <tbody>`;
    
    logs.forEach(log => {
        const statusClass = log.status === 'success' ? 'success' : 'danger';
        const statusBadge = `<span style="padding:3px 8px;border-radius:4px;font-size:11px;background:${log.status==='success'?'#d4edda':'#f8d7da'};color:${log.status==='success'?'#155724':'#721c24'}">${log.status}</span>`;
        
        html += `
            <tr>
                <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.timestamp}</td>
                <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.user}</td>
                <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.action}</td>
                <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.ip}</td>
                <td style="padding:10px;border-bottom:1px solid #e2e8f0;text-align:center;">${statusBadge}</td>
            </tr>`;
    });
    
    html += '</tbody></table>';
    area.innerHTML = html;
}

async function exportLogsToExcel() {
    showToast('Exporting logs to Excel...', 'warning');
    window.location.href = '../backend/api/database_api.php?action=export_logs&format=excel&csrf_token=<?php echo $csrf; ?>';
    setTimeout(() => showToast('✓ Excel file downloaded!'), 1000);
}

async function exportLogsToPDF() {
    showToast('PDF export coming soon...', 'warning');
}

async function backupNow() {
    if (!confirm('Create backup of current database? This may take a few minutes.')) return;
    
    showToast('Creating backup...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'backup',
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Backup created successfully!');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.error || 'Backup failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function openBackupConfig() {
    document.getElementById('backupConfig').style.display = 'block';
}

function closeBackupConfig() {
    document.getElementById('backupConfig').style.display = 'none';
}

// ══════════════════════════════════════════════════════════════
// 1. BACKUP FUNCTIONS
// ══════════════════════════════════════════════════════════════

async function saveBackupConfig() {
    const frequency = document.getElementById('backupFrequency').value;
    const storage = document.getElementById('backupStoragePath').value.trim();
    const retention = document.getElementById('retentionDays').value;
    
    if (!storage) {
        showToast('Please enter storage location', 'error');
        return;
    }
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'save_backup_config',
                frequency, 
                storage, 
                retention,
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Backup configuration saved!');
        } else {
            showToast(data.error || 'Failed to save settings', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function viewBackups() {
    fetch('../backend/api/database_api.php?action=get_backups&csrf_token=<?php echo $csrf; ?>')
        .then(res => res.json())
        .then(data => {
            if (data.ok && data.backups) {
                openBackupsModal(data.backups);
            } else {
                showToast('No backups found', 'warning');
            }
        })
        .catch(err => showToast('Error loading backups', 'error'));
}

function openBackupsModal(backups) {
    let html = `
        <div class="db-modal-overlay open" id="backupsModal">
            <div class="db-modal">
                <div class="db-modal-header">
                    <h3><i class="fas fa-list"></i> Available Backups</h3>
                    <button class="db-modal-close" onclick="closeModal('backupsModal')">&times;</button>
                </div>
                <div class="db-modal-body">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;">
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Filename</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Size</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Date</th>
                                <th style="padding:10px;text-align:center;border-bottom:2px solid #cbd5e0;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>`;
    
    if (backups.length === 0) {
        html += '<tr><td colspan="4" style="text-align:center;padding:30px;color:#999;">No backups found</td></tr>';
    } else {
        backups.forEach(backup => {
            html += `
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${backup.filename}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${backup.size}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${backup.date}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;text-align:center;">
                        <button class="db-btn db-btn-warning" style="padding:5px 10px;font-size:11px;" onclick="selectBackupForRestore('${backup.filename}')">
                            <i class="fas fa-undo"></i> Restore
                        </button>
                        <button class="db-btn db-btn-danger" style="padding:5px 10px;font-size:11px;" onclick="deleteBackup('${backup.filename}')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </td>
                </tr>`;
        });
    }
    
    html += `
                        </tbody>
                    </table>
                </div>
                <div class="db-modal-footer">
                    <button class="db-btn db-btn-secondary" onclick="closeModal('backupsModal')">Close</button>
                </div>
            </div>
        </div>`;
    
    // Remove existing modal if any
    const existing = document.getElementById('backupsModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
}

function selectBackupForRestore(filename) {
    closeModal('backupsModal');
    document.getElementById('restoreBackupFile').value = filename;
    openRestorePoint();
}

async function deleteBackup(filename) {
    if (!confirm(`Delete backup file: ${filename}?`)) return;
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'delete_backup',
                filename: filename,
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Backup deleted successfully!');
            viewBackups(); // Refresh list
        } else {
            showToast(data.error || 'Failed to delete backup', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// ══════════════════════════════════════════════════════════════
// 2. RESTORE FUNCTIONS
// ══════════════════════════════════════════════════════════════

function openRestorePoint() {
    document.getElementById('restoreModal').classList.add('open');
    
    // Load actual backup files
    loadBackupFilesForRestore();
    
    // Handle restore scope change
    const scopeSelect = document.getElementById('restoreScope');
    scopeSelect.onchange = async function() {
        const tableSelection = document.getElementById('tableSelection');
        if (this.value === 'partial') {
            tableSelection.style.display = 'block';
            // Load tables for selection
            await loadTablesForRestore();
        } else {
            tableSelection.style.display = 'none';
        }
    };
}

async function loadTablesForRestore() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_tables&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        const container = document.getElementById('tableCheckboxes');
        if (data.ok && data.tables && data.tables.length > 0) {
            container.innerHTML = '';
            data.tables.forEach(table => {
                container.innerHTML += `
                    <label style="display:block;margin-bottom:8px;">
                        <input type="checkbox" class="restore-table-checkbox" value="${table}"> ${table}
                    </label>`;
            });
        } else {
            container.innerHTML = '<p style="text-align:center;color:#999;">No tables found</p>';
        }
    } catch (err) {
        console.error('Error loading tables:', err);
        document.getElementById('tableCheckboxes').innerHTML = '<p style="text-align:center;color:#dc3545;">Error loading tables</p>';
    }
}

async function loadBackupFilesForRestore() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_backups&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        const select = document.getElementById('restoreBackupFile');
        select.innerHTML = '<option value="">Select a backup file...</option>';
        
        if (data.ok && data.backups && data.backups.length > 0) {
            data.backups.forEach(backup => {
                const option = document.createElement('option');
                option.value = backup.filename;
                option.textContent = `${backup.filename} (${backup.date}, ${backup.size})`;
                select.appendChild(option);
            });
        } else {
            select.innerHTML = '<option value="">No backups available</option>';
        }
    } catch (err) {
        console.error('Error loading backups:', err);
    }
}

async function confirmRestore() {
    const backupFile = document.getElementById('restoreBackupFile').value;
    const scope = document.getElementById('restoreScope').value;
    
    if (!backupFile) {
        showToast('Please select a backup file', 'error');
        return;
    }
    
    let tables = [];
    if (scope === 'partial') {
        const checkboxes = document.querySelectorAll('.restore-table-checkbox:checked');
        if (checkboxes.length === 0) {
            showToast('Please select at least one table to restore', 'error');
            return;
        }
        tables = Array.from(checkboxes).map(cb => cb.value);
    }
    
    if (!confirm('⚠️ WARNING: This will OVERWRITE current database!\n\nAre you absolutely sure you want to continue?')) return;
    if (!confirm('This is your FINAL confirmation. Proceed with restore?')) return;
    
    showToast('Restoring database... Please wait.', 'warning');
    closeModal('restoreModal');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'restore',
                backup_file: backupFile,
                scope: scope,
                tables: tables.join(','),
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Database restored successfully!');
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast(data.error || 'Restore failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function viewRestoreHistory() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_restore_history&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok) {
            openRestoreHistoryModal(data.restores || []);
        } else {
            showToast('Error loading restore history', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

async function optimizeDatabase() {
    if (!confirm('Optimize database tables? This may take a few minutes.')) return;
    
    showToast('Optimizing database...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'optimize',
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ ' + (data.message || 'Database optimized!'));
        } else {
            showToast(data.error || 'Optimization failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

// ══════════════════════════════════════════════════════════════
// 3. SCHEMA UPDATE FUNCTIONS
// ══════════════════════════════════════════════════════════════

function openSchemaUpdate() {
    document.getElementById('schemaModal').classList.add('open');
    loadTables();
}

async function loadTables() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_tables&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok && data.tables) {
            const select = document.getElementById('schemaTable');
            select.innerHTML = '<option value="">Select a table...</option>';
            data.tables.forEach(table => {
                const option = document.createElement('option');
                option.value = table;
                option.textContent = table;
                select.appendChild(option);
            });
        } else {
            showToast('Error loading tables', 'error');
        }
    } catch (err) {
        showToast('Error loading tables', 'error');
    }
}

async function loadTableSchema() {
    const table = document.getElementById('schemaTable').value;
    if (!table) {
        document.getElementById('schemaActions').style.display = 'none';
        return;
    }
    
    document.getElementById('schemaActions').style.display = 'block';
    
    // Load table structure
    try {
        const res = await fetch(`../backend/api/database_api.php?action=get_table_structure&table=${encodeURIComponent(table)}&csrf_token=<?php echo $csrf; ?>`);
        const data = await res.json();
        
        if (data.ok && data.columns) {
            // Store columns for later use
            window.currentTableColumns = data.columns;
        }
    } catch (err) {
        console.error('Error loading table structure:', err);
    }
}

function addColumn() {
    const form = document.getElementById('schemaForm');
    form.style.display = 'block';
    form.innerHTML = `
        <h5 style="margin:0 0 12px;font-size:13px;color:var(--petron-blue);">Add New Column</h5>
        <input type="text" id="columnName" placeholder="Column Name" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
        <select id="columnType" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
            <option value="INT">INT</option>
            <option value="VARCHAR">VARCHAR</option>
            <option value="TEXT">TEXT</option>
            <option value="DATE">DATE</option>
            <option value="TIMESTAMP">TIMESTAMP</option>
        </select>
        <input type="number" id="columnLength" placeholder="Length (for VARCHAR)" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
        <label><input type="checkbox" id="columnNull"> Allow NULL</label>
    `;
}

function modifyColumn() {
    if (!window.currentTableColumns || window.currentTableColumns.length === 0) {
        showToast('Please select a table first', 'warning');
        return;
    }
    
    const form = document.getElementById('schemaForm');
    form.style.display = 'block';
    
    let columnOptions = '';
    window.currentTableColumns.forEach(col => {
        columnOptions += `<option value="${col.Field}">${col.Field} (${col.Type})</option>`;
    });
    
    form.innerHTML = `
        <h5 style="margin:0 0 12px;font-size:13px;color:var(--petron-blue);">Modify Column</h5>
        <select id="modifyColumnName" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
            <option value="">Select column to modify...</option>
            ${columnOptions}
        </select>
        <input type="text" id="modifyNewName" placeholder="New Column Name (optional)" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
        <select id="modifyColumnType" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
            <option value="">Keep current type</option>
            <option value="INT">INT</option>
            <option value="VARCHAR">VARCHAR</option>
            <option value="TEXT">TEXT</option>
            <option value="DATE">DATE</option>
            <option value="TIMESTAMP">TIMESTAMP</option>
            <option value="DECIMAL">DECIMAL</option>
        </select>
        <input type="number" id="modifyColumnLength" placeholder="Length (for VARCHAR)" style="width:100%;padding:8px;margin-bottom:8px;border:1px solid #cbd5e0;border-radius:8px;">
        <label><input type="checkbox" id="modifyColumnNull"> Allow NULL</label>
    `;
}

function removeColumn() {
    if (!window.currentTableColumns || window.currentTableColumns.length === 0) {
        showToast('Please select a table first', 'warning');
        return;
    }
    
    const form = document.getElementById('schemaForm');
    form.style.display = 'block';
    
    let columnOptions = '';
    window.currentTableColumns.forEach(col => {
        columnOptions += `<option value="${col.Field}">${col.Field} (${col.Type})</option>`;
    });
    
    form.innerHTML = `
        <h5 style="margin:0 0 12px;font-size:13px;color:var(--petron-blue);">Remove Column</h5>
        <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px;border-radius:8px;margin-bottom:12px;">
            <i class="fas fa-exclamation-triangle" style="color:#856404;"></i>
            <span style="font-size:12px;color:#856404;"><strong>Warning:</strong> This will permanently delete the column and all its data!</span>
        </div>
        <select id="removeColumnName" style="width:100%;padding:8px;border:1px solid #cbd5e0;border-radius:8px;">
            <option value="">Select column to remove...</option>
            ${columnOptions}
        </select>
    `;
}

function addIndex() {
    showToast('Index creation interface', 'warning');
}

function addForeignKey() {
    showToast('Foreign key creation interface', 'warning');
}

async function applySchemaChanges() {
    const table = document.getElementById('schemaTable').value;
    if (!table) {
        showToast('Please select a table', 'error');
        return;
    }
    
    // Determine which action is active
    const form = document.getElementById('schemaForm');
    if (form.style.display === 'none') {
        showToast('Please select an action first', 'warning');
        return;
    }
    
    let action = '';
    let params = { table };
    
    // Check which form is displayed
    if (document.getElementById('columnName')) {
        // Add column
        action = 'add_column';
        const name = document.getElementById('columnName').value.trim();
        const type = document.getElementById('columnType').value;
        const length = document.getElementById('columnLength').value;
        const allowNull = document.getElementById('columnNull').checked;
        
        if (!name) {
            showToast('Column name is required', 'error');
            return;
        }
        if (!type) {
            showToast('Column type is required', 'error');
            return;
        }
        
        params.column_name = name;
        params.column_type = type;
        params.column_length = length;
        params.allow_null = allowNull ? '1' : '0';
        
    } else if (document.getElementById('modifyColumnName')) {
        // Modify column
        action = 'modify_column';
        const oldName = document.getElementById('modifyColumnName').value;
        const newName = document.getElementById('modifyNewName').value.trim();
        const type = document.getElementById('modifyColumnType').value;
        const length = document.getElementById('modifyColumnLength').value;
        const allowNull = document.getElementById('modifyColumnNull').checked;
        
        if (!oldName) {
            showToast('Please select a column', 'error');
            return;
        }
        
        params.old_name = oldName;
        params.new_name = newName || oldName;
        params.column_type = type;
        params.column_length = length;
        params.allow_null = allowNull ? '1' : '0';
        
    } else if (document.getElementById('removeColumnName')) {
        // Remove column
        action = 'remove_column';
        const columnName = document.getElementById('removeColumnName').value;
        
        if (!columnName) {
            showToast('Please select a column', 'error');
            return;
        }
        
        if (!confirm(`⚠️ WARNING: This will permanently delete column "${columnName}" and ALL its data!\n\nAre you sure?`)) {
            return;
        }
        
        params.column_name = columnName;
    } else {
        showToast('Unknown action', 'error');
        return;
    }
    
    if (!confirm('Apply schema changes? This will modify the database structure.')) return;
    
    showToast('Applying changes...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: action,
                ...params,
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Schema changes applied successfully!');
            closeModal('schemaModal');
        } else {
            showToast(data.error || 'Schema update failed', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

async function viewSchemaHistory() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_schema_history&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok) {
            openSchemaHistoryModal(data.migrations || []);
        } else {
            showToast('Error loading migration history', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function openSchemaHistoryModal(migrations) {
    let html = `
        <div class="db-modal-overlay open" id="schemaHistoryModal">
            <div class="db-modal" style="width:min(800px,95vw);">
                <div class="db-modal-header">
                    <h3><i class="fas fa-history"></i> Schema Migration History</h3>
                    <button class="db-modal-close" onclick="closeModal('schemaHistoryModal')">&times;</button>
                </div>
                <div class="db-modal-body">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;">
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Migration</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Executed By</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Date</th>
                            </tr>
                        </thead>
                        <tbody>`;
    
    if (migrations.length === 0) {
        html += '<tr><td colspan="3" style="text-align:center;padding:30px;color:#999;">No migrations found</td></tr>';
    } else {
        migrations.forEach(mig => {
            html += `
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${mig.migration_name}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${mig.executed_by}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${mig.executed_at}</td>
                </tr>`;
        });
    }
    
    html += `
                        </tbody>
                    </table>
                </div>
                <div class="db-modal-footer">
                    <button class="db-btn db-btn-secondary" onclick="closeModal('schemaHistoryModal')">Close</button>
                </div>
            </div>
        </div>`;
    
    const existing = document.getElementById('schemaHistoryModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
}

async function viewRestoreHistory() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_restore_history&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok) {
            openRestoreHistoryModal(data.restores || []);
        } else {
            showToast('Error loading restore history', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function openRestoreHistoryModal(restores) {
    let html = `
        <div class="db-modal-overlay open" id="restoreHistoryModal">
            <div class="db-modal" style="width:min(800px,95vw);">
                <div class="db-modal-header">
                    <h3><i class="fas fa-history"></i> Restore History</h3>
                    <button class="db-modal-close" onclick="closeModal('restoreHistoryModal')">&times;</button>
                </div>
                <div class="db-modal-body">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead>
                            <tr style="background:#f1f5f9;">
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Backup File</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Restored By</th>
                                <th style="padding:10px;text-align:left;border-bottom:2px solid #cbd5e0;">Date</th>
                            </tr>
                        </thead>
                        <tbody>`;
    
    if (restores.length === 0) {
        html += '<tr><td colspan="3" style="text-align:center;padding:30px;color:#999;">No restore history found</td></tr>';
    } else {
        restores.forEach(res => {
            html += `
                <tr>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${res.backup_filename}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${res.restored_by}</td>
                    <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${res.restored_at}</td>
                </tr>`;
        });
    }
    
    html += `
                        </tbody>
                    </table>
                </div>
                <div class="db-modal-footer">
                    <button class="db-btn db-btn-secondary" onclick="closeModal('restoreHistoryModal')">Close</button>
                </div>
            </div>
        </div>`;
    
    const existing = document.getElementById('restoreHistoryModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
}

// ══════════════════════════════════════════════════════════════
// 4. REPLICATION FUNCTIONS
// ══════════════════════════════════════════════════════════════

async function enableReplication() {
    if (!confirm('Enable database replication? This will start syncing data between stations.')) return;
    
    showToast('Enabling replication...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'enable_replication',
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Replication enabled!');
        } else {
            showToast(data.error || 'Failed to enable replication', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

async function disableReplication() {
    if (!confirm('Disable database replication? Stations will stop syncing.')) return;
    
    showToast('Disabling replication...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'disable_replication',
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Replication disabled!');
        } else {
            showToast(data.error || 'Failed to disable replication', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function openReplicationConfig() {
    document.getElementById('replicationConfig').style.display = 'block';
}

function closeReplicationConfig() {
    document.getElementById('replicationConfig').style.display = 'none';
}

async function saveReplicationConfig() {
    const station = document.getElementById('replicationStation').value;
    const frequency = document.getElementById('syncFrequency').value;
    const resolution = document.getElementById('conflictResolution').value;
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'save_replication_config',
                station, frequency, resolution,
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Replication settings saved!');
            closeReplicationConfig();
        } else {
            showToast(data.error || 'Failed to save settings', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

async function viewSyncStatus() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_sync_status&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok) {
            openSyncStatusModal(data.status);
        } else {
            showToast('Error loading sync status', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}

function openSyncStatusModal(status) {
    const isEnabled = status.enabled === '1';
    const statusBadge = isEnabled 
        ? '<span style="padding:4px 12px;background:#10b981;color:#fff;border-radius:6px;font-size:11px;font-weight:600;">ENABLED</span>'
        : '<span style="padding:4px 12px;background:#6c757d;color:#fff;border-radius:6px;font-size:11px;font-weight:600;">DISABLED</span>';
    
    let html = `
        <div class="db-modal-overlay open" id="syncStatusModal">
            <div class="db-modal">
                <div class="db-modal-header">
                    <h3><i class="fas fa-info-circle"></i> Replication Sync Status</h3>
                    <button class="db-modal-close" onclick="closeModal('syncStatusModal')">&times;</button>
                </div>
                <div class="db-modal-body">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:16px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                            <h4 style="margin:0;font-size:14px;color:var(--petron-blue);">Replication Status</h4>
                            ${statusBadge}
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:13px;">
                            <div>
                                <div style="color:#666;margin-bottom:4px;">Station Binding</div>
                                <div style="font-weight:600;">${status.station || 'All Stations'}</div>
                            </div>
                            <div>
                                <div style="color:#666;margin-bottom:4px;">Sync Frequency</div>
                                <div style="font-weight:600;">${status.frequency || 'Not Set'}</div>
                            </div>
                            <div>
                                <div style="color:#666;margin-bottom:4px;">Conflict Resolution</div>
                                <div style="font-weight:600;">${status.resolution || 'Not Set'}</div>
                            </div>
                            <div>
                                <div style="color:#666;margin-bottom:4px;">Last Sync</div>
                                <div style="font-weight:600;">${status.last_sync || 'Never'}</div>
                            </div>
                        </div>
                    </div>
                    
                    ${!isEnabled ? `
                    <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px;border-radius:8px;">
                        <i class="fas fa-info-circle" style="color:#856404;"></i>
                        <span style="font-size:12px;color:#856404;">Replication is currently disabled. Click "Enable Sync" to start syncing.</span>
                    </div>
                    ` : `
                    <div style="background:#d1fae5;border-left:4px solid #10b981;padding:12px;border-radius:8px;">
                        <i class="fas fa-check-circle" style="color:#065f46;"></i>
                        <span style="font-size:12px;color:#065f46;">Replication is active and syncing data.</span>
                    </div>
                    `}
                </div>
                <div class="db-modal-footer">
                    <button class="db-btn db-btn-secondary" onclick="closeModal('syncStatusModal')">Close</button>
                </div>
            </div>
        </div>`;
    
    const existing = document.getElementById('syncStatusModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
}

// ══════════════════════════════════════════════════════════════
// 5. SECURITY LOGS FUNCTIONS
// ══════════════════════════════════════════════════════════════

async function viewSecurityLogs() {
    document.getElementById('logsModal').classList.add('open');
    loadSecurityLogs();
}

async function loadSecurityLogs() {
    const tbody = document.getElementById('logsTableBody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;">Loading logs...</td></tr>';
    
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_security_logs&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok && data.logs) {
            tbody.innerHTML = '';
            data.logs.forEach(log => {
                const statusClass = log.status === 'success' ? 'success' : 'danger';
                const statusBadge = `<span style="padding:3px 8px;border-radius:4px;font-size:11px;background:${log.status==='success'?'#d4edda':'#f8d7da'};color:${log.status==='success'?'#155724':'#721c24'}">${log.status}</span>`;
                
                tbody.innerHTML += `
                    <tr>
                        <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.timestamp}</td>
                        <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.user}</td>
                        <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.action}</td>
                        <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${log.ip}</td>
                        <td style="padding:10px;border-bottom:1px solid #e2e8f0;">${statusBadge}</td>
                    </tr>
                `;
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#999;">No logs found</td></tr>';
        }
    } catch (err) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#dc3545;">Error loading logs</td></tr>';
    }
}

function filterLogs() {
    // Apply filters and reload
    loadSecurityLogs();
}

async function exportLogs() {
    showToast('Exporting logs...', 'warning');
    
    try {
        const res = await fetch('../backend/api/database_api.php?action=export_logs&format=excel&csrf_token=<?php echo $csrf; ?>');
        const blob = await res.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'security_logs_' + new Date().toISOString().split('T')[0] + '.xlsx';
        a.click();
        
        showToast('✓ Logs exported successfully!');
    } catch (err) {
        showToast('Export failed', 'error');
    }
}

function openAlertSetup() {
    let html = `
        <div class="db-modal-overlay open" id="alertSetupModal">
            <div class="db-modal">
                <div class="db-modal-header">
                    <h3><i class="fas fa-bell"></i> Security Alert Setup</h3>
                    <button class="db-modal-close" onclick="closeModal('alertSetupModal')">&times;</button>
                </div>
                <div class="db-modal-body">
                    <div class="db-info">
                        <i class="fas fa-info-circle"></i>
                        <span class="db-info-text">Configure alerts for suspicious activities and database access patterns.</span>
                    </div>
                    
                    <div style="margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;">
                            <input type="checkbox" id="alertFailedLogins" checked>
                            <span>Alert on multiple failed login attempts (3+ in 5 minutes)</span>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;">
                            <input type="checkbox" id="alertUnauthorizedAccess" checked>
                            <span>Alert on unauthorized database access attempts</span>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;">
                            <input type="checkbox" id="alertSchemaChanges">
                            <span>Alert on schema modifications</span>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;">
                            <input type="checkbox" id="alertDataDeletion">
                            <span>Alert on bulk data deletion</span>
                        </label>
                        
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;">
                            <input type="checkbox" id="alertBackupFailure" checked>
                            <span>Alert on backup failures</span>
                        </label>
                    </div>
                    
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;">
                        <h5 style="margin:0 0 12px;font-size:13px;color:var(--petron-blue);">Notification Settings</h5>
                        
                        <div style="margin-bottom:12px;">
                            <label style="font-size:11px;font-weight:600;color:#444;text-transform:uppercase;display:block;margin-bottom:6px;">Email Recipients</label>
                            <input type="text" id="alertEmails" placeholder="admin@example.com, security@example.com" style="width:100%;padding:8px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                        </div>
                        
                        <div>
                            <label style="font-size:11px;font-weight:600;color:#444;text-transform:uppercase;display:block;margin-bottom:6px;">Alert Frequency</label>
                            <select id="alertFrequency" style="width:100%;padding:8px;border:1px solid #cbd5e0;border-radius:8px;font-size:13px;">
                                <option value="immediate">Immediate</option>
                                <option value="hourly">Hourly Digest</option>
                                <option value="daily">Daily Summary</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="db-modal-footer">
                    <button class="db-btn db-btn-secondary" onclick="closeModal('alertSetupModal')">Cancel</button>
                    <button class="db-btn db-btn-success" onclick="saveAlertSettings()">
                        <i class="fas fa-check"></i> Save Alert Settings
                    </button>
                </div>
            </div>
        </div>`;
    
    const existing = document.getElementById('alertSetupModal');
    if (existing) existing.remove();
    
    document.body.insertAdjacentHTML('beforeend', html);
    
    // Load existing settings
    loadAlertSettings();
}

async function loadAlertSettings() {
    try {
        const res = await fetch('../backend/api/database_api.php?action=get_alert_settings&csrf_token=<?php echo $csrf; ?>');
        const data = await res.json();
        
        if (data.ok && data.settings) {
            document.getElementById('alertFailedLogins').checked = data.settings.failed_logins === '1';
            document.getElementById('alertUnauthorizedAccess').checked = data.settings.unauthorized_access === '1';
            document.getElementById('alertSchemaChanges').checked = data.settings.schema_changes === '1';
            document.getElementById('alertDataDeletion').checked = data.settings.data_deletion === '1';
            document.getElementById('alertBackupFailure').checked = data.settings.backup_failure === '1';
            document.getElementById('alertEmails').value = data.settings.emails || '';
            document.getElementById('alertFrequency').value = data.settings.frequency || 'immediate';
        }
    } catch (err) {
        console.error('Error loading alert settings:', err);
    }
}

async function saveAlertSettings() {
    const settings = {
        failed_logins: document.getElementById('alertFailedLogins').checked ? '1' : '0',
        unauthorized_access: document.getElementById('alertUnauthorizedAccess').checked ? '1' : '0',
        schema_changes: document.getElementById('alertSchemaChanges').checked ? '1' : '0',
        data_deletion: document.getElementById('alertDataDeletion').checked ? '1' : '0',
        backup_failure: document.getElementById('alertBackupFailure').checked ? '1' : '0',
        emails: document.getElementById('alertEmails').value,
        frequency: document.getElementById('alertFrequency').value
    };
    
    try {
        const res = await fetch('../backend/api/database_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'save_alert_settings',
                ...settings,
                csrf_token: '<?php echo $csrf; ?>'
            })
        });
        
        const data = await res.json();
        if (data.ok) {
            showToast('✓ Alert settings saved successfully!');
            closeModal('alertSetupModal');
        } else {
            showToast(data.error || 'Failed to save settings', 'error');
        }
    } catch (err) {
        showToast('Network error', 'error');
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
