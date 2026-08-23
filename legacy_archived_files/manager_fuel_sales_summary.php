<?php
/**
 * MANAGER FUEL SALES SUMMARY
 * 
 * Complete 3-table fuel sales summary for managers:
 * A. Meter Reading Table (raw pump readings + computed liters/amount per entry)
 * B. Volume Sales Summary (liters per fuel type)
 * C. Volume & Amount Summary (liters + peso per fuel type with totals)
 * 
 * Features:
 * - Date range filter (from/to)
 * - Shift period filter
 * - Export to Excel/PDF
 * - Real-time KPI cards
 */

$page_id = 'fuel_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('fuel_management')) {
    render_module_disabled_page('Fuel Management');
}

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager privileges required.';
    header('Location: dashboard.php');
    exit;
}

// ── Fetch shift periods ───────────────────────────────────────
$shift_periods = [];
try {
    $stmt = $pdo->query("SELECT shift_key, shift_name FROM shift_periods WHERE is_active = 1 ORDER BY sort_order ASC");
    $shift_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $shift_periods = [
        ['shift_key' => 'first',  'shift_name' => 'First Shift: 6:00 AM – 2:00 PM'],
        ['shift_key' => 'second', 'shift_name' => 'Second Shift: 2:00 PM – 12:00 Midnight'],
    ];
}

require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../partials/flash_toast.php';
?>

<style>
/* ═══════════════════════════════════════════════════════════════
   MANAGER FUEL SALES SUMMARY — Page Styles
═══════════════════════════════════════════════════════════════ */

.mfss-page {
    padding: 0;
    min-width: 0;
}

.mfss-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 22px;
    gap: 16px;
    flex-wrap: wrap;
}

.mfss-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.mfss-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: rgba(0,47,108,.12);
    color: var(--petron-blue);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.mfss-title h1 {
    font-size: 22px !important;
    font-weight: 800 !important;
    color: var(--petron-blue) !important;
    margin: 0 !important;
}

.mfss-title p {
    font-size: 13px;
    color: #64748b;
    margin: 4px 0 0;
}

.mfss-filter-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 22px;
    margin-bottom: 20px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}

.mfss-filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 14px;
}

.mfss-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.mfss-field label {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.mfss-input, .mfss-select {
    padding: 10px 13px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: 14px;
    color: #1e293b;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
    width: 100%;
}

.mfss-input:focus, .mfss-select:focus {
    outline: none;
    border-color: var(--petron-blue);
    box-shadow: 0 0 0 3px rgba(0,47,108,.1);
}

.mfss-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all .15s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.mfss-btn.primary {
    background: var(--petron-blue);
    color: #fff;
}

.mfss-btn.primary:hover {
    background: #002a5c;
    box-shadow: 0 4px 12px rgba(0,47,108,.3);
}

.mfss-btn.secondary {
    background: #f1f5f9;
    color: #475569;
}

.mfss-btn.secondary:hover {
    background: #e2e8f0;
}

.mfss-btn.export {
    background: #16a34a;
    color: #fff;
}

.mfss-btn.export:hover {
    background: #15803d;
}

.mfss-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.mfss-kpi {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 18px;
    text-align: center;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
}

.mfss-kpi .kpi-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: .5px;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.mfss-kpi .kpi-val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--petron-blue);
}

.mfss-kpi .kpi-sub {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 4px;
}

.mfss-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: visible !important;
    margin-bottom: 15px !important;
}

.mfss-card-header {
    padding: 16px 22px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
}

.mfss-card-header h3 {
    font-size: 15px !important;
    font-weight: 700 !important;
    color: #1e293b !important;
    margin: 0 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
}

.mfss-card-header .badge {
    margin-left: auto;
    font-size: 11px;
    color: #64748b;
    font-weight: 500;
}

.mfss-card-body {
    padding: 0;
}

.mfss-table-wrap {
    overflow:hidden;
}

.mfss-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    background: #fff;
}

.mfss-table th {
    background: #002F70 !important;
    color: #fff !important;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .3px;
    padding: 14px 13px !important;
    text-align: left;
    border: none !important;
    white-space: nowrap;
}

