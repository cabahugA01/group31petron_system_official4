<?php
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/inventory_automation.php';

require_login();
$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

// Debug: Log who is logged in
error_log("DEBUG: Current user - ID: ".$u['id'].", Username: ".$u['username'].", Role: ".$role.", Pass: ".substr($u['password_hash'] ?? '', 0, 20));

if (!has_role_at_least('admin')) {
    die("Access Denied");
}

$msg = '';
$selected_recon_id = null;
$selected_recon = null;

// Get manager-approved reconciliations (status='Verified')
$pending_finalization = [];
try {
    $stmt = $pdo->prepare("
        SELECT fr.*, ft.name as fuel_type_name
        FROM fuel_reconciliation fr
        LEFT JOIN fuel_types ft ON fr.fuel_type_id = ft.id
        WHERE fr.station_id = ? AND fr.status = 'Verified'
        ORDER BY fr.reconciliation_date DESC
    ");
    $stmt->execute([$station_id]);
    $pending_finalization = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $msg = "❌ Error loading records: " . $e->getMessage();
}

// Handle selection of a record to finalize
if ($_GET['select'] ?? false) {
    $selected_recon_id = (int)$_GET['select'];
    $selected_recon = array_filter($pending_finalization, fn($r) => $r['id'] == $selected_recon_id)[0] ?? null;
}

// STEP 3: Finalize with password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'finalize_reconciliation') {
        $recon_id = (int)($_POST['recon_id'] ?? 0);
        $physical_stock = (float)($_POST['physical_stock'] ?? 0);
        $admin_password = $_POST['admin_password'] ?? '';
        $admin_notes = $_POST['admin_notes'] ?? '';
        
        // Debug
        error_log("DEBUG: recon_id=$recon_id, physical_stock=$physical_stock, password_len=".strlen($admin_password));
        error_log("DEBUG: user_id=".$u['id'].", stored_pass=".substr($u['password_hash'] ?? '', 0, 10));
        
        if ($recon_id === 0) {
            $msg = "❌ No reconciliation selected";
        } elseif ($physical_stock <= 0) {
            $msg = "❌ Please enter a valid physical stock value (greater than 0)";
        } elseif (empty($admin_password)) {
            $msg = "❌ Admin password required to finalize";
        } else {
            // Verify password: check session, then check database directly
            $pwd_from_session = $u['password_hash'] ?? '';
            
            // Also get password directly from database  
            $db_pwd_stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $db_pwd_stmt->execute([$u['id']]);
            $db_pwd = $db_pwd_stmt->fetchColumn();
            
            $password_match = ($admin_password === $pwd_from_session) || ($admin_password === $db_pwd);
            
            error_log("DEBUG: Password check - entered: '$admin_password', session: '".substr($pwd_from_session, 0, 10)."', db: '".substr($db_pwd, 0, 10)."', match: ".($password_match ? 'YES' : 'NO'));
            
            if (!$password_match) {
                $msg = "❌ Incorrect password";
            } else {
                error_log("DEBUG: Password verification PASSED");
                try {
                    // Get the reconciliation record
                $stmt = $pdo->prepare("SELECT * FROM fuel_reconciliation WHERE id = ? AND station_id = ?");
                $stmt->execute([$recon_id, $station_id]);
                $recon = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$recon) {
                    $msg = "❌ Reconciliation not found";
                } else {
                    // Calculate variance
                    $system_stock = ($recon['present_reading'] - $recon['previous_reading'] - ($recon['calibration'] ?? 0));
                    $variance_liters = $physical_stock - $system_stock;
                    $variance_percent = $system_stock != 0 ? ($variance_liters / $system_stock) * 100 : 0;
                    $price_per_liter = (float)($recon['price_per_liter'] ?? 0);
                    $variance_currency = $variance_liters * $price_per_liter;
                    
                    // Update record
                    $upd = $pdo->prepare("
                        UPDATE fuel_reconciliation 
                        SET physical_stock = ?, variance_liters = ?, variance_percent = ?, 
                            status = 'finalized', verified_by = ?, verified_at = NOW(), notes = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $physical_stock, 
                        $variance_liters, 
                        $variance_percent, 
                        $u['id'], 
                        $admin_notes,
                        $recon_id
                    ]);
                    
                    // Update inventory in real-time to match physical stock
                    $stock_adjustment = $physical_stock - ($recon['present_reading'] - $recon['previous_reading'] - ($recon['calibration'] ?? 0));
                    $stock_result = recordStockMovement(
                        $pdo,
                        $station_id,
                        $recon['fuel_type_id'],
                        $stock_adjustment,
                        'reconciliation_sync',
                        'fuel_reconciliation',
                        $recon_id,
                        $u['id'],
                        "Reconciliation #$recon_id finalized - adjusted to physical: {$physical_stock}L"
                    );
                    
                    // Record daily closing stock for next day's opening balance
                    recordDailyClosingStock(
                        $pdo,
                        $station_id,
                        $recon['fuel_type_id'],
                        $physical_stock,
                        $me['id'],
                        'Shift: ' . date('H:i', strtotime($recon['reconciliation_date'])),
                        date('Y-m-d'),
                        $u['id']
                    );
                    
                    log_activity($pdo, $u['id'], 'Reconciliation Finalized', 
                        "ID: $recon_id | Fuel: {$recon['fuel_type_id']} | Physical: {$physical_stock}L | Variance: {$variance_liters}L | LOCKED");
                    
                    $msg = "✅ Reconciliation finalized and LOCKED!";
                    $selected_recon_id = null;
                    $selected_recon = null;
                    
                    // Refresh list
                    $stmt = $pdo->prepare("
                        SELECT fr.*, ft.name as fuel_type_name
                        FROM fuel_reconciliation fr
                        LEFT JOIN fuel_types ft ON fr.fuel_type_id = ft.id
                        WHERE fr.station_id = ? AND fr.status = 'Verified'
                        ORDER BY fr.reconciliation_date DESC
                    ");
                    $stmt->execute([$station_id]);
                    $pending_finalization = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
            }
        }
    }
}

