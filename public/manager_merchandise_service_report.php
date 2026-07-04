<?php
/**
 * DAILY MERCHANDISE & SERVICE SALES REPORT - Complete Implementation
 * Standalone page with 6 comprehensive sections
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Access control
if (!in_array($role, ['manager', 'admin', 'superadmin', 'developer'])) {
    die('Access denied. Manager privileges required.');
}

if (!$station_id) {
    die('Error: You are not assigned to a station.');
}

// Get Station Name
$station_name = 'Station';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $st = $s->fetch(PDO::FETCH_ASSOC);
    if ($st) $station_name = $st['name'];
} catch (Exception $e) {}

// Date handling
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) $date_to = date('Y-m-d');

// Include the data fetching functions
require_once __DIR__ . '/reports/merchandise_service_report_new.php';

// Fetch all data
$reportData = fetchMerchandiseServiceReport($pdo, $station_id, $date_from, $date_to, null);

$page_title = "Daily Merchandise & Service Sales Report";
require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Report Container */
.report-container {
    background: white;
    padding: 24px;
    margin: 0;
}

/* Report Header */
.report-header {
    text-align: center;
    padding: 20px 0;
    border-bottom: 2px solid #00264D;
    margin-bottom: 24px;
}

.report-header h1 {
    font-size: 24px;
    font-weight: 700;
    color: #00264D;
    margin: 0 0 8px 0;
    text-transform: uppercase;
}

.report-header .subtitle {
    font-size: 16px;
    font-weight: 600;
    color: #00264D;
    margin: 0 0 8px 0;
}

.report-header .meta {
    font-size: 12px;
    color: #64748b;
    margin: 4px 0;
}

/* Filter Bar */
.filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 0;
    margin-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
}

.filter-bar label {
    font-size: 12px;
    font-weight: 600;
    color: #00264D;
}

.filter-bar input[type="date"] {
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 12px;
}

.filter-bar button {
    padding: 7px 16px;
    background: #ffffff;
    color: #00264D;
    border: 1px solid #00264D;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.filter-bar button:hover {
    background: #00264D;
    color: #ffffff;
}

/* Export Buttons */
.export-actions {
    display: flex;
    gap: 6px;
    margin-left: auto;
}

.export-btn {
    padding: 7px 14px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    border: 1px solid;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #ffffff;
    text-decoration: none;
}

.export-btn-excel {
    color: #16a34a;
    border-color: #16a34a;
}
.export-btn-excel:hover {
    background: #16a34a;
    color: #ffffff;
}

.export-btn-csv {
    color: #1e3a8a;
    border-color: #1e3a8a;
}
.export-btn-csv:hover {
    background: #1e3a8a;
    color: #ffffff;
}

.export-btn-pdf {
    color: #dc2626;
    border-color: #dc2626;
}
.export-btn-pdf:hover {
    background: #dc2626;
    color: #ffffff;
}

/* Section Styling */
.report-section {
    margin-bottom: 32px;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: #00264D;
    margin: 0 0 12px 0;
    padding: 8px 0;
    border-bottom: 2px solid #00264D;
    text-transform: uppercase;
}

/* Tables */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-bottom: 8px;
}

.data-table thead tr {
    background: #f8fafc;
    border-top: 2px solid #00264D;
    border-bottom: 1px solid #e2e8f0;
}

.data-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
}

.data-table tbody tr {
    border-bottom: 1px solid #f1f5f9;
}

.data-table tbody tr:hover {
    background: #f8fafc;
}

.data-table tbody td {
    padding: 9px 8px;
    color: #334155;
}

.data-table tfoot tr {
    background: #f0f4ff;
    border-top: 2px solid #00264D;
}

.data-table tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    color: #00264D;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.empty-state {
    text-align: center;
    padding: 24px;
    color: #94a3b8;
    font-style: italic;
}

/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

.summary-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 16px;
}

.summary-card h3 {
    font-size: 14px;
    font-weight: 700;
    color: #00264D;
    margin: 0 0 12px 0;
    text-transform: uppercase;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border-bottom: 1px solid #e2e8f0;
}

.summary-item:last-child {
    border-bottom: none;
    margin-top: 8px;
    padding-top: 12px;
    border-top: 2px solid #00264D;
}

.summary-label {
    font-size: 12px;
    color: #64748b;
}

.summary-value {
    font-size: 12px;
    font-weight: 600;
    color: #00264D;
}

