<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
}

if (ob_get_level() === 0) {
    ob_start();
}

function manager_shift_json_response(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode($payload);
    exit;
}

function manager_shift_table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$tableName]);
    return (bool)$stmt->fetchColumn();
}

try {
    require_login();

    $me = current_user();
    $station_id = user_station_id();
    $role = role_key($me['role'] ?? '');

    // Restrict access to managers and admins only
    if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
        manager_shift_json_response(['error' => 'Access denied. Manager access required.'], 403);
    }

    $action = $_GET['action'] ?? 'get_shifts';
    
    switch ($action) {
        case 'get_shifts':
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $staff_id = $_GET['staff_id'] ?? '';
            
            $shifts = [];
            
            // Get shifts from labor_sessions table
            if (manager_shift_table_exists($pdo, 'labor_sessions')) {
                $sql = "SELECT 
                    ls.id,
                    ls.user_id as staff_id,
                    u.name as staff_name,
                    u.username,
                    ls.station_id,
                    s.name as station_name,
                    ls.start_time,
                    ls.end_time,
                    ls.status,
                    CASE 
                        WHEN ls.end_time IS NULL THEN 'Active'
                        WHEN ls.end_time IS NOT NULL THEN 'Completed'
                        ELSE 'Unknown'
                    END as shift_status
                FROM labor_sessions ls
                LEFT JOIN users u ON ls.user_id = u.id
                LEFT JOIN stations s ON ls.station_id = s.id
                WHERE ls.station_id = ? 
                AND DATE(ls.start_time) BETWEEN ? AND ?";
                
                $params = [$station_id, $start_date, $end_date];
                
                if (!empty($staff_id)) {
                    $sql .= " AND ls.user_id = ?";
                    $params[] = $staff_id;
                }
                
                $sql .= " ORDER BY ls.start_time DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate duration and add transaction counts for each shift
                foreach ($shifts as &$shift) {
                    $startTime = new DateTime($shift['start_time']);
                    $endTime = $shift['end_time'] ? new DateTime($shift['end_time']) : new DateTime();
                    $duration = $startTime->diff($endTime);
                    $shift['duration'] = $duration->format('%dh %02dm');
                    $shift['duration_hours'] = $duration->h + ($duration->days * 24);
                    $shift['duration_minutes'] = $duration->i;
                    
                    // Get transaction counts for this shift
                    $shiftStart = $shift['start_time'];
                    $shiftEnd = $shift['end_time'] ?: date('Y-m-d H:i:s');
                    
                    $shift['transaction_summary'] = getShiftTransactionSummary($pdo, $shift['staff_id'], $station_id, $shiftStart, $shiftEnd);
                }
            }
            
            // Get configuration data
$config = getShiftConfiguration($pdo);

manager_shift_json_response([
                'shifts' => $shifts,
                'config' => $config,
                'summary' => [
                    'total_shifts' => count($shifts),
                    'active_shifts' => count(array_filter($shifts, fn($s) => $s['shift_status'] === $config['shift_status']['active'])),
                    'completed_shifts' => count(array_filter($shifts, fn($s) => $s['shift_status'] === $config['shift_status']['completed'])),
                    'date_range' => [
                        'start' => $start_date,
                        'end' => $end_date
                    ]
                ]
            ]);
            break;
            
        case 'get_shift_details':
            $shift_id = $_GET['shift_id'] ?? '';
            
            if (empty($shift_id)) {
                manager_shift_json_response(['error' => 'Shift ID is required'], 400);
            }
            
            // Get shift details
            $shift_sql = "SELECT 
                ls.id,
                ls.user_id as staff_id,
                u.name as staff_name,
                u.username,
                ls.station_id,
                s.name as station_name,
                ls.start_time,
                ls.end_time,
                ls.status,
                CASE 
                    WHEN ls.end_time IS NULL THEN 'Active'
                    WHEN ls.end_time IS NOT NULL THEN 'Completed'
                    ELSE 'Unknown'
                END as shift_status
            FROM labor_sessions ls
            LEFT JOIN users u ON ls.user_id = u.id
            LEFT JOIN stations s ON ls.station_id = s.id
            WHERE ls.id = ? AND ls.station_id = ?";
            
            $stmt = $pdo->prepare($shift_sql);
            $stmt->execute([$shift_id, $station_id]);
            $shift = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$shift) {
                manager_shift_json_response(['error' => 'Shift not found'], 404);
            }
            
            // Calculate duration
            $startTime = new DateTime($shift['start_time']);
            $endTime = $shift['end_time'] ? new DateTime($shift['end_time']) : new DateTime();
            $duration = $startTime->diff($endTime);
            $shift['duration'] = $duration->format('%dh %02dm');
            
            // Get detailed transactions for this shift
            $shiftStart = $shift['start_time'];
            $shiftEnd = $shift['end_time'] ?: date('Y-m-d H:i:s');
            
            $shift['fuel_transactions'] = getShiftFuelTransactions($pdo, $shift['staff_id'], $station_id, $shiftStart, $shiftEnd);
            $shift['merchandise_transactions'] = getShiftMerchandiseTransactions($pdo, $shift['staff_id'], $station_id, $shiftStart, $shiftEnd);
            $shift['transaction_summary'] = getShiftTransactionSummary($pdo, $shift['staff_id'], $station_id, $shiftStart, $shiftEnd);
            
            manager_shift_json_response(['shift' => $shift]);
            break;
            
        case 'get_staff_list':
            // Get list of staff for filtering
            $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.name, u.username 
                FROM users u 
                WHERE u.station_id = ? 
                AND u.role IN ('staff', 'cashier', 'pump_attendant')
                ORDER BY u.name");
            $stmt->execute([$station_id]);
            $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            manager_shift_json_response(['staff_list' => $staff_list]);
            break;
            
        default:
            manager_shift_json_response(['error' => 'Invalid action'], 400);
    }
    
} catch (Throwable $e) {
    manager_shift_json_response(['error' => 'Database error: ' . $e->getMessage()], 500);
}

