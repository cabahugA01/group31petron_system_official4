<?php
/**
 * Staff Inventory Summary partial file
 * Clean, modern design matching transaction module style
 */
if (!isset($pdo) || !isset($me) || !isset($station_id)) {  return;
}

$today = date('Y-m-d');
$my_id = (int)$me['id'];

// 1. Transactions Encoded today by this staff
$encoded_count = 0;
try {  $s_tx = $pdo->prepare("SELECT COUNT(*) FROM merchandise_transactions WHERE station_id = ? AND DATE(created_at) = ? AND created_by = ?");  $s_tx->execute([$station_id, $today, $my_id]);  $encoded_count += (int)$s_tx->fetchColumn();
} catch (Exception $e) {}
try {  $s_jo = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ? AND cashier_id = ?");  $s_jo->execute([$station_id, $today, $my_id]);  $encoded_count += (int)$s_jo->fetchColumn();
} catch (Exception $e) {}

// 2. Deliveries Submitted today by this staff
$deliveries_submitted = 0;
try {  $s_del_merch = $pdo->prepare("SELECT COUNT(*) FROM merchandise_stock_in WHERE station_id = ? AND DATE(encoded_at) = ? AND encoded_by = ?");  $s_del_merch->execute([$station_id, $today, $my_id]);  $deliveries_submitted += (int)$s_del_merch->fetchColumn();
} catch (Exception $e) {}
try {  $s_del_fuel = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_in WHERE station_id = ? AND DATE(encoded_at) = ? AND encoded_by = ?");  $s_del_fuel->execute([$station_id, $today, $my_id]);  $deliveries_submitted += (int)$s_del_fuel->fetchColumn();
} catch (Exception $e) {}

// 3. Stock Requests Submitted by this staff (all time)
$requests_submitted = 0;
try {  $s_req = $pdo->prepare("SELECT COUNT(*) FROM stock_requests WHERE station_id = ? AND staff_id = ?");  $s_req->execute([$station_id, $my_id]);  $requests_submitted += (int)$s_req->fetchColumn();
} catch (Exception $e) {}
try {  $s_req_fuel = $pdo->prepare("SELECT COUNT(*) FROM fuel_stock_requests WHERE staff_id = ?");  $s_req_fuel->execute([$my_id]);  $requests_submitted += (int)$s_req_fuel->fetchColumn();
} catch (Exception $e) {}

// 4. Shift Sales Total today by this staff
$shift_sales = 0.0;
try {  $s_sales_merch = $pdo->prepare("SELECT SUM(total_amount) FROM merchandise_transactions WHERE station_id = ? AND DATE(created_at) = ? AND created_by = ?");  $s_sales_merch->execute([$station_id, $today, $my_id]);  $shift_sales += (float)$s_sales_merch->fetchColumn();
} catch (Exception $e) {}
try {  $s_sales_jo = $pdo->prepare("SELECT SUM(actual_cost) FROM job_orders WHERE station_id = ? AND DATE(created_at) = ? AND cashier_id = ?");  $s_sales_jo->execute([$station_id, $today, $my_id]);  $shift_sales += (float)$s_sales_jo->fetchColumn();
} catch (Exception $e) {}

// 5. Outstanding Balances (total customer utang) for this station
$outstanding_balances = 0.0;
try {  $s_bal = $pdo->prepare("SELECT SUM(balance) FROM customers WHERE station_id = ?");  $s_bal->execute([$station_id]);  $outstanding_balances = (float)$s_bal->fetchColumn();
} catch (Exception $e) {}
?>
<style>
/* Staff Summary Cards - Modern Design */
.staff-summary-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));  gap: 16px;  margin-bottom: 20px;
}

.stat-card {  background: #fff;  border-radius: 12px;  padding: 18px 20px;  border: 1px solid #e2e8f0;  box-shadow: 0 1px 3px rgba(0,0,0,0.06);  transition: all 0.2s ease;  position: relative;  overflow: hidden;
}

.stat-card:hover {  box-shadow: 0 4px 12px rgba(0,0,0,0.08);  transform: translateY(-2px);
}

.stat-card-icon {  width: 44px;  height: 44px;  border-radius: 10px;  display: flex;  align-items: center;  justify-content: center;  font-size: 20px;  margin-bottom: 12px;
}

.stat-card-label {  font-size: 12px;  font-weight: 600;  color: #64748b;  text-transform: uppercase;  letter-spacing: 0.5px;  margin-bottom: 6px;
}

.stat-card-value {  font-size: 26px;  font-weight: 800;  line-height: 1;  margin-bottom: 4px;
}

.stat-card-subtitle {  font-size: 11px;  color: #94a3b8;  font-weight: 500;
}

/* Color variants */
.stat-card.blue .stat-card-icon {  background: linear-gradient(135deg, #002F70 0%, #004aad 100%);  color: #fff;
}
.stat-card.blue .stat-card-value {  color: #002F70;
}

.stat-card.green .stat-card-icon {  background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);  color: #fff;
}
.stat-card.green .stat-card-value {  color: #16a34a;
}

.stat-card.orange .stat-card-icon {  background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);  color: #fff;
}
.stat-card.orange .stat-card-value {  color: #ea580c;
}

.stat-card.indigo .stat-card-icon {  background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);  color: #fff;
}
.stat-card.indigo .stat-card-value {  color: #4f46e5;
}

.stat-card.red .stat-card-icon {  background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);  color: #fff;
}
.stat-card.red .stat-card-value {  color: #dc2626;
}

/* Responsive */
@media (max-width: 768px) {  .staff-summary-grid {  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));  gap: 12px;  }  .stat-card {  padding: 14px 16px;  }  .stat-card-icon {  width: 38px;  height: 38px;  font-size: 18px;  margin-bottom: 10px;  }  .stat-card-value {  font-size: 22px;  }
}
</style>

<div class="staff-summary-grid">  <div class="stat-card blue">  <div class="stat-card-icon">  <i class="fas fa-shopping-cart"></i>  </div>  <div class="stat-card-label">Transactions Encoded Today</div>  <div class="stat-card-value"><?= number_format($encoded_count) ?></div>  <div class="stat-card-subtitle">Merchandise + Job Orders</div>  </div>  <div class="stat-card green">  <div class="stat-card-icon">  <i class="fas fa-truck"></i>  </div>  <div class="stat-card-label">Deliveries Submitted Today</div>  <div class="stat-card-value"><?= number_format($deliveries_submitted) ?></div>  <div class="stat-card-subtitle">Fuel + Merchandise</div>  </div>  <div class="stat-card orange">  <div class="stat-card-icon">  <i class="fas fa-box"></i>  </div>  <div class="stat-card-label">Stock Requests Submitted</div>  <div class="stat-card-value"><?= number_format($requests_submitted) ?></div>  <div class="stat-card-subtitle">Total All Time</div>  </div>  <div class="stat-card indigo">  <div class="stat-card-icon">  <i class="fas fa-peso-sign"></i>  </div>  <div class="stat-card-label">Shift Sales Total Today</div>  <div class="stat-card-value">₱<?= number_format($shift_sales, 2) ?></div>  <div class="stat-card-subtitle">Your Transactions</div>  </div>  <div class="stat-card red">  <div class="stat-card-icon">  <i class="fas fa-exclamation-triangle"></i>  </div>  <div class="stat-card-label">Outstanding Balances (Utang)</div>  <div class="stat-card-value">₱<?= number_format($outstanding_balances, 2) ?></div>  <div class="stat-card-subtitle">Station Total</div>  </div>
</div>
