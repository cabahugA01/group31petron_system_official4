<?php
/**
 * Official Purchase Order — Final Admin View
 * print_po_new.php
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me  = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['admin', 'superadmin'])) {  http_response_code(403);  die('<p style="font-family:Arial;padding:40px;color:#721c24;">Access denied. Admin privileges required.</p>');
}

$po_id  = (int)($_GET['id'] ?? 0);
$po_date  = $_GET['date']  ?? null;
$batch_id = trim($_GET['batch_id'] ?? '');
$po_type  = $_GET['type']  ?? 'merch'; // 'fuel' or 'merch'

if (!$po_id && !$po_date && $batch_id === '') {  die('<p style="font-family:Arial;padding:40px;">No Purchase Order ID, Date, or Batch ID provided.</p>');
}

$station_id = (int)user_station_id();
$po_items  = [];

// ── Fetch PO with all related data ────────────────────────────────────────
try {  // Helper select columns for sup and st  // NOTE: stations table has no 'contact' column; fall back to NULL  $select_fields = "  st.name AS station_name,  st.location AS station_location,  st.address AS station_address,  st.vat_tin AS station_vat_tin,  NULL AS station_contact,  sup.name AS supplier_name,  sup.contact_person AS supplier_contact_person,  sup.phone AS supplier_phone,  sup.email AS supplier_email,  sup.address AS supplier_address  ";  if ($batch_id !== '') {  if ($po_type === 'fuel') {  $stmt = $pdo->prepare("  SELECT fpo.*,  fpo.volume AS quantity,  fpo.notes AS sr_manager_notes,  ft.name AS product_name,  'Fuel' AS product_category,  u.name AS created_by_name,  ab.name AS approved_by_name,  NULL AS staff_id,  NULL AS item_sku,  NULL AS sr_requested_qty,  NULL AS sr_approved_qty,  u.name AS staff_name,  NULL AS manager_name,  NULL AS request_id,  fpo.approved_at AS approved_at,  $select_fields  FROM fuel_purchase_orders fpo  LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id  LEFT JOIN stations st ON fpo.station_id = st.id  LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id  LEFT JOIN users u ON fpo.created_by = u.id  LEFT JOIN users ab ON fpo.approved_by = ab.id  WHERE fpo.batch_id = ?  ORDER BY fpo.id ASC  ");  $stmt->execute([$batch_id]);  } else {  $stmt = $pdo->prepare("  SELECT po.*,  u.name AS created_by_name,  ab.name AS approved_by_name,  sr.staff_id,  sr.item_sku,  sr.requested_quantity AS sr_requested_qty,  sr.approved_quantity  AS sr_approved_qty,  sr.manager_notes  AS sr_manager_notes,  staff_u.name  AS staff_name,  mgr_u.name  AS manager_name,  COALESCE(ip.category, 'Lubricant') AS product_category,  $select_fields  FROM purchase_orders po  LEFT JOIN stations st ON po.station_id = st.id  LEFT JOIN suppliers sup ON po.supplier_id = sup.id  LEFT JOIN users u ON po.created_by = u.id  LEFT JOIN users ab ON po.approved_by = ab.id  LEFT JOIN stock_requests sr ON po.request_id = sr.id  LEFT JOIN users staff_u ON sr.staff_id = staff_u.id  LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id  LEFT JOIN inventory_products ip ON (sr.item_sku = ip.sku OR po.product_name = ip.product_name)  WHERE po.batch_id = ?  ORDER BY po.id ASC  ");  $stmt->execute([$batch_id]);  }  $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);  $po = $po_items[0] ?? false;  if ($po) { $po['po_number'] = $batch_id; }  }  elseif ($po_date) {  if ($po_type === 'fuel') {  $stmt = $pdo->prepare("  SELECT fpo.*,  fpo.volume AS quantity,  fpo.notes AS sr_manager_notes,  ft.name AS product_name,  'Fuel' AS product_category,  u.name AS created_by_name,  ab.name AS approved_by_name,  NULL AS staff_id,  NULL AS item_sku,  NULL AS sr_requested_qty,  NULL AS sr_approved_qty,  u.name AS staff_name,  NULL AS manager_name,  NULL AS request_id,  fpo.approved_at AS approved_at,  $select_fields  FROM fuel_purchase_orders fpo  LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id  LEFT JOIN stations st ON fpo.station_id = st.id  LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id  LEFT JOIN users u ON fpo.created_by = u.id  LEFT JOIN users ab ON fpo.approved_by = ab.id  WHERE fpo.station_id = ? AND DATE(fpo.created_at) = ?  ORDER BY fpo.id ASC  ");  $stmt->execute([$station_id, $po_date]);  } else {  $stmt = $pdo->prepare("  SELECT po.*,  u.name AS created_by_name,  ab.name AS approved_by_name,  sr.staff_id,  sr.item_sku,  sr.requested_quantity AS sr_requested_qty,  sr.approved_quantity  AS sr_approved_qty,  sr.manager_notes  AS sr_manager_notes,  staff_u.name  AS staff_name,  mgr_u.name  AS manager_name,  COALESCE(ip.category, 'Lubricant') AS product_category,  $select_fields  FROM purchase_orders po  LEFT JOIN stations st ON po.station_id = st.id  LEFT JOIN suppliers sup ON po.supplier_id = sup.id  LEFT JOIN users u ON po.created_by = u.id  LEFT JOIN users ab ON po.approved_by = ab.id  LEFT JOIN stock_requests sr ON po.request_id = sr.id  LEFT JOIN users staff_u ON sr.staff_id = staff_u.id  LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id  LEFT JOIN inventory_products ip ON (sr.item_sku = ip.sku OR po.product_name = ip.product_name)  WHERE po.station_id = ? AND DATE(po.created_at) = ? AND po.type = 'merch'  ORDER BY po.id ASC  ");  $stmt->execute([$station_id, $po_date]);  }  $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);  if ($po_items) {  $po = $po_items[0];  $found_batch = $po_items[0]['batch_id'] ?? '';  if (empty($found_batch)) {  $date_tag = date('Ymd', strtotime($po_date));  $prefix  = $po_type === 'fuel' ? 'POF-' : 'POM-';  $found_batch = $prefix . $date_tag . '-BATCH';  }  $po['po_number'] = $found_batch;  } else {  $po = false;  }  }  else {  if ($po_type === 'fuel') {  $stmt = $pdo->prepare("  SELECT fpo.*,  fpo.volume AS quantity,  fpo.notes AS sr_manager_notes,  ft.name AS product_name,  'Fuel' AS product_category,  u.name AS created_by_name,  ab.name AS approved_by_name,  NULL AS staff_id,  NULL AS item_sku,  NULL AS sr_requested_qty,  NULL AS sr_approved_qty,  u.name AS staff_name,  NULL AS manager_name,  NULL AS request_id,  fpo.approved_at AS approved_at,  $select_fields  FROM fuel_purchase_orders fpo  LEFT JOIN fuel_types ft ON fpo.fuel_type_id = ft.id  LEFT JOIN stations st ON fpo.station_id = st.id  LEFT JOIN suppliers sup ON fpo.supplier_id = sup.id  LEFT JOIN users u ON fpo.created_by = u.id  LEFT JOIN users ab ON fpo.approved_by = ab.id  WHERE fpo.id = ?  LIMIT 1  ");  } else {  $stmt = $pdo->prepare("  SELECT po.*,  u.name AS created_by_name,  ab.name AS approved_by_name,  sr.staff_id,  sr.item_sku,  sr.requested_quantity AS sr_requested_qty,  sr.approved_quantity  AS sr_approved_qty,  sr.manager_notes  AS sr_manager_notes,  staff_u.name  AS staff_name,  mgr_u.name  AS manager_name,  COALESCE(ip.category, 'Lubricant') AS product_category,  $select_fields  FROM purchase_orders po  LEFT JOIN stations st ON po.station_id = st.id  LEFT JOIN suppliers sup ON po.supplier_id = sup.id  LEFT JOIN users u ON po.created_by = u.id  LEFT JOIN users ab ON po.approved_by = ab.id  LEFT JOIN stock_requests sr ON po.request_id = sr.id  LEFT JOIN users staff_u ON sr.staff_id = staff_u.id  LEFT JOIN users mgr_u ON sr.manager_id = mgr_u.id  LEFT JOIN inventory_products ip ON (sr.item_sku = ip.sku OR po.product_name = ip.product_name)  WHERE po.id = ?  LIMIT 1  ");  }  $stmt->execute([$po_id]);  $po = $stmt->fetch(PDO::FETCH_ASSOC);  if ($po) {  $po_items = [$po];  } else {  $po_items = [];  }  }
} catch (Exception $e) {  die('<p style="font-family:Arial;padding:40px;">Database error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

if (!$po) {  die('<p style="font-family:Arial;padding:40px;">Purchase Order not found.</p>');
}

// Block printing only when record is explicitly rejected/cancelled.
$blocked_statuses = ['Rejected', 'rejected', 'Rejected by Admin', 'Cancelled', 'cancelled', 'Draft', 'draft'];
if ($po_id && !$batch_id && !$po_date && in_array($po['status'] ?? '', $blocked_statuses)) {  die('<p style="font-family:Arial;padding:40px;color:#856404;">This PO has been rejected or cancelled and cannot be printed.</p>');
}

// Log print action
try {  $po_label = $po_type === 'fuel' ? 'Fuel PO' : 'Purchase Order';  log_activity(  $pdo,  $me['id'],  'Print Purchase Order',  "{$po_label} {$po['po_number']} printed by {$me['name']} (Admin)."  );
} catch (Exception $e) {}

// Gather values
$is_fuel  = ($po_type === 'fuel');
$qty_unit  = $is_fuel ? 'L' : 'pcs';
$finalized_dt  = (!empty($po['approved_at']) ? $po['approved_at'] : null) ?? $po['admin_finalized_at'] ?? $po['created_at'];
$finalized_date = date('F d, Y', strtotime($finalized_dt));
$finalized_time = date('g:i A', strtotime($finalized_dt));
$purchase_date  = date('F d, Y', strtotime($po['created_at']));
$printed_date  = date('F d, Y g:i A');
$po_number  = htmlspecialchars($po['po_number']);
$admin_name  = htmlspecialchars($po['approved_by_name'] ?? $me['name'] ?? '—');

// Expected Delivery Date parsing
$expected_delivery_date = 'N/A';
foreach ($po_items as $item) {  $d = $item['expected_delivery_date'] ?? $item['expected_delivery'] ?? '';  if (!empty($d) && $d !== '0000-00-00') {  $expected_delivery_date = date('F d, Y', strtotime($d));  break;  }
}

// Parse all structured notes fields
$raw_notes = $po['admin_notes'] ?? $po['notes'] ?? $po['remarks'] ?? '';

$expected_delivery_time = '09:00 AM';
if (preg_match('/Expected Time:\s*(.+)/im', $raw_notes, $m))  { $expected_delivery_time = trim($m[1]); }
elseif (preg_match('/Expected Delivery Time:\s*([0-9:]+\s*[A-Z]{2})/i', $raw_notes, $m)) { $expected_delivery_time = trim($m[1]); }

$parsed_receiving = 'Any Assigned Staff';
if (preg_match('/Receiving Personnel:\s*(.+)/im', $raw_notes, $m)) { $parsed_receiving = trim($m[1]); }

$parsed_payment = '30 Days';
if (preg_match('/Payment Terms:\s*(.+)/im', $raw_notes, $m)) { $parsed_payment = trim($m[1]); }

$parsed_instructions = 'Deliver all items in one shipment.';
if (preg_match('/Instructions:\s*(.+)/im', $raw_notes, $m)) { $parsed_instructions = trim($m[1]); }

$parsed_remarks = 'None';
if (preg_match('/Remarks:\s*(.+)/im', $raw_notes, $m) && trim($m[1]) !== '') { $parsed_remarks = trim($m[1]); }

// Supplier Info
$supplier_name  = htmlspecialchars($po['supplier_name'] ?? 'Petron Corporation');
$sup_contact  = htmlspecialchars($po['supplier_contact_person'] ?? 'Account Manager');
$sup_phone  = htmlspecialchars($po['supplier_phone'] ?? '(02) 8884-9200');
$sup_email  = htmlspecialchars($po['supplier_email'] ?? 'sales@petron.com');
$sup_addr  = htmlspecialchars($po['supplier_address'] ?? 'San Miguel Corp. Head Office Complex, 40 San Miguel Ave, Mandaluyong City');

// Station Info — prefer address column, then location, then CDO default
$station_name  = htmlspecialchars($po['station_name'] ?? 'Petron Carmen');
$raw_addr  = trim($po['station_address'] ?? '');
$raw_loc  = trim($po['station_location'] ?? '');
if (empty($raw_addr) && !empty($raw_loc) && $raw_loc !== 'CDO') {  $raw_addr = $raw_loc;
} elseif (empty($raw_addr)) {  $raw_addr = 'Vamenta Blvd., Carmen, City of Cagayan de Oro, Misamis Oriental';
}
$station_addr  = htmlspecialchars($raw_addr);
$station_phone = htmlspecialchars($po['station_contact'] ?? 'N/A');
$vat_tin  = htmlspecialchars($po['station_vat_tin'] ?? '—');

// Audit trail URL
$audit_url = 'activity_logs.php?module=' . urlencode('Purchase Order') . '&start=' . date('Y-m-01') . '&end=' . date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Order — <?php echo $po_number; ?></title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;font-size:12px;color:#333;background:#fff;padding:25px;line-height:1.4}
.po-document{width:100%;max-width:850px;margin:0 auto;border:1px solid #ddd;padding:30px;position:relative;background:#fff;box-shadow:0 0 10px rgba(0,0,0,0.05);}
.header-box{display:flex;justify-content:space-between;align-items:center;position:relative;padding-bottom:12px;}
.divider-double{border-top:3px double #333;margin:15px 0;}
.divider-single{border-top:1px dashed #ccc;margin:15px 0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:15px;}
.info-block{border:1px solid #e2e8f0;border-radius:6px;padding:12px;background:#f8fafc;}
.info-block h3{font-size:13px;font-weight:bold;color:#0f172a;border-bottom:1px solid #cbd5e1;padding-bottom:4px;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.5px;}
.info-row{margin-bottom:4px;display:flex;}
.info-row strong{width:150px;color:#475569;display:inline-block;}
.info-row span{color:#0f172a;flex:1;}
.items-table{width:100%;border-collapse:collapse;margin:15px 0;}
.items-table th{background:#002F6C;color:#fff;padding:8px 10px;font-weight:600;text-align:left;font-size:11px;text-transform:uppercase;}
.items-table td{padding:8px 10px;border-bottom:1px solid #e2e8f0;color:#0f172a;}
.items-table th.r, .items-table td.r{text-align:right;}
.signatures-box{display:grid;grid-template-columns:1fr 1fr 1fr;gap:25px;margin-top:40px;}
.sig-col{text-align:center;}
.sig-line{border-top:1px solid #475569;width:80%;margin:45px auto 4px auto;}
.btn-print-bar{max-width:850px;margin:0 auto 15px auto;display:flex;justify-content:flex-end;gap:10px;}
.btn-print{font-family:sans-serif;font-size:12px;padding:8px 16px;background:#002F6C;color:#fff;border:none;border-radius:4px;cursor:pointer;text-decoration:none;font-weight:600;}
.btn-print:hover{background:#0b448a;}
.btn-back{background:#fff;color:#333;border:1px solid #ccc;}
.btn-back:hover{background:#f5f5f5;}

/* Rotated official stamp */
.official-stamp {  position: absolute;  top: 5px;  right: 230px;  border: 3px solid #2e7d32;  color: #2e7d32;  padding: 2px 10px;  font-size: 14px;  font-weight: bold;  text-transform: uppercase;  transform: rotate(-10deg);  border-radius: 4px;  background: #fff;  font-family: 'Segoe UI', Arial, sans-serif;  letter-spacing: 1px;  z-index: 10;  pointer-events: none;  text-align: center;  line-height: 1.1;
}
.official-stamp small {  display: block;  font-size: 7px;  font-weight: bold;  letter-spacing: 0.5px;
}