function getShiftTransactionSummary(PDO $pdo, int $staff_id, int $station_id, string $shift_start, string $shift_end): array {
    $summary = [
        'fuel_transactions' => 0,
        'fuel_sales' => 0.0,
        'fuel_liters' => 0.0,
        'merchandise_transactions' => 0,
        'merchandise_sales' => 0.0,
        'total_transactions' => 0,
        'total_sales' => 0.0
    ];
    
    // Fuel transactions summary
    if (manager_shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = "SELECT 
            COUNT(*) as count,
            COALESCE(SUM(total_amount), 0) as total_sales,
            COALESCE(SUM(liters_sold), 0) as total_liters
        FROM fuel_transactions 
        WHERE staff_id = ? AND station_id = ? 
        AND transaction_date BETWEEN ? AND ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staff_id, $station_id, $shift_start, $shift_end]);
        $fuel_summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $summary['fuel_transactions'] = (int)($fuel_summary['count'] ?? 0);
        $summary['fuel_sales'] = (float)($fuel_summary['total_sales'] ?? 0);
        $summary['fuel_liters'] = (float)($fuel_summary['total_liters'] ?? 0);
    }
    
    // Merchandise transactions summary
    if (manager_shift_table_exists($pdo, 'sales')) {
        $sql = "SELECT 
            COUNT(DISTINCT s.id) as count,
            COALESCE(SUM(s.total), 0) as total_sales
        FROM sales s
        WHERE s.user_id = ? AND s.station_id = ? 
        AND s.created_at BETWEEN ? AND ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staff_id, $station_id, $shift_start, $shift_end]);
        $merch_summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $summary['merchandise_transactions'] = (int)($merch_summary['count'] ?? 0);
        $summary['merchandise_sales'] = (float)($merch_summary['total_sales'] ?? 0);
    }
    
    $summary['total_transactions'] = $summary['fuel_transactions'] + $summary['merchandise_transactions'];
    $summary['total_sales'] = $summary['fuel_sales'] + $summary['merchandise_sales'];
    
    return $summary;
}

