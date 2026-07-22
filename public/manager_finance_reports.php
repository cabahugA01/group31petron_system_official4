<?php
/**
 * Manager Finance Reports - Real Report Format
 * Finance Reports with tabbed interface matching design
 * Blue theme for Manager role
 */

$page_id = 'manager_finance_reports';
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
$active_tab = $_GET['tab'] ?? 'payments';

// Active finance sub-report
$section = $_GET['section'] ?? 'payments';
$valid_sections = ['payments', 'suppliers', 'financial'];
if (!in_array($section, $valid_sections, true)) {
    $section = 'payments';
}

// Get date range from GET or use current month as default
$date_from = $_GET['date_from'] ?? date('Y-m-01');
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

function mfPaymentMethodCase(string $expr): string {
    return "CASE
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%fleet%' THEN 'Fleet'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%fuel card%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%efuel%' THEN 'E-Fuel Card'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%card%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%credit%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%debit%' THEN 'Card'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%wallet%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%gcash%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%maya%'
          OR LOWER(COALESCE({$expr},'')) LIKE '%paymaya%' THEN 'E-Wallet'
        WHEN LOWER(COALESCE({$expr},'')) LIKE '%cash%'
          OR COALESCE({$expr},'') = '' THEN 'Cash'
        ELSE COALESCE(NULLIF({$expr},''), 'Other')
    END";
}

function mfNonRejectedWhere(string $alias, string $workflow_col, string $validation_col = ''): string {
    $p = $alias !== '' ? $alias . '.' : '';
    $where = "LOWER(COALESCE({$p}{$workflow_col}, '')) NOT IN ('rejected','cancelled','canceled','voided')";
    if ($validation_col !== '') {
        $where .= " AND LOWER(COALESCE({$p}{$validation_col}, '')) NOT IN ('rejected','cancelled','canceled','voided')";
    }
    return $where;
}

$mf_fuel_ok = mfNonRejectedWhere('ft', 'status');
$mf_mt_ok = mfNonRejectedWhere('mt', 'workflow_status', 'validation_status');
$mf_jo_ok = mfNonRejectedWhere('jo', 'status', 'validation_status');
$mf_jo_amount = "COALESCE(NULLIF(jo.amount_paid, 0), NULLIF(jo.total_cost, 0), NULLIF(jo.estimated_cost, 0), COALESCE(jo.actual_labor_cost,0) + COALESCE(jo.actual_parts_cost,0), 0)";
$mf_mt_not_native_job = "NOT EXISTS (
    SELECT 1
    FROM job_orders jo2
    WHERE jo2.station_id = mt.station_id
      AND (
          (mt.job_order_db_id IS NOT NULL AND mt.job_order_db_id <> 0 AND jo2.id = mt.job_order_db_id)
          OR (mt.job_order_id IS NOT NULL AND TRIM(CAST(mt.job_order_id AS CHAR)) <> ''
              AND (jo2.job_order_number = mt.job_order_id OR jo2.job_order_id = mt.job_order_id))
      )
)";

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Reuse the same styles from manager_reports.php */
.reports-wrapper {
    background: white !important;
    border-radius: 8px !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08) !important;
    margin: 0 !important;
    overflow: hidden !important;
}

.rpt-content {
    padding: 24px 28px !important;
}

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
    background: #ffffff !important;
    color: #00264D !important;
    border: 1px solid #00264D !important;
    border-radius: 4px !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
}

.rpt-filter-bar button:hover {
    background: #00264D !important;
    color: #ffffff !important;
}

.rpt-filter-bar button i {
    margin-right: 4px !important;
}

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

/* Excel Button - Green Border */
.rpt-export-btn:nth-child(1) {
    color: #16a34a !important;
    border-color: #16a34a !important;
}
.rpt-export-btn:nth-child(1):hover {
    background: #f0fdf4 !important;
    border-color: #15803d !important;
}

