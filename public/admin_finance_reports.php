<?php
/**
 * Admin Finance Reports
 * Payments | Suppliers | Financial / Payables
 * Same design as admin_reports.php (Operations Reports)
 */

$page_id = 'admin_reports';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = user_station_id();

if ($role !== 'admin') {
    die('Access denied. Only administrators can view this page.');
}

$section    = in_array($_GET['section'] ?? '', ['payments','suppliers','financial']) ? $_GET['section'] : 'payments';
$date_from  = $_GET['date_from'] ?? date('Y-m-01');
$date_to    = $_GET['date_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');

// Station name
$station_name = '';
try {
    $s = $pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");
    $s->execute([$station_id]);
    $station_name = $s->fetchColumn() ?: '';
} catch (Exception $e) {}

// ── DATA FETCH ────────────────────────────────────────────────────────────────

// PAYMENTS — fuel + merch combined
$pay_rows = [];
$pay_chart = ['labels'=>[],'data'=>[]];
try {
    $q = $pdo->prepare("
        SELECT
            CASE
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fleet%' THEN 'Fleet Card'
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%fuel card%' OR LOWER(COALESCE(payment_method,'')) LIKE '%efuel%' THEN 'E-Fuel Card'
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card'
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%wallet%' OR LOWER(COALESCE(payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                ELSE 'Cash'
            END AS mode_of_payment,
            COUNT(*) AS txn_count,
            SUM(COALESCE(total_amount,0)) AS total_amount,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder,
            MAX(ft.transaction_date) AS last_txn
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id=u.id
        WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ?
        GROUP BY mode_of_payment, u.first_name, u.last_name
        ORDER BY total_amount DESC
    ");
    $q->execute([$station_id, $date_from, $date_to]);
    $fuel_pay = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $q2 = $pdo->prepare("
        SELECT
            CASE
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%card%' THEN 'Card'
                WHEN LOWER(COALESCE(payment_method,'')) LIKE '%wallet%' OR LOWER(COALESCE(payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                ELSE 'Cash'
            END AS mode_of_payment,
            COUNT(*) AS txn_count,
            SUM(COALESCE(total_amount,0)) AS total_amount,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder,
            MAX(mt.created_at) AS last_txn
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id=u.id
        WHERE mt.station_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
        GROUP BY mode_of_payment, u.first_name, u.last_name
        ORDER BY total_amount DESC
    ");
    $q2->execute([$station_id, $date_from, $date_to]);
    $merch_pay = $q2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Merge
    $merged = [];
    foreach (array_merge($fuel_pay, $merch_pay) as $r) {
        $key = $r['mode_of_payment'].'||'.$r['encoder'];
        if (!isset($merged[$key])) $merged[$key] = $r;
        else {
            $merged[$key]['txn_count']   += $r['txn_count'];
            $merged[$key]['total_amount'] += $r['total_amount'];
            if (strtotime($r['last_txn']) > strtotime($merged[$key]['last_txn'])) {
                $merged[$key]['last_txn'] = $r['last_txn'];
            }
        }
    }
    $pay_rows = array_values($merged);
    usort($pay_rows, fn($a,$b) => $b['total_amount'] <=> $a['total_amount']);

    // Chart aggregation by mode
    $mode_agg = [];
    foreach ($pay_rows as $r) {
        $m = $r['mode_of_payment'];
        $mode_agg[$m] = ($mode_agg[$m] ?? 0) + (float)$r['total_amount'];
    }
    $pay_chart['labels'] = array_keys($mode_agg);
    $pay_chart['data']   = array_values($mode_agg);
} catch (Exception $e) { $pay_rows = []; }

// SUPPLIERS
$sup_rows = [];
try {
    $q = $pdo->prepare("
        SELECT
            COALESCE(s.name, po.supplier_name, '—') AS supplier_name,
            COALESCE(s.contact_person, s.phone, '—') AS contact,
            COALESCE(po.expected_delivery_date, po.created_at) AS delivery_date,
            COALESCE(poi.item_name, po.product_name, '—') AS items_delivered,
            COALESCE(poi.quantity, po.quantity, 0) AS quantity,
            COALESCE(poi.unit_price, po.unit_price, 0) AS unit_price,
            COALESCE(poi.total_price, po.total_amount, 0) AS total_amount,
            CASE WHEN po.status NOT IN ('Received', 'Admin Finalized') THEN COALESCE(poi.total_price, po.total_amount, 0) ELSE 0 END AS outstanding_balance,
            po.expected_delivery_date AS due_date,
            CASE WHEN po.status IN ('Received', 'Admin Finalized') THEN 'Paid' ELSE 'Unpaid' END AS pay_status,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u ON po.created_by = u.id
        WHERE po.station_id = ? AND DATE(po.created_at) BETWEEN ? AND ?

        UNION ALL

        SELECT
            COALESCE(fd.supplier, '—') AS supplier_name,
            '—' AS contact,
            fd.delivery_date AS delivery_date,
            COALESCE(fd.fuel_type, '—') AS items_delivered,
            COALESCE(fd.delivery_liters, 0) AS quantity,
            0 AS unit_price,
            0 AS total_amount,
            0 AS outstanding_balance,
            NULL AS due_date,
            'Paid' AS pay_status,
            TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS encoder
        FROM fuel_deliveries fd
        LEFT JOIN users u ON fd.received_by = u.id
        WHERE fd.station_id = ? AND DATE(fd.delivery_date) BETWEEN ? AND ?
        ORDER BY delivery_date DESC
    ");
    $q->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $sup_rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $sup_rows = []; }

// FINANCIAL / PAYABLES
$fin_ap    = [];  // Accounts Payable
$fin_col   = [];  // Collections
$fin_recon = [];  // Reconciliation
try {
    // Accounts Payable — from suppliers/deliveries
    $ap_q = $pdo->prepare("
        SELECT
            COALESCE(s.name, po.supplier_name, '—') AS supplier_name,
            SUM(COALESCE(poi.total_price, po.total_amount, 0)) AS total_payable,
            SUM(CASE WHEN po.status NOT IN ('Received', 'Admin Finalized') THEN COALESCE(poi.total_price, po.total_amount, 0) ELSE 0 END) AS outstanding,
            MAX(po.expected_delivery_date) AS due_date,
            CASE WHEN SUM(CASE WHEN po.status NOT IN ('Received', 'Admin Finalized') THEN 1 ELSE 0 END) = 0 THEN 'Paid' ELSE 'Unpaid' END AS status
        FROM purchase_orders po
        LEFT JOIN purchase_order_items poi ON po.id = poi.po_id
        LEFT JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.station_id=? AND DATE(po.created_at) BETWEEN ? AND ?
        GROUP BY supplier_name
        ORDER BY outstanding DESC
    ");
    $ap_q->execute([$station_id, $date_from, $date_to]);
    $fin_ap = $ap_q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $fin_ap = []; }

try {
    // Collections — fuel + merchandise payments
    $col_q = $pdo->prepare("
        SELECT
            CASE
                WHEN LOWER(COALESCE(ft.payment_method,'')) LIKE '%fleet%' THEN 'Fleet Account'
                WHEN LOWER(COALESCE(ft.payment_method,'')) LIKE '%wallet%' OR LOWER(COALESCE(ft.payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(ft.payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                WHEN LOWER(COALESCE(ft.payment_method,'')) LIKE '%card%' THEN 'Card'
                ELSE 'Cash'
            END AS collection_type,
            COUNT(*) AS txn_count,
            SUM(COALESCE(ft.total_amount,0)) AS collected_amount,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id=u.id
        WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ?
        GROUP BY collection_type, u.first_name, u.last_name

        UNION ALL

        SELECT
            CASE
                WHEN LOWER(COALESCE(mt.payment_method,'')) LIKE '%wallet%' OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%gcash%' OR LOWER(COALESCE(mt.payment_method,'')) LIKE '%maya%' THEN 'E-Wallet'
                WHEN LOWER(COALESCE(mt.payment_method,'')) LIKE '%card%' THEN 'Card'
                ELSE 'Cash'
            END AS collection_type,
            COUNT(*) AS txn_count,
            SUM(COALESCE(mt.total_amount,0)) AS collected_amount,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id=u.id
        WHERE mt.station_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
        GROUP BY collection_type, u.first_name, u.last_name
    ");
    $col_q->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $raw_col = $col_q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $merged_col = [];
    foreach ($raw_col as $r) {
        $k = $r['collection_type'] . '||' . $r['encoder'];
        if (!isset($merged_col[$k])) {
            $merged_col[$k] = $r;
        } else {
            $merged_col[$k]['txn_count'] += (int)$r['txn_count'];
            $merged_col[$k]['collected_amount'] += (float)$r['collected_amount'];
        }
    }
    usort($merged_col, fn($a,$b) => $b['collected_amount'] <=> $a['collected_amount']);
    $fin_col = array_values($merged_col);
} catch (Exception $e) { $fin_col = []; }

try {
    // Reconciliation — expected (all transactions) vs actual (cash only)
    $recon_q = $pdo->prepare("
        SELECT
            DATE(ft.transaction_date) AS recon_date,
            SUM(COALESCE(ft.total_amount,0)) AS expected_total,
            SUM(CASE WHEN LOWER(COALESCE(ft.payment_method,'')) LIKE '%cash%' OR COALESCE(ft.payment_method,'')='' THEN COALESCE(ft.total_amount,0) ELSE 0 END) AS actual_cash,
            SUM(CASE WHEN LOWER(COALESCE(ft.payment_method,'')) NOT LIKE '%cash%' AND COALESCE(ft.payment_method,'')!='' THEN COALESCE(ft.total_amount,0) ELSE 0 END) AS digital_collections,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id=u.id
        WHERE ft.station_id=? AND DATE(ft.transaction_date) BETWEEN ? AND ?
        GROUP BY recon_date, u.first_name, u.last_name

        UNION ALL

        SELECT
            DATE(mt.created_at) AS recon_date,
            SUM(COALESCE(mt.total_amount,0)) AS expected_total,
            SUM(CASE WHEN LOWER(COALESCE(mt.payment_method,'')) LIKE '%cash%' OR COALESCE(mt.payment_method,'')='' THEN COALESCE(mt.total_amount,0) ELSE 0 END) AS actual_cash,
            SUM(CASE WHEN LOWER(COALESCE(mt.payment_method,'')) NOT LIKE '%cash%' AND COALESCE(mt.payment_method,'')!='' THEN COALESCE(mt.total_amount,0) ELSE 0 END) AS digital_collections,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS encoder
        FROM merchandise_transactions mt
        LEFT JOIN users u ON mt.staff_id=u.id
        WHERE mt.station_id=? AND DATE(mt.created_at) BETWEEN ? AND ?
        GROUP BY recon_date, u.first_name, u.last_name
    ");
    $recon_q->execute([$station_id, $date_from, $date_to, $station_id, $date_from, $date_to]);
    $raw_recon = $recon_q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $merged_recon = [];
    foreach ($raw_recon as $r) {
        $k = $r['recon_date'] . '||' . $r['encoder'];
        if (!isset($merged_recon[$k])) {
            $merged_recon[$k] = $r;
        } else {
            $merged_recon[$k]['expected_total'] += (float)$r['expected_total'];
            $merged_recon[$k]['actual_cash'] += (float)$r['actual_cash'];
            $merged_recon[$k]['digital_collections'] += (float)$r['digital_collections'];
        }
    }
    usort($merged_recon, fn($a,$b) => strcmp($b['recon_date'], $a['recon_date']));
    $fin_recon = array_values($merged_recon);
} catch (Exception $e) { $fin_recon = []; }

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* Finance Reports — same design as Operations Reports */
.rpt-wrapper { background:white; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.08); overflow:hidden; }
.fr-filter-bar { display:flex; align-items:center; gap:10px; padding:14px 18px; background:#f8f9fa; border-bottom:1px solid #e2e8f0; flex-wrap:wrap; }
.fr-filter-bar label { font-size:12px; font-weight:600; color:#00264D; margin:0; }
.fr-filter-bar input[type="date"] { padding:7px 10px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px; }
.fr-filter-bar button { padding:7px 16px; background:#00264D; color:white; border:none; border-radius:4px; font-size:12px; font-weight:600; cursor:pointer; }
.fr-export-btn { padding:7px 14px; background:white; color:#00264D; border:1px solid #00264D; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; margin-left:auto; }
.fr-export-btn:hover { background:#00264D; color:white; }
.fr-tabs { display:flex; border-bottom:2px solid #e2e8f0; overflow:hidden; }
.fr-tab { padding:13px 20px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; color:#64748b; background:#f8f9fa; border:none; border-bottom:3px solid transparent; cursor:pointer; white-space:nowrap; transition:all .2s; }
.fr-tab:hover { background:#fff; color:#00264D; }
.fr-tab.active { background:#fff; color:#00264D; border-bottom-color:#00264D; font-weight:800; }
.fr-content { padding:24px; }
.fr-section-panel { display:none; }
.fr-section-panel.active { display:block; }
/* Report header */
.fr-rpt-header { text-align:center; padding:20px 0 14px; border-bottom:2px solid #e2e8f0; margin-bottom:18px; }
.fr-rpt-header .rh-title { font-size:20px; font-weight:800; color:#00264D; text-transform:uppercase; letter-spacing:.5px; margin-bottom:4px; }
.fr-rpt-header .rh-sub { font-size:15px; font-weight:700; color:#00264D; text-transform:uppercase; margin-bottom:8px; }
.fr-rpt-header .rh-station { font-size:12px; color:#64748b; }
.fr-rpt-header .rh-date { font-size:12px; color:#334155; }
/* Sub section heading */
.fr-sub-heading { font-size:13px; font-weight:700; color:#00264D; text-transform:uppercase; padding:8px 0 6px; border-bottom:1px solid #e2e8f0; margin:24px 0 12px; }
/* Table */
.fr-tbl { width:100%; border-collapse:collapse; font-size:12px; }
.fr-tbl thead tr { border-top:2px solid #00264D; border-bottom:1px solid #e2e8f0; background:#f8f9fa; }
.fr-tbl thead th { padding:10px 8px; text-align:left; font-weight:700; color:#00264D; font-size:11px; text-transform:uppercase; }
.fr-tbl tbody tr { border-bottom:1px solid #f1f5f9; }
.fr-tbl tbody tr:hover { background:#f8fafc; }
.fr-tbl tbody td { padding:9px 8px; color:#334155; font-size:12px; }
.fr-tbl tfoot tr { border-top:2px solid #00264D; background:#f0f4ff; }
.fr-tbl tfoot td { padding:10px 8px; font-weight:700; color:#00264D; font-size:12px; }
.fr-empty { text-align:center; padding:28px; color:#94a3b8; font-size:13px; }
.fr-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:10px; font-weight:700; }
.badge-paid { background:#dcfce7; color:#16a34a; }
.badge-unpaid { background:#fee2e2; color:#dc2626; }
/* Chart container */
.fr-chart-wrap { max-width:500px; margin:0 auto 28px; }
@media print {
    @page { size:legal portrait; margin:.3in .4in; }
    * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    html,body { background:white !important; padding:0 !important; margin:0 !important; }
    body > * { display:none !important; }
    .rpt-printable { display:block !important; }
    .fr-filter-bar, .fr-tabs, .fr-export-actions, .fr-chart-wrap { display:none !important; }
    .fr-section-panel { display:block !important; }
    .fr-tbl { font-size:10px !important; }
    .fr-tbl thead th { font-size:9px !important; padding:5px !important; }
    .fr-tbl tbody td, .fr-tbl tfoot td { font-size:10px !important; padding:5px !important; }
}
</style>

<div class="rpt-wrapper">
    <!-- Filter Bar -->
    <form method="GET" class="fr-filter-bar">
        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
        <label><i class="fas fa-calendar"></i> Report Date:</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        <span style="color:#64748b;">to</span>
        <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        <button type="submit"><i class="fas fa-sync-alt"></i> Apply</button>
        <div style="display:flex;gap:6px;margin-left:auto;">
            <button type="button" class="fr-export-btn" onclick="frExport('excel')">
                <i class="fas fa-file-excel"></i> Export Excel
            </button>
            <button type="button" class="fr-export-btn" onclick="frExport('csv')">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <button type="button" class="fr-export-btn" onclick="frPrint()">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </form>

    <!-- Section Tabs -->
    <div class="fr-tabs">
        <button class="fr-tab <?= $section==='payments'?'active':'' ?>" onclick="frTab('payments')">
            <i class="fas fa-money-bill-wave"></i> Payments Report
        </button>
        <button class="fr-tab <?= $section==='suppliers'?'active':'' ?>" onclick="frTab('suppliers')">
            <i class="fas fa-truck"></i> Suppliers Report
        </button>
        <button class="fr-tab <?= $section==='financial'?'active':'' ?>" onclick="frTab('financial')">
            <i class="fas fa-calculator"></i> Financial / Payables Report
        </button>
    </div>

    <div class="fr-content">
        <div class="rpt-printable">

        <!-- PAYMENTS -->
        <div id="fr-panel-payments" class="fr-section-panel <?= $section==='payments'?'active':'' ?>">
            <div class="fr-rpt-header">
                <div class="rh-title">PAYMENTS REPORT</div>
                <div class="rh-sub">FINANCE SUMMARY</div>
                <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
                <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
            </div>

            <!-- Chart -->
            <?php if (!empty($pay_chart['labels'])): ?>
            <div class="fr-chart-wrap">
                <canvas id="payChart" height="160"></canvas>
            </div>
            <?php endif; ?>

            <div class="fr-sub-heading"><i class="fas fa-money-bill-wave"></i> Payment Breakdown by Mode</div>
            <table class="fr-tbl">
                <thead><tr>
                    <th>Mode of Payment</th><th>Transaction Count</th><th>Total Amount</th>
                    <th>Encoder</th><th>Last Transaction</th>
                </tr></thead>
                <tbody>
                <?php $ptc=0;$pta=0;
                if (empty($pay_rows)): ?>
                    <tr><td colspan="5" class="fr-empty">No payment records for this period.</td></tr>
                <?php else: foreach ($pay_rows as $r):
                    $ptc+=(int)$r['txn_count']; $pta+=(float)$r['total_amount']; ?>
                    <tr>
                        <td><?= htmlspecialchars($r['mode_of_payment']) ?></td>
                        <td><?= number_format($r['txn_count']) ?></td>
                        <td>₱<?= number_format($r['total_amount'],2) ?></td>
                        <td><?= htmlspecialchars(trim($r['encoder']))?:'-' ?></td>
                        <td><?= $r['last_txn'] ? date('m/d/Y H:i', strtotime($r['last_txn'])) : '—' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if(!empty($pay_rows)): ?>
                <tfoot><tr>
                    <td>TOTAL</td><td><?= number_format($ptc) ?></td>
                    <td>₱<?= number_format($pta,2) ?></td><td></td><td></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- SUPPLIERS -->
        <div id="fr-panel-suppliers" class="fr-section-panel <?= $section==='suppliers'?'active':'' ?>">
            <div class="fr-rpt-header">
                <div class="rh-title">SUPPLIERS REPORT</div>
                <div class="rh-sub">DELIVERIES & PAYABLES</div>
                <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
                <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
            </div>
            <table class="fr-tbl">
                <thead><tr>
                    <th>Supplier Name</th><th>Contact</th><th>Delivery Date</th>
                    <th>Items Delivered</th><th>Quantity</th><th>Unit Price</th>
                    <th>Total Amount</th><th>Outstanding</th><th>Due Date</th>
                    <th>Status</th><th>Encoder</th>
                </tr></thead>
                <tbody>
                <?php $sta=0;$sout=0;
                if (empty($sup_rows)): ?>
                    <tr><td colspan="11" class="fr-empty">No supplier delivery records for this period.</td></tr>
                <?php else: foreach ($sup_rows as $r):
                    $sta+=(float)$r['total_amount']; $sout+=(float)$r['outstanding_balance'];
                    $paid = strtolower($r['pay_status'])==='paid';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($r['supplier_name']) ?></td>
                        <td><?= htmlspecialchars($r['contact']) ?></td>
                        <td><?= $r['delivery_date'] ? date('m/d/Y', strtotime($r['delivery_date'])) : '—' ?></td>
                        <td><?= htmlspecialchars($r['items_delivered']) ?></td>
                        <td><?= number_format($r['quantity'],2) ?></td>
                        <td>₱<?= number_format($r['unit_price'],2) ?></td>
                        <td>₱<?= number_format($r['total_amount'],2) ?></td>
                        <td>₱<?= number_format($r['outstanding_balance'],2) ?></td>
                        <td><?= $r['due_date'] ? date('m/d/Y', strtotime($r['due_date'])) : '—' ?></td>
                        <td><span class="fr-badge <?= $paid?'badge-paid':'badge-unpaid' ?>"><?= htmlspecialchars($r['pay_status']) ?></span></td>
                        <td><?= htmlspecialchars(trim($r['encoder']))?:'-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if(!empty($sup_rows)): ?>
                <tfoot><tr>
                    <td colspan="6">TOTAL</td>
                    <td>₱<?= number_format($sta,2) ?></td>
                    <td>₱<?= number_format($sout,2) ?></td>
                    <td colspan="3"></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>

        <!-- FINANCIAL / PAYABLES -->
        <div id="fr-panel-financial" class="fr-section-panel <?= $section==='financial'?'active':'' ?>">
            <div class="fr-rpt-header">
                <div class="rh-title">FINANCIAL / PAYABLES REPORT</div>
                <div class="rh-sub">RECONCILIATION SUMMARY</div>
                <div class="rh-station"><?= htmlspecialchars($station_name) ?></div>
                <div class="rh-date"><strong>Date:</strong> <?= date('F j, Y', strtotime($date_from)) ?><?= $date_from!==$date_to?' – '.date('F j, Y', strtotime($date_to)):'' ?></div>
            </div>

            <!-- Accounts Payable -->
            <div class="fr-sub-heading"><i class="fas fa-file-invoice-dollar"></i> Accounts Payable</div>
            <table class="fr-tbl">
                <thead><tr>
                    <th>Supplier Name</th><th>Total Payable</th><th>Outstanding Balance</th><th>Due Date</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php $ap_t=0; $ap_o=0;
                if (empty($fin_ap)): ?>
                    <tr><td colspan="5" class="fr-empty">No payable records for this period.</td></tr>
                <?php else: foreach ($fin_ap as $r):
                    $ap_t+=(float)$r['total_payable']; $ap_o+=(float)$r['outstanding'];
                    $paid=strtolower($r['status'])==='paid';
                ?>
                    <tr>
                        <td><?= htmlspecialchars($r['supplier_name']) ?></td>
                        <td>₱<?= number_format($r['total_payable'],2) ?></td>
                        <td>₱<?= number_format($r['outstanding'],2) ?></td>
                        <td><?= $r['due_date'] ? date('m/d/Y', strtotime($r['due_date'])) : '—' ?></td>
                        <td><span class="fr-badge <?= $paid?'badge-paid':'badge-unpaid' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if(!empty($fin_ap)): ?>
                <tfoot><tr>
                    <td>TOTAL</td><td>₱<?= number_format($ap_t,2) ?></td>
                    <td>₱<?= number_format($ap_o,2) ?></td><td></td><td></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>

            <!-- Collections -->
            <div class="fr-sub-heading"><i class="fas fa-hand-holding-usd"></i> Collections</div>
            <table class="fr-tbl">
                <thead><tr>
                    <th>Collection Type</th><th>Transaction Count</th><th>Collected Amount</th><th>Encoder</th>
                </tr></thead>
                <tbody>
                <?php $col_tc=0; $col_ta=0;
                if (empty($fin_col)): ?>
                    <tr><td colspan="4" class="fr-empty">No collection records for this period.</td></tr>
                <?php else: foreach ($fin_col as $r):
                    $col_tc+=(int)$r['txn_count']; $col_ta+=(float)$r['collected_amount']; ?>
                    <tr>
                        <td><?= htmlspecialchars($r['collection_type']) ?></td>
                        <td><?= number_format($r['txn_count']) ?></td>
                        <td>₱<?= number_format($r['collected_amount'],2) ?></td>
                        <td><?= htmlspecialchars(trim($r['encoder']))?:'-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if(!empty($fin_col)): ?>
                <tfoot><tr>
                    <td>TOTAL</td><td><?= number_format($col_tc) ?></td>
                    <td>₱<?= number_format($col_ta,2) ?></td><td></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>

            <!-- Reconciliation -->
            <div class="fr-sub-heading"><i class="fas fa-balance-scale"></i> Reconciliation (Expected vs Actual)</div>
            <table class="fr-tbl">
                <thead><tr>
                    <th>Date</th><th>Expected Total</th><th>Actual Cash</th>
                    <th>Digital Collections</th><th>Variance</th><th>Encoder</th>
                </tr></thead>
                <tbody>
                <?php $r_exp=0; $r_cash=0; $r_dig=0; $r_var=0;
                if (empty($fin_recon)): ?>
                    <tr><td colspan="6" class="fr-empty">No reconciliation records for this period.</td></tr>
                <?php else: foreach ($fin_recon as $r):
                    $variance = (float)$r['actual_cash'] + (float)$r['digital_collections'] - (float)$r['expected_total'];
                    $r_exp+=(float)$r['expected_total']; $r_cash+=(float)$r['actual_cash'];
                    $r_dig+=(float)$r['digital_collections']; $r_var+=$variance;
                    $var_color = $variance >= 0 ? '#16a34a' : '#dc2626';
                ?>
                    <tr>
                        <td><?= date('m/d/Y', strtotime($r['recon_date'])) ?></td>
                        <td>₱<?= number_format($r['expected_total'],2) ?></td>
                        <td>₱<?= number_format($r['actual_cash'],2) ?></td>
                        <td>₱<?= number_format($r['digital_collections'],2) ?></td>
                        <td style="color:<?=$var_color?>;font-weight:600;"><?= ($variance>=0?'+':'') ?>₱<?= number_format($variance,2) ?></td>
                        <td><?= htmlspecialchars(trim($r['encoder']))?:'-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
                <?php if(!empty($fin_recon)): ?>
                <tfoot><tr>
                    <td>TOTAL</td>
                    <td>₱<?= number_format($r_exp,2) ?></td>
                    <td>₱<?= number_format($r_cash,2) ?></td>
                    <td>₱<?= number_format($r_dig,2) ?></td>
                    <td style="font-weight:700;color:<?=$r_var>=0?'#16a34a':'#dc2626'?>;"><?= ($r_var>=0?'+':'') ?>₱<?= number_format($r_var,2) ?></td>
                    <td></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>

        </div><!-- end rpt-printable -->
    </div><!-- end fr-content -->
</div><!-- end rpt-wrapper -->

<script src="../assets/vendor/xlsx/xlsx.full.min.js"></script>
<script src="../assets/vendor/chart.js/chart.umd.min.js"></script>
<script>
// Tab switching
function frTab(key) {
    const url = new URL(window.location.href);
    url.searchParams.set('section', key);
    const df = document.querySelector('input[name="date_from"]');
    const dt = document.querySelector('input[name="date_to"]');
    if (df) url.searchParams.set('date_from', df.value);
    if (dt) url.searchParams.set('date_to', dt.value);
    window.location.href = url.toString();
}

// ── Export helpers ────────────────────────────────────────────────────────────
function tableToAoA(table) {
    const aoa = [];
    table.querySelectorAll('thead tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('th')].map(th => th.innerText.trim())));
    table.querySelectorAll('tbody tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    table.querySelectorAll('tfoot tr').forEach(tr =>
        aoa.push([...tr.querySelectorAll('td')].map(td => td.innerText.trim())));
    return aoa;
}

function autoWidth(ws, aoa) {
    if (!aoa.length) return;
    ws['!cols'] = aoa[0].map((_, ci) => ({
        wch: Math.min(40, Math.max(10, ...aoa.map(row => String(row[ci] ?? '').length)))
    }));
}

function frExport(type) {
    const wrap = document.querySelector('.rpt-printable');
    if (!wrap) { alert('No report content found.'); return; }
    const activePanel = wrap.querySelector('.fr-section-panel.active');
    if (!activePanel) { alert('No active section found.'); return; }

    const section  = new URL(window.location).searchParams.get('section') || 'finance';
    const dateFrom = document.querySelector('input[name="date_from"]')?.value || '';
    const dateTo   = document.querySelector('input[name="date_to"]')?.value || '';
    const filename = `Finance_Report_${section}_${dateFrom}_${dateTo}`;

    const tables = activePanel.querySelectorAll('table.fr-tbl');
    if (!tables.length) { alert('No table data to export.'); return; }

    if (type === 'csv') {
        // CSV: combine all tables in active section
        let csv = '';
        tables.forEach((tbl, i) => {
            // Add sub-heading if present
            const heading = tbl.previousElementSibling;
            if (heading && heading.classList.contains('fr-sub-heading')) {
                csv += '"' + heading.innerText.trim() + '"\n';
            }
            if (i > 0) csv += '\n';
            tableToAoA(tbl).forEach(row => {
                csv += row.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',') + '\n';
            });
        });
        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href = url; a.download = filename + '.csv'; a.click();
        URL.revokeObjectURL(url);
    } else {
        // Excel: one sheet per sub-section table
        const wb = XLSX.utils.book_new();
        const sheetNames = ['Payments','Accounts Payable','Collections','Reconciliation','Suppliers'];
        tables.forEach((tbl, i) => {
            const heading = tbl.previousElementSibling;
            let sheetName = heading?.innerText?.trim()?.replace(/[:\\\/\?\*\[\]]/g,'')?.substring(0,31)
                          || sheetNames[i] || `Sheet${i+1}`;
            const aoa = tableToAoA(tbl);
            const ws  = XLSX.utils.aoa_to_sheet(aoa);
            autoWidth(ws, aoa);
            XLSX.utils.book_append_sheet(wb, ws, sheetName);
        });
        XLSX.writeFile(wb, filename + '.xlsx');
    }
}

// ── Print ─────────────────────────────────────────────────────────────────────
function frPrint() {
    const wrap   = document.querySelector('.rpt-printable');
    const active = wrap?.querySelector('.fr-section-panel.active') || wrap;
    if (!active) { window.print(); return; }
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Finance Report</title>
    <style>
        @page{size:legal portrait;margin:.3in .4in;}
        *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;box-sizing:border-box;}
        body{font-family:Arial,sans-serif;font-size:11px;color:#000;background:white;margin:0;padding:0;}
        .fr-tabs,.fr-filter-bar,.fr-export-actions,.fr-chart-wrap{display:none !important;}
        .fr-rpt-header{text-align:center;padding:12px 0 8px;border-bottom:2px solid #000;margin-bottom:12px;}
        .rh-title{font-size:16px;font-weight:800;text-transform:uppercase;margin-bottom:3px;}
        .rh-sub{font-size:13px;font-weight:700;text-transform:uppercase;margin-bottom:6px;}
        .rh-station,.rh-date{font-size:11px;color:#444;}
        .fr-sub-heading{font-size:12px;font-weight:700;text-transform:uppercase;padding:6px 0;border-bottom:1px solid #ccc;margin:16px 0 8px;}
        table{width:100%;border-collapse:collapse;font-size:9.5px;}
        thead tr{background:#f0f0f0 !important;border-top:2px solid #000;border-bottom:1px solid #999;}
        thead th{padding:6px 5px;text-align:left;font-weight:700;font-size:9px;text-transform:uppercase;}
        tbody tr{border-bottom:1px solid #ddd;}
        tbody td{padding:5px;}
        tfoot tr{border-top:2px solid #000;background:#f0f0f0 !important;}
        tfoot td{padding:6px 5px;font-weight:700;}
        .fr-empty{text-align:center;padding:12px;color:#888;font-style:italic;}
        .fr-badge{padding:1px 5px;border-radius:3px;font-size:8.5px;font-weight:700;}
        .badge-paid{background:#dcfce7;color:#16a34a;}
        .badge-unpaid{background:#fee2e2;color:#dc2626;}
    </style></head><body>${active.innerHTML}</body></html>`);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); w.close(); }, 400);
}

// Payments chart
<?php if (!empty($pay_chart['labels'])): ?>
const ctx = document.getElementById('payChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($pay_chart['labels']) ?>,
            datasets: [{
                label: 'Amount (₱)',
                data: <?= json_encode($pay_chart['data']) ?>,
                backgroundColor: ['#00264D','#22c55e','#3b82f6','#f59e0b','#64748b'],
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false }, title: { display: true, text: 'Payment Mode Distribution' } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + v.toLocaleString() } } }
        }
    });
}
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
