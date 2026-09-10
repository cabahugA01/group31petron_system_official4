<?php
$page_id = 'module_config';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/module_config.php';
require_login();

// Only allow SuperAdmin, Developer, Admin, and Manager access to Module Configuration page (Staff are limited)
$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');

if (!in_array($my_role, ['superadmin', 'developer', 'admin', 'manager'], true)) {
    header("Location: dashboard.php");
    exit;
}

$msg = $_SESSION['flash_error'] ?? '';
$success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

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
                    // Update main status if sent in POST or inside config_data JSON
                    if (isset($_POST['is_enabled'])) {
                        $enabled = $_POST['is_enabled'] === '1';
                        ModuleConfig::setModuleStatus($moduleKey, $enabled, $me['id'], $my_role);
                    } elseif (isset($configArray['module_status'])) {
                        $enabled = ($configArray['module_status'] === 'enabled' || $configArray['module_status'] === '1' || $configArray['module_status'] === true);
                        ModuleConfig::setModuleStatus($moduleKey, $enabled, $me['id'], $my_role);
                    }

                    // Update user access if sent
                    if (isset($_POST['user_access']) || isset($configArray['user_access'])) {
                        $rawRoles = $_POST['user_access'] ?? $configArray['user_access'] ?? [];
                        $rolesArr = is_array($rawRoles) ? $rawRoles : explode(',', $rawRoles);
                        $userAccessStr = implode(', ', array_map('trim', array_filter($rolesArr)));
                        if (!empty($userAccessStr)) {
                            try {
                                $pdo->prepare("UPDATE module_settings SET user_access = ? WHERE module_key = ?")->execute([$userAccessStr, $moduleKey]);
                            } catch (Exception $e) {
                                error_log("Failed to update user_access in module_settings: " . $e->getMessage());
                            }
                        }
                    }
                    
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
                    
                    // If global config, also sync back to individual module_config entries
                    if ($stationId === 'all' || !$stationId) {
                        foreach ($configArray as $cKey => $cVal) {
                            $strVal = is_bool($cVal) ? ($cVal ? '1' : '0') : (string)$cVal;
                            $cType  = is_bool($cVal) ? 'boolean' : (is_numeric($cVal) ? 'integer' : 'string');
                            try {
                                $insStmt = $pdo->prepare("
                                    INSERT INTO module_config (module_key, config_key, config_value, config_type, config_category, description)
                                    VALUES (?, ?, ?, ?, 'General', ?)
                                    ON DUPLICATE KEY UPDATE
                                        config_value = VALUES(config_value),
                                        updated_at   = NOW()
                                ");
                                $insStmt->execute([
                                    $moduleKey,
                                    $cKey,
                                    $strVal,
                                    $cType,
                                    ucwords(str_replace('_', ' ', $cKey))
                                ]);
                            } catch (Exception $e) {
                                ModuleConfig::updateModuleSetting($moduleKey, $cKey, $strVal, $me['id'], $my_role);
                            }
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
                
            case 'reset_module_config':
                $moduleKey = $_POST['module_key'] ?? '';
                $stationId = $_POST['station_id'] ?? 'all';

                if (!$moduleKey) {
                    $msg = "No module key provided for reset.";
                    break;
                }

                try {
                    // Remove station-specific saved config
                    $pdo->prepare(
                        "DELETE FROM module_station_config WHERE module_key = ? AND station_id = ?"
                    )->execute([$moduleKey, $stationId]);

                    // Also wipe global config entries from module_config table for this module
                    $pdo->prepare(
                        "DELETE FROM module_config WHERE module_key = ?"
                    )->execute([$moduleKey]);

                    $stationLabel = ($stationId === 'all' || !$stationId) ? 'All Stations (Global)' : 'Station #' . $stationId;
                    $success = "Configuration for '<strong>{$moduleKey}</strong>' has been restored to defaults for <strong>{$stationLabel}</strong>.";
                    log_activity($pdo, $me['id'], 'Module Configuration', "Reset config to default for {$moduleKey} @ {$stationLabel}");
                } catch (Exception $e) {
                    $msg = "Error resetting configuration: " . $e->getMessage();
                }
                break;

            case 'delete_module':
                $moduleKey = $_POST['module_key'] ?? '';
                // Define core developer modules that CANNOT be deleted
                $coreModules = ['dashboard','transactions','fuel_management','inventory','customers','product_pricing','calendar','reports','notifications','backup_restore','audit_trail','api_integration'];
                
                if (in_array($moduleKey, $coreModules)) {
                    $msg = "Core system module cannot be deleted.";
                } elseif (empty($moduleKey)) {
                    $msg = "No module specified.";
                } else {
                    try {
                        // Delete configs and settings
                        $pdo->prepare("DELETE FROM module_config WHERE module_key = ?")->execute([$moduleKey]);
                        $pdo->prepare("DELETE FROM module_settings WHERE module_key = ?")->execute([$moduleKey]);
                        $success = "Module &lsquo;{$moduleKey}&rsquo; has been removed from the system.";
                        log_activity($pdo, $me['id'], 'Module Configuration', "Deleted module: {$moduleKey}");
                    } catch (Exception $e) {
                        $msg = "Error deleting module: " . $e->getMessage();
                    }
                }
                break;
        }
    }

    if (!empty($success)) {
        $_SESSION['flash_success'] = $success;
    }
    if (!empty($msg)) {
        $_SESSION['flash_error'] = $msg;
    }

    header("Location: module_configuration.php");
    exit;
}

// Get all modules and their settings
$modules = ModuleConfig::getModules();
$enabledModules = ModuleConfig::getEnabledModules();

// Build config map for JavaScript populate
$moduleConfigMap = [];
foreach ($modules as $m) {
    $mSettings = ModuleConfig::getModuleSettings($m['module_key']);
    $moduleConfigMap[$m['module_key']] = [];
    foreach ($mSettings as $s) {
        $moduleConfigMap[$m['module_key']][$s['config_key']] = $s['config_value'];
    }
}

// Build station config map for JavaScript populate
$stationConfigsMap = [];
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
    $stmt = $pdo->query("SELECT module_key, station_id, config_data FROM module_station_config");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mKey = $row['module_key'];
        $sId = $row['station_id'];
        $cData = json_decode($row['config_data'], true) ?: [];
        if (!isset($stationConfigsMap[$mKey])) {
            $stationConfigsMap[$mKey] = [];
        }
        $stationConfigsMap[$mKey][$sId] = $cData;
    }
} catch (Exception $e) {
    error_log("Failed to load station configs: " . $e->getMessage());
}


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

