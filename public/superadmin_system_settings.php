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

// Fetch all stations for the station selector
$stations = [];
try {
    $stmt = $pdo->query("SELECT id, name, address, status FROM stations ORDER BY name ASC");
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stations = [];
}

include __DIR__ . '/../partials/header.php';
?>
<link rel="stylesheet" href="../backend/generate_theme_css.php">
<style>
/* -- CSS Variables ----------------------------------------------------------- */
:root {
    --primary-color:    var(--petron-blue, #002F6C);
    --surface:          #ffffff;
    --page-bg:          #f4f6fb;
    --text-primary:     #1a1a2e;
    --text-secondary:   #6b7280;
    --border-color:     #e5e7eb;
    --radius-card:      12px;
    --shadow-card:      0 2px 12px rgba(0,0,0,0.08);
    --transition:       0.2s ease;
}

/* -- Sidebar Protection (HARDCODED - immune to generate_theme_css.php) -------- */
aside.sidebar,
#mainSidebar,
.sidebar {
    background-color: #00264D !important;
    background: #00264D !important;
    background-image: none !important;
}

/* Force ALL sidebar icons to stay white */
.sidebar i,
.sidebar .fas,
.sidebar .far,
.sidebar .fab,
.sidebar .fa {
    color: #eeeeee !important;
}

/* Force nav-item text and background to stay correct on dark sidebar */
.sidebar .nav-item,
.sidebar a.nav-item {
    color: #eeeeee !important;
    background-color: transparent !important;
    background: transparent !important;
}

.sidebar .nav-item span,
.sidebar a.nav-item span {
    color: #eeeeee !important;
}

.sidebar .nav-item:hover,
.sidebar a.nav-item:hover {
    background-color: rgba(255,255,255,0.10) !important;
    background: rgba(255,255,255,0.10) !important;
    color: #ffffff !important;
}

.sidebar .nav-item.active,
.sidebar a.nav-item.active {
    background-color: #CC0000 !important;
    background: #CC0000 !important;
    color: #ffffff !important;
}

.sidebar .nav-item.active span,
.sidebar .nav-item.active i {
    color: #ffffff !important;
}

/* -- Page Layout ------------------------------------------------------------- */
.ss-wrapper {
    display: block;
    min-height: calc(100vh - 140px);
    background: var(--page-bg);
    width: 100%;
}

/* -- Main Content ------------------------------------------------------------ */
.ss-content {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 15px 32px 28px !important;
    overflow-y: auto;
    background: var(--page-bg);
}

/* -- Cards ------------------------------------------------------------------- */
.ss-card {
    background: var(--surface);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-card);
    padding: 24px;
    margin-bottom: 20px;
    border: 1px solid var(--border-color);
}

.ss-card-title {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: var(--primary-color, #002F6C) !important;
    margin: 0 0 16px !important;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 8px;
    text-transform: uppercase !important;
}

.ss-card-title i {
    color: var(--primary-color);
    font-size: 16px;
}

/* -- Panel Header ------------------------------------------------------------ */
.ss-panel-header {
    margin-bottom: 24px;
    margin-top: -12px !important;
}

.ss-panel-header h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--primary-color, #002F6C) !important;
    margin: 0 0 4px !important;
    text-transform: uppercase !important;
}

.ss-panel-header p {
    color: var(--text-secondary);
    font-size: 14px;
    margin: 0;
}

/* -- Form Controls ----------------------------------------------------------- */
.ss-form-group {
    margin-bottom: 18px;
}

.ss-form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.ss-form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 14px;
    color: var(--text-primary);
    background: var(--surface);
    transition: border-color var(--transition), box-shadow var(--transition);
    box-sizing: border-box;
}

.ss-form-control:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,47,108,0.1);
}

/* -- Buttons ----------------------------------------------------------------- */
.ss-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all var(--transition);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.ss-btn-primary {
    background: white !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
}

.ss-btn-primary:hover {
    background: #00264D !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,47,108,0.3);
}

.ss-btn-secondary {
    background: white !important;
    color: #6b7280 !important;
    border: 1px solid #6b7280 !important;
}

.ss-btn-secondary:hover {
    background: #6b7280 !important;
    color: white !important;
}

.ss-btn-success {
    background: white !important;
    color: #16a34a !important;
    border: 1px solid #16a34a !important;
}

.ss-btn-success:hover {
    background: #16a34a !important;
    color: white !important;
}

.ss-btn-danger {
    background: white !important;
    color: #dc2626 !important;
    border: 1px solid #dc2626 !important;
}

.ss-btn-danger:hover {
    background: #dc2626 !important;
    color: white !important;
}

/* -- Toggle Switch ----------------------------------------------------------- */
.ss-toggle-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.ss-toggle {
    position: relative;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}

.ss-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.ss-toggle-slider {
    position: absolute;
    inset: 0;
    background: #d1d5db;
    border-radius: 24px;
    cursor: pointer;
    transition: background var(--transition);
}

