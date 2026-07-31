<?php
/**
 * Complete Manager Reports - Real Report Format
 * Operations Reports with tabbed interface matching Admin design
 * Blue theme for Manager role
 */

$page_id = 'manager_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Check if manager role
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied. Manager privileges required.');
}

// Module gate
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('reports')) {
    render_module_disabled_page('Reports');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Get active tab from URL parameter
$active_tab = $_GET['tab'] ?? 'shift_reports';

// Get date range from GET or use current day as default.
// Manager reports are operational daily reports, so the default should stay updated to today.
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to   = $_GET['date_to']   ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d');
}

// Pass these variables to included report files
$date_start = $date_from;
$date_end = $date_to;

// Get Station Name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ============================================================
   MANAGER REPORTS - MATCHES ADMIN PROFESSIONAL DESIGN
   Blue theme for manager (#00264D, #002F70)
   Force application with !important for specificity
   ============================================================ */

/* Main Container */
.reports-wrapper {
    background: white !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    margin: 0 !important;
    overflow: hidden !important;
}

/* Page Header */
.rpt-page-header {
    background: linear-gradient(135deg, #00264D 0%, #003d7a 100%) !important;
    padding: 24px 28px !important;
    color: white !important;
    border-bottom: 3px solid #002F70 !important;
}

.rpt-page-header h1 {
    margin: 0 0 6px !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    color: white !important;
}

.rpt-page-header .subtitle {
    margin: 0 !important;
    font-size: 13px !important;
    opacity: 0.9 !important;
    color: white !important;
}

/* Tab Navigation - Matches Staff Style */
.rpt-tabs {
    display: flex !important;
    background: #f8f9fa !important;
    border-bottom: 2px solid #e2e8f0 !important;
    overflow:hidden !important;
    gap: 0 !important;
}

.rpt-tab-btn {
    flex: 1 !important;
    !important;
    padding: 14px 18px !important;
    text-align: center !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #64748b !important;
    background: transparent !important;
    border: none !important;
    border-bottom: 3px solid transparent !important;
    cursor: pointer !important;
    transition: all 0.2s ease !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

.rpt-tab-btn:hover {
    background: rgba(0, 38, 77, 0.05) !important;
    color: #00264D !important;
}

.rpt-tab-btn.active {
    background: white !important;
    color: #00264D !important;
    border-bottom-color: #002F70 !important;
    font-weight: 700 !important;
}

.rpt-tab-btn i {
    margin-right: 5px !important;
    font-size: 13px !important;
}

/* Content Area */
.rpt-content {
    padding: 24px 28px !important;
}

/* Date Filter Bar - Clean Design */
.rpt-filter-bar {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 0 !important;
    background: transparent !important;
    border-radius: 0 !important;
    border: none !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
}

.rpt-filter-bar label {
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #00264D !important;
    margin: 0 !important;
}

.rpt-filter-bar input[type="date"] {
    padding: 7px 10px !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    max-width: 140px !important;
}

.rpt-filter-bar button {
    padding: 7px 16px !important;
    background: #00264D !important;
    color: #ffffff !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
}

.rpt-filter-bar button:hover {
    background: #001933 !important;
    color: #ffffff !important;
}

.rpt-filter-bar button i {
    color: #ffffff !important;
}

/* Export Actions */
.rpt-export-actions {
    display: flex !important;
    gap: 6px !important;
    margin-left: auto !important;
}

.rpt-export-btn {
    padding: 7px 14px !important;
    border-radius: 4px !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    border: 1px solid !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    background: #ffffff !important;
}

/* Excel Button - Green outline & text */
.rpt-export-btn:nth-child(1) {
    color: #16a34a !important;
    border-color: #16a34a !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(1):hover {
    background: #f0fdf4 !important;
    border-color: #15803d !important;
    color: #15803d !important;
}

/* CSV Button - Clean slate border with dark navy text */
.rpt-export-btn:nth-child(2) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(2):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

/* PDF Button - Clean slate border with dark navy text */
.rpt-export-btn:nth-child(3) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(3):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

/* Print Button - Clean slate border with dark navy text */
.rpt-export-btn:nth-child(4) {
    color: #00264D !important;
    border-color: #cbd5e1 !important;
    background: #ffffff !important;
}
.rpt-export-btn:nth-child(4):hover {
    background: #f8fafc !important;
    border-color: #00264D !important;
    color: #00264D !important;
}

.rpt-export-btn i {
    margin-right: 3px !important;
}

/* Tab Panel */
.rpt-tab-panel {
    display: none !important;
}

.rpt-tab-panel.active {
    display: block !important;
}

/* Report Info Box */
.rpt-info-box {
    background: #f0f4ff !important;
    padding: 14px 16px !important;
    border-radius: 4px !important;
    margin-bottom: 20px !important;
    border: 1px solid #dbeafe !important;
}

.rpt-info-box p {
    margin: 0 !important;
    font-size: 12px !important;
    color: #334155 !important;
    line-height: 1.5 !important;
}

.rpt-info-box strong {
    color: #00264D !important;
}

/* Station Info Header */
.rpt-station-info {
    text-align: center !important;
    padding: 16px 0 !important;
    border-bottom: 2px solid #e2e8f0 !important;
    margin-bottom: 20px !important;
}

.rpt-station-info h2 {
    margin: 0 0 4px !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #00264D !important;
    text-transform: uppercase !important;
}

.rpt-station-info p {
    margin: 0 !important;
    font-size: 12px !important;
    color: #64748b !important;
}

/* Tables - Clean Professional Style with Blue Headers */
.rpt-table {
    width: 100% !important;
    border-collapse: collapse !important;
    margin-top: 16px !important;
    font-size: 12px !important;
    background: white !important;
}

.rpt-table thead {
    background: #f8fafc !important;
    border-top: 2px solid #00264D !important;
    border-bottom: 2px solid #e2e8f0 !important;
}

.rpt-table thead th {
    padding: 12px 10px !important;
    text-align: left !important;
    font-weight: 700 !important;
    color: #475569 !important;
    font-size: 11px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    background: #f8fafc !important;
}

.rpt-table tbody tr {
    border-bottom: 1px solid #f1f5f9 !important;
    transition: background 0.15s !important;
}

.rpt-table tbody tr:hover {
    background: #f8fafc !important;
}

.rpt-table tbody td {
    padding: 10px !important;
    color: #334155 !important;
    font-size: 12px !important;
}

.rpt-table tfoot {
    background: #f8f9fa !important;
    border-top: 2px solid #00264D !important;
    font-weight: 700 !important;
}

.rpt-table tfoot td {
    padding: 12px 10px !important;
    color: #00264D !important;
    font-size: 12px !important;
}

/* Print Styles - matches staff_reports.php approach */
@media print {
    @page {
        size: A4 portrait;
        margin: 0.3in 0.4in;
    }

    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    html, body {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }

    /* Hide EVERYTHING except .rpt-printable */
    body > * { display: none !important; }
    .rpt-printable { display: block !important; overflow: visible !important; }

    /* Tables print clean */
    .sr-section-panel {
        overflow: visible !important;
    }

    .sr-shift-block {
        break-inside: auto !important;
        page-break-inside: auto !important;
        margin-bottom: 20px !important;
        overflow: visible !important;
    }

    .sr-shift-heading,
    h3,
    div[style*="font-size: 14px"] {
        break-after: avoid !important;
        page-break-after: avoid !important;
    }

    .sr-tbl, .rpt-table, .sr-table {
        width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 9.5px !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
    }

    .sr-tbl thead, .rpt-table thead, .sr-table thead { display: table-header-group !important; }
    .sr-tbl tfoot, .rpt-table tfoot, .sr-table tfoot { display: table-footer-group !important; }
    .sr-tbl tr, .rpt-table tr, .sr-table tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .sr-tbl thead tr,
    .rpt-table thead tr {
        background: #f8f9fa !important;
    }

    .sr-tbl thead th,
    .rpt-table thead th {
        padding: 7px 6px !important;
        font-size: 9px !important;
        border-bottom: 1px solid #000 !important;
    }

    .sr-tbl tbody td,
    .rpt-table tbody td,
    .sr-table tbody td,
    .sr-table tfoot td,
    .sr-table thead th {
        padding: 6px !important;
        font-size: 9.5px !important;
        border-bottom: 1px solid #ddd !important;
        white-space: normal !important;
        word-break: break-word !important;
    }

    .sr-tbl tfoot td,
    .rpt-table tfoot td {
        padding: 7px 6px !important;
        font-size: 10px !important;
        border-top: 2px solid #000 !important;
        font-weight: 700 !important;
    }

    /* Show all shift blocks when printing */
    .sr-shift-block.hidden {
        display: block !important;
    }
}
</style>

<div class="reports-wrapper">
    <!-- Content Area -->
    <div class="rpt-content">
        <!-- Date Filter Bar -->
        <form method="GET" class="rpt-filter-bar">
            <input type="hidden" name="section" id="hiddenSectionInput" value="<?= htmlspecialchars($_GET['section'] ?? 'fuel_sales') ?>">
            <label style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
            <span style="font-weight:700;color:#64748b;font-size:13px;text-transform:uppercase;letter-spacing:.4px;">To</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
            <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
            
            <div class="rpt-export-actions">
                <button type="button" class="rpt-export-btn" onclick="exportReport('excel')">
                    <i class="fas fa-file-excel"></i> Excel
                </button>
                <button type="button" class="rpt-export-btn" onclick="exportReport('csv')">
                    <i class="fas fa-file-csv"></i> CSV
                </button>
                <button type="button" class="rpt-export-btn" onclick="exportReport('pdf', this)">
                    <i class="fas fa-file-pdf"></i> PDF
                </button>
                <button type="button" class="rpt-export-btn" onclick="printReport()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </form>

        <!-- Printable Report Content -->
        <div class="rpt-printable">
            <?php include __DIR__ . '/reports/manager_shift_reports.php'; ?>
        </div>

    </div>
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function exportReport(type) {
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }

    const activePanel = wrap.querySelector('.sr-section-panel.active') || wrap;
    const section  = document.getElementById('hiddenSectionInput')?.value
                  || new URL(window.location).searchParams.get('section')
                  || 'fuel_sales';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Manager_Report_${section}_${dateFrom}_to_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(activePanel, 'Manager Operations Report', filename, document.activeElement);
        return;
    }

    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }

    // Get active section panel
    // Temporarily make hidden shift blocks visible so innerText works
    const hiddenBlocks = Array.from(activePanel.querySelectorAll('.sr-shift-block.hidden'));
    hiddenBlocks.forEach(b => { b.style.display = 'block'; b.dataset.wasHidden = '1'; });

    // Grab all tables with actual tbody rows
    const tables = Array.from(activePanel.querySelectorAll('table')).filter(
        t => t.querySelector('tbody tr')
    );

    // Restore hidden blocks
    hiddenBlocks.forEach(b => { b.style.display = 'none'; delete b.dataset.wasHidden; });

    if (!tables.length) { alert('No table data found to export.'); return; }

    if (type === 'csv') {
        exportCSV(tables, filename);
    } else {
        exportExcel(tables, filename);
    }
}

