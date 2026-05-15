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

include __DIR__ . '/../partials/header.php';
?>
<link rel="stylesheet" href="../backend/generate_theme_css.php">
<style>
/* ── CSS Variables ─────────────────────────────────────────────────────────── */
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

/* ── Page Layout ───────────────────────────────────────────────────────────── */
.ss-wrapper {
    display: block;
    min-height: calc(100vh - 140px);
    background: var(--page-bg);
    position: relative;
}

/* ── Settings Sidebar ──────────────────────────────────────────────────────── */
.ss-sidebar {
    width: 260px;
    flex-shrink: 0;
    background: var(--surface);
    border-right: 1px solid var(--border-color);
    padding: 24px 0;
    box-shadow: 2px 0 8px rgba(0,0,0,0.04);
    position: sticky;
    top: 0;
    height: fit-content;
    z-index: 10;
}

.ss-sidebar-header {
    padding: 0 20px 20px;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 12px;
}

.ss-sidebar-header h2 {
    font-size: 13px !important;
    font-weight: 700 !important;
    color: var(--text-secondary) !important;
    text-transform: uppercase !important;
    letter-spacing: 1px !important;
    margin: 0 !important;
}

.ss-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: all var(--transition);
    color: var(--text-secondary);
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    user-select: none;
}

.ss-nav-item:hover {
    background: rgba(0,47,108,0.05);
    color: var(--primary-color);
    border-left-color: rgba(0,47,108,0.3);
}

.ss-nav-item.active {
    background: rgba(0,47,108,0.08);
    color: var(--primary-color);
    border-left-color: var(--primary-color);
    font-weight: 600;
}

.ss-nav-item .ss-nav-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,47,108,0.08);
    flex-shrink: 0;
    font-size: 14px;
    transition: background var(--transition);
}

.ss-nav-item.active .ss-nav-icon,
.ss-nav-item:hover .ss-nav-icon {
    background: var(--primary-color);
    color: #fff;
}

.ss-nav-step-num {
    font-size: 10px;
    font-weight: 700;
    color: var(--text-secondary);
    margin-left: auto;
    background: var(--page-bg);
    padding: 2px 7px;
    border-radius: 10px;
}

.ss-nav-item.active .ss-nav-step-num {
    background: var(--primary-color);
    color: #fff;
}

