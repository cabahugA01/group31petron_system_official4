<?php
/**
 * Official Supplier Invoice / Receipt — Manager & Admin View
 * public/print_supplier_invoice.php
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
    http_response_code(403);
    die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied.</p>');
}

$station_id = (int)user_station_id();
$batch_id = trim($_GET['batch_id'] ?? $_GET['po_id'] ?? $_GET['po_number'] ?? $_GET['delivery_ref'] ?? '');
$raw_type = $_GET['type'] ?? '';
$type     = (strpos(strtolower($raw_type), 'fuel') !== false) ? 'fuel' : 'merch';

if ($batch_id === '') {
    die('<p style="font-family:Arial;padding:40px;">Missing invoice reference parameters.</p>');
}

/* ── helpers ── */
function psr_due_date($d): string {
    $ts = strtotime((string)$d);
    return $ts ? date('Y-m-d', strtotime('+30 days', $ts)) : date('Y-m-d', strtotime('+30 days'));
}
function psr_date($d): string {
    $ts = strtotime((string)$d);
    return $ts ? date('F d, Y', $ts) : '—';
}
function psr_money($v): string {
    return '&#8369;' . number_format((float)$v, 2);
}
function si_extract_invoice(?string $remarks): string {
    $remarks = (string)$remarks;
    if (preg_match('/Invoice:\s*([^|]+)/i', $remarks, $m)) {
        return trim($m[1]);
    }
    return '';
}

/* ── station info ── */
$station_name  = 'Petron Station';
$station_addr  = 'Carmen, City of Cagayan de Oro, Misamis Oriental';
$vat_tin       = '—';
try {
    $st = $pdo->prepare("SELECT name, address, location, vat_tin FROM stations WHERE id=? LIMIT 1");
    $st->execute([$station_id]);
    $strow = $st->fetch(PDO::FETCH_ASSOC);
    if ($strow) {
        $station_name = $strow['name'] ?: $station_name;
        $raw_addr     = trim($strow['address'] ?? '');
        $raw_loc      = trim($strow['location'] ?? '');
        if (empty($raw_addr) && !empty($raw_loc) && $raw_loc !== 'CDO') $raw_addr = $raw_loc;
        if (!empty($raw_addr)) $station_addr = $raw_addr;
        $vat_tin = $strow['vat_tin'] ?: '—';
    }
} catch (Exception $e) {}

/* ── fetch items ── */
$items = [];
$is_fuel = false;
$po_number = '';
$supplier = 'Petron Corporation';
$delivery_date = '';
$delivery_time = '';
$dr_number = '';
$sales_invoice_no = '';
$manager_name = '';
$approved_at = '';
$actual_batch_id = '';

