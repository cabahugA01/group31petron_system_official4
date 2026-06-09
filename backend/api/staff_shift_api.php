<?php
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json');

try {
    $pdo = get_db();
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_shift_periods':
            getShiftPeriods($pdo);
            break;
        case 'clock_in':
            clockIn($pdo);
            break;
        case 'clock_out':
            clockOut($pdo);
            break;
        case 'get_active_session':
            getActiveSession($pdo);
            break;
        case 'get_shift_history':
            getShiftHistory($pdo);
            break;
        case 'bind_transaction':
            bindTransactionToShift($pdo);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

function getShiftPeriods($pdo) {
    $stmt = $pdo->query("SELECT * FROM shift_periods WHERE is_active = TRUE ORDER BY start_time");
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'shifts' => $shifts]);
}

function clockIn($pdo) {
    $staff_id = $_POST['staff_id'] ?? 0;
    $station_id = $_POST['station_id'] ?? 0;
    
    if (!$staff_id || !$station_id) {
        echo json_encode(['error' => 'Staff ID and Station ID required']);
        return;
    }
    
    // Check if staff already has active session
    $stmt = $pdo->prepare("SELECT id FROM active_staff_sessions WHERE staff_id = ?");
    $stmt->execute([$staff_id]);
    if ($stmt->fetch()) {
        echo json_encode(['error' => 'Staff already has an active session']);
        return;
    }
    
    // Get current time and determine shift
    $current_time = date('H:i:s');
    $current_date = date('Y-m-d');
    
    // Determine shift based on current time
    $stmt = $pdo->prepare("
        SELECT id FROM shift_periods 
        WHERE is_active = TRUE 
        AND (? BETWEEN start_time AND end_time OR 
             (start_time > end_time AND (? >= start_time OR ? <= end_time)))
    ");
    $stmt->execute([$current_time, $current_time, $current_time]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        echo json_encode(['error' => 'No active shift period for current time']);
        return;
    }
    
    $shift_period_id = $shift['id'];
    
    // Create active session
    $stmt = $pdo->prepare("
        INSERT INTO active_staff_sessions 
        (staff_id, station_id, shift_period_id, clock_in_time, shift_date) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$staff_id, $station_id, $shift_period_id, date('Y-m-d H:i:s'), $current_date]);
    
    $session_id = $pdo->lastInsertId();
    
    // Create shift record
    $stmt = $pdo->prepare("
        INSERT INTO staff_shift_records 
        (staff_id, station_id, shift_period_id, clock_in_time, shift_date, status) 
        VALUES (?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$staff_id, $station_id, $shift_period_id, date('Y-m-d H:i:s'), $current_date]);
    
    $shift_record_id = $pdo->lastInsertId();
    
    // Get shift details
    $stmt = $pdo->prepare("
        SELECT sp.*, s.station_name, u.username, u.full_name 
        FROM shift_periods sp
        JOIN stations s ON s.id = ?
        JOIN users u ON u.user_id = ?
        WHERE sp.id = ?
    ");
    $stmt->execute([$station_id, $staff_id, $shift_period_id]);
    $shift_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log the clock-in
    logShiftAction($pdo, $staff_id, $station_id, 'CLOCK_IN', "Staff clocked in for {$shift_details['name']}");
    
    echo json_encode([
        'success' => true,
        'session_id' => $session_id,
        'shift_record_id' => $shift_record_id,
        'shift_details' => $shift_details,
        'clock_in_time' => date('Y-m-d H:i:s'),
        'message' => "Successfully clocked in for {$shift_details['name']}"
    ]);
}

function clockOut($pdo) {
    $staff_id = $_POST['staff_id'] ?? 0;
    
    if (!$staff_id) {
        echo json_encode(['error' => 'Staff ID required']);
        return;
    }
    
    // Get active session
    $stmt = $pdo->prepare("
        SELECT * FROM active_staff_sessions 
        WHERE staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['error' => 'No active session found']);
        return;
    }
    
    $clock_out_time = date('Y-m-d H:i:s');
    $clock_in_time = $session['clock_in_time'];
    
    // Calculate total hours
    $clock_in = new DateTime($clock_in_time);
    $clock_out = new DateTime($clock_out_time);
    $interval = $clock_in->diff($clock_out);
    $total_hours = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
    
    // Update shift record
    $stmt = $pdo->prepare("
        UPDATE staff_shift_records 
        SET clock_out_time = ?, total_hours = ?, status = 'completed', updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$clock_out_time, $total_hours, $session['shift_record_id']]);
    
    // Get shift period details for end time
    $stmt = $pdo->prepare("SELECT * FROM shift_periods WHERE id = ?");
    $stmt->execute([$session['shift_period_id']]);
    $shift_period = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Remove active session
    $stmt = $pdo->prepare("DELETE FROM active_staff_sessions WHERE staff_id = ?");
    $stmt->execute([$staff_id]);
    
    // Log the clock-out
    logShiftAction($pdo, $staff_id, $session['station_id'], 'CLOCK_OUT', "Staff clocked out from {$shift_period['name']}");
    
    echo json_encode([
        'success' => true,
        'clock_out_time' => $clock_out_time,
        'shift_end_time' => $shift_period['end_time'],
        'total_hours' => round($total_hours, 2),
        'shift_name' => $shift_period['name'],
        'message' => "Successfully clocked out from {$shift_period['name']}"
    ]);
}

