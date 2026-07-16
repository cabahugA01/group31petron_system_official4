<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied.</p>');
}

$station_id   = (int)user_station_id();
$invoice_no   = trim($_GET['invoice_no'] ?? '');
$po_number    = trim($_GET['po_number']  ?? '');
$invoice_type = $_GET['type'] ?? 'merchandise';
if (!in_array($invoice_type, ['merchandise', 'fuel'], true)) $invoice_type = 'merchandise';

if ($invoice_no === '' || $po_number === '') {
    die('<p style="font-family:Arial;padding:40px;">Missing invoice parameters.</p>');
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

/* ── station info ── */
$station_name  = 'Petron Station';
$station_addr  = 'Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental';
$station_phone = 'N/A';
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

/* ── payment record ── */
$payment = null;
try {
    $ps = $pdo->prepare("SELECT * FROM supplier_invoice_payments WHERE station_id=? AND invoice_type=? AND invoice_no=? AND po_number=? LIMIT 1");
    $ps->execute([$station_id, $invoice_type, $invoice_no, $po_number]);
    $payment = $ps->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ── fetch items ── */
$items         = [];
$supplier      = 'Petron Corporation';
$delivery_date = '';
$total_amount  = 0.0;
$is_fuel       = ($invoice_type === 'fuel');

if (!$is_fuel) {
    try {
        $sq = $pdo->prepare("
            SELECT msi.product_name, msi.sku,
                   COALESCE(NULLIF(msi.qty_received,0),0)  AS qty_received,
                   COALESCE(NULLIF(msi.unit_cost,0),0)     AS unit_cost,
                   COALESCE(NULLIF(msi.total_cost,0),0)    AS total_cost,
                   MIN(COALESCE(d.delivery_date, DATE(msi.encoded_at))) AS delivery_date,
                   COALESCE(MAX(NULLIF(d.supplier,'')), 'Petron Corporation') AS supplier_name
            FROM merchandise_stock_in msi
            LEFT JOIN deliveries_oversight d
                   ON d.station_id = msi.station_id
                  AND d.delivery_type = 'merchandise'
                  AND d.status IN ('Stock-In Complete','Confirmed','Closed')
                  AND (d.id = msi.delivery_id
                    OR (COALESCE(NULLIF(d.source_ref,''),d.delivery_ref) = msi.po_number
                        AND LOWER(TRIM(d.product)) = LOWER(TRIM(msi.product_name))))
            WHERE msi.station_id = ?
              AND COALESCE(msi.qty_received,0) > 0
              AND (msi.po_number = ? OR COALESCE(NULLIF(d.source_ref,''),d.delivery_ref) = ?)
            GROUP BY msi.id, msi.product_name, msi.sku, msi.qty_received, msi.unit_cost, msi.total_cost, msi.encoded_at
            ORDER BY delivery_date ASC
        ");
        $sq->execute([$station_id, $po_number, $po_number]);
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $qty   = (float)$r['qty_received'];
            $cost  = (float)$r['unit_cost'];
            $total = (float)$r['total_cost'];
            if ($total <= 0 && $qty > 0) $total = $qty * $cost;
            $items[] = ['sku'=>$r['sku']?:'—','name'=>$r['product_name'],'qty'=>$qty,'unit'=>'pcs','cost'=>$cost,'total'=>$total];
            $total_amount += $total;
            if (!$delivery_date && $r['delivery_date']) $delivery_date = $r['delivery_date'];
            if ($r['supplier_name']) $supplier = $r['supplier_name'];
        }
    } catch (Exception $e) {}
} else {
    try {
        $sq = $pdo->prepare("
            SELECT fsi.fuel_type,
                   COALESCE(NULLIF(fsi.qty_received,0),0)   AS qty_received,
                   COALESCE(NULLIF(d.unit_cost,0),NULLIF(d.unit_price,0),0) AS cost_per_liter,
                   COALESCE(d.delivery_date, DATE(fsi.encoded_at)) AS delivery_date,
                   COALESCE(NULLIF(d.supplier,''),'Petron Corporation') AS supplier_name,
                   COALESCE(NULLIF(d.source_ref,''),fsi.delivery_ref,CONCAT('FPO-',fsi.delivery_id)) AS po_ref
            FROM fuel_stock_in fsi
            LEFT JOIN deliveries_oversight d ON d.id = fsi.delivery_id AND d.station_id = fsi.station_id
            WHERE fsi.station_id = ?
              AND COALESCE(fsi.qty_received,0) > 0
              AND COALESCE(NULLIF(d.source_ref,''),fsi.delivery_ref,CONCAT('FPO-',fsi.delivery_id)) = ?
            ORDER BY delivery_date ASC
        ");
        $sq->execute([$station_id, $po_number]);
        foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $liters = (float)$r['qty_received'];
            $cost   = (float)$r['cost_per_liter'];
            $total  = $liters * $cost;
            $items[] = ['sku'=>'FUEL','name'=>$r['fuel_type'],'qty'=>$liters,'unit'=>'L','cost'=>$cost,'total'=>$total];
            $total_amount += $total;
            if (!$delivery_date && $r['delivery_date']) $delivery_date = $r['delivery_date'];
            if ($r['supplier_name']) $supplier = $r['supplier_name'];
        }
    } catch (Exception $e) {}
}

if ($payment && (float)$payment['total_amount'] > 0) $total_amount = (float)$payment['total_amount'];
if ($payment && $payment['supplier']) $supplier = $payment['supplier'];

$payment_status = $payment['payment_status'] ?? 'Pending';
$approved_by_id = $payment['approved_by'] ?? null;
$approved_at    = $payment['approved_at']  ?? null;

/* approver name */
$approver_name = $me['name'] ?? 'Admin';
if ($approved_by_id) {
    try {
        $ua = $pdo->prepare("SELECT name FROM users WHERE id=? LIMIT 1");
        $ua->execute([$approved_by_id]);
        $un = $ua->fetchColumn();
        if ($un) $approver_name = $un;
    } catch (Exception $e) {}
}

$invoice_date  = $delivery_date ?: date('Y-m-d');
$due_date      = psr_due_date($invoice_date);
$printed_date  = date('F d, Y g:i A');
$delivery_date_fmt = psr_date($delivery_date);
$invoice_date_fmt  = psr_date($invoice_date);
$due_date_fmt      = psr_date($due_date);
$approved_at_fmt   = $approved_at ? psr_date($approved_at) . ' ' . date('g:i A', strtotime($approved_at)) : '—';

$receipt_no    = 'RCP-' . date('Y') . '-' . strtoupper(substr(md5($invoice_no . $po_number), 0, 6));
$logo_url      = '../' . get_system_logo_url($station_id);

/* status colour for stamp */
$stamp_color = $payment_status === 'Paid' ? '#2e7d32' : ($payment_status === 'Approved' ? '#1d4ed8' : '#b45309');

/* subtotal from items */
$subtotal = array_sum(array_column($items, 'total'));
if ($subtotal <= 0) $subtotal = $total_amount;

/* log */
try {
    log_activity($pdo, (int)$me['id'], 'Print Supplier Receipt',
        "Receipt for invoice {$invoice_no} | PO {$po_number} printed by {$me['name']}.");
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Supplier Payment Receipt — <?= htmlspecialchars($invoice_no) ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;font-size:11px;color:#333;background:#fff;padding:15px;line-height:1.3}
.po-document{width:100%;max-width:850px;margin:0 auto;border:1px solid #ddd;padding:20px;position:relative;background:#fff;box-shadow:0 0 10px rgba(0,0,0,0.05);}
.header-box{display:flex;justify-content:space-between;align-items:center;position:relative;padding-bottom:8px;}
.divider-double{border-top:3px double #333;margin:10px 0;}
.divider-single{border-top:1px dashed #ccc;margin:10px 0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;}
.info-block{border:1px solid #e2e8f0;border-radius:4px;padding:8px;background:#f8fafc;}
.info-block h3{font-size:11px;font-weight:bold;color:#0f172a;border-bottom:1px solid #cbd5e1;padding-bottom:3px;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.3px;}
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
.btn-print-bar{max-width:850px;margin:0 auto 10px auto;display:flex;justify-content:flex-end;gap:10px;}
.btn-print{font-family:sans-serif;font-size:11px;padding:6px 14px;background:#002F6C;color:#fff;border:none;border-radius:4px;cursor:pointer;text-decoration:none;font-weight:600;}
.btn-print:hover{background:#0b448a;}
.btn-back{background:#fff;color:#333;border:1px solid #ccc;}
.btn-back:hover{background:#f5f5f5;}

/* Rotated official stamp */
.official-stamp {
    position: absolute;
    top: 5px;
    right: 230px;
    border: 3px solid <?= $stamp_color ?>;
    color: <?= $stamp_color ?>;
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
    <a href="admin_supplier_billing.php?tab=<?= urlencode($invoice_type) ?>" class="btn-print btn-back">&larr; Back</a>
    <button onclick="window.print()" class="btn-print">Print Receipt</button>
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
                <?= htmlspecialchars($payment_status) ?>
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
            <div class="info-row"><strong>Invoice No.</strong>    <span><?= htmlspecialchars($invoice_no) ?></span></div>
            <div class="info-row"><strong>PO Reference</strong>   <span><?= htmlspecialchars($po_number) ?></span></div>
            <div class="info-row"><strong>Invoice Type</strong>   <span><?= ucfirst($invoice_type) ?></span></div>
            <div class="info-row"><strong>Payment Status</strong> <span style="font-weight:600; color:<?= $stamp_color ?>;"><?= htmlspecialchars($payment_status) ?></span></div>
        </div>

        <div class="info-block">
            <h3>Station Information</h3>
            <div class="info-row"><strong>Station Name</strong>  <span><?= htmlspecialchars($station_name) ?></span></div>
            <div class="info-row"><strong>Branch Address</strong><span><?= htmlspecialchars($station_addr) ?></span></div>
            <div class="info-row"><strong>TIN / VAT</strong>    <span><?= htmlspecialchars($vat_tin) ?></span></div>
            <div class="info-row"><strong>Approved By</strong>  <span><?= htmlspecialchars($approver_name) ?></span></div>
        </div>
    </div>

    <!-- Info Block: Supplier & Delivery Details -->
    <div class="info-block" style="margin-bottom:10px;">
        <h3>Supplier &amp; Delivery Information</h3>
        <div class="info-grid" style="grid-template-columns:1fr 1fr; gap:8px 25px; margin-bottom:0; background:none; padding:0; border:none;">
            <div>
                <div class="info-row"><strong>Supplier</strong>       <span><?= htmlspecialchars($supplier) ?></span></div>
                <div class="info-row"><strong>Invoice Date</strong>   <span><?= $invoice_date_fmt ?></span></div>
                <div class="info-row"><strong>Delivery Date</strong>  <span><?= $delivery_date_fmt ?></span></div>
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
                    <th class="r" style="width:10%;">Qty Received</th>
                    <th style="width:6%;">UOM</th>
                    <th class="r" style="width:14%;">Unit Cost</th>
                    <?php else: ?>
                    <th>Fuel Type</th>
                    <th class="r" style="width:16%;">Liters Received</th>
                    <th class="r" style="width:16%;">Cost / Liter</th>
                    <?php endif; ?>
                    <th class="r" style="width:16%;">Total Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="<?= $is_fuel ? 5 : 7 ?>" style="text-align:center;color:#64748b;padding:12px;">
                        No item-level records found. Grand total is based on the payment record.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <?php if (!$is_fuel): ?>
                    <td><code style="font-weight:bold;font-size:11px;"><?= htmlspecialchars($item['sku']) ?></code></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty'], 0) ?></td>
                    <td><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php else: ?>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td class="r"><?= number_format($item['qty'], 2) ?> L</td>
                    <td class="r">&#8369;<?= number_format($item['cost'], 2) ?></td>
                    <?php endif; ?>
                    <td class="r">&#8369;<?= number_format($item['total'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
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
            <strong style="font-size:10px;">Approved By (Admin)</strong>
            <div style="font-size:9px; color:#475569; margin-top:2px;"><?= htmlspecialchars($approver_name) ?></div>
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

</div><!-- end .po-document -->

<script>
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 500);
});
<?php endif; ?>
</script>
</body>
</html>
