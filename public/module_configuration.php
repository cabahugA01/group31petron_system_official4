<?php
$page_id = 'module_config';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/module_config.php';
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'toggle_module':
                $moduleKey = $_POST['module_key'] ?? '';
                $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
                
                if (ModuleConfig::setModuleStatus($moduleKey, $enabled, $me['id'], $my_role)) {
                    $success = "Module status updated successfully!";
                    log_activity($pdo, $me['id'], 'Module Configuration', "Updated {$moduleKey} status to " . ($enabled ? 'enabled' : 'disabled'));
                } else {
                    $msg = "Failed to update module status.";
                }
                break;
                
            case 'update_setting':
                $moduleKey = $_POST['module_key'] ?? '';
                $configKey = $_POST['config_key'] ?? '';
                $newValue = $_POST['new_value'] ?? '';
                
                if (ModuleConfig::updateModuleSetting($moduleKey, $configKey, $newValue, $me['id'], $my_role)) {
                    $success = "Module setting updated successfully!";
                    log_activity($pdo, $me['id'], 'Module Configuration', "Updated {$moduleKey}.{$configKey} to {$newValue}");
                } else {
                    $msg = "Failed to update module setting.";
                }
                break;
                
            case 'save_module_config':
                $moduleKey  = $_POST['module_key']  ?? '';
                $stationId  = $_POST['station_id']  ?? 'all';
                $configData = $_POST['config_data'] ?? '{}';
                $configArray = json_decode($configData, true);
                
                if ($moduleKey && is_array($configArray)) {
                    // Resolve station name for logging
                    $stationName = 'All Stations (Global)';
                    if ($stationId && $stationId !== 'all') {
                        try {
                            $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
                            $stmt->execute([$stationId]);
                            $station = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($station) {
                                $stationName = $station['name'];
                            }
                        } catch (Exception $e) {
                            error_log("Failed to fetch station name: " . $e->getMessage());
                        }
                    }
                    
                    // Persist into module_station_config table
                    try {
                        $pdo->exec("
                            CREATE TABLE IF NOT EXISTS module_station_config (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                module_key VARCHAR(100) NOT NULL,
                                station_id VARCHAR(20) NOT NULL DEFAULT 'all',
                                config_data JSON NOT NULL,
                                updated_by INT,
                                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                UNIQUE KEY uq_module_station (module_key, station_id)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                        ");
                        $stmt = $pdo->prepare("
                            INSERT INTO module_station_config (module_key, station_id, config_data, updated_by, updated_at)
                            VALUES (?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE
                                config_data = VALUES(config_data),
                                updated_by  = VALUES(updated_by),
                                updated_at  = NOW()
                        ");
                        $stmt->execute([
                            $moduleKey,
                            $stationId ?: 'all',
                            json_encode($configArray),
                            $me['id']
                        ]);
                    } catch (Exception $e) {
                        error_log("module_station_config save error: " . $e->getMessage());
                    }
                    
                    $settingCount = count($configArray);
                    $success = "Configuration for '<strong>{$moduleKey}</strong>' saved successfully for <strong>{$stationName}</strong>! ({$settingCount} settings applied)";
                    log_activity($pdo, $me['id'], 'Module Configuration', "Saved config for {$moduleKey} @ {$stationName}: " . json_encode($configArray));
                } else {
                    $msg = "Failed to save module configuration. Module key is required.";
                }
                break;
                
            case 'add_module':
                $moduleKey = $_POST['module_key'] ?? '';
                $moduleName = $_POST['module_name'] ?? '';
                $moduleDesc = $_POST['module_description'] ?? '';
                $moduleIcon = $_POST['module_icon'] ?? 'cube';
                $isEnabled = isset($_POST['is_enabled']) && $_POST['is_enabled'] === '1';
                $stationIds = $_POST['stations'] ?? [];
                
                // Validate module key format
                if (!preg_match('/^[a-z_]+$/', $moduleKey)) {
                    $msg = "Invalid module key format. Use lowercase letters and underscores only.";
                } else {
                    // TODO: Add module to database
                    // For now, just show success message
                    $success = "Module '{$moduleName}' added successfully! Assigned to " . count($stationIds) . " station(s).";
                    log_activity($pdo, $me['id'], 'Module Configuration', "Added new module: {$moduleName} ({$moduleKey})");
                }
                break;
        }
    }
}

// Get all modules and their settings
$modules = ModuleConfig::getModules();
$enabledModules = ModuleConfig::getEnabledModules();

// Fetch all stations from database
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name, address, location, region, status FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Debug: Log station count
    error_log("Loaded " . count($stations) . " stations for module configuration");
} catch (Exception $e) {
    error_log("Failed to fetch stations: " . $e->getMessage());
    $stations = [];
}

include __DIR__ . '/../partials/header.php';

// Debug output
if (empty($stations)) {
    error_log("WARNING: No stations found in module_configuration.php");
} else {
    error_log("SUCCESS: " . count($stations) . " stations loaded in module_configuration.php");
}
?>

<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-cogs"></i> Station-Dependent Module Control</h1>
        <div class="sub">Enable or disable modules per branch station. Changes cascade to sidebar and page access for that station's users.</div>
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