/* ── Main Content ──────────────────────────────────────────────────────────── */
.ss-content {
    width: 100%;
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

/* ── Cards ─────────────────────────────────────────────────────────────────── */
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

/* ── Panel Header ──────────────────────────────────────────────────────────── */
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

/* ── Form Controls ─────────────────────────────────────────────────────────── */
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

/* ── Buttons ───────────────────────────────────────────────────────────────── */
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

/* ── Toggle Switch ─────────────────────────────────────────────────────────── */
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

/* ── Toast Notifications ───────────────────────────────────────────────────── */
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

/* ── Loading Spinner ───────────────────────────────────────────────────────── */
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

/* ── Logo Preview ──────────────────────────────────────────────────────────── */
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

/* ── Theme Preset Cards ────────────────────────────────────────────────────── */
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

/* ── Color Pickers Row ─────────────────────────────────────────────────────── */
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

/* ── Live Preview Panel ────────────────────────────────────────────────────── */
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

/* ── Sidebar Style Toggle ──────────────────────────────────────────────────── */
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

/* ── Drag & Drop Card Order ────────────────────────────────────────────────── */
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

/* ── Accessibility Slider ──────────────────────────────────────────────────── */
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

/* ── Audit Table ───────────────────────────────────────────────────────────── */
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

/* ── Audit Filters ─────────────────────────────────────────────────────────── */
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

/* ── Pagination ────────────────────────────────────────────────────────────── */
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

/* ── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
    .ss-wrapper {
        flex-direction: column;
    }
    .ss-sidebar {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid var(--border-color);
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

<!-- ── Toast Container ──────────────────────────────────────────────────────── -->
<div id="ss-toast-container"></div>

<!-- ── Page Wrapper ────────────────────────────────────────────────────────── -->
<div class="ss-wrapper">

  <!-- ── Main Content ────────────────────────────────────────────────────────── -->
  <main class="ss-content">

    <!-- ── Tab Navigation ──────────────────────────────────────────────────────── -->
    <div style="display:flex;gap:12px;margin-bottom:24px;border-bottom:2px solid var(--border-color);padding-bottom:16px;flex-wrap:wrap;">
      <button class="ss-btn ss-btn-primary" onclick="showStep('step-logo', this)" id="tab-logo">
        <i class="fas fa-image"></i> Logo Management
      </button>
      <button class="ss-btn ss-btn-secondary" onclick="showStep('step-theme', this)" id="tab-theme">
        <i class="fas fa-palette"></i> Color Theme / UI
      </button>
      <button class="ss-btn ss-btn-secondary" onclick="showStep('step-layout', this)" id="tab-layout">
        <i class="fas fa-th-large"></i> Sidebar &amp; Cards
      </button>
      <button class="ss-btn ss-btn-secondary" onclick="showStep('step-accessibility', this)" id="tab-accessibility">
        <i class="fas fa-universal-access"></i> Accessibility
      </button>
      <button class="ss-btn ss-btn-secondary" onclick="showStep('step-audit', this)" id="tab-audit">
        <i class="fas fa-history"></i> Audit Trail
      </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════
         STEP 1 — Logo Management
    ═══════════════════════════════════════════════════════════════════════ -->
    <section id="step-logo" class="ss-panel active">
      <div class="ss-panel-header">
        <h1><i class="fas fa-image" style="margin-right:8px;color:var(--primary-color);"></i>Logo Management</h1>
        <p>Upload and manage the system logo displayed across all pages.</p>
      </div>

      <!-- Current Logo -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-eye"></i>Current Logo</h3>
        <div class="ss-logo-preview-box" id="current-logo-box">
          <img id="current-logo-img" src="../assets/img/Petron Logo.png" alt="Current Logo">
        </div>
        <button class="ss-btn ss-btn-danger" onclick="resetLogo()" id="btn-reset-logo">
          <i class="fas fa-undo"></i> Reset to Default
        </button>
      </div>

      <!-- Upload New Logo -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-upload"></i>Upload New Logo</h3>
        <div class="ss-form-group">
          <label for="logo-file-input">Select Image File (PNG, JPG, GIF, SVG, WEBP — max 2MB)</label>
          <input type="file" id="logo-file-input" accept="image/*" class="ss-form-control"
                 onchange="previewLogoFile(this)">
        </div>

        <!-- Upload Preview -->
        <div id="logo-upload-preview-wrap" style="display:none; margin-bottom:16px;">
          <p style="font-size:12px;font-weight:600;color:var(--text-secondary);text-transform:uppercase;margin-bottom:8px;">Preview</p>
          <div class="ss-logo-preview-box">
            <img id="logo-upload-preview" src="" alt="Preview">
          </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="ss-btn ss-btn-primary" onclick="uploadLogo()" id="btn-upload-logo" disabled>
            <i class="fas fa-cloud-upload-alt"></i> Upload Logo
          </button>
          <button class="ss-btn ss-btn-secondary" onclick="clearLogoInput()">
            <i class="fas fa-times"></i> Clear
          </button>
        </div>
      </div>
    </section>

    <!-- ════════════════════════════════════════════════════════════════════════
         STEP 2 — Color Theme / UI Scheme
    ═══════════════════════════════════════════════════════════════════════════ -->
    <section id="step-theme" class="ss-panel">
      <div class="ss-panel-header">
        <h1><i class="fas fa-palette" style="margin-right:8px;color:var(--primary-color);"></i>Color Theme / UI Scheme</h1>
        <p>Customize the visual appearance of the system.</p>
      </div>

      <!-- Preset Themes -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-swatchbook"></i>Preset Themes</h3>
        <div class="ss-theme-presets" id="theme-presets">
          <div class="ss-preset-card" data-preset="default" data-primary="#002F6C" data-secondary="#CC0000" data-accent="#FFC107" onclick="applyPreset(this)">
            <div class="ss-preset-swatch" style="background:#002F6C;"></div>
            <div class="ss-preset-label">Petron Blue</div>
          </div>
          <div class="ss-preset-card" data-preset="dark" data-primary="#0f172a" data-secondary="#334155" data-accent="#38bdf8" onclick="applyPreset(this)">
            <div class="ss-preset-swatch" style="background:#0f172a;"></div>
            <div class="ss-preset-label">Dark Mode</div>
          </div>
          <div class="ss-preset-card" data-preset="green" data-primary="#065f46" data-secondary="#047857" data-accent="#34d399" onclick="applyPreset(this)">
            <div class="ss-preset-swatch" style="background:#065f46;"></div>
            <div class="ss-preset-label">Green</div>
          </div>
          <div class="ss-preset-card" data-preset="red" data-primary="#7f1d1d" data-secondary="#991b1b" data-accent="#fca5a5" onclick="applyPreset(this)">
            <div class="ss-preset-swatch" style="background:#7f1d1d;"></div>
            <div class="ss-preset-label">Red</div>
          </div>
          <div class="ss-preset-card" data-preset="purple" data-primary="#4c1d95" data-secondary="#5b21b6" data-accent="#a78bfa" onclick="applyPreset(this)">
            <div class="ss-preset-swatch" style="background:#4c1d95;"></div>
            <div class="ss-preset-label">Purple</div>
          </div>
        </div>
      </div>

      <!-- Custom Colors -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-paint-brush"></i>Custom Colors</h3>
        <div class="ss-color-row">
          <div class="ss-color-item">
            <label>Primary Color</label>
            <div class="ss-color-picker-wrap">
              <input type="color" id="color-primary" value="#002F6C" oninput="syncColorHex('primary')">
              <input type="text" id="hex-primary" class="ss-color-hex" value="#002F6C" oninput="syncColorPicker('primary')" maxlength="7">
            </div>
          </div>
          <div class="ss-color-item">
            <label>Secondary Color</label>
            <div class="ss-color-picker-wrap">
              <input type="color" id="color-secondary" value="#CC0000" oninput="syncColorHex('secondary')">
              <input type="text" id="hex-secondary" class="ss-color-hex" value="#CC0000" oninput="syncColorPicker('secondary')" maxlength="7">
            </div>
          </div>
          <div class="ss-color-item">
            <label>Accent Color</label>
            <div class="ss-color-picker-wrap">
              <input type="color" id="color-accent" value="#FFC107" oninput="syncColorHex('accent')">
              <input type="text" id="hex-accent" class="ss-color-hex" value="#FFC107" oninput="syncColorPicker('accent')" maxlength="7">
            </div>
          </div>
        </div>

        <!-- Dark Mode Toggle -->
        <div class="ss-toggle-wrap" style="margin-top:8px;">
          <label class="ss-toggle">
            <input type="checkbox" id="toggle-dark-mode" onchange="updateLivePreview()">
            <span class="ss-toggle-slider"></span>
          </label>
          <label for="toggle-dark-mode">Dark Mode</label>
        </div>
      </div>

      <!-- Typography -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-font"></i>Typography</h3>
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
          <div class="ss-form-group" style="flex:1;min-width:180px;">
            <label for="font-family">Font Family</label>
            <select id="font-family" class="ss-form-control" onchange="updateLivePreview()">
              <option value="Inter">Default (Inter)</option>
              <option value="Roboto">Roboto</option>
              <option value="Open Sans">Open Sans</option>
              <option value="Lato">Lato</option>
            </select>
          </div>
          <div class="ss-form-group" style="flex:1;min-width:180px;">
            <label for="text-size">Text Size</label>
            <select id="text-size" class="ss-form-control" onchange="updateLivePreview()">
              <option value="small">Small</option>
              <option value="medium" selected>Medium</option>
              <option value="large">Large</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Live Preview -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-desktop"></i>Live Preview</h3>
        <div class="ss-live-preview" id="theme-live-preview">
          <div class="ss-preview-bar" id="preview-bar" style="background:#002F6C;">
            <i class="fas fa-gas-pump"></i>
            <span>Petron Management System</span>
          </div>
          <div class="ss-preview-body" id="preview-body">
            <button class="ss-preview-btn" id="preview-primary-btn" style="background:#002F6C;">Primary</button>
            <button class="ss-preview-accent-btn" id="preview-accent-btn" style="background:#FFC107;">Accent</button>
            <span id="preview-text" style="font-size:14px;color:#1a1a2e;align-self:center;">Sample text preview</span>
          </div>
        </div>
      </div>

      <button class="ss-btn ss-btn-primary" onclick="saveTheme()" id="btn-save-theme">
        <i class="fas fa-save"></i> Save Theme Settings
      </button>
    </section>

    <!-- ════════════════════════════════════════════════════════════════════════
         STEP 3 — Sidebar Layout & Dashboard Cards
    ═══════════════════════════════════════════════════════════════════════════ -->
    <section id="step-layout" class="ss-panel">
      <div class="ss-panel-header">
        <h1><i class="fas fa-th-large" style="margin-right:8px;color:var(--primary-color);"></i>Sidebar Layout &amp; Dashboard Cards</h1>
        <p>Configure the sidebar style and the order of dashboard cards.</p>
      </div>

      <!-- Sidebar Style -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-columns"></i>Sidebar Style</h3>
        <div class="ss-sidebar-style-options">
          <label class="ss-sidebar-option selected" id="opt-expanded">
            <input type="radio" name="sidebar_style" value="expanded" checked>
            <div class="ss-sidebar-preview">
              <div class="ss-sidebar-preview-bar" style="width:40px;"></div>
              <div class="ss-sidebar-preview-content"></div>
            </div>
            <span style="font-size:12px;font-weight:600;color:var(--text-primary);">Expanded</span>
          </label>
          <label class="ss-sidebar-option" id="opt-collapsed">
            <input type="radio" name="sidebar_style" value="collapsed">
            <div class="ss-sidebar-preview">
              <div class="ss-sidebar-preview-bar" style="width:18px;"></div>
              <div class="ss-sidebar-preview-content"></div>
            </div>
            <span style="font-size:12px;font-weight:600;color:var(--text-primary);">Collapsed</span>
          </label>
        </div>
      </div>

      <!-- Dashboard Card Order -->
      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-grip-vertical"></i>Dashboard Card Order</h3>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px;">
          Drag and drop to reorder the dashboard cards.
        </p>
        <ul class="ss-sortable-list" id="card-order-list">
          <li class="ss-sortable-item" draggable="true" data-card="system_health">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-heartbeat" style="color:#16a34a;"></i>
            System Health
          </li>
          <li class="ss-sortable-item" draggable="true" data-card="reports">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-chart-bar" style="color:#2563eb;"></i>
            Reports
          </li>
          <li class="ss-sortable-item" draggable="true" data-card="logs">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-list-alt" style="color:#d97706;"></i>
            Logs
          </li>
          <li class="ss-sortable-item" draggable="true" data-card="users">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-users" style="color:#7c3aed;"></i>
            Users
          </li>
          <li class="ss-sortable-item" draggable="true" data-card="stations">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-map-marker-alt" style="color:#dc2626;"></i>
            Stations
          </li>
          <li class="ss-sortable-item" draggable="true" data-card="alerts">
            <i class="fas fa-grip-vertical ss-drag-handle"></i>
            <i class="fas fa-bell" style="color:#ea580c;"></i>
            Alerts
          </li>
        </ul>
      </div>

      <button class="ss-btn ss-btn-primary" onclick="saveLayout()" id="btn-save-layout">
        <i class="fas fa-save"></i> Save Layout Settings
      </button>
    </section>

    <!-- ════════════════════════════════════════════════════════════════════════
         STEP 4 — Accessibility Options
    ═══════════════════════════════════════════════════════════════════════════ -->
    <section id="step-accessibility" class="ss-panel">
      <div class="ss-panel-header">
        <h1><i class="fas fa-universal-access" style="margin-right:8px;color:var(--primary-color);"></i>Accessibility Options</h1>
        <p>Improve usability for all users with accessibility enhancements.</p>
      </div>

      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-adjust"></i>Display Options</h3>

        <div class="ss-toggle-wrap">
          <label class="ss-toggle">
            <input type="checkbox" id="toggle-high-contrast">
            <span class="ss-toggle-slider"></span>
          </label>
          <label for="toggle-high-contrast">High Contrast Mode</label>
        </div>

        <div class="ss-toggle-wrap">
          <label class="ss-toggle">
            <input type="checkbox" id="toggle-reduce-motion">
            <span class="ss-toggle-slider"></span>
          </label>
          <label for="toggle-reduce-motion">Reduce Motion</label>
        </div>

        <div class="ss-toggle-wrap">
          <label class="ss-toggle">
            <input type="checkbox" id="toggle-focus-indicators">
            <span class="ss-toggle-slider"></span>
          </label>
          <label for="toggle-focus-indicators">Enhanced Focus Indicators</label>
        </div>

        <div class="ss-toggle-wrap">
          <label class="ss-toggle">
            <input type="checkbox" id="toggle-screen-reader">
            <span class="ss-toggle-slider"></span>
          </label>
          <label for="toggle-screen-reader">Screen Reader Hints (ARIA)</label>
        </div>
      </div>

      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-text-height"></i>Font Scale</h3>
        <div class="ss-form-group">
          <label>Scale Factor</label>
          <div class="ss-slider-wrap">
            <span style="font-size:12px;color:var(--text-secondary);">0.8×</span>
            <input type="range" id="font-scale-slider" class="ss-slider"
                   min="0.8" max="1.4" step="0.1" value="1.0"
                   oninput="updateFontScalePreview(this.value)">
            <span style="font-size:12px;color:var(--text-secondary);">1.4×</span>
            <span class="ss-slider-value" id="font-scale-value">1.0×</span>
          </div>
          <div class="ss-font-preview" id="font-scale-preview">
            <strong>Preview:</strong> The quick brown fox jumps over the lazy dog.
          </div>
        </div>
      </div>

      <button class="ss-btn ss-btn-primary" onclick="saveAccessibility()" id="btn-save-accessibility">
        <i class="fas fa-save"></i> Save Accessibility Settings
      </button>
    </section>

    <!-- ════════════════════════════════════════════════════════════════════════
         STEP 5 — Audit Trail Logging
    ═══════════════════════════════════════════════════════════════════════════ -->
    <section id="step-audit" class="ss-panel">
      <div class="ss-panel-header">
        <h1><i class="fas fa-history" style="margin-right:8px;color:var(--primary-color);"></i>Audit Trail Logging</h1>
        <p>View a complete history of all system settings changes.</p>
      </div>

      <div class="ss-card">
        <h3 class="ss-card-title"><i class="fas fa-filter"></i>Filters &amp; Search</h3>
        <div class="ss-audit-filters">
          <select id="audit-filter-group" onchange="loadAudit(1)">
            <option value="">All Groups</option>
            <option value="branding">Branding</option>
            <option value="theme">Theme</option>
            <option value="layout">Layout</option>
            <option value="accessibility">Accessibility</option>
          </select>
          <input type="text" id="audit-search" placeholder="Search key or user…"
                 oninput="debounceAudit()" style="min-width:200px;">
          <button class="ss-btn ss-btn-secondary" onclick="loadAudit(1)">
            <i class="fas fa-search"></i> Search
          </button>
          <button class="ss-btn ss-btn-success" onclick="exportAuditCSV()" style="margin-left:auto;">
            <i class="fas fa-file-csv"></i> Export CSV
          </button>
        </div>
      </div>

      <div class="ss-card" style="padding:0;">
        <div id="audit-loading" style="display:none;padding:24px;text-align:center;color:var(--text-secondary);">
          <i class="fas fa-spinner fa-spin" style="font-size:20px;margin-bottom:8px;"></i>
          <p style="margin:0;">Loading audit records…</p>
        </div>
        <div class="ss-table-wrap" id="audit-table-wrap">
          <table class="ss-table" id="audit-table">
            <thead>
              <tr>
                <th>Date / Time</th>
                <th>Setting Key</th>
                <th>Group</th>
                <th>Old Value</th>
                <th>New Value</th>
                <th>Changed By</th>
                <th>IP Address</th>
              </tr>
            </thead>
            <tbody id="audit-tbody">
              <tr>
                <td colspan="7" style="text-align:center;padding:32px;color:var(--text-secondary);">
                  <i class="fas fa-history" style="font-size:24px;margin-bottom:8px;display:block;"></i>
                  Click the Audit Trail tab to load records.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="ss-pagination" id="audit-pagination"></div>
      </div>
    </section>

  </main><!-- /.ss-content -->
</div><!-- /.ss-wrapper -->

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   SYSTEM SETTINGS — JavaScript
   All API calls go to: backend/api/system_settings_api.php?action=...
═══════════════════════════════════════════════════════════════════════════ */

const API = '../backend/api/system_settings_api.php';

/* ── Step Navigation ──────────────────────────────────────────────────────── */
function showStep(stepId, navEl) {
    // Hide all panels
    document.querySelectorAll('.ss-panel').forEach(p => p.classList.remove('active'));
    // Deactivate all tab buttons
    document.querySelectorAll('.ss-btn').forEach(b => b.classList.remove('active'));
    // Show target panel
    const panel = document.getElementById(stepId);
    if (panel) panel.classList.add('active');
    // Activate tab button
    if (navEl) navEl.classList.add('active');

    // Lazy-load audit when that step is opened
    if (stepId === 'step-audit') {
        loadAudit(1);
    }
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

/* ── Set button loading state ─────────────────────────────────────────────── */
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

/* ── Load All Settings on Page Load ──────────────────────────────────────── */
async function loadAllSettings() {
    try {
        const res  = await fetch(`${API}?action=get_all`);
        const data = await res.json();
        if (!data.success) return;
        const s = data.settings || {};

        // Theme
        if (s.primary_color)   setColorField('primary',   s.primary_color.value);
        if (s.secondary_color) setColorField('secondary', s.secondary_color.value);
        if (s.accent_color)    setColorField('accent',    s.accent_color.value);
        if (s.theme_mode)      document.getElementById('toggle-dark-mode').checked = (s.theme_mode.value === 'dark');
        if (s.font_family) {
            const ff = document.getElementById('font-family');
            if (ff) ff.value = s.font_family.value;
        }
        if (s.text_size) {
            const ts = document.getElementById('text-size');
            if (ts) ts.value = s.text_size.value;
        }
        if (s.theme_preset) {
            document.querySelectorAll('.ss-preset-card').forEach(c => {
                c.classList.toggle('selected', c.dataset.preset === s.theme_preset.value);
            });
        }

        // Layout
        if (s.sidebar_style) {
            const val = s.sidebar_style.value;
            document.querySelectorAll('input[name="sidebar_style"]').forEach(r => {
                r.checked = (r.value === val);
            });
            document.getElementById('opt-expanded').classList.toggle('selected', val === 'expanded');
            document.getElementById('opt-collapsed').classList.toggle('selected', val === 'collapsed');
        }
        if (s.dashboard_card_order) {
            try {
                const order = JSON.parse(s.dashboard_card_order.value);
                reorderCardList(order);
            } catch(e) {}
        }

        // Accessibility
        if (s.high_contrast)       document.getElementById('toggle-high-contrast').checked   = (s.high_contrast.value === '1' || s.high_contrast.value === 'true');
        if (s.reduce_motion)       document.getElementById('toggle-reduce-motion').checked    = (s.reduce_motion.value === '1' || s.reduce_motion.value === 'true');
        if (s.focus_indicators)    document.getElementById('toggle-focus-indicators').checked = (s.focus_indicators.value === '1' || s.focus_indicators.value === 'true');
        if (s.screen_reader_hints) document.getElementById('toggle-screen-reader').checked    = (s.screen_reader_hints.value === '1' || s.screen_reader_hints.value === 'true');
        if (s.font_scale) {
            const scale = parseFloat(s.font_scale.value) || 1.0;
            document.getElementById('font-scale-slider').value = scale;
            updateFontScalePreview(scale);
        }

        updateLivePreview();
    } catch(e) {
        console.warn('Could not load settings:', e);
    }
}

/* ── Load Current Logo ────────────────────────────────────────────────────── */
async function loadCurrentLogo() {
    try {
        const res  = await fetch(`${API}?action=get_logo`);
        const data = await res.json();
        if (data.success && data.logo_url) {
            document.getElementById('current-logo-img').src = '../' + data.logo_url;
        }
    } catch(e) {}
}

/* ── Logo: Preview before upload ──────────────────────────────────────────── */
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
        document.getElementById('logo-upload-preview').src = e.target.result;
        document.getElementById('logo-upload-preview-wrap').style.display = 'block';
        document.getElementById('btn-upload-logo').disabled = false;
    };
    reader.readAsDataURL(file);
}

