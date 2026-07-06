<?php
$page_id = 'staff_expected_fuel_deliveries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me  = current_user();
$role  = role_key($me['role'] ?? '');
$station_id = user_station_id();  if (!in_array($role, ['staff', 'cashier', 'pump_attendant'])) {  header('Location: dashboard.php');  exit;
}  /* ── Bootstrap deliveries_oversight table ── */
try {  $pdo->exec("  CREATE TABLE IF NOT EXISTS deliveries_oversight (  id  INT AUTO_INCREMENT PRIMARY KEY,  delivery_type  ENUM('fuel','merchandise') NOT NULL DEFAULT 'merchandise',  delivery_ref  VARCHAR(100) NOT NULL DEFAULT '',  batch_id  VARCHAR(100) DEFAULT NULL,  supplier  VARCHAR(200) NOT NULL DEFAULT '',  product  VARCHAR(200) NOT NULL DEFAULT '',  quantity  DECIMAL(12,3) NOT NULL DEFAULT 0,  unit  VARCHAR(30)  NOT NULL DEFAULT 'pcs',  delivery_date  DATE  NOT NULL,  dr_number  VARCHAR(100) DEFAULT NULL,  encoded_by  INT  DEFAULT NULL,  station_id  INT  NOT NULL,  status  VARCHAR(60)  NOT NULL DEFAULT 'Pending Manager Approval',  source_ref  VARCHAR(100) DEFAULT NULL,  admin_id  INT  DEFAULT NULL,  admin_action_at DATETIME  DEFAULT NULL,  admin_notes  TEXT  DEFAULT NULL,  remarks  TEXT  DEFAULT NULL,  created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,  updated_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,  INDEX idx_station (station_id),  INDEX idx_status  (status),  INDEX idx_date  (delivery_date)  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4  ");
} catch (Exception $e) {}  /* ── Fetch Expected Fuel Deliveries (from Admin Finalized POs) ── */
$expected_fuel_deliveries = [];
try {  $stmt = $pdo->prepare("  SELECT * FROM deliveries_oversight  WHERE station_id = ? AND status = 'Expected Delivery' AND delivery_type = 'fuel'  ORDER BY created_at ASC  ");  $stmt->execute([$station_id]);  $expected_fuel_deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}  /* ── Summary Cards Data ── */
$total_expected = count($expected_fuel_deliveries);
$pending_this_week = 0;
$overdue = 0;  foreach ($expected_fuel_deliveries as $ed) {  $delivery_date = strtotime($ed['created_at']);  $week_start = strtotime('monday this week');  $week_end = strtotime('sunday this week');  if ($delivery_date >= $week_start && $delivery_date <= $week_end) {  $pending_this_week++;  }  // Check if overdue (created more than 7 days ago)  if ($delivery_date < strtotime('-7 days')) {  $overdue++;  }
}  include __DIR__ . '/../partials/header.php';
?>
<style>
/* ── Summary Cards ── */
.summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
.summary-card { background: #fff; border-radius: 12px; padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,.06); border: 1px solid #e9ecef; display: flex; flex-direction: column; gap: 8px; transition: transform .2s, box-shadow .2s; }
.summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.summary-card .sc-num { font-size: 2.5rem; font-weight: 700; line-height: 1; }
.summary-card .sc-label { font-size: 13px; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.summary-card .sc-icon { font-size: 2rem; opacity: 0.15; position: absolute; right: 20px; top: 20px; }
.sc-blue .sc-num { color: #002F70; }
.sc-orange .sc-num { color: #fd7e14; }
.sc-red .sc-num { color: #dc3545; }  /* ── Page Layout ── */
.del-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.06); border: 1px solid #e9ecef; margin-bottom: 24px; }
.del-card-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 24px; border-bottom: 1px solid #e9ecef; }
.del-card-title { font-size: 1.1rem; font-weight: 700; color: #002F70; display: flex; align-items: center; gap: 10px; }
.del-card-body { padding: 24px; }  /* ── Expected Deliveries List ── */
.expected-item { background: #f8f9fa; border: 1px solid #e9ecef; border-left: 4px solid #002F70; border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; gap: 12px; transition: transform .15s, box-shadow .15s; }
.expected-item:hover { transform: translateX(4px); box-shadow: 0 4px 12px rgba(0,0,0,.08); }
.expected-info { flex-grow: 1; }
.expected-info h4 { margin: 0 0 8px 0; font-size: 15px; color: #002F70; font-weight: 600; }
.expected-meta { font-size: 13px; color: #6c757d; display: flex; gap: 16px; flex-wrap: wrap; }
.expected-meta span { display: inline-flex; align-items: center; gap: 6px; }
.po-badge { background: #e8f4fd; color: #002F70; padding: 3px 8px; border-radius: 6px; font-family: monospace; font-size: 11px; font-weight: bold; border: 1px solid #b8d4f0; }
.btn-receive { background: #28a745; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: background .2s; text-decoration: none; }
.btn-receive:hover { background: #218838; }  /* ── Empty State ── */
.empty-state { text-align: center; padding: 60px 20px; color: #adb5bd; }
.empty-state i { font-size: 4rem; margin-bottom: 20px; display: block; opacity: 0.3; }
.empty-state p { font-size: 15px; margin: 0; }  /* ── Back Button ── */
.btn-back { background: #6c757d; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: background .2s; }
.btn-back:hover { background: #5a6268; }
</style>  <div class="page-head">  <div>  <h1 class="h1"><i class="fas fa-gas-pump"></i> Expected Fuel Deliveries</h1>  <div class="sub">View fuel purchase orders created by Manager/Admin with expected fuel types and quantities.</div>  </div>  <div class="header-actions">  <a href="staff_dashboard.php" class="btn-back">  <i class="fas fa-arrow-left"></i> Back to Dashboard  </a>  </div>
</div>  <!-- Summary Cards -->
<div class="summary-grid">  <div class="summary-card sc-blue" style="position:relative;">  <i class="fas fa-truck sc-icon"></i>  <div class="sc-num"><?php echo $total_expected; ?></div>  <div class="sc-label">Total Expected</div>  </div>  <div class="summary-card sc-orange" style="position:relative;">  <i class="fas fa-calendar-week sc-icon"></i>  <div class="sc-num"><?php echo $pending_this_week; ?></div>  <div class="sc-label">Pending This Week</div>  </div>  <div class="summary-card sc-red" style="position:relative;">  <i class="fas fa-exclamation-triangle sc-icon"></i>  <div class="sc-num"><?php echo $overdue; ?></div>  <div class="sc-label">Overdue</div>  </div>
</div>  <!-- Expected Fuel Deliveries Card -->
<div class="del-card">  <div class="del-card-head">  <div class="del-card-title">  <i class="fas fa-truck-loading"></i> Expected Fuel Deliveries  <?php if($total_expected > 0): ?>  <span style="background:#dc3545;color:#fff;border-radius:12px;padding:3px 10px;font-size:12px;"><?php echo $total_expected; ?></span>  <?php endif; ?>  </div>  <span style="font-size:13px;color:#6c757d;">From Finalized Purchase Orders</span>  </div>  <div class="del-card-body">  <?php if (empty($expected_fuel_deliveries)): ?>  <div class="empty-state">  <i class="fas fa-gas-pump"></i>  <p>No expected fuel deliveries at the moment.</p>  <p style="font-size:13px;color:#6c757d;margin-top:10px;">Check back later or use Manual Encode for non-PO deliveries.</p>  <a href="staff_fuel_deliveries.php" style="display:inline-block;margin-top:20px;background:#002F70;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;">  <i class="fas fa-keyboard"></i> Manual Encode Delivery  </a>  </div>  <?php else: ?>  <?php foreach ($expected_fuel_deliveries as $ed): ?>  <div class="expected-item">  <div class="expected-info">  <h4><?php echo htmlspecialchars($ed['product']); ?> Fuel</h4>  <div class="expected-meta">  <span><i class="fas fa-hashtag"></i> PO: <span class="po-badge"><?php echo htmlspecialchars($ed['source_ref'] ?? 'N/A'); ?></span></span>  <span><i class="fas fa-gas-pump"></i> Expected: <strong><?php echo number_format($ed['quantity'], 2) . ' ' . $ed['unit']; ?></strong></span>  <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($ed['supplier']); ?></span>  <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($ed['created_at'])); ?></span>  </div>  </div>  <a href="staff_fuel_deliveries.php?po_id=<?php echo $ed['id']; ?>" class="btn-receive">  <i class="fas fa-eye"></i> View Details  </a>  </div>  <?php endforeach; ?>  <?php endif; ?>  </div>
</div>  <?php include __DIR__ . '/../partials/footer.php'; ?>
