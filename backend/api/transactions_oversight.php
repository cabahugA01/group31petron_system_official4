<?php
/**
 * Transactions Oversight API
 * Provides data for Manager Dashboard Transactions Oversight module
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config/database_config.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Start session for authentication
session_start();

// Check if user is logged in and has manager privileges
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Get current user and station
try {
    $stmt = $pdo->prepare("SELECT station_id, role FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !in_array($user['role'], ['manager', 'admin', 'superadmin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient privileges']);
        exit;
    }
    
    $station_id = $user['station_id'];
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

// Get action parameter
$action = $_GET['action'] ?? '';
$date_filter = $_GET['date'] ?? 'today';
$type_filter = $_GET['type'] ?? 'all';
$payment_filter = $_GET['payment'] ?? 'all';
$search_term = $_GET['search'] ?? '';
$severity_filter = $_GET['severity'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$staff_filter = $_GET['staff'] ?? 'all';

try {
    switch ($action) {
        case 'validated':
            echo getValidatedTransactions($station_id, $date_filter, $type_filter, $payment_filter, $search_term);
            break;
            
        case 'pending':
            echo getPendingTransactions($station_id);
            break;
            
        case 'variance':
            echo getVarianceAlerts($station_id, $severity_filter, $status_filter);
            break;
            
        case 'shifts':
            echo getShiftTransactions($station_id, $date_filter, $staff_filter, $status_filter);
            break;
            
        case 'validate_transaction':
            echo validateTransaction($station_id);
            break;
            
        case 'reject_transaction':
            echo rejectTransaction($station_id);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Transactions Oversight API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

/**
 * Get validated transactions (fuel and merchandise)
 */
function getValidatedTransactions($station_id, $date_filter, $type_filter, $payment_filter, $search_term) {
    global $pdo;
    
    // Build date condition
    $date_condition = getDateCondition($date_filter, 'transaction_date');
    
    // Build type condition
    $type_condition = '';
    if ($type_filter !== 'all') {
        $type_condition = "AND transaction_type = ?";
    }
    
    // Build payment condition
    $payment_condition = '';
    if ($payment_filter !== 'all') {
        $payment_condition = "AND payment_method = ?";
    }
    
    // Build search condition
    $search_condition = '';
    if (!empty($search_term)) {
        $search_condition = "AND (id LIKE ? OR staff_name LIKE ? OR details LIKE ?)";
    }
    
    // Combine fuel and merchandise transactions
    $query = "
        SELECT `user_id`, 'Fuel' as transaction_type,
            fuel_type as details,
            total_amount,
            payment_method,
            transaction_date,
            staff_name,
            status,
            pump_number
        FROM fuel_transactions
        WHERE station_id = ? $date_condition $type_condition $payment_condition $search_condition
        
        UNION ALL
        
        SELECT 
            s.id,
            'Merchandise' as transaction_type,
            CONCAT(si.product_name, ' (', si.quantity, 'x)') as details,
            s.total_amount,
            s.payment_method,
            s.transaction_date,
            u.name as staff_name,
            s.status,
            NULL as pump_number
        FROM sales s
        LEFT JOIN users u ON u.user_id = s.staff_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.station_id = ? $date_condition $type_condition $payment_condition $search_condition
        
        ORDER BY transaction_date DESC
        LIMIT 100
    ";
    
    $params = [$station_id];
    
    // Add date parameters
    $date_params = getDateParams($date_filter);
    $params = array_merge($params, $date_params);
    
    // Add type parameter
    if ($type_filter !== 'all') {
        $params[] = ucfirst($type_filter);
    }
    
    // Add payment parameter
    if ($payment_filter !== 'all') {
        $params[] = ucfirst($payment_filter);
    }
    
    // Add search parameters
    if (!empty($search_term)) {
        $search_like = "%$search_term%";
        $params = array_merge($params, [$search_like, $search_like, $search_like]);
    }
    
    // Second station parameter for UNION
    $params[] = $station_id;
    
    // Add date parameters again for second part of UNION
    $params = array_merge($params, $date_params);
    
    // Add type parameter again
    if ($type_filter !== 'all') {
        $params[] = ucfirst($type_filter);
    }
    
    // Add payment parameter again
    if ($payment_filter !== 'all') {
        $params[] = ucfirst($payment_filter);
    }
    
    // Add search parameters again
    if (!empty($search_term)) {
        $search_like = "%$search_term%";
        $params = array_merge($params, [$search_like, $search_like, $search_like]);
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'total_count' => count($transactions),
        'total_amount' => array_sum(array_column($transactions, 'total_amount'))
    ];
    
    return json_encode([
        'success' => true,
        'transactions' => $transactions,
        'summary' => $summary
    ]);
}

/**
 * Get pending validation transactions
 */
