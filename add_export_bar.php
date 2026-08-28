<?php
$file = __DIR__ . '/public/users.php';
$content = file_get_contents($file);

// 1. Add CSS for rpt-export-btn
$css_add = '
/* Reports-Style Export Bar & Buttons */
.rpt-export-group {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-left: auto !important;
    white-space: nowrap !important;
}
.rpt-export-btn {
    padding: 7px 13px !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
    background: #ffffff !important;
    border: 1px solid !important;
    transition: all 0.18s !important;
    text-decoration: none !important;
}
.rpt-btn-print  { color: #475569 !important; border-color: #cbd5e1 !important; background: #ffffff !important; }
.rpt-btn-print:hover  { background: #f1f5f9 !important; color: #00264D !important; }
.rpt-btn-pdf   { color: #dc2626 !important; border-color: #fca5a5 !important; background: #ffffff !important; }
.rpt-btn-pdf:hover   { background: #fef2f2 !important; color: #991b1b !important; }
.rpt-btn-excel { color: #16a34a !important; border-color: #86efac !important; background: #ffffff !important; }
.rpt-btn-excel:hover { background: #f0fdf4 !important; color: #166534 !important; }
.rpt-btn-csv   { color: #0d9488 !important; border-color: #99f6e4 !important; background: #ffffff !important; }
.rpt-btn-csv:hover   { background: #f0fdfa !important; color: #115e59 !important; }
';

if (strpos($content, '.rpt-export-btn') === false) {
    $content = str_replace('</style>', $css_add . "\n</style>", $content);
}

// 2. Add Filter & Export Bar HTML right above .um-tabs
$export_bar_html = '<!-- SEARCH, FILTERS & REPORTS-STYLE CONSOLIDATED EXPORT BAR -->
<div style="display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 18px; flex-wrap: wrap; background: #ffffff; padding: 14px 18px; border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 2px 8px rgba(0,0,0,0.03);">
    <div style="display: flex; align-items: center; gap: 10px; flex: 1; min-width: 290px; flex-wrap: wrap;">
        <div style="position: relative; flex: 1; min-width: 200px;">
            <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px;"></i>
            <input type="text" id="empSearchInput" onkeyup="filterEmployeeTable()" placeholder="Search employee name, ID, or username..." class="inp" style="padding-left: 36px; height: 36px; border-radius: 6px; font-size: 13px; width: 100%;">
        </div>
        <select id="empRoleFilter" onchange="filterEmployeeTable()" class="inp" style="width: 140px; height: 36px; border-radius: 6px; font-size: 13px;">
            <option value="">All Roles</option>
            <option value="staff">Staff</option>
            <option value="manager">Manager</option>
            <option value="admin">Admin / Owner</option>
        </select>
        <select id="empStatusFilter" onchange="filterEmployeeTable()" class="inp" style="width: 140px; height: 36px; border-radius: 6px; font-size: 13px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>

    <!-- CONSOLIDATED EMPLOYEE MASTER EXPORT BUTTONS (REPORTS STYLE) -->
    <div class="rpt-export-group">
        <button type="button" class="rpt-export-btn rpt-btn-print" onclick="window.open(\'export_employee_list.php?format=print\', \'_blank\')">
            <i class="fas fa-print"></i> Print
        </button>
        <a href="export_employee_list.php?format=pdf" target="_blank" class="rpt-export-btn rpt-btn-pdf">
            <i class="fas fa-file-pdf"></i> Export PDF
        </a>
        <a href="export_employee_list.php?format=excel" class="rpt-export-btn rpt-btn-excel">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="export_employee_list.php?format=csv" class="rpt-export-btn rpt-btn-csv">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
    </div>
</div>
';

$target_tabs = '<!-- DUAL TAB NAVIGATION -->';
if (strpos($content, 'rpt-export-group') === false) {
    $content = str_replace($target_tabs, $export_bar_html . "\n" . $target_tabs, $content);
}

file_put_contents($file, $content);
echo "SUCCESS: Added Search, Filter & Consolidated Export bar to users.php\n";
