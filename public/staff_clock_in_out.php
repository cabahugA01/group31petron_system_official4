<?php
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login(); // Enforces authenticated session + no-cache headers

// Role guard: must be a staff-level user
if (!in_array($_SESSION['user']['role'] ?? $_SESSION['role'] ?? '', ['Staff','staff','cashier','pump_attendant'])) {
    header('Location: login.php');
    exit;
}

$me = current_user();
$station_id = user_station_id();
$current_time = date('Y-m-d H:i:s');
$current_time_only = date('H:i:s');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Staff Clock In/Out - Petron POS</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .main-container { max-width: 800px; margin: 50px auto; }
        .clock-card { background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); padding: 30px; margin-bottom: 20px; }
        .time-display { font-size: 48px; font-weight: bold; color: #667eea; text-align: center; margin: 20px 0; }
        .shift-info { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; }
        .btn-clock { font-size: 18px; padding: 15px 30px; border-radius: 10px; }
        .btn-clock-in { background: #28a745; border: none; }
        .btn-clock-out { background: #dc3545; border: none; }
        .active-session { background: #d4edda; border-left: 4px solid #28a745; }
        .no-session { background: #f8d7da; border-left: 4px solid #dc3545; }
        .shift-badge { background: #007bff; color: white; padding: 5px 10px; border-radius: 15px; font-size: 12px; }
        .duration-badge { background: #6c757d; color: white; padding: 5px 10px; border-radius: 15px; font-size: 12px; }
        .history-item { border-left: 3px solid #dee2e6; padding-left: 15px; margin-bottom: 10px; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-completed { color: #17a2b8; font-weight: bold; }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="text-center mb-4">
            <h1 class="text-white"><i class="fas fa-clock"></i> Staff Time Clock</h1>
            <p class="text-white"><?php echo htmlspecialchars($me['full_name']); ?> - <?php echo htmlspecialchars($me['role']); ?></p>
        </div>

        <!-- Current Time Display -->
        <div class="clock-card">
            <div class="time-display" id="current-time"><?php echo date('h:i:s A'); ?></div>
            <div class="text-center">
                <small class="text-muted"><?php echo date('l, F j, Y'); ?></small>
            </div>
        </div>

        <!-- Active Session Status -->
        <div class="clock-card" id="session-status">
            <h3><i class="fas fa-user-clock"></i> Current Shift Status</h3>
            <div id="session-content">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Checking current session...</p>
                </div>
            </div>
        </div>

        <!-- Clock Actions -->
        <div class="clock-card" id="clock-actions">
            <h3><i class="fas fa-hand-pointer"></i> Clock Actions</h3>
            <div id="action-buttons">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading actions...</p>
                </div>
            </div>
        </div>

        <!-- Shift History -->
        <div class="clock-card">
            <h3><i class="fas fa-history"></i> Recent Shift History</h3>
            <div id="shift-history">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading shift history...</p>
                </div>
            </div>
        </div>

        <!-- Navigation -->
        <div class="text-center mt-4">
            <a href="staff_transactions_hub.php" class="btn-back">
                <i class="fas fa-cash-register"></i> Back to Transactions
            </a>
        </div>
    </div>

    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentSession = null;
        let refreshInterval;

        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit',
                hour12: true 
            });
            document.getElementById('current-time').textContent = timeString;
        }

        // Load active session
        async function loadActiveSession() {
            try {
                const response = await fetch(`backend/api/staff_shift_api.php?action=get_active_session&staff_id=<?php echo $me['id']; ?>`);
                const data = await response.json();
                
                if (data.success) {
                    currentSession = data.session;
                    updateSessionDisplay();
                    updateActionButtons();
                } else {
                    console.error('Failed to load session:', data.error);
                }
            } catch (error) {
                console.error('Error loading session:', error);
            }
        }

        // Update session display
        function updateSessionDisplay() {
            const sessionContent = document.getElementById('session-content');
            
            if (currentSession) {
                sessionContent.innerHTML = `
                    <div class="active-session p-3">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Status:</strong> <span class="status-active">ACTIVE</span></p>
                                <p><strong>Shift:</strong> <span class="shift-badge">${currentSession.shift_name}</span></p>
                                <p><strong>Clock In:</strong> ${formatDateTime(currentSession.clock_in_time)}</p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Station:</strong> ${currentSession.station_name}</p>
                                <p><strong>Duration:</strong> <span class="duration-badge">${currentSession.current_duration} hours</span></p>
                                <p><strong>Shift Period:</strong> ${currentSession.start_time} - ${currentSession.end_time}</p>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                sessionContent.innerHTML = `
                    <div class="no-session p-3">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle text-danger fa-2x mb-2"></i>
                            <p class="mb-0"><strong>No Active Session</strong></p>
                            <small class="text-muted">You are not currently clocked in</small>
                        </div>
                    </div>
                `;
            }
        }

        // Update action buttons
        function updateActionButtons() {
            const actionButtons = document.getElementById('action-buttons');
            
            if (currentSession) {
                actionButtons.innerHTML = `
                    <div class="text-center">
                        <p class="text-muted mb-3">You are currently clocked in. Ready to clock out?</p>
                        <button class="btn btn-clock btn-clock-out btn-lg" onclick="clockOut()">
                            <i class="fas fa-sign-out-alt"></i> Clock Out
                        </button>
                    </div>
                `;
            } else {
                actionButtons.innerHTML = `
                    <div class="text-center">
                        <p class="text-muted mb-3">Ready to start your shift?</p>
                        <button class="btn btn-clock btn-clock-in btn-lg" onclick="clockIn()">
                            <i class="fas fa-sign-in-alt"></i> Clock In
                        </button>
                    </div>
                `;
            }
        }

        // Clock in
        async function clockIn() {
            try {
                const formData = new FormData();
                formData.append('staff_id', <?php echo $me['id']; ?>);
                formData.append('station_id', <?php echo $station_id; ?>);
                
                const response = await fetch('backend/api/staff_shift_api.php?action=clock_in', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', data.message);
                    loadActiveSession();
                    loadShiftHistory();
                } else {
                    showAlert('danger', data.error);
                }
            } catch (error) {
                showAlert('danger', 'Error clocking in: ' + error.message);
            }
        }

        // Clock out
        async function clockOut() {
            try {
                const formData = new FormData();
                formData.append('staff_id', <?php echo $me['id']; ?>);
                
                const response = await fetch('backend/api/staff_shift_api.php?action=clock_out', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showAlert('success', data.message);
                    loadActiveSession();
                    loadShiftHistory();
                } else {
                    showAlert('danger', data.error);
                }
            } catch (error) {
                showAlert('danger', 'Error clocking out: ' + error.message);
            }
        }

        // Load shift history
        async function loadShiftHistory() {
            try {
                const response = await fetch(`backend/api/staff_shift_api.php?action=get_shift_history&staff_id=<?php echo $me['id']; ?>&limit=5`);
                const data = await response.json();
                
                if (data.success) {
                    updateShiftHistory(data.shifts);
                } else {
                    console.error('Failed to load history:', data.error);
                }
            } catch (error) {
                console.error('Error loading history:', error);
            }
        }

        // Update shift history display
        function updateShiftHistory(shifts) {
            const historyContainer = document.getElementById('shift-history');
            
            if (shifts.length === 0) {
                historyContainer.innerHTML = '<p class="text-muted text-center">No shift history available</p>';
                return;
            }
            
            let html = '';
            shifts.forEach(shift => {
                const statusClass = shift.status === 'completed' ? 'status-completed' : 'status-active';
                const duration = shift.total_hours ? `${shift.total_hours} hours` : 'In progress';
                
                html += `
                    <div class="history-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <p class="mb-1"><strong>${shift.shift_name}</strong> 
                                    <span class="shift-badge">${shift.status}</span>
                                </p>
                                <small class="text-muted">
                                    ${formatDateTime(shift.clock_in_time)} - 
                                    ${shift.clock_out_time ? formatDateTime(shift.clock_out_time) : 'Active'}
                                </small>
                            </div>
                            <div class="text-end">
                                <small class="duration-badge">${duration}</small>
                                <br>
                                <small class="text-muted">${shift.station_name}</small>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            historyContainer.innerHTML = html;
        }

        // Show alert
        function showAlert(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Insert at the top of the container
            const container = document.querySelector('.main-container');
            container.insertAdjacentHTML('afterbegin', alertHtml);
            
            // Auto dismiss after 5 seconds
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }

        // Format date time
        function formatDateTime(dateTime) {
            const date = new Date(dateTime);
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hour12: true
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateCurrentTime();
            setInterval(updateCurrentTime, 1000);
            
            loadActiveSession();
            loadShiftHistory();
            
            // Refresh every 30 seconds
            refreshInterval = setInterval(() => {
                loadActiveSession();
            }, 30000);
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>
