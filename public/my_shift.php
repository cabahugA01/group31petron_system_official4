<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'my_shift';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
$me = current_user();

$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));
if (!in_array($role, ['staff'])) { header("Location: dashboard.php"); exit; }

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
  <div>
    <h1 class="h1">My Shift</h1>
    <div class="sub">This page is part of the My Shift module.</div>
  </div>
</div>
<?php
$view = $_GET['view'] ?? 'today';
$labels = ['today'=>"Today's Schedule",'upcoming'=>'Upcoming Shifts','clock'=>'Clock In/Out','hours'=>'Hours Worked'];

// Fetch shift data
$shift_data = [];
$upcoming_shifts = [];
$hours_worked = [];

try {
    // Today's shift
    $stmt = $pdo->prepare("SELECT * FROM labor_sessions WHERE user_id = ? AND DATE(start_time) = CURDATE() ORDER BY start_time DESC");
    $stmt->execute([$me['id']]);
    $shift_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Upcoming shifts (future shifts without end_time yet)
    $stmt = $pdo->prepare("SELECT DATE(start_time) as shift_date, TIME(start_time) as shift_start, TIME(end_time) as shift_end FROM labor_sessions WHERE user_id = ? AND start_time > NOW() AND end_time IS NOT NULL ORDER BY start_time ASC LIMIT 10");
    $stmt->execute([$me['id']]);
    $upcoming_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format upcoming shifts for display
    $upcoming_shifts_formatted = [];
    foreach ($upcoming_shifts as $shift) {
        $upcoming_shifts_formatted[] = [
            'date' => $shift['shift_date'],
            'shift' => $shift['shift_start'] <= '14:00:00' ? 'Morning' : ($shift['shift_start'] < '22:00:00' ? 'Afternoon' : 'Night'),
            'time' => date('g:i A', strtotime($shift['shift_start'])) . ' - ' . date('g:i A', strtotime($shift['shift_end']))
        ];
    }
    $upcoming_shifts = $upcoming_shifts_formatted;
    
    // Hours worked this week
    $stmt = $pdo->prepare("SELECT SUM(hours_worked) as total_hours FROM labor_sessions WHERE user_id = ? AND YEARWEEK(start_time) = YEARWEEK(NOW()) AND end_time IS NOT NULL");
    $stmt->execute([$me['id']]);
    $hours_worked = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<section class="card">
  <div class="card-head">
    <div class="card-title"><i class="fas fa-clock"></i> <?php echo htmlspecialchars($labels[$view] ?? 'My Shift'); ?></div>
    <div class="muted">Shift and time tracking</div>
  </div>
  <div style="padding:16px;">
    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
      <a class="btn <?php echo $view === 'today' ? 'btn-primary' : 'ghost'; ?>" href="my_shift.php?view=today">Today</a>
      <a class="btn <?php echo $view === 'upcoming' ? 'btn-primary' : 'ghost'; ?>" href="my_shift.php?view=upcoming">Upcoming</a>
      <a class="btn <?php echo $view === 'clock' ? 'btn-primary' : 'ghost'; ?>" href="my_shift.php?view=clock">Clock</a>
      <a class="btn <?php echo $view === 'hours' ? 'btn-primary' : 'ghost'; ?>" href="my_shift.php?view=hours">Hours</a>
    </div>
    
    <?php if($view === 'today'): ?>
      <div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
        <div class="stat-card" style="background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e9ecef;">
          <div style="font-size:24px; font-weight:bold; color:#0066cc;">
            <?php 
            $current_session = null;
            foreach($shift_data as $shift) {
                if (!$shift['end_time']) {
                    $current_session = $shift;
                    break;
                }
            }
            echo $current_session ? 'ON SHIFT' : 'OFF SHIFT'; 
            ?>
          </div>
          <div style="color:#666; font-size:14px;">Current Status</div>
        </div>
        <div class="stat-card" style="background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e9ecef;">
          <div style="font-size:24px; font-weight:bold; color:#28a745;">
            <?php echo $current_session ? date('g:i A', strtotime($current_session['start_time'])) : '--:--'; ?>
          </div>
          <div style="color:#666; font-size:14px;">Clock In Time</div>
        </div>
        <div class="stat-card" style="background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e9ecef;">
          <div style="font-size:24px; font-weight-bold; color:#ffc107;">
            <?php echo number_format($hours_worked['total_hours'] ?? 0, 1); ?>h
          </div>
          <div style="color:#666; font-size:14px;">Hours This Week</div>
        </div>
      </div>
      
      <h3 style="margin-bottom:12px;">Today's Shift Activity</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Start Time</th>
              <th>End Time</th>
              <th>Hours Worked</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if(empty($shift_data)): ?>
              <tr>
                <td colspan="4" style="text-align:center; padding:20px; color:#888;">No shift activity today. Use Staff Dashboard to clock in.</td>
              </tr>
            <?php else: ?>
              <?php foreach($shift_data as $shift): ?>
                <tr>
                  <td><?php echo $shift['start_time'] ? date('g:i A', strtotime($shift['start_time'])) : '-'; ?></td>
                  <td><?php echo $shift['end_time'] ? date('g:i A', strtotime($shift['end_time'])) : 'Still working'; ?></td>
                  <td><?php echo $shift['hours_worked'] ? number_format($shift['hours_worked'], 2) . 'h' : '-'; ?></td>
                  <td>
                    <span class="badge" style="background:<?php echo $shift['end_time'] ? '#28a745' : '#ffc107'; ?>; color:white; padding:2px 8px; border-radius:12px; font-size:12px;">
                      <?php echo $shift['end_time'] ? 'Completed' : 'Active'; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      
    <?php elseif($view === 'upcoming'): ?>
      <h3 style="margin-bottom:12px;">Upcoming Shifts</h3>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Shift</th>
              <th>Time</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($upcoming_shifts as $shift): ?>
              <tr>
                <td><?php echo date('M d, Y', strtotime($shift['date'])); ?></td>
                <td><?php echo htmlspecialchars($shift['shift']); ?></td>
                <td><?php echo htmlspecialchars($shift['time']); ?></td>
                <td><span class="badge" style="background:#6c757d; color:white; padding:2px 8px; border-radius:12px; font-size:12px;">Scheduled</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      
    <?php elseif($view === 'clock'): ?>
      <div style="text-align:center; padding:40px 20px;">
        <div style="font-size:48px; margin-bottom:16px;">
          <i class="fas fa-clock" style="color:#0066cc;"></i>
        </div>
        <h3 style="margin-bottom:8px;">Clock In/Out</h3>
        <p style="color:#666; margin-bottom:20px;">For clocking in and out, please use the Staff Dashboard page.</p>
        <a href="staff_dashboard.php" class="btn btn-primary">
          <i class="fas fa-arrow-right"></i> Go to Staff Dashboard
        </a>
      </div>
      
    <?php elseif($view === 'hours'): ?>
      <h3 style="margin-bottom:12px;">Hours Worked Summary</h3>
      <div class="stats-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px;">
        <div class="stat-card" style="background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e9ecef;">
          <div style="font-size:32px; font-weight:bold; color:#0066cc;">
            <?php echo number_format($hours_worked['total_hours'] ?? 0, 1); ?>h
          </div>
          <div style="color:#666; font-size:14px;">This Week</div>
        </div>
        <div class="stat-card" style="background:#f8f9fa; padding:16px; border-radius:8px; border:1px solid #e9ecef;">
          <div style="font-size:32px; font-weight:bold; color:#28a745;">
            <?php echo number_format(($hours_worked['total_hours'] ?? 0) / 5, 1); ?>h
          </div>
          <div style="color:#666; font-size:14px;">Daily Average</div>
        </div>
      </div>
      
      <div style="margin-top:20px; padding:16px; background:#e3f2fd; border-radius:8px; border-left:4px solid #2196f3;">
        <h4 style="margin-top:0;">Hours Tracking</h4>
        <p>Your hours are automatically tracked when you clock in and out using the Staff Dashboard. Regular breaks and overtime are calculated based on your shift schedule.</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