function clearLogoInput() {
    document.getElementById('logo-file-input').value = '';
    document.getElementById('logo-upload-preview-wrap').style.display = 'none';
    document.getElementById('btn-upload-logo').disabled = true;
}

/* ── Logo: Upload ─────────────────────────────────────────────────────────── */
async function uploadLogo() {
    const input = document.getElementById('logo-file-input');
    if (!input.files[0]) { showToast('Please select a file first.', 'warning'); return; }

    setBtnLoading('btn-upload-logo', true);
    const formData = new FormData();
    formData.append('logo', input.files[0]);

    try {
        const res  = await fetch(`${API}?action=save_logo`, { method: 'POST', body: formData });
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

/* ── Logo: Reset to Default ───────────────────────────────────────────────── */
async function resetLogo() {
    if (!confirm('Reset logo to the default Petron logo?')) return;
    setBtnLoading('btn-reset-logo', true);
    try {
        const res  = await fetch(`${API}?action=reset_logo`, { method: 'POST' });
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
    const picker = document.getElementById(`color-${name}`);
    const hex    = document.getElementById(`hex-${name}`);
    if (picker) picker.value = value;
    if (hex)    hex.value    = value;
    updateLivePreview();
}

function syncColorHex(name) {
    const picker = document.getElementById(`color-${name}`);
    const hex    = document.getElementById(`hex-${name}`);
    if (picker && hex) hex.value = picker.value;
    updateLivePreview();
}

function syncColorPicker(name) {
    const picker = document.getElementById(`color-${name}`);
    const hex    = document.getElementById(`hex-${name}`);
    if (hex && picker && /^#[0-9A-Fa-f]{6}$/.test(hex.value)) {
        picker.value = hex.value;
    }
    updateLivePreview();
}

/* ── Theme: Apply Preset ──────────────────────────────────────────────────── */
function applyPreset(card) {
    document.querySelectorAll('.ss-preset-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    setColorField('primary',   card.dataset.primary);
    setColorField('secondary', card.dataset.secondary);
    setColorField('accent',    card.dataset.accent);
    updateLivePreview();
}

/* ── Theme: Live Preview ──────────────────────────────────────────────────── */
function updateLivePreview() {
    const primary = document.getElementById('color-primary')?.value   || '#002F6C';
    const accent  = document.getElementById('color-accent')?.value    || '#FFC107';
    const isDark  = document.getElementById('toggle-dark-mode')?.checked;

    const bar  = document.getElementById('preview-bar');
    const body = document.getElementById('preview-body');
    const pBtn = document.getElementById('preview-primary-btn');
    const aBtn = document.getElementById('preview-accent-btn');
    const txt  = document.getElementById('preview-text');

    if (bar)  bar.style.background  = primary;
    if (pBtn) pBtn.style.background = primary;
    if (aBtn) aBtn.style.background = accent;

    if (body) {
        body.style.background = isDark ? '#1e293b' : '#f8fafc';
    }
    if (txt) {
        txt.style.color = isDark ? '#e2e8f0' : '#1a1a2e';
    }
}

/* ── Theme: Save ──────────────────────────────────────────────────────────── */
async function saveTheme() {
    setBtnLoading('btn-save-theme', true);
    const selectedPreset = document.querySelector('.ss-preset-card.selected');
    const payload = {
        primary_color:   document.getElementById('hex-primary').value,
        secondary_color: document.getElementById('hex-secondary').value,
        accent_color:    document.getElementById('hex-accent').value,
        theme_mode:      document.getElementById('toggle-dark-mode').checked ? 'dark' : 'light',
        font_family:     document.getElementById('font-family').value,
        text_size:       document.getElementById('text-size').value,
        theme_preset:    selectedPreset ? selectedPreset.dataset.preset : 'custom',
    };

    try {
        const res  = await fetch(`${API}?action=save_theme`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Theme settings saved!', 'success');
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    } finally {
        setBtnLoading('btn-save-theme', false);
    }
}

/* ── Layout: Sidebar radio visual ────────────────────────────────────────── */
document.querySelectorAll('input[name="sidebar_style"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('opt-expanded').classList.toggle('selected', this.value === 'expanded');
        document.getElementById('opt-collapsed').classList.toggle('selected', this.value === 'collapsed');
    });
});

/* ── Layout: Drag & Drop ──────────────────────────────────────────────────── */
(function initDragDrop() {
    const list = document.getElementById('card-order-list');
    if (!list) return;

    let dragSrc = null;

    list.addEventListener('dragstart', function(e) {
        dragSrc = e.target.closest('.ss-sortable-item');
        if (dragSrc) {
            dragSrc.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }
    });

    list.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.ss-sortable-item');
        if (target && target !== dragSrc) {
            list.querySelectorAll('.ss-sortable-item').forEach(i => i.classList.remove('drag-over'));
            target.classList.add('drag-over');
        }
    });

    list.addEventListener('dragleave', function(e) {
        const target = e.target.closest('.ss-sortable-item');
        if (target) target.classList.remove('drag-over');
    });

    list.addEventListener('drop', function(e) {
        e.preventDefault();
        const target = e.target.closest('.ss-sortable-item');
        if (target && dragSrc && target !== dragSrc) {
            const items = [...list.querySelectorAll('.ss-sortable-item')];
            const srcIdx = items.indexOf(dragSrc);
            const tgtIdx = items.indexOf(target);
            if (srcIdx < tgtIdx) {
                list.insertBefore(dragSrc, target.nextSibling);
            } else {
                list.insertBefore(dragSrc, target);
            }
        }
        list.querySelectorAll('.ss-sortable-item').forEach(i => i.classList.remove('drag-over'));
    });

    list.addEventListener('dragend', function() {
        if (dragSrc) dragSrc.classList.remove('dragging');
        dragSrc = null;
    });
})();

