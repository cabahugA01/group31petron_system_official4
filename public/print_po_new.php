<?php
/**
 * Official Purchase Order — Final Admin View
 * print_po_new.php
 *
 * Features:
 *  - Auto-generated PO Number display
 *  - Status badge (Approved / Pending / Cancelled)
 *  - Date Finalized + Finalized By (Admin)
 *  - Station Name, Address, Supplier
 *  - Approval Chain / Audit Trail: Staff → Manager → Admin → Supplier
 *  - Order Details Table: #, Product, SKU, Qty, Unit Price, Total Amount
 *  - Subtotal + Total Order Amount (₱ formatted)
 *  - Date Finalized + Printed Date
 *  - "OFFICIAL" watermark stamp
 *  - Audit trail log link
 *  - Print / Save as PDF button
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
               st.name       AS station_name,
               st.location   AS station_location,
               st.address    AS station_address,
               st.vat_tin    AS station_vat_tin,
               sup.name      AS supplier_name,
               u.name        AS created_by_name,
               ab.name       AS approved_by_name,
               sr.staff_id,
               sr.item_sku,
               sr.requested_quantity AS sr_requested_qty,
               sr.approved_quantity  AS sr_approved_qty,
               sr.manager_notes      AS sr_manager_notes,
               staff_u.name          AS staff_name,
               mgr_u.name            AS manager_name
        FROM purchase_orders po
        LEFT JOIN stations st     ON po.station_id  = st.id
        LEFT JOIN suppliers sup   ON po.supplier_id = sup.id
        LEFT JOIN users u         ON po.created_by  = u.id
        LEFT JOIN users ab        ON po.approved_by = ab.id
        LEFT JOIN stock_requests sr   ON po.request_id = sr.id
        LEFT JOIN users staff_u   ON sr.staff_id    = staff_u.id
        LEFT JOIN users mgr_u     ON sr.manager_id  = mgr_u.id
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

// Only allow printing of finalized POs
$printable_statuses = ['Official', 'Approved', 'official', 'approved', 'Approved PO', 'approved po'];
if (!in_array($po['status'], $printable_statuses)) {
    die('<p style="font-family:Arial;padding:40px;color:#856404;">This PO has not been finalized yet. Only Official/Approved POs can be printed.</p>');
}

// ── Log print action ──────────────────────────────────────────────────────
try {
    log_activity(
        $pdo,
        $me['id'],
        'Print Purchase Order',
        "PO {$po['po_number']} printed by {$me['name']} (Admin). Product: {$po['product_name']} | Qty: {$po['quantity']} | Total: ₱" . number_format($po['total_amount'], 2) . " | Station: {$po['station_name']}"
    );
} catch (Exception $e) { /* fail silently */ }

// ── Build display values ──────────────────────────────────────────────────
$finalized_dt   = $po['approved_at'] ?: $po['created_at'];
$finalized_date = date('F d, Y', strtotime($finalized_dt));
$finalized_time = date('g:i A', strtotime($finalized_dt));
$printed_date   = date('F d, Y g:i A');
$po_number      = htmlspecialchars($po['po_number']);
$product_name   = htmlspecialchars($po['product_name'] ?? '—');
$qty            = (float)($po['quantity'] ?? 0);
$unit_price     = (float)($po['unit_price'] ?? 0);
$total_amount   = (float)($po['total_amount'] ?? round($qty * $unit_price, 2));
$sku            = htmlspecialchars($po['item_sku'] ?? '');
$staff_name     = htmlspecialchars($po['staff_name']       ?? $po['created_by_name'] ?? '—');
$manager_name   = htmlspecialchars($po['manager_name']     ?? '—');
$admin_name     = htmlspecialchars($po['approved_by_name'] ?? $me['name'] ?? '—');
$sr_notes       = htmlspecialchars($po['sr_manager_notes'] ?? $po['notes'] ?? '');
$vat_tin        = htmlspecialchars($po['station_vat_tin']  ?? '');
$station_name   = htmlspecialchars($po['station_name']     ?? '—');
$station_addr   = htmlspecialchars($po['station_address']  ?? $po['station_location'] ?? '');
$supplier_name  = htmlspecialchars($po['supplier_name']    ?? 'Not Assigned');