/* CSV Button - Dark Blue Border */
.rpt-export-btn:nth-child(2) {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.rpt-export-btn:nth-child(2):hover {
    background: #eff6ff !important;
    border-color: #001f4d !important;
}

/* PDF Button - Red Border */
.rpt-export-btn:nth-child(3) {
    color: #dc2626 !important;
    border-color: #dc2626 !important;
}
.rpt-export-btn:nth-child(3):hover {
    background: #fef2f2 !important;
    border-color: #b91c1c !important;
}

/* Print Button - Blue Border */
.rpt-export-btn:nth-child(4) {
    color: #002F70 !important;
    border-color: #002F70 !important;
}
.rpt-export-btn:nth-child(4):hover {
    background: #eff6ff !important;
    border-color: #002F70 !important;
}

.rpt-export-btn i {
    margin-right: 3px !important;
}

/* Finance Section Tabs */
.fr-section-tabs {
    display: flex;
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 0;
    overflow:hidden;
    background: #00264D;
}
.fr-section-tab {
    padding: 12px 20px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #ffffff !important;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
}

.fr-section-tab:hover { background: rgba(255,255,255,0.15); color: #ffffff !important; }
.fr-section-tab.active {
    background: #ffffff;
    color: #00264D !important;
    border-bottom-color: #002F70;
    font-weight: 800;
}

.fr-section-panel { display: none; }
.fr-section-panel.active { display: block; }

/* Finance Tables */
.fr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 16px;
}

.fr-table thead tr {
    border-top: 2px solid #00264D;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
}

.fr-table thead th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 700;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    background: #f8fafc;
}

