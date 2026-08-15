<?php
/**
 * STEP 3 — Daily Fuel Sales Report
 * Printable Report Matching Petron Format
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$station_id  = (int)($_SESSION['station_id'] ?? 1);
$report_date = $_GET['date'] ?? date('Y-m-d');
$shift       = trim($_GET['shift'] ?? '');

$shift_key = '';
$shift_lower = strtolower($shift);
if (strpos($shift_lower, '1') !== false || strpos($shift_lower, 'first') !== false) {
    $shift_key = 'first';
} elseif (strpos($shift_lower, '2') !== false || strpos($shift_lower, 'second') !== false) {
    $shift_key = 'second';
}

// Fetch Station Info
try {
    $stmt_st = $pdo->prepare("SELECT name AS station_name, COALESCE(address, location, 'Vamenta Blvd., Carmen, City Of Cagayan De Oro , Misamis Oriental') AS address FROM stations WHERE id = ?");
    $stmt_st->execute([$station_id]);
    $station = $stmt_st->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $station = [];
}
$station_name = $station['station_name'] ?? 'Petron Station';
$station_addr = $station['address'] ?? 'Vamenta Blvd., Carmen, City Of Cagayan De Oro , Misamis Oriental';

// Fetch Saved Closing Data
$stmt_cl = $pdo->prepare("
    SELECT * FROM fuel_sales_closing
    WHERE station_id = ? AND report_date = ? AND (? = '' OR shift = ? OR shift_period = ?)
    ORDER BY id DESC LIMIT 1
");
$stmt_cl->execute([$station_id, $report_date, $shift, $shift, $shift_key]);
$closing = $stmt_cl->fetch(PDO::FETCH_ASSOC) ?: [];

// Fetch Meter Readings / Transactions for Date and Shift
$stmt_readings = $pdo->prepare("
    SELECT 
        pump_id, fuel_type, present_reading, previous_reading, calibration,
        price_per_liter, liters_sold, total_amount, shift_period
    FROM fuel_transactions
    WHERE station_id = ? 
      AND (DATE(transaction_date) = ? OR (transaction_date IS NULL AND DATE(created_at) = ?))
      AND (? = '' OR shift_period = ? OR shift_name = ?)
      AND LOWER(COALESCE(status,'')) NOT IN ('rejected','voided','cancelled','canceled')
    ORDER BY pump_id ASC, id ASC
");
$stmt_readings->execute([$station_id, $report_date, $report_date, $shift, $shift_key, $shift]);
$readings = $stmt_readings->fetchAll(PDO::FETCH_ASSOC);

// Fetch Tank Inventories
$stmt_tanks = $pdo->prepare("
    SELECT fuel_type, current_level, capacity, reorder_level
    FROM fuel_inventory
    WHERE station_id = ?
");
$stmt_tanks->execute([$station_id]);
$tanks = $stmt_tanks->fetchAll(PDO::FETCH_ASSOC);

$encoder_id = $closing['encoded_by'] ?? $_SESSION['user_id'];
try {
    $stmt_user = $pdo->prepare("SELECT CONCAT_WS(' ', first_name, last_name) AS full_name, role FROM users WHERE id = ?");
    $stmt_user->execute([$encoder_id]);
    $encoder = $stmt_user->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $encoder = [];
}
$encoder_name = trim($encoder['full_name'] ?? '') ?: ($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff User');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Fuel Sales Report — <?= htmlspecialchars($report_date) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 20px;
        }
        .report-page {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            padding: 30px 40px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            border: 1px solid #cbd5e1;
        }
        .report-header {
            text-align: center;
            border-bottom: 3px double #002F70;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .report-header img {
            height: 50px;
            margin-bottom: 8px;
        }
        .report-header h1 {
            font-size: 22px;
            color: #002F70;
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .report-header h2 {
            font-size: 16px;
            color: #e30613;
            margin: 0 0 6px 0;
            font-weight: 700;
        }
        .meta-grid {
            display: flex;
            justify-content: space-between;
            background: #f1f5f9;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        .section-title {
            font-size: 14px;
            font-weight: 800;
            color: #002F70;
            background: #e2e8f0;
            padding: 8px 12px;
            margin-top: 24px;
            margin-bottom: 12px;
            border-left: 4px solid #e30613;
            text-transform: uppercase;
        }
        table.report-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 16px;
        }
        table.report-tbl th {
            background: #002F70;
            color: white;
            padding: 8px 10px;
            text-align: left;
            font-weight: 700;
        }
        table.report-tbl td {
            padding: 7px 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        table.report-tbl tr:nth-child(even) td {
            background: #f8fafc;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .font-bold {
            font-weight: 700;
        }
        .total-row-highlight td {
            background: #fee2e2 !important;
            font-weight: 800;
            color: #991b1b;
        }
        .summary-grid-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .sign-box {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            padding-top: 20px;
        }
        .sign-line {
            width: 200px;
            border-top: 1px solid #334155;
            text-align: center;
            font-size: 12px;
            padding-top: 4px;
            font-weight: 600;
        }
        .no-print-bar {
            max-width: 900px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn-print {
            background: #002F70;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .btn-print:hover {
            background: #001f4d;
        }
        @media print {
            .no-print-bar {
                display: none !important;
            }
            body {
                background: white;
                padding: 0;
            }
            .report-page {
                box-shadow: none;
                border: none;
                padding: 0;
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <div class="no-print-bar">
        <a href="staff_fuel_sales_closing.php?date=<?= urlencode($report_date) ?>" style="text-decoration:none; color:#002F70; font-weight:700; font-size:13px;">
            <i class="fas fa-arrow-left"></i> Edit Closing Inputs
        </a>
        <button onclick="window.print()" class="btn-print">
            <i class="fas fa-print"></i> Print Official Report (PDF)
        </button>
    </div>

    <div class="report-page">
        <!-- Header -->
        <div class="report-header">
            <h1><?= htmlspecialchars($station_name) ?></h1>
            <h2>DAILY FUEL SALES & RECONCILIATION REPORT</h2>
            <div style="font-size: 12px; color: #475569;"><?= htmlspecialchars($station_addr) ?></div>
        </div>

        <!-- Meta -->
        <div class="meta-grid">
            <div><strong>Report Date:</strong> <?= htmlspecialchars($report_date) ?></div>
            <div><strong>Shift:</strong> <?= htmlspecialchars($closing['shift'] ?? 'General') ?></div>
            <div><strong>Encoded By:</strong> <?= htmlspecialchars($encoder_name) ?></div>
            <div><strong>Status:</strong> <?= strtoupper(htmlspecialchars($closing['status'] ?? 'Draft')) ?></div>
        </div>

        <!-- 1. Meter Reading Table -->
        <div class="section-title"><i class="fas fa-tachometer-alt"></i> 1. Meter Reading Details</div>
        <table class="report-tbl">
            <thead>
                <tr>
                    <th>Pump / Fuel Type</th>
                    <th>Shift</th>
                    <th class="text-right">Beginning</th>
                    <th class="text-right">Ending</th>
                    <th class="text-right">Calibration</th>
                    <th class="text-right">Volume (L)</th>
                    <th class="text-right">Price/L</th>
                    <th class="text-right">Total Amount (₱)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $sum_vol = 0;
                $sum_amt = 0;
                if (!empty($readings)): 
                    foreach ($readings as $r):
                        $sum_vol += (float)$r['liters_sold'];
                        $sum_amt += (float)$r['total_amount'];
                ?>
                <tr>
                    <td><strong>Pump #<?= (int)$r['pump_id'] ?></strong> — <?= htmlspecialchars($r['fuel_type']) ?></td>
                    <td><?= htmlspecialchars($r['shift_period']) ?></td>
                    <td class="text-right"><?= number_format((float)$r['previous_reading'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$r['present_reading'], 2) ?></td>
                    <td class="text-right"><?= number_format((float)$r['calibration'], 2) ?></td>
                    <td class="text-right font-bold"><?= number_format((float)$r['liters_sold'], 2) ?></td>
                    <td class="text-right">₱<?= number_format((float)$r['price_per_liter'], 2) ?></td>
                    <td class="text-right font-bold">₱<?= number_format((float)$r['total_amount'], 2) ?></td>
                </tr>
                <?php endforeach; else: ?>
                <tr>
                    <td colspan="8" class="text-center" style="color: #94a3b8; padding: 15px;">No meter readings recorded for this date.</td>
                </tr>
                <?php endif; ?>
                <tr class="total-row-highlight">
                    <td colspan="5">TOTAL FUEL SALES</td>
                    <td class="text-right"><?= number_format($sum_vol, 2) ?> L</td>
                    <td></td>
                    <td class="text-right">₱<?= number_format($sum_amt, 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- 2 & 3. Volume & Amount Summary -->
        <div class="summary-grid-2col">
            <div>
                <div class="section-title"><i class="fas fa-chart-pie"></i> 2. Fuel Sales Breakdown</div>
                <table class="report-tbl">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="text-right">Amount (₱)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Diesel</td><td class="text-right">₱<?= number_format((float)($closing['diesel_sales'] ?? 0), 2) ?></td></tr>
                        <tr><td>Turbo Diesel</td><td class="text-right">₱<?= number_format((float)($closing['turbo_diesel_sales'] ?? 0), 2) ?></td></tr>
                        <tr><td>XCS Plus</td><td class="text-right">₱<?= number_format((float)($closing['xcs_plus_sales'] ?? 0), 2) ?></td></tr>
                        <tr><td>XTRA Advance</td><td class="text-right">₱<?= number_format((float)($closing['xtra_advance_sales'] ?? 0), 2) ?></td></tr>
                        <tr><td>Kerosene</td><td class="text-right">₱<?= number_format((float)($closing['kerosene_sales'] ?? 0), 2) ?></td></tr>
                        <tr class="font-bold" style="background:#f1f5f9;">
                            <td>Total Fuel Sales</td>
                            <td class="text-right">₱<?= number_format((float)($closing['total_fuel_sales'] ?? $sum_amt), 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 4. Tank Inventory Summary -->
            <div>
                <div class="section-title"><i class="fas fa-database"></i> 4. Tank Inventory Summary</div>
                <table class="report-tbl">
                    <thead>
                        <tr>
                            <th>Fuel Type</th>
                            <th class="text-right">Current Stock</th>
                            <th class="text-right">Capacity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($tanks)): foreach ($tanks as $t): ?>
                        <tr>
                            <td><?= htmlspecialchars($t['fuel_type']) ?></td>
                            <td class="text-right font-bold"><?= number_format((float)$t['current_level'], 2) ?> L</td>
                            <td class="text-right"><?= number_format((float)$t['capacity'], 2) ?> L</td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="3" class="text-center">No inventory records.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Shop / Store Sales Income -->
        <div class="section-title"><i class="fas fa-shopping-bag"></i> 5. Shop / Store Sales Income</div>
        <table class="report-tbl">
            <thead>
                <tr>
                    <th>OLG Sales</th>
                    <th>TBA Sales</th>
                    <th>Services Income</th>
                    <th>Other Sales</th>
                    <th>A/R Collected</th>
                    <th class="text-right">Total Store Sales</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>₱<?= number_format((float)($closing['olg_sales'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($closing['tba_sales'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($closing['service_income'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($closing['other_sales'] ?? 0), 2) ?></td>
                    <td>₱<?= number_format((float)($closing['ar_collected'] ?? 0), 2) ?></td>
                    <td class="text-right font-bold" style="color:#002F70;">₱<?= number_format((float)($closing['total_store_sales'] ?? 0), 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- 6, 7 & 8. Financial Reconciliation -->
        <div class="summary-grid-2col">
            <div>
                <div class="section-title"><i class="fas fa-coins"></i> 6. Cash Summary</div>
                <table class="report-tbl">
                    <tbody>
                        <tr><td>Shift 1 Cash Collection</td><td class="text-right">₱<?= number_format((float)($closing['cash_shift1'] ?? 0), 2) ?></td></tr>
                        <tr><td>Shift 2 Cash Collection</td><td class="text-right">₱<?= number_format((float)($closing['cash_shift2'] ?? 0), 2) ?></td></tr>
                        <tr class="font-bold" style="background:#f1f5f9;">
                            <td>Total Cash Collection</td>
                            <td class="text-right">₱<?= number_format((float)($closing['total_cash'] ?? 0), 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div>
                <div class="section-title"><i class="fas fa-file-invoice-dollar"></i> 7. Accounts Receivable</div>
                <table class="report-tbl">
                    <tbody>
                        <tr><td>Shift 1 A/R</td><td class="text-right">₱<?= number_format((float)($closing['ar_shift1'] ?? 0), 2) ?></td></tr>
                        <tr><td>Shift 2 A/R</td><td class="text-right">₱<?= number_format((float)($closing['ar_shift2'] ?? 0), 2) ?></td></tr>
                        <tr class="font-bold" style="background:#f1f5f9;">
                            <td>Total Accounts Receivable</td>
                            <td class="text-right">₱<?= number_format((float)($closing['total_ar'] ?? 0), 2) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 8 & 9. Overall Summary & Bank -->
        <div class="section-title"><i class="fas fa-calculator"></i> 8 & 9. Overall Financial Summary</div>
        <table class="report-tbl">
            <tbody>
                <tr>
                    <td style="font-size:13px;">Gross Sales (Fuel Sales + Store Sales)</td>
                    <td class="text-right font-bold" style="font-size:14px; color:#002F70;">₱<?= number_format((float)($closing['gross_sales'] ?? 0), 2) ?></td>
                </tr>
                <tr>
                    <td style="font-size:13px;">Expected Cash (Gross Sales − Accounts Receivable)</td>
                    <td class="text-right font-bold" style="font-size:14px; color:#16a34a;">₱<?= number_format((float)($closing['expected_cash'] ?? 0), 2) ?></td>
                </tr>
                <tr style="background:#eff6ff;">
                    <td style="font-size:14px; font-weight:800; color:#002F70;">TOTAL CASH IN BANK / REMITTANCE</td>
                    <td class="text-right font-bold" style="font-size:16px; color:#002F70;">₱<?= number_format((float)($closing['total_cash_bank'] ?? 0), 2) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="sign-box">
            <div class="sign-line">
                Prepared By: <?= htmlspecialchars($encoder_name) ?><br>
                <span style="font-weight:400; color:#64748b;">Staff Encoder</span>
            </div>
            <div class="sign-line">
                Verified By: ____________________<br>
                <span style="font-weight:400; color:#64748b;">Station Manager</span>
            </div>
        </div>

    </div>

</body>
</html>
