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
        }
    }
}

// Get all modules and their settings
$modules = ModuleConfig::getModules();
$enabledModules = ModuleConfig::getEnabledModules();

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Module Configuration</h1>
        <div class="sub">Control and customize system modules</div>
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

<!-- Module Status Overview -->
<div class="card">
    <div class="card-header">
        <h3>Module Status</h3>
    </div>
    <div class="card-body">
        <div class="module-grid">
            <?php foreach ($modules as $module): ?>
                <div class="module-card" data-module="<?php echo $module['module_key']; ?>">
                    <div class="module-header">
                        <div class="module-info">
                            <h4><?php echo htmlspecialchars($module['module_name']); ?></h4>
                            <p><?php echo htmlspecialchars($module['module_description']); ?></p>
                        </div>
                        <div class="module-toggle">
                            <label class="switch">
                                <input type="checkbox" 
                                       id="module_<?php echo $module['module_key']; ?>" 
                                       <?php echo $module['is_enabled'] ? 'checked' : ''; ?>
                                       onchange="toggleModule('<?php echo $module['module_key']; ?>', this.checked)">
                                <span class="slider"></span>
                            </label>
                            <span class="status-badge <?php echo $module['is_enabled'] ? 'enabled' : 'disabled'; ?>">
                                <?php echo $module['is_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="module-actions">
                        <button class="btn btn-sm" onclick="showModuleSettings('<?php echo $module['module_key']; ?>')">
                            <i class="fas fa-cog"></i> Configure
                        </button>
                        <button class="btn btn-sm btn-ghost" onclick="showModuleAudit('<?php echo $module['module_key']; ?>')">
                            <i class="fas fa-history"></i> Audit Log
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Module Settings Panel -->
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

<style>
    .module-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .module-card {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 20px;
        background: white;
        transition: all 0.3s ease;
    }
    
    .module-card:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        transform: translateY(-2px);
    }
    
    .module-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .module-info h4 {
        margin: 0 0 8px 0;
        color: #1f2937;
        font-size: 18px;
        font-weight: 600;
    }
    
    .module-info p {
        margin: 0;
        color: #6b7280;
        font-size: 14px;
        line-height: 1.5;
    }
    
    .module-toggle {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
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
        gap: 10px;
        margin-top: 15px;
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
</style>

<script>
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

function showModuleSettings(moduleKey) {
    // Load module settings via AJAX
    fetch(`module_configuration.php?action=get_settings&module=${moduleKey}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('moduleSettingsContent').innerHTML = html;
            document.getElementById('settingsModuleTitle').textContent = `${moduleKey} Settings`;
            document.getElementById('moduleSettingsPanel').style.display = 'block';
            document.getElementById('moduleAuditPanel').style.display = 'none';
            
            // Scroll to settings panel
            document.getElementById('moduleSettingsPanel').scrollIntoView({ behavior: 'smooth' });
        });
}

function hideModuleSettings() {
    document.getElementById('moduleSettingsPanel').style.display = 'none';
}

function showModuleAudit(moduleKey) {
    // Load module audit log via AJAX
    fetch(`module_configuration.php?action=get_audit&module=${moduleKey}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('moduleAuditContent').innerHTML = html;
            document.getElementById('auditModuleTitle').textContent = `${moduleKey} Audit Log`;
            document.getElementById('moduleAuditPanel').style.display = 'block';
            document.getElementById('moduleSettingsPanel').style.display = 'none';
            
            // Scroll to audit panel
            document.getElementById('moduleAuditPanel').scrollIntoView({ behavior: 'smooth' });
        });
}

function hideModuleAudit() {
    document.getElementById('moduleAuditPanel').style.display = 'none';
}

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

// Handle AJAX requests for settings and audit
<?php
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_settings':
            $moduleKey = $_GET['module'] ?? '';
            if ($moduleKey) {
                $settings = ModuleConfig::getModuleSettingsByCategory($moduleKey);
                echo '<div class="settings-container">';
                foreach ($settings as $category => $categorySettings) {
                    echo '<div class="settings-group">';
                    echo '<h4>' . ucfirst($category) . '</h4>';
                    foreach ($categorySettings as $setting) {
                        echo '<div class="setting-item">';
                        echo '<div class="setting-label">' . htmlspecialchars($setting['config_key']) . '</div>';
                        echo '<div class="setting-input">';
                        
                        // Render input based on type
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
            break;
            
        case 'get_audit':
            $moduleKey = $_GET['module'] ?? '';
            if ($moduleKey) {
                $auditTrail = ModuleConfig::getModuleAuditTrail($moduleKey, 50);
                echo '<table class="audit-table">';
                echo '<thead><tr>';
                echo '<th>Timestamp</th>';
                echo '<th>Action</th>';
                echo '<th>Setting</th>';
                echo '<th>Old Value</th>';
                echo '<th>New Value</th>';
                echo '<th>User</th>';
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
            break;
    }
    exit;
}
?>
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
?>
