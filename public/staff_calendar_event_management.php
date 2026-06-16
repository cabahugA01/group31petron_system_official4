<?php
$page_id = 'staff_calendar_management';
require_once __DIR__ . '/../config/database_config.php';
require_once __DIR__ . '/../includes/session.php';
require_login();

// Check if user has access (manager, admin only for event management)
$allowed_roles = ['manager', 'admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    $_SESSION['error'] = 'Access denied. Manager or admin access required for event management.';
    header('Location: staff_calendar.php');
    exit;
}

$station_id = $_SESSION['station_id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $_SESSION['error'] = 'Database connection error. Please contact administrator.';
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_event':
                $event_type_id = $_POST['event_type_id'] ?? '';
                $staff_encoder_id = $_POST['staff_encoder_id'] ?? '';
                $manager_assigned_id = $_POST['manager_assigned_id'] ?? '';
                $event_date = $_POST['event_date'] ?? '';
                $start_time = $_POST['start_time'] ?? '';
                $end_time = $_POST['end_time'] ?? '';
                $work_description = $_POST['work_description'] ?? '';
                $status = $_POST['status'] ?? 'pending';
                $notes = $_POST['notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO staff_calendar_events 
                        (station_id, event_type_id, staff_encoder_id, manager_assigned_id, 
                         event_date, start_time, end_time, work_description, status, notes)
                        VALUES 
                        (:station_id, :event_type_id, :staff_encoder_id, :manager_assigned_id,
                         :event_date, :start_time, :end_time, :work_description, :status, :notes)
                    ");
                    
                    $stmt->execute([
                        ':station_id' => $station_id,
                        ':event_type_id' => $event_type_id,
                        ':staff_encoder_id' => $staff_encoder_id,
                        ':manager_assigned_id' => $manager_assigned_id ?: null,
                        ':event_date' => $event_date,
                        ':start_time' => $start_time,
                        ':end_time' => $end_time,
                        ':work_description' => $work_description,
                        ':status' => $status,
                        ':notes' => $notes ?: null
                    ]);
                    
                    $_SESSION['success'] = 'Calendar event created successfully!';
                    header('Location: staff_calendar_event_management.php');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error creating event: ' . $e->getMessage();
                    header('Location: staff_calendar_event_management.php');
                    exit;
                }
                break;
                
            case 'update_event':
                $event_id = $_POST['event_id'] ?? '';
                $event_type_id = $_POST['event_type_id'] ?? '';
                $staff_encoder_id = $_POST['staff_encoder_id'] ?? '';
                $manager_assigned_id = $_POST['manager_assigned_id'] ?? '';
                $event_date = $_POST['event_date'] ?? '';
                $start_time = $_POST['start_time'] ?? '';
                $end_time = $_POST['end_time'] ?? '';
                $work_description = $_POST['work_description'] ?? '';
                $status = $_POST['status'] ?? '';
                $notes = $_POST['notes'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE staff_calendar_events 
                        SET event_type_id = :event_type_id,
                            staff_encoder_id = :staff_encoder_id,
                            manager_assigned_id = :manager_assigned_id,
                            event_date = :event_date,
                            start_time = :start_time,
                            end_time = :end_time,
                            work_description = :work_description,
                            status = :status,
                            notes = :notes,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :event_id AND station_id = :station_id
                    ");
                    
                    $stmt->execute([
                        ':event_id' => $event_id,
                        ':event_type_id' => $event_type_id,
                        ':staff_encoder_id' => $staff_encoder_id,
                        ':manager_assigned_id' => $manager_assigned_id ?: null,
                        ':event_date' => $event_date,
                        ':start_time' => $start_time,
                        ':end_time' => $end_time,
                        ':work_description' => $work_description,
                        ':status' => $status,
                        ':notes' => $notes ?: null,
                        ':station_id' => $station_id
                    ]);
                    
                    $_SESSION['success'] = 'Event updated successfully!';
                    header('Location: staff_calendar_event_management.php');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error updating event: ' . $e->getMessage();
                    header('Location: staff_calendar_event_management.php');
                    exit;
                }
                break;
                
            case 'delete_event':
                $event_id = $_POST['event_id'] ?? '';
                
                try {
                    $stmt = $pdo->prepare("
                        DELETE FROM staff_calendar_events 
                        WHERE id = :event_id AND station_id = :station_id
                    ");
                    $stmt->execute([
                        ':event_id' => $event_id,
                        ':station_id' => $station_id
                    ]);
                    
                    $_SESSION['success'] = 'Event deleted successfully!';
                    header('Location: staff_calendar_event_management.php');
                    exit;
                    
                } catch (Exception $e) {
                    $_SESSION['error'] = 'Error deleting event: ' . $e->getMessage();
                    header('Location: staff_calendar_event_management.php');
                    exit;
                }
                break;
        }
    }
}