.fr-table tbody tr { border-bottom: 1px solid #f1f5f9; }
.fr-table tbody tr:hover { background: #f8fafc; }
.fr-table tbody td { padding: 9px 8px; color: #334155; font-size: 12px; }
.fr-table tfoot tr {
    border-top: 2px solid #00264D;
    background: #f0f4ff;
}

.fr-table tfoot td {
    padding: 10px 8px;
    font-weight: 700;
    color: #00264D;
    font-size: 12px;
}

.fr-empty {
    text-align: center;
    padding: 28px;
    color: #94a3b8;
    font-size: 13px;
}

@media print {
    @page { size: legal portrait; margin: 0.3in 0.4in; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    .fr-section-tabs { display: none !important; }
    .rpt-filter-bar, .rpt-export-actions { display: none !important; }
    .rpt-printable { display:block !important; overflow:visible !important; }
    .fr-section-panel { display:none !important; overflow:visible !important; }
    .fr-section-panel.active { display:block !important; }
    .fr-table {
        width:100% !important;
        border-collapse:collapse !important;
        table-layout:auto !important;
        font-size:9.5px !important;
        break-inside:auto !important;
        page-break-inside:auto !important;
    }
    .fr-table thead { display:table-header-group !important; }
    .fr-table tfoot { display:table-footer-group !important; }
    .fr-table tr { break-inside:avoid !important; page-break-inside:avoid !important; }
    .fr-table th, .fr-table td {
        white-space:normal !important;
        word-break:break-word !important;
        padding:5px !important;
        font-size:9.5px !important;
    }
}
</style>

<div class="reports-wrapper">
    <div class="rpt-content">
        <!-- Date Filter Bar -->
        <form method="GET" class="rpt-filter-bar">
            <input type="hidden" name="section" id="managerFinanceSection" value="<?= htmlspecialchars($section) ?>">
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
            <!-- Section Tabs -->
            <div class="fr-section-tabs">
                <button type="button" class="fr-section-tab <?= $section === 'payments' ? 'active' : '' ?>"
                        onclick="frSwitchSection('payments', this)">
                    <i class="fas fa-money-bill-wave"></i> Payments Breakdown
                </button>
                <button type="button" class="fr-section-tab <?= $section === 'suppliers' ? 'active' : '' ?>"
                        onclick="frSwitchSection('suppliers', this)">
                    <i class="fas fa-truck"></i> Suppliers & Deliveries
                </button>
                <button type="button" class="fr-section-tab <?= $section === 'financial' ? 'active' : '' ?>"
                        onclick="frSwitchSection('financial', this)">
                    <i class="fas fa-file-invoice-dollar"></i> Financial / Payables
                </button>
            </div>

            <!-- Payments Section -->
            <div id="fr-panel-payments" class="fr-section-panel <?= $section === 'payments' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        PAYMENTS BREAKDOWN REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        MODE OF PAYMENT ANALYSIS
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Fetch payments breakdown
                try {
                    $q = $pdo->prepare("
                        SELECT
                            " . mfPaymentMethodCase('ft.payment_method') . " AS mode_of_payment,
                            'Fuel' AS source,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(total_amount, 0)) AS total_amount
                        FROM fuel_transactions ft
                        WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ?
                          AND {$mf_fuel_ok}
                        GROUP BY mode_of_payment
                        
                        UNION ALL
                        
                        SELECT
                            " . mfPaymentMethodCase('mt.payment_method') . " AS mode_of_payment,
                            'Merchandise' AS source,
                            COUNT(*) AS txn_count,
                            SUM(COALESCE(total_amount, 0)) AS total_amount
                        FROM merchandise_transactions mt
                        WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ?
                          AND {$mf_mt_ok}
                          AND {$mf_mt_not_native_job}
                        GROUP BY mode_of_payment

                        UNION ALL

                        SELECT
                            " . mfPaymentMethodCase('jo.payment_method') . " AS mode_of_payment,
                            'Job Order' AS source,
                            COUNT(*) AS txn_count,
                            SUM({$mf_jo_amount}) AS total_amount
                        FROM job_orders jo
                        WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
                          AND {$mf_jo_ok}
                        GROUP BY mode_of_payment
                        
                        ORDER BY total_amount DESC
                    ");
                    $q->execute([
                        $station_id, $date_start, $date_end,
                        $station_id, $date_start, $date_end,
                        $station_id, $date_start, $date_end
                    ]);
                    $payment_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    
                    // Aggregate by mode
                    $payments_agg = [];
                    foreach ($payment_rows as $row) {
                        $mode = $row['mode_of_payment'];
                        if (!isset($payments_agg[$mode])) {
                            $payments_agg[$mode] = ['mode' => $mode, 'txn_count' => 0, 'total_amount' => 0];
                        }
                        $payments_agg[$mode]['txn_count'] += $row['txn_count'];
                        $payments_agg[$mode]['total_amount'] += $row['total_amount'];
                    }
                    usort($payments_agg, fn($a, $b) => $b['total_amount'] <=> $a['total_amount']);
                } catch (Exception $e) {
                    $payments_agg = [];
                }
                ?>

                <table class="fr-table">
                    <thead>
                        <tr>
                            <th>Mode of Payment</th>
                            <th>Transaction Count</th>
                            <th>Total Amount</th>
                            <th>Percentage</th>
                            <th>Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($payments_agg)) {
                            echo '<tr><td colspan="5" class="fr-empty">No payment records for this period</td></tr>';
                        } else {
                            $grand_total = array_sum(array_column($payments_agg, 'total_amount'));
                            foreach ($payments_agg as $row) {
                                $pct = $grand_total > 0 ? ($row['total_amount'] / $grand_total) * 100 : 0;
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($row['mode']) . '</td>';
                                echo '<td>' . number_format($row['txn_count']) . '</td>';
                                echo '<td>₱' . number_format($row['total_amount'], 2) . '</td>';
                                echo '<td>' . number_format($pct, 1) . '%</td>';
                                echo '<td>Confirm</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                    <?php if (!empty($payments_agg)): ?>
                    <tfoot>
                        <tr>
                            <td style="text-align:right;"><strong>GRAND TOTAL:</strong></td>
                            <td><strong><?= number_format(array_sum(array_column($payments_agg, 'txn_count'))) ?></strong></td>
                            <td><strong>₱<?= number_format($grand_total, 2) ?></strong></td>
                            <td><strong>100.0%</strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Suppliers Section -->
            <div id="fr-panel-suppliers" class="fr-section-panel <?= $section === 'suppliers' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        SUPPLIERS & DELIVERIES REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        PAYABLES & DELIVERY TRACKING
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Fetch supplier deliveries
                try {
                    $q = $pdo->prepare("
                        SELECT
                            supplier_name,
                            SUM(delivery_count) AS delivery_count,
                            SUM(total_value) AS total_value,
                            SUM(payable_amount) AS payable_amount,
                            SUM(total_value - payable_amount) AS variance
                        FROM (
                            SELECT
                                COALESCE(NULLIF(s.name, ''), NULLIF(po.product_name, ''), 'Unknown') AS supplier_name,
                                COUNT(DISTINCT po.id) AS delivery_count,
                                SUM(COALESCE(poi.total_price, po.total_amount, 0)) AS total_value,
                                SUM(CASE WHEN po.status NOT IN ('Received','Admin Finalized') THEN COALESCE(poi.total_price, po.total_amount, 0) ELSE 0 END) AS payable_amount
                            FROM purchase_orders po
                            LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
                            LEFT JOIN suppliers s ON po.supplier_id = s.id
                            WHERE po.station_id = ?
                              AND DATE(po.created_at) BETWEEN ? AND ?
                            GROUP BY supplier_name

                            UNION ALL

                            SELECT
                                COALESCE(fd.supplier, 'Unknown') AS supplier_name,
                                COUNT(*) AS delivery_count,
                                0 AS total_value,
                                0 AS payable_amount
                            FROM fuel_deliveries fd
                            WHERE fd.station_id = ?
                              AND DATE(COALESCE(fd.delivery_date, fd.created_at)) BETWEEN ? AND ?
                            GROUP BY supplier_name
                        ) src
                        GROUP BY supplier_name
                        ORDER BY total_value DESC, delivery_count DESC
                    ");
                    $q->execute([$station_id, $date_start, $date_end, $station_id, $date_start, $date_end]);
                    $supplier_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $supplier_rows = [];
                }
                ?>

                <table class="fr-table">
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Delivery Count</th>
                            <th>Total Delivery Value</th>
                            <th>Payable Amount</th>
                            <th>Variance</th>
                            <th>Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($supplier_rows)) {
                            echo '<tr><td colspan="6" class="fr-empty">No supplier deliveries for this period</td></tr>';
                        } else {
                            foreach ($supplier_rows as $row) {
                                echo '<tr>';
                                echo '<td>' . htmlspecialchars($row['supplier_name']) . '</td>';
                                echo '<td>' . number_format($row['delivery_count']) . '</td>';
                                echo '<td>₱' . number_format($row['total_value'], 2) . '</td>';
                                echo '<td>₱' . number_format($row['payable_amount'], 2) . '</td>';
                                echo '<td>₱' . number_format($row['variance'], 2) . '</td>';
                                echo '<td>Approve</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                    <?php if (!empty($supplier_rows)): ?>
                    <tfoot>
                        <tr>
                            <td style="text-align:right;"><strong>TOTAL:</strong></td>
                            <td><strong><?= number_format(array_sum(array_column($supplier_rows, 'delivery_count'))) ?></strong></td>
                            <td><strong>₱<?= number_format(array_sum(array_column($supplier_rows, 'total_value')), 2) ?></strong></td>
                            <td><strong>₱<?= number_format(array_sum(array_column($supplier_rows, 'payable_amount')), 2) ?></strong></td>
                            <td><strong>₱<?= number_format(array_sum(array_column($supplier_rows, 'variance')), 2) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Financial / Payables Section -->
            <div id="fr-panel-financial" class="fr-section-panel <?= $section === 'financial' ? 'active' : '' ?>">
                <div style="text-align:center;padding:22px 0 14px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;">
                    <div style="font-size:20px;font-weight:800;color:#00264D;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                        FINANCIAL & PAYABLES REPORT
                    </div>
                    <div style="font-size:16px;font-weight:700;color:#00264D;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:8px;">
                        ACCOUNTS PAYABLE & RECONCILIATION
                    </div>
                    <div style="font-size:12px;color:#64748b;margin-bottom:2px;">
                        <?= htmlspecialchars($station_name) ?>
                    </div>
                    <div style="font-size:12px;color:#334155;">
                        <strong>Date:</strong>
                        <?= date('F j, Y', strtotime($date_start)) ?>
                        <?= $date_start !== $date_end ? ' – ' . date('F j, Y', strtotime($date_end)) : '' ?>
                    </div>
                </div>

                <?php
                // Fetch financial summary
                try {
                    // Expected revenue and collections from all valid transaction streams.
                    $q1 = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS fuel_revenue FROM fuel_transactions ft WHERE ft.station_id = ? AND DATE(ft.transaction_date) BETWEEN ? AND ? AND {$mf_fuel_ok}");
                    $q1->execute([$station_id, $date_start, $date_end]);
                    $fuel_rev = $q1->fetchColumn() ?: 0;
                    
                    $q2 = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) AS merch_revenue FROM merchandise_transactions mt WHERE mt.station_id = ? AND DATE(COALESCE(mt.transaction_date, mt.created_at)) BETWEEN ? AND ? AND {$mf_mt_ok} AND {$mf_mt_not_native_job}");
                    $q2->execute([$station_id, $date_start, $date_end]);
                    $merch_rev = $q2->fetchColumn() ?: 0;

                    $q2b = $pdo->prepare("SELECT COALESCE(SUM({$mf_jo_amount}), 0) AS jo_revenue FROM job_orders jo WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ? AND {$mf_jo_ok}");
                    $q2b->execute([$station_id, $date_start, $date_end]);
                    $jo_rev = $q2b->fetchColumn() ?: 0;
                    
                    $expected_revenue = $fuel_rev + $merch_rev + $jo_rev;
                    
                    $actual_collections = $expected_revenue;
                    
                    // Payables from open purchase orders.
                    $q4 = $pdo->prepare("
                        SELECT COALESCE(SUM(CASE WHEN po.status NOT IN ('Received','Admin Finalized') THEN COALESCE(poi.total_price, po.total_amount, 0) ELSE 0 END), 0) AS payables
                        FROM purchase_orders po
                        LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
                        WHERE po.station_id = ?
                          AND DATE(po.created_at) BETWEEN ? AND ?
                    ");
                    $q4->execute([$station_id, $date_start, $date_end]);
                    $total_payables = $q4->fetchColumn() ?: 0;
                    
                    $variance = $expected_revenue - $actual_collections;
                    $net_position = $actual_collections - $total_payables;
                    
                } catch (Exception $e) {
                    $expected_revenue = 0;
                    $actual_collections = 0;
                    $total_payables = 0;
                    $variance = 0;
                    $net_position = 0;
                }
                ?>

                <table class="fr-table">
                    <thead>
                        <tr>
                            <th>Financial Item</th>
                            <th>Expected Amount</th>
                            <th>Actual Amount</th>
                            <th>Variance</th>
                            <th>Status</th>
                            <th>Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Total Revenue (Fuel + Merchandise + Service)</strong></td>
                            <td>₱<?= number_format($expected_revenue, 2) ?></td>
                            <td>—</td>
                            <td>—</td>
                            <td>Expected</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td><strong>Total Collections</strong></td>
                            <td>₱<?= number_format($expected_revenue, 2) ?></td>
                            <td>₱<?= number_format($actual_collections, 2) ?></td>
                            <td style="color:<?= $variance > 0 ? '#c62828' : '#0d7d3e' ?>;">
                                ₱<?= number_format(abs($variance), 2) ?>
                                <?= $variance > 0 ? '(Short)' : '(Over)' ?>
                            </td>
                            <td><?= abs($variance) < 100 ? 'Reconciled' : 'Variance' ?></td>
                            <td>Confirm</td>
                        </tr>
                        <tr>
                            <td><strong>Accounts Payable (Suppliers)</strong></td>
                            <td>₱<?= number_format($total_payables, 2) ?></td>
                            <td>₱<?= number_format($total_payables, 2) ?></td>
                            <td>—</td>
                            <td>Outstanding</td>
                            <td>Approve</td>
                        </tr>
                        <tr>
                            <td><strong>Net Financial Position</strong></td>
                            <td>—</td>
                            <td style="color:<?= $net_position > 0 ? '#0d7d3e' : '#c62828' ?>;">
                                ₱<?= number_format(abs($net_position), 2) ?>
                                <?= $net_position > 0 ? '(Surplus)' : '(Deficit)' ?>
                            </td>
                            <td>—</td>
                            <td><?= $net_position > 0 ? 'Healthy' : 'Review Required' ?></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script>
function frSwitchSection(sectionKey, trigger) {
    // Hide all panels
    document.querySelectorAll('.fr-section-panel').forEach(p => p.classList.remove('active'));
    // Show selected panel
    const panel = document.getElementById('fr-panel-' + sectionKey);
    if (panel) panel.classList.add('active');
    
    // Update tab buttons
    document.querySelectorAll('.fr-section-tab').forEach(btn => btn.classList.remove('active'));
    if (trigger) trigger.classList.add('active');

    const hidden = document.getElementById('managerFinanceSection');
    if (hidden) hidden.value = sectionKey;
    
    // Update URL without reload
    const url = new URL(window.location);
    url.searchParams.set('section', sectionKey);
    window.history.replaceState({}, '', url);
}

function exportReport(type) {
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }

    const activePanel = wrap.querySelector('.fr-section-panel.active') || wrap;
    const section  = document.getElementById('managerFinanceSection')?.value
                  || new URL(window.location).searchParams.get('section')
                  || 'payments';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Manager_Finance_Report_${section}_${dateFrom}_to_${dateTo}`;

    if (type === 'pdf') {
        exportPrintableAreaToPDF(activePanel, 'Manager Finance Report', filename, document.activeElement);
        return;
    }

    if (typeof XLSX === 'undefined') {
        alert('Export library not loaded. Please refresh the page and try again.');
        return;
    }

    const tables = Array.from(activePanel.querySelectorAll('table')).filter(
        t => t.querySelector('tbody tr')
    );

    if (!tables.length) { alert('No table data found to export.'); return; }

    if (type === 'csv') {
        exportCSV(tables, filename);
    } else {
        exportExcel(tables, filename);
    }
}

function tableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim()));
    });
    table.querySelectorAll('tbody tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    table.querySelectorAll('tfoot tr').forEach(tr => {
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim()));
    });
    return aoa;
}

function exportExcel(tables, filename) {
    const wb = XLSX.utils.book_new();
    tables.forEach((tbl, i) => {
        const aoa = tableToAoA(tbl);
        const ws  = XLSX.utils.aoa_to_sheet(aoa);
        if (aoa.length && aoa[0]) {
            ws['!cols'] = aoa[0].map((_, ci) => ({
                wch: Math.min(45, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
            }));
        }
        XLSX.utils.book_append_sheet(wb, ws, `Report_${i + 1}`);
    });
    XLSX.writeFile(wb, filename + '.xlsx');
}

function exportCSV(tables, filename) {
    let csv = '';
    tables.forEach((tbl, i) => {
        if (i > 0) csv += '\n';
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
    const activePanel = wrap?.querySelector('.fr-section-panel.active') || wrap;
    printReportArea(activePanel);
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