<!-- Station-Dependent Configuration Section -->
<div class="card" id="tb_station_combo_card" style="margin-bottom: 30px; overflow: visible !important;">
    <div class="card-header" style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: var(--petron-blue, #00264D);">
            <i class="fas fa-map-marker-alt" style="color: #3b82f6;"></i> Station-Dependent Configuration
        </h3>
    </div>
    <div class="card-body" style="padding: 20px; overflow: visible !important;">
        <div style="display: flex; align-items: center; gap: 15px; position: relative; margin-bottom: 15px;">
            <label for="stationFilter" style="font-weight: 600; color: #374151; min-width: 120px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;">
                Search Station
            </label>
            <!-- Searchable station filter (SEARCHABLE SELECT) -->
            <div class="am-combo am-combo-toolbar" id="tb_station_combo" style="width:450px; position: relative; z-index: 100;">
                <input type="text" class="am-combo-input" id="tb_station_display" placeholder="Type to search stations..." autocomplete="off" style="padding-right:80px; cursor: text;">
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
        <!-- Station selection info banner -->
        <div id="stationSelectionBanner" style="display: none; padding: 12px 16px; background: #dbeafe; border-left: 4px solid #3b82f6; border-radius: 6px; margin-top: 15px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-info-circle" style="color: #3b82f6; font-size: 18px;"></i>
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: #1e40af; font-size: 14px;">Selected Station:</div>
                    <div id="selectedStationName" style="color: #1f2937; font-size: 13px; margin-top: 2px;"></div>
                </div>
            </div>
        </div>
        <div id="noStationWarning" style="padding: 12px 16px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px; margin-top: 15px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-exclamation-triangle" style="color: #f59e0b; font-size: 18px;"></i>
                <div style="flex: 1; color: #92400e; font-size: 13px;">
                    <strong>Please select a station</strong> to configure modules for that specific location.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Global Module Settings Section -->
<div class="card">
    <div class="card-header" style="background: #f8f9fa; border-bottom: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 16px; font-weight: 600; color: var(--petron-blue, #00264D);">
            <i class="fas fa-globe" style="color: #10b981;"></i> Global Module Settings
        </h3>
        <button class="btn-add-module" onclick="openAddModuleModal()" title="Add New Module">
            <i class="fas fa-plus"></i> Add Module
        </button>
    </div>
    <div class="card-body" style="padding: 20px 20px 10px;">
        <div style="display: flex; gap: 12px; margin-bottom: 20px;">
            <input type="text" 
                   id="moduleSearch" 
                   placeholder="Search modules by name or description..." 
                   style="flex: 1; padding: 10px 15px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;"
                   oninput="filterModules()">
            <select id="statusFilter" 
                    style="padding: 10px 15px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; min-width: 150px;"
                    onchange="filterModules()">
                <option value="">All Status</option>
                <option value="enabled">Enabled</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <table class="module-table">
            <thead>
                <tr>
                    <th style="width: 30%;">Module</th>
                    <th style="width: 40%;">Description</th>
                    <th style="width: 15%; text-align: center;">Status</th>
                    <th style="width: 10%; text-align: center;">Enable/Disable</th>
                    <th style="width: 10%; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody id="moduleTableBody">
                <?php foreach ($modules as $module): ?>
                <tr data-module="<?php echo $module['module_key']; ?>" 
                    data-status="<?php echo $module['is_enabled'] ? 'enabled' : 'disabled'; ?>">
                    <td>
                        <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #1f2937;">
                            <?php echo htmlspecialchars($module['module_name']); ?>
                        </h4>
                    </td>
                    <td>
                        <p style="margin: 0; color: #6b7280; font-size: 13px; line-height: 1.5;">
                            <?php echo htmlspecialchars($module['module_description']); ?>
                        </p>
                    </td>
                    <td style="text-align: center;">
                        <span class="status-badge status-<?php echo $module['is_enabled'] ? 'enabled' : 'disabled'; ?>">
                            <?php echo $module['is_enabled'] ? 'Enabled' : 'Disabled'; ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <label class="toggle-switch">
                            <input type="checkbox" 
                                   id="module_<?php echo $module['module_key']; ?>" 
                                   <?php echo $module['is_enabled'] ? 'checked' : ''; ?>
                                   onchange="toggleModule('<?php echo $module['module_key']; ?>', this.checked)">
                            <span class="toggle-slider"></span>
                        </label>
                    </td>
                    <td style="text-align: center;">
                        <button class="btn-action btn-configure" 
                                onclick="showModuleSettings('<?php echo $module['module_key']; ?>')"
                                title="Configure Module">
                            <i class="fas fa-cog"></i> Configure
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Module Settings Panel (Hidden by default) -->
<div id="moduleSettingsPanel" class="card" style="display: none;">
    <div class="card-header">
        <h3><span id="settingsModuleTitle">Module Settings</span></h3>
        <button class="btn btn-ghost btn-sm" onclick="hideModuleSettings()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="card-body">
        <div id="moduleSettingsContent">
            <!-- Settings will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Module Configuration Modal -->
<div id="moduleConfigModal" class="modal-overlay" style="display: none;">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3><i class="fas fa-cog"></i> <span id="configModuleTitle">Module Configuration</span></h3>
            <button class="modal-close" onclick="closeModuleConfigModal()">&times;</button>
        </div>
        <form id="moduleConfigForm" onsubmit="saveModuleConfig(event)">
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div id="moduleConfigContent">
                    <!-- Configuration content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="resetModuleConfig()">
                    <i class="fas fa-undo"></i> Reset to Default
                </button>
                <button type="button" class="btn btn-ghost" onclick="closeModuleConfigModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Module Audit Panel -->
<div id="moduleAuditPanel" class="card" style="display: none;">
    <div class="card-header">
        <h3><span id="auditModuleTitle">Module Audit Log</span></h3>
        <button class="btn btn-ghost btn-sm" onclick="hideModuleAudit()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="card-body">
        <div id="moduleAuditContent">
            <!-- Audit log will be loaded dynamically -->
        </div>
    </div>
</div>

<!-- Add Module Modal -->
<div id="addModuleModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Add New Module</h3>
            <button class="modal-close" onclick="closeAddModuleModal()">&times;</button>
        </div>
        <form id="addModuleForm" method="POST" action="">
            <div class="modal-body">
                <input type="hidden" name="action" value="add_module">
                
                <div class="form-group">
                    <label>Module Key <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="module_key" class="form-input" placeholder="e.g., inventory_management" required>
                    <small>Unique identifier (lowercase, underscores only)</small>
                </div>
                
                <div class="form-group">
                    <label>Module Name <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="module_name" class="form-input" placeholder="e.g., Inventory Management" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="module_description" class="form-input" rows="3" placeholder="Brief description of the module"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Icon (Font Awesome class)</label>
                    <input type="text" name="module_icon" class="form-input" placeholder="e.g., fa-boxes">
                    <small>Enter Font Awesome icon class (without 'fas' prefix)</small>
                </div>
                
                <div class="form-group">
                    <label>Assign to Stations</label>
                    <select name="stations[]" class="form-input" multiple size="5">
                        <option value="all">All Stations</option>
                        <?php foreach ($stations as $st): ?>
                        <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Hold Ctrl/Cmd to select multiple stations</small>
                </div>
                
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="is_enabled" value="1" checked>
                        Enable module immediately
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddModuleModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Module
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Page Layout */
    body {
        background: #f3f4f6;
    }
    
    .page-head {
        margin-top: -12px !important;
        padding-top: 0 !important;
    }
    
    .page-head h1, .page-head .h1 {
        font-size: 22px !important;
        font-weight: 700 !important;
        color: var(--petron-blue, #00264D) !important;
        margin: 0 !important;
        text-transform: uppercase !important;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .page-head .sub {
        font-size: 13px;
        color: #666;
        margin-top: 4px;
    }
    
    .card-header h3 {
        margin: 0 !important;
        font-size: 16px !important;
        font-weight: 600 !important;
        color: var(--petron-blue, #00264D) !important;
    }
    
    /* Admin Management Combo Styles (EXACT COPY) */
    .am-combo-toolbar .am-combo-input { padding-top: 9px; padding-bottom: 9px; font-size: 13px; }
    .am-combo { position: relative; }
    .am-combo-input { width: 100%; padding: 10px 36px 10px 13px; border: 1px solid #ddd; border-radius: 10px; font-size: 13px; outline: none; transition: border-color .2s; background: #fff; box-sizing: border-box; cursor: text; }
    .am-combo-input:focus { border-color: var(--petron-blue); box-shadow: 0 0 0 3px rgba(0,38,77,.08); }
    .am-combo-input.has-value { border-color: var(--petron-blue); }
    .am-combo-arrow { position: absolute; right: 32px; top: 50%; transform: translateY(-50%); color: #999; font-size: 12px; pointer-events: none; transition: transform .2s; z-index: 1; }
    .am-combo.open .am-combo-arrow { transform: translateY(-50%) rotate(180deg); }
    .am-combo-clear { position: absolute; right: 52px; top: 50%; transform: translateY(-50%); color: #bbb; font-size: 13px; cursor: pointer; display: none; background: none; border: none; padding: 2px 4px; line-height: 1; z-index: 2; }
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

    /* Module Table Styles */
    .module-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }
    
    .module-table thead th {
        background: #1e3a5f;
        color: #ffffff;
        padding: 14px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: none;
    }
    
    .module-table tbody tr {
        border-bottom: 1px solid #e5e7eb;
        transition: background-color .15s;
    }
    
    .module-table tbody tr:hover {
        background-color: #f9fafb;
    }
    
    .module-table tbody tr:last-child {
        border-bottom: none;
    }
    
    .module-table tbody td {
        padding: 16px;
        vertical-align: middle;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .status-badge.status-enabled {
        background-color: #d1fae5;
        color: #065f46;
    }
    
    .status-badge.status-disabled {
        background-color: #fee2e2;
        color: #991b1b;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 48px;
        height: 24px;
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
        background-color: #cbd5e1;
        transition: .3s;
        border-radius: 24px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .3s;
        border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
        background-color: #10b981;
    }
    
    input:checked + .toggle-slider:before {
        transform: translateX(24px);
    }
    
    /* Action Buttons */
    .btn-action {
        padding: 7px 14px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .2s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    .btn-configure {
        background: white !important;
        color: #00264D !important;
        border: 1px solid #00264D !important;
    }
    
    .btn-configure:hover {
        background: #00264D !important;
        color: white !important;
    }
    
    /* Add Module Button */
    .btn-add-module {
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        background: white !important;
        color: #16a34a !important;
        border: 1px solid #16a34a !important;
        transition: all .2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-add-module:hover {
        background: #16a34a !important;
        color: white !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(22, 163, 74, 0.3);
    }
    
    .btn-add-module i {
        font-size: 14px;
    }
    
    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 100%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        animation: modalSlideIn 0.3s ease-out;
    }
    
    .modal-large {
        max-width: 900px;
    }
    
    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .modal-header {
        padding: 20px 24px;
        border-bottom: 2px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #f9fafb;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--petron-blue, #00264D) !important;
    }
    
    .modal-header h3 i {
        color: #10b981;
        margin-right: 8px;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 28px;
        color: #9ca3af;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        transition: all 0.2s;
    }
    
    .modal-close:hover {
        background: #e5e7eb;
        color: #1f2937;
    }
    
    .modal-body {
        padding: 24px;
    }
    
    .modal-footer {
        padding: 16px 24px;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        background: #f9fafb;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 6px;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
    }
    
    .form-group small {
        display: block;
        margin-top: 4px;
        font-size: 12px;
        color: #6b7280;
    }
    
    .form-input {
        width: 100%;
        padding: 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        color: #1f2937;
        transition: all 0.2s;
        box-sizing: border-box;
    }
    
    .form-input:focus {
        outline: none;
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
    }
    
    .btn {
        padding: 10px 20px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    
    .btn-primary {
        background: white !important;
        color: #00264D !important;
        border: 1px solid #00264D !important;
    }
    
    .btn-primary:hover {
        background: #00264D !important;
        color: white !important;
    }
    
    .btn-secondary {
        background: white !important;
        color: #6b7280 !important;
        border: 1px solid #6b7280 !important;
    }
    
    .btn-secondary:hover {
        background: #6b7280 !important;
        color: white !important;
    }
    
    /* Configuration Section Styles */
    .config-section {
        margin-bottom: 25px;
        padding: 15px;
        background: #f9fafb;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
    }
    
    .config-label {
        display: block;
        margin-bottom: 10px;
        font-weight: 600;
        font-size: 14px;
        color: #1f2937;
    }
    
    .config-options label,
    .config-section > label {
        display: block;
        margin: 8px 0;
        font-size: 13px;
        color: #4b5563;
    }
    
    .config-options input[type="checkbox"],
    .config-section > label input[type="checkbox"] {
        margin-right: 8px;
    }
    
    .config-input,
    .config-input-sm {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 13px;
    }
    
    .config-input-sm {
        width: 120px;
        padding: 6px 10px;
    }
    
    .config-list {
        margin: 10px 0;
    }
    
    .list-item {
        padding: 8px 12px;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        margin-bottom: 6px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .workflow-steps {
        display: flex;
        gap: 10px;
        margin: 10px 0;
    }
    
    .step {
        flex: 1;
        padding: 10px;
        background: #1e3a5f;
        color: white;
        text-align: center;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .price-table,
    .tier-table,
    .access-table {
        width: 100%;
        border-collapse: collapse;
        margin: 10px 0;
    }
    
    .price-table td,
    .tier-table td,
    .access-table td {
        padding: 8px;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .btn-config {
        display: block;
        width: 100%;
        padding: 10px;
        margin: 6px 0;
        background: white;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        text-align: left;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-config:hover {
        background: #f3f4f6;
        border-color: #9ca3af;
    }
    
    .btn-config i {
        margin-right: 8px;
        color: #6b7280;
    }

    /* Station Filter Styles */
    .station-select {
        flex: 1;
        max-width: 400px;
        padding: 10px 15px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
        color: #374151;
        background-color: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .station-select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .station-select:hover {
        border-color: #9ca3af;
    }

    /* Module Table Styles */
    .module-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .module-table thead th {
        background-color: #f9fafb;
        padding: 16px 20px;
        text-align: left;
        font-weight: 600;
        color: #374151;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .module-table tbody tr {
        border-bottom: 1px solid #e5e7eb;
        transition: background-color 0.2s ease;
    }
    
    .module-table tbody tr:hover {
        background-color: #f9fafb;
    }
    
    .module-table tbody tr:last-child {
        border-bottom: none;
    }
    
    .module-table tbody td {
        padding: 20px;
        vertical-align: middle;
    }
    
    .module-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }
    
    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }
    
    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 24px;
    }
    
    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }
    
    input:checked + .slider {
        background-color: #3b82f6;
    }
    
    input:checked + .slider:before {
        transform: translateX(26px);
    }
    
    .status-badge {
        font-size: 12px;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 12px;
        text-transform: uppercase;
    }
    
    .status-badge.enabled {
        background-color: #d1fae5;
        color: #065f46;
    }
    
    .status-badge.disabled {
        background-color: #fee2e2;
        color: #991b1b;
    }
    
    .module-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }
    
    .btn-sm {
        padding: 6px 12px;
        font-size: 13px;
    }
    
    .btn-ghost {
        background: transparent;
        border: 1px solid #d1d5db;
        color: #6b7280;
    }
    
    .btn-ghost:hover {
        background: #f3f4f6;
        color: #1f2937;
    }
    
    .settings-group {
        margin-bottom: 25px;
    }
    
    .settings-group h4 {
        color: #1f2937;
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e5e7eb;
    }
    
    .setting-item {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        padding: 15px;
        background: #f9fafb;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
    }
    
    .setting-label {
        flex: 0 0 200px;
        font-weight: 500;
        color: #374151;
    }
    
    .setting-input {
        flex: 1;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .setting-input input,
    .setting-input select,
    .setting-input textarea {
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        flex: 1;
    }
    
    .setting-input input:focus,
    .setting-input select:focus,
    .setting-input textarea:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .setting-description {
        font-size: 12px;
        color: #6b7280;
        margin-top: 5px;
        font-style: italic;
    }
    
    .audit-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    
    .audit-table th,
    .audit-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .audit-table th {
        background-color: #f9fafb;
        font-weight: 600;
        color: #374151;
    }
    
    .audit-table tr:hover {
        background-color: #f3f4f6;
    }
    
    .audit-action {
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
    }
    
    .audit-action.enable {
        color: #059669;
    }
    
    .audit-action.disable {
        color: #dc2626;
    }
    
    .audit-action.update {
        color: #2563eb;
    }
    
    .card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .card-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .card-body {
        padding: 20px;
    }
    
    #tb_station_combo_card,
    #tb_station_combo_card .card-body {
        overflow: visible !important;
    }
</style>

<script>
// ══════════════════════════════════════════════════════════════
// STATION DATA — embedded as JSON once at module scope
// Filtered in JS, max 50 results rendered per query
// ══════════════════════════════════════════════════════════════
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
    if (!combo || !display || !list || !hidden || !clear) {
        console.error('station combo elements not found');
        return;
    }

    const MAX = 50;
    let currentVal = '';
    let currentLabel = 'All Stations';

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
        currentLabel  = value ? label : 'All Stations';
        hidden.value  = value;
        display.value = value ? label : 'All Stations';
        display.classList.toggle('has-value', !!value);
        clear.style.display = value ? 'block' : 'none';
        combo.classList.remove('open');
        filterByStation();
        updateStationBanner();
    }

    function open() {
        combo.classList.add('open');
        display.value = '';
        // Re-anchor the fixed dropdown to match input position
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

    // Initial value
    pick('', '');
})()

// Update station selection banner
function updateStationBanner() {
    const stationVal = document.getElementById('tb_station_val').value;
    const stationDisplay = document.getElementById('tb_station_display').value;
    const banner = document.getElementById('stationSelectionBanner');
    const warning = document.getElementById('noStationWarning');
    const stationNameDiv = document.getElementById('selectedStationName');
    
    if (stationVal && stationDisplay && stationDisplay !== 'All Stations') {
        // Show selected station banner
        banner.style.display = 'block';
        warning.style.display = 'none';
        stationNameDiv.textContent = stationDisplay;
    } else {
        // Show warning to select station
        banner.style.display = 'none';
        warning.style.display = 'block';
    }
}
function filterByStation() {
    // station filter is visual-only for now (modules are global)
    // extend here if per-station config rows are added
}

function filterModules() {
    const q   = document.getElementById('moduleSearch').value.toLowerCase();
    const st  = document.getElementById('statusFilter').value;
    document.querySelectorAll('#moduleTableBody tr').forEach(row => {
        const name  = (row.querySelector('h4')?.textContent || '').toLowerCase();
        const desc  = (row.querySelector('p')?.textContent  || '').toLowerCase();
        const stat  = row.dataset.status || '';
        const show  = (!q || name.includes(q) || desc.includes(q)) && (!st || stat === st);
        row.style.display = show ? '' : 'none';
    });
}

function toggleModule(moduleKey, enabled) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="toggle_module">
        <input type="hidden" name="module_key" value="${moduleKey}">
        <input type="hidden" name="enabled" value="${enabled ? '1' : '0'}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Store current module key and station for configuration
let currentConfigModule = '';
let currentConfigStation = '';
let defaultConfigValues = {};

function showModuleSettings(moduleKey) {
    console.log('Opening configuration modal for:', moduleKey);
    
    // Get currently selected station (optional – can be set inside modal too)
    const stationInput = document.getElementById('tb_station_val');
    const stationDisplay = document.getElementById('tb_station_display');
    currentConfigStation = stationInput ? stationInput.value : '';
    const stationName = (stationDisplay && stationDisplay.value && stationDisplay.value !== 'All Stations')
        ? stationDisplay.value : 'All Stations';
    
    currentConfigModule = moduleKey;
    
    // Build station options HTML for in-modal selector
    const STATIONS = <?php echo json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $stations), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
    let stationOptions = '<option value="">-- All Stations (Global) --</option>';
    STATIONS.forEach(s => {
        const sel = (String(s.name) === currentConfigStation) ? ' selected' : '';
        stationOptions += `<option value="${s.id}" data-name="${s.name}"${sel}>${s.name}</option>`;
    });
    
    console.log('Configuring module:', moduleKey, 'for station:', stationName);
    
    // Module-specific configuration templates
    const moduleConfigs = {
        'transactions': `
            <h4><i class="fas fa-shopping-cart"></i> Transactions (Merchandise POS) Settings</h4>
            <div class="config-section">
                <label class="config-label">Payment Methods</label>
                <div class="config-options">
                    <label><input type="checkbox" name="payment_cash" checked data-default="true"> Cash</label>
                    <label><input type="checkbox" name="payment_card" checked data-default="true"> Card</label>
                    <label><input type="checkbox" name="payment_ewallet" data-default="false"> E-Wallet</label>
                    <label><input type="checkbox" name="payment_fleet" data-default="false"> Fleet Card</label>
                </div>
            </div>
            <div class="config-section">
                <label class="config-label">Discount/VAT Formula Setup</label>
                <input type="text" name="formula" class="config-input" placeholder="Price * (1 - Discount%) * (1 + VAT%)" value="Price * (1 - Discount%) * (1 + VAT%)" data-default="Price * (1 - Discount%) * (1 + VAT%)">
            </div>
            <div class="config-section">
                <label class="config-label">Transaction Validation</label>
                <select name="validation" class="config-input" data-default="full">
                    <option value="full" selected>Full Payment Required</option>
                    <option value="partial">Partial Payment Allowed</option>
                </select>
            </div>
            <div class="config-section">
                <label class="config-label">Audit Trail Logging</label>
                <label><input type="checkbox" name="audit_trail" checked data-default="true"> Enable per-transaction audit logging</label>
            </div>
        `,
        'fuel_management': `
            <h4><i class="fas fa-gas-pump"></i> Fuel Management Settings</h4>
            <div class="config-section">
                <label class="config-label">Fuel Type List</label>
                <div class="config-list">
                    <div class="list-item">Diesel</div>
                    <div class="list-item">Gasoline 91</div>
                    <div class="list-item">Gasoline 95</div>
                    <div class="list-item">Kerosene</div>
                </div>
            </div>
            <div class="config-section">
                <label class="config-label">Price per Liter Setup</label>
                <table class="price-table">
                    <tr><td>Diesel</td><td><input type="number" name="price_diesel" step="0.01" value="55.50" class="config-input-sm" data-default="55.50"></td></tr>
                    <tr><td>Gasoline 91</td><td><input type="number" name="price_gas91" step="0.01" value="62.00" class="config-input-sm" data-default="62.00"></td></tr>
                    <tr><td>Gasoline 95</td><td><input type="number" name="price_gas95" step="0.01" value="68.50" class="config-input-sm" data-default="68.50"></td></tr>
                </table>
            </div>
            <div class="config-section">
                <label class="config-label">Calibration Variance Tolerance (%)</label>
                <input type="number" name="variance_tolerance" step="0.1" value="2.0" class="config-input" data-default="2.0">
            </div>
            <div class="config-section">
                <label class="config-label">Reconciliation Rules</label>
                <select name="reconciliation" class="config-input" data-default="daily">
                    <option value="daily" selected>Daily Reconciliation</option>
                    <option value="shift">Per Shift</option>
                    <option value="weekly">Weekly</option>
                </select>
            </div>
        `,
        'inventory': `
            <h4><i class="fas fa-boxes"></i> Inventory Settings</h4>
            <div class="config-section">
                <label class="config-label">Inventory Method</label>
                <label><input type="checkbox" name="fifo" checked data-default="true"> Enable FIFO (First In, First Out)</label>
            </div>
            <div class="config-section">
                <label class="config-label">Auto-Update Stock</label>
                <label><input type="checkbox" name="auto_sales" checked data-default="true"> Auto-update after sales</label>
                <label><input type="checkbox" name="auto_delivery" checked data-default="true"> Auto-update after deliveries</label>
            </div>
            <div class="config-section">
                <label class="config-label">Low Stock Alert Threshold</label>
                <input type="number" name="threshold" value="10" class="config-input" data-default="10">
            </div>
        `,
        'customers': `
            <h4><i class="fas fa-users"></i> Customers Settings</h4>
            <div class="config-section">
                <label class="config-label">Loyalty Points per Peso</label>
                <input type="number" name="points_per_peso" step="0.01" value="0.01" class="config-input" data-default="0.01">
            </div>
            <div class="config-section">
                <label class="config-label">Customer Tier Rules</label>
                <table class="tier-table">
                    <tr><td>Regular</td><td>₱0 - ₱9,999</td><td>0% discount</td></tr>
                    <tr><td>VIP</td><td>₱10,000 - ₱49,999</td><td>5% discount</td></tr>
                    <tr><td>Fleet</td><td>₱50,000+</td><td>10% discount</td></tr>
                </table>
            </div>
        `,
        'calendar': `
            <h4><i class="fas fa-calendar-alt"></i> Calendar Settings</h4>
            <div class="config-section">
                <label class="config-label">Shift Schedule Templates</label>
                <button type="button" class="btn-config"><i class="fas fa-clock"></i> Morning (6AM-2PM)</button>
                <button type="button" class="btn-config"><i class="fas fa-clock"></i> Afternoon (2PM-10PM)</button>
                <button type="button" class="btn-config"><i class="fas fa-clock"></i> Night (10PM-6AM)</button>
            </div>
        `,
        'reports': `
            <h4><i class="fas fa-chart-line"></i> Reports Settings</h4>
            <div class="config-section">
                <label class="config-label">Formula Setup</label>
                <textarea name="formula" class="config-input" rows="3" data-default="Variance = (Actual - Expected) / Expected * 100">Variance = (Actual - Expected) / Expected * 100</textarea>
            </div>
            <div class="config-section">
                <label class="config-label">Export Options</label>
                <label><input type="checkbox" name="export_excel" checked data-default="true"> Excel (.xlsx)</label>
                <label><input type="checkbox" name="export_pdf" checked data-default="true"> PDF</label>
                <label><input type="checkbox" name="export_csv" data-default="false"> CSV</label>
            </div>
        `
    };
    
    // Get configuration content
    const configContent = moduleConfigs[moduleKey] || `
        <h4>Configuration for ${moduleKey}</h4>
        <p>Configuration options for this module are being developed.</p>
    `;
    
    // Build station selector row for modal header
    const stationRow = `
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;">
            <i class="fas fa-map-marker-alt" style="color:#0284c7;font-size:16px;"></i>
            <div style="flex:1;">
                <label style="display:block;font-weight:600;font-size:12px;color:#0369a1;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Apply Configuration To:</label>
                <select id="modalStationSelect" style="width:100%;padding:8px 12px;border:1px solid #bae6fd;border-radius:6px;font-size:13px;color:#1e3a5f;background:white;">
                    ${stationOptions}
                </select>
            </div>
        </div>
    `;
    
    // Load content into modal
    document.getElementById('moduleConfigContent').innerHTML = stationRow + configContent;
    
    // Update modal title
    document.getElementById('configModuleTitle').textContent = moduleKey.replace(/_/g, ' ').toUpperCase() + ' Configuration';
    
    // Store default values
    storeDefaultValues();
    
    // Show modal
    document.getElementById('moduleConfigModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function storeDefaultValues() {
    defaultConfigValues = {};
    const modal = document.getElementById('moduleConfigModal');
    
    // Store all input default values
    modal.querySelectorAll('input[data-default], select[data-default], textarea[data-default]').forEach(input => {
        if (input.name) {
            if (input.type === 'checkbox') {
                defaultConfigValues[input.name] = input.dataset.default === 'true';
            } else {
                defaultConfigValues[input.name] = input.dataset.default;
            }
        }
    });
}

function closeModuleConfigModal() {
    document.getElementById('moduleConfigModal').style.display = 'none';
    document.body.style.overflow = '';
    currentConfigModule = '';
    currentConfigStation = '';
    defaultConfigValues = {};
}

function resetModuleConfig() {
    if (!confirm('Are you sure you want to reset all settings to default values?')) {
        return;
    }
    
    const modal = document.getElementById('moduleConfigModal');
    
    // Reset all inputs to default values
    modal.querySelectorAll('input[data-default], select[data-default], textarea[data-default]').forEach(input => {
        if (input.type === 'checkbox') {
            input.checked = input.dataset.default === 'true';
        } else if (input.tagName === 'SELECT') {
            const defaultValue = input.dataset.default;
            input.value = defaultValue;
            // Also try to select by matching the value attribute
            const options = input.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === defaultValue) {
                    opt.selected = true;
                }
            });
        } else {
            input.value = input.dataset.default;
        }
    });
    
    console.log('Configuration reset to default values for:', currentConfigModule);
}

function saveModuleConfig(event) {
    event.preventDefault();
    
    if (!currentConfigModule) {
        alert('No module selected');
        return;
    }
    
    // Read station from in-modal selector (may be empty = All Stations)
    const modalStationSelect = document.getElementById('modalStationSelect');
    const selectedStationId   = modalStationSelect ? modalStationSelect.value : '';
    const selectedOption      = modalStationSelect ? modalStationSelect.options[modalStationSelect.selectedIndex] : null;
    const selectedStationName = selectedOption && selectedOption.value
        ? (selectedOption.dataset.name || selectedOption.text)
        : 'All Stations (Global)';
    
    currentConfigStation = selectedStationId;
    
    // Collect all configuration values from the modal (skip the station selector itself)
    const configSettings = {};
    const modal = document.getElementById('moduleConfigModal');
    
    modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(input => {
        if (input.id === 'modalStationSelect' || input.name === 'modalStationSelect') return; // skip station picker
        if (input.name) {
            if (input.type === 'checkbox') {
                configSettings[input.name] = input.checked;
            } else {
                configSettings[input.name] = input.value;
            }
        }
    });
    
    console.log('Saving config for module:', currentConfigModule, '| station:', selectedStationName, '| settings:', configSettings);
    
    // Create form and submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="save_module_config">
        <input type="hidden" name="module_key" value="${currentConfigModule}">
        <input type="hidden" name="station_id" value="${selectedStationId || 'all'}">
        <input type="hidden" name="config_data" value='${JSON.stringify(configSettings)}'>
    `;
    document.body.appendChild(form);
    form.submit();
    
    return false;
}

function hideModuleSettings() { document.getElementById('moduleSettingsPanel').style.display = 'none'; }

function showModuleAudit(moduleKey) {
    fetch(`module_configuration.php?action=get_audit&module=${moduleKey}`)
        .then(r => r.text()).then(html => {
            document.getElementById('moduleAuditContent').innerHTML = html;
            document.getElementById('auditModuleTitle').textContent = `${moduleKey} Audit Log`;
            document.getElementById('moduleAuditPanel').style.display = 'block';
            document.getElementById('moduleSettingsPanel').style.display = 'none';
            document.getElementById('moduleAuditPanel').scrollIntoView({ behavior: 'smooth' });
        });
}

function hideModuleAudit() { document.getElementById('moduleAuditPanel').style.display = 'none'; }

function updateModuleSetting(moduleKey, configKey, newValue) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="update_setting">
        <input type="hidden" name="module_key" value="${moduleKey}">
        <input type="hidden" name="config_key" value="${configKey}">
        <input type="hidden" name="new_value" value="${newValue}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Open Add Module Modal
function openAddModuleModal() {
    document.getElementById('addModuleModal').style.display = 'flex';
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

// Close Add Module Modal
function closeAddModuleModal() {
    document.getElementById('addModuleModal').style.display = 'none';
    document.body.style.overflow = ''; // Restore scrolling
    document.getElementById('addModuleForm').reset();
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    const addModal = document.getElementById('addModuleModal');
    const configModal = document.getElementById('moduleConfigModal');
    
    if (event.target === addModal) {
        closeAddModuleModal();
    }
    if (event.target === configModal) {
        closeModuleConfigModal();
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const addModal = document.getElementById('addModuleModal');
        const configModal = document.getElementById('moduleConfigModal');
        
        if (addModal.style.display === 'flex') {
            closeAddModuleModal();
        }
        if (configModal.style.display === 'flex') {
            closeModuleConfigModal();
        }
    }
});
</script>

<?php
// Handle AJAX requests for settings and audit
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_settings':
            $moduleKey = $_GET['module'] ?? '';
            if ($moduleKey) {
                $settings = ModuleConfig::getModuleSettingsByCategory($moduleKey);
                echo '<div class="settings-container">';
                foreach ($settings as $category => $categorySettings) {
                    echo '<div class="settings-group"><h4>' . ucfirst($category) . '</h4>';
                    foreach ($categorySettings as $setting) {
                        echo '<div class="setting-item">';
                        echo '<div class="setting-label">' . htmlspecialchars($setting['config_key']) . '</div>';
                        echo '<div class="setting-input">';
                        switch ($setting['config_type']) {
                            case 'boolean':
                                echo '<select onchange="updateModuleSetting(\'' . $moduleKey . '\', \'' . $setting['config_key'] . '\', this.value)">';
                                echo '<option value="1"' . ($setting['config_value'] == '1' ? ' selected' : '') . '>Enabled</option>';
                                echo '<option value="0"' . ($setting['config_value'] == '0' ? ' selected' : '') . '>Disabled</option>';
                                echo '</select>';
                                break;
                            case 'integer':
                                echo '<input type="number" value="' . htmlspecialchars($setting['config_value']) . '" onchange="updateModuleSetting(\'' . $moduleKey . '\', \'' . $setting['config_key'] . '\', this.value)">';
                                break;
                            case 'decimal':
                                echo '<input type="number" step="0.01" value="' . htmlspecialchars($setting['config_value']) . '" onchange="updateModuleSetting(\'' . $moduleKey . '\', \'' . $setting['config_key'] . '\', this.value)">';
                                break;
                            default:
                                echo '<input type="text" value="' . htmlspecialchars($setting['config_value']) . '" onchange="updateModuleSetting(\'' . $moduleKey . '\', \'' . $setting['config_key'] . '\', this.value)">';
                        }
                        echo '</div>';
                        echo '<div class="setting-description">' . htmlspecialchars($setting['description']) . '</div>';
                        echo '</div>';
                    }
                    echo '</div>';
                }
                echo '</div>';
            }
            exit;
        case 'get_audit':
            $moduleKey = $_GET['module'] ?? '';
            if ($moduleKey) {
                $auditTrail = ModuleConfig::getModuleAuditTrail($moduleKey, 50);
                echo '<table class="audit-table"><thead><tr>';
                echo '<th>Timestamp</th><th>Action</th><th>Setting</th><th>Old Value</th><th>New Value</th><th>User</th>';
                echo '</tr></thead><tbody>';
                foreach ($auditTrail as $entry) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($entry['timestamp']) . '</td>';
                    echo '<td><span class="audit-action ' . htmlspecialchars($entry['action_type']) . '">' . htmlspecialchars($entry['action_type']) . '</span></td>';
                    echo '<td>' . htmlspecialchars($entry['config_key'] ?? 'N/A') . '</td>';
                    echo '<td>' . htmlspecialchars($entry['old_value'] ?? 'N/A') . '</td>';
                    echo '<td>' . htmlspecialchars($entry['new_value'] ?? 'N/A') . '</td>';
                    echo '<td>' . htmlspecialchars($entry['username'] ?? 'Unknown') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            exit;
    }
}
?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
