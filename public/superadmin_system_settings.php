<?php
$page_id = 'system_settings';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

if (!in_array($role, ['superadmin', 'developer'])) {
    header('Location: super_admin_dashboard.php');
    exit;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT DEFAULT 0 COMMENT '0 for global, or specific station ID',
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            category VARCHAR(50) DEFAULT 'general',
            updated_by INT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_station_key (station_id, setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log("System Settings table creation failed: " . $e->getMessage());
}

// Migration: add missing columns to existing tables
try {
    $cols = $pdo->query("SHOW COLUMNS FROM system_settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('station_id', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN station_id INT DEFAULT 0 COMMENT '0 for global' AFTER id");
    }
    if (!in_array('category', $cols)) {
        $pdo->exec("ALTER TABLE system_settings ADD COLUMN category VARCHAR(50) DEFAULT 'general' AFTER setting_value");
    }
    // Rebuild unique key if station_id was just added
    $indexes = $pdo->query("SHOW INDEX FROM system_settings WHERE Key_name = 'idx_station_key'")->fetchAll();
    if (empty($indexes)) {
        try { $pdo->exec("ALTER TABLE system_settings ADD UNIQUE KEY idx_station_key (station_id, setting_key)"); } catch(Exception $e2) {}
    }
} catch (Exception $e) {
    error_log("System Settings migration failed: " . $e->getMessage());
}

$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name, address, status FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stations = [];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
:root {
    --primary-color: #002F6C;
    --surface: #ffffff;
    --page-bg: #f4f6fb;
    --text-primary: #1f2937;
    --text-secondary: #6b7280;
    --border-color: #e5e7eb;
    --radius-card: 10px;
    --shadow-card: 0 4px 16px rgba(0,0,0,0.06);
}

/* ── Logo button overrides — defeat global theme CSS ── */
#btn_upload_logo,
#btn_upload_logo:link,
#btn_upload_logo:visited {
    background: transparent !important;
    background-color: transparent !important;
    color: #002F6C !important;
    border: 2px solid #002F6C !important;
    padding: 7px 16px !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    box-shadow: none !important;
    text-decoration: none !important;
}
#btn_upload_logo:hover {
    background: rgba(0,47,108,0.07) !important;
}

#btn_remove_logo,
#btn_remove_logo:link,
#btn_remove_logo:visited {
    background: transparent !important;
    background-color: transparent !important;
    color: #dc2626 !important;
    border: 2px solid #dc2626 !important;
    padding: 5px 12px !important;
    border-radius: 6px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    box-shadow: none !important;
    text-decoration: none !important;
}
#btn_remove_logo:hover {
    background: rgba(220,38,38,0.07) !important;
}

.ss-wrapper {
    display: block;
    min-height: calc(100vh - 120px);
    background: var(--page-bg);
    width: 100%;
}

.ss-content {
    padding: 0 !important;
}

.ss-panel-header {
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

.ss-panel-header h1 {
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

.ss-panel-header p {
    color: var(--text-secondary);
    font-size: 13px;
    margin: 0;
}

.ss-card {
    background: var(--surface);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-card);
    padding: 20px 24px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

.ss-card-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0 0 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.ss-card-title i {
    color: #3b82f6;
    font-size: 16px;
}

.ss-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.ss-grid-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
}

.ss-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.ss-form-group {
    margin-bottom: 16px;
}

.ss-form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: #374151;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.ss-form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--border-color);
    border-radius: 7px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--surface);
    transition: border-color 0.15s, box-shadow 0.15s;
    box-sizing: border-box;
}

.ss-form-control:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
}

.ss-toggle-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #f9fafb;
}

.ss-toggle-label {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}

.ss-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 22px;
}

.ss-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.ss-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .2s;
    border-radius: 22px;
}

.ss-slider:before {
    position: absolute;
    content: "";
    height: 16px;
    width: 16px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .2s;
    border-radius: 50%;
}

input:checked + .ss-slider {
    background-color: #16a34a;
}

input:checked + .ss-slider:before {
    transform: translateX(22px);
}

