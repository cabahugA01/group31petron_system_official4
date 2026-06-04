<?php
/**
 * Admin Inventory Summary partial file
 * Flat-table summary panel for admin inventory oversight.
 */
if (!isset($pdo) || !isset($me) || !isset($station_id)) { return; }

// 1. Pending POs (purchase orders not yet stocked in)
$admin_pending_pos = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = ? AND stock_in_done = 0");
    $s->execute([$station_id]); $admin_pending_pos = (int)$s->fetchColumn();
} catch (Exception $e) {}

// 2. Pending Stock Requests (all roles)
$admin_pending_sr = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
    $s->execute([$station_id]); $admin_pending_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}

// 3. Total Active Products
$admin_active_products = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM inventory_products WHERE status = 'active' AND category != 'Fuel'");
    $s->execute([]); $admin_active_products = (int)$s->fetchColumn();
} catch (Exception $e) {}

// 4. Low Stock Items
$admin_low_stock = 0;
try {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category != 'Fuel' AND ip.status = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) > 0
          AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, 10)
    ");
    $s->execute([$station_id]); $admin_low_stock = (int)$s->fetchColumn();
} catch (Exception $e) {}

// 5. Out of Stock Items
$admin_out_of_stock = 0;
try {
    $s = $pdo->prepare("
        SELECT COUNT(*) FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category != 'Fuel' AND ip.status = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) <= 0
    ");
    $s->execute([$station_id]); $admin_out_of_stock = (int)$s->fetchColumn();
} catch (Exception $e) {}

// 6. Outstanding Utang (customer balances)
$admin_utang = 0.0;
try {
    $s = $pdo->prepare("SELECT SUM(balance) FROM customers WHERE station_id = ?");
    $s->execute([$station_id]); $admin_utang = (float)$s->fetchColumn();
} catch (Exception $e) {}
?>
<div class="card" style="margin-bottom:20px;border-left:4px solid #002F6C;border-radius:8px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.06);">
  <div class="card-hd" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:10px 16px;">
    <div class="card-hd-title" style="font-size:13px;font-weight:700;color:#002F6C;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-chart-line"></i> Admin Inventory Summary
    </div>
  </div>
  <div class="card-body" style="padding:0;overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;text-align:center;font-size:13px;min-width:750px;">
      <thead>
        <tr style="background:#f1f5f9;border-bottom:1px solid #cbd5e1;">
          <th style="padding:10px;color:#475569;font-weight:700;border-right:1px solid #cbd5e1;">Pending POs</th>
          <th style="padding:10px;color:#475569;font-weight:700;border-right:1px solid #cbd5e1;">Pending Stock Requests</th>
          <th style="padding:10px;color:#475569;font-weight:700;border-right:1px solid #cbd5e1;">Active Products</th>
          <th style="padding:10px;color:#475569;font-weight:700;border-right:1px solid #cbd5e1;">Low Stock Items</th>
          <th style="padding:10px;color:#475569;font-weight:700;border-right:1px solid #cbd5e1;">Out of Stock Items</th>
          <th style="padding:10px;color:#475569;font-weight:700;">Outstanding Utang</th>
        </tr>
      </thead>
      <tbody>
        <tr style="font-size:16px;font-weight:800;">
          <td style="padding:12px;color:#002F6C;border-right:1px solid #e2e8f0;"><?= number_format($admin_pending_pos) ?></td>
          <td style="padding:12px;color:#ea580c;border-right:1px solid #e2e8f0;"><?= number_format($admin_pending_sr) ?></td>
          <td style="padding:12px;color:#16a34a;border-right:1px solid #e2e8f0;"><?= number_format($admin_active_products) ?></td>
          <td style="padding:12px;color:#ca8a04;border-right:1px solid #e2e8f0;"><?= number_format($admin_low_stock) ?></td>
          <td style="padding:12px;color:#dc2626;border-right:1px solid #e2e8f0;"><?= number_format($admin_out_of_stock) ?></td>
          <td style="padding:12px;color:#7c3aed;">&#8369;<?= number_format($admin_utang, 2) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