@media print{  .btn-print-bar{display:none;}  body{padding:0;background:#fff;}  .po-document{border:none;padding:0;box-shadow:none;}
}
</style>
</head>
<body>

<div class="btn-print-bar">  <button onclick="window.print()" class="btn-print">Print Purchase Order</button>
</div>

<div class="po-document">  <!-- ── Document Header ── -->  <div class="header-box">  <!-- Left Side: Logo & Station Address -->  <div style="display:flex; align-items:center; gap:15px; max-width:65%; text-align:left;">  <?php  $logo_url = '../' . get_system_logo_url($station_id);  ?>  <img src="<?php echo $logo_url; ?>" alt="Petron Logo" style="width:65px; height:65px; object-fit:contain;" onerror="this.src='../assets/img/Petron Logo.png'">  <div>  <h1 style="font-family:'Segoe UI', Arial, sans-serif; font-size:17px; font-weight:800; color:#002F6C; margin:0; line-height:1.2;">  Petron Station Management System  </h1>  <p style="font-size:10px; color:#333; margin-top:4px; font-weight:600;">  <?php echo htmlspecialchars($station_name); ?>  </p>  <p style="font-size:9.5px; color:#666; margin-top:2px; text-transform:uppercase; line-height:1.3;">  <?php echo htmlspecialchars($station_addr); ?>  </p>  <p style="font-size:9px; color:#666; margin-top:1px;">  Contact: <?php echo htmlspecialchars($station_phone); ?>  </p>  </div>  </div>  <!-- Right Side: PO Number & Dates -->  <div style="text-align:right; position:relative; min-width:30%;">  <!-- Official stamp rotated -->  <div class="official-stamp">  <small>PURCHASE ORDER</small>  OFFICIAL  <small>PURCHASE ORDER</small>  </div>  <div style="font-size:20px; font-weight:900; color:#002F6C; letter-spacing:-0.5px; font-family:'Courier New', Courier, monospace;">  <?php echo $po_number; ?>  </div>  <div style="font-size:9.5px; color:#555; margin-top:4px; line-height:1.4;">  <strong>Finalized:</strong> <?php echo $finalized_date; ?> <?php echo $finalized_time; ?><br>  <strong>Printed:</strong> <?php echo $printed_date; ?>  </div>  </div>  </div>  <!-- Solid thick blue divider line underneath header -->  <div style="border-top: 4px solid #002F6C; margin-bottom: 20px; width: 100%;"></div>  <!-- Info Sections in 2 Columns Grid -->  <div class="info-grid">  <!-- Purchase Order Information -->  <div class="info-block">  <h3>Purchase Order Information</h3>  <div class="info-row"><strong>Purchase Order No.</strong> <span><?php echo $po_number; ?></span></div>  <div class="info-row"><strong>Request Batch ID</strong> <span><?php echo $po_number; ?></span></div>  <div class="info-row"><strong>Purchase Date</strong> <span><?php echo $purchase_date; ?></span></div>  <div class="info-row"><strong>Finalized Date</strong> <span><?php echo $finalized_date; ?></span></div>  <div class="info-row"><strong>Status</strong> <span style="font-weight:600; color:#16a34a;">Approved / Pending Delivery</span></div>  </div>  <!-- Station Information -->  <div class="info-block">  <h3>Station Information</h3>  <div class="info-row"><strong>Station Name</strong> <span><?php echo $station_name; ?></span></div>  <div class="info-row"><strong>Branch Address</strong> <span><?php echo $station_addr; ?></span></div>  <div class="info-row"><strong>Prepared By</strong> <span><?php echo $admin_name; ?></span></div>  </div>  </div>  <!-- Supplier & Delivery Information -->  <div class="info-block" style="margin-bottom:15px;">  <h3>Supplier &amp; Delivery Information</h3>  <div class="info-grid" style="grid-template-columns:1fr 1fr; gap:10px 30px; margin-bottom:0; background:none; padding:0; border:none;">  <div>  <div class="info-row"><strong>Supplier</strong> <span><?php echo $supplier_name; ?></span></div>  <div class="info-row"><strong>Expected Delivery</strong> <span><?php echo $expected_delivery_date; ?></span></div>  <div class="info-row"><strong>Expected Time</strong> <span><?php echo htmlspecialchars($expected_delivery_time); ?></span></div>  <div class="info-row"><strong>Payment Terms</strong> <span><?php echo htmlspecialchars($parsed_payment); ?></span></div>  </div>  <div>  <div class="info-row"><strong>Delivery Location</strong> <span><?php echo $station_addr; ?></span></div>  <div class="info-row"><strong>Receiving Personnel</strong> <span><?php echo htmlspecialchars($parsed_receiving); ?></span></div>  <div class="info-row"><strong>Instructions</strong> <span><?php echo nl2br(htmlspecialchars($parsed_instructions)); ?></span></div>  <div class="info-row"><strong>Remarks</strong> <span><?php echo htmlspecialchars($parsed_remarks); ?></span></div>  </div>  </div>  </div>  <!-- Order Details -->  <div>  <h3 style="font-size:12px; font-weight:bold; color:#0f172a; text-transform:uppercase; margin-bottom:6px;">Order Details</h3>  <table class="items-table">  <thead>  <tr>  <th style="width:5%;">#</th>  <th style="width:12%;">SKU</th>  <th>Product Name</th>  <th style="width:15%;">Category</th>  <th class="r" style="width:10%;">Quantity</th>  <th style="width:8%;">Unit</th>  <th class="r" style="width:14%;">Unit Price</th>  <th class="r" style="width:16%;">Total Amount</th>  </tr>  </thead>  <tbody>  <?php  $subtotal = 0;  foreach ($po_items as $idx => $item):  $qty = (float)($item['quantity'] ?? 0);  $price = (float)($item['unit_price'] ?? 0);  $total = $qty * $price;  $subtotal += $total;  ?>  <tr>  <td><?php echo $idx + 1; ?></td>  <td><code style="font-weight:bold; font-size:11px;"><?php echo htmlspecialchars($item['item_sku'] ?? ($is_fuel ? 'FUEL-PO' : 'N/A')); ?></code></td>  <td><?php echo htmlspecialchars($item['product_name'] ?? '—'); ?></td>  <td><?php echo htmlspecialchars($item['product_category'] ?? 'Lubricant'); ?></td>  <td class="r"><?php echo number_format($qty, $is_fuel ? 2 : 0); ?></td>  <td><?php echo $qty_unit; ?></td>  <td class="r">₱<?php echo number_format($price, 2); ?></td>  <td class="r">₱<?php echo number_format($total, 2); ?></td>  </tr>  <?php endforeach; ?>  </tbody>  </table>  </div>  <!-- Order Summary -->  <div style="display:flex; justify-content:flex-end; margin-top:10px;">  <div style="width:300px; line-height:1.6; border:1px solid #e2e8f0; border-radius:6px; padding:10px; background:#f8fafc;">  <div style="display:flex; justify-content:space-between;"><span>Subtotal</span> <span style="font-weight:600;">₱<?php echo number_format($subtotal, 2); ?></span></div>  <div style="display:flex; justify-content:space-between;"><span>Discount</span> <span style="font-weight:600;">₱0.00</span></div>  <div style="display:flex; justify-content:space-between;"><span>VAT (12% Included)</span> <span style="font-weight:600;">₱<?php echo number_format($subtotal * 0.12, 2); ?></span></div>  <div style="display:flex; justify-content:space-between; font-weight:bold; border-top:1px solid #cbd5e1; margin-top:6px; padding-top:6px; font-size:13px; color:#002F6C;">  <span>Grand Total</span> <span>₱<?php echo number_format($subtotal, 2); ?></span>  </div>  </div>  </div>  <div class="divider-single" style="margin-top:20px;"></div>  <!-- Signature Section -->  <div class="signatures-box">  <div class="sig-col">  <div class="sig-line"></div>  <strong>Prepared By (Admin)</strong>  <div style="font-size:11px; color:#475569; margin-top:2px;"><?php echo $admin_name; ?></div>  </div>  <div class="sig-col">  <div class="sig-line"></div>  <strong>Supplier Representative</strong>  <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>  </div>  <div class="sig-col">  <div class="sig-line"></div>  <strong>Received By</strong>  <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Signature over Printed Name / Date</div>  </div>  </div>  <div class="divider-double" style="margin-bottom:8px; margin-top:30px;"></div>  <!-- Footer -->  <div style="display:flex; justify-content:space-between; font-size:10px; color:#64748b;">  <div>Generated by Petron Station Management System</div>  <div>Printed by: <?php echo $admin_name; ?></div>  <div>Date Printed: <?php echo $printed_date; ?></div>  <div>Page 1 of 1</div>  </div>  </div>

<script>
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load', function () {  setTimeout(function () { window.print(); }, 500);
});
<?php endif; ?>
</script>
</body>
</html>