function getPendingTransactions($station_id) {
    global $pdo;
    
    $query = "
        SELECT 
            s.id,
            'Merchandise' as transaction_type,
            CONCAT(si.product_name, ' (', si.quantity, 'x)') as details,
            s.total_amount,
            s.payment_method,
            s.transaction_date,
            u.name as staff_name,
            s.status,
            s.created_at as submitted_at
        FROM sales s
        LEFT JOIN users u ON u.user_id = s.staff_id
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.station_id = ? AND s.status = 'pending_validation'
        
        UNION ALL
        
        SELECT 
            ft.id,
            'Fuel' as transaction_type,
            CONCAT(ft.fuel_type, ' - Pump ', ft.pump_number) as details,
            ft.total_amount,
            ft.payment_method,
            ft.transaction_date,
            u.name as staff_name,
            ft.status,
            ft.created_at as submitted_at
        FROM fuel_transactions ft
        LEFT JOIN users u ON u.user_id = ft.staff_id
        WHERE ft.station_id = ? AND ft.status = 'pending_validation'
        
        ORDER BY submitted_at DESC
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$station_id, $station_id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = [
        'pending_count' => count($transactions)
    ];
    
    return json_encode([
        'success' => true,
        'transactions' => $transactions,
        'summary' => $summary
    ]);
}

/**
 * Get variance alerts
 */
function getVarianceAlerts($station_id, $severity_filter, $status_filter) {
    global $pdo;
    
    // Build severity condition
    $severity_condition = '';
    if ($severity_filter !== 'all') {
        $severity_condition = "AND severity = ?";
    }
    
    // Build status condition
    $status_condition = '';
    if ($status_filter !== 'all') {
        $status_condition = "AND status = ?";
    }
    
    $query = "
        SELECT 
            id,
            type,
            severity,
            description,
            expected_value,
            actual_value,
            variance_percent,
            status,
            created_at,
            resolved_at
        FROM fuel_variance_reports
        WHERE station_id = ? $severity_condition $status_condition
        
        ORDER BY 
            CASE severity 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'medium' THEN 3 
                WHEN 'low' THEN 4 
            END,
            created_at DESC
        LIMIT 50
    ";
    
    $params = [$station_id];
    
    if ($severity_filter !== 'all') {
        $params[] = $severity_filter;
    }
    
    if ($status_filter !== 'all') {
        $params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low' => 0
    ];
    
    foreach ($alerts as $alert) {
        $severity = strtolower($alert['severity']);
        if (isset($summary[$severity])) {
            $summary[$severity]++;
        }
    }
    
    return json_encode([
        'success' => true,
        'alerts' => $alerts,
        'summary' => $summary
    ]);
}

/**
 * Get shift transactions with consolidated data
 */
function getShiftTransactions($station_id, $date_filter, $staff_filter, $status_filter) {
    global $pdo;
    
    // Get date condition
    $date_condition = getDateCondition($date_filter, 'start_time');
    
    // Build staff condition
    $staff_condition = '';
    if ($staff_filter !== 'all') {
        $staff_condition = "AND s.staff_id = ?";
    }
    
    // Build status condition
    $status_condition = '';
    if ($status_filter !== 'all') {
        $status_condition = "AND s.status = ?";
    }
    
    // Query for shifts
    $shift_query = "
        SELECT 
            s.id,
            s.staff_id,
            u.name as staff_name,
            s.start_time,
            s.end_time,
            s.status,
            COUNT(ft.id) as fuel_transactions,
            COUNT(sales.id) as merchandise_transactions,
            COALESCE(SUM(ft.total_amount), 0) + COALESCE(SUM(sales.total_amount), 0) as total_amount,
            COUNT(ft.id) + COUNT(sales.id) as transaction_count
        FROM shifts s
        LEFT JOIN users u ON u.user_id = s.staff_id
        LEFT JOIN fuel_transactions ft ON ft.station_id = s.station_id 
            AND ft.staff_id = s.staff_id 
            AND ft.transaction_date BETWEEN s.start_time AND COALESCE(s.end_time, NOW())
        LEFT JOIN sales ON sales.station_id = s.station_id 
            AND sales.staff_id = s.staff_id 
            AND sales.transaction_date BETWEEN s.start_time AND COALESCE(s.end_time, NOW())
        WHERE s.station_id = ? $date_condition $staff_condition $status_condition
        GROUP BY s.id
        ORDER BY s.start_time DESC
        LIMIT 20
    ";
    
    $params = [$station_id];
    $date_params = getDateParams($date_filter);
    $params = array_merge($params, $date_params);
    
    if ($staff_filter !== 'all') {
        $params[] = $staff_filter;
    }
    
    if ($status_filter !== 'all') {
        $params[] = $status_filter;
    }
    
    $stmt = $pdo->prepare($shift_query);
    $stmt->execute($params);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent transactions for each shift
    foreach ($shifts as &$shift) {
        $shift['transactions'] = getShiftTransactionsDetails($station_id, $shift['staff_id'], $shift['start_time'], $shift['end_time']);
    }
    
    // Get staff options for filter
    $staff_query = "
        SELECT DISTINCT u.id, u.name
        FROM users u
        INNER JOIN shifts s ON s.staff_id = u.id
        WHERE s.station_id = ? AND u.role IN ('staff', 'cashier', 'pump_attendant')
        ORDER BY u.name
    ";
    
    $stmt = $pdo->prepare($staff_query);
    $stmt->execute([$station_id]);
    $staff_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate summary
    $summary = [
        'active_shifts' => 0,
        'completed_shifts' => 0,
        'total_transactions' => array_sum(array_column($shifts, 'transaction_count'))
    ];
    
    foreach ($shifts as $shift) {
        if ($shift['status'] === 'active') {
            $summary['active_shifts']++;
        } elseif ($shift['status'] === 'completed') {
            $summary['completed_shifts']++;
        }
    }
    
    return json_encode([
        'success' => true,
        'shifts' => $shifts,
        'summary' => $summary,
        'staff_options' => $staff_options
    ]);
}

