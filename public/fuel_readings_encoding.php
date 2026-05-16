<?php
$page_id = 'fuel_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/classes/ShiftPeriodConfig.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? '');

// ── Module gate ───────────────────────────────────────────────
if (!in_array($role, ['superadmin','developer']) && !is_module_enabled('fuel_management')) {
    render_module_disabled_page('Fuel Management');
}

// Only staff and above can access fuel management
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied.';
    header('Location: dashboard.php');
    exit;
}

$msg = '';
$msg_type = '';
if (isset($_SESSION['success'])) { 
    $msg = $_SESSION['success']; 
    $msg_type = 'success';
    unset($_SESSION['success']); 
}
if (isset($_SESSION['error'])) { 
    $msg = $_SESSION['error']; 
    $msg_type = 'error';
    unset($_SESSION['error']); 
}

function fm_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

function fm_table_columns(PDO $pdo, string $tableName): array {
    if (!fm_table_exists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $tableName) . '`');
    return array_map(static function (array $row): string {
        return $row['Field'];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function fm_has_column(PDO $pdo, string $tableName, string $columnName): bool {
    return in_array($columnName, fm_table_columns($pdo, $tableName), true);
}

function fm_ensure_support_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_readings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pump_number VARCHAR(20) NOT NULL,
        fuel_type VARCHAR(100) NOT NULL,
        present_reading DECIMAL(10,2) NOT NULL,
        previous_reading DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        difference DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        shift_period VARCHAR(20) DEFAULT NULL,
        status VARCHAR(50) DEFAULT 'Pending Manager Validation',
        station_id INT NOT NULL,
        encoded_by INT DEFAULT NULL,
        encoded_at DATETIME NOT NULL,
        INDEX idx_fuel_readings_station_time (station_id, encoded_at),
        INDEX idx_fuel_readings_type (station_id, fuel_type),
        INDEX idx_fuel_readings_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add status column to existing tables if it doesn't exist
    $pdo->exec("ALTER TABLE fuel_readings 
                ADD COLUMN IF NOT EXISTS status VARCHAR(50) DEFAULT 'Pending Manager Validation'");
    $pdo->exec("ALTER TABLE fuel_readings 
                ADD INDEX IF NOT EXISTS idx_fuel_readings_status (status)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS calibration_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pump_number VARCHAR(20) NOT NULL,
        fuel_type VARCHAR(100) NOT NULL,
        calibration_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        shift_period VARCHAR(20) DEFAULT NULL,
        station_id INT NOT NULL,
        encoded_by INT DEFAULT NULL,
        encoded_at DATETIME NOT NULL,
        INDEX idx_calibration_logs_station_time (station_id, encoded_at),
        INDEX idx_calibration_logs_type (station_id, fuel_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_audit_trail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reading_id INT DEFAULT NULL,
        calibration_id INT DEFAULT NULL,
        action VARCHAR(50) NOT NULL,
        before_value DECIMAL(10,2) DEFAULT NULL,
        after_value DECIMAL(10,2) DEFAULT NULL,
        stock_before DECIMAL(10,2) DEFAULT NULL,
        stock_after DECIMAL(10,2) DEFAULT NULL,
        performed_by INT DEFAULT NULL,
        performed_at DATETIME NOT NULL,
        notes TEXT DEFAULT NULL,
        INDEX idx_fuel_audit_station_time (performed_by, performed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS low_stock_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL,
        fuel_type VARCHAR(100) NOT NULL,
        current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        alert_level VARCHAR(50) NOT NULL DEFAULT 'Warning',
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_low_stock_station_time (station_id, created_at),
        INDEX idx_low_stock_type (station_id, fuel_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function fm_resolve_pump_for_fuel_type(PDO $pdo, int $stationId, string $fuelType): ?array {
    if (fm_table_exists($pdo, 'fuel_pumps') && fm_table_exists($pdo, 'fuel_types') && fm_has_column($pdo, 'fuel_pumps', 'fuel_type_id')) {
        $stmt = $pdo->prepare(" 
            SELECT fp.pump_number, COALESCE(ft.name, ?) AS fuel_type
            FROM fuel_pumps fp
            LEFT JOIN fuel_types ft
                ON ft.id = fp.fuel_type_id
            WHERE fp.station_id = ?
              AND LOWER(TRIM(COALESCE(ft.name, ?))) = LOWER(TRIM(?))
            ORDER BY fp.pump_number ASC
            LIMIT 1
        ");
        $stmt->execute([$fuelType, $stationId, $fuelType, $fuelType]);
        $pump = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pump) {
            return $pump;
        }
    }

    if (fm_table_exists($pdo, 'fuel_pumps')) {
        $stmt = $pdo->prepare(" 
            SELECT pump_number
            FROM fuel_pumps
            WHERE station_id = ?
            ORDER BY pump_number ASC
            LIMIT 1
        ");
        $stmt->execute([$stationId]);
        $pump = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($pump) {
            $pump['fuel_type'] = $fuelType;
            return $pump;
        }
    }

    return null;
}

fm_ensure_support_tables($pdo);

// Initialize shift period configuration
$shift_config = getShiftPeriodConfig($pdo, $station_id);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'encode_fuel_reading':
                $fuel_type = trim($_POST['fuel_type'] ?? '');
                $pump_number = trim($_POST['pump_number'] ?? '');
                $present_reading = $_POST['present_reading'] ?? 0;
                $shift_period = $_POST['shift_period'] ?? '';
                
                try {
                    if ($fuel_type === '') {
                        $_SESSION['error'] = 'Invalid fuel type selected.';
                        header('Location: fuel_readings_encoding.php');
                        exit;
                    }
                    
                    if (!$shift_config->isValidShiftKey($shift_period)) {
                        $_SESSION['error'] = 'Invalid shift period selected.';
                        header('Location: fuel_readings_encoding.php');
                        exit;
                    }

                    $pump = fm_resolve_pump_for_fuel_type($pdo, (int)$station_id, $fuel_type);
                    
                    if (!$pump) {
                        $_SESSION['error'] = 'No pump is configured for the selected fuel type.';
                        header('Location: fuel_readings_encoding.php');
                        exit;
                    }
                    
                    $pump_number = $pump_number !== '' ? $pump_number : (string)$pump['pump_number'];
                    $fuel_type = $pump['fuel_type'];
                    
                    // Get last transaction reading for this fuel type
                    $stmt = $pdo->prepare("
                        SELECT present_reading
                        FROM fuel_transactions
                        WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                        ORDER BY transaction_date DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$station_id, $fuel_type]);
                    $last_reading = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$last_reading) {
                        $stmt = $pdo->prepare("
                            SELECT present_reading
                            FROM fuel_readings
                            WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))
                            ORDER BY encoded_at DESC
                            LIMIT 1
                        ");
                        $stmt->execute([$station_id, $fuel_type]);
                        $last_reading = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    $previous_reading = $last_reading['present_reading'] ?? 0;
                    
                    // Validation
                    if ($present_reading < $previous_reading) {
                        $_SESSION['error'] = 'Present reading cannot be less than previous reading (' . number_format($previous_reading, 2) . 'L).';
                        header('Location: fuel_readings_encoding.php');
                        exit;
                    }
                    
                    // System compute difference
                    $difference = $present_reading - $previous_reading;
                    
                    // Get current stock before update
                    $stmt = $pdo->prepare("SELECT current_level AS current_stock FROM fuel_inventory WHERE station_id = ? AND fuel_type = ?");
                    $stmt->execute([$station_id, $fuel_type]);
                    $stock_before = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stock_before_amount = $stock_before['current_stock'] ?? 0;
                    
                    // Validate stock availability
                    if ($difference > $stock_before_amount) {
                        $_SESSION['error'] = 'Insufficient stock! Available: ' . number_format($stock_before_amount, 2) . 'L, Requested: ' . number_format($difference, 2) . 'L';
                        header('Location: fuel_readings_encoding.php');
                        exit;
                    }
                    
                    // Create fuel reading record
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_readings (
                            pump_number, fuel_type, present_reading, previous_reading,
                            difference, shift_period, status, station_id, encoded_by, encoded_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 'Pending Manager Validation', ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $pump_number, $fuel_type, $present_reading, $previous_reading,
                        $difference, $shift_period, $station_id, $me['id']
                    ]);                    
                    $reading_id = $pdo->lastInsertId();
                    
                    // Update stock levels
                    $new_stock = $stock_before_amount - $difference;
                    $stmt = $pdo->prepare("
                        UPDATE fuel_inventory SET current_level = ?, last_updated = NOW() 
                        WHERE station_id = ? AND fuel_type = ?
                    ");
                    $stmt->execute([$new_stock, $station_id, $fuel_type]);
                    
                    // Check for low stock alert — pull thresholds from DB
                    $threshold = 500.0; // safe default
                    $critical_threshold = 100.0;
                    try {
                        $thr_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('low_stock_threshold','critical_stock_threshold')");
                        if ($thr_stmt) {
                            foreach ($thr_stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                                if ($k === 'low_stock_threshold'     && (float)$v > 0) $threshold          = (float)$v;
                                if ($k === 'critical_stock_threshold' && (float)$v > 0) $critical_threshold = (float)$v;
                            }
                        }
                    } catch (Exception $e) {}
                    // Also check fuel_inventory.reorder_threshold for this specific fuel type
                    if (fm_has_column($pdo, 'fuel_inventory', 'reorder_threshold')) {
                        $thr2 = $pdo->prepare("SELECT reorder_threshold FROM fuel_inventory WHERE station_id = ? AND fuel_type = ? AND reorder_threshold > 0 LIMIT 1");
                        $thr2->execute([$station_id, $fuel_type]);
                        $rt = $thr2->fetchColumn();
                        if ($rt !== false && (float)$rt > 0) $threshold = (float)$rt;
                    }

                    $low_stock_alert = false;
                    if ($new_stock < $threshold) {
                        $low_stock_alert = true;
                        $stmt = $pdo->prepare("
                            INSERT INTO low_stock_alerts (
                                station_id, fuel_type, current_stock, threshold, alert_level,
                                created_by, created_at
                            ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $alert_level = $new_stock < $critical_threshold ? 'Critical' : 'Warning';
                        $stmt->execute([$station_id, $fuel_type, $new_stock, $threshold, $alert_level, $me['id']]);
                    }
                    
                    // Create comprehensive audit trail
                    $stmt = $pdo->prepare("
                        INSERT INTO fuel_audit_trail (
                            reading_id, action, before_value, after_value, stock_before, stock_after,
                            performed_by, performed_at, notes
                        ) VALUES (?, 'FUEL_READING', ?, ?, ?, ?, ?, NOW(), ?)
                    ");
                    
                    $notes = "Pump #$pump_number, $fuel_type, Difference: " . number_format($difference, 2) . "L";
                    if ($low_stock_alert) {
                        $notes .= " [LOW STOCK ALERT: " . number_format($new_stock, 2) . "L]";
                    }
                    
                    $stmt->execute([
                        $reading_id, $previous_reading, $present_reading, 
                        $stock_before_amount, $new_stock, $me['id'], $notes
                    ]);
                    
                    $_SESSION['success'] = "✅ Fuel reading encoded successfully! 📊 Difference: " . number_format($difference, 2) . "L" . 
                                        ($low_stock_alert ? " ⚠️ [LOW STOCK ALERT!]" : "");

                    // ── Audit log ──
                    try {
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        $detail = "Fuel reading encoded | Pump #{$pump_number} | {$fuel_type} | Prev: " . number_format($previous_reading,2) . "L → Present: " . number_format($present_reading,2) . "L | Diff: " . number_format($difference,2) . "L | Shift: {$shift_period}" . ($low_stock_alert ? " | ⚠ LOW STOCK" : '');
                        $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'transaction', 'Create', ?, 'fuel_readings', ?, 'Success', ?, ?, NOW())")
                            ->execute([$me['id'], $detail, $reading_id, $ip, $ua]);
                    } catch (Exception $e) {}

                    header('Location: fuel_readings_encoding.php');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error encoding fuel reading: ' . $e->getMessage();
                    header('Location: fuel_readings_encoding.php');
                    exit;
                }
                break;
        }
    }
}

// Fetch data for forms
$fuel_readings = [];
$fuel_inventory = [];
$low_stock_alerts = [];
$fuel_options = [];

try {
    $fuelInventoryColumns = fm_table_columns($pdo, 'fuel_inventory');
    $reorderThresholdExpr = in_array('reorder_threshold', $fuelInventoryColumns, true)
        ? 'COALESCE(fi.reorder_threshold, 0)'
        : '0';
    $lastUpdatedExpr = in_array('last_updated', $fuelInventoryColumns, true)
        ? 'COALESCE(fi.last_updated, NOW())'
        : 'NOW()';

    // Get fuel types for this station
    $stmt = $pdo->prepare("
        SELECT 
            fi.fuel_type,
            COALESCE(MIN(fp_any.pump_number), fi.fuel_type) AS pump_number,
            COALESCE(last_tx.present_reading, 0) AS previous_reading,
            COALESCE(fi.current_level, 0) AS current_stock
        FROM fuel_inventory fi
        LEFT JOIN fuel_pumps fp_any
            ON fp_any.station_id = fi.station_id
        LEFT JOIN (
            SELECT ft.station_id, ft.fuel_type, ft.present_reading
            FROM fuel_transactions ft
            INNER JOIN (
                SELECT station_id, fuel_type, MAX(transaction_date) AS latest_transaction_date
                FROM fuel_transactions
                GROUP BY station_id, fuel_type
            )
                latest
                ON latest.station_id = ft.station_id
               AND LOWER(TRIM(latest.fuel_type)) = LOWER(TRIM(ft.fuel_type))
               AND latest.latest_transaction_date = ft.transaction_date
        ) last_tx
                        ON last_tx.station_id = fi.station_id
                     AND LOWER(TRIM(last_tx.fuel_type)) = LOWER(TRIM(fi.fuel_type))
                WHERE fi.station_id = ?
                    AND COALESCE(fi.current_level, 0) > 0
                GROUP BY fi.fuel_type, last_tx.present_reading, fi.current_level
                ORDER BY fi.fuel_type
    ");
    $stmt->execute([$station_id]);
    $fuel_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get fuel inventory
        $stmt = $pdo->prepare("SELECT fi.*, COALESCE(fi.current_level, 0) AS current_stock, " . $reorderThresholdExpr . " AS reorder_threshold FROM fuel_inventory fi WHERE station_id = ? ORDER BY fuel_type");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get low stock alerts directly from live fuel inventory
    $stmt = $pdo->prepare("
        SELECT 
            fi.fuel_type,
            COALESCE(fi.current_level, 0) AS current_stock,
                        " . $reorderThresholdExpr . " AS reorder_threshold,
            CASE
                WHEN COALESCE(fi.current_level, 0) < 100 THEN 'Critical'
                ELSE 'Warning'
            END AS alert_level,
                        " . $lastUpdatedExpr . " AS created_at
        FROM fuel_inventory fi
        WHERE fi.station_id = ?
                    AND " . $reorderThresholdExpr . " > 0
                    AND COALESCE(fi.current_level, 0) <= " . $reorderThresholdExpr . "
                ORDER BY (COALESCE(fi.current_level, 0) / NULLIF(" . $reorderThresholdExpr . ", 0)) ASC, fi.fuel_type ASC
    ");
    $stmt->execute([$station_id]);
    $low_stock_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent fuel readings based on role
    if (in_array($role, ['staff', 'cashier', 'pump_attendant'])) {
        // Staff can only see their own readings
        $stmt = $pdo->prepare("
            SELECT fr.*, u.name as encoded_by_name 
            FROM fuel_readings fr 
            LEFT JOIN users u ON fr.encoded_by = u.id 
            WHERE fr.station_id = ? AND fr.encoded_by = ?
            ORDER BY fr.encoded_at DESC
            LIMIT 50
        ");
        $stmt->execute([$station_id, $me['id']]);
    } else {
        // Managers and above can see all readings
        $stmt = $pdo->prepare("
            SELECT fr.*, u.name as encoded_by_name 
            FROM fuel_readings fr 
            LEFT JOIN users u ON fr.encoded_by = u.id 
            WHERE fr.station_id = ?
            ORDER BY fr.encoded_at DESC
            LIMIT 100
        ");
        $stmt->execute([$station_id]);
    }
    $fuel_readings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        
} catch (Exception $e) {
    error_log("Error fetching fuel data: " . $e->getMessage());
}

include __DIR__ . '/../partials/header.php';
?>

<style>
.fuel-management-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}


.fuel-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 1px solid #e9ecef;
    padding: 30px;
    margin-bottom: 20px;
}

.reading-form {
    padding: 0;
    color: inherit;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #333;
}


.form-input, .form-select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}


.auto-pulled {
    background: #f8f9fa;
    border-color: #28a745;
    color: #28a745;
}

.computed {
    background: #e3f2fd;
    border-color: #2196f3;
    color: #1976d2;
}

.calculation-display {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
}

.calc-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    padding: 8px 0;
}

.calc-row.total {
    border-top: 2px solid #333;
    padding-top: 15px;
    font-weight: bold;
    font-size: 18px;
}

.btn-primary {
    background: #003d7a;
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.3s ease;
}

.btn-primary:hover {
    background: #002855;
}

.btn-secondary {
    background: #6c757d;
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 12px;
}

.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid transparent;
}

.alert-success {
    background: #d4edda;
    border-color: #c3e6cb;
    color: #155724;
}

.alert-error {
    background: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}

.alert-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.alert-danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.stock-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stock-card {
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid #003d7a;
    background: #f8f9fa;
}

.stock-card.low-stock {
    border-left-color: #ffc107;
    background: #fff3cd;
}

.stock-card.critical-stock {
    border-left-color: #dc3545;
    background: #f8d7da;
}

.fuel-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.fuel-table th,
.fuel-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}

.fuel-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #333;
}

.fuel-table tr:hover {
    background: #f8f9fa;
}

@media (max-width: 768px) {
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .fuel-tabs {
        flex-direction: column;
    }
    
    .stock-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="fuel-management-container">
    <div class="page-head">
        <div>
            <h1 class="h1">Fuel Management</h1>
            <div class="sub">Encode readings, track inventory, and monitor stock levels with audit-ready transparency</div>
        </div>
    </div>
    
    
<?php if($msg): ?>
<div class="alert <?php echo $msg_type === 'error' ? 'alert-error' : 'alert-success'; ?>">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

    <!-- Low Stock Alerts -->
    <?php if (!empty($low_stock_alerts)): ?>
        <div class="alert-warning">
            <h4><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h4>
            <?php foreach ($low_stock_alerts as $alert): ?>
                <p><strong><?php echo htmlspecialchars($alert['fuel_type']); ?>:</strong> 
                   Current: <?php echo number_format($alert['current_stock'], 2); ?>L, 
                   Threshold: <?php echo number_format($alert['reorder_threshold'], 2); ?>L,
                   Level: <span style="color: <?php echo $alert['alert_level'] === 'Critical' ? '#dc3545' : '#856404'; ?>;">
                       <?php echo $alert['alert_level']; ?>
                   </span>
                </p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Fuel Reading Tracker -->
        <div class="fuel-card">
            <h2 style="margin-bottom: 20px;">Fuel Reading Tracker</h2>
                
                <form method="post" action="fuel_readings_encoding.php">
                    <input type="hidden" name="action" value="encode_fuel_reading">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Fuel Type</label>
                            <select name="fuel_type" id="fuel_type" class="form-select" onchange="updatePreviousReading()" required>
                                <option value="">Select fuel type</option>
                                <?php foreach ($fuel_options as $fuel): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($fuel['fuel_type']); ?>"
                                        data-pump-number="<?php echo htmlspecialchars($fuel['pump_number']); ?>"
                                        data-fuel-type="<?php echo htmlspecialchars($fuel['fuel_type']); ?>"
                                        data-previous-reading="<?php echo number_format((float)($fuel['previous_reading'] ?? 0), 2, '.', ''); ?>"
                                        data-current-stock="<?php echo number_format((float)($fuel['current_stock'] ?? 0), 2, '.', ''); ?>">
                                        <?php echo htmlspecialchars($fuel['fuel_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="pump_number" id="resolved_pump_number" value="">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Present Reading</label>
                            <input type="number" name="present_reading" id="present_reading" 
                                   class="form-input" step="0.01" min="0" required onchange="computeDifference()">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Previous Reading</label>
                            <input type="number" id="previous_reading" 
                                   class="form-input auto-pulled" step="0.01" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Difference</label>
                            <input type="number" id="computed_difference" 
                                   class="form-input computed" step="0.01" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Shift Period</label>
                            <select name="shift_period" class="form-select" required>
                                <option value="">Select shift period</option>
                                <?php echo $shift_config->generateShiftSelectOptions(); ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Staff Name</label>
                            <input type="text" class="form-input" 
                                   value="<?php echo htmlspecialchars($me['name'] ?? $me['username']); ?>" readonly>
                        </div>
                    </div>

                    <div style="display: flex; gap: 15px; margin-top: 20px; justify-content: flex-end;">
                        <button type="submit" class="btn-primary">
                            Encode Reading
                        </button>
                        <button type="reset" class="btn-secondary" onclick="resetReadingForm()">
                            Reset
                        </button>
                    </div>
                </form>
        </div>
</div>

<script>

function updatePreviousReading() {
    const fuelSelect = document.getElementById('fuel_type');
    const selectedOption = fuelSelect.options[fuelSelect.selectedIndex];
    const pumpMeta = document.getElementById('pump_selection_meta');
    const resolvedPumpInput = document.getElementById('resolved_pump_number');
    
    if (!selectedOption || !selectedOption.value) {
        document.getElementById('previous_reading').value = '0.00';
        if (resolvedPumpInput) {
            resolvedPumpInput.value = '';
        }
        if (pumpMeta) {
            pumpMeta.textContent = 'Select a fuel type to auto-load the last reading and current stock.';
        }
        computeDifference();
        return;
    }

    const previousReading = parseFloat(selectedOption.dataset.previousReading || '0');
    const currentStock = parseFloat(selectedOption.dataset.currentStock || '0');
    const fuelType = selectedOption.dataset.fuelType || 'Unknown';
    const pumpNumber = selectedOption.dataset.pumpNumber || '';

    document.getElementById('previous_reading').value = previousReading.toFixed(2);
    if (resolvedPumpInput) {
        resolvedPumpInput.value = pumpNumber;
    }
    if (pumpMeta) {
        pumpMeta.textContent = `${fuelType} | Linked pump: #${pumpNumber || '-'} | Previous reading: ${previousReading.toFixed(2)} L | Current stock: ${currentStock.toFixed(2)} L`;
    }
    computeDifference();
}

function computeDifference() {
    const present = parseFloat(document.getElementById('present_reading').value) || 0;
    const previous = parseFloat(document.getElementById('previous_reading').value) || 0;
    
    const difference = present - previous;
    document.getElementById('computed_difference').value = difference.toFixed(2);
}

function resetReadingForm() {
    if (confirm('Reset all form fields?')) {
        document.querySelector('form').reset();
        document.getElementById('previous_reading').value = '0.00';
        document.getElementById('computed_difference').value = '0.00';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    computeDifference();
    updatePreviousReading();
});
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
