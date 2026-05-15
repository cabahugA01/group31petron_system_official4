<?php
/**
 * Official Purchase Order Print Document
 * Matches the actual purchase_orders table schema used in purchase_orders.php
 * Logs print action to activity_logs for audit trail
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me   = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['admin', 'superadmin'])) {
    http_response_code(403);
    die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied. Admin privileges required.</p>');
}

$po_id = (int)($_GET['id'] ?? 0);
if (!$po_id) {
    die('<p style="font-family:Arial;padding:40px;">No Purchase Order ID provided.</p>');
}

// ── Fetch PO with all related data ────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT po.*,
               st.name     AS station_name,
               st.location AS station_location,
               st.address  AS station_address,
               st.vat_tin  AS station_vat_tin,
               u.name      AS created_by_name,
               ab.name     AS approved_by_name,
               sr.staff_id,
               sr.item_sku,
               sr.requested_quantity AS sr_requested_qty,
               sr.approved_quantity  AS sr_approved_qty,
               sr.manager_notes      AS sr_manager_notes,
               staff_u.name          AS staff_name,
               mgr_u.name            AS manager_name
        FROM purchase_orders po
        LEFT JOIN stations st   ON po.station_id  = st.id
        LEFT JOIN users u       ON po.created_by  = u.id
        LEFT JOIN users ab      ON po.approved_by = ab.id
        LEFT JOIN stock_requests sr   ON po.request_id = sr.id
        LEFT JOIN users staff_u ON sr.staff_id    = staff_u.id
        LEFT JOIN users mgr_u   ON sr.manager_id  = mgr_u.id
        WHERE po.id = ?
        LIMIT 1
    ");
    $stmt->execute([$po_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('<p style="font-family:Arial;padding:40px;">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

if (!$po) {
    die('<p style="font-family:Arial;padding:40px;">Purchase Order #' . $po_id . ' not found.</p>');
}

// Only allow printing of finalized (Official/Approved) POs
$printable_statuses = ['Official', 'Approved', 'official', 'approved'];
if (!in_array($po['status'], $printable_statuses)) {
    die('<p style="font-family:Arial;padding:40px;color:#856404;">This PO has not been finalized yet. Only Official/Approved POs can be printed.</p>');
}

// ── Log print action to activity_logs ────────────────────────────────────
try {
    log_activity(
        $pdo,
        $me['id'],
        'Print Purchase Order',
        "PO {$po['po_number']} printed by {$me['name']} (Admin). Product: {$po['product_name']} | Qty: {$po['quantity']} | Total: ₱" . number_format($po['total_amount'], 2) . " | Station: {$po['station_name']}"
    );
} catch (Exception $e) { /* fail silently */ }

// ── Build display values ──────────────────────────────────────────────────
$station_display = trim(
    ($po['station_name'] ?? '') . "\n" .
    ($po['station_address'] ?? $po['station_location'] ?? '')
);
$finalized_date  = $po['approved_at']  ? date('F d, Y', strtotime($po['approved_at']))  : date('F d, Y', strtotime($po['created_at']));
$finalized_time  = $po['approved_at']  ? date('g:i A', strtotime($po['approved_at']))   : '';
$generated_date  = date('F d, Y g:i A');
$po_number       = htmlspecialchars($po['po_number']);
$product_name    = htmlspecialchars($po['product_name'] ?? '—');
$qty             = number_format((float)($po['quantity'] ?? 0), 0);
$unit_price      = number_format((float)($po['unit_price'] ?? 0), 2);
$total_amount    = number_format((float)($po['total_amount'] ?? 0), 2);
$sku             = htmlspecialchars($po['item_sku'] ?? '');
$staff_name      = htmlspecialchars($po['staff_name']    ?? $po['created_by_name'] ?? '—');
$manager_name    = htmlspecialchars($po['manager_name']  ?? '—');
$admin_name      = htmlspecialchars($po['approved_by_name'] ?? $me['name'] ?? '—');
$sr_notes        = htmlspecialchars($po['sr_manager_notes'] ?? $po['notes'] ?? '');
$vat_tin         = htmlspecialchars($po['station_vat_tin'] ?? '');

// Audit log link
$audit_url = 'activity_logs.php?module=' . urlencode('Purchase Order') . '&start=' . date('Y-m-01') . '&end=' . date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Order — <?php echo $po_number; ?></title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.5;
    color: #1a1a2e;
    background: #f0f2f5;
}

