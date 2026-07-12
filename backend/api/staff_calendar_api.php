<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_once __DIR__ . '/../calendar_module_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user']) && !empty($_SESSION['user_id'])) {
    $_SESSION['user'] = [
        'id' => (int)$_SESSION['user_id'],
        'role' => $_SESSION['role'] ?? 'staff',
        'station_id' => $_SESSION['station_id'] ?? null,
    ];
}

require_login();
calendar_ensure_schema($pdo);

class StaffCalendarAPI
{
    private PDO $pdo;
    private array $user;
    private int $station_id;
    private int $user_id;
    private string $role;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->user = current_user();
        $this->station_id = (int)user_station_id();
        $this->user_id = (int)($this->user['id'] ?? 0);
        $this->role = role_key($this->user['role'] ?? 'staff');

        if (!$this->station_id || !$this->user_id) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    }

    public function handleRequest(): array
    {
        $action = $_GET['action'] ?? '';

        try {
            switch ($action) {
                case 'get_events':
                    $month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
                    return ['success' => true, 'events' => $this->eventsBetween($month . '-01', date('Y-m-t', strtotime($month . '-01')), $_GET['status'] ?? '')];
                case 'get_event_types':
                    return $this->getEventTypes();
                case 'get_staff_members':
                    return $this->getStaffMembers();
                case 'get_managers':
                    return $this->getManagers();
                case 'add_event':
                    return $this->addEvent();
                case 'update_event':
                    return $this->updateEvent();
                case 'delete_event':
                    return $this->deleteEvent();
                case 'get_dashboard_data':
                    return $this->getDashboardData();
                case 'get_today_events':
                    return ['success' => true, 'events' => $this->eventsBetween(date('Y-m-d'), date('Y-m-d'))];
                case 'get_upcoming_events':
                    return ['success' => true, 'events' => $this->eventsBetween(date('Y-m-d'), date('Y-m-d', strtotime('+3 days')))];
                default:
                    http_response_code(400);
                    return ['success' => false, 'error' => 'Invalid action'];
            }
        } catch (Throwable $e) {
            http_response_code(500);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function requestData(): array
    {
        $raw = file_get_contents('php://input');
        $json = $raw ? json_decode($raw, true) : null;
        return is_array($json) ? $json : $_POST;
    }

    private function eventsBetween(string $start, string $end, string $status = ''): array
    {
        $sql = "SELECT sce.*, et.type_key, et.type_name, et.icon_class, et.color_class,
                       su.name AS staff_name, mgr.name AS manager_name
                FROM staff_calendar_events sce
                JOIN staff_event_types et ON sce.event_type_id = et.id
                JOIN users su ON sce.staff_encoder_id = su.id
                LEFT JOIN users mgr ON sce.manager_assigned_id = mgr.id
                WHERE sce.station_id = :station_id
                  AND sce.event_date BETWEEN :start_date AND :end_date";
        $params = [
            ':station_id' => $this->station_id,
            ':start_date' => $start,
            ':end_date' => $end,
        ];

        if ($this->role === 'staff') {
            $sql .= " AND (sce.staff_encoder_id = :user_id OR sce.manager_assigned_id = :user_id)";
            $params[':user_id'] = $this->user_id;
        }

        if ($status !== '') {
            $sql .= " AND sce.status = :status";
            $params[':status'] = calendar_normalize_status($status);
        }

        $sql .= " ORDER BY sce.event_date, sce.start_time";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getEventTypes(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, type_key, type_name, description, icon_class, color_class
            FROM staff_event_types
            WHERE is_active = 1
            ORDER BY sort_order, type_name
        ");
        return ['success' => true, 'event_types' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function getStaffMembers(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, first_name, last_name, email, name
            FROM users
            WHERE station_id = ? AND role = 'staff' AND status = 'Active'
            ORDER BY first_name, last_name
        ");
        $stmt->execute([$this->station_id]);
        return ['success' => true, 'staff' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function getManagers(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, first_name, last_name, email, name
            FROM users
            WHERE station_id = ? AND role IN ('manager', 'admin', 'superadmin') AND status = 'Active'
            ORDER BY first_name, last_name
        ");
        $stmt->execute([$this->station_id]);
        return ['success' => true, 'managers' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function addEvent(): array
    {
        $data = $this->requestData();
        $eventTypeId = (int)($data['event_type_id'] ?? 0);
        if (!$eventTypeId) {
            $eventTypeId = calendar_event_type_id($this->pdo, $data['event_type'] ?? 'other');
        }

        $eventDate = calendar_normalize_date($data['event_date'] ?? '');
        $description = calendar_clean_text($data['work_description'] ?? '');
        $startTime = calendar_normalize_time($data['start_time'] ?? '');
        $endTime = calendar_normalize_time($data['end_time'] ?? '', $startTime);
        $staffId = (int)($data['staff_encoder_id'] ?? $this->user_id);
        if ($this->role === 'staff') {
            $staffId = $this->user_id;
        }

        if (!$eventDate || !$description) {
            return ['success' => false, 'error' => 'Date and description are required'];
        }
        if (!$this->staffBelongsToStation($staffId)) {
            return ['success' => false, 'error' => 'Selected staff is not assigned to this station'];
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO staff_calendar_events
                (station_id, event_type_id, staff_encoder_id, manager_assigned_id, event_date,
                 start_time, end_time, work_description, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->station_id,
            $eventTypeId,
            $staffId,
            $data['manager_assigned_id'] ?? null,
            $eventDate,
            $startTime,
            $endTime,
            $description,
            calendar_normalize_status($data['status'] ?? 'pending'),
            $data['notes'] ?? null,
        ]);

        return ['success' => true, 'event_id' => (int)$this->pdo->lastInsertId(), 'message' => 'Event created successfully'];
    }

    private function updateEvent(): array
    {
        $data = $this->requestData();
        $eventId = (int)($data['event_id'] ?? 0);
        if (!$eventId) {
            return ['success' => false, 'error' => 'Event ID required'];
        }

        $event = $this->loadEvent($eventId);
        if (!$event || !$this->canManageEvent($event)) {
            return ['success' => false, 'error' => 'Event not found or not allowed'];
        }

        $stmt = $this->pdo->prepare("
            UPDATE staff_calendar_events
            SET event_type_id = ?, manager_assigned_id = ?, event_date = ?, start_time = ?,
                end_time = ?, work_description = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND station_id = ?
        ");
        $stmt->execute([
            (int)($data['event_type_id'] ?? $event['event_type_id']),
            $data['manager_assigned_id'] ?? $event['manager_assigned_id'],
            calendar_normalize_date($data['event_date'] ?? $event['event_date']),
            calendar_normalize_time($data['start_time'] ?? $event['start_time']),
            calendar_normalize_time($data['end_time'] ?? $event['end_time'], calendar_normalize_time($data['start_time'] ?? $event['start_time'])),
            calendar_clean_text($data['work_description'] ?? $event['work_description']),
            calendar_normalize_status($data['status'] ?? $event['status']),
            $data['notes'] ?? $event['notes'],
            $eventId,
            $this->station_id,
        ]);

        return ['success' => true, 'message' => 'Event updated successfully'];
    }

    private function deleteEvent(): array
    {
        $eventId = (int)($_GET['event_id'] ?? 0);
        $event = $this->loadEvent($eventId);
        if (!$event || !$this->canManageEvent($event)) {
            return ['success' => false, 'error' => 'Event not found or not allowed'];
        }

        $stmt = $this->pdo->prepare("DELETE FROM staff_calendar_events WHERE id = ? AND station_id = ?");
        $stmt->execute([$eventId, $this->station_id]);
        return ['success' => true, 'message' => 'Event deleted successfully'];
    }

    private function getDashboardData(): array
    {
        $today = date('Y-m-d');
        $upcoming = date('Y-m-d', strtotime('+3 days'));

        $events = $this->eventsBetween($today, $upcoming);
        $todayCount = 0;
        foreach ($events as $event) {
            if (($event['event_date'] ?? '') === $today) {
                $todayCount++;
            }
        }

        return [
            'success' => true,
            'dashboard_data' => [
                'today_events' => $todayCount,
                'upcoming_events' => count($events),
            ],
        ];
    }

    private function loadEvent(int $eventId): ?array
    {
        if (!$eventId) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM staff_calendar_events WHERE id = ? AND station_id = ?");
        $stmt->execute([$eventId, $this->station_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        return $event ?: null;
    }

    private function canManageEvent(array $event): bool
    {
        if (in_array($this->role, ['manager', 'admin', 'superadmin'], true)) {
            return true;
        }
        return (int)$event['staff_encoder_id'] === $this->user_id || (int)$event['manager_assigned_id'] === $this->user_id;
    }

    private function staffBelongsToStation(int $staffId): bool
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND station_id = ?");
        $stmt->execute([$staffId, $this->station_id]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

$api = new StaffCalendarAPI($pdo);
echo json_encode($api->handleRequest());