/* Action Footer Bar at bottom of data */
.ss-action-bar {
    position: static !important;
    margin-top: 25px !important;
    margin-bottom: 25px !important;
    padding: 10px 0 !important;
    display: flex !important;
    justify-content: flex-end !important;
    align-items: center !important;
    gap: 12px !important;
    background: transparent !important;
    background-color: transparent !important;
    border-top: none !important;
    border: none !important;
    box-shadow: none !important;
    z-index: 10 !important;
    pointer-events: auto !important;
}
.ss-action-bar button,
.ss-action-bar .ss-btn {
    pointer-events: auto !important;
}

.ss-btn {
    padding: 9px 20px !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    background: transparent !important;
    background-color: transparent !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    transition: all 0.18s ease !important;
    letter-spacing: 0.3px !important;
    white-space: nowrap !important;
    text-decoration: none !important;
}

.ss-btn-primary {
    color: #002F6C !important;
    border: 2px solid #002F6C !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-primary:hover {
    background: rgba(0, 47, 108, 0.08) !important;
    color: #002F6C !important;
}

.ss-btn-secondary {
    color: #dc2626 !important;
    border: 2px solid #dc2626 !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-secondary:hover {
    background: rgba(220, 38, 38, 0.08) !important;
    color: #dc2626 !important;
}

.ss-btn-light {
    color: #374151 !important;
    border: 2px solid #9ca3af !important;
    background: transparent !important;
    background-color: transparent !important;
}

.ss-btn-light:hover {
    background: rgba(156, 163, 175, 0.10) !important;
    color: #374151 !important;
}

/* Custom Virtual Station Combobox */
.am-combo { position: relative; }
.am-combo-input {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    font-size: 13px;
    background: #fff;
    box-sizing: border-box;
}
.am-combo-arrow {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    pointer-events: none;
    font-size: 11px;
}
.am-combo-clear {
    position: absolute;
    right: 30px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    padding: 4px;
    display: none;
}
.am-combo-dropdown {
    position: absolute;
    top: 100%;
    left: 0; right: 0;
    margin-top: 4px;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.12);
    z-index: 9999;
    display: none;
    max-height: 260px;
    overflow-y: auto;
}
.am-combo-item {
    padding: 10px 14px;
    font-size: 13px;
    cursor: pointer;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}
.am-combo-item:hover, .am-combo-item.selected {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
}
</style>

