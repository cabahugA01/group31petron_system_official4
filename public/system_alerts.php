<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'system_alerts';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';

$u = current_user();
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

// Only allow superadmin and admin
if (!in_array($roleKey, ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

include __DIR__ . '/../partials/header.php';

// Fetch all critical alerts
$alerts = [];
$alert_types = [
    'fuel' => 'Low Fuel Stock',
    'security' => 'Security Alerts', 
    'system' => 'System Errors',
    'jobs' => 'High Pending Jobs'
];

// Get alerts from the same logic as dashboard
try {
    $all_alerts = [];
    
    // 1. Low Fuel Stock Alerts
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'station_inventory'");
        $table_exists = $stmt->rowCount() > 0;
        
        if ($table_exists) {
            $stmt = $pdo->query("
                SELECT 
                    'Low Fuel Stock' as alert_type,
                    CONCAT(COALESCE(s.name, 'Unknown Station'), ' - ', COALESCE(p.name, 'Unknown Product')) as description,
                    si.stock_level as current_level,
                    COALESCE(si.reorder_level, 10) as threshold,
                    'critical' as severity,
                    si.station_id,
                    si.product_id,
                    si.updated_at
                FROM station_inventory si
                LEFT JOIN stations s ON si.station_id = s.id
                LEFT JOIN products p ON si.product_id = p.id
                LEFT JOIN product_types pt ON p.type_id = pt.id
                WHERE (pt.name = 'fuel' OR pt.name IS NULL)
                AND si.stock_level <= COALESCE(si.reorder_level, 10)
                AND si.stock_level > 0
                ORDER BY (si.stock_level / COALESCE(si.reorder_level, 10)) ASC
            ");
            $fuel_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $all_alerts = array_merge($all_alerts, $fuel_alerts);
        }
    } catch(Exception $e) {
        error_log("Fuel alerts error: " . $e->getMessage());
    }
    
    // 2. Failed Login Attempts (Last 7 days)
    try {
        $stmt = $pdo->prepare("
            SELECT 
                'Security Alert' as alert_type,
                CONCAT('Multiple failed login attempts for: ', username) as description,
                COUNT(*) as current_level,
                5 as threshold,
                'warning' as severity,
                NULL as station_id,
                NULL as product_id,
                MAX(created_at) as updated_at
            FROM activity_logs 
            WHERE action LIKE '%Login Failed%' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY username
            HAVING COUNT(*) > 3
            ORDER BY COUNT(*) DESC
        ");
        $stmt->execute();
        $security_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_alerts = array_merge($all_alerts, $security_alerts);
    } catch(Exception $e) {
        error_log("Security alerts error: " . $e->getMessage());
    }
    
    // 3. System Error Alerts (Last 7 days)
    try {
        $stmt = $pdo->query("
            SELECT 
                'System Error' as alert_type,
                'Database connection or system errors detected' as description,
                COUNT(*) as current_level,
                1 as threshold,
                'critical' as severity,
                NULL as station_id,
                NULL as product_id,
                MAX(created_at) as updated_at
            FROM audit_logs 
            WHERE status = 'Failed' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            HAVING COUNT(*) > 0
        ");
        $system_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_alerts = array_merge($all_alerts, $system_alerts);
    } catch(Exception $e) {
        error_log("System alerts error: " . $e->getMessage());
    }
    
    // 4. High Pending Job Orders
    try {
        $stmt = $pdo->query("
            SELECT 
                'High Pending Jobs' as alert_type,
                CONCAT('Pending jobs exceeding 24 hours: ', COUNT(*)) as description,
                COUNT(*) as current_level,
                10 as threshold,
                'warning' as severity,
                NULL as station_id,
                NULL as product_id,
                MAX(created_at) as updated_at
            FROM job_orders 
            WHERE status IN ('Pending', 'Awaiting Parts')
            AND created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            HAVING COUNT(*) > 5
        ");
        $job_alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $all_alerts = array_merge($all_alerts, $job_alerts);
    } catch(Exception $e) {
        error_log("Job alerts error: " . $e->getMessage());
    }
    
    // Sort alerts by severity and date
    usort($all_alerts, function($a, $b) {
        $severity_order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $a_severity = $severity_order[$a['severity']] ?? 3;
        $b_severity = $severity_order[$b['severity']] ?? 3;
        
        if ($a_severity !== $b_severity) {
            return $a_severity - $b_severity;
        }
        
        return strtotime($b['updated_at'] ?? '1970-01-01') - strtotime($a['updated_at'] ?? '1970-01-01');
    });
    
    $alerts = $all_alerts;
    
} catch(Exception $e) {
    error_log("System alerts error: " . $e->getMessage());
    $alerts = [];
}

// Filter by alert type
$filter_type = $_GET['type'] ?? '';
if ($filter_type && isset($alert_types[$filter_type])) {
    $alerts = array_filter($alerts, function($alert) use ($filter_type) {
        return strpos(strtolower($alert['alert_type']), $filter_type) !== false;
    });
}
?>

<div class="page-header">
    <h1>System Alerts</h1>
    <p>View and monitor all critical system alerts across all stations</p>
</div>

<div class="filter-bar">
    <form method="GET" class="filter-form">
        <div class="form-group">
            <label>Alert Type:</label>
            <select name="type" class="inp">
                <option value="">All Alerts</option>
                <?php foreach($alert_types as $key => $label): ?>
                    <option value="<?php echo $key; ?>" <?php echo $filter_type === $key ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn">Filter</button>
        <a href="system_alerts.php" class="btn btn-secondary">Reset</a>
    </form>
</div>

<div class="content-section">
    <?php if(!empty($alerts)): ?>
        <div class="alert-summary">
            <div class="summary-item">
                <span class="label">Total Alerts:</span>
                <span class="value"><?php echo count($alerts); ?></span>
            </div>
            <div class="summary-item critical">
                <span class="label">Critical:</span>
                <span class="value"><?php echo count(array_filter($alerts, function($a) { return $a['severity'] === 'critical'; })); ?></span>
            </div>
            <div class="summary-item warning">
                <span class="label">Warning:</span>
                <span class="value"><?php echo count(array_filter($alerts, function($a) { return $a['severity'] === 'warning'; })); ?></span>
            </div>
        </div>

        <div class="alerts-list">
            <?php foreach($alerts as $alert): ?>
                <div class="alert-item severity-<?php echo $alert['severity']; ?>">
                    <div class="alert-header">
                        <div class="alert-type">
                            <i class="fas fa-<?php echo $alert['severity'] === 'critical' ? 'exclamation-triangle' : 'exclamation-circle'; ?>"></i>
                            <?php echo htmlspecialchars($alert['alert_type']); ?>
                        </div>
                        <div class="alert-severity">
                            <span class="badge badge-<?php echo $alert['severity']; ?>">
                                <?php echo ucfirst($alert['severity']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="alert-description">
                        <?php echo htmlspecialchars($alert['description']); ?>
                    </div>
                    <div class="alert-details">
                        <div class="detail-item">
                            <span class="label">Current Level:</span>
                            <span class="value"><?php echo number_format($alert['current_level']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="label">Threshold:</span>
                            <span class="value"><?php echo number_format($alert['threshold']); ?></span>
                        </div>
                        <?php if($alert['updated_at']): ?>
                            <div class="detail-item">
                                <span class="label">Last Updated:</span>
                                <span class="value"><?php echo date('M j, Y H:i', strtotime($alert['updated_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <h3>No Alerts Found</h3>
            <p>There are currently no system alerts matching your criteria.</p>
        </div>
    <?php endif; ?>
</div>

<style>
.alert-summary {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.summary-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 20px;
    background: white;
    border-radius: 6px;
    border-left: 4px solid #ddd;
}

.summary-item.critical {
    border-left-color: #ef4444;
}

.summary-item.warning {
    border-left-color: #f59e0b;
}

.summary-item .label {
    font-size: 12px;
    color: #666;
    margin-bottom: 5px;
}

.summary-item .value {
    font-size: 24px;
    font-weight: bold;
    color: #333;
}

.alerts-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.alert-item {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    border-left: 4px solid #ddd;
}

.alert-item.severity-critical {
    border-left-color: #ef4444;
}

.alert-item.severity-warning {
    border-left-color: #f59e0b;
}

.alert-item.severity-info {
    border-left-color: #3b82f6;
}

.alert-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.alert-type {
    font-weight: bold;
    color: #333;
    display: flex;
    align-items: center;
    gap: 8px;
}

.alert-severity .badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge-critical {
    background: #ef4444;
    color: white;
}

.badge-warning {
    background: #f59e0b;
    color: white;
}

.badge-info {
    background: #3b82f6;
    color: white;
}

.alert-description {
    margin-bottom: 15px;
    color: #555;
    line-height: 1.5;
}

.alert-details {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.detail-item .label {
    font-size: 11px;
    color: #888;
    text-transform: uppercase;
}

.detail-item .value {
    font-size: 14px;
    font-weight: 600;
    color: #333;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
}

.empty-state i {
    font-size: 48px;
    color: #22c55e;
    margin-bottom: 20px;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #333;
}

.filter-bar {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid #e5e7eb;
}

.filter-form {
    display: flex;
    gap: 15px;
    align-items: end;
}

.filter-form .form-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.filter-form .form-group label {
    font-size: 14px;
    font-weight: 500;
    color: #333;
}

.filter-form .inp {
    }

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
