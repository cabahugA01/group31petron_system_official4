<?php
/**
 * Supplier Report — deliveries, payables linked to purchase orders
 */
$suppliers = [];
try {  $q = $pdo->prepare("SELECT s.*, COUNT(po.id) AS po_count,  COALESCE(SUM(CASE WHEN po.status NOT IN ('Received','Cancelled','Rejected by Admin') THEN po.total_amount ELSE 0 END),0) AS open_payable,  COALESCE(SUM(po.total_amount),0) AS total_ordered  FROM suppliers s  LEFT JOIN purchase_orders po ON po.supplier_id=s.id AND po.station_id=?  GROUP BY s.id ORDER BY open_payable DESC");  $q->execute([$station_id]);  $suppliers = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Deliveries in period (fuel)
$fuel_delivs = [];
try {  $q2 = $pdo->prepare("SELECT fd.*, s.name AS supplier_name  FROM fuel_deliveries fd LEFT JOIN suppliers s ON fd.supplier=s.name  WHERE fd.station_id=? AND fd.delivery_date BETWEEN ? AND ?  ORDER BY fd.delivery_date DESC LIMIT 50");  $q2->execute([$station_id, $date_start, $date_end]);  $fuel_delivs = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// POs in period
$pos = [];
try {  $q3 = $pdo->prepare("SELECT po.*, s.name AS supplier_name_rel  FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id=s.id  WHERE po.station_id=? AND DATE(po.created_at) BETWEEN ? AND ?  ORDER BY po.created_at DESC LIMIT 50");  $q3->execute([$station_id, $date_start, $date_end]);  $pos = $q3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$total_open = array_sum(array_column($suppliers,'open_payable'));
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">  <div>  <h2 style="margin:0 0 4px;color:#003366;font-size:22px;"><i class="fas fa-truck-loading"></i> Supplier Report</h2>  <p style="margin:0;color:#666;font-size:13px;">Deliveries, purchase orders, and outstanding payables</p>  </div>  <button onclick="window.print()" style="background:#003366;color:#fff;border:none;padding:9px 18px;border-radius:6px;font-weight:600;cursor:pointer;"><i class="fas fa-print"></i> Print</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:16px;margin-bottom:28px;">  <div style="background:linear-gradient(135deg,#003366,#004d99);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Total Suppliers</div>  <div style="font-size:26px;font-weight:700;"><?= count($suppliers) ?></div>  </div>  <div style="background:linear-gradient(135deg,#dc3545,#c82333);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Open Payables</div>  <div style="font-size:22px;font-weight:700;">₱<?= number_format($total_open,2) ?></div>  <div style="font-size:11px;opacity:.75;">Unpaid POs</div>  </div>  <div style="background:linear-gradient(135deg,#1a7c40,#228b22);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Fuel Deliveries</div>  <div style="font-size:26px;font-weight:700;"><?= count($fuel_delivs) ?></div>  <div style="font-size:11px;opacity:.75;">This period</div>  </div>  <div style="background:linear-gradient(135deg,#c55a00,#e06c00);padding:18px;border-radius:10px;color:#fff;">  <div style="font-size:12px;opacity:.85;">Purchase Orders</div>  <div style="font-size:26px;font-weight:700;"><?= count($pos) ?></div>  <div style="font-size:11px;opacity:.75;">This period</div>  </div>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-building"></i> Supplier Accounts</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">  <thead><tr><th>Supplier</th><th>Contact</th><th>Email</th><th>PO Count</th><th>Total Ordered</th><th>Open Payable</th></tr></thead>  <tbody>  <?php if(empty($suppliers)): ?>  <tr><td colspan="6" style="text-align:center;color:#999;padding:40px;">No suppliers found.</td></tr>  <?php else: foreach($suppliers as $s): ?>  <tr>  <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>  <td><?= htmlspecialchars($s['contact_person']??$s['phone']??'—') ?></td>  <td><?= htmlspecialchars($s['email']??'—') ?></td>  <td><?= $s['po_count'] ?></td>  <td>₱<?= number_format($s['total_ordered'],2) ?></td>  <td style="color:<?= $s['open_payable']>0?'#dc3545':'#28a745' ?>;font-weight:700;">₱<?= number_format($s['open_payable'],2) ?></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-gas-pump"></i> Fuel Deliveries (<?= $date_start ?> to <?= $date_end ?>)</h3>
<div style="overflow-x:auto;margin-bottom:28px;">
<table class="report-table">  <thead><tr><th>Date</th><th>Fuel Type</th><th>Supplier</th><th>Liters</th><th>Invoice</th><th>Status</th></tr></thead>  <tbody>  <?php if(empty($fuel_delivs)): ?>  <tr><td colspan="6" style="text-align:center;color:#999;padding:30px;">No fuel deliveries in period.</td></tr>  <?php else: foreach($fuel_delivs as $d): ?>  <tr>  <td><?= date('M j, Y', strtotime($d['delivery_date'])) ?></td>  <td><?= htmlspecialchars($d['fuel_type']) ?></td>  <td><?= htmlspecialchars($d['supplier']) ?></td>  <td><?= number_format($d['delivery_liters'],2) ?> L</td>  <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($d['invoice_no']??'—') ?></td>  <td><?= htmlspecialchars($d['status']??'—') ?></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>

<h3 style="color:#003366;margin-bottom:12px;"><i class="fas fa-file-invoice"></i> Purchase Orders (<?= $date_start ?> to <?= $date_end ?>)</h3>
<div style="overflow-x:auto;">
<table class="report-table">  <thead><tr><th>PO #</th><th>Date</th><th>Product</th><th>Supplier</th><th>Qty</th><th>Amount</th><th>Status</th></tr></thead>  <tbody>  <?php if(empty($pos)): ?>  <tr><td colspan="7" style="text-align:center;color:#999;padding:30px;">No purchase orders in period.</td></tr>  <?php else: foreach($pos as $po):  $sc = in_array($po['status'],['Received','Admin Finalized']) ? '#28a745' : (in_array($po['status'],['Cancelled','Rejected by Admin']) ? '#dc3545' : '#fd7e14');  ?>  <tr>  <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($po['po_number']??'PO-'.$po['id']) ?></td>  <td><?= date('M j, Y', strtotime($po['created_at'])) ?></td>  <td><?= htmlspecialchars($po['product_name']) ?></td>  <td><?= htmlspecialchars($po['supplier_name_rel']??$po['supplier_name']??'—') ?></td>  <td><?= number_format($po['quantity'],2) ?></td>  <td>₱<?= number_format($po['total_amount'],2) ?></td>  <td><span style="color:<?=$sc?>;font-weight:700;font-size:12px;"><?= htmlspecialchars($po['status']) ?></span></td>  </tr>  <?php endforeach; endif; ?>  </tbody>
</table>
</div>