<div class="ss-wrapper">
    <div class="ss-content">

        <!-- Panel Header -->
        <div class="ss-panel-header">
            <h1>System Settings</h1>
        </div>

        <!-- Station Selection -->
        <div class="ss-card">
            <div class="ss-card-title">
                <i class="fas fa-map-marker-alt"></i> Station Selection
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <label style="font-weight: 700; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
                    Scope Settings For:
                </label>
                <div class="am-combo" id="ss_station_combo" style="width: 420px;">
                    <input type="text" class="am-combo-input" id="ss_station_display"
                           placeholder="Type to search stations..." autocomplete="off">
                    <button type="button" class="am-combo-clear" id="ss_station_clear" tabindex="-1" title="Clear">
                        <i class="fas fa-times"></i>
                    </button>
                    <i class="fas fa-chevron-down am-combo-arrow"></i>
                    <input type="hidden" id="ss_station_val" value="0">
                    <div class="am-combo-dropdown" id="ss_station_dropdown">
                        <div class="am-combo-list" id="ss_station_list"></div>
                    </div>
                </div>
            </div>
        </div>

        <form id="systemSettingsForm" onsubmit="return false;">

            <!-- General Settings -->
            <div class="ss-card" id="section_general">
                <div class="ss-card-title">
                    <i class="fas fa-sliders-h"></i> General Settings
                </div>
                <div class="ss-grid-3">
                    <div class="ss-form-group">
                        <label for="ss_system_name">System Name</label>
                        <input type="text" id="ss_system_name" class="ss-form-control"
                               value="Petron Station Management System" placeholder="Enter System Name">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_logo_input">Company Logo</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="file" id="ss_logo_input" accept="image/*" class="ss-form-control" style="padding:6px 10px;">
                            <button type="button" id="btn_upload_logo" onclick="uploadLogo()"><i class="fas fa-upload"></i> Upload</button>
                        </div>
                        <div id="logo_preview_container" style="margin-top:8px; display:flex; align-items:center; gap:12px;">
                            <img id="logo_preview_img" src="../assets/img/petron_logo.png" alt="Company Logo" style="height:36px; border-radius:4px; border:1px solid #e2e8f0; padding:2px; background:#fff;">
                            <button type="button" id="btn_remove_logo" onclick="removeLogo()"><i class="fas fa-trash-alt"></i> Remove Logo</button>
                        </div>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_system_version">System Version</label>
                        <input type="text" id="ss_system_version" class="ss-form-control"
                               value="v1.0.0" readonly style="background:#f8fafc; font-family:monospace;">
                    </div>
                </div>
            </div>

            <!-- Regional Settings -->
            <div class="ss-card" id="section_regional">
                <div class="ss-card-title">
                    <i class="fas fa-globe"></i> Regional Settings
                </div>
                <div class="ss-grid-2">
                    <div class="ss-form-group">
                        <label for="ss_timezone">Timezone</label>
                        <select id="ss_timezone" class="ss-form-control">
                            <option value="Asia/Manila (UTC+8)">Asia/Manila (UTC+8)</option>
                            <option value="UTC">UTC</option>
                            <option value="Asia/Singapore (UTC+8)">Asia/Singapore (UTC+8)</option>
                            <option value="America/New_York (UTC-5)">America/New_York (UTC-5)</option>
                            <option value="Europe/London (UTC+0)">Europe/London (UTC+0)</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_date_format">Date Format</label>
                        <select id="ss_date_format" class="ss-form-control">
                            <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                            <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                            <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                            <option value="MMM DD, YYYY">MMM DD, YYYY</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_time_format">Time Format</label>
                        <select id="ss_time_format" class="ss-form-control">
                            <option value="12H">12H (12-Hour)</option>
                            <option value="24H">24H (24-Hour)</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_currency_symbol">Currency Symbol</label>
                        <select id="ss_currency_symbol" class="ss-form-control">
                            <option value="PHP (₱)">PHP (₱)</option>
                            <option value="USD ($)">USD ($)</option>
                            <option value="EUR (€)">EUR (€)</option>
                            <option value="JPY (¥)">JPY (¥)</option>
                            <option value="GBP (£)">GBP (£)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Appearance -->
            <div class="ss-card" id="section_appearance">
                <div class="ss-card-title">
                    <i class="fas fa-paint-brush"></i> Appearance
                </div>
                <div class="ss-grid-4">
                    <div class="ss-form-group">
                        <label for="ss_theme">Theme</label>
                        <select id="ss_theme" class="ss-form-control">
                            <option value="Light">Light</option>
                            <option value="Dark">Dark</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_accent_color">System Accent Color</label>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <input type="color" id="ss_accent_color" value="#002F6C" style="padding:1px 3px; height:38px; width:45px; cursor:pointer; border:1px solid #cbd5e1; border-radius:6px; background:#fff;" oninput="document.getElementById('ss_accent_color_hex').value=this.value.toUpperCase()">
                            <input type="text" id="ss_accent_color_hex" class="ss-form-control" value="#002F6C" readonly style="background:#f8fafc; font-family:monospace; text-align:center; font-weight:600;">
                        </div>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_sidebar_mode">Sidebar Mode</label>
                        <select id="ss_sidebar_mode" class="ss-form-control">
                            <option value="Expanded">Expanded</option>
                            <option value="Collapsed">Collapsed</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_dashboard_auto_refresh">Dashboard Auto Refresh (seconds)</label>
                        <input type="number" id="ss_dashboard_auto_refresh" class="ss-form-control" value="30" min="5" max="300">
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="ss-card" id="section_security">
                <div class="ss-card-title">
                    <i class="fas fa-shield-alt"></i> Security
                </div>
                <div class="ss-grid-3" style="margin-bottom:16px;">
                    <div class="ss-form-group">
                        <label for="ss_session_timeout">Session Timeout (minutes)</label>
                        <input type="number" id="ss_session_timeout" class="ss-form-control" value="30" min="5" max="1440">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_min_password_length">Minimum Password Length</label>
                        <input type="number" id="ss_min_password_length" class="ss-form-control" value="8" min="6" max="32">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_max_login_attempts">Maximum Login Attempts</label>
                        <input type="number" id="ss_max_login_attempts" class="ss-form-control" value="5" min="3" max="10">
                    </div>
                </div>
                <div class="ss-grid-3">
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Uppercase</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_uppercase" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Numbers</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_numbers" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Require Special Characters</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_require_special_chars" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Notification Settings -->
            <div class="ss-card" id="section_notification">
                <div class="ss-card-title">
                    <i class="fas fa-bell"></i> Notification Settings
                </div>
                <div class="ss-grid-3">
                    <div class="ss-form-group">
                        <label for="ss_banner_duration">Success Banner Duration (seconds)</label>
                        <input type="number" id="ss_banner_duration" class="ss-form-control" value="5" min="1" max="30">
                    </div>
                    <div class="ss-toggle-wrapper" style="align-self:end; margin-bottom:16px;">
                        <span class="ss-toggle-label">Enable System Notifications</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_enable_system_notifications" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper" style="align-self:end; margin-bottom:16px;">
                        <span class="ss-toggle-label">Enable Error Notifications</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_enable_error_notifications" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Report Settings -->
            <div class="ss-card" id="section_reports">
                <div class="ss-card-title">
                    <i class="fas fa-file-alt"></i> Report Settings
                </div>
                <div class="ss-grid-2" style="margin-bottom:16px;">
                    <div class="ss-form-group">
                        <label for="ss_default_paper_size">Default Paper Size</label>
                        <select id="ss_default_paper_size" class="ss-form-control">
                            <option value="A4">A4</option>
                            <option value="Letter">Letter</option>
                        </select>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_default_orientation">Default Orientation</label>
                        <select id="ss_default_orientation" class="ss-form-control">
                            <option value="Portrait">Portrait</option>
                            <option value="Landscape">Landscape</option>
                        </select>
                    </div>
                </div>
                <div class="ss-grid-2">
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Show Company Logo on Reports</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_show_company_logo_reports" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-toggle-wrapper">
                        <span class="ss-toggle-label">Show Report Footer</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_show_report_footer" checked>
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Maintenance Settings (Recommended) -->
            <div class="ss-card" id="section_maintenance">
                <div class="ss-card-title">
                    <i class="fas fa-tools"></i> Maintenance Settings
                </div>
                <div class="ss-grid-3">
                    <div class="ss-toggle-wrapper" style="align-self:center;">
                        <span class="ss-toggle-label">Maintenance Mode</span>
                        <label class="ss-switch">
                            <input type="checkbox" id="ss_maintenance_mode">
                            <span class="ss-slider"></span>
                        </label>
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_system_status">System Status</label>
                        <input type="text" id="ss_system_status" class="ss-form-control" value="Online" readonly style="background:#f8fafc; font-weight:700; color:#16a34a;">
                    </div>
                    <div class="ss-form-group">
                        <label for="ss_last_system_update">Last System Update</label>
                        <input type="text" id="ss_last_system_update" class="ss-form-control" value="2026-08-06 22:30:00" readonly style="background:#f8fafc; font-family:monospace;">
                    </div>
                </div>
            </div>

            <!-- Action Footer Bar (at bottom of form data) -->
            <div class="ss-action-bar">
                <button type="button" class="ss-btn ss-btn-secondary" onclick="restoreDefaultSettings()">
                    <i class="fas fa-undo-alt"></i> Restore Default
                </button>
                <button type="button" class="ss-btn ss-btn-light" onclick="cancelSettingsChanges()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="ss-btn ss-btn-primary" onclick="saveAllSystemSettings()">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>

        </form>
    </div>
</div>

<!-- Toast Container -->
<div id="toastNotification" style="display:none; position:fixed; top:72px; right:20px; z-index:999999; width:380px; background:#ffffff; border-radius:10px; box-shadow:0 8px 32px rgba(0,0,0,0.22); animation:toastSlideIn 0.35s cubic-bezier(0.16,1,0.3,1);">
    <div style="display:flex; align-items:flex-start; gap:12px; padding:16px;">
        <div id="toastIconBg" style="flex-shrink:0; background:#dcfce7; width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
            <i id="toastIcon" class="fas fa-check-circle" style="color:#16a34a; font-size:18px;"></i>
        </div>
        <div style="flex:1; min-width:0;">
            <div id="toastTitle" style="font-size:13px; font-weight:700; color:#15803d; margin-bottom:3px;">Success</div>
            <div id="toastMessage" style="font-size:12px; color:#374151; line-height:1.55; font-weight:400;">Settings saved successfully.</div>
        </div>
    </div>
</div>

<style>
@keyframes toastSlideIn {
    from { opacity:0; transform:translateX(110%); }
    to   { opacity:1; transform:translateX(0); }
}
</style>

<script>
const STATION_DATA = <?php echo json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $stations), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const API_URL = '../backend/api/system_settings_api.php';
let loadedSettings = {};

function showToast(title, message, isError = false) {
    const el = document.getElementById('toastNotification');
    const tTitle = document.getElementById('toastTitle');
    const tMsg = document.getElementById('toastMessage');
    const tIcon = document.getElementById('toastIcon');
    const tBg = document.getElementById('toastIconBg');

    tTitle.textContent = title;
    tMsg.textContent = message;

    if (isError) {
        tTitle.style.color = '#dc2626';
        tBg.style.background = '#fee2e2';
        tIcon.className = 'fas fa-exclamation-circle';
        tIcon.style.color = '#dc2626';
    } else {
        tTitle.style.color = '#15803d';
        tBg.style.background = '#dcfce7';
        tIcon.className = 'fas fa-check-circle';
        tIcon.style.color = '#16a34a';
    }

    el.style.display = 'block';
    el.style.opacity = '1';
    el.style.transform = 'translateX(0)';

    setTimeout(() => {
        el.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateX(110%)';
        setTimeout(() => { el.style.display = 'none'; }, 320);
    }, 5000);
}

// Station Combo Box Init
(function initStationCombo() {
    const combo   = document.getElementById('ss_station_combo');
    const list    = document.getElementById('ss_station_list');
    const display = document.getElementById('ss_station_display');
    const hidden  = document.getElementById('ss_station_val');
    const clear   = document.getElementById('ss_station_clear');
    const dropdown = document.getElementById('ss_station_dropdown');

    if (!combo || !display || !list || !hidden || !clear || !dropdown) return;

    let currentVal = '0';

    function render(q) {
        const rawQ = (q || '').trim();
        // If query matches current selected label exactly, treat search filter as empty so all options show
        const isSelectedLabel = (currentVal === '0' && rawQ === 'Global (All Stations)') || 
                                (STATION_DATA.some(s => String(s.id) === currentVal && s.name === rawQ));
        const lq = isSelectedLabel ? '' : rawQ.toLowerCase();
        list.innerHTML = '';

        if (!lq || 'global (all stations)'.includes(lq)) {
            const itemAll = document.createElement('div');
            itemAll.className = 'am-combo-item' + (currentVal === '0' ? ' selected' : '');
            itemAll.textContent = 'Global (All Stations)';
            itemAll.onclick = () => select('0', 'Global (All Stations)');
            list.appendChild(itemAll);
        }

        const filtered = STATION_DATA.filter(s => !lq || s.name.toLowerCase().includes(lq)).slice(0, 100);
        filtered.forEach(s => {
            const item = document.createElement('div');
            item.className = 'am-combo-item' + (currentVal === String(s.id) ? ' selected' : '');
            item.textContent = s.name;
            item.onclick = () => select(String(s.id), s.name);
            list.appendChild(item);
        });
    }

    function select(id, name) {
        currentVal = id;
        hidden.value = id;
        display.value = name;
        dropdown.style.display = 'none';
        clear.style.display = (id === '0') ? 'none' : 'block';
        loadSystemSettings(id);
    }

    display.onfocus = () => { render(display.value); dropdown.style.display = 'block'; display.select(); };
    display.onclick = () => { render(display.value); dropdown.style.display = 'block'; };
    display.oninput = () => { render(display.value); dropdown.style.display = 'block'; };

    clear.onclick = (e) => {
        e.stopPropagation();
        select('0', 'Global (All Stations)');
    };

    document.addEventListener('click', (e) => {
        if (!combo.contains(e.target)) dropdown.style.display = 'none';
    });

    display.value = 'Global (All Stations)';
})();

async function loadSystemSettings(stationId = '0') {
    try {
        const res = await fetch(`${API_URL}?action=get_settings&station_id=${stationId}`);
        const data = await res.json();
        if (data.success && data.settings && typeof data.settings === 'object') {
            loadedSettings = data.settings;
            populateFormFields(data.settings);
        } else if (!data.success) {
            showToast('Load Error', data.message || 'Failed to load settings.', true);
        }
    } catch (e) {
        console.error('Failed to load system settings:', e);
        showToast('Load Error', 'Could not connect to server.', true);
    }
}

function populateFormFields(s) {
    document.getElementById('ss_system_name').value = s.system_name || 'Petron Station Management System';
    document.getElementById('ss_system_version').value = s.system_version || 'v1.0.0';
    document.getElementById('ss_timezone').value = s.timezone || 'Asia/Manila (UTC+8)';
    document.getElementById('ss_date_format').value = s.date_format || 'YYYY-MM-DD';
    document.getElementById('ss_time_format').value = s.time_format || '12H';
    document.getElementById('ss_currency_symbol').value = s.currency_symbol || 'PHP (₱)';
    document.getElementById('ss_theme').value = s.theme || 'Light';
    const accentCol = s.system_accent_color || '#002F6C';
    document.getElementById('ss_accent_color').value = accentCol;
    document.getElementById('ss_accent_color_hex').value = accentCol.toUpperCase();
    document.getElementById('ss_sidebar_mode').value = s.sidebar_mode || 'Expanded';
    document.getElementById('ss_dashboard_auto_refresh').value = s.dashboard_auto_refresh || '30';
    document.getElementById('ss_session_timeout').value = s.session_timeout || '30';
    document.getElementById('ss_min_password_length').value = s.min_password_length || '8';
    document.getElementById('ss_max_login_attempts').value = s.max_login_attempts || '5';
    document.getElementById('ss_require_uppercase').checked = s.require_uppercase == '1';
    document.getElementById('ss_require_numbers').checked = s.require_numbers == '1';
    document.getElementById('ss_require_special_chars').checked = s.require_special_chars == '1';
    document.getElementById('ss_banner_duration').value = s.banner_duration || '5';
    document.getElementById('ss_enable_system_notifications').checked = s.enable_system_notifications == '1';
    document.getElementById('ss_enable_error_notifications').checked = s.enable_error_notifications == '1';
    document.getElementById('ss_default_paper_size').value = s.default_paper_size || 'A4';
    document.getElementById('ss_default_orientation').value = s.default_orientation || 'Portrait';
    document.getElementById('ss_show_company_logo_reports').checked = s.show_company_logo_reports == '1';
    document.getElementById('ss_show_report_footer').checked = s.show_report_footer == '1';
    document.getElementById('ss_maintenance_mode').checked = s.maintenance_mode == '1';
    document.getElementById('ss_system_status').value = s.system_status || 'Online';
    document.getElementById('ss_last_system_update').value = s.last_system_update || '2026-08-06 22:30:00';

    if (s.company_logo) {
        document.getElementById('logo_preview_img').src = s.company_logo;
    }
}

async function uploadLogo() {
    const fileInput = document.getElementById('ss_logo_input');
    if (!fileInput.files || !fileInput.files[0]) {
        showToast('Logo Upload', 'Please select an image file first.', true);
        return;
    }
    const stationId = document.getElementById('ss_station_val').value || '0';
    const formData = new FormData();
    formData.append('action', 'upload_logo');
    formData.append('station_id', stationId);
    formData.append('logo', fileInput.files[0]);

    try {
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById('logo_preview_img').src = data.logo_url;
            showToast('Logo Uploaded', data.message || 'Company logo uploaded successfully.');
        } else {
            showToast('Upload Error', data.message || 'Failed to upload logo.', true);
        }
    } catch (e) {
        showToast('Upload Error', 'Failed to communicate with server.', true);
    }
}

async function removeLogo() {
    const stationId = document.getElementById('ss_station_val').value || '0';
    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove_logo', station_id: parseInt(stationId) })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('logo_preview_img').src = '../assets/img/petron_logo.png';
            showToast('Logo Removed', data.message || 'Company logo removed successfully.');
        } else {
            showToast('Error', data.message || 'Failed to remove logo.', true);
        }
    } catch (e) {
        showToast('Error', 'Failed to remove logo.', true);
    }
}

async function saveAllSystemSettings() {
    const stationId = document.getElementById('ss_station_val').value || '0';

    // Basic numeric validation
    const numericFields = [
        { id: 'ss_session_timeout',        label: 'Session Timeout',          min: 1,  max: 1440 },
        { id: 'ss_min_password_length',    label: 'Min Password Length',      min: 4,  max: 64   },
        { id: 'ss_max_login_attempts',     label: 'Max Login Attempts',       min: 1,  max: 20   },
        { id: 'ss_dashboard_auto_refresh', label: 'Dashboard Auto Refresh',   min: 0,  max: 3600 },
        { id: 'ss_banner_duration',        label: 'Banner Duration',          min: 1,  max: 60   },
    ];
    for (const f of numericFields) {
        const val = parseInt(document.getElementById(f.id).value);
        if (isNaN(val) || val < f.min || val > f.max) {
            showToast('Validation Error', `${f.label} must be a number between ${f.min} and ${f.max}.`, true);
            document.getElementById(f.id).focus();
            return;
        }
    }

    const payload = {
        action: 'save_all_settings',
        station_id: parseInt(stationId),
        settings: {
            system_name: document.getElementById('ss_system_name').value,
            system_version: document.getElementById('ss_system_version').value,
            timezone: document.getElementById('ss_timezone').value,
            date_format: document.getElementById('ss_date_format').value,
            time_format: document.getElementById('ss_time_format').value,
            currency_symbol: document.getElementById('ss_currency_symbol').value,
            theme: document.getElementById('ss_theme').value,
            system_accent_color: document.getElementById('ss_accent_color').value,
            sidebar_mode: document.getElementById('ss_sidebar_mode').value,
            dashboard_auto_refresh: document.getElementById('ss_dashboard_auto_refresh').value,
            session_timeout: document.getElementById('ss_session_timeout').value,
            min_password_length: document.getElementById('ss_min_password_length').value,
            max_login_attempts: document.getElementById('ss_max_login_attempts').value,
            require_uppercase: document.getElementById('ss_require_uppercase').checked ? '1' : '0',
            require_numbers: document.getElementById('ss_require_numbers').checked ? '1' : '0',
            require_special_chars: document.getElementById('ss_require_special_chars').checked ? '1' : '0',
            banner_duration: document.getElementById('ss_banner_duration').value,
            enable_system_notifications: document.getElementById('ss_enable_system_notifications').checked ? '1' : '0',
            enable_error_notifications: document.getElementById('ss_enable_error_notifications').checked ? '1' : '0',
            default_paper_size: document.getElementById('ss_default_paper_size').value,
            default_orientation: document.getElementById('ss_default_orientation').value,
            show_company_logo_reports: document.getElementById('ss_show_company_logo_reports').checked ? '1' : '0',
            show_report_footer: document.getElementById('ss_show_report_footer').checked ? '1' : '0',
            maintenance_mode: document.getElementById('ss_maintenance_mode').checked ? '1' : '0',
            system_status: document.getElementById('ss_system_status').value,
            last_system_update: document.getElementById('ss_last_system_update').value,
        }
    };

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.success) {
            showToast('Settings Saved', 'System settings saved successfully.');
            loadSystemSettings(stationId);
        } else {
            showToast('Save Error', data.message || 'Failed to save system settings.', true);
        }
    } catch (e) {
        showToast('Save Error', 'Failed to send request to server.', true);
    }
}

async function restoreDefaultSettings() {
    const stationId = document.getElementById('ss_station_val').value || '0';
    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'restore_defaults', station_id: parseInt(stationId) })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Defaults Restored', 'System settings restored to default values successfully.');
            loadSystemSettings(stationId);
        } else {
            showToast('Restore Error', data.message || 'Failed to restore settings.', true);
        }
    } catch (e) {
        showToast('Restore Error', 'Failed to communicate with server.', true);
    }
}

function cancelSettingsChanges() {
    populateFormFields(loadedSettings);
    showToast('Changes Cancelled', 'Form reset back to saved settings.');
}

// Initial load on page ready
document.addEventListener('DOMContentLoaded', () => {
    loadSystemSettings('0');
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
