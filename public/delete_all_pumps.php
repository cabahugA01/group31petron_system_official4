<?php
/**
 * DELETE ALL PUMP RECORDS
 * WARNING: This will permanently delete all pump data!
 * Access: http://localhost/group31petron_system_official4/public/delete_all_pumps.php
 */

session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// Only allow admin/manager/superadmin
$me = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'admin', 'manager', 'developer'])) {
    die('Access Denied: Only admins and managers can delete pump records.');
}

$deleted_counts = [];
$errors = [];

// Check if confirmation was clicked
if (isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'YES_DELETE_ALL') {
    try {
        $pdo->beginTransaction();
        
        // Disable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // 1. Delete from fuel_pumps
        $stmt = $pdo->query("DELETE FROM fuel_pumps");
        $deleted_counts['fuel_pumps'] = $stmt->rowCount();
        
        // 2. Delete from pump_calibration_history
        try {
            $stmt = $pdo->query("DELETE FROM pump_calibration_history");
            $deleted_counts['pump_calibration_history'] = $stmt->rowCount();
        } catch (Exception $e) {
            $errors[] = "pump_calibration_history: " . $e->getMessage();
        }
        
        // 3. Delete from fuel_calibration_records
        try {
            $stmt = $pdo->query("DELETE FROM fuel_calibration_records");
            $deleted_counts['fuel_calibration_records'] = $stmt->rowCount();
        } catch (Exception $e) {
            $errors[] = "fuel_calibration_records: " . $e->getMessage();
        }
        
        // 4. Delete from calibration_logs
        try {
            $stmt = $pdo->query("DELETE FROM calibration_logs");
            $deleted_counts['calibration_logs'] = $stmt->rowCount();
        } catch (Exception $e) {
            $errors[] = "calibration_logs: " . $e->getMessage();
        }
        
        // 5. Delete from pump_configuration
        try {
            $stmt = $pdo->query("DELETE FROM pump_configuration");
            $deleted_counts['pump_configuration'] = $stmt->rowCount();
        } catch (Exception $e) {
            $errors[] = "pump_configuration: " . $e->getMessage();
        }
        
        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        // Reset AUTO_INCREMENT counters
        $pdo->exec("ALTER TABLE fuel_pumps AUTO_INCREMENT = 1");
        try { $pdo->exec("ALTER TABLE pump_calibration_history AUTO_INCREMENT = 1"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE fuel_calibration_records AUTO_INCREMENT = 1"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE calibration_logs AUTO_INCREMENT = 1"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE pump_configuration AUTO_INCREMENT = 1"); } catch (Exception $e) {}
        
        $pdo->commit();
        $success = true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = "Transaction failed: " . $e->getMessage();
        $success = false;
    }
}

// Get current record counts
$counts = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_pumps");
    $counts['fuel_pumps'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $counts['fuel_pumps'] = 'Error';
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pump_calibration_history");
    $counts['pump_calibration_history'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $counts['pump_calibration_history'] = 'N/A';
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM fuel_calibration_records");
    $counts['fuel_calibration_records'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $counts['fuel_calibration_records'] = 'N/A';
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM calibration_logs");
    $counts['calibration_logs'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $counts['calibration_logs'] = 'N/A';
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pump_configuration");
    $counts['pump_configuration'] = $stmt->fetchColumn();
} catch (Exception $e) {
    $counts['pump_configuration'] = 'N/A';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete All Pump Records</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f8fafc; padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 20px; }
        .card-header { background: linear-gradient(135deg, #dc2626, #991b1b); color: #fff; padding: 20px; }
        .card-header h1 { font-size: 24px; display: flex; align-items: center; gap: 12px; }
        .card-header p { margin-top: 8px; font-size: 14px; color: #fecaca; }
        .card-body { padding: 30px; }
        
        .warning-box { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 24px; }
        .warning-box h3 { color: #92400e; font-size: 16px; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .warning-box ul { margin-left: 20px; color: #78350f; }
        .warning-box li { margin: 6px 0; }
        
        .counts-table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        .counts-table th, .counts-table td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        .counts-table th { background: #f1f5f9; font-weight: 600; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; }
        .counts-table td { font-size: 14px; color: #334155; }
        .count-badge { display: inline-block; background: #dbeafe; color: #1e40af; padding: 4px 12px; border-radius: 999px; font-weight: 700; font-size: 13px; }
        .count-badge.zero { background: #dcfce7; color: #166534; }
        
        .confirm-section { background: #fee2e2; border: 2px solid #ef4444; border-radius: 8px; padding: 20px; margin: 24px 0; }
        .confirm-section h3 { color: #991b1b; font-size: 16px; margin-bottom: 12px; }
        .confirm-input { width: 100%; padding: 10px; border: 2px solid #dc2626; border-radius: 6px; font-size: 14px; margin: 12px 0; font-family: monospace; }
        
        .btn { display: inline-block; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; text-decoration: none; transition: all 0.2s; }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #991b1b; }
        .btn-danger:disabled { background: #cbd5e1; cursor: not-allowed; }
        .btn-secondary { background: #64748b; color: #fff; margin-right: 8px; }
        .btn-secondary:hover { background: #475569; }
        
        .success-box { background: #dcfce7; border: 2px solid #16a34a; border-radius: 8px; padding: 20px; margin-bottom: 24px; }
        .success-box h3 { color: #166534; font-size: 18px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
        .success-list { list-style: none; margin: 12px 0; }
        .success-list li { padding: 8px 0; color: #166534; font-size: 14px; }
        .success-list li strong { color: #15803d; }
        
        .error-box { background: #fee2e2; border: 2px solid #ef4444; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .error-box h4 { color: #991b1b; margin-bottom: 8px; }
        .error-box ul { margin-left: 20px; color: #7f1d1d; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (isset($success) && $success): ?>
        <div class="card">
            <div class="card-header" style="background: linear-gradient(135deg, #16a34a, #15803d);">
                <h1><i class="fas fa-check-circle"></i> All Pump Records Deleted Successfully!</h1>
            </div>
            <div class="card-body">
                <div class="success-box">
                    <h3><i class="fas fa-trash-alt"></i> Deletion Complete</h3>
                    <ul class="success-list">
                        <?php foreach ($deleted_counts as $table => $count): ?>
                        <li><strong><?php echo $table; ?>:</strong> <?php echo $count; ?> records deleted</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <?php if (!empty($errors)): ?>
                <div class="error-box">
                    <h4><i class="fas fa-exclamation-triangle"></i> Some Warnings:</h4>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div style="margin-top: 24px;">
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-home"></i> Back to Dashboard</a>
                    <a href="delete_all_pumps.php" class="btn btn-secondary"><i class="fas fa-sync"></i> Refresh Page</a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h1><i class="fas fa-exclamation-triangle"></i> Delete All Pump Records</h1>
                <p>This action will permanently delete all pump-related data from the database.</p>
            </div>
            <div class="card-body">
                <div class="warning-box">
                    <h3><i class="fas fa-exclamation-circle"></i> WARNING: This action cannot be undone!</h3>
                    <p style="margin: 8px 0; color: #78350f;">The following tables will be completely emptied:</p>
                    <ul>
                        <li><strong>fuel_pumps</strong> - All pump records (PUMP-9, PUMP-10, etc.)</li>
                        <li><strong>pump_calibration_history</strong> - All calibration history</li>
                        <li><strong>fuel_calibration_records</strong> - All calibration records</li>
                        <li><strong>calibration_logs</strong> - All calibration logs</li>
                        <li><strong>pump_configuration</strong> - All pump configurations</li>
                    </ul>
                </div>
                
                <h3 style="margin: 24px 0 12px; color: #334155; font-size: 16px;">Current Record Counts:</h3>
                <table class="counts-table">
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Current Records</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($counts as $table => $count): ?>
                        <tr>
                            <td><code><?php echo $table; ?></code></td>
                            <td>
                                <span class="count-badge <?php echo $count == 0 ? 'zero' : ''; ?>">
                                    <?php echo $count; ?> records
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <form method="POST" id="deleteForm">
                    <div class="confirm-section">
                        <h3><i class="fas fa-keyboard"></i> Confirmation Required</h3>
                        <p style="color: #7f1d1d; margin-bottom: 12px;">Type <strong>YES_DELETE_ALL</strong> to confirm deletion:</p>
                        <input type="text" name="confirm_delete" id="confirmInput" class="confirm-input" placeholder="Type: YES_DELETE_ALL" autocomplete="off" required>
                    </div>
                    
                    <div style="display: flex; gap: 12px; margin-top: 24px;">
                        <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Cancel</a>
                        <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                            <i class="fas fa-trash-alt"></i> Delete All Pump Records
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 20px; color: #64748b; font-size: 13px;">
            <p>Logged in as: <strong><?php echo htmlspecialchars($me['username'] ?? 'Unknown'); ?></strong> (<?php echo htmlspecialchars($role); ?>)</p>
        </div>
    </div>
    
    <script>
    // Enable delete button only when correct text is typed
    const input = document.getElementById('confirmInput');
    const btn = document.getElementById('deleteBtn');
    
    if (input && btn) {
        input.addEventListener('input', function() {
            if (this.value === 'YES_DELETE_ALL') {
                btn.disabled = false;
                btn.style.opacity = '1';
            } else {
                btn.disabled = true;
                btn.style.opacity = '0.5';
            }
        });
        
        // Final confirmation before submit
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            if (!confirm('Are you absolutely sure you want to delete ALL pump records? This cannot be undone!')) {
                e.preventDefault();
            }
        });
    }
    </script>
</body>
</html>