// ── AJAX JSON POLLING ENDPOINT FOR MODULE CONFIGURATION ──────────────────────
if (isset($_GET['ajax_mc']) && $_GET['ajax_mc'] == '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'success'         => true,
        'modules_count'   => count($modules ?? []),
        'enabled_count'   => count($enabledModules ?? []),
        'stations_count'  => count($stations ?? [])
    ]);
    exit;
}

include __DIR__ . '/../partials/header.php';

// Debug output
if (empty($stations)) {
    error_log("WARNING: No stations found in module_configuration.php");
} else {
    error_log("SUCCESS: " . count($stations) . " stations loaded in module_configuration.php");
}
?>

<div class="mc-page-wrap" style="padding: 0 !important; width: 100% !important; max-width: 100% !important; overflow-x: hidden !important; box-sizing: border-box !important;">
<div class="page-head">
    <div>
        <h1 class="h1"><i class="fas fa-cogs" style="margin-right: 8px;"></i> Station-Dependent Module Control</h1>
    </div>
</div>

<?php if($success): ?>
<div id="toastNotification" style="
    position: fixed;
    top: 72px;
    right: 20px;
    z-index: 999999;
    width: 380px;
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.22), 0 2px 8px rgba(0,0,0,0.10);
    animation: toastSlideIn 0.35s cubic-bezier(0.16,1,0.3,1);
">
    <div style="display:flex; align-items:flex-start; gap:12px; padding:16px;">
        <div style="flex-shrink:0; background:#dcfce7; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
            <i class="fas fa-check-circle" style="color:#16a34a; font-size:18px;"></i>
        </div>
        <div style="flex:1; min-width:0;">
            <div style="font-size:13px; font-weight:700; color:#15803d; margin-bottom:3px;">Configuration Saved</div>
            <div style="font-size:12px; color:#374151; line-height:1.55; font-weight:400;"><?php echo strip_tags($success, ''); ?></div>
        </div>
    </div>
</div>
<style>
@keyframes toastSlideIn {
    from { opacity:0; transform:translateX(110%); }
    to   { opacity:1; transform:translateX(0); }
}
@keyframes toastProgress {
    from { transform:scaleX(1); }
    to   { transform:scaleX(0); }
}
</style>
<script>
function dismissToast(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
    el.style.opacity = '0';
    el.style.transform = 'translateX(110%)';
    setTimeout(function(){ el.remove(); }, 320);
}
setTimeout(function(){ dismissToast('toastNotification'); }, 5000);
</script>
<?php endif; ?>

<?php if($msg): ?>
<div id="toastError" style="
    position: fixed;
    top: 72px;
    right: 20px;
    z-index: 999999;
    width: 380px;
    background: #ffffff;
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.22), 0 2px 8px rgba(0,0,0,0.10);
    animation: toastSlideIn 0.35s cubic-bezier(0.16,1,0.3,1);
">
    <div style="display:flex; align-items:flex-start; gap:12px; padding:16px;">
        <div style="flex-shrink:0; background:#fee2e2; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
            <i class="fas fa-exclamation-circle" style="color:#dc2626; font-size:18px;"></i>
        </div>
        <div style="flex:1; min-width:0;">
            <div style="font-size:13px; font-weight:700; color:#dc2626; margin-bottom:3px;">Error</div>
            <div style="font-size:12px; color:#374151; line-height:1.55; font-weight:400;"><?php echo strip_tags($msg, ''); ?></div>
        </div>
    </div>
</div>
<script>
setTimeout(function(){ dismissToast('toastError'); }, 7000);
</script>
<?php endif; ?>

<!-- Station-Dependent Configuration Section -->
<div class="card" id="tb_station_combo_card" style="margin-bottom: 25px; overflow: visible !important;">
    <div class="card-header" style="background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
        <h3 style="margin: 0; font-size: 19px; font-weight: 700; color: var(--petron-blue, #00264D); display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-map-marker-alt" style="color: #3b82f6; font-size: 20px;"></i> Station-Dependent Configuration
        </h3>
    </div>
    <div class="card-body" style="padding: 22px; overflow: visible !important;">
        <div style="display: flex; align-items: center; gap: 15px; position: relative; margin-bottom: 15px; flex-wrap: wrap;">
            <label for="tb_station_display" style="font-weight: 700; color: #374151; font-size: 15px; text-transform: uppercase; letter-spacing: 0.5px;">
                Search Station:
            </label>
            <!-- Searchable station filter (SEARCHABLE SELECT) -->
            <div class="am-combo am-combo-toolbar" id="tb_station_combo" style="width:450px; max-width: 100%; position: relative; z-index: 100;">
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
        <div id="stationSelectionBanner" style="display: none; padding: 14px 18px; background: #dbeafe; border-left: 5px solid #3b82f6; border-radius: 8px; margin-top: 15px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-info-circle" style="color: #3b82f6; font-size: 20px;"></i>
                <div style="flex: 1;">
                    <div style="font-weight: 700; color: #1e40af; font-size: 15px;">Selected Station:</div>
                    <div id="selectedStationName" style="color: #1f2937; font-size: 15px; font-weight: 600; margin-top: 2px;"></div>
                </div>
            </div>
        </div>
        <div id="noStationWarning" style="display: none !important;"></div>
    </div>
</div>