/* ── Screen toolbar (hidden on print) ── */
.screen-toolbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    background: #002F6C;
    color: #fff;
    padding: 10px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    z-index: 1000;
    box-shadow: 0 2px 12px rgba(0,0,0,.3);
}
.screen-toolbar .toolbar-left {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    font-weight: 600;
}
.screen-toolbar .toolbar-right {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn-print {
    background: #fff;
    color: #002F6C;
    border: none;
    padding: 8px 20px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-print:hover { background: #e8f0fe; }
.btn-back {
    background: rgba(255,255,255,.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,.3);
    padding: 7px 16px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-back:hover { background: rgba(255,255,255,.25); }
.btn-audit {
    background: rgba(255,255,255,.15);
    color: #fff;
    border: 1px solid rgba(255,255,255,.3);
    padding: 7px 16px;
    border-radius: 6px;
    font-size: 12px;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-audit:hover { background: rgba(255,255,255,.25); }

/* ── Page wrapper ── */
.page-wrapper {
    padding: 80px 24px 40px;
    display: flex;
    justify-content: center;
}

/* ── PO Document ── */
.po-document {
    background: #fff;
    width: 210mm;
    min-height: 297mm;
    padding: 20mm 18mm;
    box-shadow: 0 4px 32px rgba(0,0,0,.15);
    position: relative;
}

/* ── Document Header ── */
.doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-bottom: 16px;
    border-bottom: 3px solid #002F6C;
    margin-bottom: 20px;
}
.doc-header-left {
    display: flex;
    align-items: center;
    gap: 14px;
}
.doc-logo {
    width: 56px;
    height: 56px;
    object-fit: contain;
}
.doc-company h1 {
    font-size: 16px;
    font-weight: 800;
    color: #002F6C;
    line-height: 1.2;
}
.doc-company p {
    font-size: 10px;
    color: #555;
    margin-top: 3px;
    max-width: 280px;
    line-height: 1.4;
}
.doc-header-right {
    text-align: right;
}
.doc-po-label {
    font-size: 10px;
    color: #888;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 4px;
}
.doc-po-number {
    font-size: 20px;
    font-weight: 800;
    color: #002F6C;
    letter-spacing: .5px;
}
.doc-po-date {
    font-size: 10px;
    color: #666;
    margin-top: 4px;
}

/* ── Official stamp ── */
.official-stamp {
    position: absolute;
    top: 28mm;
    right: 18mm;
    border: 3px solid #16a34a;
    color: #16a34a;
    padding: 6px 14px;
    font-size: 18px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 2px;
    transform: rotate(-12deg);
    opacity: .75;
    border-radius: 4px;
    pointer-events: none;
}

/* ── Section title ── */
.section-title {
    font-size: 10px;
    font-weight: 700;
    color: #002F6C;
    text-transform: uppercase;
    letter-spacing: .6px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 5px;
    margin-bottom: 10px;
}

/* ── Info grid ── */
.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}
.info-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 12px 14px;
}
.info-row {
    display: flex;
    gap: 6px;
    margin-bottom: 5px;
    font-size: 11px;
}
.info-row:last-child { margin-bottom: 0; }
.info-label {
    font-weight: 700;
    color: #475569;
    min-width: 90px;
    flex-shrink: 0;
}
.info-value { color: #1e293b; }

/* ── Audit trail chain ── */
.audit-trail-box {
    background: #f0f4ff;
    border: 1px solid #c5d3f0;
    border-radius: 6px;
    padding: 12px 14px;
    margin-bottom: 20px;
}
.audit-chain {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.audit-step {
    background: #fff;
    border: 1px solid #c5d3f0;
    border-radius: 4px;
    padding: 5px 10px;
    font-size: 11px;
    font-weight: 600;
    color: #002F6C;
}
.audit-step .role {
    font-size: 9px;
    font-weight: 400;
    color: #64748b;
    display: block;
    margin-top: 1px;
}
.audit-arrow {
    color: #94a3b8;
    font-size: 14px;
}

/* ── Items table ── */
.items-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
    font-size: 11px;
}
.items-table thead th {
    background: #002F6C;
    color: #fff;
    padding: 9px 12px;
    text-align: left;
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.items-table thead th.text-right { text-align: right; }
.items-table tbody td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    color: #334155;
}
.items-table tbody td.text-right { text-align: right; }
.items-table tbody tr:last-child td { border-bottom: none; }
.items-table tfoot td {
    padding: 8px 12px;
    font-size: 11px;
}
.items-table tfoot .total-row td {
    background: #002F6C;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    padding: 10px 12px;
}

/* ── Notes ── */
.notes-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 6px;
    padding: 10px 14px;
    margin-bottom: 20px;
    font-size: 11px;
    color: #78350f;
}

/* ── Signatures ── */
.signatures {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 24px;
    margin-top: 32px;
    page-break-inside: avoid;
}
.sig-box { text-align: center; }
.sig-line {
    border-top: 1px solid #334155;
    margin-top: 48px;
    padding-top: 8px;
}
.sig-name {
    font-weight: 700;
    font-size: 12px;
    color: #1e293b;
}
.sig-role {
    font-size: 10px;
    color: #64748b;
    margin-top: 2px;
}
.sig-date {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 4px;
}

/* ── Document footer ── */
.doc-footer {
    margin-top: 32px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
    text-align: center;
    font-size: 9px;
    color: #94a3b8;
    line-height: 1.6;
}

/* ── Print media ── */
@media print {
    body { background: #fff; }
    .screen-toolbar { display: none !important; }
    .page-wrapper { padding: 0; }
    .po-document {
        box-shadow: none;
        width: 100%;
        padding: 12mm 14mm;
    }
    @page {
        size: A4;
        margin: 0;
    }
}
</style>
</head>
<body>

<!-- ── Screen Toolbar (hidden on print) ── -->
<div class="screen-toolbar">
    <div class="toolbar-left">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Purchase Order — <?php echo $po_number; ?>
        <span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:10px;font-size:11px;font-weight:400;">
            <?php echo htmlspecialchars($po['status']); ?>
        </span>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo $audit_url; ?>" class="btn-audit" title="View this action in the audit log">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            View in Audit Log
        </a>
        <a href="purchase_orders.php" class="btn-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back to POs
        </a>
        <button class="btn-print" onclick="window.print()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print / Save as PDF
        </button>
    </div>
</div>

<!-- ── PO Document ── -->
<div class="page-wrapper">
<div class="po-document">

    <!-- Official stamp -->
    <div class="official-stamp">&#10003; Official</div>

    <!-- Document Header -->
    <div class="doc-header">
        <div class="doc-header-left">
            <img src="../assets/img/Petron Logo.png" alt="Petron" class="doc-logo"
                 onerror="this.style.display='none'">
            <div class="doc-company">
                <h1>Petron Station Management System</h1>
                <p><?php
                    $addr_parts = array_filter([
                        $po['station_name'] ?? '',
                        $po['station_address'] ?? $po['station_location'] ?? '',
                    ]);
                    echo htmlspecialchars(implode(' — ', $addr_parts));
                    if ($vat_tin) echo '<br>VAT TIN: ' . $vat_tin;
                ?></p>
            </div>
        </div>
        <div class="doc-header-right">
            <div class="doc-po-label">Purchase Order</div>
            <div class="doc-po-number"><?php echo $po_number; ?></div>
            <div class="doc-po-date">
                Finalized: <?php echo $finalized_date; ?><?php echo $finalized_time ? ' ' . $finalized_time : ''; ?><br>
                Printed: <?php echo $generated_date; ?>
            </div>
        </div>
    </div>

    <!-- Order & Station Info -->
    <div class="info-grid">
        <div class="info-box">
            <div class="section-title">Order Information</div>
            <div class="info-row">
                <span class="info-label">PO Number:</span>
                <span class="info-value"><strong><?php echo $po_number; ?></strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value" style="color:#16a34a;font-weight:700;"><?php echo htmlspecialchars($po['status']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Finalized:</span>
                <span class="info-value"><?php echo $finalized_date; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Finalized By:</span>
                <span class="info-value"><?php echo $admin_name; ?> (Admin)</span>
            </div>
            <?php if ($po['expected_delivery_date']): ?>
            <div class="info-row">
                <span class="info-label">Expected Delivery:</span>
                <span class="info-value"><?php echo date('F d, Y', strtotime($po['expected_delivery_date'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="info-box">
            <div class="section-title">Station Information</div>
            <div class="info-row">
                <span class="info-label">Station:</span>
                <span class="info-value"><strong><?php echo htmlspecialchars($po['station_name'] ?? '—'); ?></strong></span>
            </div>
            <?php if ($po['station_address'] || $po['station_location']): ?>
            <div class="info-row">
                <span class="info-label">Address:</span>
                <span class="info-value"><?php echo htmlspecialchars($po['station_address'] ?? $po['station_location'] ?? ''); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($vat_tin): ?>
            <div class="info-row">
                <span class="info-label">VAT TIN:</span>
                <span class="info-value"><?php echo $vat_tin; ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Supplier:</span>
                <span class="info-value"><strong>Petron Corporation</strong></span>
            </div>
        </div>
    </div>

    <!-- Audit Trail Chain -->
    <div class="audit-trail-box">
        <div class="section-title" style="margin-bottom:8px;">Approval Chain / Audit Trail</div>
        <div class="audit-chain">
            <div class="audit-step">
                <span>&#128100; <?php echo $staff_name; ?></span>
                <span class="role">Staff — Encoded Request</span>
            </div>
            <span class="audit-arrow">&#8594;</span>
            <div class="audit-step">
                <span>&#128101; <?php echo $manager_name; ?></span>
                <span class="role">Manager — Validated &amp; Generated PO</span>
            </div>
            <span class="audit-arrow">&#8594;</span>
            <div class="audit-step" style="background:#f0fdf4;border-color:#86efac;">
                <span>&#128203; <?php echo $admin_name; ?></span>
                <span class="role">Admin — Finalized as Official</span>
            </div>
            <span class="audit-arrow">&#8594;</span>
            <div class="audit-step" style="background:#eff6ff;border-color:#93c5fd;">
                <span>&#128666; Petron Corporation</span>
                <span class="role">Supplier — Receive &amp; Arrange Delivery</span>
            </div>
        </div>
    </div>

    <!-- Order Items -->
    <div class="section-title">Order Details</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:5%;">#</th>
                <th style="width:35%;">Product / Item Description</th>
                <th style="width:15%;">SKU / Code</th>
                <th class="text-right" style="width:15%;">Quantity</th>
                <th class="text-right" style="width:15%;">Unit Price</th>
                <th class="text-right" style="width:15%;">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td><strong><?php echo $product_name; ?></strong></td>
                <td style="color:#64748b;font-size:10px;"><?php echo $sku ?: '—'; ?></td>
                <td class="text-right"><strong><?php echo $qty; ?></strong></td>
                <td class="text-right">&#8369;<?php echo $unit_price; ?></td>
                <td class="text-right"><strong>&#8369;<?php echo $total_amount; ?></strong></td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;color:#64748b;font-size:10px;padding-top:10px;">
                    Subtotal:
                </td>
                <td class="text-right" style="padding-top:10px;">&#8369;<?php echo $total_amount; ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" style="text-align:right;">TOTAL ORDER AMOUNT:</td>
                <td class="text-right">&#8369;<?php echo $total_amount; ?></td>
            </tr>
        </tfoot>
    </table>

    <?php if ($sr_notes): ?>
    <!-- Notes -->
    <div class="notes-box">
        <strong>&#128203; Manager Notes / Special Instructions:</strong><br>
        <?php echo nl2br($sr_notes); ?>
    </div>
    <?php endif; ?>

    <!-- Signature Lines -->
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-line">
                <div class="sig-name"><?php echo $staff_name; ?></div>
                <div class="sig-role">Prepared By (Staff)</div>
                <div class="sig-date">Date: _______________</div>
            </div>
        </div>
        <div class="sig-box">
            <div class="sig-line">
                <div class="sig-name"><?php echo $manager_name; ?></div>
                <div class="sig-role">Validated By (Manager)</div>
                <div class="sig-date">Date: _______________</div>
            </div>
        </div>
        <div class="sig-box">
            <div class="sig-line">
                <div class="sig-name"><?php echo $admin_name; ?></div>
                <div class="sig-role">Finalized By (Admin)</div>
                <div class="sig-date">Date: <?php echo $finalized_date; ?></div>
            </div>
        </div>
    </div>

    <!-- Document Footer -->
    <div class="doc-footer">
        <strong>This Purchase Order is official and valid only with authorized signatures.</strong><br>
        Document ID: <?php echo $po_number; ?> &nbsp;|&nbsp;
        Station: <?php echo htmlspecialchars($po['station_name'] ?? ''); ?> &nbsp;|&nbsp;
        Supplier: Petron Corporation &nbsp;|&nbsp;
        Generated: <?php echo $generated_date; ?><br>
        <span style="font-size:8px;">
            Printed by: <?php echo htmlspecialchars($me['name']); ?> (<?php echo ucfirst($role); ?>) &nbsp;|&nbsp;
            This document serves as proof of finalization and approval. Keep a copy for station records and compliance.
        </span>
    </div>

</div><!-- /po-document -->
</div><!-- /page-wrapper -->

<script>
// Auto-print if ?print=1 is in URL
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 600);
});
<?php endif; ?>
</script>
</body>
</html>
