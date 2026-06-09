<?php
require_once '../../config/database_config.php';
require_once '../../includes/session.php';

header('Content-Type: application/json');

class StaffColorConfigAPI {
    private $pdo;
    private $station_id;
    private $user_id;
    private $user_role;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->station_id = $_SESSION['station_id'] ?? null;
        $this->user_id = $_SESSION['user_id'] ?? null;
        $this->user_role = $_SESSION['role'] ?? null;
        
        if (!$this->station_id || !$this->user_id) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'get_staff_colors':
                return $this->getStaffColors();
            case 'get_manager_colors':
                return $this->getManagerColors();
            case 'update_staff_color':
                return $this->updateStaffColor();
            case 'update_manager_color':
                return $this->updateManagerColor();
            case 'initialize_colors':
                return $this->initializeColors();
            default:
                http_response_code(400);
                return ['error' => 'Invalid action'];
        }
    }
    
    private function getStaffColors() {
        try {
            $stmt = $this->pdo->prepare("
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
            $stmt->execute([':station_id' => $this->station_id]);
            $staff_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'staff_colors' => $staff_colors];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function getManagerColors() {
        try {
            $stmt = $this->pdo->prepare("
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
            $stmt->execute([':station_id' => $this->station_id]);
            $manager_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return ['success' => true, 'manager_colors' => $manager_colors];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function updateStaffColor() {
        $data = json_decode(file_get_contents('php://input'), true);
        $staff_id = $data['staff_id'] ?? 0;
        $color_code = $data['color_code'] ?? '';
        $color_name = $data['color_name'] ?? '';
        
        if (!$staff_id || !$color_code || !$color_name) {
            return ['success' => false, 'error' => 'Staff ID, color code, and color name required'];
        }
        
        try {
            // Validate color code format
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_code)) {
                return ['success' => false, 'error' => 'Invalid color code format. Use #RRGGBB format.'];
            }
            
            // Check if staff exists and belongs to station
            $stmt = $this->pdo->prepare("
                SELECT user_id FROM users 
                WHERE user_id = :staff_id AND station_id = :station_id 
                AND role IN ('staff', 'cashier', 'pump_attendant')
                AND account_status = 'Active'
            ");
            $stmt->execute([
                ':staff_id' => $staff_id,
                ':station_id' => $this->station_id
            ]);
            
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return ['success' => false, 'error' => 'Staff not found or not authorized'];
            }
            
            // Update or insert color configuration
            $stmt = $this->pdo->prepare("
                INSERT INTO staff_color_config (user_id, color_code, color_name, is_active)
                VALUES (:user_id, :color_code, :color_name, TRUE)
                ON DUPLICATE KEY UPDATE 
                color_code = VALUES(color_code),
                color_name = VALUES(color_name),
                is_active = TRUE,
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                ':user_id' => $staff_id,
                ':color_code' => $color_code,
                ':color_name' => $color_name
            ]);
            
            return ['success' => true, 'message' => 'Staff color updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function updateManagerColor() {
        $data = json_decode(file_get_contents('php://input'), true);
        $manager_id = $data['manager_id'] ?? 0;
        $color_code = $data['color_code'] ?? '';
        $color_name = $data['color_name'] ?? '';
        
        if (!$manager_id || !$color_code || !$color_name) {
            return ['success' => false, 'error' => 'Manager ID, color code, and color name required'];
        }
        
        try {
            // Validate color code format
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_code)) {
                return ['success' => false, 'error' => 'Invalid color code format. Use #RRGGBB format.'];
            }
            
            // Check if manager exists and belongs to station
            $stmt = $this->pdo->prepare("
                SELECT user_id FROM users 
                WHERE user_id = :manager_id AND station_id = :station_id 
                AND role IN ('manager', 'admin')
                AND account_status = 'Active'
            ");
            $stmt->execute([
                ':manager_id' => $manager_id,
                ':station_id' => $this->station_id
            ]);
            
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return ['success' => false, 'error' => 'Manager not found or not authorized'];
            }
            
            // Update or insert color configuration
            $stmt = $this->pdo->prepare("
                INSERT INTO manager_color_config (user_id, color_code, color_name, is_active)
                VALUES (:user_id, :color_code, :color_name, TRUE)
                ON DUPLICATE KEY UPDATE 
                color_code = VALUES(color_code),
                color_name = VALUES(color_name),
                is_active = TRUE,
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $stmt->execute([
                ':user_id' => $manager_id,
                ':color_code' => $color_code,
                ':color_name' => $color_name
            ]);
            
            return ['success' => true, 'message' => 'Manager color updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function initializeColors() {
        try {
            // Initialize staff colors with default pattern
            $default_staff_colors = [
                '#007bff' => 'Blue',
                '#28a745' => 'Green',
                '#fd7e14' => 'Orange',
                '#6f42c1' => 'Purple',
                '#e83e8c' => 'Pink',
                '#6c757d' => 'Gray',
                '#17a2b8' => 'Teal',
                '#20c997' => 'Cyan'
            ];
            
            $stmt = $this->pdo->prepare("
                SELECT user_id FROM users 
                WHERE station_id = :station_id 
                AND role IN ('staff', 'cashier', 'pump_attendant')
                AND account_status = 'Active'
                AND id NOT IN (SELECT user_id FROM staff_color_config WHERE is_active = TRUE)
            ");
            $stmt->execute([':station_id' => $this->station_id]);
            $staff_without_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $color_index = 0;
            $color_keys = array_keys($default_staff_colors);
            
            foreach ($staff_without_colors as $staff) {
                $color_code = $color_keys[$color_index % count($color_keys)];
                $color_name = $default_staff_colors[$color_code];
                
                $insert_stmt = $this->pdo->prepare("
                    INSERT INTO staff_color_config (user_id, color_code, color_name, is_active)
                    VALUES (:user_id, :color_code, :color_name, TRUE)
                ");
                
                $insert_stmt->execute([
                    ':user_id' => $staff['id'],
                    ':color_code' => $color_code,
                    ':color_name' => $color_name
                ]);
                
                $color_index++;
            }
            
            // Initialize manager colors
            $default_manager_colors = [
                '#dc3545' => 'Red',
                '#343a40' => 'Dark',
                '#6f42c1' => 'Purple',
                '#fd7e14' => 'Orange'
            ];
            
            $stmt = $this->pdo->prepare("
                SELECT user_id FROM users 
                WHERE station_id = :station_id 
                AND role IN ('manager', 'admin')
                AND account_status = 'Active'
                AND id NOT IN (SELECT user_id FROM manager_color_config WHERE is_active = TRUE)
            ");
            $stmt->execute([':station_id' => $this->station_id]);
            $managers_without_colors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $manager_color_index = 0;
            $manager_color_keys = array_keys($default_manager_colors);
            
            foreach ($managers_without_colors as $manager) {
                $color_code = $manager_color_keys[$manager_color_index % count($manager_color_keys)];
                $color_name = $default_manager_colors[$color_code];
                
                $insert_stmt = $this->pdo->prepare("
                    INSERT INTO manager_color_config (user_id, color_code, color_name, is_active)
                    VALUES (:user_id, :color_code, :color_name, TRUE)
                ");
                
                $insert_stmt->execute([
                    ':user_id' => $manager['id'],
                    ':color_code' => $color_code,
                    ':color_name' => $color_name
                ]);
                
                $manager_color_index++;
            }
            
            return ['success' => true, 'message' => 'Color initialization completed'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// Initialize and handle request
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $api = new StaffColorConfigAPI($pdo);
    echo json_encode($api->handleRequest());
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
}
?>
