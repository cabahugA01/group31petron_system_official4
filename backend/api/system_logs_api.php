<?php
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Check if user is SuperAdmin or Developer
session_start();
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$u = $_SESSION['user'];
$role = $u['role'] ?? 'staff';
$roleKey = function_exists('role_key') ? role_key($role) : strtolower(trim((string)$role));

if (!in_array($roleKey, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. SuperAdmin/Developer only.']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_audit_details':
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT sal.*, u.username, u.full_name, s.station_name
                FROM system_activity_logs sal
                LEFT JOIN users u ON sal.user_id = u.id
                LEFT JOIN stations s ON sal.station_id = s.id
                WHERE sal.id = ?
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                $html = '<div class="row">';
                $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                $html .= '<div class="col-md-6"><strong>User:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['username'] ?? 'System') . '</div>';
                $html .= '<div class="col-md-6"><strong>Activity Type:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getActivityTypeColor($data['activity_type']) . '">' . ucfirst($data['activity_type']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Module:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['module'] ?? 'N/A') . '</div>';
                $html .= '<div class="col-md-6"><strong>Action:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['action']) . '</div>';
                $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getStatusColor($data['status']) . '">' . ucfirst($data['status']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>IP Address:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['ip_address'] ?? 'N/A') . '</div>';
                $html .= '<div class="col-md-6"><strong>Request URI:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['request_uri'] ?? 'N/A') . '</div>';
                $html .= '<div class="col-md-12"><strong>Description:</strong></div>';
                $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['description'] ?? '')) . '</div>';
                
                if ($data['old_values']) {
                    $html .= '<div class="col-md-12 mt-3"><strong>Old Values:</strong></div>';
                    $html .= '<div class="col-md-12"><pre class="bg-light p-2 rounded">' . htmlspecialchars($data['old_values']) . '</pre></div>';
                }
                
                if ($data['new_values']) {
                    $html .= '<div class="col-md-12 mt-3"><strong>New Values:</strong></div>';
                    $html .= '<div class="col-md-12"><pre class="bg-light p-2 rounded">' . htmlspecialchars($data['new_values']) . '</pre></div>';
                }
                
                $html .= '</div>';
                echo $html;
            } else {
                echo '<p>Record not found</p>';
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'get_error_details':
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT sel.*, u.username, u.full_name, s.station_name
                FROM system_error_logs sel
                LEFT JOIN users u ON sel.user_id = u.id
                LEFT JOIN stations s ON sel.station_id = s.id
                WHERE sel.id = ?
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                $html = '<div class="row">';
                $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                $html .= '<div class="col-md-6"><strong>Error Type:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-secondary">' . htmlspecialchars($data['error_type']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Severity:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getSeverityColor($data['severity']) . '">' . ucfirst($data['severity']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Error Code:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['error_code'] ?? 'N/A') . '</div>';
                $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getStatusColor($data['status']) . '">' . ucfirst($data['status']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>User:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['username'] ?? 'System') . '</div>';
                $html .= '<div class="col-md-6"><strong>IP Address:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['ip_address'] ?? 'N/A') . '</div>';
                
                if ($data['file_path']) {
                    $html .= '<div class="col-md-6"><strong>File:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['file_path']);
                    if ($data['line_number']) {
                        $html .= ':' . $data['line_number'];
                    }
                    $html .= '</div>';
                }
                
                if ($data['function_name']) {
                    $html .= '<div class="col-md-6"><strong>Function:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['function_name']) . '</div>';
                }
                
                if ($data['class_name']) {
                    $html .= '<div class="col-md-6"><strong>Class:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['class_name']) . '</div>';
                }
                
                $html .= '<div class="col-md-12 mt-3"><strong>Error Message:</strong></div>';
                $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['error_message'])) . '</div>';
                
                if ($data['stack_trace']) {
                    $html .= '<div class="col-md-12 mt-3"><strong>Stack Trace:</strong></div>';
                    $html .= '<div class="col-md-12"><pre class="bg-light p-2 rounded" style="max-height: 300px; overflow-y: auto;">' . htmlspecialchars($data['stack_trace']) . '</pre></div>';
                }
                
                if ($data['resolution_notes']) {
                    $html .= '<div class="col-md-12 mt-3"><strong>Resolution Notes:</strong></div>';
                    $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['resolution_notes'])) . '</div>';
                }
                
                $html .= '</div>';
                echo $html;
            } else {
                echo '<p>Record not found</p>';
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'get_alert_details':
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT sa.*, 
                       ack.username as acknowledged_by_name,
                       res.username as resolved_by_name,
                       DATEDIFF(NOW(), sa.created_at) as days_open
                FROM system_alerts sa
                LEFT JOIN users ack ON sa.acknowledged_by = ack.id
                LEFT JOIN users res ON sa.resolved_by = res.id
                WHERE sa.id = ?
            ");
            $stmt->execute([$id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($data) {
                $html = '<div class="row">';
                $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                $html .= '<div class="col-md-6"><strong>Alert Type:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-info">' . htmlspecialchars($data['alert_type']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Severity:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getSeverityColor($data['severity']) . '">' . ucfirst($data['severity']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                $html .= '<div class="col-md-6"><span class="badge bg-' . getAlertStatusColor($data['status']) . '">' . ucfirst($data['status']) . '</span></div>';
                $html .= '<div class="col-md-6"><strong>Module:</strong></div>';
                $html .= '<div class="col-md-6">' . htmlspecialchars($data['module'] ?? 'N/A') . '</div>';
                $html .= '<div class="col-md-6"><strong>Days Open:</strong></div>';
                $html .= '<div class="col-md-6">' . $data['days_open'] . ' days</div>';
                
                $html .= '<div class="col-md-12 mt-3"><strong>Title:</strong></div>';
                $html .= '<div class="col-md-12">' . htmlspecialchars($data['title']) . '</div>';
                
                $html .= '<div class="col-md-12 mt-3"><strong>Message:</strong></div>';
                $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['message'])) . '</div>';
                
                if ($data['trigger_condition']) {
                    $html .= '<div class="col-md-6 mt-3"><strong>Trigger Condition:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['trigger_condition']) . '</div>';
                }
                
                if ($data['threshold_value'] !== null) {
                    $html .= '<div class="col-md-6"><strong>Threshold Value:</strong></div>';
                    $html .= '<div class="col-md-6">' . $data['threshold_value'] . '</div>';
                }
                
                if ($data['current_value'] !== null) {
                    $html .= '<div class="col-md-6"><strong>Current Value:</strong></div>';
                    $html .= '<div class="col-md-6">' . $data['current_value'] . '</div>';
                }
                
                if ($data['acknowledged_by_name']) {
                    $html .= '<div class="col-md-6 mt-3"><strong>Acknowledged By:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['acknowledged_by_name']) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Acknowledged At:</strong></div>';
                    $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['acknowledged_at'])) . '</div>';
                }
                
                if ($data['resolved_by_name']) {
                    $html .= '<div class="col-md-6 mt-3"><strong>Resolved By:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['resolved_by_name']) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Resolved At:</strong></div>';
                    $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['resolved_at'])) . '</div>';
                }
                
                if ($data['resolution_notes']) {
                    $html .= '<div class="col-md-12 mt-3"><strong>Resolution Notes:</strong></div>';
                    $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['resolution_notes'])) . '</div>';
                }
                
                $html .= '</div>';
                echo $html;
            } else {
                echo '<p>Record not found</p>';
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'get_details':
        // Legacy support for old action name
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        
        if (empty($type) || $id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            if ($type === 'audit') {
                $stmt = $pdo->prepare("
                    SELECT sal.*, u.username, u.full_name, s.station_name
                    FROM system_activity_logs sal
                    LEFT JOIN users u ON sal.user_id = u.id
                    LEFT JOIN stations s ON sal.station_id = s.id
                    WHERE sal.id = ?
                ");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $html = '<div class="row">';
                    $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                    $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                    $html .= '<div class="col-md-6"><strong>User:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['username'] ?? 'System') . '</div>';
                    $html .= '<div class="col-md-6"><strong>Module:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['module'] ?? 'N/A') . '</div>';
                    $html .= '<div class="col-md-6"><strong>Action:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['action']) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                    $html .= '<div class="col-md-6"><span class="badge badge-' . ($data['status'] === 'success' ? 'success' : 'danger') . '">' . ucfirst($data['status']) . '</span></div>';
                    $html .= '<div class="col-md-6"><strong>IP Address:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['ip_address'] ?? 'N/A') . '</div>';
                    $html .= '<div class="col-md-6"><strong>Request URI:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['request_uri'] ?? 'N/A') . '</div>';
                    $html .= '<div class="col-md-12"><strong>Description:</strong></div>';
                    $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['description'] ?? '')) . '</div>';
                    
                    if ($data['old_values']) {
                        $html .= '<div class="col-md-12"><strong>Old Values:</strong></div>';
                        $html .= '<div class="col-md-12"><pre>' . htmlspecialchars($data['old_values']) . '</pre></div>';
                    }
                    
                    if ($data['new_values']) {
                        $html .= '<div class="col-md-12"><strong>New Values:</strong></div>';
                        $html .= '<div class="col-md-12"><pre>' . htmlspecialchars($data['new_values']) . '</pre></div>';
                    }
                    
                    $html .= '</div>';
                    echo $html;
                } else {
                    echo '<p>Record not found</p>';
                }
                
            } elseif ($type === 'error') {
                $stmt = $pdo->prepare("
                    SELECT sel.*, u.username, u.full_name, s.station_name
                    FROM system_error_logs sel
                    LEFT JOIN users u ON sel.user_id = u.id
                    LEFT JOIN stations s ON sel.station_id = s.id
                    WHERE sel.id = ?
                ");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $html = '<div class="row">';
                    $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                    $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Error Type:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['error_type']) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Severity:</strong></div>';
                    $html .= '<div class="col-md-6"><span class="badge badge-' . getSeverityColor($data['severity']) . '">' . ucfirst($data['severity']) . '</span></div>';
                    $html .= '<div class="col-md-6"><strong>Error Code:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['error_code'] ?? 'N/A') . '</div>';
                    $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                    $html .= '<div class="col-md-6"><span class="badge badge-' . getStatusColor($data['status']) . '">' . ucfirst($data['status']) . '</span></div>';
                    $html .= '<div class="col-md-6"><strong>User:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['username'] ?? 'System') . '</div>';
                    $html .= '<div class="col-md-6"><strong>IP Address:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['ip_address'] ?? 'N/A') . '</div>';
                    
                    if ($data['file_path']) {
                        $html .= '<div class="col-md-6"><strong>File:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['file_path']);
                        if ($data['line_number']) {
                            $html .= ':' . $data['line_number'];
                        }
                        $html .= '</div>';
                    }
                    
                    if ($data['function_name']) {
                        $html .= '<div class="col-md-6"><strong>Function:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['function_name']) . '</div>';
                    }
                    
                    if ($data['class_name']) {
                        $html .= '<div class="col-md-6"><strong>Class:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['class_name']) . '</div>';
                    }
                    
                    $html .= '<div class="col-md-12"><strong>Error Message:</strong></div>';
                    $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['error_message'])) . '</div>';
                    
                    if ($data['stack_trace']) {
                        $html .= '<div class="col-md-12"><strong>Stack Trace:</strong></div>';
                        $html .= '<div class="col-md-12"><pre>' . htmlspecialchars($data['stack_trace']) . '</pre></div>';
                    }
                    
                    if ($data['resolution_notes']) {
                        $html .= '<div class="col-md-12"><strong>Resolution Notes:</strong></div>';
                        $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['resolution_notes'])) . '</div>';
                    }
                    
                    $html .= '</div>';
                    echo $html;
                } else {
                    echo '<p>Record not found</p>';
                }
                
            } elseif ($type === 'alert') {
                $stmt = $pdo->prepare("
                    SELECT sa.*, 
                           ack.username as acknowledged_by_name,
                           res.username as resolved_by_name
                    FROM system_alerts sa
                    LEFT JOIN users ack ON sa.acknowledged_by = ack.id
                    LEFT JOIN users res ON sa.resolved_by = res.id
                    WHERE sa.id = ?
                ");
                $stmt->execute([$id]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($data) {
                    $html = '<div class="row">';
                    $html .= '<div class="col-md-6"><strong>Date/Time:</strong></div>';
                    $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['created_at'])) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Alert Type:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['alert_type']) . '</div>';
                    $html .= '<div class="col-md-6"><strong>Severity:</strong></div>';
                    $html .= '<div class="col-md-6"><span class="badge badge-' . getSeverityColor($data['severity']) . '">' . ucfirst($data['severity']) . '</span></div>';
                    $html .= '<div class="col-md-6"><strong>Status:</strong></div>';
                    $html .= '<div class="col-md-6"><span class="badge badge-' . getAlertStatusColor($data['status']) . '">' . ucfirst($data['status']) . '</span></div>';
                    $html .= '<div class="col-md-6"><strong>Module:</strong></div>';
                    $html .= '<div class="col-md-6">' . htmlspecialchars($data['module'] ?? 'N/A') . '</div>';
                    $html .= '<div class="col-md-6"><strong>Days Open:</strong></div>';
                    $html .= '<div class="col-md-6">' . $data['days_open'] . ' days</div>';
                    
                    $html .= '<div class="col-md-12"><strong>Title:</strong></div>';
                    $html .= '<div class="col-md-12">' . htmlspecialchars($data['title']) . '</div>';
                    
                    $html .= '<div class="col-md-12"><strong>Message:</strong></div>';
                    $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['message'])) . '</div>';
                    
                    if ($data['trigger_condition']) {
                        $html .= '<div class="col-md-6"><strong>Trigger Condition:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['trigger_condition']) . '</div>';
                    }
                    
                    if ($data['threshold_value'] !== null) {
                        $html .= '<div class="col-md-6"><strong>Threshold Value:</strong></div>';
                        $html .= '<div class="col-md-6">' . $data['threshold_value'] . '</div>';
                    }
                    
                    if ($data['current_value'] !== null) {
                        $html .= '<div class="col-md-6"><strong>Current Value:</strong></div>';
                        $html .= '<div class="col-md-6">' . $data['current_value'] . '</div>';
                    }
                    
                    if ($data['acknowledged_by_name']) {
                        $html .= '<div class="col-md-6"><strong>Acknowledged By:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['acknowledged_by_name']) . '</div>';
                        $html .= '<div class="col-md-6"><strong>Acknowledged At:</strong></div>';
                        $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['acknowledged_at'])) . '</div>';
                    }
                    
                    if ($data['resolved_by_name']) {
                        $html .= '<div class="col-md-6"><strong>Resolved By:</strong></div>';
                        $html .= '<div class="col-md-6">' . htmlspecialchars($data['resolved_by_name']) . '</div>';
                        $html .= '<div class="col-md-6"><strong>Resolved At:</strong></div>';
                        $html .= '<div class="col-md-6">' . date('M j, Y H:i:s', strtotime($data['resolved_at'])) . '</div>';
                    }
                    
                    if ($data['resolution_notes']) {
                        $html .= '<div class="col-md-12"><strong>Resolution Notes:</strong></div>';
                        $html .= '<div class="col-md-12">' . nl2br(htmlspecialchars($data['resolution_notes'])) . '</div>';
                    }
                    
                    $html .= '</div>';
                    echo $html;
                } else {
                    echo '<p>Record not found</p>';
                }
            }
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'update_error_status':
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        if ($id <= 0 || empty($status)) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE system_error_logs 
                SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $id]);
            
            // Log the status update
            $stmt = $pdo->prepare("
                INSERT INTO system_activity_logs (
                    activity_type, module, action, description, user_id, user_role, 
                    ip_address, user_agent, status
                ) VALUES (
                    'update', 'system_logs', 'error_status_update', 
                    CONCAT('Updated error status to: ', ?, ' for error ID: ', ?),
                    ?, ?, ?, ?, 'success'
                )
            ");
            $stmt->execute([$status, $id, $u['id'], $role, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'acknowledge_alert':
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE system_alerts 
                SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW()
                WHERE id = ? AND status = 'active'
            ");
            $stmt->execute([$u['id'], $id]);
            
            // Log the acknowledgment
            $stmt = $pdo->prepare("
                INSERT INTO system_activity_logs (
                    activity_type, module, action, description, user_id, user_role, 
                    ip_address, user_agent, status
                ) VALUES (
                    'update', 'system_logs', 'alert_acknowledged', 
                    CONCAT('Acknowledged alert ID: ', ?),
                    ?, ?, ?, ?, 'success'
                )
            ");
            $stmt->execute([$id, $u['id'], $role, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    case 'resolve_alert':
        $id = (int)($_POST['id'] ?? 0);
        $resolution_notes = $_POST['resolution_notes'] ?? '';
        
        if ($id <= 0) {
            echo json_encode(['error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                UPDATE system_alerts 
                SET status = 'resolved', resolved_by = ?, resolved_at = NOW(), 
                    resolution_notes = ?
                WHERE id = ? AND status IN ('active', 'acknowledged')
            ");
            $stmt->execute([$u['id'], $resolution_notes, $id]);
            
            // Log the resolution
            $stmt = $pdo->prepare("
                INSERT INTO system_activity_logs (
                    activity_type, module, action, description, user_id, user_role, 
                    ip_address, user_agent, status
                ) VALUES (
                    'update', 'system_logs', 'alert_resolved', 
                    CONCAT('Resolved alert ID: ', ?, ' with notes: ', ?),
                    ?, ?, ?, ?, 'success'
                )
            ");
            $stmt->execute([$id, $resolution_notes, $u['id'], $role, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']]);
            
            echo json_encode(['success' => true]);
            
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}

function getSeverityColor($severity) {
    $colors = [
        'critical' => 'danger',
        'high' => 'warning',
        'medium' => 'info',
        'low' => 'secondary'
    ];
    return $colors[$severity] ?? 'secondary';
}

function getStatusColor($status) {
    $colors = [
        'open' => 'danger',
        'investigating' => 'warning',
        'resolved' => 'success',
        'closed' => 'secondary'
    ];
    return $colors[$status] ?? 'secondary';
}

function getAlertStatusColor($status) {
    $colors = [
        'active' => 'danger',
        'acknowledged' => 'warning',
        'resolved' => 'success',
        'dismissed' => 'secondary'
    ];
    return $colors[$status] ?? 'secondary';
}

function getActivityTypeColor($activity_type) {
    $colors = [
        'login' => 'success',
        'logout' => 'secondary',
        'create' => 'primary',
        'update' => 'info',
        'delete' => 'danger',
        'view' => 'light',
        'export' => 'success',
        'import' => 'warning',
        'approve' => 'success',
        'reject' => 'danger',
        'system' => 'dark',
        'error' => 'danger'
    ];
    return $colors[$activity_type] ?? 'secondary';
}
?>