function getActiveSession($pdo) {
    $staff_id = $_GET['staff_id'] ?? 0;
    
    if (!$staff_id) {
        echo json_encode(['error' => 'Staff ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT ass.*, sp.name as shift_name, sp.start_time, sp.end_time,
               u.username, u.full_name, s.station_name
        FROM active_staff_sessions ass
        JOIN shift_periods sp ON ass.shift_period_id = sp.id
        JOIN users u ON ass.staff_id = u.id
        JOIN stations s ON ass.station_id = s.id
        WHERE ass.staff_id = ?
    ");
    $stmt->execute([$staff_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        // Calculate current duration
        $clock_in = new DateTime($session['clock_in_time']);
        $now = new DateTime();
        $interval = $clock_in->diff($now);
        $current_duration = $interval->h + ($interval->i / 60) + ($interval->s / 3600);
        
        $session['current_duration'] = round($current_duration, 2);
        echo json_encode(['success' => true, 'session' => $session]);
    } else {
        echo json_encode(['success' => true, 'session' => null]);
    }
}

function getShiftHistory($pdo) {
    $staff_id = $_GET['staff_id'] ?? 0;
    $limit = $_GET['limit'] ?? 30;
    
    $stmt = $pdo->prepare("
        SELECT ssr.*, sp.name as shift_name, sp.start_time, sp.end_time,
               u.username, u.full_name, s.station_name
        FROM staff_shift_records ssr
        JOIN shift_periods sp ON ssr.shift_period_id = sp.id
        JOIN users u ON ssr.staff_id = u.id
        JOIN stations s ON ssr.station_id = s.id
        WHERE ssr.staff_id = ?
        ORDER BY ssr.clock_in_time DESC
        LIMIT ?
    ");
    $stmt->execute([$staff_id, $limit]);
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'shifts' => $shifts]);
}

function bindTransactionToShift($pdo) {
    $staff_id = $_POST['staff_id'] ?? 0;
    $transaction_id = $_POST['transaction_id'] ?? '';
    $transaction_type = $_POST['transaction_type'] ?? '';
    
    if (!$staff_id || !$transaction_id || !$transaction_type) {
        echo json_encode(['error' => 'Staff ID, Transaction ID, and Transaction Type required']);
        return;
    }
    
    // Get active session
    $stmt = $pdo->prepare("SELECT * FROM active_staff_sessions WHERE staff_id = ?");
    $stmt->execute([$staff_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        echo json_encode(['error' => 'No active session found for staff']);
        return;
    }
    
    // Bind transaction to shift
    $stmt = $pdo->prepare("
        INSERT INTO transaction_shift_binding 
        (transaction_id, transaction_type, staff_id, shift_record_id, shift_period_id, transaction_time)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $transaction_id,
        $transaction_type,
        $staff_id,
        $session['shift_record_id'],
        $session['shift_period_id']
    ]);
    
    echo json_encode([
        'success' => true,
        'shift_record_id' => $session['shift_record_id'],
        'shift_period_id' => $session['shift_period_id'],
        'message' => 'Transaction bound to active shift'
    ]);
}

function logShiftAction($pdo, $staff_id, $station_id, $action, $details) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (action, user_id, station_id, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$action, $staff_id, $station_id, $details]);
    } catch (Exception $e) {
        error_log("Failed to log shift action: " . $e->getMessage());
    }
}
?>
