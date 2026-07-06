<?php
/**  * Admin Inventory Summary partial file  * Clean, modern card design matching staff/manager style  */
if (!isset($pdo) || !isset($me) || !isset($station_id)) { return; }  // 1. Pending POs (purchase orders not yet stocked in)
$admin_pending_pos = 0;
try {  $s = $pdo->prepare("SELECT COUNT(DISTINCT po_number) FROM purchase_orders WHERE station_id = ? AND stock_in_done = 0");  $s->execute([$station_id]); $admin_pending_pos = (int)$s->fetchColumn();
} catch (Exception $e) {}  // 2. Pending Stock Requests (all roles)
$admin_pending_sr = 0;
try {  $s = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND status = 'Pending'");  $s->execute([$station_id]); $admin_pending_sr = (int)$s->fetchColumn();
} catch (Exception $e) {}  // 3. Total Active Products
$admin_active_products = 0;
try {  $s = $pdo->prepare("SELECT COUNT(*) FROM inventory_products WHERE status = 'Active' AND category != 'Fuel'");  $s->execute([]); $admin_active_products = (int)$s->fetchColumn();
} catch (Exception $e) {}  // 4. Low Stock Items
$admin_low_stock = 0;
try {  $s = $pdo->prepare("  SELECT COUNT(*) FROM inventory_products ip  LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?  WHERE ip.category != 'Fuel' AND ip.status = 'Active'  AND COALESCE(si.stock_level, ip.stock, 0) > 0  AND COALESCE(si.stock_level, ip.stock, 0) <= COALESCE(si.reorder_level, 10)  ");  $s->execute([$station_id]); $admin_low_stock = (int)$s->fetchColumn();
} catch (Exception $e) {}  // 5. Out of Stock Items
$admin_out_of_stock = 0;
try {  $s = $pdo->prepare("  SELECT COUNT(*) FROM inventory_products ip  LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?  WHERE ip.category != 'Fuel' AND ip.status = 'Active'  AND COALESCE(si.stock_level, ip.stock, 0) <= 0  ");  $s->execute([$station_id]); $admin_out_of_stock = (int)$s->fetchColumn();
} catch (Exception $e) {}  // 6. Outstanding Utang (customer balances)
$admin_utang = 0.0;
try {  $s = $pdo->prepare("SELECT SUM(balance) FROM customers WHERE station_id = ?");  $s->execute([$station_id]); $admin_utang = (float)$s->fetchColumn();
} catch (Exception $e) {}
?>
<style>
/* Admin Summary Cards - Modern Design */
.admin-summary-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));  gap: 16px;  margin-bottom: 20px;
}  .admin-stat-card {  background: #fff;  border-radius: 12px;  padding: 18px 20px;  border: 1px solid #e2e8f0;  box-shadow: 0 1px 3px rgba(0,0,0,0.06);  transition: all 0.2s ease;  position: relative;  overflow: hidden;
}  .admin-stat-card:hover {  box-shadow: 0 4px 12px rgba(0,0,0,0.08);  transform: translateY(-2px);
}  .admin-stat-card-icon {  width: 44px;  height: 44px;  border-radius: 10px;  display: flex;  align-items: center;  justify-content: center;  font-size: 20px;  margin-bottom: 12px;
}  .admin-stat-card-label {  font-size: 12px;  font-weight: 600;  color: #64748b;  text-transform: uppercase;  letter-spacing: 0.5px;  margin-bottom: 6px;
}  .admin-stat-card-value {  font-size: 26px;  font-weight: 800;  line-height: 1;  margin-bottom: 4px;
}  .admin-stat-card-subtitle {  font-size: 11px;  color: #94a3b8;  font-weight: 500;
}  /* Color variants */
.admin-stat-card.blue .admin-stat-card-icon {  background: linear-gradient(135deg, #002F70 0%, #004aad 100%);  color: #fff;
}
.admin-stat-card.blue .admin-stat-card-value {  color: #002F70;
}  .admin-stat-card.orange .admin-stat-card-icon {  background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);  color: #fff;
}
.admin-stat-card.orange .admin-stat-card-value {  color: #ea580c;
}  .admin-stat-card.green .admin-stat-card-icon {  background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);  color: #fff;
}
.admin-stat-card.green .admin-stat-card-value {  color: #16a34a;
}  .admin-stat-card.yellow .admin-stat-card-icon {  background: linear-gradient(135deg, #ca8a04 0%, #eab308 100%);  color: #fff;
}
.admin-stat-card.yellow .admin-stat-card-value {  color: #ca8a04;
}  .admin-stat-card.red .admin-stat-card-icon {  background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);  color: #fff;
}
.admin-stat-card.red .admin-stat-card-value {  color: #dc2626;
}  .admin-stat-card.purple .admin-stat-card-icon {  background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%);  color: #fff;
}
.admin-stat-card.purple .admin-stat-card-value {  color: #7c3aed;
}  /* Responsive */
@media (max-width: 768px) {  .admin-summary-grid {  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));  gap: 12px;  }  .admin-stat-card {  padding: 14px 16px;  }  .admin-stat-card-icon {  width: 38px;  height: 38px;  font-size: 18px;  margin-bottom: 10px;  }  .admin-stat-card-value {  font-size: 22px;  }
}
</style>  <div class="admin-summary-grid">  <div class="admin-stat-card blue">  <div class="admin-stat-card-icon">  <i class="fas fa-file-invoice"></i>  </div>  <div class="admin-stat-card-label">Pending POs</div>  <div class="admin-stat-card-value"><?= number_format($admin_pending_pos) ?></div>  <div class="admin-stat-card-subtitle">Awaiting Stock-In</div>  </div>  <div class="admin-stat-card orange">  <div class="admin-stat-card-icon">  <i class="fas fa-clipboard-list"></i>  </div>  <div class="admin-stat-card-label">Pending Requests</div>  <div class="admin-stat-card-value"><?= number_format($admin_pending_sr) ?></div>  <div class="admin-stat-card-subtitle">Stock Requests</div>  </div>  <div class="admin-stat-card green">  <div class="admin-stat-card-icon">  <i class="fas fa-box-open"></i>  </div>  <div class="admin-stat-card-label">Active Products</div>  <div class="admin-stat-card-value"><?= number_format($admin_active_products) ?></div>  <div class="admin-stat-card-subtitle">Total Items</div>  </div>  <div class="admin-stat-card yellow">  <div class="admin-stat-card-icon">  <i class="fas fa-exclamation-circle"></i>  </div>  <div class="admin-stat-card-label">Low Stock</div>  <div class="admin-stat-card-value"><?= number_format($admin_low_stock) ?></div>  <div class="admin-stat-card-subtitle">Need Reorder</div>  </div>  <div class="admin-stat-card red">  <div class="admin-stat-card-icon">  <i class="fas fa-times-circle"></i>  </div>  <div class="admin-stat-card-label">Out of Stock</div>  <div class="admin-stat-card-value"><?= number_format($admin_out_of_stock) ?></div>  <div class="admin-stat-card-subtitle">Critical</div>  </div>  <div class="admin-stat-card purple">  <div class="admin-stat-card-icon">  <i class="fas fa-coins"></i>  </div>  <div class="admin-stat-card-label">Outstanding Utang</div>  <div class="admin-stat-card-value">₱<?= number_format($admin_utang, 2) ?></div>  <div class="admin-stat-card-subtitle">Customer Balances</div>  </div>
</div>