.summary-total .summary-label,
.summary-total .summary-value {
    font-size: 14px;
    font-weight: 700;
}

@media print {
    .filter-bar, .export-actions {
        display: none !important;
    }
}
</style>

<div class="report-container">
    <!-- Filter Bar -->
    <form method="GET" class="filter-bar">
        <label><i class="fas fa-calendar"></i> Report Date:</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        <span style="color: #64748b;">to</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
        
        <div class="export-actions">
            <a href="#" class="export-btn export-btn-excel" onclick="exportToExcel(); return false;">
                <i class="fas fa-file-excel"></i> Excel
            </a>
            <a href="#" class="export-btn export-btn-csv" onclick="exportToCSV(); return false;">
                <i class="fas fa-file-csv"></i> CSV
            </a>
            <button type="button" class="export-btn export-btn-pdf" onclick="window.print()">
                <i class="fas fa-file-pdf"></i> PDF
            </button>
        </div>
    </form>

    <!-- Report Header -->
    <div class="report-header">
        <h1>DAILY MERCHANDISE & SERVICE SALES REPORT</h1>
        <div class="subtitle">24-HOUR SUMMARY</div>
        <div class="meta"><?= htmlspecialchars($station_name) ?></div>
        <div class="meta"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from !== $date_to ? ' – ' . date('F j, Y', strtotime($date_to)) : '' ?></div>
    </div>

    <!-- SECTION 1: MERCHANDISE SALES -->
    <div class="report-section">
        <div class="section-title">1. Merchandise Sales</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Receipt No.</th>
                    <th>Customer</th>
                    <th>Category</th>
                    <th>Product</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Amount</th>
                    <th>Encoder</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData['merchandise_sales'])): ?>
                    <tr><td colspan="8" class="empty-state">No merchandise sales for this period</td></tr>
                <?php else: ?>
                    <?php foreach ($reportData['merchandise_sales'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['receipt_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td><?= htmlspecialchars($row['product']) ?></td>
                            <td class="text-right"><?= number_format($row['qty'], 0) ?></td>
                            <td class="text-right">₱<?= number_format($row['unit_price'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['encoder']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData['merchandise_sales'])): ?>
            <tfoot>
                <tr>
                    <td colspan="6" class="text-right"><strong>Total Merchandise Sales:</strong></td>
                    <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($reportData['merchandise_sales'], 'amount')), 2) ?></strong></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- SECTION 2: JOB ORDER / SERVICE SALES -->
    <div class="report-section">
        <div class="section-title">2. Job Order / Service Sales</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>JO No.</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Service Type</th>
                    <th class="text-right">Labor Fee</th>
                    <th class="text-right">Parts Cost</th>
                    <th class="text-right">Total Amount</th>
                    <th>Encoder</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData['job_orders'])): ?>
                    <tr><td colspan="8" class="empty-state">No job orders for this period</td></tr>
                <?php else: ?>
                    <?php foreach ($reportData['job_orders'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['jo_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['vehicle']) ?></td>
                            <td><?= htmlspecialchars($row['service_type']) ?></td>
                            <td class="text-right">₱<?= number_format($row['labor_fee'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($row['parts_cost'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($row['total_amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['encoder']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData['job_orders'])): ?>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right"><strong>Total Service Income (Labor):</strong></td>
                    <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($reportData['job_orders'], 'labor_fee')), 2) ?></strong></td>
                    <td colspan="3"></td>
                </tr>
                <tr>
                    <td colspan="4" class="text-right"><strong>Total Job Order Sales:</strong></td>
                    <td colspan="2" class="text-right"><strong>₱<?= number_format(array_sum(array_column($reportData['job_orders'], 'total_amount')), 2) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- SECTION 3: PARTS USED IN JOB ORDERS -->
    <div class="report-section">
        <div class="section-title">3. Parts Used in Job Orders (From Merchandise Products)</div>
        <p style="font-size: 11px; color: #64748b; margin: 0 0 12px 0; font-style: italic;">Source: Merchandise Inventory Products</p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>JO No.</th>
                    <th>Customer</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th class="text-right">Qty Used</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData['parts_used'])): ?>
                    <tr><td colspan="7" class="empty-state">No parts used in job orders for this period</td></tr>
                <?php else: ?>
                    <?php foreach ($reportData['parts_used'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['jo_no']) ?></td>
                            <td><?= htmlspecialchars($row['customer']) ?></td>
                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td class="text-right"><?= number_format($row['qty_used'], 0) ?></td>
                            <td class="text-right">₱<?= number_format($row['unit_price'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($row['total_cost'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData['parts_used'])): ?>
            <tfoot>
                <tr>
                    <td colspan="4" class="text-right"><strong>Total Parts Used:</strong></td>
                    <td class="text-right"><strong><?= number_format(array_sum(array_column($reportData['parts_used'], 'qty_used')), 0) ?></strong></td>
                    <td class="text-right"><strong>Total Parts Cost:</strong></td>
                    <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($reportData['parts_used'], 'total_cost')), 2) ?></strong></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- SECTION 4: PAYMENT BREAKDOWN -->
    <div class="report-section">
        <div class="section-title">4. Payment Breakdown</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th class="text-right">Transactions</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData['payment_breakdown'])): ?>
                    <tr><td colspan="3" class="empty-state">No payment data for this period</td></tr>
                <?php else: ?>
                    <?php foreach ($reportData['payment_breakdown'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['payment_method']) ?></td>
                            <td class="text-right"><?= number_format($row['transactions'], 0) ?></td>
                            <td class="text-right">₱<?= number_format($row['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData['payment_breakdown'])): ?>
            <tfoot>
                <tr>
                    <td class="text-right"><strong>TOTAL:</strong></td>
                    <td class="text-right"><strong><?= number_format(array_sum(array_column($reportData['payment_breakdown'], 'transactions')), 0) ?></strong></td>
                    <td class="text-right"><strong>₱<?= number_format(array_sum(array_column($reportData['payment_breakdown'], 'amount')), 2) ?></strong></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <!-- SECTION 5: SHIFT SALES SUMMARY -->
    <div class="report-section">
        <div class="section-title">5. Shift Sales Summary</div>
        <div class="summary-grid">
            <!-- Shift 1 -->
            <div class="summary-card">
                <h3>Shift 1 (6:00 AM – 2:00 PM)</h3>
                <div class="summary-item">
                    <span class="summary-label">Merchandise Sales</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift1']['merchandise_sales'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Labor Income</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift1']['labor_income'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Parts Sales</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift1']['parts_sales'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item summary-total">
                    <span class="summary-label">Grand Total</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift1']['grand_total'] ?? 0, 2) ?></span>
                </div>
            </div>
            
            <!-- Shift 2 -->
            <div class="summary-card">
                <h3>Shift 2 (2:00 PM – 12:00 AM)</h3>
                <div class="summary-item">
                    <span class="summary-label">Merchandise Sales</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift2']['merchandise_sales'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Labor Income</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift2']['labor_income'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Parts Sales</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift2']['parts_sales'] ?? 0, 2) ?></span>
                </div>
                <div class="summary-item summary-total">
                    <span class="summary-label">Grand Total</span>
                    <span class="summary-value">₱<?= number_format($reportData['shift_summary']['shift2']['grand_total'] ?? 0, 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 6: OVERALL DAILY SUMMARY -->
    <div class="report-section">
        <div class="section-title">6. Overall Daily Summary</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Merchandise Sales</td>
                    <td class="text-right">₱<?= number_format($reportData['daily_summary']['merchandise_sales'], 2) ?></td>
                </tr>
                <tr>
                    <td>Labor Income</td>
                    <td class="text-right">₱<?= number_format($reportData['daily_summary']['labor_income'], 2) ?></td>
                </tr>
                <tr>
                    <td>Parts Used (Merchandise Products)</td>
                    <td class="text-right">₱<?= number_format($reportData['daily_summary']['parts_used'], 2) ?></td>
                </tr>
                <tr style="background: #f0f4ff;">
                    <td><strong>Grand Total Sales</strong></td>
                    <td class="text-right"><strong>₱<?= number_format($reportData['daily_summary']['grand_total'], 2) ?></strong></td>
                </tr>
                <tr>
                    <td>Total Transactions</td>
                    <td class="text-right"><?= number_format($reportData['daily_summary']['total_transactions'], 0) ?></td>
                </tr>
                <tr>
                    <td>Customers Served</td>
                    <td class="text-right"><?= number_format($reportData['daily_summary']['customers_served'], 0) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportToExcel() {
    alert('Excel export functionality to be implemented');
}

function exportToCSV() {
    alert('CSV export functionality to be implemented');
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