function tableToAoA(table) {
    const aoa = [];
    // Headers
    table.querySelectorAll('thead tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
    });
    // Body
    table.querySelectorAll('tbody tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    // Footer
    table.querySelectorAll('tfoot tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    return aoa;
}

function exportExcel(tables, filename) {
    const wb = XLSX.utils.book_new();
    const usedNames = {};

    tables.forEach((tbl, i) => {
        // Get heading from nearest shift block
        const block = tbl.closest('.sr-shift-block');
        let sheetName = block?.querySelector('.sr-shift-heading')?.innerText?.trim()
                      || tbl.previousElementSibling?.innerText?.trim()
                      || `Sheet ${i + 1}`;
        // Clean sheet name (Excel limit: 31 chars, no special chars)
        sheetName = sheetName.replace(/[:\\\/?*\[\]]/g, '').substring(0, 31).trim() || `Sheet${i+1}`;
        // Deduplicate sheet names
        if (usedNames[sheetName]) {
            usedNames[sheetName]++;
            sheetName = (sheetName.substring(0, 28) + ' ' + usedNames[sheetName]).substring(0,31);
        } else {
            usedNames[sheetName] = 1;
        }

        const aoa = tableToAoA(tbl);
        const ws  = XLSX.utils.aoa_to_sheet(aoa);
        // Auto column widths
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
    });

    XLSX.writeFile(wb, filename + '.xlsx');
}

function exportCSV(tables, filename) {
    let csv = '';
    tables.forEach((tbl, i) => {
        const block   = tbl.closest('.sr-shift-block');
        const heading = block?.querySelector('.sr-shift-heading')?.innerText?.trim();
        if (heading) csv += '"' + heading.replace(/"/g, '""') + '"\n';
        else if (i > 0) csv += '\n';
        tableToAoA(tbl).forEach(row => {
            csv += row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',') + '\n';
        });
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 200);
}

function printReport() {
    const wrap = document.querySelector('.rpt-printable');
    const activePanel = wrap?.querySelector('.sr-section-panel.active') || wrap;
    printReportArea(activePanel);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
