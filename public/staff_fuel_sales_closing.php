<?php
/**
 * Official Petron Fuel Sales Closing Page
 * Staff enters raw numbers gikan sa external paper report, system automatically computes all derived totals.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me         = $_SESSION['user'] ?? [];
$station_id = (int)($_SESSION['station_id'] ?? $me['station_id'] ?? 1);
$user_id    = (int)$_SESSION['user_id'];
$user_name  = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'JUDY LASTIMOSA';

$report_date = $_GET['date'] ?? date('Y-m-d');
$shift       = $_GET['shift'] ?? 'Second Shift';

$page_title = "Fuel Sales Closing";
include __DIR__ . '/../partials/header.php';
?>

<div class="main-content" style="padding: 24px; background: #f8fafc; min-height: 100vh;">
    <style>
        .closing-container {
            max-width: 1280px;
            margin: 0 auto;
            padding-bottom: 12px;
        }
        .txn-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .txn-section-title h1 {
            font-size: 24px;
            font-weight: 800;
            color: #002F70;
            margin: 0 0 4px 0;
        }
        .txn-section-title p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }
        .closing-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .closing-grid { grid-template-columns: 1fr; }
        }
        .closing-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            padding: 20px;
        }
        .closing-card.full-width {
            grid-column: 1 / -1;
        }
        .closing-card-header {
            font-size: 15px;
            font-weight: 700;
            color: #002F70;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 10px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .badge-tag {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
            background: #e2e8f0;
            color: #475569;
        }
        .form-row-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .form-row-custom label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            flex: 1;
        }
        .form-row-custom input {
            width: 180px;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            text-align: right;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            transition: all 0.15s ease-in-out;
        }
        .form-row-custom input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }
        .form-row-custom input[readonly] {
            background: #f8fafc;
            color: #475569;
            border-color: #e2e8f0;
        }
        .form-row-custom.total-row {
            border-top: 1px dashed #cbd5e1;
            padding-top: 12px;
            margin-top: 12px;
        }
        .form-row-custom.total-row label {
            font-size: 14px;
            font-weight: 700;
            color: #002F70;
        }
        .form-row-custom.total-row input {
            font-size: 14px;
            font-weight: 700;
            color: #002F70;
            background: #f0f7ff;
            border-color: #93c5fd;
        }
        .overall-highlight {
            background: #f0f7ff;
            border-color: #bfdbfe;
        }
        .closing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .closing-table th {
            background: #002F70;
            color: #ffffff;
            font-weight: 700;
            padding: 8px 10px;
            text-align: left;
            border: 1px solid #001f4d;
        }
        .closing-table td {
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            color: #1e293b;
        }
        .closing-table tr:nth-child(even) {
            background: #f8fafc;
        }
        .closing-table .total-tr td {
            font-weight: 800;
            background: #e8f0fe;
            border-top: 2px solid #002F70;
            color: #002F70;
        }
        .action-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-top: 12px;
            margin-bottom: 12px;
            padding: 4px 0;
            background: transparent;
            border: none;
            box-shadow: none;
        }
        .btn-closing {
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-save { background: #002F70; color: #ffffff; box-shadow: 0 2px 6px rgba(0,47,112,0.3); }
        .btn-save:hover { background: #001f4d; box-shadow: 0 4px 12px rgba(0,47,112,0.4); }
        .btn-cancel { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-cancel:hover { background: #e2e8f0; }
    </style>

    <div class="closing-container">
        <!-- Header -->
        <div class="txn-section-header">
            <div class="txn-section-title">
                <div>
                    <h1>FUEL SALES CLOSING</h1>
                    <p>Shift: <strong style="color:#0f172a;"><?= htmlspecialchars($shift) ?></strong> | Date: <strong><?= htmlspecialchars($report_date) ?></strong></p>
                </div>
            </div>
            <div>
                <a href="staff_transactions_hub.php?section=fuel" class="btn-closing btn-cancel">
                    <i class="fas fa-arrow-left"></i> Back to Meter Readings
                </a>
            </div>
        </div>

        <form id="fuelClosingForm">
            <input type="hidden" name="report_date" value="<?= htmlspecialchars($report_date) ?>">
            <input type="hidden" name="shift" value="<?= htmlspecialchars($shift) ?>">

            <div class="closing-grid">
                <!-- Meter Reading (Full Width Table) -->
                <div class="closing-card full-width">
                    <div class="closing-card-header">
                        <span><i class="fas fa-gas-pump me-1"></i> Meter Reading</span>
                    </div>
                    <div style="overflow-x:auto;">
                        <table class="closing-table" id="fuelSalesSummaryTable">
                            <thead>
                                <tr>
                                    <th>Pump / Nozzle Name</th>
                                    <th>Fuel Type</th>
                                    <th style="text-align:right;">Beginning</th>
                                    <th style="text-align:right;">Ending</th>
                                    <th style="text-align:right;">Calib (L)</th>
                                    <th style="text-align:right;">Volume Liters (L)</th>
                                    <th style="text-align:right;">Price / L</th>
                                    <th style="text-align:right;">Amount (₱)</th>
                                </tr>
                            </thead>
                            <tbody id="fuelSalesSummaryTbody">
                                <tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b;">Loading meter readings data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Volume Sales Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-filter me-1"></i> Volume Sales Summary</span>
                    </div>
                    <table class="closing-table">
                        <thead>
                            <tr>
                                <th>Fuel Type</th>
                                <th style="text-align:right;">Volume Sales (L)</th>
                                <th style="text-align:right;">Amount (₱)</th>
                            </tr>
                        </thead>
                        <tbody id="volSalesTbody">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>

                <!-- Volume & Amount Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-chart-pie me-1"></i> Volume & Amount Summary</span>
                    </div>
                    <div class="form-row-custom total-row" style="border:none; margin-top:0; padding-top:0;">
                        <label style="font-size:16px;">TOTAL LITERS</label>
                        <input type="text" id="summary_total_liters" readonly value="0.00 L" style="font-size:16px; font-weight:800; color:#15803d; width:220px;">
                    </div>
                    <div class="form-row-custom total-row" style="margin-top:16px;">
                        <label style="font-size:16px;">TOTAL AMOUNT</label>
                        <input type="text" id="summary_total_amount" readonly value="₱0.00" style="font-size:18px; font-weight:800; color:#002F70; width:220px; background:#e8f0fe;">
                    </div>
                </div>

                <!-- Tank Liters Summary -->
                <div class="closing-card full-width">
                    <div class="closing-card-header">
                        <span><i class="fas fa-database me-1"></i> Tank Liters Summary</span>
                    </div>
                    <table class="closing-table">
                        <thead>
                            <tr>
                                <th>Tank / Pump Name</th>
                                <th style="text-align:right;">Liters Sold (L)</th>
                            </tr>
                        </thead>
                        <tbody id="tankSummaryTbody">
                            <!-- Populated via JS -->
                        </tbody>
                        <tfoot>
                            <tr class="total-tr">
                                <td>TOTAL TANK LITERS</td>
                                <td style="text-align:right;" id="tankTotalLiters">0.00 L</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php
                $role = role_key($_SESSION['user']['role'] ?? $_SESSION['role'] ?? 'staff');
                $is_mgr_admin = in_array($role, ['manager', 'admin', 'superadmin', 'developer']);

                $current_shift_str = strtolower(trim($shift));
                $is_s1 = (strpos($current_shift_str, '1') !== false || strpos($current_shift_str, 'first') !== false);
                $is_s2 = (strpos($current_shift_str, '2') !== false || strpos($current_shift_str, 'second') !== false);

                // Default to S1 if ambiguous
                if (!$is_s1 && !$is_s2) {
                    $is_s1 = true;
                }
                ?>

                <!-- Cash Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-money-bill-wave me-1"></i> Cash Summary</span>
                    </div>
                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_cash_shift1">
                        <label>Shift 1 Cash (₱)</label>
                        <input type="text" id="cash_shift1" name="cash_shift1" value="0.00" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="cash_shift1" name="cash_shift1" value="0.00">
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_cash_shift2">
                        <label>Shift 2 Cash (₱)</label>
                        <input type="text" id="cash_shift2" name="cash_shift2" value="0.00" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="cash_shift2" name="cash_shift2" value="0.00">
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>Total Cash (₱)</label>
                        <input type="text" id="total_cash" name="total_cash" readonly value="0.00" style="font-weight:800; color:#002F70;">
                    </div>
                </div>

                <!-- A/R Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <span><i class="fas fa-file-invoice me-1"></i> A/R Summary</span>
                    </div>
                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_ar_shift1">
                        <label>Shift 1 A/R (₱)</label>
                        <input type="text" id="ar_shift1" name="ar_shift1" value="0.00" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="ar_shift1" name="ar_shift1" value="0.00">
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_ar_shift2">
                        <label>Shift 2 A/R (₱)</label>
                        <input type="text" id="ar_shift2" name="ar_shift2" value="0.00" placeholder="0.00" onfocus="formatClosingFocus(this)" oninput="formatClosingInput(this); calculateClosingTotals()" onblur="formatClosingBlur(this); calculateClosingTotals()" onkeydown="handleClosingKeydown(event, this)" autocomplete="off">
                    </div>
                    <?php else: ?>
                        <input type="hidden" id="ar_shift2" name="ar_shift2" value="0.00">
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>Total A/R (₱)</label>
                        <input type="text" id="total_ar" name="total_ar" readonly value="0.00" style="font-weight:800; color:#002F70;">
                    </div>
                </div>

                <!-- Overall Summary -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <span><i class="fas fa-calculator me-1"></i> Overall Summary</span>
                    </div>
                    <div class="form-row-custom">
                        <label>TOTAL FUEL AMOUNT SALES (₱)</label>
                        <input type="text" id="ov_total_fuel_amount" readonly value="0.00" style="font-weight:800; color:#002F70;">
                    </div>

                    <?php if ($is_mgr_admin || $is_s1): ?>
                    <div class="form-row-custom" id="row_ov_ar_shift1">
                        <label>LESS: A/R SHIFT 1 (₱)</label>
                        <input type="text" id="ov_ar_shift1" readonly value="0.00" style="color:#dc2626;">
                    </div>
                    <?php endif; ?>

                    <?php if ($is_mgr_admin || $is_s2): ?>
                    <div class="form-row-custom" id="row_ov_ar_shift2">
                        <label>LESS: A/R SHIFT 2 (₱)</label>
                        <input type="text" id="ov_ar_shift2" readonly value="0.00" style="color:#dc2626;">
                    </div>
                    <?php endif; ?>

                    <div class="form-row-custom total-row">
                        <label>NET CASH / REMAINING AMOUNT (₱)</label>
                        <input type="text" id="net_sales" name="net_sales" readonly value="0.00" style="font-size:16px; font-weight:800; color:#15803d; background:#dcfce7;">
                    </div>
                </div>

                <!-- Total Cash in Bank -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <span><i class="fas fa-university me-1"></i> Total Cash in Bank</span>
                    </div>
                    <div class="form-row-custom total-row" style="border:none; margin-top:20px; padding-top:0;">
                        <label style="font-size:16px;">TOTAL CASH IN BANK (₱)</label>
                        <input type="text" id="total_cash_bank" name="total_cash_bank" readonly value="0.00" style="font-size:18px; font-weight:800; color:#002F70; width:220px; background:#e8f0fe;">
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <a href="staff_transactions_hub.php?section=fuel" class="btn-closing btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" onclick="saveClosingData()" class="btn-closing btn-save">
                    <i class="fas fa-save"></i> Save Closing
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetchSummary();
});

function parseVal(val) {
    if (!val) return 0;
    const clean = val.toString().replace(/,/g, '').trim();
    const num = parseFloat(clean);
    return isNaN(num) ? 0 : num;
}

function formatNum(num) {
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatClosingFocus(input) {
    const val = input.value.trim();
    if (val === '0.00' || val === '0' || val === '0.0') {
        input.value = '';
    }
}

function formatClosingInput(input) {
    let val = input.value;
    let parts = val.replace(/[^0-9.]/g, '').split('.');
    if (parts.length > 2) {
        val = parts[0] + '.' + parts.slice(1).join('');
    } else {
        val = val.replace(/[^0-9.]/g, '');
    }
    input.value = val;
}

function formatClosingBlur(input) {
    const clean = input.value.toString().replace(/,/g, '').trim();
    if (clean === '' || isNaN(parseFloat(clean))) {
        input.value = '0.00';
    } else {
        const num = parseFloat(clean);
        input.value = formatNum(num);
    }
}

function handleClosingKeydown(e, input) {
    const key = e.key;
    if (key !== 'Enter' && key !== 'ArrowDown' && key !== 'ArrowUp') {
        return;
    }

    e.preventDefault();
    if (typeof formatClosingBlur === 'function') formatClosingBlur(input);
    if (typeof calculateClosingTotals === 'function') calculateClosingTotals();

    const editableInputs = Array.from(document.querySelectorAll('#fuelClosingForm input:not([readonly]):not([type="hidden"])'));
    const currentIdx = editableInputs.indexOf(input);
    let targetInput = null;

    if (key === 'Enter' || key === 'ArrowDown') {
        if (currentIdx !== -1 && currentIdx < editableInputs.length - 1) {
            targetInput = editableInputs[currentIdx + 1];
        }
    } else if (key === 'ArrowUp') {
        if (currentIdx > 0) {
            targetInput = editableInputs[currentIdx - 1];
        }
    }

    if (targetInput) {
        targetInput.focus();
        if (typeof formatClosingFocus === 'function') {
            formatClosingFocus(targetInput);
        }
        if (typeof targetInput.scrollIntoView === 'function') {
            targetInput.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }
}

async function fetchSummary() {
    const reportDate = '<?= htmlspecialchars($report_date) ?>';
    try {
        const response = await fetch(`staff_fuel_sales_closing_handler.php?action=get_summary&date=${encodeURIComponent(reportDate)}`);
        const result = await response.json();
        
        if (result.success) {
            // A. Meter Reading Table
            const meterRows = result.meter_rows || [];
            const tbody = document.getElementById('fuelSalesSummaryTbody');
            tbody.innerHTML = '';

            if (meterRows.length > 0) {
                meterRows.forEach(r => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-weight:700;">${r.pump_name || r.fuel_type}</td>
                        <td>${r.fuel_type}</td>
                        <td style="text-align:right;">${formatNum(parseFloat(r.beginning_reading))}</td>
                        <td style="text-align:right;">${formatNum(parseFloat(r.ending_reading))}</td>
                        <td style="text-align:right; color:#d97706;">${formatNum(parseFloat(r.calibration))}</td>
                        <td style="text-align:right; font-weight:700; color:#15803d;">${formatNum(parseFloat(r.liters_sold))} L</td>
                        <td style="text-align:right;">₱${formatNum(parseFloat(r.price_per_liter))}</td>
                        <td style="text-align:right; font-weight:800; color:#002F70;">₱${formatNum(parseFloat(r.total_amount))}</td>
                    `;
                    tbody.appendChild(tr);
                });
            } else {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:20px; color:#64748b; font-style:italic;">No meter readings encoded for this date. Default fuel types loaded.</td></tr>`;
            }

            // B. Volume Sales Summary Table
            const byFuel = result.by_fuel || {};
            const volTbody = document.getElementById('volSalesTbody');
            volTbody.innerHTML = '';
            let sumVol = 0;
            let sumAmt = 0;

            Object.keys(byFuel).forEach(fKey => {
                const vol = byFuel[fKey].liters || 0;
                const amt = byFuel[fKey].amount || 0;
                sumVol += vol;
                sumAmt += amt;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:700;">${fKey}</td>
                    <td style="text-align:right; font-weight:700; color:#15803d;">${formatNum(vol)} L</td>
                    <td style="text-align:right; font-weight:800; color:#002F70;">₱${formatNum(amt)}</td>
                `;
                volTbody.appendChild(tr);
            });

            // C. Volume & Amount Summary Totals
            const totalFuelSales = result.totals.total_fuel_sales || sumAmt;
            const totalLitersSold = result.totals.total_liters_sold || sumVol;
            document.getElementById('summary_total_liters').value = formatNum(totalLitersSold) + ' L';
            document.getElementById('summary_total_amount').value = '₱' + formatNum(totalFuelSales);

            // D. Tank Liters Summary Table
            const tankSummary = result.tank_summary || {};
            const tankTbody = document.getElementById('tankSummaryTbody');
            tankTbody.innerHTML = '';
            let sumTankLiters = 0;

            Object.keys(tankSummary).forEach(tName => {
                const tVol = tankSummary[tName] || 0;
                sumTankLiters += tVol;
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="font-weight:700;">${tName}</td>
                    <td style="text-align:right; font-weight:700; color:#15803d;">${formatNum(tVol)} L</td>
                `;
                tankTbody.appendChild(tr);
            });
            document.getElementById('tankTotalLiters').textContent = formatNum(sumTankLiters) + ' L';

            // Populate existing saved fields if any
            if (result.existing_closing) {
                const ex = result.existing_closing;
                document.getElementById('cash_shift1').value = formatNum(parseFloat(ex.cash_shift1 || 0));
                document.getElementById('cash_shift2').value = formatNum(parseFloat(ex.cash_shift2 || 0));
                
                document.getElementById('ar_shift1').value   = formatNum(parseFloat(ex.ar_shift1 || 0));
                document.getElementById('ar_shift2').value   = formatNum(parseFloat(ex.ar_shift2 || 0));
            }

            calculateClosingTotals();
        }
    } catch (e) {
        console.error('Failed to load summary data:', e);
    }
}

function calculateClosingTotals() {
    const elCash1 = document.getElementById('cash_shift1');
    const elCash2 = document.getElementById('cash_shift2');
    const cashS1 = elCash1 ? parseVal(elCash1.value) : 0;
    const cashS2 = elCash2 ? parseVal(elCash2.value) : 0;
    const totalCash = cashS1 + cashS2;
    if (document.getElementById('total_cash')) {
        document.getElementById('total_cash').value = formatNum(totalCash);
    }

    const elAr1 = document.getElementById('ar_shift1');
    const elAr2 = document.getElementById('ar_shift2');
    const arS1 = elAr1 ? parseVal(elAr1.value) : 0;
    const arS2 = elAr2 ? parseVal(elAr2.value) : 0;
    const totalAr = arS1 + arS2;
    if (document.getElementById('total_ar')) {
        document.getElementById('total_ar').value = formatNum(totalAr);
    }

    const totalFuelAmountElem = document.getElementById('summary_total_amount');
    const totalFuelAmountStr  = totalFuelAmountElem ? totalFuelAmountElem.value.replace(/[^0-9.]/g, '') : '0';
    const totalFuelAmount     = parseFloat(totalFuelAmountStr) || 0;

    if (document.getElementById('ov_total_fuel_amount')) {
        document.getElementById('ov_total_fuel_amount').value = formatNum(totalFuelAmount);
    }
    if (document.getElementById('ov_ar_shift1')) {
        document.getElementById('ov_ar_shift1').value = formatNum(arS1);
    }
    if (document.getElementById('ov_ar_shift2')) {
        document.getElementById('ov_ar_shift2').value = formatNum(arS2);
    }

    const netSales = totalFuelAmount - arS1 - arS2;
    if (document.getElementById('net_sales')) {
        document.getElementById('net_sales').value = formatNum(netSales);
    }

    if (document.getElementById('total_cash_bank')) {
        document.getElementById('total_cash_bank').value = formatNum(totalCash > 0 ? totalCash : netSales);
    }
}

async function saveClosingData() {
    const form = document.getElementById('fuelClosingForm');
    const formData = new FormData(form);
    
    // Clean formatted numbers before submitting
    const numberFields = ['cash_shift1', 'cash_shift2', 'total_cash', 'ar_shift1', 'ar_shift2', 'total_ar', 'net_sales', 'total_cash_bank'];
    numberFields.forEach(field => {
        const input = document.getElementById(field);
        if (input) {
            formData.set(field, parseVal(input.value).toFixed(2));
        }
    });

    const totalFuelAmountStr = document.getElementById('summary_total_amount').value.replace(/[^0-9.]/g, '');
    const totalLitersStr     = document.getElementById('summary_total_liters').value.replace(/[^0-9.]/g, '');
    formData.set('total_fuel_sales', (parseFloat(totalFuelAmountStr) || 0).toFixed(2));
    formData.set('total_liters', (parseFloat(totalLitersStr) || 0).toFixed(2));

    formData.append('action', 'save_closing');

    try {
        const response = await fetch('staff_fuel_sales_closing_handler.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            window.location.href = 'staff_transactions_hub.php?section=fuel&closing_saved=1';
        } else {
            alert('Error: ' + (result.message || 'Failed to save closing.'));
        }
    } catch (e) {
        alert('Server error while saving closing: ' + e.message);
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