function getShiftFuelTransactions(PDO $pdo, int $staff_id, int $station_id, string $shift_start, string $shift_end): array {
    $transactions = [];
    
    if (manager_shift_table_exists($pdo, 'fuel_transactions')) {
        $sql = "SELECT 
            ft.transaction_id,
            ft.fuel_type,
            ft.present_reading,
            ft.previous_reading,
            ft.calibration,
            ft.liters_sold,
            ft.price_per_liter,
            ft.total_amount,
            ft.payment_method,
            ft.status,
            ft.transaction_date,
            ft.created_at,
            u.name as staff_name
        FROM fuel_transactions ft
        LEFT JOIN users u ON ft.staff_id = u.id
        WHERE ft.staff_id = ? AND ft.station_id = ? 
        AND ft.transaction_date BETWEEN ? AND ?
        ORDER BY ft.transaction_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staff_id, $station_id, $shift_start, $shift_end]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $transactions;
}

function getShiftMerchandiseTransactions(PDO $pdo, int $staff_id, int $station_id, string $shift_start, string $shift_end): array {
    $transactions = [];
    
    if (manager_shift_table_exists($pdo, 'sales')) {
        $sql = "SELECT 
            s.id as transaction_id,
            s.total,
            s.payment_method,
            s.status,
            s.created_at,
            u.name as staff_name,
            c.name as customer_name,
            GROUP_CONCAT(CONCAT(si.name, ' (', si.quantity, ')') SEPARATOR ', ') as items
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE s.user_id = ? AND s.station_id = ? 
        AND s.created_at BETWEEN ? AND ?
        GROUP BY s.id
        ORDER BY s.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$staff_id, $station_id, $shift_start, $shift_end]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $transactions;
}

function getShiftConfiguration(PDO $pdo): array {
    $config = [
        'shift_status' => [
            'active' => 'Active',
            'completed' => 'Completed',
            'colors' => [
                'active' => '#28a745',
                'completed' => '#007bff'
            ]
        ],
        'transaction_status' => [
            'colors' => [
                'completed' => '#28a745',
                'complete' => '#28a745',
                'pending' => '#dc3545',
                'pending_validation' => '#dc3545',
                'rejected' => '#dc3545',
                'validated' => '#007bff'
            ]
        ],
        'currency' => [
            'symbol' => '¥'
        ]
    ];
    
    // Try to get shift status configuration from database
    try {
        $stmt = $pdo->query("SELECT status_key, status_name, color_code FROM shift_status_config WHERE active = 1 ORDER BY sort_order");
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($statuses)) {
            $config['shift_status']['colors'] = [];
            foreach ($statuses as $status) {
                $config['shift_status']['colors'][$status['status_key']] = $status['color_code'] ?? '#007bff';
                if ($status['status_key'] === 'active') {
                    $config['shift_status']['active'] = $status['status_name'];
                } elseif ($status['status_key'] === 'completed') {
                    $config['shift_status']['completed'] = $status['status_name'];
                }
            }
        }
    } catch(Exception $e) {
        // Keep defaults if table doesn't exist
    }
    
    // Try to get transaction status colors from database
    try {
        $stmt = $pdo->query("SELECT status_key, color_code FROM transaction_status_config WHERE active = 1");
        $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($statuses)) {
            $config['transaction_status']['colors'] = [];
            foreach ($statuses as $status) {
                $config['transaction_status']['colors'][$status['status_key']] = $status['color_code'] ?? '#6c757d';
            }
        }
    } catch(Exception $e) {
        // Keep defaults if table doesn't exist
    }
    
    // Try to get currency symbol from database
    try {
        $stmt = $pdo->query("SELECT currency_symbol FROM station_config WHERE station_id = ? LIMIT 1");
        $stmt->execute([user_station_id()]);
        $currency = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($currency && !empty($currency['currency_symbol'])) {
            $config['currency']['symbol'] = $currency['currency_symbol'];
        }
    } catch(Exception $e) {
        // Keep default if table doesn't exist
    }
    
    return $config;
}
?>
