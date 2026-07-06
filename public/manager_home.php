<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'manager_home';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();  $me = current_user();
$role = role_key($me['role'] ?? '');  // Restrict to managers only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {  $_SESSION['error'] = 'Access denied. Manager access required.';  header('Location: dashboard.php');  exit;
}  $station_id = user_station_id();  // Manager metrics - use existing database tables
$metrics = [  'staff_on_duty' => 0,  'pending_approvals' => 0,  'active_jobs' => 0,  'quality_score' => 0,  'shift_coverage' => 0,  'compliance_checks' => 0
];  try {  // Staff on duty - use users table with shift_status  $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE station_id = ? AND status = 'Active' AND role IN ('staff', 'cashier', 'pump_attendant') AND shift_status = 'on_duty'");  $stmt->execute([$station_id]);  $metrics['staff_on_duty'] = (int)$stmt->fetchColumn();  // Pending approvals - use sales table with status filtering  $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE station_id = ? AND status = 'Pending Validation'");  $stmt->execute([$station_id]);  $metrics['pending_approvals'] = (int)$stmt->fetchColumn();  // Active jobs  $stmt = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE station_id = ? AND status IN ('Pending','PENDING_REVIEW','Pending Review','In Progress','Awaiting Parts')");  $stmt->execute([$station_id]);  $metrics['active_jobs'] = (int)$stmt->fetchColumn();  // Quality score (placeholder - calculate from recent performance)  $metrics['quality_score'] = 95.0;  // Shift coverage (placeholder - calculate from shift logs)  $metrics['shift_coverage'] = 85.0;  // Compliance checks (placeholder - count passed checks)  $metrics['compliance_checks'] = 8;  } catch(Exception $e) {  // Keep default values if queries fail
}  include __DIR__ . '/../partials/header.php';  // Recent alerts (priority/high/medium)
$alerts = [];
try {  $stmt = $pdo->prepare("SELECT `user_id`, action as title, created_at, details, 'medium' as priority FROM activity_logs WHERE station_id = ? ORDER BY created_at DESC LIMIT 5");  $stmt->execute([$station_id]);  $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}  // Staff performance: safe fallback
$staff_performance = [];
try {  $stmt = $pdo->prepare("SELECT u.name, COUNT(jo.id) as completed_jobs  FROM users u  LEFT JOIN job_orders jo ON u.id = jo.user_id AND jo.status = 'Completed' AND DATE(jo.completed_at) = CURDATE()  WHERE u.station_id = ?  GROUP BY u.id  ORDER BY completed_jobs DESC  LIMIT 5");  $stmt->execute([$station_id]);  $staff_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}
?>  <div class="page-head">  <div>  <h1 class="h1">Operations Control Center</h1>  <div class="sub">Welcome, <?php echo htmlspecialchars($me['name'] ?? 'Manager'); ?>  Station Performance Dashboard</div>  </div>  <div class="header-actions">  <span class="badge status-active" style="margin-right: 15px;">  <i class="fas fa-clock"></i> <?php echo date('g:i A'); ?>  </span>  <button class="btn btn-outline" onclick="location.reload()">  <i class="fas fa-sync-alt"></i> Refresh  </button>  </div>
</div>  <div class="manager-stats-grid">  <div class="stat-card">  <div class="stat-icon bg-primary"><i class="fas fa-users"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo (int)$metrics['staff_on_duty']; ?></div>  <div class="stat-label">Staff On Duty</div>  <div class="stat-sub"><?php echo number_format((float)$metrics['shift_coverage'], 1); ?>% coverage</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-warning"><i class="fas fa-clipboard-check"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo (int)$metrics['pending_approvals']; ?></div>  <div class="stat-label">Pending Approvals</div>  <div class="stat-sub">Awaiting action</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-info"><i class="fas fa-tasks"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo (int)$metrics['active_jobs']; ?></div>  <div class="stat-label">Active Jobs</div>  <div class="stat-sub">Pending / In progress</div>  </div>  </div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-warning"><i class="fas fa-clipboard-check"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo $metrics['pending_approvals']; ?></div>  <div class="stat-label">Pending Approvals</div>  <div class="stat-sub">Awaiting action</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-info"><i class="fas fa-wrench"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo $metrics['active_jobs']; ?></div>  <div class="stat-label">Active Jobs</div>  <div class="stat-sub">In progress</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-primary"><i class="fas fa-star"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo number_format($metrics['quality_score'], 1); ?>%</div>  <div class="stat-label">Quality Score</div>  <div class="stat-sub">Performance rating</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-info"><i class="fas fa-calendar"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo number_format($metrics['shift_coverage'], 1); ?>%</div>  <div class="stat-label">Shift Coverage</div>  <div class="stat-sub">Last 7 days</div>  </div>  </div>  <div class="stat-card">  <div class="stat-icon bg-danger"><i class="fas fa-shield-alt"></i></div>  <div class="stat-content">  <div class="stat-value"><?php echo $metrics['compliance_checks']; ?></div>  <div class="stat-label">Compliance Checks</div>  <div class="stat-sub">Passed/Failed</div>  </div>  </div>
</div>  <div class="dashboard-grid">  <div class="dashboard-column">  <div class="dashboard-card">  <div class="card-header">  <h3><i class="fas fa-chart-line text-warning"></i> Quick Actions</h3>  </div>  <div class="card-body">  <div class="quick-actions-grid">  <a href="transactions.php" class="action-btn">  <i class="fas fa-eye"></i>  <span>Transactions Oversight</span>  </a>  <a href="staff_management.php" class="action-btn">  <i class="fas fa-users"></i>  <span>Staff Management</span>  </a>  <a href="inventory_management.php" class="action-btn">  <i class="fas fa-box"></i>  <span>Inventory</span>  </a>  <a href="fuel_management.php" class="action-btn">  <i class="fas fa-gas-pump"></i>  <span>Fuel Operations</span>  </a>  </div>  </div>  </div>  </div>  <div class="dashboard-column">  <div class="dashboard-card">  <div class="card-header">  <h3><i class="fas fa-users text-success"></i> Staff Status</h3>  </div>  <div class="card-body">  <div class="staff-status-grid">  <div class="staff-status-item">  <h4>On Duty</h4>  <div class="status-number"><?php echo $metrics['staff_on_duty']; ?></div>  <div class="status-label">Currently active</div>  </div>  <div class="staff-status-item">  <h4>Off Duty</h4>  <div class="status-number"><?php echo ($metrics['staff_on_duty'] > 0 ? ($metrics['staff_on_duty'] - 1) : 0); ?></div>  <div class="status-label">Currently off duty</div>  </div>  </div>  </div>  </div>  </div>
</div>  <style>
.manager-stats-grid{display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:20px;margin:24px 0}
.stat-card{background:#fff;border-radius:12px;padding:20px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef;transition:transform .2s}
.stat-icon{width:56px;height:56px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#fff}
.bg-primary{background:#0066cc}.bg-success{background:#28a745}.bg-info{background:#00b4d8}.bg-warning{background:#ff9500}.bg-danger{background:#dc3545}
.stat-content{flex:1}
.stat-value{font-size:28px;font-weight:700;color:#1a1a1a;line-height:1}
.stat-label{font-size:14px;color:#666;margin-top:4px}
.stat-sub{font-size:12px;color:#888}
.dashboard-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px}
.dashboard-column{display:flex;flex-direction:column;gap:24px}
.dashboard-card{background:#fff;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef}
.card-header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #e9ecef}
.card-header h3{margin:0;font-size:16px;font-weight:600;color:#333}
.card-body{padding:24px}
.quick-actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px}
.action-btn{display:flex;flex-direction:column;align-items:center;padding:20px;background:#f8f9fa;border-radius:8px;text-decoration:none;color:#333;transition:all .2s;min-height:80px}
.action-btn:hover{background:#0066cc;color:#fff;transform:translateY(-2px)}
.action-btn i{font-size:24px;margin-bottom:8px}
.action-btn span{font-size:12px;font-weight:500}
.staff-status-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.staff-status-item{background:#f8f9fa;border-radius:8px;padding:20px;text-align:center}
.staff-status-item h4{margin:0 0 0 8px;color:#333}
.status-number{font-size:24px;font-weight:700;color:#0066cc;margin-bottom:4px}
.status-label{font-size:14px;color:#666}
@media (max-width:768px){.manager-stats-grid{grid-template-columns:1fr;gap:15px}.dashboard-grid{grid-template-columns:1fr}.quick-actions-grid{grid-template-columns:1fr}.staff-status-grid{grid-template-columns:1fr}}
.staff-avatar{width:40px;height:40px;border-radius:50%;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-size:20px;color:#666}
.staff-info{flex:1}.staff-name{font-weight:500;margin-bottom:4px}.staff-stats{display:flex;gap:8px}
.stat-badge{font-size:11px;padding:2px 8px;border-radius:12px;background:#e9ecef;display:inline-flex;align-items:center;gap:4px}
.quick-actions-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.quick-action-btn{display:flex;flex-direction:column;align-items:center;gap:8px;padding:16px;border-radius:8px;background:#f8f9fa;text-decoration:none;color:#333;transition:all .2s}
.quick-action-btn:hover{background:#0066cc;color:#fff;transform:translateY(-2px)}
.quick-icon{width:40px;height:40px;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:18px}
.quick-label{font-size:12px;font-weight:500;text-align:center}
.compliance-progress{margin-bottom:20px}.progress-label{display:flex;justify-content:space-between;margin-bottom:8px;font-size:14px}
.progress-bar{height:8px;background:#e9ecef;border-radius:4px;overflow:hidden}.progress-fill{height:100%;background:#00a65a;border-radius:4px}
.compliance-checklist{display:flex;flex-direction:column;gap:12px}.checklist-item{display:flex;align-items:center;gap:8px;font-size:14px}
.checklist-item.completed{color:#00a65a}.checklist-item.pending{color:#ff9500}
.empty-state{text-align:center;padding:40px 20px;color:#888}.empty-state i{font-size:48px;margin-bottom:16px;opacity:.5}
.btn-link{font-size:14px;color:#0066cc;text-decoration:none;font-weight:500}.btn-link:hover{text-decoration:underline}
.header-actions{display:flex;align-items:center}
@media (max-width:1200px){.manager-stats-grid{grid-template-columns:repeat(2,1fr)}.manager-dashboard-grid{grid-template-columns:1fr}}
@media (max-width:768px){.manager-stats-grid{grid-template-columns:1fr}.quick-actions-grid{grid-template-columns:repeat(2,1fr)}}
</style>  <?php include __DIR__ . '/../partials/footer.php'; ?>
