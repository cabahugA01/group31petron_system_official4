<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'staff_management';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/classes/ShiftPeriodConfig.php';
require_login();
$me = current_user();

$role = role_key($me['role'] ?? 'staff');
if (!in_array($role, ['manager','admin','superadmin'])) { 
    header("Location: dashboard.php"); 
    exit; 
}

include __DIR__ . '/../partials/header.php';

$view = $_GET['view'] ?? 'active';
$station_id = user_station_id();
$msg = '';

// Initialize shift period configuration
$shift_config = getShiftPeriodConfig($pdo, $station_id);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'update_shift') {
            $user_id = $_POST['user_id'] ?? 0;
            $shift = $_POST['shift'] ?? '';
            $scheduled_date = $_POST['scheduled_date'] ?? date('Y-m-d');
            
            // Verify user belongs to this station
            $check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND station_id = ?");
            $check->execute([$user_id, $station_id]);
            if (!$check->fetch()) throw new Exception("Unauthorized");
            
            // Insert or update shift
            $stmt = $pdo->prepare("INSERT INTO staff_schedules (user_id, shift, scheduled_date, status) VALUES (?, ?, ?, 'scheduled') ON DUPLICATE KEY UPDATE shift = ?, scheduled_date = ?, updated_at = NOW()");
            $stmt->execute([$user_id, $shift, $scheduled_date, $shift, $scheduled_date]);
            $msg = "✅ Shift updated successfully.";
        }
        
        if ($action === 'assign_task') {
            $user_id = $_POST['user_id'] ?? 0;
            $task = $_POST['task'] ?? '';
            $priority = $_POST['priority'] ?? 'medium';
            $due_date = $_POST['due_date'] ?? null;
            
            // Verify user
            $check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND station_id = ?");
            $check->execute([$user_id, $station_id]);
            if (!$check->fetch()) throw new Exception("Unauthorized");
            
            $stmt = $pdo->prepare("INSERT INTO staff_tasks (user_id, task, priority, status, assigned_date, due_date) VALUES (?, ?, ?, 'pending', CURDATE(), ?)");
            $stmt->execute([$user_id, $task, $priority, $due_date]);
            $msg = "✅ Task assigned successfully.";
        }
        
    } catch (Exception $e) {
        $msg = "❌ " . $e->getMessage();
    }
}

// Get all staff for this station
$staff = [];
$stmt = $pdo->prepare("SELECT `user_id`, name, username, email, role, status, created_at FROM users WHERE station_id = ? AND role = 'staff' ORDER BY name");
$stmt->execute([$station_id]);
$staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="stock-page">
<div class="page-head">
    <div>
        <h1 class="h1">Staff Management</h1>
        <div class="sub">Manage your station's staff, schedules, tasks, and performance</div>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding: 15px; margin-bottom: 20px; background: <?php echo strpos($msg, '❌') !== false ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo strpos($msg, '❌') !== false ? '#721c24' : '#155724'; ?>;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Navigation Tabs -->
<div class="card" style="padding: 0; border: none;">
    <div style="display: flex; gap: 0; border-bottom: 1px solid #e5e7eb;">
        <a href="?view=active" class="nav-tab <?php echo $view === 'active' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'active' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-users"></i> Active Staff
        </a>
        <a href="?view=schedule" class="nav-tab <?php echo $view === 'schedule' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'schedule' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-calendar"></i> Schedule
        </a>
        <a href="?view=tasks" class="nav-tab <?php echo $view === 'tasks' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'tasks' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-tasks"></i> Tasks
        </a>
        <a href="?view=productivity" class="nav-tab <?php echo $view === 'productivity' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'productivity' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-chart-line"></i> Productivity
        </a>
        <a href="?view=qc" class="nav-tab <?php echo $view === 'qc' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'qc' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-check-circle"></i> Quality
        </a>
        <a href="?view=compliance" class="nav-tab <?php echo $view === 'compliance' ? 'active' : ''; ?>" style="padding: 12px 16px; text-decoration: none; border-bottom: 3px solid transparent; cursor: pointer; color: #6b7280; font-weight: 500; flex: 1; text-align: center; <?php echo $view === 'compliance' ? 'color: #3b82f6; border-bottom-color: #3b82f6;' : ''; ?>">
            <i class="fas fa-shield-alt"></i> Compliance
        </a>
    </div>
