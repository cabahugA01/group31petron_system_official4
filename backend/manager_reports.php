<?php
/**
 * Manager Reports Backend
 * Team performance, fuel variance, JO status, summaries, inventory movement
 */

require_once __DIR__ . '/lib.php';

class ManagerReports {
    private $pdo;
    private $station_id;
    private $user;

    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
        require_permission('view_team_reports');
    }

    /**
     * Team Performance Report
     * Sales, JO completion, readings per staff
     */
    public function team_performance($period = 'week') {
        $days = match($period) {
            'week' => 7, 'month' => 30, 'quarter' => 90, default => 7
        };

        $stmt = $this->pdo->prepare("
            SELECT 
                u.id, u.name,
                COUNT(DISTINCT t.id) as total_transactions,
                SUM(t.total_amount) as total_sales,
                COUNT(DISTINCT fdr.id) as fuel_readings,
                SUM(fdr.sales_liters) as total_liters,
                COUNT(DISTINCT jo.id) as job_orders,
                SUM(CASE WHEN jo.status = 'Completed' THEN jo.total_amount ELSE 0 END) as completed_jo_value,
                ROUND(AVG(CASE WHEN jo.status = 'Completed' THEN 1.0 ELSE 0 END)*100, 1) as completion_rate
            FROM users u
            LEFT JOIN merchandise_transactions t ON t.staff_id = u.id AND t.station_id = ?
            LEFT JOIN fuel_daily_readings fdr ON fdr.user_id = u.id AND fdr.station_id = ?
            LEFT JOIN job_orders jo ON (jo.created_by = u.id OR jo.assigned_mechanic_id = u.id) AND jo.station_id = ?
            WHERE u.station_id = ? AND u.role = 'staff'
                AND (t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     OR fdr.reading_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                     OR jo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY))
            GROUP BY u.id, u.name
            HAVING total_transactions > 0 OR fuel_readings > 0 OR job_orders > 0
            ORDER BY total_sales DESC
        ");

        $stmt->execute([$this->station_id, $this->station_id, $this->station_id, $this->station_id, $days, $days, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fuel Variance Report (team)
     */
    public function fuel_variance_report($period = 'week') {
        $days = match($period) {
            'week' => 7, 'month' => 30, default => 7
        };

        $stmt = $this->pdo->prepare("
            SELECT 
                u.name as staff_name,
                fvr.*,
                fp.pump_number,
                ft.name as fuel_type_name
            FROM fuel_variance_reports fvr
            JOIN fuel_pumps fp ON fvr.pump_id = fp.id
            JOIN fuel_types ft ON fvr.fuel_type_id = ft.id
            JOIN fuel_daily_readings fdr ON fdr.id = fvr.reference_id
            JOIN users u ON fdr.user_id = u.id
            WHERE fvr.station_id = ? AND fvr.report_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY fvr.report_date DESC, fvr.variance_abs DESC
        ");

        $stmt->execute([$this->station_id, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Job Order Status Summary
     */
    public function job_order_status() {
        return [
            'pending' => $this->get_jo_count('Pending'),
            'in_progress' => $this->get_jo_count('In Progress'),
            'awaiting_parts' => $this->get_jo_count('Awaiting Parts'),
            'completed' => $this->get_jo_count('Completed')
        ];
    }

    private function get_jo_count($status) {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count, SUM(total_amount) as value
            FROM job_orders 
            WHERE station_id = ? AND status = ?
        ");
        $stmt->execute([$this->station_id, $status]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Staff Summaries
     */
    public function staff_summaries() {
        $stmt = $this->pdo->prepare("
            SELECT 
                u.id, u.name,
                SUM(t.total_amount) as total_sales_30d,
                COUNT(fdr.id) as readings_30d,
                COUNT(jo.id) as jobs_30d,
                AVG(DATEDIFF(clock_out, clock_in)) as avg_hours_shift
            FROM users u
            LEFT JOIN merchandise_transactions t ON t.staff_id = u.id AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            LEFT JOIN fuel_daily_readings fdr ON fdr.user_id = u.id AND fdr.reading_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            LEFT JOIN shift_reports sr ON sr.staff_id = u.id AND sr.shift_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            LEFT JOIN job_orders jo ON jo.created_by = u.id AND jo.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            WHERE u.station_id = ? AND u.role = 'staff'
            GROUP BY u.id
            ORDER BY total_sales_30d DESC
        ");
        $stmt->execute([$this->station_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Inventory Movement (last 30 days)
     */
    public function inventory_movement() {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.name,
                SUM(CASE WHEN t.type = 'in' THEN t.quantity ELSE 0 END) as received,
                SUM(CASE WHEN t.type = 'out' THEN t.quantity ELSE 0 END) as issued,
                SUM(CASE WHEN t.type = 'out' THEN t.quantity * p.cost_price ELSE 0 END) as cost_out
            FROM inventory_transactions t
            JOIN products p ON t.product_id = p.id
            WHERE t.station_id = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY p.id
            ORDER BY cost_out DESC
        ");
        $stmt->execute([$this->station_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// AJAX Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    require_login();

    $pdo = get_db_connection();
    $user = current_user();
    $station_id = user_station_id();
    $reports = new ManagerReports($pdo, $user, $station_id);

    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'team_performance':
                $data = $reports->team_performance($_POST['period'] ?? 'week');
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            case 'fuel_variance':
                $data = $reports->fuel_variance_report($_POST['period'] ?? 'week');
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            case 'jo_status':
                $data = $reports->job_order_status();
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            case 'staff_summaries':
                $data = $reports->staff_summaries();
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            case 'inventory_movement':
                $data = $reports->inventory_movement();
                echo json_encode(['success' => true, 'data' => $data]);
                break;
            default:
                throw new Exception('Invalid report type');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

