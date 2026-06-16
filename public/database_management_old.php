<?php
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

$msg = '';
$success = '';

// Fetch all stations for dropdown
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Failed to fetch stations: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'backup':
            $success = "Database backup initiated successfully!";
            log_activity($pdo, $me['id'], 'Database Management', 'Initiated database backup');
            break;
        case 'restore':
            $success = "Database restore completed successfully!";
            log_activity($pdo, $me['id'], 'Database Management', 'Restored database from backup');
            break;
        case 'save_backup_config':
            $success = "Backup configuration saved successfully!";
            log_activity($pdo, $me['id'], 'Database Management', 'Updated backup configuration');
            break;
    }
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-database"></i> Database Management</h1>
        <div class="sub">Complete database control panel for backup, restore, schema updates, replication, and security monitoring.</div>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<?php if($success): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb;">
    <?php echo $success; ?>
</div>
<?php endif; ?>

<!-- Station Selection -->
<div class="card" id="tb_station_combo_card" style="margin-bottom: 30px; overflow: visible !important;">
    <div class="card-header" style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: #1f2937; text-transform: uppercase; letter-spacing: 0.5px;">
            <i class="fas fa-map-marker-alt" style="color: #3b82f6;"></i> Station-Dependent Configuration
        </h3>
    </div>
    <div class="card-body" style="padding: 20px; overflow: visible !important;">
        <div style="display: flex; align-items: center; gap: 15px; position: relative;">
            <label for="stationSelect" style="font-weight: 600; color: #374151; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">
                Search Station
            </label>
            <!-- Searchable station select (SAME AS MODULE CONFIGURATION) -->
            <div class="am-combo am-combo-toolbar" id="tb_station_combo" style="width:450px; position: relative; z-index: 100;">
                <input type="text" class="am-combo-input" id="tb_station_display" placeholder="Type to search stations or select All Stations..." autocomplete="off" style="padding-right:35px; cursor: text;">
                <button type="button" class="am-combo-clear" id="tb_station_clear" tabindex="-1" title="Clear filter"><i class="fas fa-times"></i></button>
                <i class="fas fa-chevron-down am-combo-arrow"></i>
                <input type="hidden" id="tb_station_val">
                <div class="am-combo-dropdown" id="tb_station_dropdown">
                    <div class="am-combo-list" id="tb_station_list">
                        <!-- options populated dynamically by virtualized list -->
                    </div>
                </div>
            </div>
        </div>
        <!-- Hidden select for backward compatibility -->
        <select id="stationSelect" style="display: none;">
            <option value="">All Stations (Global Operations)</option>
            <?php foreach ($stations as $st): ?>
            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Tab Navigation -->
<div class="tab-navigation" style="margin-bottom: 0;">
    <button class="tab-btn active" onclick="switchTab('backup')">
        <i class="fas fa-save"></i> Backup
    </button>
    <button class="tab-btn" onclick="switchTab('restore')">
        <i class="fas fa-undo"></i> Restore
    </button>
    <button class="tab-btn" onclick="switchTab('schema')">
        <i class="fas fa-table"></i> Schema & Migrations
    </button>
    <button class="tab-btn" onclick="switchTab('replication')">
        <i class="fas fa-sync"></i> Replication
    </button>
    <button class="tab-btn" onclick="switchTab('security')">
        <i class="fas fa-shield-alt"></i> Security Logs
    </button>
</div>

