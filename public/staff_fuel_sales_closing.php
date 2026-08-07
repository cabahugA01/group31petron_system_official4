<?php
/**
 * STEP 2 — Fuel Sales Closing
 * Financial Reconciliation Page for Staff
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id     = (int)$_SESSION['user_id'];
$station_id  = (int)($_SESSION['station_id'] ?? 1);
$report_date = $_GET['date'] ?? date('Y-m-d');
$shift       = $_GET['shift'] ?? 'General';
$page_id     = 'fuel';

include __DIR__ . '/../partials/header.php';
?>

<div class="main-content" style="padding: 20px 0 !important;">
    <style>
        .closing-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .closing-header {
            background: linear-gradient(135deg, #002F70 0%, #001A40 100%);
            color: white;
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,47,112,0.15);
        }
        .closing-header h1 {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .closing-header p {
            margin: 0;
            font-size: 13px;
            opacity: 0.85;
        }
        .closing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(540px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 600px) {
            .closing-grid {
                grid-template-columns: 1fr;
            }
        }
        .closing-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }
        .closing-card-header {
            font-size: 15px;
            font-weight: 800;
            color: #002F70;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .closing-card-header .badge-tag {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            margin-left: auto;
        }
        .form-row-custom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px dashed #f1f5f9;
        }
        .form-row-custom:last-child {
            border-bottom: none;
        }
        .form-row-custom label {
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        .form-row-custom input {
            width: 180px;
            text-align: right;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-row-custom input:focus {
            border-color: #002F70;
            box-shadow: 0 0 0 3px rgba(0,47,112,0.1);
        }
        .form-row-custom input[readonly] {
            background: #f8fafc;
            color: #475569;
            border-color: #e2e8f0;
            cursor: not-allowed;
        }
        .form-row-custom.total-row {
            background: #f0f9ff;
            margin: 12px -20px -20px -20px;
            padding: 14px 20px;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
            border-top: 1px solid #bae6fd;
        }
        .form-row-custom.total-row label {
            font-size: 14px;
            font-weight: 800;
            color: #0369a1;
        }
        .form-row-custom.total-row input {
            background: #ffffff;
            color: #0369a1;
            font-size: 15px;
            font-weight: 800;
            border-color: #7dd3fc;
        }
        .overall-highlight {
            background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
            border: 2px solid #cbd5e1;
        }
        .action-bar {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 12px;
            margin-top: 24px;
            padding: 16px 20px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
        }
        .btn-closing {
            padding: 10px 20px;
            font-size: 13px;
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
        .btn-save {
            background: #002F70;
            color: #ffffff;
        }
        .btn-save:hover {
            background: #001f4d;
            box-shadow: 0 4px 8px rgba(0,47,112,0.25);
        }
        .btn-report {
            background: #16a34a;
            color: #ffffff;
        }
        .btn-report:hover {
            background: #15803d;
            box-shadow: 0 4px 8px rgba(22,163,74,0.25);
        }
        .btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn-cancel:hover {
            background: #e2e8f0;
        }
    </style>

    <div class="closing-container">
        <!-- Header -->
        <div class="closing-header">
            <div>
                <h1><i class="fas fa-file-invoice-dollar"></i> STEP 2 — Fuel Sales Closing</h1>
                <p>Financial Reconciliation for Date: <strong><?= htmlspecialchars($report_date) ?></strong> | Shift: <strong><?= htmlspecialchars($shift) ?></strong></p>
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
                <!-- A. Fuel Summary (Read-Only) -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <i class="fas fa-gas-pump"></i> A. Fuel Summary
                        <span class="badge-tag">Auto-Computed</span>
                    </div>
                    <div class="form-row-custom">
                        <label>Diesel Sales (₱)</label>
                        <input type="text" id="diesel_sales" name="diesel_sales" readonly value="0.00">
                    </div>
                    <div class="form-row-custom">
                        <label>Turbo Diesel Sales (₱)</label>
                        <input type="text" id="turbo_diesel_sales" name="turbo_diesel_sales" readonly value="0.00">
                    </div>
                    <div class="form-row-custom">
                        <label>XCS Plus Sales (₱)</label>
                        <input type="text" id="xcs_plus_sales" name="xcs_plus_sales" readonly value="0.00">
                    </div>
                    <div class="form-row-custom">
                        <label>XTRA Advance Sales (₱)</label>
                        <input type="text" id="xtra_advance_sales" name="xtra_advance_sales" readonly value="0.00">
                    </div>
                    <div class="form-row-custom">
                        <label>Kerosene Sales (₱)</label>
                        <input type="text" id="kerosene_sales" name="kerosene_sales" readonly value="0.00">
                    </div>
                    <div class="form-row-custom">
                        <label>Total Liters Sold (L)</label>
                        <input type="text" id="total_liters" name="total_liters" readonly value="0.00">
                    </div>
                    <div class="form-row-custom total-row">
                        <label>Total Fuel Sales (₱)</label>
                        <input type="text" id="total_fuel_sales" name="total_fuel_sales" readonly value="0.00">
                    </div>
                </div>

                <!-- B. Shop / Store Sales Income -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <i class="fas fa-store"></i> B. Shop / Store Sales Income
                        <span class="badge-tag" style="background:#fef3c7; color:#b45309;">Manual + Auto</span>
                    </div>
                    <div class="form-row-custom">
                        <label>OLG Sales (₱)</label>
                        <input type="number" step="0.01" id="olg_sales" name="olg_sales" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>TBA Sales (₱)</label>
                        <input type="number" step="0.01" id="tba_sales" name="tba_sales" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>Services Income (Job Orders) (₱)</label>
                        <input type="number" step="0.01" id="service_income" name="service_income" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>Other Sales (₱)</label>
                        <input type="number" step="0.01" id="other_sales" name="other_sales" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>A/R Collected (₱)</label>
                        <input type="number" step="0.01" id="ar_collected" name="ar_collected" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom total-row">
                        <label>Total Store Sales Income (₱)</label>
                        <input type="text" id="total_store_sales" name="total_store_sales" readonly value="0.00">
                    </div>
                </div>

                <!-- C. Cash Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <i class="fas fa-money-bill-wave"></i> C. Cash Summary
                        <span class="badge-tag" style="background:#fef3c7; color:#b45309;">Manual Input</span>
                    </div>
                    <div class="form-row-custom">
                        <label>Shift 1 Cash Collection (₱)</label>
                        <input type="number" step="0.01" id="cash_shift1" name="cash_shift1" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>Shift 2 Cash Collection (₱)</label>
                        <input type="number" step="0.01" id="cash_shift2" name="cash_shift2" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom total-row">
                        <label>Total Cash Collection (₱)</label>
                        <input type="text" id="total_cash" name="total_cash" readonly value="0.00">
                    </div>
                </div>

                <!-- D. Accounts Receivable Summary -->
                <div class="closing-card">
                    <div class="closing-card-header">
                        <i class="fas fa-file-invoice"></i> D. Accounts Receivable Summary
                        <span class="badge-tag" style="background:#fef3c7; color:#b45309;">Manual Input</span>
                    </div>
                    <div class="form-row-custom">
                        <label>Shift 1 A/R (₱)</label>
                        <input type="number" step="0.01" id="ar_shift1" name="ar_shift1" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom">
                        <label>Shift 2 A/R (₱)</label>
                        <input type="number" step="0.01" id="ar_shift2" name="ar_shift2" value="0.00" oninput="calculateClosingTotals()">
                    </div>
                    <div class="form-row-custom total-row">
                        <label>Total Accounts Receivable (₱)</label>
                        <input type="text" id="total_ar" name="total_ar" readonly value="0.00">
                    </div>
                </div>

                <!-- E. Overall Summary -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <i class="fas fa-calculator"></i> E. Overall Summary
                        <span class="badge-tag" style="background:#dcfce7; color:#15803d;">Auto-Computed</span>
                    </div>
                    <div class="form-row-custom">
                        <label>Fuel Sales + Store Sales = Gross Sales (₱)</label>
                        <input type="text" id="gross_sales" name="gross_sales" readonly value="0.00" style="font-weight:800; color:#002F70;">
                    </div>
                    <div class="form-row-custom total-row">
                        <label>Gross Sales − A/R = Expected Cash (₱)</label>
                        <input type="text" id="expected_cash" name="expected_cash" readonly value="0.00">
                    </div>
                </div>

                <!-- F. Total Cash in Bank -->
                <div class="closing-card overall-highlight">
                    <div class="closing-card-header">
                        <i class="fas fa-university"></i> F. Total Cash in Bank
                        <span class="badge-tag">Reconciliation</span>
                    </div>
                    <div class="form-row-custom total-row" style="margin-top:0;">
                        <label>Total Cash in Bank (₱)</label>
                        <input type="number" step="0.01" id="total_cash_bank" name="total_cash_bank" value="0.00">
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="action-bar">
                <a href="staff_transactions_hub.php?section=fuel" class="btn-closing btn-cancel">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="button" onclick="saveClosingData(false)" class="btn-closing btn-save">
                    <i class="fas fa-save"></i> Save Closing
                </button>
                <button type="button" onclick="saveClosingData(true)" class="btn-closing btn-report">
                    <i class="fas fa-print"></i> Generate Fuel Sales Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    loadFuelSummaryData();
});

async function loadFuelSummaryData() {
    const reportDate = '<?= htmlspecialchars($report_date) ?>';
    try {
        const response = await fetch(`staff_fuel_sales_closing_handler.php?action=get_summary&date=${encodeURIComponent(reportDate)}`);
        const result = await response.json();
        
        if (result.success) {
            const fuel = result.auto_fuel_summary;
            document.getElementById('diesel_sales').value       = fuel.diesel_sales.toFixed(2);
            document.getElementById('turbo_diesel_sales').value = fuel.turbo_diesel_sales.toFixed(2);
            document.getElementById('xcs_plus_sales').value     = fuel.xcs_plus_sales.toFixed(2);
            document.getElementById('xtra_advance_sales').value = fuel.xtra_advance_sales.toFixed(2);
            document.getElementById('kerosene_sales').value     = fuel.kerosene_sales.toFixed(2);
            document.getElementById('total_liters').value       = fuel.total_liters_sold.toFixed(2);
            document.getElementById('total_fuel_sales').value   = fuel.total_fuel_sales.toFixed(2);

            if (result.auto_service_income > 0) {
                document.getElementById('service_income').value = result.auto_service_income.toFixed(2);
            }

            // Populate existing saved fields if any
            if (result.existing_closing) {
                const ex = result.existing_closing;
                document.getElementById('olg_sales').value       = parseFloat(ex.olg_sales || 0).toFixed(2);
                document.getElementById('tba_sales').value       = parseFloat(ex.tba_sales || 0).toFixed(2);
                document.getElementById('service_income').value = parseFloat(ex.service_income || 0).toFixed(2);
                document.getElementById('other_sales').value     = parseFloat(ex.other_sales || 0).toFixed(2);
                document.getElementById('ar_collected').value    = parseFloat(ex.ar_collected || 0).toFixed(2);
                document.getElementById('cash_shift1').value     = parseFloat(ex.cash_shift1 || 0).toFixed(2);
                document.getElementById('cash_shift2').value     = parseFloat(ex.cash_shift2 || 0).toFixed(2);
                document.getElementById('ar_shift1').value       = parseFloat(ex.ar_shift1 || 0).toFixed(2);
                document.getElementById('ar_shift2').value       = parseFloat(ex.ar_shift2 || 0).toFixed(2);
                document.getElementById('total_cash_bank').value = parseFloat(ex.total_cash_bank || 0).toFixed(2);
            }

            calculateClosingTotals();
        }
    } catch (e) {
        console.error('Failed to load summary data:', e);
    }
}

function calculateClosingTotals() {
    const fuelSales = parseFloat(document.getElementById('total_fuel_sales').value) || 0;

    const olg     = parseFloat(document.getElementById('olg_sales').value) || 0;
    const tba     = parseFloat(document.getElementById('tba_sales').value) || 0;
    const service = parseFloat(document.getElementById('service_income').value) || 0;
    const other   = parseFloat(document.getElementById('other_sales').value) || 0;
    const arColl  = parseFloat(document.getElementById('ar_collected').value) || 0;

    const totalStore = olg + tba + service + other + arColl;
    document.getElementById('total_store_sales').value = totalStore.toFixed(2);

    const cash1 = parseFloat(document.getElementById('cash_shift1').value) || 0;
    const cash2 = parseFloat(document.getElementById('cash_shift2').value) || 0;
    const totalCash = cash1 + cash2;
    document.getElementById('total_cash').value = totalCash.toFixed(2);

    const ar1 = parseFloat(document.getElementById('ar_shift1').value) || 0;
    const ar2 = parseFloat(document.getElementById('ar_shift2').value) || 0;
    const totalAr = ar1 + ar2;
    document.getElementById('total_ar').value = totalAr.toFixed(2);

    const grossSales = fuelSales + totalStore;
    document.getElementById('gross_sales').value = grossSales.toFixed(2);

    const expectedCash = grossSales - totalAr;
    document.getElementById('expected_cash').value = expectedCash.toFixed(2);

    const bankInput = document.getElementById('total_cash_bank');
    if (!bankInput.dataset.userEdited) {
        bankInput.value = expectedCash.toFixed(2);
    }
}

document.getElementById('total_cash_bank').addEventListener('input', function() {
    this.dataset.userEdited = 'true';
});

async function saveClosingData(generateReport = false) {
    const form = document.getElementById('fuelClosingForm');
    const formData = new FormData(form);
    formData.append('action', 'save_closing');

    try {
        const response = await fetch('staff_fuel_sales_closing_handler.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            alert('Fuel Sales Closing saved successfully!');
            if (generateReport) {
                window.location.href = `staff_fuel_sales_report.php?date=${encodeURIComponent(result.report_date)}`;
            } else {
                window.location.href = 'staff_transactions_hub.php?section=fuel';
            }
        } else {
            alert('Error: ' + (result.message || 'Failed to save closing.'));
        }
    } catch (e) {
        alert('Server error while saving closing: ' + e.message);
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