.ss-toggle-slider::before {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    left: 3px;
    top: 3px;
    background: #fff;
    border-radius: 50%;
    transition: transform var(--transition);
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.ss-toggle input:checked + .ss-toggle-slider {
    background: var(--primary-color);
}

.ss-toggle input:checked + .ss-toggle-slider::before {
    transform: translateX(20px);
}

/* -- Toast Notifications ----------------------------------------------------- */
#ss-toast-container {
    position: fixed;
    top: 80px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ss-toast {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 10px;
    background: var(--surface);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    border-left: 4px solid #16a34a;
    max-width: 380px;
    animation: toastIn 0.3s ease;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

.ss-toast.error   { border-left-color: #dc2626; }
.ss-toast.warning { border-left-color: #d97706; }
.ss-toast.info    { border-left-color: #2563eb; }

.ss-toast.success i { color: #16a34a; }
.ss-toast.error   i { color: #dc2626; }
.ss-toast.warning i { color: #d97706; }
.ss-toast.info    i { color: #2563eb; }

@keyframes toastIn {
    from { opacity: 0; transform: translateX(30px); }
    to   { opacity: 1; transform: translateX(0); }
}

/* -- Loading Spinner --------------------------------------------------------- */
.ss-spinner {
    display: inline-block;
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255,255,255,0.4);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* -- Logo Preview ------------------------------------------------------------ */
.ss-logo-preview-box {
    width: 200px;
    height: 140px;
    border: 2px dashed var(--border-color);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--page-bg);
    overflow: hidden;
    margin-bottom: 16px;
}

.ss-logo-preview-box img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

/* -- Color Pickers Row ------------------------------------------------------- */
.ss-color-row {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.ss-color-item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.ss-color-item label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.ss-color-picker-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 6px 10px;
    background: var(--surface);
}

.ss-color-picker-wrap input[type="color"] {
    width: 32px;
    height: 32px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    padding: 0;
}

.ss-color-hex {
    font-size: 13px;
    font-family: monospace;
    color: var(--text-primary);
    width: 80px;
    border: none;
    outline: none;
    background: transparent;
}

/* -- Live Preview Panel ------------------------------------------------------ */
.ss-live-preview {
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    margin-top: 20px;
}

.ss-preview-bar {
    height: 50px;
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 12px;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
}

.ss-preview-body {
    padding: 20px;
    background: #f8fafc;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.ss-preview-btn {
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    border: none;
    color: #fff;
}

/* -- Accessibility Slider ---------------------------------------------------- */
.ss-slider-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 20px;
}

.ss-slider {
    flex: 1;
    accent-color: var(--primary-color);
    height: 6px;
    cursor: pointer;
}

.ss-slider-value {
    font-size: 14px;
    font-weight: 700;
    color: var(--primary-color);
    min-width: 50px;
    text-align: right;
}

.ss-font-preview {
    padding: 16px 20px;
    background: var(--page-bg);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    margin-top: 12px;
    transition: font-size var(--transition);
}

/* -- Responsive -------------------------------------------------------------- */
@media (max-width: 768px) {
    .ss-content { padding: 16px; }
    .ss-color-row { flex-direction: column; }
}
</style>


<!-- Toast Container -->
<div id="ss-toast-container"></div>

<!-- Page Wrapper -->
<div class="ss-wrapper">
  <main class="ss-content">

    <!-- Page Title -->
    <div class="ss-panel-header">
      <h1><i class="fas fa-cog" style="margin-right:8px;color:var(--primary-color);"></i>System Settings - Estate Form</h1>
      <p>Configure all system appearance, layout, and accessibility options in one view.</p>
    </div>

    <!-- ====================================================================
         🔹 STATION SELECTOR
    ======================================================================= -->
    <div class="ss-card" id="station-selector-card">
      <h3 class="ss-card-title"><i class="fas fa-map-marker-alt"></i>Station Selection</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">
        Select a station to scope settings for that specific branch. Global settings apply to all stations.
      </p>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="position:relative;flex:1;max-width:480px;">
          <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none;"></i>
          <input type="text" id="stationSearchInput" placeholder="Search stations..." autocomplete="off"
                 style="width:100%;padding:10px 14px 10px 36px;border:1px solid var(--border-color);border-radius:8px;font-size:13px;color:var(--text-primary);background:var(--surface);box-sizing:border-box;"
                 onfocus="showStationDropdown()">
          <div id="stationDropdown" style="display:none;position:absolute;top:calc(100%+4px);left:0;right:0;background:#fff;border:1px solid var(--border-color);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;max-height:220px;overflow-y:auto;"></div>
        </div>
        <input type="hidden" id="selectedStationId" value="0">
        <button class="ss-btn ss-btn-secondary" onclick="clearStationFilter()" id="clearStationBtn" style="display:none;">
          <i class="fas fa-times"></i> Clear
        </button>
        <span style="font-size:13px;color:var(--text-secondary);" id="current-scope-indicator">
          <i class="fas fa-globe" style="color:#3b82f6;"></i> Global (all stations)
        </span>
      </div>
      <div id="selectedStationBanner" style="display:none;margin-top:12px;padding:10px 16px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:6px;font-size:13px;color:#1e40af;">
        <i class="fas fa-building"></i> Configuring for: <strong id="selectedStationName"></strong>
      </div>
    </div>


    <!-- ====================================================================
         🔹 LOGO MANAGEMENT
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-image"></i>Logo Management</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Upload or replace company/station logo. Changes reflect on dashboard, receipts, and reports.
      </p>
      
      <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:8px;text-transform:uppercase;">
            Current Logo Preview
          </label>
          <div class="ss-logo-preview-box" id="logo-preview-box">
            <img id="logo-preview-img" src="../assets/img/Petron Logo.png" alt="Logo Preview">
          </div>
        </div>

        <div style="flex:1;">
          <div class="ss-form-group">
            <label for="logo-upload-input"><i class="fas fa-upload"></i> Upload New Logo</label>
            <input type="file" id="logo-upload-input" accept="image/*" class="ss-form-control" onchange="handleLogoUpload(event)">
            <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
              Accepted formats: JPG, PNG, GIF. Max size: 2MB. Recommended: 200x80px
            </p>
          </div>

          <div style="display:flex;gap:10px;margin-top:16px;">
            <button class="ss-btn ss-btn-primary" id="save-logo-btn" onclick="saveLogo()">
              <i class="fas fa-save"></i> Apply Logo
            </button>
            <button class="ss-btn ss-btn-danger" onclick="removeLogo()">
              <i class="fas fa-trash"></i> Remove Logo
            </button>
          </div>
        </div>
      </div>
    </div>


    <!-- ====================================================================
         🔹 COLOR THEME / UI SCHEME
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-palette"></i>Color Theme / UI Scheme</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Set branding colors for dashboard, buttons, sidebar navigation, and more.
      </p>

      <div class="ss-color-row">
        <div class="ss-color-item">
          <label>Primary Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-primary" value="#002F6C" onchange="updateColorHex('primary', this.value)">
            <input type="text" class="ss-color-hex" id="hex-primary" value="#002F6C" oninput="updateColorPicker('primary', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Accent Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-accent" value="#CC0000" onchange="updateColorHex('accent', this.value)">
            <input type="text" class="ss-color-hex" id="hex-accent" value="#CC0000" oninput="updateColorPicker('accent', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Button Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-button" value="#002F6C" onchange="updateColorHex('button', this.value)">
            <input type="text" class="ss-color-hex" id="hex-button" value="#002F6C" oninput="updateColorPicker('button', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Sidebar Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-sidebar" value="#00264D" onchange="updateColorHex('sidebar', this.value)">
            <input type="text" class="ss-color-hex" id="hex-sidebar" value="#00264D" oninput="updateColorPicker('sidebar', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Success Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-success" value="#16a34a" onchange="updateColorHex('success', this.value)">
            <input type="text" class="ss-color-hex" id="hex-success" value="#16a34a" oninput="updateColorPicker('success', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Warning Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-warning" value="#d97706" onchange="updateColorHex('warning', this.value)">
            <input type="text" class="ss-color-hex" id="hex-warning" value="#d97706" oninput="updateColorPicker('warning', this.value)">
          </div>
        </div>

        <div class="ss-color-item">
          <label>Danger Color</label>
          <div class="ss-color-picker-wrap">
            <input type="color" id="color-danger" value="#dc2626" onchange="updateColorHex('danger', this.value)">
            <input type="text" class="ss-color-hex" id="hex-danger" value="#dc2626" oninput="updateColorPicker('danger', this.value)">
          </div>
        </div>
      </div>

      <div class="ss-live-preview" id="color-preview">
        <div class="ss-preview-bar" id="preview-bar" style="background:#002F6C;">
          <i class="fas fa-tachometer-alt"></i> Dashboard Preview
        </div>
        <div class="ss-preview-body">
          <button class="ss-preview-btn" id="preview-btn-success" style="background:#16a34a;">Approve</button>
          <button class="ss-preview-btn" id="preview-btn-danger" style="background:#dc2626;">Reject</button>
          <button class="ss-preview-btn" id="preview-btn-accent" style="background:#CC0000;">View</button>
        </div>
      </div>

      <div style="margin-top:20px;">
        <button class="ss-btn ss-btn-primary" onclick="saveColorScheme()">
          <i class="fas fa-check"></i> Apply Color Scheme
        </button>
      </div>
    </div>


    <!-- ====================================================================
         🔹 LAYOUT SETTINGS
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-th-large"></i>Layout Settings</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Customize sidebar style, dashboard arrangement, font sizes, and scaling.
      </p>

      <div class="ss-form-group">
        <label><i class="fas fa-bars"></i> Sidebar Style</label>
        <select id="sidebar-style" class="ss-form-control">
          <option value="inline">Inline Navigation</option>
          <option value="stacked">Stacked Navigation</option>
          <option value="compact">Compact Mode</option>
        </select>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-grip-horizontal"></i> Dashboard Card Arrangement</label>
        <select id="card-arrangement" class="ss-form-control">
          <option value="grid">Grid Layout (2 columns)</option>
          <option value="list">List Layout (1 column)</option>
          <option value="masonry">Masonry Layout</option>
        </select>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-text-height"></i> Base Font Size</label>
        <div class="ss-slider-wrap">
          <input type="range" id="font-size-slider" class="ss-slider" min="12" max="18" value="14" step="1" oninput="updateFontPreview(this.value)">
          <span class="ss-slider-value" id="font-size-value">14px</span>
        </div>
        <div class="ss-font-preview" id="font-preview" style="font-size:14px;">
          This is a preview of how text will appear at the selected font size.
        </div>
      </div>

      <div style="margin-top:20px;">
        <button class="ss-btn ss-btn-primary" onclick="saveLayoutSettings()">
          <i class="fas fa-save"></i> Save Layout Settings
        </button>
        <button class="ss-btn ss-btn-secondary" onclick="previewLayout()">
          <i class="fas fa-eye"></i> Preview Layout
        </button>
      </div>
    </div>


    <!-- ====================================================================
         🔹 ACCESSIBILITY OPTIONS
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-universal-access"></i>Accessibility Options</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Enable accessibility features for better visibility and usability.
      </p>

      <div class="ss-toggle-wrap">
        <div class="ss-toggle">
          <input type="checkbox" id="high-contrast-toggle">
          <span class="ss-toggle-slider" onclick="toggleHighContrast()"></span>
        </div>
        <label for="high-contrast-toggle" style="cursor:pointer;margin:0;font-size:14px;">
          <i class="fas fa-adjust"></i> Enable High Contrast Mode
        </label>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-font"></i> Text Scaling (Accessibility)</label>
        <div class="ss-slider-wrap">
          <input type="range" id="accessibility-font-scale" class="ss-slider" min="100" max="150" value="100" step="10" oninput="updateAccessibilityScale(this.value)">
          <span class="ss-slider-value" id="accessibility-scale-value">100%</span>
        </div>
        <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
          Scales all text for users with visual impairments. 100% = default, 150% = maximum
        </p>
      </div>

      <div class="ss-toggle-wrap">
        <div class="ss-toggle">
          <input type="checkbox" id="focus-indicators-toggle">
          <span class="ss-toggle-slider" onclick="toggleFocusIndicators()"></span>
        </div>
        <label for="focus-indicators-toggle" style="cursor:pointer;margin:0;font-size:14px;">
          <i class="fas fa-crosshairs"></i> Enhanced Focus Indicators
        </label>
      </div>

      <div class="ss-toggle-wrap">
        <div class="ss-toggle">
          <input type="checkbox" id="reduce-motion-toggle">
          <span class="ss-toggle-slider" onclick="toggleReduceMotion()"></span>
        </div>
        <label for="reduce-motion-toggle" style="cursor:pointer;margin:0;font-size:14px;">
          <i class="fas fa-ban"></i> Reduce Motion / Animations
        </label>
      </div>

      <div style="margin-top:20px;padding:16px;background:#fef3c7;border-left:4px solid #d97706;border-radius:8px;">
        <p style="font-size:13px;color:#92400e;margin:0;">
          <i class="fas fa-info-circle"></i> <strong>Preview Mode:</strong> Enable settings above to see changes before applying.
        </p>
      </div>

      <div style="margin-top:20px;">
        <button class="ss-btn ss-btn-success" onclick="saveAccessibilitySettings()">
          <i class="fas fa-check-circle"></i> Enable Accessibility
        </button>
        <button class="ss-btn ss-btn-secondary" onclick="resetAccessibilityDefaults()">
          <i class="fas fa-undo"></i> Reset to Defaults
        </button>
      </div>
    </div>

    <!-- ====================================================================
         🔹 SYSTEM PREFERENCES
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-sliders-h"></i>System Preferences</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Configure system-wide preferences for language, timezone, notifications, and default views.
      </p>

      <div class="ss-form-group">
        <label><i class="fas fa-language"></i> Language Settings</label>
        <select id="system-language" class="ss-form-control">
          <option value="en">English</option>
          <option value="tl">Tagalog</option>
          <option value="ceb">Cebuano</option>
        </select>
        <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
          Choose default language for UI elements and system messages
        </p>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-clock"></i> Time Zone Settings</label>
        <select id="system-timezone" class="ss-form-control">
          <option value="Asia/Manila" selected>Asia/Manila (PHT - GMT+8)</option>
          <option value="Asia/Hong_Kong">Asia/Hong_Kong (HKT - GMT+8)</option>
          <option value="Asia/Singapore">Asia/Singapore (SGT - GMT+8)</option>
          <option value="Asia/Tokyo">Asia/Tokyo (JST - GMT+9)</option>
        </select>
        <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
          Set system time zone per station for accurate timestamps
        </p>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-bell"></i> Notification Preferences</label>
        
        <div class="ss-toggle-wrap">
          <div class="ss-toggle">
            <input type="checkbox" id="email-notifications-toggle" checked>
            <span class="ss-toggle-slider" onclick="toggleEmailNotifications()"></span>
          </div>
          <label for="email-notifications-toggle" style="cursor:pointer;margin:0;font-size:14px;">
            <i class="fas fa-envelope"></i> Enable Email Alerts
          </label>
        </div>

        <div class="ss-toggle-wrap">
          <div class="ss-toggle">
            <input type="checkbox" id="sms-notifications-toggle">
            <span class="ss-toggle-slider" onclick="toggleSMSNotifications()"></span>
          </div>
          <label for="sms-notifications-toggle" style="cursor:pointer;margin:0;font-size:14px;">
            <i class="fas fa-sms"></i> Enable SMS Alerts
          </label>
        </div>

        <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
          Toggle email/SMS alerts for system notifications and critical updates
        </p>
      </div>

      <div class="ss-form-group">
        <label><i class="fas fa-home"></i> Default Station View</label>
        <select id="default-station-view" class="ss-form-control">
          <option value="0">All Stations (Global View)</option>
          <?php foreach ($stations as $st): ?>
            <option value="<?php echo htmlspecialchars($st['id']); ?>">
              <?php echo htmlspecialchars($st['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p style="font-size:11px;color:var(--text-secondary);margin-top:6px;">
          Define default landing dashboard station for system users
        </p>
      </div>

      <div style="margin-top:20px;">
        <button class="ss-btn ss-btn-primary" onclick="saveSystemPreferences()">
          <i class="fas fa-save"></i> Save Preferences
        </button>
      </div>
    </div>

    <!-- ====================================================================
         🔹 AUDIT & COMPLIANCE
    ======================================================================= -->
    <div class="ss-card">
      <h3 class="ss-card-title"><i class="fas fa-shield-alt"></i>Audit & Compliance</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">
        Track configuration changes, export settings, and restore system defaults.
      </p>

      <!-- Change Logs Table -->
      <div style="margin-bottom:24px;">
        <h4 style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:12px;">
          <i class="fas fa-history"></i> Recent Configuration Changes
        </h4>
        <div style="overflow:hidden;border-radius:8px;border:1px solid var(--border-color);">
          <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
              <tr style="background:var(--page-bg);">
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">Date/Time</th>
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">User</th>
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">Category</th>
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">Setting Changed</th>
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">Old Value</th>
                <th style="padding:10px 12px;text-align:left;font-weight:600;border-bottom:1px solid var(--border-color);">New Value</th>
              </tr>
            </thead>
            <tbody id="audit-logs-tbody">
              <tr>
                <td colspan="6" style="padding:20px;text-align:center;color:var(--text-secondary);">
                  <i class="fas fa-spinner fa-spin"></i> Loading change logs...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Export & Restore Actions -->
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px;">
        <button class="ss-btn ss-btn-success" onclick="exportSettings('excel')">
          <i class="fas fa-file-excel"></i> Export to Excel
        </button>
        <button class="ss-btn ss-btn-success" onclick="exportSettings('pdf')">
          <i class="fas fa-file-pdf"></i> Export to PDF
        </button>
        <button class="ss-btn ss-btn-secondary" onclick="exportSettings('json')">
          <i class="fas fa-file-code"></i> Export to JSON
        </button>
      </div>

      <div style="padding:16px;background:#fee2e2;border-left:4px solid #dc2626;border-radius:8px;margin-bottom:16px;">
        <p style="font-size:13px;color:#991b1b;margin:0;">
          <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Restoring defaults will reset all custom settings. This action cannot be undone.
        </p>
      </div>

      <div style="display:flex;gap:12px;">
        <button class="ss-btn ss-btn-danger" onclick="confirmRestoreDefaults()">
          <i class="fas fa-undo-alt"></i> Restore System Defaults
        </button>
        <button class="ss-btn ss-btn-secondary" onclick="loadAuditLogs()">
          <i class="fas fa-sync"></i> Refresh Logs
        </button>
      </div>
    </div>


  </main>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   SYSTEM SETTINGS — JavaScript (Estate Form - All Features)
   API: backend/api/system_settings_api.php
═══════════════════════════════════════════════════════════════════════════ */

const API = '../backend/api/system_settings_api.php';
const STATIONS = <?php echo json_encode($stations); ?>;
let currentStationId = 0;
let currentLogoFile = null;

/* ── Utility: HTML Escape ─────────────────────────────────────────────────── */
function escHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

/* ── Toast Notifications ──────────────────────────────────────────────────── */
function showToast(message, type = 'success', duration = 3500) {
    const icons = {
        success: 'fas fa-check-circle',
        error:   'fas fa-times-circle',
        warning: 'fas fa-exclamation-triangle',
        info:    'fas fa-info-circle',
    };
    const container = document.getElementById('ss-toast-container');
    const toast = document.createElement('div');
    toast.className = `ss-toast ${type}`;
    toast.innerHTML = `<i class="${icons[type] || icons.info}"></i><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(30px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 320);
    }, duration);
}

/* ── Button Loading State ─────────────────────────────────────────────────── */
function setBtnLoading(btnId, loading) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    if (loading) {
        btn._origHTML = btn.innerHTML;
        btn.innerHTML = '<span class="ss-spinner"></span> Saving…';
        btn.disabled = true;
    } else {
        btn.innerHTML = btn._origHTML || btn.innerHTML;
        btn.disabled = false;
    }
}


/* ══════════════════════════════════════════════════════════════════════════
   🔹 STATION SELECTOR
══════════════════════════════════════════════════════════════════════════ */
function showStationDropdown() {
    const dropdown = document.getElementById('stationDropdown');
    if (!dropdown) return;
    
    dropdown.innerHTML = '';
    
    // Global option
    const globalOpt = document.createElement('div');
    globalOpt.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;';
    globalOpt.innerHTML = '<i class="fas fa-globe" style="color:#3b82f6;margin-right:8px;"></i>Global (all stations)';
    globalOpt.onclick = () => selectStation(0, 'Global');
    globalOpt.onmouseenter = function() { this.style.background = '#f3f4f6'; };
    globalOpt.onmouseleave = function() { this.style.background = 'transparent'; };
    dropdown.appendChild(globalOpt);

    // Station options
    STATIONS.forEach(st => {
        const opt = document.createElement('div');
        opt.style.cssText = 'padding:10px 14px;cursor:pointer;border-top:1px solid var(--border-color);font-size:13px;';
        opt.innerHTML = `<i class="fas fa-building" style="color:#6b7280;margin-right:8px;"></i>${escHtml(st.name)} <span style="font-size:11px;color:#9ca3af;">(${escHtml(st.address)})</span>`;
        opt.onclick = () => selectStation(st.id, st.name);
        opt.onmouseenter = function() { this.style.background = '#f3f4f6'; };
        opt.onmouseleave = function() { this.style.background = 'transparent'; };
        dropdown.appendChild(opt);
    });
    
    dropdown.style.display = 'block';
}

function selectStation(id, name) {
    currentStationId = id;
    document.getElementById('selectedStationId').value = id;
    document.getElementById('stationSearchInput').value = name === 'Global' ? '' : name;
    document.getElementById('stationDropdown').style.display = 'none';

    const clearBtn = document.getElementById('clearStationBtn');
    const banner = document.getElementById('selectedStationBanner');
    const bannerName = document.getElementById('selectedStationName');

    if (id > 0) {
        if (clearBtn) clearBtn.style.display = 'inline-flex';
        if (banner) banner.style.display = 'block';
        if (bannerName) bannerName.textContent = name;
    } else {
        if (clearBtn) clearBtn.style.display = 'none';
        if (banner) banner.style.display = 'none';
    }

    showToast(`Settings scope: ${name}`, 'info');
    loadAllSettings();
}

function clearStationFilter() {
    selectStation(0, 'Global');
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    const container = document.getElementById('station-selector-card');
    const dropdown = document.getElementById('stationDropdown');
    if (container && !container.contains(e.target) && dropdown) {
        dropdown.style.display = 'none';
    }
});


/* ══════════════════════════════════════════════════════════════════════════
   🔹 LOGO MANAGEMENT
══════════════════════════════════════════════════════════════════════════ */
function handleLogoUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
        showToast('File size exceeds 2MB limit', 'error');
        return;
    }

    // Validate file type
    if (!file.type.startsWith('image/')) {
        showToast('Please upload a valid image file', 'error');
        return;
    }

    currentLogoFile = file;

    // Preview the image
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('logo-preview-img').src = e.target.result;
    };
    reader.readAsDataURL(file);

    showToast('Logo ready to upload. Click "Apply Logo" to save.', 'info');
}

async function saveLogo() {
    if (!currentLogoFile) {
        showToast('Please select a logo file first', 'warning');
        return;
    }

    setBtnLoading('save-logo-btn', true);

    const formData = new FormData();
    formData.append('action', 'upload_logo');
    formData.append('station_id', currentStationId);
    formData.append('logo', currentLogoFile);

    try {
        const res = await fetch(API, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            showToast('Logo uploaded successfully!', 'success');
            currentLogoFile = null;
            document.getElementById('logo-upload-input').value = '';
        } else {
            showToast(data.message || 'Failed to upload logo', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    } finally {
        setBtnLoading('save-logo-btn', false);
    }
}

async function removeLogo() {
    if (!confirm('Are you sure you want to remove the current logo?')) return;

    try {
        const res = await fetch(`${API}?action=remove_logo&station_id=${currentStationId}`);
        const data = await res.json();
        
        if (data.success) {
            showToast('Logo removed successfully', 'success');
            document.getElementById('logo-preview-img').src = '../assets/img/Petron Logo.png';
        } else {
            showToast(data.message || 'Failed to remove logo', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}


/* ══════════════════════════════════════════════════════════════════════════
   🔹 COLOR THEME / UI SCHEME
══════════════════════════════════════════════════════════════════════════ */
function updateColorHex(name, value) {
    document.getElementById(`hex-${name}`).value = value.toUpperCase();
    updatePreview();
}

function updateColorPicker(name, value) {
    if (/^#[0-9A-F]{6}$/i.test(value)) {
        document.getElementById(`color-${name}`).value = value;
        updatePreview();
    }
}

function updatePreview() {
    const primary = document.getElementById('color-primary').value;
    const accent = document.getElementById('color-accent').value;
    const success = document.getElementById('color-success').value;
    const danger = document.getElementById('color-danger').value;

    document.getElementById('preview-bar').style.background = primary;
    document.getElementById('preview-btn-success').style.background = success;
    document.getElementById('preview-btn-danger').style.background = danger;
    document.getElementById('preview-btn-accent').style.background = accent;
}

async function saveColorScheme() {
    const colors = {
        primary: document.getElementById('color-primary').value,
        button:  document.getElementById('color-button').value,
        sidebar: document.getElementById('color-sidebar').value,
        accent:  document.getElementById('color-accent').value,
        success: document.getElementById('color-success').value,
        warning: document.getElementById('color-warning').value,
        danger:  document.getElementById('color-danger').value
    };

    try {
        const res = await fetch(`${API}?action=save_colors&station_id=${currentStationId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ colors })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Color scheme applied! Reloading...', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Failed to save color scheme', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}


/* ══════════════════════════════════════════════════════════════════════════
   🔹 LAYOUT SETTINGS
══════════════════════════════════════════════════════════════════════════ */
function updateFontPreview(value) {
    document.getElementById('font-size-value').textContent = value + 'px';
    document.getElementById('font-preview').style.fontSize = value + 'px';
}

async function saveLayoutSettings() {
    const settings = {
        sidebar_style:    document.getElementById('sidebar-style').value,
        card_arrangement: document.getElementById('card-arrangement').value,
        base_font_size:   document.getElementById('font-size-slider').value
    };

    try {
        const res = await fetch(`${API}?action=save_layout&station_id=${currentStationId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Layout settings saved successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to save layout settings', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}

function previewLayout() {
    showToast('Preview mode activated. Review changes before saving.', 'info', 5000);
    // Apply temporary preview styles
    const fontSize = document.getElementById('font-size-slider').value + 'px';
    document.documentElement.style.fontSize = fontSize;
}


/* ══════════════════════════════════════════════════════════════════════════
   🔹 ACCESSIBILITY OPTIONS
══════════════════════════════════════════════════════════════════════════ */
function toggleHighContrast() {
    const toggle = document.getElementById('high-contrast-toggle');
    toggle.checked = !toggle.checked;
    
    if (toggle.checked) {
        document.body.classList.add('high-contrast-mode');
        showToast('High contrast mode enabled', 'info');
    } else {
        document.body.classList.remove('high-contrast-mode');
        showToast('High contrast mode disabled', 'info');
    }
}

function updateAccessibilityScale(value) {
    document.getElementById('accessibility-scale-value').textContent = value + '%';
    // Preview the scaling
    const scale = value / 100;
    document.documentElement.style.setProperty('--accessibility-scale', scale);
}

function toggleFocusIndicators() {
    const toggle = document.getElementById('focus-indicators-toggle');
    toggle.checked = !toggle.checked;
    
    if (toggle.checked) {
        document.body.classList.add('enhanced-focus');
        showToast('Enhanced focus indicators enabled', 'info');
    } else {
        document.body.classList.remove('enhanced-focus');
        showToast('Enhanced focus indicators disabled', 'info');
    }
}

function toggleReduceMotion() {
    const toggle = document.getElementById('reduce-motion-toggle');
    toggle.checked = !toggle.checked;
    
    if (toggle.checked) {
        document.body.classList.add('reduce-motion');
        showToast('Reduced motion enabled', 'info');
    } else {
        document.body.classList.remove('reduce-motion');
        showToast('Reduced motion disabled', 'info');
    }
}

async function saveAccessibilitySettings() {
    const settings = {
        high_contrast:    document.getElementById('high-contrast-toggle').checked,
        font_scale:       document.getElementById('accessibility-font-scale').value,
        focus_indicators: document.getElementById('focus-indicators-toggle').checked,
        reduce_motion:    document.getElementById('reduce-motion-toggle').checked
    };

    try {
        const res = await fetch(`${API}?action=save_accessibility&station_id=${currentStationId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings })
        });
        const data = await res.json();
        if (data.success) {
            showToast('Accessibility settings saved!', 'success');
        } else {
            showToast(data.message || 'Failed to save accessibility settings', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}

function resetAccessibilityDefaults() {
    document.getElementById('high-contrast-toggle').checked = false;
    document.getElementById('accessibility-font-scale').value = 100;
    document.getElementById('accessibility-scale-value').textContent = '100%';
    document.getElementById('focus-indicators-toggle').checked = false;
    document.getElementById('reduce-motion-toggle').checked = false;
    
    document.body.classList.remove('high-contrast-mode', 'enhanced-focus', 'reduce-motion');
    document.documentElement.style.removeProperty('--accessibility-scale');
    
    showToast('Reset to default accessibility settings', 'info');
}


/* ══════════════════════════════════════════════════════════════════════════
   🔹 LOAD ALL SETTINGS
══════════════════════════════════════════════════════════════════════════ */
async function loadAllSettings() {
    try {
        const res  = await fetch(`${API}?action=get_settings&station_id=${currentStationId}`);
        const data = await res.json();

        if (!data.success || !data.settings) return;
        const s = data.settings;

        // ── Colors ────────────────────────────────────────────────────────
        if (s.colors) {
            ['primary','button','sidebar','accent','success','warning','danger'].forEach(name => {
                if (s.colors[name]) {
                    const picker = document.getElementById(`color-${name}`);
                    const hex    = document.getElementById(`hex-${name}`);
                    if (picker) picker.value = s.colors[name];
                    if (hex)    hex.value    = s.colors[name].toUpperCase();
                }
            });
            updatePreview();
        }

        // ── Layout ────────────────────────────────────────────────────────
        if (s.layout) {
            if (s.layout.sidebar_style)    document.getElementById('sidebar-style').value    = s.layout.sidebar_style;
            if (s.layout.card_arrangement) document.getElementById('card-arrangement').value = s.layout.card_arrangement;
            if (s.layout.base_font_size) {
                document.getElementById('font-size-slider').value = s.layout.base_font_size;
                updateFontPreview(s.layout.base_font_size);
            }
        }

        // ── Accessibility ─────────────────────────────────────────────────
        if (s.accessibility) {
            const hc = s.accessibility.high_contrast;
            document.getElementById('high-contrast-toggle').checked     = (hc === '1' || hc === true);
            document.getElementById('focus-indicators-toggle').checked  = (s.accessibility.focus_indicators === '1' || s.accessibility.focus_indicators === true);
            document.getElementById('reduce-motion-toggle').checked     = (s.accessibility.reduce_motion   === '1' || s.accessibility.reduce_motion   === true);
            if (s.accessibility.font_scale) {
                document.getElementById('accessibility-font-scale').value = s.accessibility.font_scale;
                updateAccessibilityScale(s.accessibility.font_scale);
            }
        }

        // ── Preferences ───────────────────────────────────────────────────
        if (s.preferences) {
            const p = s.preferences;
            if (p.language)        document.getElementById('system-language').value        = p.language;
            if (p.timezone)        document.getElementById('system-timezone').value        = p.timezone;
            if (p.default_station) document.getElementById('default-station-view').value  = p.default_station;
            const emailT = document.getElementById('email-notifications-toggle');
            const smsT   = document.getElementById('sms-notifications-toggle');
            if (emailT) emailT.checked = (p.email_notifications === '1' || p.email_notifications === true);
            if (smsT)   smsT.checked   = (p.sms_notifications   === '1' || p.sms_notifications   === true);
        }

        // ── Logo ──────────────────────────────────────────────────────────
        if (s.logo_url) {
            document.getElementById('logo-preview-img').src = s.logo_url;
        }

    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   🔹 SYSTEM PREFERENCES
══════════════════════════════════════════════════════════════════════════ */
function toggleEmailNotifications() {
    const toggle = document.getElementById('email-notifications-toggle');
    toggle.checked = !toggle.checked;
    showToast(toggle.checked ? 'Email notifications enabled' : 'Email notifications disabled', 'info');
}

function toggleSMSNotifications() {
    const toggle = document.getElementById('sms-notifications-toggle');
    toggle.checked = !toggle.checked;
    showToast(toggle.checked ? 'SMS notifications enabled' : 'SMS notifications disabled', 'info');
}

async function saveSystemPreferences() {
    const preferences = {
        language:            document.getElementById('system-language').value,
        timezone:            document.getElementById('system-timezone').value,
        email_notifications: document.getElementById('email-notifications-toggle').checked,
        sms_notifications:   document.getElementById('sms-notifications-toggle').checked,
        default_station:     document.getElementById('default-station-view').value
    };

    try {
        const res = await fetch(`${API}?action=save_preferences&station_id=${currentStationId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ preferences })
        });
        const data = await res.json();
        if (data.success) {
            showToast('System preferences saved successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to save preferences', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   🔹 AUDIT & COMPLIANCE
══════════════════════════════════════════════════════════════════════════ */
async function loadAuditLogs() {
    const tbody = document.getElementById('audit-logs-tbody');
    if (!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="6" style="padding:20px;text-align:center;color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading change logs...</td></tr>';
    
    try {
        const res = await fetch(`${API}?action=get_audit_logs&station_id=${currentStationId}&limit=20`);
        const data = await res.json();
        
        if (data.success && data.logs && data.logs.length > 0) {
            let html = '';
            data.logs.forEach(log => {
                html += `<tr style="border-bottom:1px solid var(--border-color);">
                    <td style="padding:10px 12px;">${log.timestamp}</td>
                    <td style="padding:10px 12px;">${log.user}</td>
                    <td style="padding:10px 12px;"><span style="padding:3px 8px;background:#dbeafe;color:#1e40af;border-radius:12px;font-size:11px;font-weight:600;">${log.category}</span></td>
                    <td style="padding:10px 12px;">${log.setting}</td>
                    <td style="padding:10px 12px;color:#dc2626;">${log.old_value || '-'}</td>
                    <td style="padding:10px 12px;color:#16a34a;font-weight:600;">${log.new_value}</td>
                </tr>`;
            });
            tbody.innerHTML = html;
        } else {
            tbody.innerHTML = '<tr><td colspan="6" style="padding:20px;text-align:center;color:var(--text-secondary);">No change logs found</td></tr>';
        }
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding:20px;text-align:center;color:#dc2626;">Failed to load logs</td></tr>';
    }
}

async function exportSettings(format) {
    showToast(`Exporting settings as ${format.toUpperCase()}...`, 'info');
    
    try {
        const res = await fetch(`${API}?action=export_settings&station_id=${currentStationId}&format=${format}`);
        const blob = await res.blob();
        
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `system_settings_${currentStationId}_${new Date().toISOString().split('T')[0]}.${format}`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
        
        showToast('Settings exported successfully!', 'success');
    } catch (error) {
        showToast('Export failed: ' + error.message, 'error');
    }
}

function confirmRestoreDefaults() {
    if (!confirm('⚠️ WARNING: This will reset ALL custom settings to system defaults. This action CANNOT be undone.\n\nAre you sure you want to continue?')) {
        return;
    }
    
    if (!confirm('Final confirmation: Restore all settings to defaults?')) {
        return;
    }
    
    restoreDefaults();
}

async function restoreDefaults() {
    showToast('Restoring system defaults...', 'info');
    try {
        const res = await fetch(`${API}?action=restore_defaults&station_id=${currentStationId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        });
        const data = await res.json();
        if (data.success) {
            showToast('Settings restored to defaults successfully!', 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showToast(data.message || 'Failed to restore defaults', 'error');
        }
    } catch (error) {
        showToast('Network error: ' + error.message, 'error');
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   🔹 INITIALIZE ON PAGE LOAD
══════════════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    loadAllSettings();
    updatePreview();
    loadAuditLogs();
});

</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
