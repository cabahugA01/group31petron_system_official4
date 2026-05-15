<?php
/**
 * FUEL SHIFT-END PROCESSING
 * Manager page for processing shift-end pump readings
 * 
 * Workflow:
 * 1. Display all pending pump readings for a specific shift
 * 2. Allow manager to review and process (approve all at once)
 * 3. On processing, automatic stock deduction from fuel_inventory
 * 4. Generate shift summary report
 */

$page_id = 'fuel_shift_processing';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/fuel_shift_operations.php';

require_login();

$me = current_user();
$isSuper = ($me['role'] ?? '') === 'superadmin';
$isManager = in_array($me['role'], ['manager', 'admin', 'superadmin']);

// Verify authorization
if (!$isManager) {
    header('Location: dashboard.php?error=unauthorized');
    exit;
}

$station_id = $isSuper ? ($_GET['station'] ?? '') : user_station_id();
$shift = $_GET['shift'] ?? 'first'; // Default to morning shift
$msg = '';

// Get stations for dropdown if superadmin
$stations = [];
if ($isSuper) {
    try {
        $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}
}

// Initialize operations class
$shiftOps = new FuelShiftOperations($pdo, $me);

// Handle shift processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'process_shift') {
    if ($station_id && $shift) {
        $result = $shiftOps->process_shift_end($station_id, $shift, $me['id']);
        if ($result['success']) {
            $msg = "✅ {$result['message']} ({$result['readings_processed']} readings processed)";
            // Refresh to show summary
            $_GET['view'] = 'summary';
        } else {
            $msg = "❌ {$result['message']}";
        }
    }
}