// 1. Try Merchandise Stock-In first
if ($type !== 'fuel') {
    try {
        // NOTE: inventory_products JOIN removed — product_name & sku are stored in merchandise_stock_in directly
        $stmt = $pdo->prepare("
            SELECT msi.*,
                   msi.sku AS sku,
                   msi.product_name AS product_name,
                   COALESCE(si.unit, 'pcs') AS unit_display,
                   do.dr_number, do.sales_invoice_no, do.supplier AS do_supplier, do.delivery_date AS do_date,
                   do.delivery_time AS do_time, do.remarks AS do_remarks, u.name AS mgr_name
            FROM merchandise_stock_in msi
            LEFT JOIN station_inventory si ON (si.product_id = msi.product_id AND si.station_id = msi.station_id)
            LEFT JOIN deliveries_oversight do ON msi.delivery_id = do.id
            LEFT JOIN users u ON msi.encoded_by = u.id
            WHERE msi.station_id = ? AND (msi.batch_ref = ? OR msi.po_number = ? OR do.delivery_ref = ?)
            ORDER BY msi.id ASC
        ");
        $stmt->execute([$station_id, $batch_id, $batch_id, $batch_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $is_fuel = false;
            $po_number = $rows[0]['po_number'];
            $actual_batch_id = $rows[0]['batch_ref'];
            $supplier = $rows[0]['do_supplier'] ?: 'Petron Corporation';
            $delivery_date = $rows[0]['do_date'] ?: date('Y-m-d', strtotime($rows[0]['encoded_at']));
            $delivery_time = $rows[0]['do_time'] ?? '';
            $dr_number = $rows[0]['dr_number'] ?: '';
            $sales_invoice_no = $rows[0]['sales_invoice_no'] ?: '';
            if (empty($sales_invoice_no)) {
                $sales_invoice_no = si_extract_invoice($rows[0]['do_remarks']);
            }
            $manager_name = $rows[0]['mgr_name'] ?: '';
            $approved_at = $rows[0]['encoded_at'];
            foreach ($rows as $r) {
                $items[] = [
                    'sku'          => $r['sku'] ?: '—',
                    'name'         => $r['product_name'],
                    'qty_ordered'  => (float)$r['qty_ordered'],
                    'qty_received' => (float)$r['qty_received'],
                    'unit'         => $r['unit_display'] ?: 'pcs',
                    'cost'         => (float)$r['unit_cost'],
                    'total'        => (float)$r['total_cost'],
                    'condition'    => $r['condition_flag'],
                    'remarks'      => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {
        error_log('[print_supplier_invoice] merch query error: ' . $e->getMessage());
    }
}

// 2. Try Fuel Stock-In if empty or if type is fuel
if (empty($items) && $type !== 'merch') {
    try {
        $stmt = $pdo->prepare("
            SELECT fsi.*,
                   do.dr_number, do.sales_invoice_no, do.supplier AS do_supplier, do.delivery_date AS do_date,
                   do.delivery_time AS do_time, do.remarks AS do_remarks, u.name AS mgr_name
            FROM fuel_stock_in fsi
            LEFT JOIN deliveries_oversight do ON fsi.delivery_id = do.id
            LEFT JOIN users u ON fsi.encoded_by = u.id
            WHERE fsi.station_id = ? AND (fsi.batch_ref = ? OR fsi.delivery_ref = ? OR fsi.invoice_no = ?)
            ORDER BY fsi.id ASC
        ");
        $stmt->execute([$station_id, $batch_id, $batch_id, $batch_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $is_fuel = true;
            $po_number = $rows[0]['delivery_ref']; // For fuel, delivery_ref is often the PO number
            $actual_batch_id = $rows[0]['batch_ref'];
            $supplier = $rows[0]['do_supplier'] ?: 'Petron Corporation';
            $delivery_date = $rows[0]['do_date'] ?: date('Y-m-d', strtotime($rows[0]['encoded_at']));
            $delivery_time = $rows[0]['do_time'] ?? '';
            $dr_number = $rows[0]['dr_number'] ?: '';
            $sales_invoice_no = $rows[0]['sales_invoice_no'] ?: '';
            if (empty($sales_invoice_no)) {
                $sales_invoice_no = $rows[0]['invoice_no'] ?: si_extract_invoice($rows[0]['do_remarks']);
            }
            $manager_name = $rows[0]['mgr_name'] ?: '';
            $approved_at = $rows[0]['encoded_at'];
            foreach ($rows as $r) {
                $qty_ordered = (float)$r['qty_expected'];
                $qty_received = (float)$r['qty_received'];
                
                // Fetch unit_cost from deliveries_oversight using delivery_id
                $cost = 0;
                try {
                    $cstmt = $pdo->prepare("SELECT COALESCE(unit_cost, unit_price, 0) FROM deliveries_oversight WHERE id = ?");
                    $cstmt->execute([$r['delivery_id']]);
                    $cost = (float)$cstmt->fetchColumn();
                } catch (Exception $e) {}
                
                $total = $qty_received * $cost;
                $items[] = [
                    'sku' => 'FUEL',
                    'name' => $r['fuel_type'],
                    'qty_ordered' => $qty_ordered,
                    'qty_received' => $qty_received,
                    'unit' => 'L',
                    'cost' => $cost,
                    'total' => $total,
                    'condition' => $r['condition_flag'],
                    'remarks' => $r['remarks']
                ];
            }
        }
    } catch (Exception $e) {}
}

if (empty($items)) {
    die('<p style="font-family:Arial;padding:40px;">No approved Stock-In records found for reference: ' . htmlspecialchars($batch_id) . '.</p>');
}

$invoice_date  = $delivery_date ?: date('Y-m-d');
$due_date      = psr_due_date($invoice_date);
$printed_date  = date('F d, Y g:i A');
$delivery_date_fmt = psr_date($delivery_date);
$delivery_time_fmt = (!empty($delivery_time) && $delivery_time !== '00:00:00')
    ? date('g:i A', strtotime($delivery_time))
    : '';
$invoice_date_fmt  = psr_date($invoice_date);
$due_date_fmt      = psr_date($due_date);
$approved_at_fmt   = $approved_at ? psr_date($approved_at) . ' ' . date('g:i A', strtotime($approved_at)) : '—';

$receipt_no    = 'RCP-' . date('Y') . '-' . strtoupper(substr(md5($po_number . $actual_batch_id), 0, 6));
$logo_url      = '../' . get_system_logo_url($station_id);

/* subtotal from items */
$subtotal = array_sum(array_column($items, 'total'));
$total_amount = $subtotal;

/* back link depending on role */
$back_url = 'admin_inventory_history.php?tab=stock_in';
if ($role === 'admin' || $role === 'superadmin') {
    $back_url = 'admin_inventory_history.php?tab=stock_in';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sales Invoice / Supplier Receipt — <?= htmlspecialchars($po_number) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;font-size:11px;color:#333;background:#fff;padding:15px;line-height:1.3}
.po-document{width:100%;max-width:850px;margin:0 auto;border:1px solid #ddd;padding:20px;position:relative;background:#fff;box-shadow:0 0 10px rgba(0,0,0,0.05);}
.header-box{display:flex;justify-content:space-between;align-items:center;position:relative;padding-bottom:8px;}
.divider-double{border-top:3px double #333;margin:10px 0;}
.divider-single{border-top:1px dashed #ccc;margin:10px 0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;}
.info-block{border:1px solid #e2e8f0;border-radius:4px;padding:8px;background:#f8fafc;}
.info-block h3{font-size:11px;font-weight:bold;color:#002F6C;border-bottom:1px solid #cbd5e1;padding-bottom:3px;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.3px;}
.info-row{margin-bottom:3px;display:flex;font-size:10px;}
.info-row strong{width:140px;color:#475569;display:inline-block;}
.info-row span{color:#0f172a;flex:1;}
.items-table{width:100%;border-collapse:collapse;margin:8px 0;}
.items-table th{background:#002F6C;color:#fff;padding:6px 8px;font-weight:600;text-align:left;font-size:10px;text-transform:uppercase;}
.items-table td{padding:5px 8px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:10px;}
.items-table th.r,.items-table td.r{text-align:right;}
.signatures-box{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:25px;}
.sig-col{text-align:center;}
.sig-line{border-top:1px solid #475569;width:80%;margin:30px auto 3px auto;}
.btn-print-bar{
    position:fixed;
    top:14px;
    right:18px;
    display:flex;
    align-items:center;
    gap:8px;
    z-index:999;
}
.btn-print{
    font-family:sans-serif;
    font-size:12px;
    padding:8px 18px;
    background:#002F6C;
    color:#fff;
    border:none;
    border-radius:6px;
    cursor:pointer;
    text-decoration:none;
    font-weight:700;
    display:inline-flex;
    align-items:center;
    gap:6px;
    transition:background 0.2s, box-shadow 0.2s;
    box-shadow:0 2px 8px rgba(0,47,108,0.30);
}
.btn-print:hover{background:#0b448a;box-shadow:0 4px 14px rgba(0,47,108,0.45);}
.btn-back{
    background:#fff;
    color:#333;
    border:1px solid #ccc;
    box-shadow:0 1px 4px rgba(0,0,0,0.10);
}
.btn-back:hover{background:#f5f5f5;}

/* Rotated official stamp */
.official-stamp {
    position: absolute;
    top: 5px;
    right: 230px;
    border: 3px solid #16a34a;
    color: #16a34a;
    padding: 2px 10px;
    font-size: 13px;
    font-weight: bold;
    text-transform: uppercase;
    transform: rotate(-10deg);
    border-radius: 4px;
    background: #fff;
    font-family: 'Segoe UI', Arial, sans-serif;
    letter-spacing: 1px;
    z-index: 10;
    pointer-events: none;
    text-align: center;
    line-height: 1.1;
}
.official-stamp small {
    display: block;
    font-size: 7px;
    font-weight: bold;
    letter-spacing: 0.5px;
}

@media print{
    @page { size: A4; margin: 0.5cm; }
    .btn-print-bar{display:none !important;}
    body{padding:0;background:#fff;margin:0;}
    .po-document{border:none;padding:15px;box-shadow:none;page-break-after:avoid;}
    a[href]:after{content:none !important;}
    .header-box{padding-bottom:5px;}
    .info-grid{gap:8px;margin-bottom:8px;}
    .info-block{padding:6px;}
    .items-table{margin:6px 0;}
    .signatures-box{margin-top:15px;gap:15px;}
    .sig-line{margin:20px auto 3px auto;}
    .official-stamp{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .items-table th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>

<div class="btn-print-bar">
    <a href="<?= htmlspecialchars($back_url) ?>" class="btn-print btn-back">&larr; Back</a>
    <button onclick="window.print()" class="btn-print">&#128438; Print Invoice</button>
</div>

<div class="po-document">

    <!-- ── Document Header ── -->
    <div class="header-box">
        <!-- Left: Logo & Station -->
        <div style="display:flex; align-items:center; gap:15px; max-width:65%; text-align:left;">
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Petron Logo"
                 style="width:65px; height:65px; object-fit:contain;"
                 onerror="this.src='../assets/img/Petron Logo.png'">
            <div>
                <h1 style="font-family:'Segoe UI', Arial, sans-serif; font-size:15px; font-weight:800; color:#002F6C; margin:0; line-height:1.1;">
                    Petron Station Management System
                </h1>
                <p style="font-size:9px; color:#333; margin-top:3px; font-weight:600;">
                    <?= htmlspecialchars($station_name) ?>
                </p>
                <p style="font-size:8.5px; color:#666; margin-top:2px; text-transform:uppercase; line-height:1.2;">
                    <?= htmlspecialchars($station_addr) ?>
                </p>
                <p style="font-size:8px; color:#666; margin-top:1px;">
                    TIN / VAT: <?= htmlspecialchars($vat_tin) ?>
                </p>
            </div>
        </div>

        <!-- Right: Receipt No & Dates -->
        <div style="text-align:right; position:relative; min-width:30%;">
            <!-- Official stamp rotated -->
            <div class="official-stamp">
                <small>SUPPLIER</small>
                INVOICE
                <small>RECEIPT</small>
            </div>

            <div style="font-size:18px; font-weight:900; color:#002F6C; letter-spacing:-0.5px; font-family:'Courier New', Courier, monospace;">
                <?= htmlspecialchars($receipt_no) ?>
            </div>
            <div style="font-size:9px; color:#555; margin-top:3px; line-height:1.3;">
                <strong>Approved:</strong> <?= $approved_at_fmt ?><br>
                <strong>Printed:</strong> <?= $printed_date ?>
            </div>
        </div>
    </div>

    <!-- Blue divider -->
    <div style="border-top: 3px solid #002F6C; margin-bottom: 12px; width: 100%;"></div>

    <!-- Info Grid Row 1: Invoice Info & Station Info -->
    <div class="info-grid">
        <div class="info-block">
            <h3>Invoice Information</h3>
            <div class="info-row"><strong>Receipt No.</strong>    <span><?= htmlspecialchars($receipt_no) ?></span></div>
            <div class="info-row"><strong>Batch Reference</strong><span><?= htmlspecialchars($actual_batch_id) ?></span></div>
            <div class="info-row"><strong>PO Reference</strong>   <span><?= htmlspecialchars($po_number) ?></span></div>
            <div class="info-row"><strong>Sales Invoice No.</strong><span><?= htmlspecialchars($sales_invoice_no ?: '—') ?></span></div>
            <div class="info-row"><strong>Delivery Receipt (DR)</strong><span><?= htmlspecialchars($dr_number ?: '—') ?></span></div>
        </div>

        <div class="info-block">
            <h3>Station Information</h3>
            <div class="info-row"><strong>Station Name</strong>  <span><?= htmlspecialchars($station_name) ?></span></div>
            <div class="info-row"><strong>Branch Address</strong><span><?= htmlspecialchars($station_addr) ?></span></div>
            <div class="info-row"><strong>TIN / VAT</strong>    <span><?= htmlspecialchars($vat_tin) ?></span></div>
            <div class="info-row"><strong>Approved By</strong>  <span><?= htmlspecialchars($manager_name ?: 'Manager') ?></span></div>
        </div>
    </div>

    <!-- Info Block: Supplier & Delivery Details -->
    <div class="info-block" style="margin-bottom:10px;">
        <h3>Supplier &amp; Delivery Information</h3>
        <div class="info-grid" style="grid-template-columns:1fr 1fr; gap:8px 25px; margin-bottom:0; background:none; padding:0; border:none;">
            <div>
                <div class="info-row"><strong>Supplier</strong>       <span><?= htmlspecialchars($supplier) ?></span></div>
                <div class="info-row"><strong>Invoice Date</strong>   <span><?= $invoice_date_fmt ?></span></div>
                <div class="info-row"><strong>Delivery Date</strong>  <span><?= $delivery_date_fmt ?><?= $delivery_time_fmt ? ' &nbsp;<em style="color:#475569;font-style:normal;">at ' . htmlspecialchars($delivery_time_fmt) . '</em>' : '' ?></span></div>
                <div class="info-row"><strong>Payment Terms</strong>  <span>30 Days</span></div>
            </div>
            <div>
                <div class="info-row"><strong>Delivery Location</strong>  <span><?= htmlspecialchars($station_addr) ?></span></div>
                <div class="info-row"><strong>Due Date</strong>           <span><?= $due_date_fmt ?></span></div>
                <div class="info-row"><strong>Date Approved</strong>      <span><?= $approved_at_fmt ?></span></div>
                <div class="info-row"><strong>Remarks</strong>            <span>Based on actual delivery received.</span></div>
            </div>
        </div>
    </div>

    <!-- Delivered Items -->
    <div>
        <h3 style="font-size:11px; font-weight:bold; color:#0f172a; text-transform:uppercase; margin-bottom:4px;">
            <?= $is_fuel ? 'Fuel Delivery Details' : 'Merchandise Delivery Details' ?>
        </h3>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width:5%;">#</th>
                    <?php if (!$is_fuel): ?>
                    <th style="width:13%;">SKU / Code</th>
                    <th>Product Name</th>
                    <th class="r" style="width:12%;">Qty Ordered</th>
                    <th class="r" style="width:12%;">Qty Received</th>
                    <th style="width:6%;">UOM</th>
                    <th class="r" style="width:14%;">Unit Cost</th>
                    <?php else: ?>
                    <th>Fuel Type</th>
                    <th class="r" style="width:16%;">Liters Ordered</th>
                    <th class="r" style="width:16%;">Liters Received</th>
                    <th class="r" style="width:16%;">Cost / Liter</th>
                    <?php endif; ?>
                    <th class="r" style="width:16%;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <?php if (!$is_fuel): ?>
                    <td><code style="font-weight:bold;font-size:11px;"><?= htmlspecialchars($item['sku']) ?></code></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty_ordered'], 0) ?></td>
                    <td class="r"><?= number_format($item['qty_received'], 0) ?></td>
                    <td><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php else: ?>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty_ordered'], 2) ?> L</td>
                    <td class="r"><?= number_format($item['qty_received'], 2) ?> L</td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php endif; ?>
                    <td class="r">&#8369;<?= number_format($item['total'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Summary Box (right-aligned, same as PO print) -->
    <div style="display:flex; justify-content:flex-end; margin-top:6px;">
        <div style="width:280px; line-height:1.5; border:1px solid #e2e8f0; border-radius:4px; padding:8px; background:#f8fafc; font-size:10px;">
            <div style="display:flex; justify-content:space-between;">
                <span>Subtotal</span>
                <span style="font-weight:600;">&#8369;<?= number_format($subtotal, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between;">
                <span>VAT (12% Included)</span>
                <span style="font-weight:600;">&#8369;<?= number_format($subtotal * 0.12, 2) ?></span>
            </div>
            <div style="display:flex; justify-content:space-between; font-weight:bold; border-top:1px solid #cbd5e1; margin-top:4px; padding-top:4px; font-size:11px; color:#002F6C;">
                <span>Grand Total</span>
                <span>&#8369;<?= number_format($total_amount, 2) ?></span>
            </div>
        </div>
    </div>

    <div class="divider-single" style="margin-top:12px; margin-bottom:35px;"></div>

    <!-- Signature Section -->
    <div class="signatures-box">
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Approved By (Manager)</strong>
            <div style="font-size:9px; color:#475569; margin-top:2px;"><?= htmlspecialchars($manager_name ?: 'Manager') ?></div>
        </div>
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Supplier Representative</strong>
            <div style="font-size:8px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>
        </div>
        <div class="sig-col">
            <div class="sig-line"></div>
            <strong style="font-size:10px;">Received By</strong>
            <div style="font-size:8px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>
        </div>
    </div>

</div>

<script>
// No auto-print — user clicks "Print Invoice" button to print manually
</script>
</body>
</html>