/**
 * Get detailed transactions for a specific shift
 */
function getShiftTransactionsDetails($station_id, $staff_id, $start_time, $end_time) {
    global $pdo;
    
    $end_time = $end_time ?: date('Y-m-d H:i:s');
    
    $query = "
        SELECT 
            id,
            'Fuel' as type,
            CONCAT(fuel_type, ' - Pump ', pump_number) as details,
            total_amount as amount,
            transaction_date
        FROM fuel_transactions
        WHERE station_id = ? AND staff_id = ? 
            AND transaction_date BETWEEN ? AND ?
        
        UNION ALL
        
        SELECT 
            s.id,
            'Merchandise' as type,
            CONCAT(si.product_name, ' (', si.quantity, 'x)') as details,
            s.total_amount as amount,
            s.transaction_date
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.station_id = ? AND s.staff_id = ? 
            AND s.transaction_date BETWEEN ? AND ?
        
        ORDER BY transaction_date DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$station_id, $staff_id, $start_time, $end_time, $station_id, $staff_id, $start_time, $end_time]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Validate a pending transaction
 */
function validateTransaction($station_id) {
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $transaction_id = $input['transaction_id'] ?? 0;
    
    if (!$transaction_id) {
        return json_encode(['success' => false, 'error' => 'Transaction ID required']);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update fuel transaction
        $stmt = $pdo->prepare("
            UPDATE fuel_transactions 
            SET status = 'validated', validated_at = NOW(), validated_by = ?
            WHERE id = ? AND station_id = ? AND status = 'pending_validation'
        ");
        $stmt->execute([$_SESSION['user_id'], $transaction_id, $station_id]);
        
        // Update merchandise transaction if fuel transaction wasn't found
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                UPDATE sales 
                SET status = 'validated', validated_at = NOW(), validated_by = ?
                WHERE id = ? AND station_id = ? AND status = 'pending_validation'
            ");
            $stmt->execute([$_SESSION['user_id'], $transaction_id, $station_id]);
        }
        
        // Log the validation
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (action, user_id, station_id, details, created_at)
            VALUES ('TRANSACTION_VALIDATED', ?, ?, ?, NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $station_id, "Transaction #$transaction_id validated"]);
        
        $pdo->commit();
        
        return json_encode(['success' => true, 'message' => 'Transaction validated successfully']);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

/**
 * Reject a pending transaction
 */
function rejectTransaction($station_id) {
    global $pdo;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $transaction_id = $input['transaction_id'] ?? 0;
    $reason = $input['reason'] ?? '';
    
    if (!$transaction_id || !$reason) {
        return json_encode(['success' => false, 'error' => 'Transaction ID and reason required']);
    }
    
    try {
        $pdo->beginTransaction();
        
        // Update fuel transaction
        $stmt = $pdo->prepare("
            UPDATE fuel_transactions 
            SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ?
            WHERE id = ? AND station_id = ? AND status = 'pending_validation'
        ");
        $stmt->execute([$_SESSION['user_id'], $reason, $transaction_id, $station_id]);
        
        // Update merchandise transaction if fuel transaction wasn't found
        if ($stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("
                UPDATE sales 
                SET status = 'rejected', rejected_at = NOW(), rejected_by = ?, rejection_reason = ?
                WHERE id = ? AND station_id = ? AND status = 'pending_validation'
            ");
            $stmt->execute([$_SESSION['user_id'], $reason, $transaction_id, $station_id]);
        }
        
        // Log the rejection
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (action, user_id, station_id, details, created_at)
            VALUES ('TRANSACTION_REJECTED', ?, ?, ?, NOW())
        ");
        $stmt->execute([$_SESSION['user_id'], $station_id, "Transaction #$transaction_id rejected: $reason"]);
        
        $pdo->commit();
        
        return json_encode(['success' => true, 'message' => 'Transaction rejected successfully']);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

/**
 * Helper function to build date condition
 */
function getDateCondition($filter, $date_column) {
    switch ($filter) {
        case 'today':
            return "AND DATE($date_column) = CURDATE()";
        case 'week':
            return "AND $date_column >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        case 'month':
            return "AND $date_column >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        case 'custom':
            // For custom dates, you would need to handle start_date and end_date parameters
            return "AND $date_column >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        default:
            return "AND DATE($date_column) = CURDATE()";
    }
}

/**
 * Helper function to get date parameters
 */
function getDateParams($filter) {
    switch ($filter) {
        case 'today':
        case 'week':
        case 'month':
        case 'custom':
        default:
            return []; // No additional parameters needed for current implementation
    }
}
?>