// Get finalized reconciliations
$finalized = [];
try {
    $stmt = $pdo->prepare("
        SELECT fr.*, ft.name as fuel_type_name
        FROM fuel_reconciliation fr
        LEFT JOIN fuel_types ft ON fr.fuel_type_id = ft.id
        WHERE fr.station_id = ? AND fr.status = 'finalized'
        ORDER BY fr.verified_at DESC
        LIMIT 20
    ");
    $stmt->execute([$station_id]);
    $finalized = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Handle error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Fuel Reconciliation - Finalization</title>
    <link rel="stylesheet" href="../assets/style.css">
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .page-header { background: linear-gradient(135deg, #003d7a 0%, #002d5c 100%); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; }
        .page-header h1 { font-size: 2.5em; margin: 0; color: white !important; }
        .page-header p { margin: 5px 0 0 0; opacity: 0.9; color: white !important; }
        .card { background: white; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card-header { background: #003d7a; color: white; padding: 20px; border-radius: 8px 8px 0 0; display: flex; align-items: center; gap: 10px; }
        .card-header h2 { margin: 0; font-size: 1.3em; color: white !important; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; color: #333; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1em;
            box-sizing: border-box;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .btn { padding: 12px 24px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-group { 
            display: flex; 
            gap: 10px; 
            margin-top: 20px; 
            flex-wrap: wrap;
            position: relative !important;
            z-index: 999;
            visibility: visible !important;
            display: flex !important;
        }
        .workflow-steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .step { background: #f8f9fa; padding: 15px; border-left: 4px solid #667eea; border-radius: 4px; }
        .step-number { display: inline-block; background: #667eea; color: white; width: 30px; height: 30px; border-radius: 50%; text-align: center; line-height: 30px; font-weight: bold; margin-right: 10px; }
        .step h4 { margin: 10px 0 5px 0; }
        .step p { margin: 0; color: #666; font-size: 0.9em; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .variance-display { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); 
            gap: 15px; 
            padding: 15px;
        }
        .variance-item { 
            background: #f8f9fa; 
            padding: 12px; 
            border-radius: 4px; 
            text-align: center; 
            border: 2px solid #003d7a;
        }
        .variance-item .label { display: block; color: #666; font-size: 0.85em; margin-bottom: 5px; }
        .variance-item .value { display: block; font-size: 1.3em; font-weight: bold; color: #667eea; }
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table th, .table td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        .table th { background: #f8f9fa; font-weight: 600; }
        .table tr:hover { background: #f8f9fa; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state i { font-size: 3em; margin-bottom: 20px; }
        .password-section { background: #fff3cd; border-left: 4px solid #ffc107; padding: 20px; border-radius: 4px; margin: 20px 0; }
        .password-section h4 { color: #856404; margin-top: 0; }
        .recon-list { display: grid; gap: 15px; }
        .recon-card { background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 4px solid #667eea; cursor: pointer; transition: all 0.3s; }
        .recon-card:hover { background: #e9ecef; border-left-color: #5568d3; }
        .recon-card.selected { background: #d1ecf1; border-left-color: #0c5460; }
        .recon-card-title { font-weight: 600; margin-bottom: 5px; }
        .recon-card-details { font-size: 0.9em; color: #666; margin-bottom: 3px; }
        .card-body { padding: 25px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/header.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-lock"></i> Fuel Reconciliation Finalization</h1>
            <p>Admin/Owner Station-Level Reconciliation Workflow</p>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($msg)): ?>
            <div class="alert <?php echo strpos($msg, '✅') === 0 ? 'alert-success' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($msg); ?>
            </div>
        <?php endif; ?>
        
        <!-- Workflow Steps Overview -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-tasks"></i>
                <h2>Reconciliation Workflow</h2>
            </div>
            <div class="card-body">
                            </div>
        </div>
        
        <!-- STEP 1-2: Pending Manager Approvals -->
        <div class="card">
            <div class="card-header">
                <span style="background: white; color: #667eea; border: 2px solid #667eea; padding: 8px 16px; border-radius: 50%; font-weight: bold;">Select Record</span>
                <h2>Select Manager-Approved Record</h2>
            </div>
            <div class="card-body">
                <?php if (empty($pending_finalization)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p><strong>No records waiting for finalization</strong></p>
                        <p style="font-size: 0.95em; color: #999;">Manager-approved reconciliations will appear here for you to finalize.</p>
                    </div>
                <?php else: ?>
                    <div class="recon-list">
                        <?php foreach ($pending_finalization as $recon): ?>
                            <div class="recon-card <?php echo $selected_recon_id == $recon['id'] ? 'selected' : ''; ?>" 
                                 onclick="window.location.href='?select=<?php echo $recon['id']; ?>'">
                                <div class="recon-card-title">
                                    <i class="fas fa-gas-pump"></i> 
                                    <?php echo htmlspecialchars($recon['fuel_type_name'] ?? 'Unknown'); ?>
                                </div>
                                <div class="recon-card-details">
                                    📅 Date: <?php echo date('M d, Y', strtotime($recon['reconciliation_date'])); ?>
                                </div>
                                <div class="recon-card-details">
                                    📊 System Stock: <?php echo number_format($recon['present_reading'] - $recon['previous_reading'] - ($recon['calibration'] ?? 0), 2); ?>L
                                </div>
                                <div class="recon-card-details">
                                    ✓ Approved by Manager
                                </div>
                                <span class="badge badge-info" style="margin-top: 10px;">Ready for Admin</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- STEP 3: Finalize with Password (Only if selected) -->
        <?php if ($selected_recon): ?>
            <div class="card">
                <div class="card-header">
                    <span style="background: white; color: #667eea; border: 2px solid #667eea; padding: 8px 16px; border-radius: 50%; font-weight: bold;">Finalize</span>
                    <h2>Finalize & Lock Report</h2>
                </div>
                <div class="card-body">
                    <div style="background: #e8f1f8; border-left: 4px solid #003d7a; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                        <strong>Selected Record:</strong> <?php echo htmlspecialchars($selected_recon['fuel_type_name']); ?> 
                        (<?php echo date('M d, Y', strtotime($selected_recon['reconciliation_date'])); ?>)
                    </div>
                    
                    <div class="password-section">
                        <h4><i class="fas fa-lock"></i> Password Protection Required</h4>
                        <p>Enter your admin password to lock this reconciliation report. Once locked, no changes can be made.</p>
                    </div>
                    
                    <!-- System Computation Display -->
                    <?php 
                    $system_stock = $selected_recon['present_reading'] - $selected_recon['previous_reading'] - ($selected_recon['calibration'] ?? 0);
                    ?>
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 4px; margin-bottom: 20px;">
                        <h4 style="margin-top: 0;"><i class="fas fa-calculator"></i> System Computation</h4>
                        <div class="variance-display">
                            <div class="variance-item">
                                <span class="label">Previous Reading</span>
                                <span class="value"><?php echo number_format($selected_recon['previous_reading'] ?? 0, 2); ?>L</span>
                            </div>
                            <div class="variance-item">
                                <span class="label">Present Reading</span>
                                <span class="value"><?php echo number_format($selected_recon['present_reading'] ?? 0, 2); ?>L</span>
                            </div>
                            <div class="variance-item">
                                <span class="label">Calibration</span>
                                <span class="value"><?php echo number_format($selected_recon['calibration'] ?? 0, 2); ?>L</span>
                            </div>
                            <div class="variance-item">
                                <span class="label">System Stock</span>
                                <span class="value"><?php echo number_format($system_stock, 2); ?>L</span>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" enctype="application/x-www-form-urlencoded">
                        <input type="hidden" name="recon_id" value="<?php echo htmlspecialchars($selected_recon['id'] ?? ''); ?>">
                        <input type="hidden" name="action" value="finalize_reconciliation">
                        
                        <div class="form-group">
                            <label for="physical_stock">
                                <i class="fas fa-water"></i> Physical Stock (Liters from Gauge)
                            </label>
                            <input 
                                type="number" 
                                name="physical_stock" 
                                id="physical_stock" 
                                step="0.01" 
                                min="0"
                                placeholder="Enter liters from fuel gauge"
                                required>
                        </div>
                        
                        <div class="form-group">
                            <label for="admin_password">
                                <i class="fas fa-key"></i> Your Admin Password
                            </label>
                            <input 
                                type="password" 
                                name="admin_password" 
                                id="admin_password" 
                                placeholder="Enter your password"
                                required
                                autocomplete="new-password"
                                spellcheck="false">
                        </div>
                        
                        <div class="form-group">
                            <label for="admin_notes">
                                <i class="fas fa-sticky-note"></i> Optional Notes
                            </label>
                            <textarea 
                                name="admin_notes" 
                                id="admin_notes" 
                                placeholder="Add any remarks or observations..."></textarea>
                        </div>
                        
                        <button type="submit" style="width: 100% !important; padding: 20px !important; margin-top: 30px !important; background: #003d7a !important; color: white !important; border: none !important; border-radius: 4px !important; font-weight: bold !important; font-size: 18px !important; cursor: pointer !important; display: block !important;">
                            🔒 FINALIZE & LOCK REPORT
                        </button>
                        
                        <a href="?" style="width: 100% !important; padding: 20px !important; margin-top: 10px !important; background: #6c757d !important; color: white !important; border: none !important; border-radius: 4px !important; font-weight: bold !important; font-size: 18px !important; text-align: center !important; display: block !important; text-decoration: none !important;">
                            ✕ CANCEL
                        </a>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- STEP 4: Generate Reports -->
        <div class="card">
            <div class="card-header">
                <span style="background: white; color: #667eea; border: 2px solid #667eea; padding: 8px 16px; border-radius: 50%; font-weight: bold;">Export</span>
                <h2>Generate Reports - Finalized Records</h2>
            </div>
            <div class="card-body">
                <?php if (empty($finalized)): ?>
                    <div class="empty-state">
                        <i class="fas fa-file-export"></i>
                        <p>No finalized reconciliations to export yet.</p>
                        <p style="font-size: 0.9em;">Complete the finalization process above to generate exportable records.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Fuel Type</th>
                                <th>Physical Stock</th>
                                <th>Variance (L)</th>
                                <th>Variance (%)</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($finalized as $recon): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($recon['verified_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($recon['fuel_type_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo number_format($recon['physical_stock'] ?? 0, 2); ?>L</td>
                                    <td><?php echo number_format($recon['variance_liters'] ?? 0, 2); ?>L</td>
                                    <td><?php echo number_format($recon['variance_percent'] ?? 0, 2); ?>%</td>
                                    <td><span class="badge badge-success"><i class="fas fa-lock"></i> LOCKED</span></td>
                                    <td>
                                        <a href="fuel_reconciliation_export.php?id=<?php echo $recon['id']; ?>&format=excel" class="btn btn-primary" style="padding: 6px 12px; font-size: 0.9em;">
                                            <i class="fas fa-file-excel"></i> Excel
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
    </div>
    
    <?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
