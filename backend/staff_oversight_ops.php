<?php
/**
 * Staff Oversight Operations
 * Manager-side: pull logs, shift summaries, validate/flagging
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/fuel_shift_operations.php'; // Reuse shift summary

class StaffOversightOps {
    private $pdo;
    private $user;
    private $station_id;

    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
        require_permission('manage_staff_oversight');
    }

    /**
     * Get staff clock-in/out logs + shift summaries (auto-pull)
     * JOINs activity_logs + fuel_daily_readings + job_orders
     */
    public function get_staff_logs($date_from = null, $date_to = null, $staff_id = null) {
        $where = 'WHERE s.id = ?';
        $params = [$this->station_id];
        
        if ($date_from) {
            $where .= ' AND DATE(l.created_at) >= ?';
            $params[] = $date_from;
        }
        if ($date_to) {
            $where .= ' AND DATE(l.created_at) <= ?';
            $params[] = $date_to;
        }
        if ($staff_id) {
            $where .= ' AND l.user_id = ?';
            $params[] = $staff_id;
        }

        $stmt = $this->pdo->prepare("
            SELECT 
                u.id as staff_id, u.name, u.username,
                l.action, l.details, l.created_at,
                COUNT(fdr.id) as fuel_readings,
                COUNT(jo.id) as job_orders,
                SUM(jo.total_amount) as jo_total,
                AVG(CASE WHEN jo.status = 'Completed' THEN 1 ELSE 0 END) as completion_rate
            FROM activity_logs l
            JOIN users u ON l.user_id = u.id
            LEFT JOIN fuel_daily_readings fdr ON fdr.user_id = u.id AND fdr.station_id = s.id
            LEFT JOIN job_orders jo ON jo.created_by = u.id AND jo.station_id = s.id
            JOIN stations s ON u.station_id = s.id
            $where
            GROUP BY u.id, DATE(l.created_at), l.action
            ORDER BY l.created_at DESC
            LIMIT 100
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get shift summaries for oversight (reuse FuelShiftOperations)
     */
    public function get_shift_summaries($shift_type = null, $date = null) {
        $fso = new FuelShiftOperations($this->pdo, $this->user);
        return $fso->get_shift_summary($this->station_id, $shift_type, $date);
    }

    /**
     * Flag suspicious entry (logs + audit trail)
     */
    public function flag_entry($table, $record_id, $note) {
        require_permission('manage_staff_oversight');
        
        // Generic flagging for fuel_daily_readings, job_orders, shift_reports
        $allowed_tables = ['fuel_daily_readings', 'job_orders', 'shift_reports'];
        if (!in_array($table, $allowed_tables)) {
            throw new Exception('Invalid table');
        }

        $this->pdo->beginTransaction();
        try {
            // Add flag + manager note
            $stmt = $this->pdo->prepare("
                UPDATE $table 
                SET flagged = 1, manager_notes = CONCAT(COALESCE(manager_notes, ''), ?), 
                    updated_at = NOW()
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute(["\n[FLAGGED: $note]", $record_id, $this->station_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Record not found or wrong station');
            }

            // Log activity
            log_activity($this->pdo, $this->user['id'], 'Staff Entry Flagged', 
                "Flagged $table #$record_id: $note");

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Entry flagged'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Validate encoding (mark as validated)
     */
    public function validate_entry($table, $record_id, $note = null) {
        require_permission('manage_staff_oversight');
        
        $allowed_tables = ['fuel_daily_readings', 'job_orders', 'shift_reports'];
        if (!in_array($table, $allowed_tables)) {
            throw new Exception('Invalid table');
        }

        $this->pdo->beginTransaction();
        try {
            $update_note = $note ? " [Note: $note]" : '';
            
            $stmt = $this->pdo->prepare("
                UPDATE $table 
                SET validated_by = ?, validated_at = NOW(),
                    manager_notes = CONCAT(COALESCE(manager_notes, ''), ?),
                    flagged = 0
                WHERE id = ? AND station_id = ?
            ");
            $stmt->execute([$this->user['id'], $update_note, $record_id, $this->station_id]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Record not found');
            }

            log_activity($this->pdo, $this->user['id'], 'Entry Validated', 
                "Validated $table #$record_id" . ($note ? ": $note" : ''));

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Entry validated'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get performance reports (aggregate)
     */
    public function get_performance_report($staff_id = null, $period = 'week') {
        $where = 'WHERE jo.station_id = ?';
        $params = [$this->station_id];

        if ($staff_id) {
            $where .= ' AND (jo.created_by = ? OR jo.assigned_mechanic_id = ?)';
            $params[] = $staff_id;
            $params[] = $staff_id;
        }

        $days = match($period) {
            'week' => 7, 'month' => 30, default => 7
        };

        $stmt = $this->pdo->prepare("
            SELECT 
                u.id, u.name,
                COUNT(jo.id) as total_jo,
                SUM(CASE WHEN jo.status = 'Completed' THEN 1 ELSE 0 END) as completed,
                AVG(jo.total_amount) as avg_amount,
                COUNT(fdr.id) as readings,
                SUM(fdr.sales_liters) as total_liters
            FROM users u
            LEFT JOIN job_orders jo ON (jo.created_by = u.id OR jo.assigned_mechanic_id = u.id)
            LEFT JOIN fuel_daily_readings fdr ON fdr.user_id = u.id
            $where
            AND jo.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY u.id
            ORDER BY completed DESC
        ");

        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get flagged items
     */
    public function get_flagged_items() {
        $items = [];
        // fuel_daily_readings
        try {
            $stmt1 = $this->pdo->prepare("SELECT 'Fuel Reading' as type, 'fuel_daily_readings' as `table`, id, user_id as staff_id, created_at as date, manager_notes as note, flagged as status FROM fuel_daily_readings WHERE flagged = 1 AND station_id = ?");
            $stmt1->execute([$this->station_id]);
            $items = array_merge($items, $stmt1->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e) {}

        // job_orders
        try {
            $stmt2 = $this->pdo->prepare("SELECT 'Job Order' as type, 'job_orders' as `table`, id, created_by as staff_id, created_at as date, manager_notes as note, flagged as status FROM job_orders WHERE flagged = 1 AND station_id = ?");
            $stmt2->execute([$this->station_id]);
            $items = array_merge($items, $stmt2->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e) {}
        
        // shift_reports
        try {
            $stmt3 = $this->pdo->prepare("SELECT 'Shift Report' as type, 'shift_reports' as `table`, id, user_id as staff_id, created_at as date, manager_notes as note, flagged as status FROM shift_reports WHERE flagged = 1 AND station_id = ?");
            $stmt3->execute([$this->station_id]);
            $items = array_merge($items, $stmt3->fetchAll(PDO::FETCH_ASSOC));
        } catch(Exception $e) {}

        foreach ($items as &$item) {
            $u = $this->pdo->prepare("SELECT name FROM users WHERE user_id = ?");
            $u->execute([$item['staff_id']]);
            $item['staff'] = $u->fetchColumn() ?: 'Unknown';
            $item['status'] = 'Flagged';
        }
        
        usort($items, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $items;
    }
}

// API Endpoints (AJAX handler)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';
    $pdo = get_db_connection(); // Assume global helper
    $user = current_user();
    $station_id = user_station_id();

    $ops = new StaffOversightOps($pdo, $user, $station_id);

    try {
        switch ($action) {
            case 'get_logs':
                $result = $ops->get_staff_logs($_POST['date_from'] ?? null, $_POST['date_to'] ?? null, $_POST['staff_id'] ?? null);
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'get_shift_summaries':
                $result = $ops->get_shift_summaries($_POST['shift'] ?? null, $_POST['date'] ?? null);
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'flag':
                $result = $ops->flag_entry($_POST['table'], (int)$_POST['id'], $_POST['note']);
                echo json_encode($result);
                break;
            case 'validate':
                $result = $ops->validate_entry($_POST['table'], (int)$_POST['id'], $_POST['note'] ?? null);
                echo json_encode($result);
                break;
            case 'performance':
                $result = $ops->get_performance_report($_POST['staff_id'] ?? null, $_POST['period'] ?? 'week');
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'get_flagged_items':
                $result = $ops->get_flagged_items();
                echo json_encode(['success' => true, 'data' => $result]);
                break;
            case 'staff_list':
                $stmt = $pdo->prepare("SELECT `user_id`, name FROM users WHERE station_id = ? AND role IN ('staff', 'cashier', 'mechanic')");
                $stmt->execute([$station_id]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                break;
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>