// Get pending readings for selected shift
$pending_readings = [];
$shift_summary = [];
if ($station_id && $shift) {
    $pending_readings = $shiftOps->get_pending_readings($station_id, $shift);
    
    // Also get approved readings for summary
    if ($_GET['view'] === 'summary') {
        $shift_summary = $shiftOps->get_shift_summary($station_id, $shift);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift-End Processing</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .controls {
            background: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .controls select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .readings-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .reading-card {
            border-bottom: 1px solid #eee;
            padding: 15px;
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: center;
        }
        
        .reading-card:hover {
            background: #f9f9f9;
        }
        
        .reading-card:last-child {
            border-bottom: none;
        }
        
        .reading-detail {
            padding: 10px;
            background: #f5f5f5;
            border-radius: 4px;
        }
        
        .reading-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .reading-value {
            font-size: 14px;
            color: #333;
            font-weight: bold;
        }
        
        .sales-value {
            color: #dc3545;
            font-size: 16px;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-process {
            background: #28a745;
            color: white;
            padding: 15px 30px;
            font-size: 16px;
        }
        
        .btn-process:hover {
            background: #218838;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #5a6268;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .summary-card {
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .summary-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .summary-value {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>⏱️ Shift-End Processing</h1>
            <p>Process pump readings and deduct sales from fuel inventory</p>
        </div>
        
        <!-- Controls -->
        <div class="controls">
            <?php if ($isSuper): ?>
                <select id="stationFilter" onchange="location.href='?station=' + this.value + '&shift=<?= htmlspecialchars($shift) ?>'">
                    <option value="">-- Select Station --</option>
                    <?php foreach ($stations as $id => $name): ?>
                        <option value="<?= htmlspecialchars($id) ?>" <?= $station_id === $id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            
            <select id="shiftFilter" onchange="location.href='?shift=' + this.value<?= $isSuper ? '&station=' . htmlspecialchars($station_id) : '' ?>">
                <option value="first" <?= $shift === 'first' ? 'selected' : '' ?>>Morning Shift</option>
                <option value="second" <?= $shift === 'second' ? 'selected' : '' ?>>Afternoon Shift</option>
                <option value="second" <?= $shift === 'second' ? 'selected' : '' ?>>Evening Shift</option>
            </select>
            
            <a href="fuel_management.php" style="padding: 8px 16px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; font-size: 14px; margin-left: auto;">← Back</a>
        </div>
        
        <!-- Messages -->
        <?php if ($msg): ?>
            <div class="alert <?= strpos($msg, '✅') === 0 ? 'alert-success' : 'alert-error' ?>">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <!-- Processing Form -->
        <form method="POST">
            <input type="hidden" name="action" value="process_shift">
            
            <!-- Pending Readings -->
            <?php if (!empty($pending_readings)): ?>
                <div class="alert alert-info">
                    ℹ️ Found <strong><?= count($pending_readings) ?></strong> pending pump reading(s) for the <?= htmlspecialchars($shift) ?> shift.
                    Review and click "Process Shift-End" to approve all and deduct sales from inventory.
                </div>
                
                <div class="readings-container" style="margin-bottom: 20px;">
                    <div style="padding: 15px; background: #f5f5f5; border-bottom: 2px solid #ddd; display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 15px; font-weight: bold; font-size: 12px; color: #666;">
                        <div>Pump #</div>
                        <div>Previous</div>
                        <div>Current</div>
                        <div>Sales (Liters)</div>
                        <div>Status</div>
                    </div>
                    
                    <?php foreach ($pending_readings as $reading): ?>
                        <div class="reading-card">
                            <div class="reading-detail">
                                <div class="reading-label">Pump Number</div>
                                <div class="reading-value">#<?= htmlspecialchars($reading['pump_number'] ?? 'Unknown') ?></div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Previous Reading</div>
                                <div class="reading-value"><?= number_format($reading['previous_reading'], 2) ?></div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Current Reading</div>
                                <div class="reading-value"><?= number_format($reading['current_reading'], 2) ?></div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Sales Liters</div>
                                <div class="reading-value sales-value">
                                    <?= number_format($reading['current_reading'] - $reading['previous_reading'], 2) ?>L
                                </div>
                            </div>
                            
                            <span style="color: #999; font-size: 12px;">PENDING</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <button type="submit" class="btn btn-process">✓ Process Shift-End (Approve All)</button>
            
            <?php elseif ($_GET['view'] === 'summary' && !empty($shift_summary)): ?>
                <!-- Summary View -->
                <div class="alert alert-success">
                    ✅ Shift processing complete! All <?= count($shift_summary) ?> readings have been approved and stock has been updated.
                </div>
                
                <div class="readings-container">
                    <div style="padding: 15px; background: #f5f5f5; border-bottom: 2px solid #ddd; display: grid; grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 15px; font-weight: bold; font-size: 12px; color: #666;">
                        <div>Pump #</div>
                        <div>Sales</div>
                        <div>Stock Before</div>
                        <div>Stock After</div>
                        <div>Status</div>
                    </div>
                    
                    <?php foreach ($shift_summary as $reading): 
                        $sales = $reading['current_reading'] - $reading['previous_reading'];
                    ?>
                        <div class="reading-card">
                            <div class="reading-detail">
                                <div class="reading-label">Pump Number</div>
                                <div class="reading-value">#<?= htmlspecialchars($reading['pump_number'] ?? 'Unknown') ?></div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Sales (Liters)</div>
                                <div class="reading-value sales-value"><?= number_format($sales, 2) ?>L</div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Stock Before</div>
                                <div class="reading-value"><?= number_format($reading['total_stock_change'], 2) ?? 'N/A' ?></div>
                            </div>
                            
                            <div class="reading-detail">
                                <div class="reading-label">Stock After</div>
                                <div class="reading-value">--</div>
                            </div>
                            
                            <span style="color: #28a745; font-size: 12px; font-weight: bold;">✓ APPROVED</span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="summary-grid">
                    <div class="summary-card">
                        <div class="summary-label">Total Readings Processed</div>
                        <div class="summary-value"><?= count($shift_summary) ?></div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Total Sales Deducted</div>
                        <div class="summary-value" style="color: #dc3545;">
                            <?php 
                                $total_sales = 0;
                                foreach ($shift_summary as $reading) {
                                    $total_sales += ($reading['current_reading'] - $reading['previous_reading']);
                                }
                                echo number_format($total_sales, 2) . 'L';
                            ?>
                        </div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-label">Processed By</div>
                        <div class="summary-value" style="font-size: 16px; color: #333;"><?= htmlspecialchars($me['name'] ?? 'Manager') ?></div>
                    </div>
                </div>
                
                <div style="text-align: center; margin-top: 30px;">
                    <a href="fuel_management.php" class="btn btn-back" style="padding: 10px 20px; display: inline-block;">← Back to Fuel Management</a>
                </div>
            
            <?php else: ?>
                <!-- No Pending Readings -->
                <div class="alert alert-info">
                    ℹ️ No pending pump readings for the <?= htmlspecialchars($shift) ?> shift. All readings have been processed or none exist yet.
                </div>
                
                <div class="readings-container">
                    <div class="empty-state">
                        <div class="empty-state-icon">✓</div>
                        <h3>All Set!</h3>
                        <p>There are no pending readings to process for the selected shift.</p>
                    </div>
                </div>
            <?php endif; ?>
        </form>
    </div>
</body>
</html>