// Status display
$raw_status = $po['status'] ?? 'Approved';
$status_label = in_array(strtolower($raw_status), ['approved po', 'official', 'approved']) ? 'Approved' : ucfirst($raw_status);
$status_color = match(strtolower($raw_status)) {
    'approved', 'official', 'approved po' => '#16a34a',
    'pending', 'pending admin validation'  => '#d97706',
    'cancelled', 'rejected'               => '#dc2626',
    default                               => '#475569',
};
$status_bg = match(strtolower($raw_status)) {
    'approved', 'official', 'approved po' => '#dcfce7',
    'pending', 'pending admin validation'  => '#fef3c7',
    'cancelled', 'rejected'               => '#fee2e2',
    default                               => '#f1f5f9',
};

$audit_url = 'activity_logs.php?module=' . urlencode('Purchase Order') . '&start=' . date('Y-m-01') . '&end=' . date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Order — <?php echo $po_number; ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#1a1a2e;background:#eef1f6}

/* ── Screen toolbar ── */
.screen-toolbar{position:fixed;top:0;left:0;right:0;background:#002F6C;color:#fff;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;z-index:1000;box-shadow:0 2px 12px rgba(0,0,0,.35)}
.toolbar-left{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600}
.toolbar-right{display:flex;align-items:center;gap:8px}
.status-pill{padding:3px 12px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.3px}
.btn-toolbar{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:background .15s}
.btn-print{background:#fff;color:#002F6C}.btn-print:hover{background:#dbeafe}
.btn-ghost{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)}.btn-ghost:hover{background:rgba(255,255,255,.25)}

/* ── Page wrapper ── */
.page-wrapper{padding:76px 20px 48px;display:flex;justify-content:center}

/* ── PO Document ── */
.po-document{background:#fff;width:210mm;min-height:297mm;padding:18mm 16mm 16mm;box-shadow:0 6px 40px rgba(0,0,0,.18);position:relative;overflow:visible;display:flex;flex-direction:column}

/* ── Watermark ── */
.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:110px;font-weight:900;color:rgba(0,47,108,.055);letter-spacing:8px;pointer-events:none;user-select:none;white-space:nowrap;z-index:0;overflow:hidden}

/* ── Official stamp ── */
.official-stamp{position:absolute;top:14mm;right:14mm;border:3px solid #16a34a;color:#16a34a;padding:5px 13px;font-size:15px;font-weight:900;text-transform:uppercase;letter-spacing:3px;transform:rotate(-12deg);opacity:.8;border-radius:4px;pointer-events:none;z-index:2;background:rgba(255,255,255,.85);max-width:120px;text-align:center}
.official-stamp small{display:block;font-size:8px;letter-spacing:1px;text-align:center;margin-top:2px;font-weight:600}

/* ── All content above watermark ── */
.po-content{position:relative;z-index:1;display:flex;flex-direction:column;flex:1}

/* ── Push signatures + footer to bottom ── */
.po-bottom{margin-top:auto;padding-top:16px}

/* ── Document header ── */
.doc-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:14px;border-bottom:3px solid #002F6C;margin-bottom:18px}
.doc-header-left{display:flex;align-items:center;gap:14px}
.doc-logo{width:54px;height:54px;object-fit:contain}
.doc-company h1{font-size:15px;font-weight:800;color:#002F6C;line-height:1.2}
.doc-company p{font-size:10px;color:#555;margin-top:3px;max-width:280px;line-height:1.45}
.doc-header-right{text-align:right}
.doc-po-label{font-size:9px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
.doc-po-number{font-size:21px;font-weight:900;color:#002F6C;letter-spacing:.5px}
.doc-dates{font-size:9.5px;color:#64748b;margin-top:5px;line-height:1.7}
.doc-dates strong{color:#334155}

/* ── Section title ── */
.section-title{font-size:9.5px;font-weight:800;color:#002F6C;text-transform:uppercase;letter-spacing:.8px;border-bottom:1.5px solid #e2e8f0;padding-bottom:5px;margin-bottom:10px}

/* ── Info grid ── */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.info-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:12px 14px}
.info-row{display:flex;gap:6px;margin-bottom:5px;font-size:11px}
.info-row:last-child{margin-bottom:0}
.info-label{font-weight:700;color:#475569;min-width:96px;flex-shrink:0}
.info-value{color:#1e293b}
.status-badge{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700}

/* ── Audit trail ── */
.audit-box{background:#f0f4ff;border:1px solid #c5d3f0;border-radius:7px;padding:12px 14px;margin-bottom:18px}
.audit-chain{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;margin-top:8px}
.audit-step{background:#fff;border:1px solid #c5d3f0;border-radius:5px;padding:6px 10px;font-size:11px;min-width:0;flex:1}
.audit-step .step-name{font-weight:700;color:#002F6C;display:flex;align-items:center;gap:5px}
.audit-step .step-role{font-size:9.5px;color:#64748b;margin-top:2px;line-height:1.4}
.audit-step.step-admin{background:#f0fdf4;border-color:#86efac}
.audit-step.step-admin .step-name{color:#15803d}
.audit-step.step-supplier{background:#eff6ff;border-color:#93c5fd}
.audit-step.step-supplier .step-name{color:#1d4ed8}
.audit-arrow{color:#94a3b8;font-size:16px;flex-shrink:0}

/* ── Items table ── */
.items-table{width:100%;border-collapse:collapse;margin-bottom:0;font-size:11px}
.items-table thead th{background:#002F6C;color:#fff;padding:9px 11px;text-align:left;font-weight:700;font-size:9.5px;text-transform:uppercase;letter-spacing:.5px}
.items-table thead th.r{text-align:right}
.items-table tbody td{padding:10px 11px;border-bottom:1px solid #e2e8f0;color:#334155;vertical-align:middle}
.items-table tbody td.r{text-align:right}
.items-table tbody tr:last-child td{border-bottom:none}
.items-table tfoot td{padding:8px 11px;font-size:11px;border-top:1px solid #e2e8f0}
.items-table tfoot .subtotal-row td{color:#64748b;font-size:10.5px}
.items-table tfoot .total-row td{background:#002F6C;color:#fff;font-weight:800;font-size:13px;padding:11px 11px}
.sku-code{font-size:10px;color:#64748b;font-family:'Courier New',monospace;background:#f1f5f9;padding:1px 5px;border-radius:3px;display:inline-block;margin-top:2px}

/* ── Notes ── */
.notes-box{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 13px;margin-top:14px;font-size:11px;color:#78350f}

/* ── Signatures ── */
.signatures{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:28px;page-break-inside:avoid}
.sig-box{text-align:center}
.sig-line{border-top:1px solid #334155;margin-top:44px;padding-top:7px}
.sig-name{font-weight:700;font-size:11.5px;color:#1e293b}
.sig-role{font-size:10px;color:#64748b;margin-top:2px}
.sig-date{font-size:9.5px;color:#94a3b8;margin-top:3px}

/* ── Footer ── */
.doc-footer{margin-top:28px;padding-top:12px;border-top:1px solid #e2e8f0;text-align:center;font-size:9px;color:#94a3b8;line-height:1.7}

/* ── Print ── */
@media print{
  *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
  html,body{background:#fff!important;margin:0!important;padding:0!important;width:100%!important;height:auto!important;overflow:visible!important}
  .screen-toolbar{display:none!important}
  .audit-section{display:none!important}
  .page-wrapper{padding:0!important;margin:0!important;display:block!important;width:100%!important}
  .po-document{
    box-shadow:none!important;
    width:100%!important;
    min-height:297mm!important;
    max-height:unset!important;
    padding:8mm 10mm!important;
    overflow:visible!important;
    page-break-after:avoid!important;
    page-break-inside:avoid!important;
    position:relative!important;
    display:flex!important;
    flex-direction:column!important;
  }
  .po-content{display:flex!important;flex-direction:column!important;flex:1!important}
  .po-bottom{margin-top:auto!important;padding-top:10px!important}
  /* Stamp — keep inside bounds */
  .official-stamp{top:8mm!important;right:8mm!important;font-size:13px!important;padding:3px 9px!important;letter-spacing:2px!important}
  /* Watermark */
  .watermark{font-size:80px!important;opacity:1}
  /* Tighten all spacing */
  .doc-header{padding-bottom:6px!important;margin-bottom:8px!important}
  .doc-logo{width:40px!important;height:40px!important}
  .doc-company h1{font-size:13px!important}
  .doc-company p{font-size:9px!important}
  .doc-po-number{font-size:16px!important}
  .doc-dates{font-size:8px!important;margin-top:3px!important;line-height:1.5!important}
  .info-grid{gap:7px!important;margin-bottom:8px!important}
  .info-box{padding:7px 9px!important}
  .info-row{font-size:9.5px!important;margin-bottom:2px!important}
  .info-label{min-width:82px!important}
  .section-title{font-size:8px!important;padding-bottom:3px!important;margin-bottom:6px!important}
  .items-table thead th{padding:5px 7px!important;font-size:8px!important}
  .items-table tbody td{padding:6px 7px!important;font-size:9.5px!important}
  .items-table tfoot td{padding:4px 7px!important;font-size:9.5px!important}
  .items-table tfoot .total-row td{padding:7px 7px!important;font-size:11px!important}
  .sku-code{font-size:8.5px!important}
  .signatures{margin-top:14px!important;gap:12px!important}
  .sig-line{margin-top:28px!important;padding-top:4px!important}
  .sig-name{font-size:9.5px!important}
  .sig-role{font-size:8.5px!important}
  .sig-date{font-size:8px!important}
  .doc-footer{margin-top:10px!important;padding-top:7px!important;font-size:7.5px!important}
  @page{size:A4 portrait;margin:0}
}
</style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     SCREEN TOOLBAR (hidden on print)
     ══════════════════════════════════════════════════════════ -->
<?php if (!isset($_GET['print']) || $_GET['print'] != '1'): ?>
<div class="screen-toolbar">
    <div class="toolbar-left">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Purchase Order &mdash; <?php echo $po_number; ?>
        <span class="status-pill" style="background:<?php echo $status_bg; ?>;color:<?php echo $status_color; ?>;">
            <?php echo $status_label; ?>
        </span>
    </div>
    <div class="toolbar-right">
        <a href="<?php echo $audit_url; ?>" class="btn-toolbar btn-ghost">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            View in Audit Log
        </a>
        <a href="purchase_orders.php" class="btn-toolbar btn-ghost">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            Back to POs
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     PO DOCUMENT
     ══════════════════════════════════════════════════════════ -->
<div class="page-wrapper">
<div class="po-document">

    <!-- Background watermark -->
    <div class="watermark">OFFICIAL</div>

    <!-- Official stamp (top-right, rotated) -->
    <div class="official-stamp">
        &#10003; OFFICIAL
        <small>PURCHASE ORDER</small>
    </div>

    <div class="po-content">

    <!-- ── Document Header ── -->
    <div class="doc-header">
        <div class="doc-header-left">
            <img src="../assets/img/Petron Logo.png" alt="Petron" class="doc-logo"
                 onerror="this.style.display='none'">
            <div class="doc-company">
                <h1>Petron Station Management System</h1>
                <p><?php
                    $parts = array_filter([$station_name, $station_addr]);
                    echo implode(', ', $parts);
                    if ($vat_tin) echo '<br>VAT TIN: ' . $vat_tin;
                ?></p>
            </div>
        </div>
        <div class="doc-header-right">
            <div class="doc-po-label">Purchase Order</div>
            <div class="doc-po-number"><?php echo $po_number; ?></div>
            <div class="doc-dates">
                <strong>Finalized:</strong> <?php echo $finalized_date; ?> <?php echo $finalized_time; ?><br>
                <strong>Printed:</strong> <?php echo $printed_date; ?>
            </div>
        </div>
    </div>

    <!-- ── Order Information + Station Information ── -->
    <div class="info-grid">

        <!-- Order Information -->
        <div class="info-box">
            <div class="section-title">Order Information</div>
            <div class="info-row">
                <span class="info-label">PO Number:</span>
                <span class="info-value"><strong><?php echo $po_number; ?></strong></span>
            </div>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value">
                    <span class="status-badge" style="background:<?php echo $status_bg; ?>;color:<?php echo $status_color; ?>;">
                        <?php echo $status_label; ?>
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Finalized:</span>
                <span class="info-value"><?php echo $finalized_date; ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Finalized By:</span>
                <span class="info-value"><strong><?php echo $admin_name; ?></strong> (Admin)</span>
            </div>
            <?php if (!empty($po['expected_delivery_date'])): ?>
            <div class="info-row">
                <span class="info-label">Expected Delivery:</span>
                <span class="info-value"><?php echo date('F d, Y', strtotime($po['expected_delivery_date'])); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Station Information -->
        <div class="info-box">
            <div class="section-title">Station Information</div>
            <div class="info-row">
                <span class="info-label">Station:</span>
                <span class="info-value"><strong><?php echo $station_name; ?></strong></span>
            </div>
            <?php if ($station_addr): ?>
            <div class="info-row">
                <span class="info-label">Address:</span>
                <span class="info-value"><?php echo $station_addr; ?></span>
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
                <span class="info-value"><strong><?php echo $supplier_name; ?></strong></span>
            </div>
        </div>

    </div><!-- /info-grid -->

    <!-- ── Approval Chain / Audit Trail ── -->
    <div class="audit-section" style="margin-bottom:18px;">
        <div class="section-title">Approval Chain / Audit Trail</div>
        <div class="audit-chain">

            <!-- Step 1: Staff -->
            <div class="audit-step">
                <div class="step-name">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    <?php echo $staff_name; ?>
                </div>
                <div class="step-role">Staff &mdash; Encoded Stock Request</div>
            </div>

            <span class="audit-arrow">&#8594;</span>

            <!-- Step 2: Manager -->
            <div class="audit-step">
                <div class="step-name">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/><polyline points="16 11 18 13 22 9"/></svg>
                    <?php echo $manager_name; ?>
                </div>
                <div class="step-role">Manager &mdash; Validated &amp; Generated Purchase Request</div>
            </div>

            <span class="audit-arrow">&#8594;</span>

            <!-- Step 3: Admin -->
            <div class="audit-step step-admin">
                <div class="step-name">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>
                    <?php echo $admin_name; ?>
                </div>
                <div class="step-role">Admin &mdash; Finalized as Official Purchase Order</div>
            </div>

            <span class="audit-arrow">&#8594;</span>

            <!-- Step 4: Supplier -->
            <div class="audit-step step-supplier">
                <div class="step-name">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <?php echo $supplier_name; ?>
                </div>
                <div class="step-role">Supplier &mdash; Receives &amp; Arranges Delivery</div>
            </div>

        </div>
    </div><!-- /audit trail -->

    <!-- ── Order Details Table ── -->
    <div class="section-title" style="margin-bottom:10px;">Order Details</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th style="width:36%">Product / Item Description</th>
                <th style="width:16%">SKU / Code</th>
                <th class="r" style="width:13%">Quantity</th>
                <th class="r" style="width:15%">Unit Price</th>
                <th class="r" style="width:15%">Total Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="color:#94a3b8;font-size:11px;">1</td>
                <td>
                    <strong><?php echo $product_name; ?></strong>
                    <?php if ($sku): ?>
                        <br><span class="sku-code"><?php echo $sku; ?></span>
                    <?php endif; ?>
                </td>
                <td style="color:#64748b;">
                    <?php echo $sku ? '<span class="sku-code">' . $sku . '</span>' : '<span style="color:#cbd5e1;">—</span>'; ?>
                </td>
                <td class="r"><strong><?php echo number_format($qty, 0); ?></strong></td>
                <td class="r">&#8369;<?php echo number_format($unit_price, 2); ?></td>
                <td class="r"><strong>&#8369;<?php echo number_format($total_amount, 2); ?></strong></td>
            </tr>
        </tbody>
        <tfoot>
            <tr class="subtotal-row">
                <td colspan="5" style="text-align:right;padding-top:10px;">Subtotal:</td>
                <td class="r" style="padding-top:10px;">&#8369;<?php echo number_format($total_amount, 2); ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="5" style="text-align:right;letter-spacing:.3px;">TOTAL ORDER AMOUNT:</td>
                <td class="r">&#8369;<?php echo number_format($total_amount, 2); ?></td>
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

    <!-- ── Signature Lines ── -->
    <div class="po-bottom">
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

    <!-- ── Document Footer ── -->
    <div class="doc-footer">
        <strong>This Purchase Order is official and valid only with authorized signatures.</strong><br>
        Document ID: <?php echo $po_number; ?> &nbsp;&bull;&nbsp;
        Station: <?php echo $station_name; ?> &nbsp;&bull;&nbsp;
        Supplier: <?php echo $supplier_name; ?> &nbsp;&bull;&nbsp;
        Generated: <?php echo $printed_date; ?><br>
        <span style="font-size:8px;">
            Printed by: <?php echo htmlspecialchars($me['name']); ?> (<?php echo ucfirst($role); ?>)
            &nbsp;&bull;&nbsp; This document serves as proof of finalization and approval. Keep a copy for station records and compliance.
        </span>
    </div>
    </div><!-- /po-bottom -->

    </div><!-- /po-content -->
</div><!-- /po-document -->
</div><!-- /page-wrapper -->

<script>
// Auto-print only when opened via the Print button (?print=1)
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 700);
});
// After print dialog closes (Save or Cancel) — go back to Purchase Orders
window.addEventListener('afterprint', function () {
    window.location.href = 'purchase_orders.php';
});
<?php endif; ?>
</script>
</body>
</html>
