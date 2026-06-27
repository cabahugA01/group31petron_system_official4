<?php
/**
 * Employee ID Helper Functions
 * 
 * Purpose: Generate unique employee IDs with role-based prefixes
 * Format: ADM-001, MGR-001, STF-001, STF-002, etc.
 */

require_once __DIR__ . '/../public/db_connect.php';

/**
 * Generate employee ID based on role
 * 
 * @param string $role User role (admin, manager, staff)
 * @param int $station_id Station ID for scoping
 * @return string Generated employee ID
 */
function generateEmployeeId($role, $station_id = null) {
    global $pdo;
    
    // Determine prefix based on role
    $prefix = match(strtolower($role)) {
        'admin' => 'ADM',
        'manager' => 'MGR',
        'staff' => 'STF',
        'superadmin' => 'SA',
        default => 'USR'
    };
    
    try {
        // Get the next available number for this role
        // For station-scoped roles (manager, staff), scope by station
        if (in_array(strtolower($role), ['manager', 'staff']) && $station_id) {
            $stmt = $pdo->prepare("
                SELECT employee_id 
                FROM users 
                WHERE role = ? 
                AND station_id = ?
                AND employee_id LIKE ?
                ORDER BY employee_id DESC 
                LIMIT 1
            ");
            $stmt->execute([$role, $station_id, $prefix . '-%']);
        } else {
            // For admin and superadmin, no station scoping
            $stmt = $pdo->prepare("
                SELECT employee_id 
                FROM users 
                WHERE role = ? 
                AND employee_id LIKE ?
                ORDER BY employee_id DESC 
                LIMIT 1
            ");
            $stmt->execute([$role, $prefix . '-%']);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['employee_id']) {
            // Extract number from existing ID and increment
            preg_match('/\d+$/', $result['employee_id'], $matches);
            $next_number = isset($matches[0]) ? intval($matches[0]) + 1 : 1;
        } else {
            // No existing IDs, start from 1
            $next_number = 1;
        }
        
        // Format: PREFIX-NNN (e.g., STF-001, STF-002)
        return $prefix . '-' . str_pad($next_number, 3, '0', STR_PAD_LEFT);
        
    } catch (PDOException $e) {
        error_log("Error generating employee ID: " . $e->getMessage());
        // Fallback: generate random number
        return $prefix . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
}

/**
 * Check if employee ID already exists
 * 
 * @param string $employee_id Employee ID to check
 * @return bool True if exists, false otherwise
 */
function employeeIdExists($employee_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error checking employee ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user by employee ID
 * 
 * @param string $employee_id Employee ID
 * @return array|null User data or null if not found
 */
function getUserByEmployeeId($employee_id) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.*,
                s.name as station_name
            FROM users u
            LEFT JOIN stations s ON u.station_id = s.id
            WHERE u.employee_id = ?
        ");
        $stmt->execute([$employee_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching user by employee ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Format employee ID for display with role badge
 * 
 * @param string $employee_id Employee ID
 * @param string $role User role
 * @return string HTML formatted employee ID
 */
function formatEmployeeIdBadge($employee_id, $role) {
    $badge_class = match(strtolower($role)) {
        'admin' => 'badge-danger',
        'manager' => 'badge-primary',
        'staff' => 'badge-secondary',
        'superadmin' => 'badge-warning',
        default => 'badge-secondary'
    };
    
    return '<span class="badge ' . $badge_class . '">' . htmlspecialchars($employee_id) . '</span>';
}

/**
 * Validate employee ID format
 * 
 * @param string $employee_id Employee ID to validate
 * @return bool True if valid format, false otherwise
 */
function isValidEmployeeIdFormat($employee_id) {
    // Format: PREFIX-NNN (e.g., STF-001, MGR-001, ADM-001)
    return preg_match('/^(ADM|MGR|STF|SA|USR)-\d{3}$/', $employee_id) === 1;
}
?>