<!-- Global Module Settings Section -->
<?php
$coreModules = ['dashboard','transactions','fuel_management','inventory','customers','product_pricing','calendar','reports','notifications','backup_restore','audit_trail','api_integration'];
?>
<div class="card" style="margin-bottom: 25px;">
    <div class="card-header" style="background: #f8f9fa; border-bottom: 2px solid #e9ecef; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0; font-size: 19px; font-weight: 700; color: var(--petron-blue, #00264D); display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-globe" style="color: #10b981; font-size: 20px;"></i> Global Module Settings
        </h3>
    </div>
    <div class="card-body" style="padding: 20px 20px 14px;">
        <div style="display: flex; gap: 14px; margin-bottom: 10px; flex-wrap: wrap;">
            <input type="text" 
                   id="moduleSearch" 
                   aria-label="Search modules by name or description"
                   placeholder="Search modules by name or description..." 
                   style="flex: 1; min-width: 240px; padding: 11px 16px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 15px;"
                   oninput="filterModules()">
            <select id="statusFilter" 
                    aria-label="Filter modules by status"
                    style="padding: 11px 16px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 15px; min-width: 150px;"
                    onchange="filterModules()">
                <option value="">All Status</option>
                <option value="enabled">Enabled</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>
    </div>
    <div class="card-body" style="padding: 0; overflow-x: hidden !important; width: 100% !important; max-width: 100% !important; box-sizing: border-box !important;">
        <table class="module-table" style="table-layout: fixed !important; width: 100% !important; max-width: 100% !important; min-width: 100% !important; border-collapse: collapse !important; margin: 0 !important;">
            <colgroup>
                <col style="width: 50%;">
                <col style="width: 14%;">
                <col style="width: 16%;">
                <col style="width: 20%;">
            </colgroup>
            <thead>
                <tr>
                    <th style="width: 50%;">Module</th>
                    <th style="width: 14%; text-align: center;">Version</th>
                    <th style="width: 16%; text-align: center;">Status</th>
                    <th style="width: 20%; text-align: center;">Action</th>
                </tr>
            </thead>
            <tbody id="moduleTableBody">
                <?php foreach ($modules as $module): ?>
                <?php $isCore = in_array($module['module_key'], $coreModules); ?>
                <tr data-module="<?php echo $module['module_key']; ?>" 
                    data-status="<?php echo $module['is_enabled'] ? 'enabled' : 'disabled'; ?>"
                    style="<?php echo !$isCore ? 'background: #f0fff4;' : ''; ?>">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <?php if (!$isCore): ?>
                            <span title="Custom Module" style="background:#10b981;color:white;font-size:11px;font-weight:800;padding:3px 8px;border-radius:4px;letter-spacing:0.5px;text-transform:uppercase;flex-shrink:0;">CUSTOM</span>
                            <?php endif; ?>
                            <div style="min-width: 0; overflow: hidden;">
                                <h4 style="margin: 0; font-size: 17px; font-weight: 700; color: #111827;">
                                    <?php echo htmlspecialchars($module['module_name']); ?>
                                </h4>
                                <p style="margin: 4px 0 0 0; color: #4b5563; font-size: 14px; line-height: 1.45;">
                                    <?php echo htmlspecialchars($module['module_description'] ?: 'No description provided.'); ?>
                                </p>
                            </div>
                        </div>
                    </td>
                    <td style="text-align: center; font-weight: 700; color: #1f2937; font-family: monospace; font-size: 14px;">
                        <?php echo htmlspecialchars($module['version'] ?? 'v1.0'); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php if ($module['is_enabled']): ?>
                            <span style="display:inline-block;background:#dcfce7;color:#15803d;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:700;">Enabled</span>
                        <?php else: ?>
                            <span style="display:inline-block;background:#fee2e2;color:#dc2626;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:700;">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center; white-space: nowrap;">
                        <div style="display:inline-flex;gap:8px;justify-content:center;align-items:center;">
                            <button class="btn-action btn-configure" 
                                    onclick="showModuleSettings('<?php echo $module['module_key']; ?>')"
                                    style="background:#002F70;color:white;padding:9px 18px;font-size:14px;font-weight:700;border-radius:7px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;box-shadow:0 1px 3px rgba(0,0,0,0.12);"
                                    title="Configure Module">
                                <i class="fas fa-cog"></i> Configure
                            </button>
                            <?php if (!$isCore): ?>
                            <button onclick="deleteModule('<?php echo $module['module_key']; ?>','<?php echo htmlspecialchars(addslashes($module['module_name'])); ?>')"
                                    style="background:#ef4444;color:white;border:none;padding:9px 13px;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;"
                                    title="Delete Custom Module">
                                <i class="fas fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
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
<div id="moduleConfigModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.55); z-index: 9999; align-items: center; justify-content: center; padding: 40px 20px;">
    <div class="modal-content modal-large" style="width: 100%; max-width: 640px; display: flex; flex-direction: column; max-height: 85vh; margin: auto; border-radius: 14px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4); background: #ffffff;">
        <!-- Header -->
        <div class="modal-header" style="background: #ffffff; padding: 20px 26px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #e5e7eb; border-top-left-radius: 14px; border-top-right-radius: 14px; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 14px;">
                <div style="background: #eff6ff; width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1.5px solid #bfdbfe;">
                    <i class="fas fa-cog" style="color: #0057b8 !important; font-size: 20px;"></i>
                </div>
                <div>
                    <h3 style="margin: 0; color: #00264D !important; font-size: 19px; font-weight: 700; letter-spacing: 0.3px;"><span id="configModuleTitle" style="color: #00264D !important;">Module Configuration</span></h3>
                </div>
            </div>
            <!-- X button removed for clean layout -->
        </div>
        <form id="moduleConfigForm" onsubmit="saveModuleConfig(event)" style="display: flex; flex-direction: column; flex: 1; overflow: hidden; margin: 0;">
            <div class="modal-body" style="padding: 26px 28px 32px 28px; flex: 1; overflow-y: auto; background: #ffffff;">
                <div id="moduleConfigContent">
                    <!-- Dynamic configuration content loaded here -->
                </div>
            </div>
            <div class="modal-footer" style="background: #ffffff; border-top: 2px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; padding: 18px 26px; flex-shrink: 0;">
                <button type="button" class="btn btn-secondary" style="background: #ef4444 !important; color: white !important; border: none; font-weight: 700; font-size: 14px; padding: 10px 20px; border-radius: 7px; cursor: pointer;" onclick="resetModuleConfig()">
                    Restore Default
                </button>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <button type="button" style="padding: 10px 22px; border: 1.5px solid #d1d5db !important; border-radius: 7px; background: #ffffff !important; cursor: pointer; font-size: 14.5px; font-weight: 700; color: #374151 !important;" onclick="closeModuleConfigModal()">
                        Cancel
                    </button>
                    <button type="submit" style="padding: 10px 26px; border: none !important; border-radius: 7px; background: #16a34a !important; color: #ffffff !important; font-size: 14.5px; font-weight: 700; cursor: pointer; letter-spacing: 0.2px; box-shadow: 0 2px 4px rgba(22,163,74,0.3);">
                        Save Configuration
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Module Audit Panel -->
<div id="moduleAuditPanel" class="card" style="display: none;">
    <div class="card-header">
        <h3 style="font-size: 19px; font-weight: 700; color: #00264D;"><span id="auditModuleTitle">Module Audit Log</span></h3>
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

