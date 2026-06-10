<?php
/**
 * CLEAR MERCHANDISE TRANSACTIONS & JOB ORDERS
 * 
 * This page allows you to delete all merchandise transactions and job orders
 * Use this to clear test data before inputting new real data
 * 
 * SECURITY: Requires SuperAdmin or Developer access
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Only SuperAdmin or Developer can access this page
if (!in_array($role, ['superadmin', 'developer'])) {
    die('⛔ <h1>Access Denied</h1><p>Only SuperAdmin or Developer can clear test data.</p>');
}

$success_message = '';
$error_message = '';
$deleted_counts = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    $confirm_code = trim($_POST['confirmation_code'] ?? '');
    
    // Security confirmation code
    if ($confirm_code !== 'DELETE-ALL-DATA') {
        $error_message = '❌ Invalid confirmation code. Please type exactly: DELETE-ALL-DATA';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Disable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // ========================================
            // 1. MERCHANDISE TRANSACTIONS
            // ========================================
            
            // Count before delete
            $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise_transaction_items");
            $deleted_counts['merchandise_items'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions");
            $deleted_counts['merchandise_transactions'] = $stmt->fetchColumn();
            
            // Delete merchandise transaction items
            $pdo->exec("TRUNCATE TABLE merchandise_transaction_items");
            
            // Delete merchandise transactions
            $pdo->exec("TRUNCATE TABLE merchandise_transactions");
            
            // Reset auto-increment
            $pdo->exec("ALTER TABLE merchandise_transactions AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE merchandise_transaction_items AUTO_INCREMENT = 1");
            
            // ========================================
            // 2. JOB ORDERS
            // ========================================
            
            // Count before delete
            $stmt = $pdo->query("SELECT COUNT(*) FROM job_order_items");
            $deleted_counts['job_order_items'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM job_order_services");
            $deleted_counts['job_order_services'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM job_orders");
            $deleted_counts['job_orders'] = $stmt->fetchColumn();
            
            // Delete job order items
            $pdo->exec("TRUNCATE TABLE job_order_items");
            
            // Delete job order services
            $pdo->exec("TRUNCATE TABLE job_order_services");
            
            // Delete labor entries related to job orders
            $stmt = $pdo->query("SELECT COUNT(*) FROM labor_entries WHERE job_order_id IS NOT NULL");
            $deleted_counts['labor_entries'] = $stmt->fetchColumn();
            $pdo->exec("DELETE FROM labor_entries WHERE job_order_id IS NOT NULL");
            
            // Delete job orders
            $pdo->exec("TRUNCATE TABLE job_orders");
            
            // Reset auto-increment
            $pdo->exec("ALTER TABLE job_orders AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE job_order_items AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE job_order_services AUTO_INCREMENT = 1");
            
            // ========================================
            // 3. AUDIT LOGS (Related records only)
            // ========================================
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type IN ('merchandise_transaction', 'job_order') OR action_type LIKE '%merchandise%' OR action_type LIKE '%job order%'");
            $deleted_counts['audit_logs'] = $stmt->fetchColumn();
            
            $pdo->exec("DELETE FROM audit_logs WHERE entity_type IN ('merchandise_transaction', 'job_order') OR action_type LIKE '%merchandise%' OR action_type LIKE '%job order%' OR action_type LIKE '%transaction%'");
            
            // ========================================
            // 4. ACTIVITY LOGS
            // ========================================
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action LIKE '%merchandise%' OR action LIKE '%job order%' OR action LIKE '%transaction%'");
            $deleted_counts['activity_logs'] = $stmt->fetchColumn();
            
            $pdo->exec("DELETE FROM activity_logs WHERE action LIKE '%merchandise%' OR action LIKE '%job order%' OR action LIKE '%Job Order%' OR action LIKE '%transaction%' OR details LIKE '%merchandise_transaction%' OR details LIKE '%job_order%'");
            
            // ========================================
            // 5. NOTIFICATIONS
            // ========================================
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE type IN ('merchandise_transaction', 'job_order') OR message LIKE '%merchandise%' OR message LIKE '%job order%'");
            $deleted_counts['notifications'] = $stmt->fetchColumn();
            
            $pdo->exec("DELETE FROM notifications WHERE type IN ('merchandise_transaction', 'job_order') OR message LIKE '%merchandise transaction%' OR message LIKE '%job order%' OR redirect_url LIKE '%merchandise%' OR redirect_url LIKE '%job_order%'");
            
            // Re-enable foreign key checks
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            
            $pdo->commit();
            
            // Log this action
            log_activity($pdo, $me['id'], 'Clear Test Data', 'Cleared all merchandise transactions and job orders. Deleted: ' . json_encode($deleted_counts));
            
            $success_message = '✅ Successfully deleted all merchandise transactions and job orders!';
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = '❌ Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Test Data - Petron System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { background: white; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); max-width: 600px; width: 100%; padding: 40px; }
        h1 { color: #2d3748; font-size: 28px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px; }
        .subtitle { color: #718096; font-size: 14px; margin-bottom: 30px; }
        .warning-box { background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 16px; margin-bottom: 24px; display: flex; align-items: flex-start; gap: 12px; }
        .warning-box i { color: #856404; font-size: 24px; margin-top: 2px; }
        .warning-text { color: #856404; font-size: 14px; line-height: 1.5; }
        .warning-text strong { display: block; margin-bottom: 6px; font-size: 16px; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 16px; margin-bottom: 24px; border-radius: 4px; }
        .info-box h3 { color: #1976d2; font-size: 14px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .info-box ul { list-style: none; padding: 0; }
        .info-box li { color: #0d47a1; padding: 4px 0; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .info-box li i { color: #42a5f5; font-size: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; color: #2d3748; font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        input[type="text"] { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 14px; transition: border-color 0.3s; font-family: monospace; }
        input[type="text"]:focus { outline: none; border-color: #dc2626; }
        .btn-delete { background: linear-gradient(135deg, #dc2626, #991b1b); color: white; border: none; padding: 14px 24px; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 12px rgba(220,38,38,0.3); }
        .btn-delete:hover { background: linear-gradient(135deg, #991b1b, #7f1d1d); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(220,38,38,0.4); }
        .btn-delete:active { transform: translateY(0); }
        .btn-back { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background 0.3s; margin-top: 16px; }
        .btn-back:hover { background: #5a6268; }
        .success-message { background: #d4edda; border: 2px solid #28a745; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #155724; display: flex; align-items: flex-start; gap: 12px; }
        .success-message i { font-size: 24px; margin-top: 2px; }
        .error-message { background: #f8d7da; border: 2px solid #dc3545; border-radius: 8px; padding: 16px; margin-bottom: 24px; color: #721c24; display: flex; align-items: flex-start; gap: 12px; }
        .error-message i { font-size: 24px; margin-top: 2px; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 20px; }
        .stat-card { background: #f8f9fa; border-radius: 8px; padding: 12px; text-align: center; }
        .stat-card .number { font-size: 24px; font-weight: 700; color: #dc2626; }
        .stat-card .label { font-size: 12px; color: #6c757d; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-trash-alt" style="color: #dc2626;"></i> Clear Test Data</h1>
        <p class="subtitle">Delete all merchandise transactions and job orders from the database</p>

        <?php if ($success_message): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <div>
                <strong><?php echo $success_message; ?></strong>
                <?php if (!empty($deleted_counts)): ?>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['merchandise_transactions'] ?? 0); ?></div>
                        <div class="label">Merchandise Transactions</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['merchandise_items'] ?? 0); ?></div>
                        <div class="label">Transaction Items</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['job_orders'] ?? 0); ?></div>
                        <div class="label">Job Orders</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['job_order_items'] ?? 0); ?></div>
                        <div class="label">JO Items</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['job_order_services'] ?? 0); ?></div>
                        <div class="label">JO Services</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['audit_logs'] ?? 0); ?></div>
                        <div class="label">Audit Logs</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['activity_logs'] ?? 0); ?></div>
                        <div class="label">Activity Logs</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo number_format($deleted_counts['notifications'] ?? 0); ?></div>
                        <div class="label">Notifications</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <a href="super_admin_dashboard.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <?php else: ?>

        <?php if ($error_message): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <div><?php echo $error_message; ?></div>
        </div>
        <?php endif; ?>

        <div class="warning-box">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="warning-text">
                <strong>⚠️ WARNING: This action cannot be undone!</strong>
                This will permanently delete all merchandise transactions and job orders from the database.
                Make sure you have a backup before proceeding.
            </div>
        </div>

        <div class="info-box">
            <h3><i class="fas fa-database"></i> Tables that will be cleared:</h3>
            <ul>
                <li><i class="fas fa-circle"></i> merchandise_transactions</li>
                <li><i class="fas fa-circle"></i> merchandise_transaction_items</li>
                <li><i class="fas fa-circle"></i> job_orders</li>
                <li><i class="fas fa-circle"></i> job_order_items</li>
                <li><i class="fas fa-circle"></i> job_order_services</li>
                <li><i class="fas fa-circle"></i> labor_entries (job order related)</li>
                <li><i class="fas fa-circle"></i> Related audit_logs</li>
                <li><i class="fas fa-circle"></i> Related activity_logs</li>
                <li><i class="fas fa-circle"></i> Related notifications</li>
            </ul>
        </div>

        <form method="POST" onsubmit="return confirm('Are you absolutely sure you want to delete ALL merchandise transactions and job orders? This cannot be undone!');">
            <div class="form-group">
                <label for="confirmation_code">
                    <i class="fas fa-key"></i> Confirmation Code
                </label>
                <input 
                    type="text" 
                    id="confirmation_code" 
                    name="confirmation_code" 
                    placeholder="Type: DELETE-ALL-DATA" 
                    required
                    autocomplete="off"
                >
                <small style="color: #6c757d; font-size: 12px; display: block; margin-top: 6px;">
                    To confirm deletion, please type exactly: <code style="background: #f1f3f5; padding: 2px 6px; border-radius: 4px; font-weight: 600;">DELETE-ALL-DATA</code>
                </small>
            </div>

            <button type="submit" name="confirm_delete" class="btn-delete">
                <i class="fas fa-trash-alt"></i>
                Delete All Transactions & Job Orders
            </button>
        </form>

        <a href="super_admin_dashboard.php" class="btn-back">
            <i class="fas fa-times"></i> Cancel
        </a>

        <?php endif; ?>
    </div>
</body>
</html>