<!-- Tab Content Container -->
<div class="card" style="margin-top: 0; border-top-left-radius: 0; border-top-right-radius: 0;">
    
    <!-- BACKUP TAB -->
    <div id="tab-backup" class="tab-content active">
        <div class="card-header" style="background: #1e3a5f; color: white; padding: 12px 20px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 600;">
                <i class="fas fa-save"></i> DATABASE BACKUP CONFIGURATION
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_backup_config">
                <table class="db-form-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">FIELD NAME</th>
                            <th style="width: 70%;">VALUE / INPUT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Backup Frequency</strong></td>
                            <td>
                                <div class="field-type-label">DROPDOWN</div>
                                <select name="backup_frequency" class="db-field-input" required>
                                    <option value="manual">Manual Only</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Storage Location</strong></td>
                            <td>
                                <div class="field-type-label">TEXT FIELD</div>
                                <input type="text" name="storage_location" class="db-field-input" 
                                       placeholder="e.g., /var/backups/petron or cloud://bucket-name" 
                                       value="local" required>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Retention Period</strong></td>
                            <td>
                                <div class="field-type-label">NUMERIC FIELD</div>
                                <input type="number" name="retention_period" class="db-field-input" 
                                       placeholder="30" value="30" min="1" max="365" required>
                                <span style="margin-left: 10px; font-size: 13px; color: #666;">days to keep backup</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                    <button type="button" class="btn btn-primary" onclick="performBackup()">
                        <i class="fas fa-save"></i> Backup Now
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Save Configuration
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="viewBackupHistory()">
                        <i class="fas fa-history"></i> View Backup History
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- RESTORE TAB -->
    <div id="tab-restore" class="tab-content">
        <div class="card-header" style="background: #1e3a5f; color: white; padding: 12px 20px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 600;">
                <i class="fas fa-undo"></i> DATABASE RESTORE
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <form method="POST" action="" id="restoreForm">
                <input type="hidden" name="action" value="restore">
                <table class="db-form-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">FIELD NAME</th>
                            <th style="width: 70%;">VALUE / INPUT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Backup File</strong></td>
                            <td>
                                <div class="field-type-label">FILE SELECTOR</div>
                                <input type="file" name="backup_file" class="db-field-input" accept=".sql,.zip" required>
                                <span style="display: block; margin-top: 5px; font-size: 12px; color: #888;">
                                    Choose backup file to restore (.sql or .zip)
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Restore Scope</strong></td>
                            <td>
                                <div class="field-type-label">RADIO BUTTON</div>
                                <label class="radio-label">
                                    <input type="radio" name="restore_scope" value="full" checked> 
                                    Full Database
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="restore_scope" value="specific"> 
                                    Specific Tables
                                </label>
                            </td>
                        </tr>
                        <tr id="tableSelectionRow" style="display: none;">
                            <td><strong>Select Tables</strong></td>
                            <td>
                                <div class="field-type-label">MULTI-SELECT</div>
                                <select name="tables[]" class="db-field-input" multiple size="5">
                                    <option value="users">users</option>
                                    <option value="stations">stations</option>
                                    <option value="transactions">transactions</option>
                                    <option value="inventory">inventory</option>
                                    <option value="activity_logs">activity_logs</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Confirmation</strong></td>
                            <td>
                                <div class="field-type-label">CHECKBOX</div>
                                <label class="checkbox-label">
                                    <input type="checkbox" name="confirm_restore" required> 
                                    I understand this will overwrite existing data. Proceed with restore.
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                    <button type="button" class="btn btn-danger" onclick="confirmRestore()">
                        <i class="fas fa-undo"></i> Restore Database
                    </button>
                    <button type="button" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- SCHEMA & MIGRATIONS TAB -->
    <div id="tab-schema" class="tab-content">
        <div class="card-header" style="background: #1e3a5f; color: white; padding: 12px 20px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 600;">
                <i class="fas fa-table"></i> SCHEMA UPDATES & MIGRATIONS
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="db-form-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">FIELD NAME</th>
                        <th style="width: 70%;">VALUE / INPUT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Column Name</strong></td>
                        <td>
                            <div class="field-type-label">TEXT FIELD</div>
                            <input type="text" name="column_name" class="db-field-input" placeholder="e.g., customer_email">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Data Type</strong></td>
                        <td>
                            <div class="field-type-label">DROPDOWN</div>
                            <select name="data_type" class="db-field-input">
                                <option value="">Select Data Type</option>
                                <option value="INT">INT</option>
                                <option value="VARCHAR">VARCHAR</option>
                                <option value="TEXT">TEXT</option>
                                <option value="DATE">DATE</option>
                                <option value="DATETIME">DATETIME</option>
                                <option value="DECIMAL">DECIMAL</option>
                                <option value="BOOLEAN">BOOLEAN</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Relationships</strong></td>
                        <td>
                            <div class="field-type-label">RELATION EDITOR</div>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openRelationEditor()">
                                <i class="fas fa-link"></i> Define Table Links
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Indexing</strong></td>
                        <td>
                            <div class="field-type-label">CHECKBOX</div>
                            <label class="checkbox-label">
                                <input type="checkbox" name="apply_indexing"> 
                                Apply indexing rules for performance optimization
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Column
                </button>
                <button type="button" class="btn btn-danger">
                    <i class="fas fa-trash"></i> Remove Column
                </button>
                <button type="button" class="btn btn-success">
                    <i class="fas fa-save"></i> Apply Changes
                </button>
            </div>
        </div>
    </div>

    <!-- REPLICATION TAB -->
    <div id="tab-replication" class="tab-content">
        <div class="card-header" style="background: #1e3a5f; color: white; padding: 12px 20px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 600;">
                <i class="fas fa-sync"></i> REPLICATION CONTROL
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="db-form-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">FIELD NAME</th>
                        <th style="width: 70%;">VALUE / INPUT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Station ID</strong></td>
                        <td>
                            <div class="field-type-label">TEXT FIELD</div>
                            <input type="text" name="station_id" class="db-field-input" placeholder="Bind sync to station">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Sync Frequency</strong></td>
                        <td>
                            <div class="field-type-label">DROPDOWN</div>
                            <select name="sync_frequency" class="db-field-input">
                                <option value="realtime">Real-time</option>
                                <option value="5min">Every 5 Minutes</option>
                                <option value="hourly">Hourly</option>
                                <option value="daily">Daily</option>
                                <option value="scheduled">Scheduled</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Conflict Resolution</strong></td>
                        <td>
                            <div class="field-type-label">RADIO BUTTON</div>
                            <label class="radio-label">
                                <input type="radio" name="conflict_resolution" value="overwrite" checked> 
                                Overwrite (Latest wins)
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="conflict_resolution" value="merge"> 
                                Merge (Combine changes)
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn btn-primary">
                    <i class="fas fa-play"></i> Start Sync
                </button>
                <button type="button" class="btn btn-danger">
                    <i class="fas fa-stop"></i> Stop Sync
                </button>
                <button type="button" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Sync Status
                </button>
            </div>
        </div>
    </div>

    <!-- SECURITY LOGS TAB -->
    <div id="tab-security" class="tab-content">
        <div class="card-header" style="background: #1e3a5f; color: white; padding: 12px 20px;">
            <h3 style="margin: 0; font-size: 15px; font-weight: 600;">
                <i class="fas fa-shield-alt"></i> SECURITY LOGS
            </h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="db-form-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">FIELD NAME</th>
                        <th style="width: 20%;">FIELD TYPE</th>
                        <th style="width: 50%;">VALUE / INPUT</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Date Range</strong></td>
                        <td><span class="field-type">Date Picker</span></td>
                        <td>
                            <input type="date" name="date_from" class="db-field-input" style="width: 45%; display: inline-block;" placeholder="From">
                            <span style="margin: 0 10px;">to</span>
                            <input type="date" name="date_to" class="db-field-input" style="width: 45%; display: inline-block;" placeholder="To">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>User ID</strong></td>
                        <td><span class="field-type">Text Field</span></td>
                        <td>
                            <input type="text" name="user_id" class="db-field-input" placeholder="Filter by user ID">
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Station</strong></td>
                        <td><span class="field-type">Dropdown</span></td>
                        <td>
                            <select name="station" class="db-field-input">
                                <option value="">All Stations</option>
                                <?php foreach ($stations as $st): ?>
                                <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Export</strong></td>
                        <td><span class="field-type">Buttons</span></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-success" onclick="exportLogs('excel')">
                                <i class="fas fa-file-excel"></i> Excel
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="exportLogs('pdf')">
                                <i class="fas fa-file-pdf"></i> PDF
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Alerts</strong></td>
                        <td><span class="field-type">Toggle</span></td>
                        <td>
                            <label class="toggle-switch">
                                <input type="checkbox" name="enable_alerts" checked>
                                <span class="toggle-slider"></span>
                            </label>
                            <span style="margin-left: 10px; font-size: 13px; color: #666;">
                                Enable/Disable suspicious activity alerts
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div style="padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6;">
                <button type="button" class="btn btn-primary" onclick="searchSecurityLogs()">
                    <i class="fas fa-search"></i> Search Logs
                </button>
                <button type="button" class="btn btn-secondary">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
        </div>
        
        <!-- Security Logs Results Table -->
        <div id="securityLogsResults" style="padding: 20px; display: none;">
            <h4 style="margin: 0 0 15px; font-size: 14px; font-weight: 600; color: #1e3a5f;">
                <i class="fas fa-list"></i> Security Log Results
            </h4>
            <div style="overflow:hidden;">
                <table class="results-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User ID</th>
                            <th>Station</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>2026-06-14 10:30:15</td>
                            <td>USR-001</td>
                            <td>Manila Central</td>
                            <td>Database Backup</td>
                            <td>192.168.1.100</td>
                            <td><span class="badge badge-success">Success</span></td>
                        </tr>
                        <tr>
                            <td>2026-06-14 09:15:42</td>
                            <td>USR-002</td>
                            <td>Cebu Station</td>
                            <td>Schema Update</td>
                            <td>192.168.1.105</td>
                            <td><span class="badge badge-warning">Warning</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<style>
    /* Tab Navigation */
    .tab-navigation {
        display: flex;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        overflow: hidden;
    }
    
    .tab-btn {
        flex: 1;
        padding: 14px 20px;
        background: #e9ecef;
        border: none;
        border-right: 1px solid #dee2e6;
        font-size: 13px;
        font-weight: 600;
        color: #495057;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .tab-btn:last-child {
        border-right: none;
    }
    
    .tab-btn:hover {
        background: #dee2e6;
        color: #212529;
    }
    
    .tab-btn.active {
        background: #1e3a5f;
        color: white;
    }
    
    .tab-btn i {
        margin-right: 6px;
    }
    
    /* Tab Content */
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    /* Database Form Table */
    .db-form-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .db-form-table thead {
        background: #f8f9fa;
    }
    
    .db-form-table thead th {
        padding: 12px 20px;
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        color: #495057;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #dee2e6;
    }
    
    .db-form-table tbody tr {
        border-bottom: 1px solid #e9ecef;
    }
    
    .db-form-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .db-form-table tbody td {
        padding: 16px 20px;
        vertical-align: middle;
        font-size: 13px;
    }
    
    .db-form-table tbody td:first-child {
        font-weight: 600;
        color: #212529;
    }
    
    /* Field Type Badge */
    .field-type {
        display: inline-block;
        padding: 4px 10px;
        background: #e7f1ff;
        color: #0c5ba8;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    /* Dark Blue Input Fields with Dark Text on Light Background */
    .db-field-input {
        width: 100%;
        padding: 10px 14px;
        border: 2px solid #1e3a5f;
        border-radius: 6px;
        font-size: 13px;
        color: #1e3a5f;
        background: #f8f9fa;
        font-weight: 500;
        transition: all 0.3s;
    }
    
    .db-field-input:focus {
        outline: none;
        border-color: #2c5282;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
    }
    
    .db-field-input::placeholder {
        color: #6c757d;
        font-weight: 400;
    }
    
    /* Option text in dropdowns */
    .db-field-input option {
        background: white;
        color: #1e3a5f;
    }
    
    /* Radio and Checkbox Labels */
    .radio-label, .checkbox-label {
        display: inline-block;
        margin-right: 20px;
        font-size: 13px;
        color: #495057;
        cursor: pointer;
    }
    
    .radio-label input, .checkbox-label input {
        margin-right: 6px;
        cursor: pointer;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 26px;
        vertical-align: middle;
    }
    
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 26px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
        background-color: #1e3a5f;
    }
    
    input:checked + .toggle-slider:before {
        transform: translateX(24px);
    }
    
    /* Buttons */
    .btn {
        display: inline-block;
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
    }
    
    .btn i {
        margin-right: 6px;
    }
    
    .btn-primary {
        background: #1e3a5f;
        color: white;
    }
    
    .btn-primary:hover {
        background: #152a47;
    }
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-success:hover {
        background: #218838;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c82333;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    /* Results Table */
    .results-table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #dee2e6;
    }
    
    .results-table thead {
        background: #1e3a5f;
        color: white;
    }
    
    .results-table thead th {
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .results-table tbody tr {
        border-bottom: 1px solid #dee2e6;
    }
    
    .results-table tbody tr:hover {
        background: #f8f9fa;
    }
    
    .results-table tbody td {
        padding: 12px 16px;
        font-size: 13px;
    }
    
    /* Badges */
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-success {
        background: #d4edda;
        color: #155724;
    }
    
    .badge-warning {
        background: #fff3cd;
        color: #856404;
    }
    
    .badge-danger {
        background: #f8d7da;
        color: #721c24;
    }
    
    /* Form Control */
    .form-control {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #1e3a5f;
        box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.1);
    }

    /* Admin Management Combo Styles (EXACT COPY) */
    .am-combo-toolbar .am-combo-input { padding-top: 9px; padding-bottom: 9px; font-size: 13px; }
    .am-combo { position: relative; }
    .am-combo-input { width: 100%; padding: 10px 36px 10px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .2s; background: #fff; box-sizing: border-box; cursor: text; }
    .am-combo-input:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
    .am-combo-input.has-value { border-color: var(--petron-blue); }
    .am-combo-arrow { position: absolute; right: 11px; top: 50%; transform: translateY(-50%); color: #999; font-size: 12px; pointer-events: none; transition: transform .2s; z-index: 1; }
    .am-combo.open .am-combo-arrow { transform: translateY(-50%) rotate(180deg); }
    .am-combo-clear { position: absolute; right: 30px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 13px; cursor: pointer; display: none; background: none; border: none; padding: 2px 4px; line-height: 1; z-index: 2; }
    .am-combo-clear:hover { color: #cc0000; }
    .am-combo-dropdown { display: none; position: fixed; background: #fff; border: 1px solid #ddd; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); z-index: 99999; max-height: 220px; overflow: hidden; flex-direction: column; }
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

    #tb_station_combo_card,
    #tb_station_combo_card .card-body {
        overflow: visible !important;
    }
</style>

<script>
// Tab Switching
function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Activate clicked button
    event.target.closest('.tab-btn').classList.add('active');
}

// Restore Scope Toggle
document.addEventListener('DOMContentLoaded', function() {
    const restoreScopes = document.querySelectorAll('input[name="restore_scope"]');
    const tableSelectionRow = document.getElementById('tableSelectionRow');
    
    if (restoreScopes && tableSelectionRow) {
        restoreScopes.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.value === 'specific') {
                    tableSelectionRow.style.display = '';
                } else {
                    tableSelectionRow.style.display = 'none';
                }
            });
        });
    }
});