<!-- Disable Module Confirmation Modal -->
<div id="disableConfirmModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.6); z-index: 10000; align-items: center; justify-content: center; padding: 40px 20px;">
    <div class="modal-content" style="max-width: 520px; width: 100%; border-radius: 14px; overflow: hidden; background: #ffffff; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5);">
        <div class="modal-header" style="background: #dc2626; padding: 18px 24px; color: white; display: flex; align-items: center; justify-content: space-between; border-top-left-radius: 14px; border-top-right-radius: 14px;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-exclamation-triangle" style="font-size: 22px; color: white;"></i>
                <h3 style="margin: 0; color: white !important; font-size: 19px; font-weight: 700;">Disable Module Confirmation</h3>
            </div>
        </div>
        <div class="modal-body" style="padding: 26px; background: #ffffff;">
            <div style="font-size: 17px; font-weight: 700; color: #1f2937; margin-bottom: 10px;">
                Disable <span id="disableModuleName"></span> Module?
            </div>
            <p style="font-size: 15px; color: #4b5563; margin-bottom: 16px; line-height: 1.5;">
                You are about to disable the <strong id="disableModuleNameText"></strong> module.
            </p>
            
            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 800; color: #991b1b; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.5px;">Effects:</div>
                <ul style="margin: 0; padding-left: 20px; font-size: 14.5px; color: #7f1d1d; line-height: 1.6;">
                    <li><span id="disableModuleNameMenu"></span> menu will be hidden.</li>
                    <li>Users will no longer access the <span id="disableModuleNameAccess"></span>.</li>
                    <li>Existing data will <strong>NOT</strong> be deleted.</li>
                    <li>This module can be enabled again anytime.</li>
                </ul>
            </div>
            
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 15px; font-weight: 700; color: #374151; margin-bottom: 8px;">
                    Type <strong style="color: #dc2626;">CONFIRM</strong> to continue:
                </label>
                <input type="text" id="confirmDisableInput" class="form-input" placeholder="CONFIRM" autocomplete="off"
                       style="width: 100%; padding: 11px 14px; border: 1.5px solid #d1d5db; border-radius: 8px; font-size: 16px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;"
                       oninput="checkConfirmDisableInput(this.value)">
            </div>
        </div>
        <div class="modal-footer" style="background: #ffffff; border-top: 2px solid #e5e7eb; padding: 18px 24px; display: flex; justify-content: flex-end; gap: 14px; align-items: center;">
            <button type="button" onclick="closeDisableConfirmModal()" style="padding: 11px 24px; border: 1.5px solid #94a3b8 !important; border-radius: 8px; background: #f8fafc !important; cursor: pointer; font-size: 14.5px; font-weight: 700; color: #0f172a !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: inline-flex; align-items: center; justify-content: center;">
                Cancel
            </button>
            <button type="button" id="btnSubmitDisable" disabled onclick="executeDisableModule()" style="padding: 11px 26px; border: none !important; border-radius: 8px; background: #dc2626 !important; color: #ffffff !important; font-size: 14.5px; font-weight: 700; cursor: not-allowed; opacity: 0.55; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(220,38,38,0.3);">
                Disable Module
            </button>
        </div>
    </div>
</div>