function reorderCardList(order) {
    const list = document.getElementById('card-order-list');
    if (!list || !Array.isArray(order)) return;
    order.forEach(cardKey => {
        const item = list.querySelector(`[data-card="${cardKey}"]`);
        if (item) list.appendChild(item);
    });
}

function getCardOrder() {
    return [...document.querySelectorAll('#card-order-list .ss-sortable-item')]
        .map(i => i.dataset.card);
}

/* ── Layout: Save ─────────────────────────────────────────────────────────── */
async function saveLayout() {
    setBtnLoading('btn-save-layout', true);
    const sidebarStyle = document.querySelector('input[name="sidebar_style"]:checked')?.value || 'expanded';
    const payload = {
        sidebar_style:        sidebarStyle,
        sidebar_state:        sidebarStyle,
        dashboard_card_order: getCardOrder(),
    };

    try {
        const res  = await fetch(`${API}?action=save_layout`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            showToast('Layout settings saved!', 'success');
        } else {
            showToast(data.message || 'Save failed.', 'error');
        }
    } catch(e) {
        showToast('Network error.', 'error');
    } finally {
        setBtnLoading('btn-save-layout', false);
    }
}

/* ── Accessibility: Font Scale Preview ───────────────────────────────────── */
function updateFontScalePreview(value) {
    const val = parseFloat(value);
    document.getElementById('font-scale-value').textContent = val.toFixed(1) + '×';
    const preview = document.getElementById('font-scale-preview');
    if (preview) preview.style.fontSize = (val * 14) + 'px';
}