</div>

<!-- VIEW: Active Staff -->
<?php if($view === 'active'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Active Staff Members</h3>
        <div class="muted">Total: <?php echo count($staff); ?> staff</div>
    </div>
    <div style="padding: 20px;">
        <?php if(empty($staff)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #9ca3af;">
                <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 10px;"></i>
                <p>No staff members found</p>
            </div>
        <?php else: ?>
            <div style="overflow:hidden;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Name</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Username</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Email</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Status</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Joined</th>
                            <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($staff as $s): ?>
                        <tr style="border-bottom: 1px solid #e5e7eb; hover: background-color: #f9fafb;">
                            <td style="padding: 12px 16px; color: #1f2937; font-weight: 500;"><?php echo htmlspecialchars($s['name'] ?? ''); ?></td>
                            <td style="padding: 12px 16px; color: #6b7280;">@<?php echo htmlspecialchars($s['username'] ?? ''); ?></td>
                            <td style="padding: 12px 16px; color: #6b7280;"><?php echo htmlspecialchars($s['email'] ?? ''); ?></td>
                            <td style="padding: 12px 16px;">
                                <span style="background: <?php echo $s['status'] === 'active' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $s['status'] === 'active' ? '#065f46' : '#991b1b'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                    <?php echo ucfirst($s['status']); ?>
                                </span>
                            </td>
                            <td style="padding: 12px 16px; color: #6b7280; font-size: 13px;"><?php echo date('M d, Y', strtotime($s['created_at'])); ?></td>
                            <td style="padding: 12px 16px; text-align: center;">
                                <button class="btn-action" onclick="alert('View profile for: <?php echo htmlspecialchars($s['name']); ?>')" style="background: #e0e7ff; color: #4f46e5; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-right: 4px;">View</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- VIEW: Shift Schedule -->
<?php elseif($view === 'schedule'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Shift Schedule</h3>
        <div class="muted">Manage staff shift assignments</div>
    </div>
    <div style="padding: 20px;">
        <?php if(empty($staff)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #9ca3af;">
                <i class="fas fa-inbox" style="font-size: 40px; margin-bottom: 10px;"></i>
                <p>No staff members found</p>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                <?php foreach($staff as $s): ?>
                <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; background: #f9fafb;">
                    <h4 style="margin: 0 0 8px 0; color: #1f2937;"><?php echo htmlspecialchars($s['name']); ?></h4>
                    <p style="margin: 0 0 12px 0; color: #6b7280; font-size: 13px;">@<?php echo htmlspecialchars($s['username']); ?></p>
                    <form method="post" style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <input type="hidden" name="action" value="update_shift">
                        <input type="hidden" name="user_id" value="<?php echo $s['id']; ?>">
                        <input type="date" name="scheduled_date" style="flex: 0 1 120px; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;" value="<?php echo date('Y-m-d'); ?>">
                        <select name="shift" style="flex: 1; padding: 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 13px;" required>
                            <option value="">Select shift</option>
                            <?php echo $shift_config->generateShiftSelectOptions(); ?>
                        </select>
                        <button type="submit" style="background: #3b82f6; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-weight: 500;">Set</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top: 24px;">
                <h4 style="margin: 0 0 12px 0; color: #1f2937;">Scheduled Shifts</h4>
                <div style="overflow:hidden;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                                <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Staff Member</th>
                                <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Shift</th>
                                <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Scheduled Date</th>
                                <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $schedules = $pdo->prepare("
                                SELECT ss.*, u.name, u.username 
                                FROM staff_schedules ss 
                                JOIN users u ON ss.user_id = u.id 
                                WHERE u.station_id = ? AND ss.scheduled_date >= CURDATE()
                                ORDER BY ss.scheduled_date ASC, u.name ASC
                                LIMIT 30
                            ");
                            $schedules->execute([$station_id]);
                            $schedule_rows = $schedules->fetchAll(PDO::FETCH_ASSOC);
                            ?>
                            <?php if(empty($schedule_rows)): ?>
                                <tr>
                                    <td colspan="4" style="padding: 16px; text-align: center; color: #9ca3af;">No scheduled shifts</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach($schedule_rows as $s): ?>
                                <tr style="border-bottom: 1px solid #e5e7eb;">
                                    <td style="padding: 12px 16px; color: #1f2937; font-weight: 500;"><?php echo htmlspecialchars($s['name']); ?></td>
                                    <td style="padding: 12px 16px;">
                                        <?php 
                                        $shift_colors = ['first' => '#dbeafe', 'second' => '#fef3c7'];
                                        $shift_text = ['first' => '#0c4a6e', 'second' => '#78350f'];
                                        $shift = $s['shift'] ?? 'unset';
                                        $shift_period = $shift_config->getShiftByKey($shift);
                                        $shift_label = $shift_period ? $shift_period['shift_name'] : ucfirst($shift);
                                        ?>
                                        <span style="background: <?php echo $shift_colors[$shift] ?? '#e5e7eb'; ?>; color: <?php echo $shift_text[$shift] ?? '#1f2937'; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                            <?php echo $shift_label; ?>
                                        </span>
                                    </td>
                                    <td style="padding: 12px 16px; color: #6b7280; font-size: 13px;">
                                        <?php echo date('M d, Y (D)', strtotime($s['scheduled_date'])); ?>
                                    </td>
                                    <td style="padding: 12px 16px;">
                                        <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                            <?php echo ucfirst($s['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- VIEW: Task Assignments -->
<?php elseif($view === 'tasks'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Task Assignments</h3>
        <div class="muted">Assign and track staff tasks</div>
    </div>
    <div style="padding: 20px;">
        <div style="margin-bottom: 20px; padding: 16px; background: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 8px;">
            <h4 style="margin: 0 0 12px 0; color: #1f2937;">Assign New Task</h4>
            <form method="post" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
                <input type="hidden" name="action" value="assign_task">
                <select name="user_id" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;" required>
                    <option value="">Select staff member</option>
                    <?php foreach($staff as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="task" placeholder="Task description" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;" required>
                <input type="date" name="due_date" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                <select name="priority" style="padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <option value="low">Low Priority</option>
                    <option value="medium" selected>Medium Priority</option>
                    <option value="high">High Priority</option>
                </select>
                <button type="submit" style="background: #10b981; color: white; border: none; padding: 10px 16px; border-radius: 6px; cursor: pointer; font-weight: 500;">Assign Task</button>
            </form>
        </div>

        <div style="margin-top: 20px;">
            <h4 style="margin: 0 0 12px 0; color: #1f2937;">Pending Tasks</h4>
            <div style="overflow:hidden;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Staff Member</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Task</th>
                            <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Priority</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Due Date</th>
                            <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tasks = $pdo->prepare("
                            SELECT st.*, u.name, u.username 
                            FROM staff_tasks st 
                            JOIN users u ON st.user_id = u.id 
                            WHERE u.station_id = ? AND st.status IN ('pending', 'in_progress')
                            ORDER BY st.priority DESC, st.due_date ASC
                            LIMIT 20
                        ");
                        $tasks->execute([$station_id]);
                        $task_rows = $tasks->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <?php if(empty($task_rows)): ?>
                            <tr>
                                <td colspan="5" style="padding: 16px; text-align: center; color: #9ca3af;">No pending tasks</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($task_rows as $t): ?>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <td style="padding: 12px 16px; color: #1f2937; font-weight: 500;"><?php echo htmlspecialchars($t['name']); ?></td>
                                <td style="padding: 12px 16px; color: #6b7280;"><?php echo htmlspecialchars($t['task']); ?></td>
                                <td style="padding: 12px 16px; text-align: center;">
                                    <?php 
                                    $priority_colors = ['low' => '#d1d5db', 'medium' => '#fbbf24', 'high' => '#ef4444'];
                                    $priority_text = ['low' => '#1f2937', 'medium' => '#78350f', 'high' => '#ffffff'];
                                    $p = $t['priority'] ?? 'medium';
                                    ?>
                                    <span style="background: <?php echo $priority_colors[$p]; ?>; color: <?php echo $priority_text[$p]; ?>; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                        <?php echo ucfirst($p); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px 16px; color: #6b7280; font-size: 13px;">
                                    <?php echo $t['due_date'] ? date('M d, Y', strtotime($t['due_date'])) : '--'; ?>
                                </td>
                                <td style="padding: 12px 16px;">
                                    <span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                                        <?php echo ucfirst($t['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- VIEW: Productivity Metrics -->
<?php elseif($view === 'productivity'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Productivity Metrics</h3>
        <div class="muted">Track staff performance and efficiency</div>
    </div>
    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 20px;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 8px;">Total Transactions</div>
                <div style="font-size: 32px; font-weight: 700;">0</div>
            </div>
            <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; padding: 20px; border-radius: 8px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 8px;">Avg Response Time</div>
                <div style="font-size: 32px; font-weight: 700;">--</div>
            </div>
            <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; padding: 20px; border-radius: 8px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 8px;">Error Rate</div>
                <div style="font-size: 32px; font-weight: 700;">0%</div>
            </div>
            <div style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; padding: 20px; border-radius: 8px;">
                <div style="font-size: 12px; opacity: 0.9; margin-bottom: 8px;">Completion Rate</div>
                <div style="font-size: 32px; font-weight: 700;">--</div>
            </div>
        </div>
        <div style="background: #fef3c7; padding: 16px; border-radius: 8px; color: #92400e; text-align: center;">
            <i class="fas fa-chart-bar" style="margin-right: 8px;"></i>
            Productivity charts and detailed analytics coming soon
        </div>
    </div>
</div>

<!-- VIEW: Quality Control -->
<?php elseif($view === 'qc'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Quality Control Scores</h3>
        <div class="muted">Monitor staff quality metrics</div>
    </div>
    <div style="padding: 20px;">
        <div style="overflow:hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                        <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Staff Member</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Accuracy</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Speed</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Compliance</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Overall Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($staff as $s): ?>
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 12px 16px; color: #1f2937; font-weight: 500;"><?php echo htmlspecialchars($s['name']); ?></td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">--</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">--</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">--</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;">N/A</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top: 16px; background: #fef3c7; padding: 16px; border-radius: 8px; color: #92400e; text-align: center;">
            <i class="fas fa-lightbulb" style="margin-right: 8px;"></i>
            Quality metrics data will be populated as staff perform transactions
        </div>
    </div>
</div>

<!-- VIEW: Compliance Tracking -->
<?php elseif($view === 'compliance'): ?>
<div class="card" style="margin-top: 20px;">
    <div class="card-head">
        <h3 style="margin: 0;">Compliance Tracking</h3>
        <div class="muted">Monitor staff compliance and certifications</div>
    </div>
    <div style="padding: 20px;">
        <div style="overflow:hidden;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f9fafb; border-bottom: 1px solid #e5e7eb;">
                        <th style="padding: 12px 16px; text-align: left; font-weight: 600; color: #374151;">Staff Member</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Training Status</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Certifications</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Last Review</th>
                        <th style="padding: 12px 16px; text-align: center; font-weight: 600; color: #374151;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($staff as $s): ?>
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 12px 16px; color: #1f2937; font-weight: 500;"><?php echo htmlspecialchars($s['name']); ?></td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">Pending</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 500;">None</span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center; color: #6b7280; font-size: 13px;">--</td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <button style="background: #f59e0b; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500;">Review</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<div class="card" style="margin-top: 20px; padding: 40px; text-align: center;">
    <p style="color: #9ca3af;">Unknown view: <?php echo htmlspecialchars($view); ?></p>
</div>
<?php endif; ?>

<style>
    .card { 
        background: white; 
        border: 1px solid #e5e7eb; 
        border-radius: 8px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
    }
    .card-head { 
        padding: 16px 20px; 
        border-bottom: 1px solid #e5e7eb; 
    }
    .muted { 
        color: #6b7280; 
        font-size: 13px; 
    }
    .nav-tab {
        transition: all 0.2s;
    }
    .nav-tab:hover {
        background: #f3f4f6;
    }
    .btn-action:hover {
        opacity: 0.8;
    }
</style>

</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