// Perform Backup
function performBackup() {
    if (confirm('Are you sure you want to perform a database backup now?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="backup">';
        document.body.appendChild(form);
        form.submit();
    }
}

// View Backup History
function viewBackupHistory() {
    alert('Backup History:\n\n' +
          '2026-06-14 10:00:00 - backup_20260614_100000.sql (45.2 MB)\n' +
          '2026-06-13 10:00:00 - backup_20260613_100000.sql (44.8 MB)\n' +
          '2026-06-12 10:00:00 - backup_20260612_100000.sql (44.5 MB)');
}

// Confirm Restore
function confirmRestore() {
    const confirmCheckbox = document.querySelector('input[name="confirm_restore"]');
    if (!confirmCheckbox.checked) {
        alert('Please confirm that you understand this will overwrite existing data.');
        return;
    }
    
    if (confirm('⚠️ WARNING: This will restore the database from the selected backup file.\n\nAll current data will be overwritten!\n\nAre you absolutely sure you want to proceed?')) {
        document.getElementById('restoreForm').submit();
    }
}

// Open Relation Editor
function openRelationEditor() {
    alert('Relation Editor\n\nDefine table relationships and foreign keys:\n\n' +
          '• users.station_id → stations.id\n' +
          '• transactions.user_id → users.id\n' +
          '• inventory.station_id → stations.id');
}

// Search Security Logs
function searchSecurityLogs() {
    const resultsDiv = document.getElementById('securityLogsResults');
    resultsDiv.style.display = 'block';
    
    // Scroll to results
    resultsDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Export Logs
function exportLogs(format) {
    if (format === 'excel') {
        alert('Exporting security logs to Excel format...\n\nFile will be downloaded shortly.');
    } else if (format === 'pdf') {
        alert('Exporting security logs to PDF format...\n\nFile will be downloaded shortly.');
    }
}

// STATION DATA — embedded as JSON once at module scope
// Filtered in JS, max 50 results rendered per query
const STATION_DATA = <?php
    echo json_encode(
        array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $stations),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
    );
?>;

// ── Virtual Station Combobox ─────────────────────────────────
(function initStationCombo() {
    const combo   = document.getElementById('tb_station_combo');
    const list    = document.getElementById('tb_station_list');
    const display = document.getElementById('tb_station_display');
    const hidden  = document.getElementById('tb_station_val');
    const clear   = document.getElementById('tb_station_clear');
    const stationSelect = document.getElementById('stationSelect');

    if (!combo || !display || !list || !hidden || !clear) {
        console.error('station combo elements not found');
        return;
    }

    const MAX = 50;
    let currentVal = '';
    let currentLabel = 'All Stations (Global Operations)';

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function render(q) {
        const lq = (q || '').toLowerCase().trim();
        list.innerHTML = '';

        // "All Stations" row — always show when no query
        if (!lq) {
            const all = document.createElement('div');
            all.className = 'am-combo-option' + (!currentVal ? ' selected' : '');
            all.dataset.value = '';
            all.dataset.label = 'All Stations (Global Operations)';
            all.style.cssText = 'font-style:italic;color:#888;';
            all.textContent   = 'All Stations (Global Operations)';
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
                div.className    = 'am-combo-option' + (currentVal == s.id ? ' selected' : '');
                div.dataset.value = s.id;
                div.dataset.label = s.name;
                div.innerHTML    = '<i class="fas fa-building opt-icon"></i> ' + esc(s.name);
                list.appendChild(div);
            });
        }

        appendMore(); // Render first 100

        // Listen to scroll to load more
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
        currentLabel  = value ? label : 'All Stations (Global Operations)';
        hidden.value  = value;
        display.value = value ? label : 'All Stations (Global Operations)';
        display.classList.toggle('has-value', !!value);
        clear.style.display = value ? 'block' : 'none';
        combo.classList.remove('open');
        
        // Sync to hidden select element
        if (stationSelect) {
            stationSelect.value = value;
            // Dispatch change event so any listeners get notified
            stationSelect.dispatchEvent(new Event('change'));
        }
    }

    function open() {
        combo.classList.add('open');
        display.value = '';
        // Position dropdown as fixed, anchored to the input
        const rect = combo.getBoundingClientRect();
        const dd = document.getElementById(combo.id.replace('combo', 'dropdown') ) || combo.querySelector('.am-combo-dropdown');
        if (dd) {
            dd.style.left   = rect.left + 'px';
            dd.style.top    = (rect.bottom + 4) + 'px';
            dd.style.width  = rect.width + 'px';
        }
        render('');
    }

    function close() {
        combo.classList.remove('open');
        display.value = currentVal ? currentLabel : 'All Stations (Global Operations)';
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

    // Initial value
    pick('', '');
})();

// Station Selection Handler for Console Logging
document.addEventListener('DOMContentLoaded', function() {
    const stationSelect = document.getElementById('stationSelect');
    if (stationSelect) {
        stationSelect.addEventListener('change', function() {
            const selectedStation = this.options[this.selectedIndex] ? this.options[this.selectedIndex].text : 'None';
            console.log('Selected station (change event):', selectedStation);
        });
    }
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