// Get data for forms
$event_types = [];
$staff_members = [];
$managers = [];
$events = [];

try {
    // Get event types
    $stmt = $pdo->prepare("
        SELECT `user_id`, type_key, type_name, description, icon_class, color_class 
        FROM staff_event_types 
        WHERE is_active = TRUE 
        ORDER BY sort_order
    ");
    $stmt->execute();
    $event_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get staff members
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email,
               COALESCE(scc.color_code, '#007bff') as color_code,
               COALESCE(scc.color_name, 'Blue') as color_name
        FROM users u
        LEFT JOIN staff_color_config scc ON u.id = scc.user_id AND scc.is_active = TRUE
        WHERE u.station_id = :station_id 
        AND u.role IN ('staff', 'cashier', 'pump_attendant')
        AND u.account_status = 'Active'
        ORDER BY u.first_name, u.last_name
    ");
    $stmt->execute([':station_id' => $station_id]);
    $staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get managers
    $stmt = $pdo->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email,
               COALESCE(mcc.color_code, '#dc3545') as color_code,
               COALESCE(mcc.color_name, 'Red') as color_name
        FROM users u
        LEFT JOIN manager_color_config mcc ON u.id = mcc.user_id AND mcc.is_active = TRUE
        WHERE u.station_id = :station_id 
        AND u.role IN ('manager', 'admin')
        AND u.account_status = 'Active'
        ORDER BY u.first_name, u.last_name
    ");
    $stmt->execute([':station_id' => $station_id]);
    $managers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get events for current month
    $current_month = date('Y-m');
    $stmt = $pdo->prepare("
        SELECT * FROM staff_calendar_summary 
        WHERE station_id = :station_id 
        AND DATE_FORMAT(event_date, '%Y-%m') = :month
        ORDER BY event_date, start_time
    ");
    $stmt->execute([
        ':station_id' => $station_id,
        ':month' => $current_month
    ]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Calendar Event Management - Petron Station Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .management-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #003d7a 0%, #002855 100%);
            color: white;
            border-radius: 12px;
        }

        .page-title h1 {
            margin: 0;
            color: white;
        }

        .event-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 25px;
            margin-bottom: 20px;
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

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #003d7a;
            box-shadow: 0 0 0 3px rgba(0,61,122,0.1);
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

        .events-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .events-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .events-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e9ecef;
        }

        .events-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .event-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .staff-color-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 4px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }

        .btn-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .events-table {
                overflow:hidden;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../partials/header.php'; ?>

    <div class="management-container">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-calendar-edit"></i> Event Management</h1>
                <div class="sub">Create and manage staff calendar events</div>
            </div>
            <div>
                <a href="staff_calendar.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Calendar
                </a>
            </div>
        </div>

        <?php if($msg): ?>
        <div class="alert <?php echo strpos($msg, 'Error') !== false ? 'alert-danger' : 'alert-success'; ?> alert-dismissible fade show" role="alert">
            <?php echo $msg; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Create Event Form -->
        <div class="event-card">
            <h2 style="margin-bottom: 30px; color: #003d7a;">
                <i class="fas fa-plus-circle"></i> Create New Event
            </h2>
            
            <form method="post" action="staff_calendar_event_management.php">
                <input type="hidden" name="action" value="create_event">
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Event Type *</label>
                        <select name="event_type_id" class="form-select" required>
                            <option value="">Select event type</option>
                            <?php foreach ($event_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <i class="<?php echo $type['icon_class']; ?>"></i> <?php echo $type['type_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Staff Encoder *</label>
                        <select name="staff_encoder_id" class="form-select" required>
                            <option value="">Select staff member</option>
                            <?php foreach ($staff_members as $staff): ?>
                                <option value="<?php echo $staff['id']; ?>">
                                    <span class="staff-color-indicator" style="background: <?php echo $staff['color_code']; ?>"></span>
                                    <?php echo $staff['first_name'] . ' ' . $staff['last_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Manager Assigned</label>
                        <select name="manager_assigned_id" class="form-select">
                            <option value="">Select manager (optional)</option>
                            <?php foreach ($managers as $manager): ?>
                                <option value="<?php echo $manager['id']; ?>">
                                    <span class="staff-color-indicator" style="background: <?php echo $manager['color_code']; ?>"></span>
                                    <?php echo $manager['first_name'] . ' ' . $manager['last_name']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Event Date *</label>
                        <input type="date" name="event_date" class="form-input" 
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Start Time *</label>
                        <input type="time" name="start_time" class="form-input" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">End Time *</label>
                        <input type="time" name="end_time" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Work Description *</label>
                        <input type="text" name="work_description" class="form-input" 
                               placeholder="e.g., Encode Job Order" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-input" rows="3" 
                              placeholder="Additional notes or comments"></textarea>
                </div>

                <div style="display: flex; gap: 15px; margin-top: 30px;">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save"></i> Create Event
                    </button>
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- Events List -->
        <div class="events-card">
            <h2 style="margin-bottom: 20px; color: #003d7a;">
                <i class="fas fa-list"></i> Current Month Events
                <span style="background: #003d7a; color: white; padding: 4px 12px; border-radius: 20px; font-size: 14px; margin-left: 10px;">
                    <?php echo count($events); ?> events
                </span>
            </h2>
            
            <div class="events-table">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Event Type</th>
                            <th>Staff</th>
                            <th>Manager</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($event['event_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($event['start_time'])); ?></td>
                            <td>
                                <span class="event-type-badge" style="background: <?php echo $event['event_color']; ?>20; color: <?php echo $event['event_color']; ?>;">
                                    <i class="<?php echo $event['event_icon']; ?>"></i> <?php echo $event['event_type']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="staff-color-indicator" style="background: <?php echo $event['assigned_color']; ?>"></span>
                                <?php echo $event['staff_encoder_name']; ?>
                            </td>
                            <td>
                                <?php if ($event['manager_assigned_name']): ?>
                                    <?php echo $event['manager_assigned_name']; ?>
                                <?php else: ?>
                                    <em>Not assigned</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $event['work_description']; ?></td>
                            <td>
                                <span class="status-badge <?php echo $event['status_badge']; ?>">
                                    <?php echo $event['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-sm btn-edit" onclick="editEvent(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-sm btn-delete" onclick="deleteEvent(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editEvent(eventId) {
            // This would open an edit modal or redirect to edit page
            alert('Edit functionality would be implemented here for event ID: ' + eventId);
        }

        function deleteEvent(eventId) {
            if (confirm('Are you sure you want to delete this event?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'staff_calendar_event_management.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_event';
                
                const eventIdInput = document.createElement('input');
                eventIdInput.type = 'hidden';
                eventIdInput.name = 'event_id';
                eventIdInput.value = eventId;
                
                form.appendChild(actionInput);
                form.appendChild(eventIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>

    <?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
