<?php
/**
 * Admin Reports API
 * Comprehensive backend API for all admin report sections
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Only Admin and SuperAdmin
if (!in_array($role, ['admin', 'superadmin'])) {
    json_response(['success' => false, 'message' => 'Access denied']);
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$date_start = trim($_GET['date_start'] ?? date('Y-m-d'));
$date_end = trim($_GET['date_end'] ?? date('Y-m-d'));

try {
    switch ($action) {
        
        // ============================================================
        // SHIFT REPORTS - Detailed breakdown per shift
        // ============================================================
        case 'get_shift_reports':
            $shift = trim($_GET['shift'] ?? 'all'); // shift1, shift2, all
            
            $sql = "SELECT 
                    fs.id, fs.shift_date, fs.shift_number,
                    fs.fuel_sales_total, fs.merchandise_sales_total, 
                    fs.service_income_total, fs.job_orders_count,
                    fs.cash_payments, fs.card_payments, fs.ewallet_payments,
                    fs.fleet_card_payments, fs.efuel_card_payments,
                    fs.customers_added, fs.status,
                    fs.validated_by, fs.validated_at,
                    u1.name as staff_name, u2.name as validator_name
                FROM fuel_shifts fs
                LEFT JOIN users u1 ON fs.staff_id = u1.id
                LEFT JOIN users u2 ON fs.validated_by = u2.id
                WHERE fs.station_id = ? 
                AND fs.shift_date BETWEEN ? AND ?";
            
            $params = [$station_id, $date_start, $date_end];
            
            if ($shift !== 'all') {
                $shift_num = $shift === 'shift1' ? 1 : 2;
                $sql .= " AND fs.shift_number = ?";
                $params[] = $shift_num;
            }
            
            $sql .= " ORDER BY fs.shift_date DESC, fs.shift_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $shifts]);
            break;
            
        // ============================================================
        // DAILY CONSOLIDATION - Totals across Shift 1 + Shift 2
        // ============================================================
        case 'get_daily_consolidation':
            $sql = "SELECT 
                    fs.shift_date,
                    SUM(fs.fuel_sales_total) as total_fuel_sales,
                    SUM(fs.merchandise_sales_total) as total_merchandise_sales,
                    SUM(fs.service_income_total) as total_service_income,
                    SUM(fs.job_orders_count) as total_job_orders,
                    SUM(fs.cash_payments + fs.card_payments + fs.ewallet_payments + 
                        fs.fleet_card_payments + fs.efuel_card_payments) as total_payments,
                    SUM(fs.customers_added) as total_customers,
                    COUNT(DISTINCT fs.id) as shifts_count
                FROM fuel_shifts fs
                WHERE fs.station_id = ? 
                AND fs.shift_date BETWEEN ? AND ?
                AND fs.status = 'Validated'
                GROUP BY fs.shift_date
                ORDER BY fs.shift_date DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $daily = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $daily]);
            break;
            
        // ============================================================
        // FUEL INVENTORY - Beginning vs Ending meter readings
        // ============================================================
        case 'get_fuel_inventory':
            $sql = "SELECT 
                    ft.fuel_type_name,
                    fp.pump_number,
                    fr1.reading_end as beginning_reading,
                    fr1.reading_date as beginning_date,
                    fr2.reading_end as ending_reading,
                    fr2.reading_date as ending_date,
                    (fr2.reading_end - fr1.reading_end) as variance,
                    ft.price_per_liter,
                    ((fr2.reading_end - fr1.reading_end) * ft.price_per_liter) as variance_amount,
                    fi.current_stock,
                    fi.reorder_point,
                    CASE 
                        WHEN fi.current_stock <= fi.reorder_point THEN 'Low Stock'
                        ELSE 'Adequate'
                    END as stock_status
                FROM fuel_pumps fp
                LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id
                LEFT JOIN fuel_readings fr1 ON fp.id = fr1.pump_id 
                    AND fr1.reading_date = ?
                LEFT JOIN fuel_readings fr2 ON fp.id = fr2.pump_id 
                    AND fr2.reading_date = ?
                LEFT JOIN fuel_inventory fi ON ft.id = fi.fuel_type_id 
                    AND fi.station_id = ?
                WHERE fp.station_id = ?
                ORDER BY ft.fuel_type_name, fp.pump_number";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$date_start, $date_end, $station_id, $station_id]);
            $fuel_inv = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $fuel_inv]);
            break;
            
        // ============================================================
        // MERCHANDISE INVENTORY - Deliveries, sales, balances
        // ============================================================
        case 'get_merchandise_inventory':
            $sql = "SELECT 
                    p.id, p.product_name, p.sku, p.category,
                    i.quantity_on_hand, i.reorder_point, i.reorder_quantity,
                    COALESCE(deliveries.delivered_qty, 0) as delivered_qty,
                    COALESCE(sales.sold_qty, 0) as sold_qty,
                    (i.quantity_on_hand - COALESCE(sales.sold_qty, 0) + COALESCE(deliveries.delivered_qty, 0)) as calculated_balance,
                    CASE 
                        WHEN i.quantity_on_hand <= i.reorder_point THEN 'Reorder Required'
                        WHEN i.quantity_on_hand <= (i.reorder_point * 1.5) THEN 'Low Stock'
                        ELSE 'Adequate'
                    END as stock_status
                FROM products p
                LEFT JOIN inventory i ON p.id = i.product_id AND i.station_id = ?
                LEFT JOIN (
                    SELECT product_id, SUM(quantity) as delivered_qty
                    FROM stock_in
                    WHERE station_id = ? AND DATE(received_date) BETWEEN ? AND ?
                    GROUP BY product_id
                ) deliveries ON p.id = deliveries.product_id
                LEFT JOIN (
                    SELECT product_id, SUM(quantity) as sold_qty
                    FROM merchandise_transactions
                    WHERE station_id = ? AND DATE(transaction_date) BETWEEN ? AND ?
                    GROUP BY product_id
                ) sales ON p.id = sales.product_id
                WHERE p.category = 'Merchandise'
                ORDER BY 
                    CASE 
                        WHEN i.quantity_on_hand <= i.reorder_point THEN 1
                        WHEN i.quantity_on_hand <= (i.reorder_point * 1.5) THEN 2
                        ELSE 3
                    END,
                    p.product_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $station_id, 
                $station_id, $date_start, $date_end,
                $station_id, $date_start, $date_end
            ]);
            $merch_inv = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $merch_inv]);
            break;
            
        // ============================================================
        // JOB ORDERS - By status and service type
        // ============================================================
        case 'get_job_orders':
            $sql = "SELECT 
                    jo.id, jo.job_order_number, jo.customer_name, jo.vehicle_plate,
                    jo.service_type, jo.status, jo.total_cost, jo.payment_status,
                    jo.created_at, jo.completed_at,
                    st.service_name, st.base_price,
                    u1.name as created_by_name,
                    u2.name as assigned_technician
                FROM job_orders jo
                LEFT JOIN service_types st ON jo.service_type_id = st.id
                LEFT JOIN users u1 ON jo.created_by = u1.id
                LEFT JOIN users u2 ON jo.assigned_to = u2.id
                WHERE jo.station_id = ?
                AND DATE(jo.created_at) BETWEEN ? AND ?
                ORDER BY 
                    CASE jo.status
                        WHEN 'Pending' THEN 1
                        WHEN 'In Progress' THEN 2
                        WHEN 'Completed' THEN 3
                        WHEN 'Cancelled' THEN 4
                    END,
                    jo.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals by status
            $totals = [
                'pending' => ['count' => 0, 'amount' => 0],
                'in_progress' => ['count' => 0, 'amount' => 0],
                'completed' => ['count' => 0, 'amount' => 0],
                'cancelled' => ['count' => 0, 'amount' => 0]
            ];
            
            foreach ($jobs as $job) {
                $key = strtolower(str_replace(' ', '_', $job['status']));
                if (isset($totals[$key])) {
                    $totals[$key]['count']++;
                    $totals[$key]['amount'] += (float)$job['total_cost'];
                }
            }
            
            json_response([
                'success' => true, 
                'data' => $jobs,
                'totals' => $totals
            ]);
            break;
            
        // ============================================================
        // PAYMENTS - Breakdown per mode
        // ============================================================
        case 'get_payments':
            $sql = "SELECT 
                    fs.shift_date, fs.shift_number,
                    fs.cash_payments, fs.card_payments, fs.ewallet_payments,
                    fs.fleet_card_payments, fs.efuel_card_payments,
                    (fs.cash_payments + fs.card_payments + fs.ewallet_payments + 
                     fs.fleet_card_payments + fs.efuel_card_payments) as total_payments,
                    (fs.fuel_sales_total + fs.merchandise_sales_total + 
                     fs.service_income_total) as total_sales,
                    ((fs.cash_payments + fs.card_payments + fs.ewallet_payments + 
                      fs.fleet_card_payments + fs.efuel_card_payments) - 
                     (fs.fuel_sales_total + fs.merchandise_sales_total + 
                      fs.service_income_total)) as variance
                FROM fuel_shifts fs
                WHERE fs.station_id = ?
                AND fs.shift_date BETWEEN ? AND ?
                AND fs.status = 'Validated'
                ORDER BY fs.shift_date DESC, fs.shift_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $payments]);
            break;
            
        // ============================================================
        // CUSTOMERS - New customers and balances
        // ============================================================
        case 'get_customers':
            $sql = "SELECT 
                    c.id, c.customer_name, c.contact_number, c.email,
                    c.customer_type, c.credit_limit, c.current_balance,
                    DATE(c.created_at) as date_added,
                    COUNT(DISTINCT t.id) as transaction_count,
                    COALESCE(SUM(t.amount), 0) as total_transactions
                FROM customers c
                LEFT JOIN transactions t ON c.id = t.customer_id 
                    AND t.station_id = ?
                    AND DATE(t.transaction_date) BETWEEN ? AND ?
                WHERE c.station_id = ?
                GROUP BY c.id
                ORDER BY c.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end, $station_id]);
            $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $customers]);
            break;
            
        // ============================================================
        // SUPPLIERS - Deliveries and payables
        // ============================================================
        case 'get_suppliers':
            $sql = "SELECT 
                    s.id, s.supplier_name, s.contact_person, s.contact_number,
                    COUNT(DISTINCT d.id) as delivery_count,
                    COALESCE(SUM(d.total_amount), 0) as total_deliveries,
                    COALESCE(SUM(CASE WHEN d.payment_status = 'Pending' THEN d.total_amount ELSE 0 END), 0) as payables
                FROM suppliers s
                LEFT JOIN deliveries d ON s.id = d.supplier_id 
                    AND d.station_id = ?
                    AND DATE(d.delivery_date) BETWEEN ? AND ?
                WHERE s.station_id = ?
                GROUP BY s.id
                ORDER BY payables DESC, s.supplier_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end, $station_id]);
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $suppliers]);
            break;
            
        // ============================================================
        // FINANCIAL/PAYABLES - Accounts payable and collections
        // ============================================================
        case 'get_financial':
            // Accounts Payable
            $sql_payables = "SELECT 
                    'Supplier' as type, s.supplier_name as name, 
                    d.id as reference_id, d.delivery_number as reference_number,
                    d.total_amount as amount, d.payment_status, d.due_date
                FROM deliveries d
                JOIN suppliers s ON d.supplier_id = s.id
                WHERE d.station_id = ? 
                AND d.payment_status IN ('Pending', 'Partial')
                AND d.delivery_date <= ?
                ORDER BY d.due_date ASC";
            
            $stmt = $pdo->prepare($sql_payables);
            $stmt->execute([$station_id, $date_end]);
            $payables = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Accounts Receivable
            $sql_receivables = "SELECT 
                    c.customer_name, c.customer_type, c.credit_limit, 
                    c.current_balance, c.contact_number
                FROM customers c
                WHERE c.station_id = ? 
                AND c.current_balance > 0
                ORDER BY c.current_balance DESC";
            
            $stmt = $pdo->prepare($sql_receivables);
            $stmt->execute([$station_id]);
            $receivables = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response([
                'success' => true, 
                'payables' => $payables,
                'receivables' => $receivables
            ]);
            break;
            
        // ============================================================
        // ACTIVITY LOG - Staff actions timeline
        // ============================================================
        case 'get_activity_log':
            $sql = "SELECT 
                    al.id, al.action_type, al.entity_type, al.action_details,
                    al.ip_address, al.status, al.created_at,
                    u.name as user_name, u.role as user_role
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE u.station_id = ?
                AND DATE(al.created_at) BETWEEN ? AND ?
                ORDER BY al.created_at DESC
                LIMIT 1000";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $logs]);
            break;
            
        // ============================================================
        // AUDIT TRAIL - Consolidated logs across shifts
        // ============================================================
        case 'get_audit_trail':
            $sql = "SELECT 
                    al.id, al.action_type, al.entity_type, al.entity_id,
                    al.action_details, al.old_values, al.new_values,
                    al.ip_address, al.user_agent, al.status, al.created_at,
                    u.name as user_name, u.role as user_role, u.email as user_email
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE u.station_id = ?
                AND DATE(al.created_at) BETWEEN ? AND ?
                AND al.action_type IN ('create', 'update', 'delete', 'approve', 'validate', 'finalize')
                ORDER BY al.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $trail = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_response(['success' => true, 'data' => $trail]);
            break;
            
        // ============================================================
        // CALENDAR & SCHEDULE - Job orders and deliveries
        // ============================================================
        case 'get_calendar_schedule':
            // Job Orders
            $sql_jobs = "SELECT 
                    'Job Order' as event_type,
                    jo.job_order_number as reference,
                    jo.customer_name as title,
                    jo.service_type as description,
                    jo.status,
                    jo.created_at as start_date,
                    jo.completed_at as end_date
                FROM job_orders jo
                WHERE jo.station_id = ?
                AND DATE(jo.created_at) BETWEEN ? AND ?";
            
            $stmt = $pdo->prepare($sql_jobs);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Deliveries
            $sql_deliveries = "SELECT 
                    'Delivery' as event_type,
                    d.delivery_number as reference,
                    s.supplier_name as title,
                    CONCAT('Amount: ₱', FORMAT(d.total_amount, 2)) as description,
                    d.status,
                    d.delivery_date as start_date,
                    d.received_date as end_date
                FROM deliveries d
                JOIN suppliers s ON d.supplier_id = s.id
                WHERE d.station_id = ?
                AND DATE(d.delivery_date) BETWEEN ? AND ?";
            
            $stmt = $pdo->prepare($sql_deliveries);
            $stmt->execute([$station_id, $date_start, $date_end]);
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $events = array_merge($jobs, $deliveries);
            usort($events, function($a, $b) {
                return strtotime($b['start_date']) - strtotime($a['start_date']);
            });
            
            json_response(['success' => true, 'data' => $events]);
            break;
            
        default:
            json_response(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    json_response(['success' => false, 'message' => $e->getMessage()]);
}