/* ── Accessibility: Save ──────────────────────────────────────────────────── */
async function saveAccessibility() {
    setBtnLoading('btn-save-accessibility', true);
    const payload = {
        high_contrast:       document.getElementById('toggle-high-contrast').checked   ? '1' : '0',
        reduce_motion:       document.getElementById('toggle-reduce-motion').checked    ? '1' : '0',
        focus_indicators:    document.getElementById('toggle-focus-indicators').checked ? '1' : '0',
        screen_reader_hints: document.getElementById('toggle-screen-reader').checked    ? '1' : '0',
        font_scale:          document.getElementById('font-scale-slider').value,
    };

    try {
        const res  = await fetch(`${API}?action=save_accessibility`, {
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
    } finally {
        setBtnLoading('btn-save-accessibility', false);
    }
}

/* ── Audit Trail ──────────────────────────────────────────────────────────── */
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

    const params = new URLSearchParams({ action: 'get_audit', page, group, search });

    try {
        const res  = await fetch(`${API}?${params}`);
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
        return `<span class="ss-badge ${cls}">${escHtml(g || 'general')}</span>`;
    };

    const truncate = (v, n = 30) => {
        if (!v) return '<span style="color:#9ca3af;">—</span>';
        const s = String(v);
        return escHtml(s.length > n ? s.substring(0, n) + '…' : s);
    };

    tbody.innerHTML = rows.map(r => `
        <tr>
            <td style="white-space:nowrap;">${escHtml(r.created_at || '')}</td>
            <td><code style="font-size:12px;background:#f3f4f6;padding:2px 6px;border-radius:4px;">${escHtml(r.setting_key || '')}</code></td>
            <td>${groupBadge(r.setting_group)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.old_value || '')}">${truncate(r.old_value)}</td>
            <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escHtml(r.new_value || '')}">${truncate(r.new_value)}</td>
            <td>${escHtml(r.changed_by_name || 'System')}</td>
            <td style="font-family:monospace;font-size:12px;">${escHtml(r.ip_address || '')}</td>
        </tr>
    `).join('');
}

function renderAuditPagination(current, total, recordCount) {
    const container = document.getElementById('audit-pagination');
    if (!container || total <= 1) { if (container) container.innerHTML = ''; return; }

    let html = `<span style="font-size:12px;color:var(--text-secondary);margin-right:8px;">${recordCount} records</span>`;
    html += `<button class="ss-page-btn" onclick="loadAudit(${current - 1})" ${current <= 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
             </button>`;

    const range = 2;
    for (let i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= current - range && i <= current + range)) {
            html += `<button class="ss-page-btn ${i === current ? 'active' : ''}" onclick="loadAudit(${i})">${i}</button>`;
        } else if (i === current - range - 1 || i === current + range + 1) {
            html += `<span style="padding:0 4px;color:var(--text-secondary);">…</span>`;
        }
    }

    html += `<button class="ss-page-btn" onclick="loadAudit(${current + 1})" ${current >= total ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
             </button>`;

    container.innerHTML = html;
}

/* ── Audit: Export CSV ────────────────────────────────────────────────────── */
async function exportAuditCSV() {
    const group  = document.getElementById('audit-filter-group').value;
    const search = document.getElementById('audit-search').value;

    showToast('Preparing CSV export…', 'info');

    // Fetch all pages
    let allRows = [];
    let page = 1, pages = 1;
    do {
        const params = new URLSearchParams({ action: 'get_audit', page, group, search });
        try {
            const res  = await fetch(`${API}?${params}`);
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
    a.download = `system_settings_audit_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    showToast(`Exported ${allRows.length} records.`, 'success');
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

/* ── Init ─────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', function() {
    loadCurrentLogo();
    loadAllSettings();
    updateLivePreview();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
