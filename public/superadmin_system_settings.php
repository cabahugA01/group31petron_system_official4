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

/* -- Sidebar Protection (HARDCODED - immune to generate_theme_css.php) --------
   IMPORTANT: Do NOT use CSS variables here - generate_theme_css.php overwrites
   --sidebar-bg with var(--gradient-sidebar) which can become light/white.     */
aside.sidebar,
#mainSidebar,
.sidebar {
    background-color: #00264D !important;
    background: #00264D !important;
    background-image: none !important;
}

/* Force ALL sidebar icons to stay white - overrides .fas/.far/.fab in generate_theme_css */
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

.sidebar .nav-item:hover span,
.sidebar .nav-item:hover i {
    color: #ffffff !important;
}

.sidebar .nav-item.active,
.sidebar a.nav-item.active {
    background-color: #CC0000 !important;
    background: #CC0000 !important;
    color: #ffffff !important;
}

.sidebar .nav-item.active span,
.sidebar .nav-item.active i,
.sidebar .nav-item.active .ico i {
    color: #ffffff !important;
}

/* Sub-menu items */
.sidebar .sidebar-sub-item {
    color: rgba(238,238,238,0.85) !important;
    background-color: transparent !important;
}

.sidebar .sidebar-sub-item:hover {
    background-color: rgba(255,255,255,0.10) !important;
    color: #ffffff !important;
}

.sidebar .sidebar-sub-item.active {
    background-color: #CC0000 !important;
    color: #ffffff !important;
}

/* -- Page Layout ------------------------------------------------------------- */
.ss-wrapper {
    display: block;
    min-height: calc(100vh - 140px);
    background: var(--page-bg);
    position: relative;
    width: 100%;
}

/* -- Hide Settings Sidebar (Use Main Sidebar Only) -------------------------- */
.ss-sidebar {
    display: none !important;
}

.ss-sidebar-header {
    display: none !important;
}

.ss-nav-item {
    display: none !important;
}

.ss-nav-step-num {
    display: none !important;
}

/* -- Main Content ------------------------------------------------------------ */
.ss-content {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 28px 32px;
    overflow-y: auto;
    position: relative;
    background: var(--page-bg);
}

.ss-panel {
    display: none;
}

.ss-panel.active {
    display: block;
    animation: fadeInPanel 0.25s ease;
}

/* Prevent duplicate navigation in content area */
.ss-panel .ss-sidebar,
.ss-panel .ss-nav-item {
    display: none !important;
}

.ss-panel > nav,
.ss-panel > .ss-sidebar-header {
    display: none !important;
}

/* Ensure only main sidebar is visible */
.ss-wrapper > .ss-sidebar {
    display: block !important;
}

/* Hide any navigation that might appear in content */
.ss-content .ss-sidebar,
.ss-content .ss-nav-item,
.ss-content nav,
.ss-content .ss-sidebar-header,
.ss-content .ss-nav-icon,
.ss-content .ss-nav-step-num {
    display: none !important;
}

/* Completely remove any navigation inside content panels */
.ss-panel .ss-sidebar,
.ss-panel nav,
.ss-panel .ss-sidebar-header,
.ss-panel .ss-nav-item,
.ss-panel .ss-nav-icon,
.ss-panel .ss-nav-step-num {
    display: none !important;
    visibility: hidden !important;
    opacity: 0 !important;
    height: 0 !important;
    width: 0 !important;
    overflow: hidden !important;
}

/* Ensure only main sidebar navigation is visible */
.ss-wrapper > .ss-sidebar {
    display: block !important;
    visibility: visible !important;
    opacity: 1 !important;
    height: auto !important;
    width: 260px !important;
    overflow: visible !important;
}

@keyframes fadeInPanel {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
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
    color: var(--text-primary) !important;
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
}

.ss-panel-header h1 {
    font-size: 22px !important;
    font-weight: 700 !important;
    color: var(--text-primary) !important;
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

/* Tab button active state */
.ss-btn.active {
    background: var(--primary-color);
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,47,108,0.3);
}

.ss-btn-primary {
    background: var(--primary-color);
    color: #fff;
}

.ss-btn-primary:hover {
    background: #003d8f;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,47,108,0.3);
}

