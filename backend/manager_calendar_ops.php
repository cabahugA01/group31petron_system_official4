<?php
/**
 * Manager Calendar Operations
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/calendar_module_helpers.php';

class ManagerCalendarOps
{
    private PDO $pdo;
    private array $user;
    private int $station_id;

    public function __construct(PDO $pdo, array $user, int $station_id)
    {
        $role = role_key($user['role'] ?? '');
        if (!in_array($role, ['manager', 'admin', 'superadmin'], true)) {
            throw new RuntimeException('Manager or admin access is required.');
        }

        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }

    public function get_calendar_data(string $start_date, string $end_date): array
    {
        $start = calendar_normalize_date($start_date) ?: date('Y-m-01');
        $end = calendar_normalize_date($end_date) ?: date('Y-m-t');
        $events = [];

        $stmt = $this->pdo->prepare("
            SELECT ss.id, ss.user_id AS staff_id, ss.scheduled_date, ss.shift AS shift_type,
                   ss.status, u.name AS staff_name, s.start_time, s.end_time
            FROM staff_schedules ss
            JOIN users u ON ss.user_id = u.id
            LEFT JOIN shifts s ON ss.shift = s.name
            WHERE u.station_id = ? AND ss.scheduled_date BETWEEN ? AND ?
            ORDER BY ss.scheduled_date, s.start_time
        ");
        $stmt->execute([$this->station_id, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $shiftStart = $row['start_time'] ?: '00:00:00';
            $shiftEnd = $row['end_time'] ?: $shiftStart;
            $events[] = [
                'id' => 'shift_' . $row['id'],
                'staff_id' => (int)$row['staff_id'],
                'shift_date' => $row['scheduled_date'],
                'shift_type' => $row['shift_type'],
                'status' => strtolower($row['status'] ?? 'scheduled'),
                'title' => trim(($row['staff_name'] ?? 'Staff') . ' - ' . $row['shift_type']),
                'start_time' => $row['scheduled_date'] . ' ' . $shiftStart,
                'end_time' => $row['scheduled_date'] . ' ' . $shiftEnd,
                'color_code' => '#3498db',
                'type' => 'shift',
            ];
        }

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.encoded_by AS staff_id, DATE(d.delivery_date) AS event_date,
                   d.supplier, d.product, d.status, u.name AS staff_name
            FROM deliveries_oversight d
            LEFT JOIN users u ON d.encoded_by = u.id
            WHERE d.station_id = ? AND DATE(d.delivery_date) BETWEEN ? AND ?
            ORDER BY d.delivery_date
        ");
        $stmt->execute([$this->station_id, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'delivery_' . $row['id'],
                'staff_id' => (int)$row['staff_id'],
                'shift_date' => $row['event_date'],
                'shift_type' => 'Delivery',
                'status' => strtolower($row['status'] ?? 'pending'),
                'title' => 'Delivery - ' . trim(($row['supplier'] ?? '') . ' ' . ($row['product'] ?? '')),
                'start_time' => $row['event_date'] . ' 00:00:00',
                'end_time' => $row['event_date'] . ' 00:00:00',
                'color_code' => '#8e24aa',
                'type' => 'delivery',
            ];
        }

        $stmt = $this->pdo->prepare("
            SELECT jo.id, jo.created_by AS staff_id, DATE(jo.created_at) AS event_date,
                   jo.job_order_number, jo.service_type, jo.customer_name, jo.status, u.name AS staff_name
            FROM job_orders jo
            LEFT JOIN users u ON jo.created_by = u.id
            WHERE jo.station_id = ? AND DATE(jo.created_at) BETWEEN ? AND ?
            ORDER BY jo.created_at
        ");
        $stmt->execute([$this->station_id, $start, $end]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[] = [
                'id' => 'job_order_' . $row['id'],
                'staff_id' => (int)$row['staff_id'],
                'shift_date' => $row['event_date'],
                'shift_type' => 'Job Order',
                'status' => strtolower($row['status'] ?? 'pending'),
                'title' => 'JO ' . ($row['job_order_number'] ?: $row['id']) . ' - ' . ($row['customer_name'] ?? 'Customer'),
                'start_time' => $row['event_date'] . ' 00:00:00',
                'end_time' => $row['event_date'] . ' 00:00:00',
                'color_code' => '#f39c12',
                'type' => 'job_order',
            ];
        }

        return $events;
    }

    public function assign_shift(int $staff_id, string $shift_date, string $shift_type, string $color = '#3498db', string $notes = ''): array
    {
        $date = calendar_normalize_date($shift_date);
        $shift = calendar_clean_text($shift_type);
        if (!$staff_id || !$date || !$shift) {
            return ['success' => false, 'message' => 'Staff, date, and shift are required.'];
        }

        if (!$this->staffBelongsToStation($staff_id)) {
            return ['success' => false, 'message' => 'Selected staff is not assigned to this station.'];
        }

        $check = $this->pdo->prepare("SELECT id FROM staff_schedules WHERE user_id = ? AND scheduled_date = ? LIMIT 1");
        $check->execute([$staff_id, $date]);
        $existingId = (int)$check->fetchColumn();

        if ($existingId) {
            $stmt = $this->pdo->prepare("UPDATE staff_schedules SET shift = ?, status = 'scheduled', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$shift, $existingId]);
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO staff_schedules (user_id, shift, scheduled_date, status) VALUES (?, ?, ?, 'scheduled')");
            $stmt->execute([$staff_id, $shift, $date]);
        }

        log_activity($this->pdo, (int)$this->user['id'], 'Shift Assigned', "Assigned $shift shift on $date to staff #$staff_id. $notes");
        return ['success' => true, 'message' => 'Shift assigned'];
    }

    public function get_assignable_staff(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, name, username
            FROM users
            WHERE station_id = ? AND role = 'staff' AND status = 'Active'
            ORDER BY name
        ");
        $stmt->execute([$this->station_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sync_shift_status(int $shift_id): array
    {
        $stmt = $this->pdo->prepare("
            SELECT ss.id, ss.user_id, ss.scheduled_date,
                   COUNT(jo.id) AS job_orders,
                   SUM(CASE WHEN jo.status IN ('Completed', 'Verified', 'finalized') THEN 1 ELSE 0 END) AS completed_jobs
            FROM staff_schedules ss
            LEFT JOIN job_orders jo ON jo.assigned_mechanic_id = ss.user_id AND DATE(jo.created_at) = ss.scheduled_date
            JOIN users u ON ss.user_id = u.id
            WHERE ss.id = ? AND u.station_id = ?
            GROUP BY ss.id, ss.user_id, ss.scheduled_date
        ");
        $stmt->execute([$shift_id, $this->station_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            return ['success' => false, 'message' => 'Shift not found.'];
        }

        $status = ((int)$data['job_orders'] > 0 && (int)$data['job_orders'] === (int)$data['completed_jobs']) ? 'completed' : 'scheduled';
        $update = $this->pdo->prepare("UPDATE staff_schedules SET status = ? WHERE id = ?");
        $update->execute([$status, $shift_id]);
        return ['success' => true, 'status' => $status];
    }

    private function staffBelongsToStation(int $staff_id): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND station_id = ?");
        $stmt->execute([$staff_id, $this->station_id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');

    try {
        calendar_ensure_schema($pdo);
        $ops = new ManagerCalendarOps($pdo, current_user(), (int)user_station_id());
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'get_data':
                echo json_encode(['success' => true, 'events' => $ops->get_calendar_data($_POST['start'] ?? '', $_POST['end'] ?? '')]);
                break;
            case 'assign_shift':
                echo json_encode($ops->assign_shift(
                    (int)($_POST['staff_id'] ?? 0),
                    $_POST['shift_date'] ?? '',
                    $_POST['shift_type'] ?? '',
                    $_POST['color'] ?? '#3498db',
                    $_POST['notes'] ?? ''
                ));
                break;
            case 'get_staff':
                echo json_encode(['success' => true, 'staff' => $ops->get_assignable_staff()]);
                break;
            case 'sync_status':
                echo json_encode($ops->sync_shift_status((int)($_POST['shift_id'] ?? 0)));
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