<style>
    /* Zero Horizontal Scrolling & Elder Friendly Typography */
    html, body {
        overflow-x: hidden !important;
        max-width: 100vw !important;
        box-sizing: border-box !important;
    }

    *, *:before, *:after {
        box-sizing: border-box !important;
    }

    .mc-page-wrap {
        width: 100% !important;
        max-width: 100% !important;
        overflow-x: hidden !important;
        box-sizing: border-box !important;
    }

    /* Page Layout */
    body {
        background: #f3f4f6;
    }
    
    .page-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: center;
        margin-top: 0 !important;
        margin-bottom: 25px !important;
        padding: 0 !important;
        border: none !important;
        width: 100%;
    }
    
    .page-head h1, .page-head .h1 {
        margin: 0 !important;
        color: #002f70 !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        line-height: 1.2 !important;
    }
    
    .page-head .sub {
        font-size: 15px !important;
        color: #555;
        margin-top: 4px;
    }
    
    .card-header h3 {
        margin: 0 !important;
        font-size: 19px !important;
        font-weight: 700 !important;
        color: var(--petron-blue, #00264D) !important;
        display: flex !important;
        align-items: center !important;
        gap: 8px !important;
    }
    
    /* Station Search Combo Styles - Elder Friendly (15px font) */
    .am-combo-toolbar .am-combo-input {
        padding-top: 10px !important;
        padding-bottom: 10px !important;
        font-size: 15px !important;
        height: 44px !important;
    }
    .am-combo { position: relative; }
    .am-combo-input {
        width: 100% !important;
        padding: 11px 65px 11px 14px !important;
        border: 1.5px solid #d1d5db !important;
        border-radius: 8px !important;
        font-size: 15px !important;
        outline: none !important;
        transition: border-color .2s, box-shadow .2s !important;
        background: #fff !important;
        box-sizing: border-box !important;
        cursor: text !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        overflow: hidden !important;
    }
    .am-combo-input:focus {
        border-color: #00264D !important;
        box-shadow: 0 0 0 3px rgba(0,38,77,.1) !important;
    }
    .am-combo-input.has-value {
        border-color: #00264D !important;
    }
    .am-combo-arrow {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        font-size: 14px;
        pointer-events: none;
        transition: transform .2s;
        z-index: 1;
    }
    .am-combo.open .am-combo-arrow {
        transform: translateY(-50%) rotate(180deg);
    }
    .am-combo-clear {
        position: absolute;
        right: 36px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 15px;
        cursor: pointer;
        display: none;
        background: none;
        border: none;
        padding: 4px;
        line-height: 1;
        z-index: 2;
    }
    .am-combo-clear:hover {
        color: #dc2626;
    }
    .am-combo-dropdown {
        display: none;
        position: fixed;
        background: #fff;
        border: 1.5px solid #d1d5db;
        border-radius: 10px;
        box-shadow: 0 10px 30px rgba(0,0,0,.15);
        z-index: 99999;
        max-height: 260px;
        overflow: hidden;
        flex-direction: column;
    }
    .am-combo.open .am-combo-dropdown { display: flex; }
    .am-combo-search {
        padding: 10px 14px;
        border-bottom: 1px solid #f0f0f0;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    .am-combo-search i { color: #94a3b8; font-size: 15px; }
    .am-combo-search input { border: none; outline: none; font-size: 15px; flex: 1; background: transparent; }
    .am-combo-list { overflow-y: auto; flex: 1; }
    .am-combo-option {
        padding: 12px 16px !important;
        font-size: 15px !important;
        cursor: pointer;
        transition: background .12s;
        display: flex;
        align-items: center;
        gap: 10px;
        color: #1f2937;
    }
    .am-combo-option:hover, .am-combo-option.focused {
        background: #f0f5ff;
        color: #00264D;
        font-weight: 600;
    }
    .am-combo-option.selected {
        background: rgba(0,38,77,.08);
        font-weight: 700;
        color: #00264D;
    }
    .am-combo-option .opt-icon { color: #64748b; font-size: 13px; flex-shrink: 0; }
    .am-combo-option.selected .opt-icon { color: #00264D; }
    .am-combo-empty { padding: 18px 14px; font-size: 15px; color: #888; text-align: center; }
    .am-combo-hidden { display: none !important; }

    /* Module Table Styles - 100% Fit & No Horizontal Scroll */
    .module-table {
        table-layout: fixed !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 100% !important;
        border-collapse: collapse !important;
        background: white;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    
    .module-table thead th {
        background: #002F70 !important;
        color: #ffffff !important;
        padding: 14px 16px !important;
        text-align: left;
        font-weight: 700 !important;
        font-size: 14px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        border-bottom: none !important;
        box-sizing: border-box !important;
        white-space: nowrap !important;
    }
    
    .module-table tbody tr {
        border-bottom: 1px solid #e5e7eb !important;
        transition: background-color .15s ease;
    }
    
    .module-table tbody tr:hover {
        background-color: #f8fafc !important;
    }
    
    .module-table tbody tr:last-child {
        border-bottom: none !important;
    }
    
    .module-table tbody td {
        padding: 16px 14px !important;
        vertical-align: middle !important;
        box-sizing: border-box !important;
    }
    
    /* Status Badge - High Contrast & Large Text */
    .status-badge {
        display: inline-block !important;
        padding: 6px 14px !important;
        border-radius: 6px !important;
        font-size: 13px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
    }
    
    .status-badge.status-enabled,
    .status-badge.enabled {
        background-color: #dcfce7 !important;
        color: #15803d !important;
        border: 1px solid #bbf7d0 !important;
    }
    
    .status-badge.status-disabled,
    .status-badge.disabled {
        background-color: #fee2e2 !important;
        color: #dc2626 !important;
        border: 1px solid #fecaca !important;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 52px;
        height: 28px;
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
        border-radius: 28px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 22px;
        width: 22px;
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
    
    /* Action Buttons - Fully Visible & Elder Friendly */
    .btn-action {
        padding: 9px 18px !important;
        border-radius: 7px !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        cursor: pointer !important;
        border: none !important;
        transition: all .2s ease !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 7px !important;
        white-space: nowrap !important;
        text-decoration: none !important;
    }
    
    .btn-configure {
        background: #002F70 !important;
        color: #ffffff !important;
        border: 1px solid #002F70 !important;
        box-shadow: 0 2px 5px rgba(0,47,112,0.2) !important;
    }
    
    .btn-configure:hover {
        background: #001f4d !important;
        border-color: #001f4d !important;
        color: #ffffff !important;
        box-shadow: 0 4px 10px rgba(0,47,112,0.3) !important;
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
        color: var(--petron-blue, #00264D);
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
        padding: 18px 24px;
        border-top: 2px solid #e5e7eb;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        align-items: center;
        background: #ffffff;
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

    /* Modal & Form Elder-Friendly Overrides */
    .modal-content.modal-large {
        max-width: 640px !important;
        width: 100% !important;
        border-radius: 14px !important;
    }

    #moduleConfigModal label {
        font-size: 14.5px !important;
        color: #374151 !important;
    }

    #moduleConfigModal input[type="text"],
    #moduleConfigModal input[type="number"],
    #moduleConfigModal select {
        font-size: 14.5px !important;
        padding: 9px 13px !important;
        border: 1.5px solid #cbd5e1 !important;
        border-radius: 8px !important;
        box-sizing: border-box !important;
    }

    #moduleConfigModal input[type="checkbox"] {
        width: 18px !important;
        height: 18px !important;
        cursor: pointer !important;
    }

    #moduleConfigModal input[type="radio"] {
        width: 20px !important;
        height: 20px !important;
        cursor: pointer !important;
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
// ================================================================
// STATION DATA - embedded as JSON once at module scope
// Filtered in JS, max 50 results rendered per query
// ================================================================
const STATION_DATA = <?php
    echo json_encode(
        array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $stations),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
    );
?>;

const activeConfigs = <?php echo json_encode($moduleConfigMap, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;
const stationConfigs = <?php echo json_encode($stationConfigsMap, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE); ?>;


// -- Virtual Station Combobox ----------------------------------
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

        // "All Stations" row - always show when no query
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
        if (banner) banner.style.display = 'block';
        if (warning) warning.style.display = 'none';
        if (stationNameDiv) stationNameDiv.textContent = stationDisplay;
    } else {
        // Show warning to select station
        if (banner) banner.style.display = 'none';
        if (warning) warning.style.display = 'none';
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

function populateModalInputs(moduleKey, stationId) {
    const modal = document.getElementById('moduleConfigModal');
    if (!modal) return;
    const sId = (stationId && stationId !== 'all') ? String(stationId) : 'all';
    
    // Retrieve configs from stationConfigs or activeConfigs
    let configSource = {};
    if (stationConfigs[moduleKey] && stationConfigs[moduleKey][sId]) {
        configSource = stationConfigs[moduleKey][sId];
    } else if (stationConfigs[moduleKey] && stationConfigs[moduleKey]['all']) {
        configSource = stationConfigs[moduleKey]['all'];
    } else if (activeConfigs[moduleKey]) {
        configSource = activeConfigs[moduleKey];
    }
    
    // Aliases map for flexible key lookups
    const aliases = {
        'enable_pdf': 'enable_pdf_export',
        'enable_pdf_export': 'enable_pdf',
        'enable_excel': 'enable_excel_export',
        'enable_excel_export': 'enable_excel',
        'enable_csv': 'enable_csv_export',
        'enable_csv_export': 'enable_csv',
        'paper_size': 'default_paper_size',
        'default_paper_size': 'paper_size',
        'enable_low_stock_alerts': 'enable_low_stock_alert',
        'enable_low_stock_alert': 'enable_low_stock_alerts',
        'enable_expiration': 'enable_expiration_monitoring',
        'enable_expiration_monitoring': 'enable_expiration'
    };
    
    modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(input => {
        if (input.id === 'modalStationSelect' || input.name === 'modalStationSelect') return;
        const key = input.name;
        const altKey = aliases[key] || '';
        
        // Default value from data-default attribute
        let val = input.dataset.default;
        
        // If configSource has the key or alias, use it
        if (configSource && configSource.hasOwnProperty(key)) {
            val = configSource[key];
        } else if (altKey && configSource && configSource.hasOwnProperty(altKey)) {
            val = configSource[altKey];
        } else if (activeConfigs[moduleKey]) {
            if (activeConfigs[moduleKey].hasOwnProperty(key)) {
                val = activeConfigs[moduleKey][key];
            } else if (altKey && activeConfigs[moduleKey].hasOwnProperty(altKey)) {
                val = activeConfigs[moduleKey][altKey];
            }
        }
        
        if (input.type === 'checkbox') {
            const boolVal = (val === true || val === 'true' || val === 1 || val === '1');
            input.checked = boolVal;
        } else if (input.type === 'radio') {
            if (input.value === String(val)) {
                input.checked = true;
            }
        } else if (input.tagName === 'SELECT') {
            input.value = val;
            const options = input.querySelectorAll('option');
            options.forEach(opt => {
                if (opt.value === String(val)) {
                    opt.selected = true;
                }
            });
        } else {
            if (val !== undefined && val !== null) {
                input.value = val;
            }
        }
    });
}

function showModuleSettings(moduleKey) {
    console.log('Opening configuration modal for:', moduleKey);
    
    const stationInput = document.getElementById('tb_station_val');
    currentConfigStation = stationInput ? stationInput.value : '';
    currentConfigModule = moduleKey;
    
    // Format module title
    const moduleNameMap = {
        'dashboard': 'Dashboard',
        'transactions': 'Transactions',
        'fuel_management': 'Fuel Management',
        'inventory': 'Inventory',
        'customers': 'Customers',
        'product_pricing': 'Product & Pricing',
        'calendar': 'Calendar',
        'reports': 'Reports',
        'notifications': 'Notifications',
        'backup_restore': 'Backup & Restore',
        'audit_trail': 'Audit Trail',
        'api_integration': 'API Integration'
    };
    
    const moduleName = moduleNameMap[moduleKey] || moduleKey.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    const tr = document.querySelector(`tr[data-module="${moduleKey}"]`);
    const currentStatus = tr ? tr.dataset.status : 'enabled';
    const isCurrentlyEnabled = (currentStatus === 'enabled');
    
    const statusBadgeHtml = isCurrentlyEnabled 
        ? '<span style="background: #dcfce7; color: #15803d; font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 6px; display: inline-block;">Enabled</span>'
        : '<span style="background: #fee2e2; color: #dc2626; font-size: 13px; font-weight: 700; padding: 5px 12px; border-radius: 6px; display: inline-block;">Disabled</span>';
    
    const moduleStatusSection = `
        <div style="margin-bottom: 22px;">
            <div style="font-size: 15px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <span>Module Status Toggle</span>
                <span style="font-size: 13px; text-transform: none; color: #64748b; font-weight: 600;">Flexible Status Control</span>
            </div>
            <div style="display: flex; gap: 28px; background: #f8fafc; border: 1.5px solid #e2e8f0; padding: 14px 20px; border-radius: 10px; align-items: center;">
                <label for="opt_module_status_enabled" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 15px; font-weight: 700; color: #16a34a;">
                    <input type="radio" id="opt_module_status_enabled" name="module_status" value="enabled" ${isCurrentlyEnabled ? 'checked' : ''} style="width: 20px; height: 20px; cursor: pointer;">
                    <i class="fas fa-check-circle" style="color: #16a34a; font-size: 18px;"></i> Enabled
                </label>
                <label for="opt_module_status_disabled" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 15px; font-weight: 700; color: #dc2626;">
                    <input type="radio" id="opt_module_status_disabled" name="module_status" value="disabled" ${!isCurrentlyEnabled ? 'checked' : ''} style="width: 20px; height: 20px; cursor: pointer;">
                    <i class="fas fa-times-circle" style="color: #dc2626; font-size: 18px;"></i> Disabled
                </label>
            </div>
        </div>
    `;
    
    // Top Module Information Card (Module Name, Version, Status)
    const topInfoCard = `
        <div style="background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 22px;">
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; align-items: center;">
                <div>
                    <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Module Name</div>
                    <div style="font-size: 17px; font-weight: 700; color: #00264D; margin-top: 3px;">${moduleName}</div>
                </div>
                <div>
                    <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Version</div>
                    <div style="font-size: 15px; font-weight: 700; color: #334155; margin-top: 3px; font-family: monospace;">v1.0.0</div>
                </div>
                <div>
                    <div style="font-size: 12.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Status</div>
                    <div style="margin-top: 3px;">${statusBadgeHtml}</div>
                </div>
            </div>
        </div>
    `;

    const moduleConfigs = {
        'dashboard': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Dashboard Settings
                </div>
                <div style="margin-bottom: 14px;">
                    <label for="inp_default_landing_page" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Default Landing Page</label>
                    <input type="text" id="inp_default_landing_page" name="default_landing_page" class="config-input" value="dashboard" data-default="dashboard" style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                </div>
                <div style="margin-bottom: 14px;">
                    <label for="inp_dashboard_refresh_interval" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Refresh Interval (seconds)</label>
                    <input type="number" id="inp_dashboard_refresh_interval" name="dashboard_refresh_interval" class="config-input" value="15" data-default="15" style="width: 140px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                </div>
            </div>

            <div style="margin-bottom: 10px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Dashboard Components
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label for="chk_enable_kpi_cards" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_kpi_cards" name="enable_kpi_cards" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>KPI Cards</span>
                    </label>
                    <label for="chk_enable_quick_actions" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_quick_actions" name="enable_quick_actions" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Quick Actions</span>
                    </label>
                    <label for="chk_enable_calendar_widget" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_calendar_widget" name="enable_calendar_widget" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Calendar Widget</span>
                    </label>
                    <label for="chk_enable_notifications_widget" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_notifications_widget" name="enable_notifications_widget" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Notifications</span>
                    </label>
                    <label for="chk_enable_search_bar" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_search_bar" name="enable_search_bar" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Search Bar</span>
                    </label>
                </div>
            </div>
        `,
        'inventory': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Inventory Settings
                </div>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <label for="chk_enable_batch_tracking" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_batch_tracking" name="enable_batch_tracking" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Batch Tracking</span>
                    </label>
                    <label for="chk_enable_expiration" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_expiration" name="enable_expiration" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Expiration</span>
                    </label>
                    <label for="chk_enable_fifo" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_fifo" name="enable_fifo" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable FIFO</span>
                    </label>
                    <label for="chk_enable_low_stock_alerts" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_low_stock_alerts" name="enable_low_stock_alerts" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Low Stock Alerts</span>
                    </label>
                </div>
            </div>
        `,
        'reports': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Export Formats
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                    <label for="chk_enable_pdf" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_pdf" name="enable_pdf" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable PDF</span>
                    </label>
                    <label for="chk_enable_excel" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_excel" name="enable_excel" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Excel</span>
                    </label>
                    <label for="chk_enable_csv" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_csv" name="enable_csv" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable CSV</span>
                    </label>
                </div>
                <div>
                    <label for="sel_paper_size" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Paper Size</label>
                    <select id="sel_paper_size" name="paper_size" class="config-input" data-default="A4" style="width: 180px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                        <option value="A4" selected>A4</option>
                        <option value="Letter">Letter</option>
                        <option value="Legal">Legal</option>
                    </select>
                </div>
            </div>
        `,
        'backup_restore': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Backup Settings
                </div>
                <div style="margin-bottom: 14px;">
                    <label for="sel_backup_frequency" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Backup Frequency</label>
                    <select id="sel_backup_frequency" name="backup_frequency" class="config-input" data-default="Daily" style="width: 180px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                        <option value="Daily" selected>Daily</option>
                        <option value="Weekly">Weekly</option>
                        <option value="Monthly">Monthly</option>
                    </select>
                </div>
                <div style="margin-bottom: 14px;">
                    <label for="sel_retention_period" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Retention Period</label>
                    <select id="sel_retention_period" name="retention_period" class="config-input" data-default="30 days" style="width: 180px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                        <option value="30 days" selected>30 days</option>
                        <option value="60 days">60 days</option>
                        <option value="90 days">90 days</option>
                    </select>
                </div>
                <div>
                    <label for="sel_storage_location" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Storage Location</label>
                    <select id="sel_storage_location" name="storage_location" class="config-input" data-default="Local" style="width: 180px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                        <option value="Local" selected>Local Storage</option>
                        <option value="Cloud">Cloud Storage</option>
                    </select>
                </div>
            </div>
        `,
        'notifications': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Notification Settings
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px;">
                    <label for="chk_enable_notifications" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_notifications" name="enable_notifications" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Notifications</span>
                    </label>
                    <label for="chk_auto_hide_success_banner" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_auto_hide_success_banner" name="auto_hide_success_banner" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Auto Hide Success Banner</span>
                    </label>
                </div>
                <div>
                    <label for="inp_notification_duration" style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Duration (seconds)</label>
                    <input type="number" id="inp_notification_duration" name="notification_duration" class="config-input" value="5" data-default="5" style="width: 140px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 13px;">
                </div>
            </div>
        `,
        'transactions': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Transaction Controls
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label for="chk_auto_transaction_numbering" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_auto_transaction_numbering" name="auto_transaction_numbering" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Auto Transaction Numbering</span>
                    </label>
                    <label for="chk_enable_void_transaction" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_void_transaction" name="enable_void_transaction" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Void Transaction Control</span>
                    </label>
                </div>
            </div>
        `,
        'fuel_management': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Fuel Controls
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label for="chk_enable_fuel_reconciliation" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_fuel_reconciliation" name="enable_fuel_reconciliation" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Automated Fuel Reconciliation</span>
                    </label>
                    <label for="chk_enable_calibration" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_calibration" name="enable_calibration" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Calibration Computations</span>
                    </label>
                    <label for="chk_enable_meter_reading_validation" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_meter_reading_validation" name="enable_meter_reading_validation" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Meter Reading Validation</span>
                    </label>
                </div>
            </div>
        `,
        'customers': `
            <div style="margin-bottom: 20px;">
                <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                    Customer Controls
                </div>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label for="chk_enable_customer_registration" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_customer_registration" name="enable_customer_registration" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Customer Account Registration</span>
                    </label>
                    <label for="chk_enable_vehicle_history" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_vehicle_history" name="enable_vehicle_history" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Vehicle Service History Integration</span>
                    </label>
                    <label for="chk_enable_credit_account" style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                        <input type="checkbox" id="chk_enable_credit_account" name="enable_credit_account" checked data-default="true" style="width: 16px; height: 16px;">
                        <span>Enable Customer Credit Account Limits</span>
                    </label>
                </div>
            </div>
        `
    };

    const configContent = moduleConfigs[moduleKey] || `
        <div style="margin-bottom: 20px;">
            <div style="font-size: 13px; font-weight: 700; color: #00264D; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 6px; border-bottom: 2px solid #e2e8f0;">
                Module Options
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; color: #374151;">
                    <input type="checkbox" name="enable_${moduleKey}_feature" checked data-default="true" style="width: 16px; height: 16px;">
                    <span>Enable ${moduleName} Integration</span>
                </label>
            </div>
        </div>
    `;
    
    document.getElementById('moduleConfigContent').innerHTML = topInfoCard + moduleStatusSection + configContent;
    document.getElementById('configModuleTitle').textContent = moduleName + ' Configuration';
    
    storeDefaultValues();
    populateModalInputs(moduleKey, currentConfigStation);
    
    document.getElementById('moduleConfigModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function storeDefaultValues() {
    defaultConfigValues = {};
    const modal = document.getElementById('moduleConfigModal');
    
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
    if (!currentConfigModule) {
        return;
    }

    // --- 1. Reset UI fields immediately for instant feedback ---
    const modal = document.getElementById('moduleConfigModal');
    modal.querySelectorAll('input[data-default], select[data-default], textarea[data-default]').forEach(function(input) {
        if (input.type === 'checkbox') {
            input.checked = input.dataset.default === 'true';
        } else if (input.type === 'radio') {
            // reset status radio to enabled
            if (input.value === 'enabled') input.checked = true;
            if (input.value === 'disabled') input.checked = false;
        } else if (input.tagName === 'SELECT') {
            var defaultVal = input.dataset.default;
            input.value = defaultVal;
            Array.from(input.options).forEach(function(opt) {
                opt.selected = (opt.value === defaultVal);
            });
        } else {
            input.value = input.dataset.default;
        }
    });

    // --- 2. Submit reset to server (DELETE from DB) ---
    var stationInput = document.getElementById('tb_station_val');
    var selectedStationId = stationInput ? stationInput.value : '';

    var form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML =
        '<input type="hidden" name="action" value="reset_module_config">' +
        '<input type="hidden" name="module_key" value="' + currentConfigModule + '">' +
        '<input type="hidden" name="station_id" value="' + (selectedStationId || 'all') + '">';
    document.body.appendChild(form);

    closeModuleConfigModal();
    form.submit();
}

let pendingDisableSubmit = null;

function checkConfirmDisableInput(val) {
    const btn = document.getElementById('btnSubmitDisable');
    if (!btn) return;
    if (val.trim().toUpperCase() === 'CONFIRM') {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
        btn.style.setProperty('background', '#dc2626', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
    } else {
        btn.disabled = true;
        btn.style.cursor = 'not-allowed';
        btn.style.opacity = '0.55';
        btn.style.setProperty('background', '#dc2626', 'important');
        btn.style.setProperty('color', '#ffffff', 'important');
    }
}

function closeDisableConfirmModal() {
    document.getElementById('disableConfirmModal').style.display = 'none';
}

function executeDisableModule() {
    closeDisableConfirmModal();
    if (pendingDisableSubmit) {
        pendingDisableSubmit();
    }
}

function saveModuleConfig(event) {
    event.preventDefault();
    
    if (!currentConfigModule) {
        alert('No module selected');
        return;
    }
    
    const coreModules = ['dashboard','transactions','fuel_management','inventory','reports','audit_trail','backup_restore'];
    const isCore = coreModules.includes(currentConfigModule);
    
    const statusRadio = document.querySelector('input[name="module_status"]:checked');
    const isDisabling = statusRadio && statusRadio.value === 'disabled';
    
    const submitAction = function() {
        const stationInput = document.getElementById('tb_station_val');
        const selectedStationId = stationInput ? stationInput.value : '';
        
        const configSettings = {};
        const modal = document.getElementById('moduleConfigModal');
        
        modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(input => {
            if (input.name) {
                if (input.type === 'checkbox') {
                    configSettings[input.name] = input.checked;
                } else if (input.type === 'radio') {
                    if (input.checked) configSettings[input.name] = input.value;
                } else {
                    configSettings[input.name] = input.value;
                }
            }
        });
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="save_module_config">
            <input type="hidden" name="module_key" value="${currentConfigModule}">
            <input type="hidden" name="is_enabled" value="${isDisabling ? '0' : '1'}">
            <input type="hidden" name="station_id" value="${selectedStationId || 'all'}">
            <input type="hidden" name="config_data" value='${JSON.stringify(configSettings)}'>
        `;
        document.body.appendChild(form);
        form.submit();
    };

    if (isDisabling) {
        const disableModal = document.getElementById('disableConfirmModal');
        if (disableModal) {
            const moduleNameMap = {
                'dashboard': 'Dashboard',
                'transactions': 'Transactions',
                'fuel_management': 'Fuel Management',
                'inventory': 'Inventory',
                'customers': 'Customers',
                'product_pricing': 'Product & Pricing',
                'calendar': 'Calendar',
                'reports': 'Reports',
                'notifications': 'Notifications',
                'backup_restore': 'Backup & Restore',
                'audit_trail': 'Audit Trail',
                'api_integration': 'API Integration'
            };
            const moduleName = moduleNameMap[currentConfigModule] || currentConfigModule.replace(/_/g, ' ');
            
            const el1 = document.getElementById('disableModuleName');
            const el2 = document.getElementById('disableModuleNameText');
            const el3 = document.getElementById('disableModuleNameMenu');
            const el4 = document.getElementById('disableModuleNameAccess');
            if (el1) el1.textContent = moduleName;
            if (el2) el2.textContent = moduleName;
            if (el3) el3.textContent = moduleName;
            if (el4) el4.textContent = moduleName;

            const confirmInput = document.getElementById('confirmDisableInput');
            if (confirmInput) confirmInput.value = '';
            
            const btn = document.getElementById('btnSubmitDisable');
            if (btn) {
                btn.disabled = true;
                btn.style.cursor = 'not-allowed';
                btn.style.opacity = '0.5';
            }
            
            pendingDisableSubmit = submitAction;
            disableModal.style.display = 'flex';
            return false;
        }
    }

    submitAction();
    return false;
}

function viewCurrentJSONSettings() {
    const jsonView = document.getElementById('jsonSettingsView');
    if (!jsonView) return;
    
    if (jsonView.style.display === 'block') {
        jsonView.style.display = 'none';
        return;
    }
    
    const configSettings = {};
    const modal = document.getElementById('moduleConfigModal');
    
    modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(input => {
        if (input.id === 'modalStationSelect' || input.name === 'modalStationSelect') return;
        if (input.name) {
            if (input.type === 'checkbox') {
                configSettings[input.name] = input.checked;
            } else {
                configSettings[input.name] = input.value;
            }
        }
    });
    
    jsonView.textContent = JSON.stringify(configSettings, null, 4);
    jsonView.style.display = 'block';
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

// Delete a custom (non-core) module
function deleteModule(moduleKey, moduleName) {
    if (!confirm(`Are you sure you want to permanently delete the module:\n\n"${moduleName}" (${moduleKey})?\n\nThis action cannot be undone.`)) {
        return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_module">
        <input type="hidden" name="module_key" value="${moduleKey}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Close modals when clicking outside
document.addEventListener('click', function(event) {
    const configModal = document.getElementById('moduleConfigModal');
    if (event.target === configModal) {
        closeModuleConfigModal();
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const configModal = document.getElementById('moduleConfigModal');
        if (configModal && configModal.style.display === 'flex') {
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

</div>
<script>
// ── REAL-TIME 10-SECOND AUTO REFRESH POLLING ─────────────────────────
let lastModuleConfigCount = null;
function autoRefreshModuleConfiguration() {
    const openModal = Array.from(document.querySelectorAll('.modal, .modal-overlay, [id*="Modal"]')).some(m => {
        const style = window.getComputedStyle(m);
        return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
    });
    if (openModal) return;

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set('ajax_mc', '1');

    fetch(currentUrl.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data && data.success) {
                const combined = (data.modules_count || 0) + '-' + (data.enabled_count || 0);
                if (lastModuleConfigCount !== null && lastModuleConfigCount !== combined) {
                    window.location.reload();
                }
                lastModuleConfigCount = combined;
            }
        })
        .catch(() => {});
}
setInterval(autoRefreshModuleConfiguration, 10000);
</script>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
