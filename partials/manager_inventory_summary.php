<?php
/**
 * Manager Inventory Summary partial file
 * Renders a simple, clean, tabular row of operational metrics for older users.
 */
if (!isset($pdo) || !isset($me) || !isset($station_id)) {
    return;
}

$my_id = (int)$me['id'];

// 1. Outstanding POs (purchase orders where stock_in_done = 0)
$outstanding_pos = 0;
try {
    $s_po = $pdo->prepare("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = ? AND stock_in_done = 0");
    $s_po->execute([$station_id]);
    $outstanding_pos = (int)$s_po->fetchColumn();
} catch (Exception $e) {}

// 2. Pending Stock Requests
$pending_sr = 0;
try {
    $s_sr = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");
    $s_sr->execute([$station_id]);
    $pending_sr = (int)$s_sr->fetchColumn();
} catch (Exception $e) {}
try {
    $s_srf = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE status = 'Pending'");
    $s_srf->execute([]);
    $pending_sr += (int)$s_srf->fetchColumn();
} catch (Exception $e) {}

// 3. Total Active Products
$active_products = 0;
try {
    $s_ap = $pdo->prepare("SELECT COUNT(*) FROM inventory_products WHERE status = 'active' AND category != 'Fuel'");
    $s_ap->execute([]);
    $active_products = (int)$s_ap->fetchColumn();
} catch (Exception $e) {}

// 4. Total Low Stock Items
$low_stock = 0;
try {
    $s_ls = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category != 'Fuel' AND ip.status = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) > 0 
          AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, 10)
    ");
    $s_ls->execute([$station_id]);
    $low_stock = (int)$s_ls->fetchColumn();
} catch (Exception $e) {}

// 5. Total Out of Stock Items
$out_of_stock = 0;
try {
    $s_oos = $pdo->prepare("
        SELECT COUNT(*) 
        FROM inventory_products ip
        LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
        WHERE ip.category != 'Fuel' AND ip.status = 'active'
          AND COALESCE(si.stock_level, ip.stock, 0) <= 0
    ");
    $s_oos->execute([$station_id]);
    $out_of_stock = (int)$s_oos->fetchColumn();
} catch (Exception $e) {}
?>
<div class="card" style="margin-bottom: 20px; border-left: 4px solid #002F6C; border-radius: 8px; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.06);">
  <div class="card-hd" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 10px 16px;">
    <div class="card-hd-title" style="font-size: 13px; font-weight: 700; color: #002F6C; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
      <i class="fas fa-chart-line"></i> Manager Summary Panel
    </div>
  </div>
  <div class="card-body" style="padding: 0; overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; text-align: center; font-size: 13px; min-width: 700px;">
      <thead>
        <tr style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1;">
          <th style="padding: 10px; color: #475569; font-weight: 700; border-right: 1px solid #cbd5e1;">Outstanding POs</th>
          <th style="padding: 10px; color: #475569; font-weight: 700; border-right: 1px solid #cbd5e1;">Pending Stock Requests</th>
          <th style="padding: 10px; color: #475569; font-weight: 700; border-right: 1px solid #cbd5e1;">Total Active Products</th>
          <th style="padding: 10px; color: #475569; font-weight: 700; border-right: 1px solid #cbd5e1;">Total Low Stock Items</th>
          <th style="padding: 10px; color: #475569; font-weight: 700;">Total Out of Stock Items</th>
        </tr>
      </thead>
      <tbody>
        <tr style="font-size: 16px; font-weight: 800;">
          <td style="padding: 12px; color: #002F6C; border-right: 1px solid #e2e8f0;"><?= number_format($outstanding_pos) ?></td>
          <td style="padding: 12px; color: #ea580c; border-right: 1px solid #e2e8f0;"><?= number_format($pending_sr) ?></td>
          <td style="padding: 12px; color: #16a34a; border-right: 1px solid #e2e8f0;"><?= number_format($active_products) ?></td>
          <td style="padding: 12px; color: #ca8a04; border-right: 1px solid #e2e8f0;"><?= number_format($low_stock) ?></td>
          <td style="padding: 12px; color: #dc2626;"><?= number_format($out_of_stock) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