.ss-btn-secondary {
    background: var(--page-bg);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.ss-btn-secondary:hover {
    background: #e5e7eb;
}

.ss-btn-danger {
    background: #dc2626;
    color: #fff;
}

.ss-btn-danger:hover {
    background: #b91c1c;
}

.ss-btn-success {
    background: #16a34a;
    color: #fff;
}

.ss-btn-success:hover {
    background: #15803d;
}

/* -- Toggle Switch ----------------------------------------------------------- */
.ss-toggle-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.ss-toggle-wrap label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    cursor: pointer;
    margin: 0;
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
    pointer-events: none;
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
    min-width: 280px;
    max-width: 380px;
    pointer-events: all;
    animation: toastIn 0.3s ease;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

.ss-toast.error   { border-left-color: #dc2626; }
.ss-toast.warning { border-left-color: #d97706; }
.ss-toast.info    { border-left-color: #2563eb; }

.ss-toast i { font-size: 18px; }
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
    width: 180px;
    height: 120px;
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

/* -- Theme Preset Cards ------------------------------------------------------ */
.ss-theme-presets {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.ss-preset-card {
    width: 110px;
    border-radius: 10px;
    border: 2px solid var(--border-color);
    cursor: pointer;
    overflow: hidden;
    transition: all var(--transition);
    text-align: center;
}

.ss-preset-card:hover {
    border-color: var(--primary-color);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.ss-preset-card.selected {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(0,47,108,0.15);
}

.ss-preset-swatch {
    height: 50px;
    width: 100%;
}

.ss-preset-label {
    padding: 6px 4px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.3px;
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
    background: none;
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
}

.ss-preview-bar {
    height: 40px;
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #fff;
}

.ss-preview-body {
    padding: 16px;
    background: #f8fafc;
    display: flex;
    gap: 10px;
}

.ss-preview-btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: default;
    color: #fff;
}

.ss-preview-accent-btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    cursor: default;
    color: #fff;
}

/* -- Sidebar Style Toggle ---------------------------------------------------- */
.ss-sidebar-style-options {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

.ss-sidebar-option {
    border: 2px solid var(--border-color);
    border-radius: 10px;
    padding: 16px;
    cursor: pointer;
    transition: all var(--transition);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    width: 140px;
}

.ss-sidebar-option:hover,
.ss-sidebar-option.selected {
    border-color: var(--primary-color);
    background: rgba(0,47,108,0.04);
}

.ss-sidebar-option input[type="radio"] {
    accent-color: var(--primary-color);
}

.ss-sidebar-preview {
    width: 100px;
    height: 60px;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #e5e7eb;
    display: flex;
}

.ss-sidebar-preview-bar {
    background: #002F6C;
    height: 100%;
}

.ss-sidebar-preview-content {
    flex: 1;
    background: #f4f6fb;
}

/* -- Drag & Drop Card Order -------------------------------------------------- */
.ss-sortable-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ss-sortable-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    background: var(--surface);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    cursor: grab;
    transition: all var(--transition);
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
}

.ss-sortable-item:hover {
    border-color: var(--primary-color);
    box-shadow: 0 2px 8px rgba(0,47,108,0.1);
}

.ss-sortable-item.dragging {
    opacity: 0.5;
    cursor: grabbing;
}

.ss-sortable-item.drag-over {
    border-color: var(--primary-color);
    background: rgba(0,47,108,0.04);
}

.ss-drag-handle {
    color: var(--text-secondary);
    font-size: 16px;
    cursor: grab;
}

/* -- Accessibility Slider ---------------------------------------------------- */
.ss-slider-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 4px;
}

.ss-slider {
    flex: 1;
    accent-color: var(--primary-color);
    height: 6px;
    cursor: pointer;
}

.ss-slider-value {
    font-size: 13px;
    font-weight: 700;
    color: var(--primary-color);
    min-width: 36px;
    text-align: right;
}

.ss-font-preview {
    padding: 12px 16px;
    background: var(--page-bg);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    margin-top: 8px;
    transition: font-size var(--transition);
}

/* -- Audit Table ------------------------------------------------------------- */
.ss-table-wrap {
    overflow-x: auto;
    border-radius: 10px;
    border: 1px solid var(--border-color);
}

.ss-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.ss-table thead th {
    background: var(--page-bg);
    padding: 12px 14px;
    text-align: left;
    font-weight: 700;
    color: var(--text-secondary);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}

.ss-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid #f3f4f6;
    color: var(--text-primary);
    vertical-align: middle;
}

.ss-table tbody tr:last-child td {
    border-bottom: none;
}

.ss-table tbody tr:hover td {
    background: rgba(0,47,108,0.02);
}

.ss-badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.ss-badge-branding    { background: #dbeafe; color: #1d4ed8; }
.ss-badge-theme       { background: #ede9fe; color: #6d28d9; }
.ss-badge-layout      { background: #d1fae5; color: #065f46; }
.ss-badge-accessibility { background: #fef3c7; color: #92400e; }
.ss-badge-general     { background: #f3f4f6; color: #374151; }

/* -- Audit Filters ----------------------------------------------------------- */
.ss-audit-filters {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 16px;
}

.ss-audit-filters select,
.ss-audit-filters input {
    padding: 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 13px;
    color: var(--text-primary);
    background: var(--surface);
}

.ss-audit-filters select:focus,
.ss-audit-filters input:focus {
    outline: none;
    border-color: var(--primary-color);
}

/* -- Pagination -------------------------------------------------------------- */
.ss-pagination {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: center;
    margin-top: 16px;
    flex-wrap: wrap;
}

.ss-page-btn {
    padding: 6px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--surface);
    color: var(--text-primary);
    font-size: 13px;
    cursor: pointer;
    transition: all var(--transition);
}

.ss-page-btn:hover,
.ss-page-btn.active {
    background: var(--primary-color);
    color: #fff;
    border-color: var(--primary-color);
}

.ss-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

/* -- Responsive -------------------------------------------------------------- */
@media (max-width: 768px) {
    .ss-wrapper {
        flex-direction: column;
    }
    .ss-sidebar {
        width: 100%;
        height: auto;
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.1);
        padding: 12px 0;
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    .ss-sidebar-header { display: none; }
    .ss-nav-item { padding: 10px 16px; }
    .ss-content { padding: 16px; }
    .ss-color-row { flex-direction: column; }
    .ss-theme-presets { gap: 8px; }
    .ss-preset-card { width: 90px; }
    
    /* Ensure no duplicate navigation in mobile content */
    .ss-content .ss-sidebar,
    .ss-content .ss-nav-item,
    .ss-content nav {
        display: none !important;
        visibility: hidden !important;
    }
}
</style>

<!-- -- Toast Container -------------------------------------------------------- -->
<div id="ss-toast-container"></div>

<!-- -- Page Wrapper ---------------------------------------------------------- -->
<div class="ss-wrapper">

  <!-- -- Main Content (No duplicate sidebar - use main sidebar only) ------------ -->
  <main class="ss-content" style="margin-left:0;width:100%;">

    <!-- -- Page Title ------------------------------------------------------------ -->
    <div class="ss-panel-header">
      <h1><i class="fas fa-cog" style="margin-right:8px;color:var(--primary-color);"></i>System Settings  -  Estate Form</h1>
      <p>Configure all system appearance, layout, and accessibility options in one view.</p>
    </div>

    <!-- ====================================================================
         🔹 STATION SELECTOR
    ======================================================================= -->
    <div class="ss-card" id="station-selector-card" style="margin-bottom:20px;">
      <h3 class="ss-card-title"><i class="fas fa-map-marker-alt"></i>Station Selection</h3>
      <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">
        Select a station to scope settings for that specific branch. Global settings apply to all stations.
      </p>
      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:260px;max-width:480px;">
          <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:13px;pointer-events:none;"></i>
          <input type="text" id="stationSearchInput" placeholder="Search stations..." autocomplete="off"
                 style="width:100%;padding:10px 14px 10px 36px;border:1px solid var(--border-color);border-radius:8px;font-size:13px;color:var(--text-primary);background:var(--surface);box-sizing:border-box;"
                 oninput="filterStations(this.value)">
          <div id="stationDropdown" style="display:none;position:absolute;top:calc(100%+4px);left:0;right:0;background:#fff;border:1px solid var(--border-color);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:9999;max-height:220px;overflow-y:auto;"></div>
        </div>
        <input type="hidden" id="selectedStationId" value="">
        <button class="ss-btn ss-btn-secondary" onclick="clearStationFilter()" id="clearStationBtn" style="display:none;">
          <i class="fas fa-times"></i> Clear
        </button>
        <span style="font-size:13px;color:var(--text-secondary);">
          <i class="fas fa-globe" style="color:#3b82f6;"></i> Global (all stations)
        </span>
      </div>
      <!-- Selected station banner -->
      <div id="selectedStationBanner" style="display:none;margin-top:12px;padding:10px 16px;background:#dbeafe;border-left:4px solid #3b82f6;border-radius:6px;font-size:13px;color:#1e40af;">
        <i class="fas fa-building"></i> Configuring for: <strong id="selectedStationName"></strong>
      </div>
    </div>

    <!-- Station data for JS -->
    <script>
/* ═══════════════════════════════════════════════════════════════════════════
   SYSTEM SETTINGS — JavaScript (Estate Form - All in One View)
   All API calls go to: backend/api/system_settings_api.php?action=...
═══════════════════════════════════════════════════════════════════════════ */

const API = '../backend/api/system_settings_api.php';

// Active station ID (0 = global)
let currentStationId = 0;

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

/* ── Set button loading state ────────────────────────────────────────────── */
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

/* ── Station Selector Logic (Dropdown list) ────────────────────────────────── */
function filterStations(query) {
    const dropdown = document.getElementById('stationDropdown');
    if (!dropdown) return;
    dropdown.innerHTML = '';
    
    // Add global option first
    const globalOpt = document.createElement('div');
    globalOpt.style.padding = '10px 14px';
    globalOpt.style.cursor = 'pointer';
    globalOpt.style.fontSize = '13px';
    globalOpt.innerHTML = '<i class="fas fa-globe" style="color:#3b82f6;margin-right:8px;"></i>Global (all stations)';
    globalOpt.onclick = () => selectStation(0, 'Global');
    dropdown.appendChild(globalOpt);

    const filtered = STATIONS.filter(s => 
        s.name.toLowerCase().includes(query.toLowerCase()) || 
        s.address.toLowerCase().includes(query.toLowerCase())
    );

    if (filtered.length > 0 && query !== '') {
        filtered.forEach(st => {
            const opt = document.createElement('div');
            opt.style.padding = '10px 14px';
            opt.style.cursor = 'pointer';
            opt.style.borderTop = '1px solid var(--border-color)';
            opt.style.fontSize = '13px';
            opt.innerHTML = `<i class="fas fa-building" style="color:#6b7280;margin-right:8px;"></i>\${escHtml(st.name)} <span style="font-size:11px;color:#9ca3af;">(\${escHtml(st.address)})</span>`;
            opt.onclick = () => selectStation(st.id, st.name);
            dropdown.appendChild(opt);
        });
    }
    
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
    const saveScopeLabel = document.getElementById('save-scope-label');

    if (id > 0) {
        if (clearBtn) clearBtn.style.display = 'inline-block';
        if (banner) banner.style.display = 'block';
        if (bannerName) bannerName.textContent = name;
        if (saveScopeLabel) saveScopeLabel.textContent = `Station: \${name}`;
    } else {
        if (clearBtn) clearBtn.style.display = 'none';
        if (banner) banner.style.display = 'none';
        if (saveScopeLabel) saveScopeLabel.textContent = 'all stations globally';
    }

    showToast(`Switched view to: \${name}`, 'info');
    
    // Reload configurations for the chosen station scope
    loadCurrentLogo();
    loadAllSettings();
    loadAudit(1);
}

function clearStationFilter() {
    selectStation(0, 'Global');
}

// Close dropdown on click outside
document.addEventListener('click', function(e) {
    const container = document.getElementById('station-selector-card');
    const dropdown = document.getElementById('stationDropdown');
    if (container && !container.contains(e.target) && dropdown) {
        dropdown.style.display = 'none';
    }
});

/* ── Load All Settings on Page Load or Station Change ────────────────────── */
async function loadAllSettings() {
    try {
        const res  = await fetch(`\${API}?action=get_all&station_id=\${currentStationId}`);
        const data = await res.json();
        if (!data.success) return;
        const s = data.settings || {};

        // Theme Colors
        setColorField('primary',   s.primary_color?.value || '#002F6C');
        setColorField('button',    s.button_color?.value || '#002F6C');
        setColorField('sidebar',   s.sidebar_color?.value || '#1a1a2e');

        // Layout
        const style = document.getElementById('sidebar-style');
        if (style) style.value = s.sidebar_style?.value || 'inline';

        const scale = document.getElementById('font-scale');
        if (scale) scale.value = s.font_scale_layout?.value || '100';

        // Accessibility
        document.getElementById('toggle-high-contrast').checked = (s.high_contrast?.value === '1' || s.high_contrast?.value === 'true');

        const slider = document.getElementById('accessibility-font-scale');
        if (slider) {
            slider.value = s.font_scale_accessibility?.value || '100';
            updateFontScaleValue();
        }
    } catch(e) {
        console.warn('Could not load settings:', e);
    }
}

/* ── Load Current Logo ────────────────────────────────────────────────────── */
async function loadCurrentLogo() {
    try {
        const res  = await fetch(`\${API}?action=get_logo&station_id=\${currentStationId}`);
        const data = await res.json();
        if (data.success && data.logo_url) {
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
        }
    } catch(e) {}
}

/* ── Logo: Preview before upload ─────────────────────────────────────────── */
function previewLogoFile(input) {
    const file = input.files[0];
    if (!file) return;

    if (file.size > 2 * 1024 * 1024) {
        showToast('File exceeds 2MB limit.', 'error');
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('current-logo-img').src = e.target.result;
        document.getElementById('btn-upload-logo').disabled = false;
    };
    reader.readAsDataURL(file);
}

function clearLogoInput() {
    document.getElementById('logo-file-input').value = '';
    document.getElementById('btn-upload-logo').disabled = true;
    loadCurrentLogo();
}

/* ── Logo: Upload ────────────────────────────────────────────────────────── */
async function uploadLogo() {
    const input = document.getElementById('logo-file-input');
    if (!input.files[0]) { showToast('Please select a file first.', 'warning'); return; }

    setBtnLoading('btn-upload-logo', true);
    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('station_id', currentStationId);

    try {
        const res  = await fetch(`\${API}?action=save_logo`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Logo updated successfully!', 'success');
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
            clearLogoInput();
        } else {
            showToast(data.message || 'Upload failed.', 'error');
        }
    } catch(e) {
        showToast('Network error. Please try again.', 'error');
    } finally {
        setBtnLoading('btn-upload-logo', false);
    }
}

/* ── Logo: Reset to Default ──────────────────────────────────────────────── */
async function resetLogo() {
    if (!confirm('Reset logo to the default Petron logo?')) return;
    setBtnLoading('btn-reset-logo', true);
    
    const formData = new FormData();
    formData.append('station_id', currentStationId);

    try {
        const res  = await fetch(`\${API}?action=reset_logo`, { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            showToast('Logo reset to default.', 'success');
            document.getElementById('current-logo-img').src = '../' + data.logo_url + '?t=' + Date.now();
        } else {
            showToast(data.message || 'Reset failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    } finally {
        setBtnLoading('btn-reset-logo', false);
    }
}

/* ── Theme: Color helpers ─────────────────────────────────────────────────── */
function setColorField(name, value) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (picker) picker.value = value;
    if (hex)    hex.value    = value;
}

function syncColorHex(name) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (picker && hex) hex.value = picker.value;
}

function syncColorPicker(name) {
    const picker = document.getElementById(`color-\${name}`);
    const hex    = document.getElementById(`hex-\${name}`);
    if (hex && picker && /^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
        picker.value = hex.value;
    }
}

/* ── Apply Color Scheme ───────────────────────────────────────────────────── */
async function applyColorScheme() {
    const payload = {
        primary_color: document.getElementById('hex-primary')?.value || '#002F6C',
        button_color: document.getElementById('hex-button')?.value || '#002F6C',
        sidebar_color: document.getElementById('hex-sidebar')?.value || '#1a1a2e',
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_theme`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Color scheme applied successfully!', 'success');
        } else {
            showToast(data.message || 'Failed to apply color scheme.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Preview Layout ──────────────────────────────────────────────────────── */
function previewLayout() {
    const style = document.getElementById('sidebar-style')?.value || 'inline';
    const scale = document.getElementById('font-scale')?.value || '100';
    
    showToast(`Preview: Sidebar \${style}, Font \${scale}%`, 'info');
    document.documentElement.style.fontSize = `\${scale}%`;
}

/* ── High Contrast Toggle ────────────────────────────────────────────────── */
function toggleHighContrast() {
    const enabled = document.getElementById('toggle-high-contrast')?.checked;
    if (enabled) {
        document.body.classList.add('high-contrast-mode');
        showToast('High contrast mode enabled', 'info');
    } else {
        document.body.classList.remove('high-contrast-mode');
        showToast('High contrast mode disabled', 'info');
    }
}

/* ── Update Font Scale Value ──────────────────────────────────────────────── */
function updateFontScaleValue() {
    const slider = document.getElementById('accessibility-font-scale');
    const display = document.getElementById('font-scale-value');
    const preview = document.getElementById('font-preview');
    
    if (slider && display) {
        display.textContent = slider.value + '%';
    }
    
    if (preview && slider) {
        const baseSize = 14;
        const scale = parseInt(slider.value) / 100;
        preview.style.fontSize = (baseSize * scale) + 'px';
    }
}

/* ── Preview Accessibility Theme ─────────────────────────────────────────── */
function previewAccessibilityTheme() {
    const highContrast = document.getElementById('toggle-high-contrast')?.checked;
    const scale = document.getElementById('accessibility-font-scale')?.value || '100';
    
    let message = 'Preview: ';
    if (highContrast) message += 'High Contrast ON, ';
    message += `Font Scale \${scale}%`;
    
    showToast(message, 'info');
}

/* ── Save Accessibility Settings ─────────────────────────────────────────── */
async function saveAccessibilitySettings() {
    const payload = {
        high_contrast: document.getElementById('toggle-high-contrast')?.checked ? '1' : '0',
        font_scale: document.getElementById('accessibility-font-scale')?.value || '100',
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_accessibility`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Accessibility settings saved!', 'success');
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Save All Settings ────────────────────────────────────────────────────── */
async function saveAllSettings() {
    const payload = {
        primary_color: document.getElementById('hex-primary')?.value,
        button_color: document.getElementById('hex-button')?.value,
        sidebar_color: document.getElementById('hex-sidebar')?.value,
        sidebar_style: document.getElementById('sidebar-style')?.value,
        font_scale_layout: document.getElementById('font-scale')?.value,
        high_contrast: document.getElementById('toggle-high-contrast')?.checked ? '1' : '0',
        font_scale_accessibility: document.getElementById('accessibility-font-scale')?.value,
        station_id: currentStationId
    };

    try {
        const res = await fetch(`\${API}?action=save_all`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('All settings saved successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    }
}

/* ── Initialize Drag & Drop for Card Arrangement ─────────────────────────── */
(function initCardDragDrop() {
    const list = document.getElementById('card-arrangement');
    if (!list) return;

    let draggedItem = null;

    list.addEventListener('dragstart', function(e) {
        draggedItem = e.target;
        e.target.classList.add('dragging');
    });

    list.addEventListener('dragend', function(e) {
        e.target.classList.remove('dragging');
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        const afterElement = getDragAfterElement(list, e.clientY);
        if (afterElement == null) {
            list.appendChild(draggedItem);
        } else {
            list.insertBefore(draggedItem, afterElement);
        }
    });

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.ss-sortable-item:not(.dragging)')];
        
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }
})();

/* ── Audit Trail ─────────────────────────────────────────────────────────── */
let auditCurrentPage = 1;
let auditDebounceTimer = null;

function debounceAudit() {
    clearTimeout(auditDebounceTimer);
    auditDebounceTimer = setTimeout(() => loadAudit(1), 400);
}

async function loadAudit(page = 1) {
    auditCurrentPage = page;
    const group  = document.getElementById('audit-filter-group').value;
    const search = document.getElementById('audit-search').value;

    const loading = document.getElementById('audit-loading');
    const wrap    = document.getElementById('audit-table-wrap');
    if (loading) loading.style.display = 'block';
    if (wrap)    wrap.style.display    = 'none';

    const params = new URLSearchParams({ action: 'get_audit', page, group, search, station_id: currentStationId });

    try {
        const res  = await fetch(`\${API}?\${params}`);
        const data = await res.json();

        if (loading) loading.style.display = 'none';
        if (wrap)    wrap.style.display    = 'block';

        if (!data.success) {
            showToast(data.message || 'Failed to load audit log.', 'error');
            return;
        }

        renderAuditTable(data.data || []);
        renderAuditPagination(data.page, data.pages, data.total);
    } catch(e) {
        if (loading) loading.style.display = 'none';
        if (wrap)    wrap.style.display    = 'block';
        showToast('Network error loading audit log.', 'error');
    }
}

function renderAuditTable(rows) {
    const tbody = document.getElementById('audit-tbody');
    if (!tbody) return;

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:32px;color:var(--text-secondary);">
            <i class="fas fa-inbox" style="font-size:24px;margin-bottom:8px;display:block;"></i>
            No audit records found.
        </td></tr>`;
        return;
    }

    const groupBadge = (g) => {
        const map = { branding: 'ss-badge-branding', theme: 'ss-badge-theme', layout: 'ss-badge-layout', accessibility: 'ss-badge-accessibility' };
        const cls = map[g] || 'ss-badge-general';
        return `<span class="ss-badge \${cls}">\${escHtml(g || 'general')}</span>`;
    };

    const truncate = (v, n = 30) => {
        if (!v) return '<span style="color:#9ca3af;">—</span>';
        const s = String(v);
        return escHtml(s.length > n ? s.substring(0, n) + '…' : s);
    };

    tbody.innerHTML = rows.map(r => `
        <tr>
            <td style="white-space:nowrap;">\${escHtml(r.created_at || '')}</td>
            <td><code style="font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">\${escHtml(r.setting_key || '')}</code></td>
            <td>\${groupBadge(r.setting_group)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="\${escHtml(r.old_value || '')}">\${truncate(r.old_value)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="\${escHtml(r.new_value || '')}">\${truncate(r.new_value)}</td>
            <td>\${escHtml(r.changed_by_name || 'System')}</td>
            <td style="font-family:monospace;font-size:12px;">\${escHtml(r.ip_address || '')}</td>
        </tr>
    `).join('');
}

function renderAuditPagination(current, total, recordCount) {
    const container = document.getElementById('audit-pagination');
    if (!container || total <= 1) { if (container) container.innerHTML = ''; return; }

    let html = `<span style="font-size:12px;color:var(--text-secondary);margin-right:8px;">\${recordCount} records</span>`;
    html += `<button class="ss-page-btn" onclick="loadAudit(\${current - 1})" \${current <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
             </button>`;

    const range = 2;
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - range && i <= current + range)) {
            html += `<button class="ss-page-btn \${i === current ? 'active' : ''}" onclick="loadAudit(\${i})">\${i}</button>`;
        } else if (i === current - range - 1 || i === current + range + 1) {
            html += `<span style="padding:0 4px;color:var(--text-secondary);">…</span>`;
        }
    }

    html += `<button class="ss-page-btn" onclick="loadAudit(\${current + 1})" \${current >= total ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
             </button>`;

    container.innerHTML = html;
}

/* ── Audit: Export CSV ───────────────────────────────────────────────────── */
async function exportAuditCSV() {
    const group  = document.getElementById('audit-filter-group').value;
    const search = document.getElementById('audit-search').value;

    showToast('Preparing CSV export…', 'info');

    let allRows = [];
    let page = 1, pages = 1;
    do {
        const params = new URLSearchParams({ action: 'get_audit', page, group, search, station_id: currentStationId });
        try {
            const res  = await fetch(`\${API}?\${params}`);
            const data = await res.json();
            if (!data.success) break;
            allRows = allRows.concat(data.data || []);
            pages = data.pages || 1;
            page++;
        } catch(e) { break; }
    } while (page <= pages);

    if (!allRows.length) { showToast('No records to export.', 'warning'); return; }

    const headers = ['Date/Time', 'Setting Key', 'Group', 'Old Value', 'New Value', 'Changed By', 'IP Address'];
    const csvRows = [headers.join(',')];
    allRows.forEach(r => {
        csvRows.push([
            csvCell(r.created_at),
            csvCell(r.setting_key),
            csvCell(r.setting_group),
            csvCell(r.old_value),
            csvCell(r.new_value),
            csvCell(r.changed_by_name),
            csvCell(r.ip_address),
        ].join(','));
    });

    const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `system_settings_audit_\${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast(`Exported \${allRows.length} records.`, 'success');
}

function csvCell(v) {
    if (v === null || v === undefined) return '';
    return '"' + String(v).replace(/"/g, '""') + '"';
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ── Init ────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    loadCurrentLogo();
    loadAllSettings();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>