.mfss-table th.num {
    text-align: right !important;
}

.mfss-table td {
    padding: 12px 13px !important;
    border-bottom: 1px solid #e9ecef !important;
    color: #212529;
    vertical-align: middle;
}

.mfss-table td.num {
    text-align: right;
}

.mfss-table tr:last-child td {
    border-bottom: 1px solid #e9ecef !important;
}

.mfss-table tbody tr:hover td {
    background: #e3f2fd !important;
}

.mfss-table tbody tr {
    transition: background 0.2s ease;
}

.mfss-table .total-row td {
    background: #e3f2fd !important;
    font-weight: 800;
    color: #002F70 !important;
    border-top: 2px solid #002F70 !important;
    border-bottom: none !important;
}

.mfss-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.mfss-loading {
    text-align: center;
    padding: 48px 20px;
    color: #94a3b8;
    font-size: 14px;
}

.mfss-loading i {
    font-size: 32px;
    display: block;
    margin-bottom: 12px;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.mfss-empty {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
    font-size: 14px;
}

.mfss-empty i {
    font-size: 32px;
    display: block;
    margin-bottom: 10px;
}

.status-badge {
    display: inline-block;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .4px;
}

@media (max-width: 900px) {
    .mfss-filter-grid {
        grid-template-columns: 1fr;
    }
    .mfss-kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="mfss-page">

    <div class="mfss-header">
        <div class="mfss-title">
            <div class="mfss-icon"><i class="fas fa-chart-bar"></i></div>
            <div>
                <h1>Fuel Sales Summary</h1>
                <p>Complete 3-table summary: Meter Readings, Volume Sales, Volume & Amount</p>
            </div>
        </div>
        <button class="mfss-btn export" onclick="exportSummary()" style="display:none;" id="exportBtn">
            <i class="fas fa-file-excel"></i> Export to Excel
        </button>
    </div>

    <!-- Filter Bar -->
    <div class="mfss-filter-bar">
        <div class="mfss-filter-grid">
            <div class="mfss-field">
                <label>Date From</label>
                <input type="date" id="mfss_date_from" class="mfss-input" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mfss-field">
                <label>Date To</label>
                <input type="date" id="mfss_date_to" class="mfss-input" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="mfss-field">
                <label>Shift Period</label>
                <select id="mfss_shift" class="mfss-select">
                    <option value="">All Shifts</option>
                    <?php foreach ($shift_periods as $sp): ?>
                    <option value="<?= htmlspecialchars($sp['shift_key']) ?>">
                        <?= htmlspecialchars($sp['shift_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="mfss-btn primary" onclick="loadSummary()">
                <i class="fas fa-search"></i> Load Summary
            </button>
            <button class="mfss-btn secondary" onclick="resetFilters()">
                <i class="fas fa-undo"></i> Reset to Today
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="mfss-kpi-grid" id="mfss_kpis" style="display:none;">
        <div class="mfss-kpi">
            <div class="kpi-label"><i class="fas fa-tachometer-alt"></i> Total Liters</div>
            <div class="kpi-val" id="kpi_liters">—</div>
            <div class="kpi-sub">Volume Sold</div>
        </div>
        <div class="mfss-kpi">
            <div class="kpi-label"><i class="fas fa-peso-sign"></i> Total Amount</div>
            <div class="kpi-val" id="kpi_amount">—</div>
            <div class="kpi-sub">Gross Sales</div>
        </div>
        <div class="mfss-kpi">
            <div class="kpi-label"><i class="fas fa-list"></i> Entries</div>
            <div class="kpi-val" id="kpi_entries">—</div>
            <div class="kpi-sub">Readings Encoded</div>
        </div>
        <div class="mfss-kpi">
            <div class="kpi-label"><i class="fas fa-gas-pump"></i> Fuel Types</div>
            <div class="kpi-val" id="kpi_types">—</div>
            <div class="kpi-sub">Active Products</div>
        </div>
    </div>

    <!-- TABLE A: Meter Reading Table -->
    <div class="mfss-card">
        <div class="mfss-card-header" style="background:#f0f4ff;">
            <i class="fas fa-tachometer-alt" style="color:var(--petron-blue);"></i>
            <h3>A. Meter Reading Table</h3>
            <span class="badge">Raw pump readings + computed liters/amount per entry</span>
        </div>
        <div class="mfss-card-body">
            <div id="mfss_table_a">
                <div class="mfss-loading"><i class="fas fa-spinner"></i> Loading…</div>
            </div>
        </div>
    </div>

    <!-- TABLE B: Volume Sales Summary -->
    <div class="mfss-card">
        <div class="mfss-card-header" style="background:#f0fdf4;">
            <i class="fas fa-chart-bar" style="color:#16a34a;"></i>
            <h3 style="color:#15803d;">B. Volume Sales Summary</h3>
            <span class="badge">Consolidated liters sold per fuel type</span>
        </div>
        <div class="mfss-card-body">
            <div id="mfss_table_b">
                <div class="mfss-loading"><i class="fas fa-spinner"></i> Loading…</div>
            </div>
        </div>
    </div>

    <!-- TABLE C: Volume & Amount Summary -->
    <div class="mfss-card">
        <div class="mfss-card-header" style="background:#fefce8;">
            <i class="fas fa-file-invoice-dollar" style="color:#ca8a04;"></i>
            <h3 style="color:#92400e;">C. Volume &amp; Amount Summary</h3>
            <span class="badge">Final summary — liters + peso per fuel type with totals</span>
        </div>
        <div class="mfss-card-body">
            <div id="mfss_table_c">
                <div class="mfss-loading"><i class="fas fa-spinner"></i> Loading…</div>
            </div>
        </div>
    </div>

</div>

<script>
/* ═══════════════════════════════════════════════════════════════
   MANAGER FUEL SALES SUMMARY — JavaScript
═══════════════════════════════════════════════════════════════ */

let currentData = null;

function peso(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function liters(n) {
    return Number(n || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' L';
}

function statusBadge(s) {
    const map = {
        'pending validation': {color:'#4338ca',label:'Pending'},
        'pending':            {color:'#4338ca',label:'Pending'},
        'approved':           {color:'#0d7d3e',label:'Approved'},
        'verified':           {color:'#0d7d3e',label:'Verified'},
        'adjusted':           {color:'#1976d2',label:'Adjusted'},
        'rejected':           {color:'#c62828',label:'Rejected'},
    };
    const k = (s||'').toLowerCase().trim();
    const c = map[k] || {color:'#616161',label:s||'—'};
    return `<span class="status-badge" style="color:${c.color};">${c.label}</span>`;
}

function resetFilters() {
    const today = new Date().toISOString().slice(0,10);
    document.getElementById('mfss_date_from').value = today;
    document.getElementById('mfss_date_to').value   = today;
    document.getElementById('mfss_shift').value     = '';
    loadSummary();
}

async function loadSummary() {
    const dateFrom = document.getElementById('mfss_date_from').value;
    const dateTo   = document.getElementById('mfss_date_to').value;
    const shift    = document.getElementById('mfss_shift').value;

    // Show loading state
    ['mfss_table_a','mfss_table_b','mfss_table_c'].forEach(id => {
        document.getElementById(id).innerHTML = '<div class="mfss-loading"><i class="fas fa-spinner"></i> Loading…</div>';
    });
    document.getElementById('mfss_kpis').style.display = 'none';
    document.getElementById('exportBtn').style.display = 'none';

    try {
        const url = `./api_fuel_readings.php?action=summary&date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&shift=${encodeURIComponent(shift)}`;
        const res  = await fetch(url, {credentials:'same-origin'});
        const json = await res.json();

        if (!json.success) {
            const errHtml = `<div class="mfss-empty"><i class="fas fa-exclamation-circle" style="color:#ef4444;"></i>${json.message || 'Failed to load summary.'}</div>`;
            ['mfss_table_a','mfss_table_b','mfss_table_c'].forEach(id => {
                document.getElementById(id).innerHTML = errHtml;
            });
            return;
        }

        currentData = json;

        const mr  = json.meter_readings    || [];
        const vs  = json.vol_sales_summary  || [];
        const va  = json.vol_amt_summary    || [];
        const tot = json.totals             || {};

        // ── KPI Cards ─────────────────────────────────────────────────
        document.getElementById('kpi_liters').textContent  = liters(tot.total_liters);
        document.getElementById('kpi_amount').textContent  = peso(tot.total_amount);
        document.getElementById('kpi_entries').textContent = mr.length;
        document.getElementById('kpi_types').textContent   = va.length;
        document.getElementById('mfss_kpis').style.display = '';
        document.getElementById('exportBtn').style.display = 'inline-flex';

        // ── TABLE A: Meter Reading Table ──────────────────────────────
        if (mr.length === 0) {
            document.getElementById('mfss_table_a').innerHTML =
                '<div class="mfss-empty"><i class="fas fa-tachometer-alt"></i>No readings found for the selected period.</div>';
        } else {
            let html = `<div class="mfss-table-wrap"><table class="mfss-table">
                <thead><tr>
                    <th>Fuel Type</th>
                    <th class="num">Beginning</th>
                    <th class="num">Ending</th>
                    <th class="num">Cal</th>
                    <th class="num">Liters Sold</th>
                    <th class="num">Price/L</th>
                    <th class="num">Amount</th>
                    <th>Shift</th>
                    <th>Date</th>
                    <th>Staff</th>
                    <th>Status</th>
                </tr></thead><tbody>`;
            mr.forEach(r => {
                const shiftLabel = r.shift_name || (r.shift_period ? r.shift_period.replace(/_/g,' ') : '—');
                const dateLabel  = r.reading_date ? new Date(r.reading_date + 'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
                html += `<tr>
                    <td><strong>${r.fuel_type||'—'}</strong></td>
                    <td class="num">${Number(r.beginning||0).toFixed(2)}</td>
                    <td class="num">${Number(r.ending||0).toFixed(2)}</td>
                    <td class="num">${Number(r.cal||0).toFixed(3)}</td>
                    <td class="num"><strong>${Number(r.volume_liters||0).toFixed(2)} L</strong></td>
                    <td class="num">₱${Number(r.price_per_liter||0).toFixed(2)}</td>
                    <td class="num"><strong>${peso(r.amount)}</strong></td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;">${shiftLabel}</td>
                    <td style="font-size:11px;color:#64748b;white-space:nowrap;">${dateLabel}</td>
                    <td style="font-size:11px;">${r.staff_name||'—'}</td>
                    <td>${statusBadge(r.status)}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('mfss_table_a').innerHTML = html;
        }

        // ── TABLE B: Volume Sales Summary ─────────────────────────────
        if (vs.length === 0) {
            document.getElementById('mfss_table_b').innerHTML =
                '<div class="mfss-empty"><i class="fas fa-chart-bar"></i>No volume data for the selected period.</div>';
        } else {
            const totalVol = vs.reduce((s,r) => s + parseFloat(r.volume_sales||0), 0);
            let html = `<div class="mfss-table-wrap"><table class="mfss-table">
                <thead><tr>
                    <th>Fuel Type</th>
                    <th class="num">Volume Sales (L)</th>
                </tr></thead><tbody>`;
            vs.forEach(r => {
                html += `<tr>
                    <td><strong>${r.fuel_type||'—'}</strong></td>
                    <td class="num"><strong>${Number(r.volume_sales||0).toFixed(2)}</strong></td>
                </tr>`;
            });
            html += `<tr class="total-row">
                <td>TOTAL</td>
                <td class="num">${totalVol.toFixed(2)}</td>
            </tr>`;
            html += '</tbody></table></div>';
            document.getElementById('mfss_table_b').innerHTML = html;
        }

        // ── TABLE C: Volume & Amount Summary ──────────────────────────
        if (va.length === 0) {
            document.getElementById('mfss_table_c').innerHTML =
                '<div class="mfss-empty"><i class="fas fa-file-invoice-dollar"></i>No sales data for the selected period.</div>';
        } else {
            const totalVol2 = va.reduce((s,r) => s + parseFloat(r.volume_sales||0), 0);
            const totalAmt  = va.reduce((s,r) => s + parseFloat(r.amount_sales||0), 0);
            let html = `<div class="mfss-table-wrap"><table class="mfss-table">
                <thead><tr>
                    <th>Fuel Type</th>
                    <th class="num">Volume Sales (L)</th>
                    <th class="num">Amount Sales (₱)</th>
                </tr></thead><tbody>`;
            va.forEach(r => {
                html += `<tr>
                    <td><strong>${r.fuel_type||'—'}</strong></td>
                    <td class="num"><strong>${Number(r.volume_sales||0).toFixed(2)}</strong></td>
                    <td class="num"><strong>${peso(r.amount_sales)}</strong></td>
                </tr>`;
            });
            html += `<tr class="total-row">
                <td>TOTAL</td>
                <td class="num">${totalVol2.toFixed(2)}</td>
                <td class="num">${peso(totalAmt)}</td>
            </tr>`;
            html += '</tbody></table></div>';
            document.getElementById('mfss_table_c').innerHTML = html;
        }

    } catch (err) {
        const errHtml = `<div class="mfss-empty"><i class="fas fa-exclamation-circle" style="color:#ef4444;"></i>Network error. Please try again.</div>`;
        ['mfss_table_a','mfss_table_b','mfss_table_c'].forEach(id => {
            document.getElementById(id).innerHTML = errHtml;
        });
    }
}

function exportSummary() {
    if (!currentData) {
        alert('No data to export. Please load the summary first.');
        return;
    }

    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    const num = value => Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
    const money = value => '&#8369;' + num(value);
    const table = (title, headers, rows) => `
        <h2>${escapeHtml(title)}</h2>
        <table border="1">
            <thead><tr>${headers.map(h => `<th>${escapeHtml(h)}</th>`).join('')}</tr></thead>
            <tbody>${rows.map(row => `<tr>${row.map(cell => `<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody>
        </table><br>`;

    const filters = currentData.filters || {};
    const meterRows = currentData.meter_readings || [];
    const volumeRows = currentData.vol_sales_summary || [];
    const amountRows = currentData.vol_amt_summary || [];
    const totals = currentData.totals || {};

    const meterTable = table('Meter Reading Table', [
        'Pump', 'Fuel Type', 'Beginning', 'Ending', 'Calibration', 'Liters Sold', 'Price/L', 'Amount', 'Shift', 'Date', 'Staff', 'Status'
    ], meterRows.map(r => [
        escapeHtml(r.pump_number || r.pump_id || ''),
        escapeHtml(r.fuel_type || ''),
        num(r.beginning),
        num(r.ending),
        num(r.cal),
        num(r.volume_liters),
        money(r.price_per_liter),
        money(r.amount),
        escapeHtml(r.shift_name || r.shift_period || ''),
        escapeHtml(r.reading_date || ''),
        escapeHtml(r.staff_name || ''),
        escapeHtml(r.status || '')
    ]));

    const volumeTable = table('Volume Sales Summary', [
        'Fuel Type', 'Volume Sales (L)'
    ], volumeRows.map(r => [
        escapeHtml(r.fuel_type || ''),
        num(r.volume_sales)
    ]).concat([['TOTAL', num(totals.total_liters)]]));

    const amountTable = table('Volume & Amount Summary', [
        'Fuel Type', 'Volume Sales (L)', 'Amount Sales'
    ], amountRows.map(r => [
        escapeHtml(r.fuel_type || ''),
        num(r.volume_sales),
        money(r.amount_sales)
    ]).concat([['TOTAL', num(totals.total_liters), money(totals.total_amount)]]));

    const workbook = `
        <html>
        <head><meta charset="UTF-8"></head>
        <body>
            <h1>Fuel Sales Summary</h1>
            <p><strong>Period:</strong> ${escapeHtml(filters.date_from || '')} to ${escapeHtml(filters.date_to || '')}</p>
            ${meterTable}
            ${volumeTable}
            ${amountTable}
        </body>
        </html>`;

    const blob = new Blob(['\ufeff', workbook], {type: 'application/vnd.ms-excel;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `fuel_sales_summary_${filters.date_from || 'from'}_to_${filters.date_to || 'to'}.xls`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// Auto-load on page open
document.addEventListener('DOMContentLoaded', loadSummary);
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
