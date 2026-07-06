<?php
/**  * Clear Test Transaction Data  * Removes all merchandise and job order transaction records  * USE WITH CAUTION - This permanently deletes data!  */  require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();  $me = current_user();
$role = role_key($me['role'] ?? '');  // Only superadmin/developer can clear data
if (!in_array($role, ['superadmin', 'developer'])) {  die('Access Denied: Only superadmin/developer can clear test data');
}  $success = [];
$errors = [];
$cleared = false;  // Handle POST request to clear data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_clear'])) {  try {  $pdo->beginTransaction();  // Disable foreign key checks temporarily  $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");  // ── Clear Job Order Data ──────────────────────────────────────  $pdo->exec("TRUNCATE TABLE job_order_items");  $success[] = " Cleared job_order_items";  $pdo->exec("TRUNCATE TABLE job_orders");  $success[] = " Cleared job_orders";  // Clear job order activity if table exists  try {  $pdo->exec("TRUNCATE TABLE job_order_activity");  $success[] = " Cleared job_order_activity";  } catch (Exception $e) {  // Table might not exist, skip  }  // ── Clear Merchandise Transaction Data ────────────────────────  $pdo->exec("TRUNCATE TABLE merchandise_transaction_items");  $success[] = " Cleared merchandise_transaction_items";  $pdo->exec("TRUNCATE TABLE merchandise_transactions");  $success[] = " Cleared merchandise_transactions";  // ── Reset Auto Increment ───────────────────────────────────────  $pdo->exec("ALTER TABLE job_orders AUTO_INCREMENT = 1");  $pdo->exec("ALTER TABLE job_order_items AUTO_INCREMENT = 1");  $pdo->exec("ALTER TABLE merchandise_transactions AUTO_INCREMENT = 1");  $pdo->exec("ALTER TABLE merchandise_transaction_items AUTO_INCREMENT = 1");  $success[] = " Reset auto increment counters";  // Re-enable foreign key checks  $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");  $pdo->commit();  $cleared = true;  // Log activity  log_activity($pdo, $me['id'], 'Clear Test Data',  "Cleared all merchandise and job order transaction data");  } catch (Exception $e) {  $pdo->rollBack();  $errors[] = "Error: " . $e->getMessage();  }
}  // Get current counts
$counts = [];
try {  $stmt = $pdo->query("SELECT COUNT(*) FROM job_orders");  $counts['job_orders'] = $stmt->fetchColumn();  $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions");  $counts['merchandise'] = $stmt->fetchColumn();  $stmt = $pdo->query("SELECT COUNT(*) FROM job_order_items");  $counts['jo_items'] = $stmt->fetchColumn();  $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise_transaction_items");  $counts['merch_items'] = $stmt->fetchColumn();
} catch (Exception $e) {  $errors[] = "Error getting counts: " . $e->getMessage();
}  include __DIR__ . '/../partials/header.php';
?>  <style>
.clear-card {  background: #fff;  border-radius: 12px;  box-shadow: 0 2px 8px rgba(0,0,0,.06);  border: 1px solid #e9ecef;  padding: 24px;  margin-bottom: 20px;
}
.warning-box {  background: #fff3cd;  border: 2px solid #ffc107;  border-radius: 8px;  padding: 20px;  margin-bottom: 24px;
}
.success-box {  background: #d4edda;  border: 2px solid #28a745;  border-radius: 8px;  padding: 20px;  margin-bottom: 24px;
}
.error-box {  background: #f8d7da;  border: 2px solid #dc3545;  border-radius: 8px;  padding: 20px;  margin-bottom: 24px;
}
.count-grid {  display: grid;  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));  gap: 16px;  margin: 20px 0;
}
.count-card {  background: #f8f9fa;  border: 1px solid #dee2e6;  border-radius: 8px;  padding: 16px;  text-align: center;
}
.count-num {  font-size: 32px;  font-weight: 700;  color: #002F6C;  margin-bottom: 4px;
}
.count-label {  font-size: 12px;  color: #6c757d;  text-transform: uppercase;  font-weight: 600;  letter-spacing: .5px;
}
.btn-clear {  background: #dc3545;  color: #fff;  border: none;  padding: 12px 32px;  border-radius: 6px;  font-size: 14px;  font-weight: 600;  cursor: pointer;  transition: background .2s;
}
.btn-clear:hover {  background: #c82333;
}
.btn-clear:disabled {  background: #6c757d;  cursor: not-allowed;
}
</style>  <div class="page-head">  <h1 class="h1"><i class="fas fa-trash-alt"></i> Clear Test Transaction Data</h1>  <div class="sub">Remove all merchandise and job order transaction records</div>
</div>  <?php if ($cleared): ?>
<div class="success-box">  <h3 style="margin:0 0 12px;color:#155724;"><i class="fas fa-check-circle"></i> Data Cleared Successfully!</h3>  <?php foreach ($success as $msg): ?>  <div style="margin:4px 0;color:#155724;"><?= htmlspecialchars($msg) ?></div>  <?php endforeach; ?>
</div>
<?php endif; ?>  <?php if (!empty($errors)): ?>
<div class="error-box">  <h3 style="margin:0 0 12px;color:#721c24;"><i class="fas fa-exclamation-circle"></i> Errors Occurred</h3>  <?php foreach ($errors as $err): ?>  <div style="margin:4px 0;color:#721c24;"><?= htmlspecialchars($err) ?></div>  <?php endforeach; ?>
</div>
<?php endif; ?>  <div class="warning-box">  <h3 style="margin:0 0 12px;color:#856404;"><i class="fas fa-exclamation-triangle"></i> Warning!</h3>  <p style="margin:0 0 8px;color:#856404;">  This action will <strong>permanently delete</strong> all merchandise and job order transaction data.  </p>  <p style="margin:0;color:#856404;">  <strong>Make sure to backup your database before proceeding!</strong>  </p>
</div>  <div class="clear-card">  <h2 style="margin:0 0 16px;font-size:18px;color:#002F6C;">Current Database Records</h2>  <div class="count-grid">  <div class="count-card">  <div class="count-num"><?= number_format($counts['job_orders'] ?? 0) ?></div>  <div class="count-label">Job Orders</div>  </div>  <div class="count-card">  <div class="count-num"><?= number_format($counts['jo_items'] ?? 0) ?></div>  <div class="count-label">Job Order Items</div>  </div>  <div class="count-card">  <div class="count-num"><?= number_format($counts['merchandise'] ?? 0) ?></div>  <div class="count-label">Merchandise Txns</div>  </div>  <div class="count-card">  <div class="count-num"><?= number_format($counts['merch_items'] ?? 0) ?></div>  <div class="count-label">Merchandise Items</div>  </div>  </div>  <div style="margin-top:24px;padding-top:24px;border-top:1px solid #dee2e6;">  <h3 style="margin:0 0 12px;font-size:16px;color:#002F6C;">What will be cleared:</h3>  <ul style="margin:0;padding-left:20px;color:#495057;">  <li>All job orders and job order items</li>  <li>All merchandise transactions and items</li>  <li>Job order activity logs</li>  <li>Auto increment counters will be reset to 1</li>  </ul>  </div>  <div style="margin-top:24px;padding-top:24px;border-top:1px solid #dee2e6;">  <h3 style="margin:0 0 12px;font-size:16px;color:#002F6C;">What will NOT be cleared:</h3>  <ul style="margin:0;padding-left:20px;color:#495057;">  <li>Products/inventory items</li>  <li>Customers</li>  <li>Users/staff</li>  <li>Service types</li>  <li>Fuel transactions</li>  <li>System settings</li>  </ul>  </div>
</div>  <div class="clear-card">  <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to clear all transaction data? This cannot be undone!');">  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">  <input type="checkbox" id="confirm_checkbox" required style="width:20px;height:20px;">  <label for="confirm_checkbox" style="margin:0;font-weight:600;color:#495057;">  I understand this will permanently delete all transaction data and cannot be undone  </label>  </div>  <button type="submit" name="confirm_clear" value="1" class="btn-clear">  <i class="fas fa-trash-alt"></i> Clear All Transaction Data  </button>  <a href="staff_transactions_hub.php" style="margin-left:12px;padding:12px 24px;background:#6c757d;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block;">  <i class="fas fa-times"></i> Cancel  </a>  </form>
</div>  <?php include __DIR__ . '/../partials/footer.php'; ?>
