<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

require_login();
$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// Only managers and owners can access this page
if (!in_array($role, ['manager', 'owner', 'superadmin'], true)) {
    $_SESSION['error'] = 'Access denied. Manager/Owner role required.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    unset($_SESSION['success']); 
}

if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    unset($_SESSION['error']); 
}

// Get all fuel types with calibration data - ensure all fuel types are included
$stmt = $pdo->prepare("
    SELECT 
        fi.fuel_type,
        COALESCE(fi.current_level, 10000) AS current_level,
        COALESCE(fi.latest_calibration, 2.0) AS latest_calibration,
        COALESCE(fi.price_per_liter, ip.unit_cost, 50.00) AS price_per_liter,
        COALESCE(fi.last_updated, NOW()) AS last_updated
    FROM fuel_inventory fi
    LEFT JOIN inventory_products ip ON ip.product_name = fi.fuel_type AND ip.category = 'Fuel'
    WHERE fi.station_id = ?
    UNION
    SELECT 
        ip.product_name AS fuel_type,
        10000 AS current_level,
        2.0 AS latest_calibration,
        ip.unit_cost AS price_per_liter,
        NOW() AS last_updated
    FROM inventory_products ip
    WHERE ip.category = 'Fuel' 
    AND ip.product_name NOT IN (
        SELECT fuel_type FROM fuel_inventory WHERE station_id = ?
    )
    ORDER BY fuel_type ASC
");
$stmt->execute([$station_id, $station_id]);
$fuel_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle calibration update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_calibration'])) {
    try {
        $fuel_type = trim($_POST['fuel_type'] ?? '');
        $new_calibration = posted_decimal('new_calibration');
        
        if (empty($fuel_type) || $new_calibration === '') {
            throw new Exception('Fuel type and calibration are required.');
        }
        
        $pdo->beginTransaction();
        
        // Check if fuel exists in fuel_inventory
        $stmt = $pdo->prepare("
            SELECT id FROM fuel_inventory 
            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
        ");
        $stmt->execute([$station_id, $fuel_type]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing calibration
            $stmt = $pdo->prepare("
                UPDATE fuel_inventory 
                SET latest_calibration = ?, last_updated = NOW()
                WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
            ");
            $stmt->execute([$new_calibration, $station_id, $fuel_type]);
        } else {
            // Insert new fuel inventory record
            $stmt = $pdo->prepare("
                INSERT INTO fuel_inventory (
                    station_id, fuel_type, current_level, latest_calibration, 
                    price_per_liter, last_updated, status
                ) VALUES (?, ?, ?, ?, ?, NOW(), 'Normal')
            ");
            $stmt->execute([
                $station_id, 
                $fuel_type, 
                10000, // Default stock level
                $new_calibration, 
                50.00 // Default price
            ]);
        }
        
        // Log calibration update
        $audit_data = [
            'fuel_type' => $fuel_type,
            'old_calibration' => $fuel_types[array_search($fuel_type, array_column($fuel_types, 'fuel_type'))]['latest_calibration'] ?? 0,
            'new_calibration' => $new_calibration,
            'updated_by' => $me['name'] ?? $me['username']
        ];
        
        $stmt = $pdo->prepare("
            INSERT INTO fuel_calibration_log (
                station_id, fuel_type, old_calibration, new_calibration, updated_by, timestamp
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $station_id,
            $fuel_type,
            $fuel_types[array_search($fuel_type, array_column($fuel_types, 'fuel_type'))]['latest_calibration'] ?? 0,
            $new_calibration,
            $me['name'] ?? $me['username']
        ]);
        
        if (function_exists('log_activity')) {
            log_activity(
                $pdo,
                $me['id'],
                'Calibration Updated',
                "Updated calibration for {$fuel_type} from {$audit_data['old_calibration']} to {$new_calibration}"
            );
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "Calibration updated successfully for {$fuel_type}!";
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error'] = 'Error updating calibration: ' . $e->getMessage();
    }
    
    header('Location: pump_master.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pump Master - Calibration Management</title>
    <link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: #003d7a;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        
        .calibration-form {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group select, .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
        }
        
        .btn {
            background: #003d7a;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
        }
        
        .btn:hover {
            background: #0056b3;
        }
        
        .fuel-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .fuel-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
        }
        
        .fuel-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .edit-btn {
            background: #28a745;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .edit-btn:hover {
            background: #218838;
        }
        
        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-gas-pump"></i> Pump Master - Calibration Management</h1>
        </div>
        
        <?php
// Toast bridge: convert $msg/$msg_type to SESSION for flash_toast
if (!empty($msg)) {
    if ($msg_type === 'success') $_SESSION['success'] = $msg;
    else $_SESSION['error'] = $msg;
    $msg = ''; $msg_type = '';
}
require __DIR__ . '/../partials/flash_toast.php';
?>

        
        <div class="calibration-form">
            <h2>Update Calibration</h2>
            <form method="post">
                <div class="form-group">
                    <label for="fuel_type">Fuel Type:</label>
                    <select name="fuel_type" id="fuel_type" required>
                        <option value="">Select fuel type</option>
                        <?php foreach ($fuel_types as $fuel): ?>
                            <option value="<?php echo htmlspecialchars($fuel['fuel_type']); ?>">
                                <?php echo htmlspecialchars($fuel['fuel_type']); ?>
                                (Current: <?php echo htmlspecialchars($fuel['latest_calibration']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="new_calibration">New Calibration Value:</label>
                    <input type="number" name="new_calibration" id="new_calibration" 
                           step="0.01" min="0" max="50" required>
                    <small>Enter new calibration value (0-50 liters)</small>
                </div>
                
                <button type="submit" class="btn" name="update_calibration">
                    <i class="fas fa-save"></i> Update Calibration
                </button>
            </form>
        </div>
        
        <div class="calibration-form">
            <h2>Current Calibration Values</h2>
            <table class="fuel-table">
                <thead>
                    <tr>
                        <th>Fuel Type</th>
                        <th>Available Stock</th>
                        <th>Current Calibration</th>
                        <th>Last Updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fuel_types as $fuel): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fuel['fuel_type']); ?></td>
                            <td><?php echo number_format($fuel['current_level'], 2); ?> L</td>
                            <td><?php echo htmlspecialchars($fuel['latest_calibration']); ?> L</td>
                            <td><?php echo htmlspecialchars($fuel['last_updated']); ?></td>
                            <td>
                                <?php if (in_array($role, ['manager', 'owner', 'superadmin'])): ?>
                                    <button class="edit-btn" onclick="editCalibration('<?php echo htmlspecialchars($fuel['fuel_type']); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
