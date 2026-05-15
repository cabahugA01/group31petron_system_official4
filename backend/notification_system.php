<?php
/**
 * Job Order Notification System
 * Handles real-time notifications for job order workflow
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

class NotificationSystem {
    
    private $pdo;
    private $station_id;
    private $user;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }
    
    /**
     * Create notification table if not exists
     */
    private function ensureNotificationTable(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS job_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            job_order_id INT NOT NULL,
            user_id INT NULL, -- Specific user (null for broadcast to role)
            user_role VARCHAR(20) NULL, -- Role to notify (null for specific user)
            notification_type ENUM('job_created', 'job_approved', 'job_started', 'parts_added', 'job_completed', 'job_reviewed', 'job_ready_billing', 'job_paid', 'vehicle_released') NOT NULL,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            
            INDEX idx_notifications_station (station_id),
            INDEX idx_notifications_job (job_order_id),
            INDEX idx_notifications_user (user_id),
            INDEX idx_notifications_role (user_role),
            INDEX idx_notifications_unread (is_read),
            INDEX idx_notifications_type (notification_type),
            
            FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    
    /**
     * Send notification based on workflow event
     */
    public function sendNotification($event_type, $job_order_id, $custom_data = []): array {
        try {
            $this->ensureNotificationTable();
            
            // Get job order details
            $stmt = $this->pdo->prepare("
                SELECT jo.*, 
                       c.name as customer_name,
                       t.full_name as technician_name,
                       m.full_name as mechanic_name,
                       sc.name as service_name,
                       u.name as created_by_name,
                       r.name as reviewed_by_name
                FROM job_orders jo
                LEFT JOIN customers c ON c.id = jo.customer_id
                LEFT JOIN technicians t ON t.id = jo.assigned_technician_id
                LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
                LEFT JOIN users u ON u.id = jo.assigned_by
                LEFT JOIN users r ON r.id = jo.reviewed_by
                WHERE jo.id = ? AND jo.station_id = ?
            ");
            $stmt->execute([$job_order_id, $this->station_id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job) {
                throw new Exception('Job order not found');
            }
            
            $notifications = [];
            
            switch ($event_type) {
                case 'job_created':
                    $notifications = $this->handleJobCreated($job);
                    break;
                    
                case 'job_approved':
                    $notifications = $this->handleJobApproved($job);
                    break;
                    
                case 'job_started':
                    $notifications = $this->handleJobStarted($job);
                    break;
                    
                case 'parts_added':
                    $notifications = $this->handlePartsAdded($job, $custom_data);
                    break;
                    
                case 'job_completed':
                    $notifications = $this->handleJobCompleted($job);
                    break;
                    
                case 'job_reviewed':
                    $notifications = $this->handleJobReviewed($job);
                    break;
                    
                case 'job_ready_billing':
                    $notifications = $this->handleJobReadyBilling($job);
                    break;
                    
                case 'job_paid':
                    $notifications = $this->handleJobPaid($job);
                    break;
                    
                case 'vehicle_released':
                    $notifications = $this->handleVehicleReleased($job);
                    break;
                    
                default:
                    throw new Exception('Unknown notification event type: ' . $event_type);
            }
            
            // Insert all notifications
            $inserted_count = 0;
            foreach ($notifications as $notification) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO job_notifications 
                    (station_id, job_order_id, user_id, user_role, notification_type, title, message)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $stmt->execute([
                    $this->station_id,
                    $job_order_id,
                    $notification['user_id'],
                    $notification['user_role'],
                    $notification['notification_type'],
                    $notification['title'],
                    $notification['message']
                ]);
                
                if ($result) {
                    $inserted_count++;
                }
            }
            
            return [
                'success' => true,
                'message' => "Sent {$inserted_count} notifications for {$event_type}",
                'count' => $inserted_count
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Handle job created notifications
     */
    private function handleJobCreated($job): array {
        $notifications = [];
        
        // Notify manager for approval
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'manager',
            'notification_type' => 'job_created',
            'title' => 'New Job Order Pending Approval',
            'message' => "Job {$job['job_order_number']} created by {$job['created_by_name']} for {$job['customer_name']}. Service: {$job['service_name']}."
        ];
        
        // Notify assigned technician
        if ($job['assigned_technician_id']) {
            $notifications[] = [
                'user_id' => $job['assigned_technician_id'],
                'user_role' => null,
                'notification_type' => 'job_created',
                'title' => 'New Job Assigned',
                'message' => "You have been assigned to job {$job['job_order_number']} for {$job['customer_name']}. Service: {$job['service_name']}."
            ];
        }
        
        return $notifications;
    }
    
    /**
     * Handle job approved notifications
     */
    private function handleJobApproved($job): array {
        $notifications = [];
        
        // Notify technician that job is approved
        if ($job['assigned_technician_id']) {
            $notifications[] = [
                'user_id' => $job['assigned_technician_id'],
                'user_role' => null,
                'notification_type' => 'job_approved',
                'title' => 'Job Order Approved',
                'message' => "Job {$job['job_order_number']} has been approved. You can now start working on this job."
            ];
        }
        
        // Notify staff who created the job
        if ($job['assigned_by']) {
            $notifications[] = [
                'user_id' => $job['assigned_by'],
                'user_role' => null,
                'notification_type' => 'job_approved',
                'title' => 'Job Order Approved',
                'message' => "Your job order {$job['job_order_number']} has been approved and is ready for work."
            ];
        }
        
        return $notifications;
    }
    
    /**
     * Handle job started notifications
     */
    private function handleJobStarted($job): array {
        $notifications = [];
        
        // Notify manager that job has started
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'manager',
            'notification_type' => 'job_started',
            'title' => 'Job Started',
            'message' => "Job {$job['job_order_number']} has been started by {$job['technician_name']}."
        ];
        
        return $notifications;
    }
    
    /**
     * Handle parts added notifications
     */
    private function handlePartsAdded($job, $parts_data): array {
        $notifications = [];
        
        $parts_info = '';
        if (isset($parts_data['parts']) && is_array($parts_data['parts'])) {
            $parts_names = array_column($parts_data['parts'], 'part_name');
            $parts_info = ' Parts added: ' . implode(', ', array_slice($parts_names, 0, 3));
            if (count($parts_names) > 3) {
                $parts_info .= ' and ' . (count($parts_names) - 3) . ' more';
            }
        }
        
        // Notify manager about parts usage
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'manager',
            'notification_type' => 'parts_added',
            'title' => 'Parts Added to Job',
            'message' => "Parts have been added to job {$job['job_order_number']} by {$job['technician_name']}.{$parts_info}"
        ];
        
        return $notifications;
    }
    
    /**
     * Handle job completed notifications
     */
    private function handleJobCompleted($job): array {
        $notifications = [];
        
        // Notify manager that job is completed and needs review
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'manager',
            'notification_type' => 'job_completed',
            'title' => 'Job Completed - Ready for Review',
            'message' => "Job {$job['job_order_number']} has been completed by {$job['technician_name']}. Please review and approve final billing."
        ];
        
        return $notifications;
    }
    
    /**
     * Handle job reviewed notifications
     */
    private function handleJobReviewed($job): array {
        $notifications = [];
        
        // Notify staff that job is ready for billing
        if ($job['assigned_by']) {
            $notifications[] = [
                'user_id' => $job['assigned_by'],
                'user_role' => null,
                'notification_type' => 'job_reviewed',
                'title' => 'Job Ready for Billing',
                'message' => "Job {$job['job_order_number']} has been reviewed and is ready for billing. Please process payment."
            ];
        }
        
        return $notifications;
    }
    
    /**
     * Handle job ready for billing notifications
     */
    private function handleJobReadyBilling($job): array {
        $notifications = [];
        
        // Notify all staff about job ready for billing
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'staff',
            'notification_type' => 'job_ready_billing',
            'title' => 'Job Ready for Billing',
            'message' => "Job {$job['job_order_number']} is ready for billing. Please process payment and release vehicle."
        ];
        
        return $notifications;
    }
    
    /**
     * Handle job paid notifications
     */
    private function handleJobPaid($job): array {
        $notifications = [];
        
        // Notify manager that payment has been processed
        $notifications[] = [
            'user_id' => null,
            'user_role' => 'manager',
            'notification_type' => 'job_paid',
            'title' => 'Payment Processed',
            'message' => "Payment has been processed for job {$job['job_order_number']}. Vehicle can now be released."
        ];
        
        return $notifications;
    }
    
    /**
     * Handle vehicle released notifications
     */
    private function handleVehicleReleased($job): array {
        $notifications = [];
        
        // Notify technician that job is fully complete
        if ($job['assigned_technician_id']) {
            $notifications[] = [
                'user_id' => $job['assigned_technician_id'],
                'user_role' => null,
                'notification_type' => 'vehicle_released',
                'title' => 'Vehicle Released',
                'message' => "Vehicle for job {$job['job_order_number']} has been released to customer. Job fully complete."
            ];
        }
        
        return $notifications;
    }
    
    /**
     * Get user notifications
     */
    public function getUserNotifications($limit = 50): array {
        try {
            $this->ensureNotificationTable();
            
            $user_id = $this->user['id'];
            $user_role = role_key($this->user['role'] ?? '');
            
            $stmt = $this->pdo->prepare("
                SELECT n.*, jo.job_order_number
                FROM job_notifications n
                LEFT JOIN job_orders jo ON jo.id = n.job_order_id
                WHERE n.station_id = ?
                  AND (n.user_id = ? OR n.user_role = ?)
                  AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY n.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$this->station_id, $user_id, $user_role, $limit]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'data' => $notifications];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notification_id): array {
        try {
            $this->ensureNotificationTable();
            
            $user_id = $this->user['id'];
            $user_role = role_key($this->user['role'] ?? '');
            
            $stmt = $this->pdo->prepare("
                UPDATE job_notifications 
                SET is_read = 1, read_at = NOW()
                WHERE id = ? 
                  AND station_id = ?
                  AND (user_id = ? OR user_role = ?)
            ");
            $stmt->execute([$notification_id, $this->station_id, $user_id, $user_role]);
            
            $updated = $stmt->rowCount() > 0;
            
            return [
                'success' => $updated,
                'message' => $updated ? 'Notification marked as read' : 'Notification not found or already read'
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Mark all notifications as read for user
     */
    public function markAllAsRead(): array {
        try {
            $this->ensureNotificationTable();
            
            $user_id = $this->user['id'];
            $user_role = role_key($this->user['role'] ?? '');
            
            $stmt = $this->pdo->prepare("
                UPDATE job_notifications 
                SET is_read = 1, read_at = NOW()
                WHERE station_id = ?
                  AND is_read = 0
                  AND (user_id = ? OR user_role = ?)
            ");
            $stmt->execute([$this->station_id, $user_id, $user_role]);
            
            $updated = $stmt->rowCount();
            
            return [
                'success' => true,
                'message' => "Marked {$updated} notifications as read",
                'count' => $updated
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount(): int {
        try {
            $this->ensureNotificationTable();
            
            $user_id = $this->user['id'];
            $user_role = role_key($this->user['role'] ?? '');
            
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as unread_count
                FROM job_notifications
                WHERE station_id = ?
                  AND is_read = 0
                  AND (user_id = ? OR user_role = ?)
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute([$this->station_id, $user_id, $user_role]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)($result['unread_count'] ?? 0);
            
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Handle API requests if called directly
if (basename($_SERVER['PHP_SELF']) === 'notification_system.php') {
    require_login();
    
    $user = current_user();
    $station_id = user_station_id();
    
    $notificationSystem = new NotificationSystem($pdo, $user, $station_id);
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        switch ($action) {
            case 'get_notifications':
                $limit = (int)($_GET['limit'] ?? 50);
                $result = $notificationSystem->getUserNotifications($limit);
                break;
                
            case 'mark_read':
                $notification_id = $_POST['notification_id'] ?? 0;
                $result = $notificationSystem->markAsRead($notification_id);
                break;
                
            case 'mark_all_read':
                $result = $notificationSystem->markAllAsRead();
                break;
                
            case 'get_unread_count':
                $count = $notificationSystem->getUnreadCount();
                $result = ['success' => true, 'count' => $count];
                break;
                
            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
        
        json_response($result);
        
    } catch (Exception $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
