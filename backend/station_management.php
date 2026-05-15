<?php
/**
 * Station Management Helper Functions
 * Provides consistent station ID handling across the system
 * 
 * Requirements:
 * - Managers can only create staff for their assigned station
 * - Superadmins must manually select station (no default selection)
 * - Managers see read-only field showing their station
 * - Completely prevent managers from selecting different stations
 */

class StationManager {
    
    /**
     * Determines target station ID for new user creation
     * 
     * @param string $creator_role Role of the user creating the new user
     * @param int $creator_station_id Station ID of the user creating the new user
     * @param int|null $selected_station_id Station ID selected in the form (if any)
     * @return int Target station ID for the new user
     * @throws Exception If validation fails
     */
    public static function getTargetStationForUserCreation($creator_role, $creator_station_id, $selected_station_id = null) {
        switch (strtolower($creator_role)) {
            case 'superadmin':
                // Must manually select station, no default
                if (empty($selected_station_id)) {
                    throw new Exception("Station selection is required for user creation");
                }
                
                // Validate that selected station exists and is active
                if (!self::isValidActiveStation($selected_station_id)) {
                    throw new Exception("Selected station is not valid or inactive");
                }
                
                return (int)$selected_station_id;
                
            case 'admin':
            case 'manager':
                // Must use their own station only
                if (empty($creator_station_id)) {
                    throw new Exception("Creator must have a station assigned to create users");
                }
                
                // If someone tries to pass a different station ID, it's a security violation
                if (!empty($selected_station_id) && (int)$selected_station_id !== (int)$creator_station_id) {
                    throw new Exception("You can only create users for your assigned station");
                }
                
                return (int)$creator_station_id;
                
            default:
                throw new Exception("Insufficient permissions to create users");
        }
    }
    
    /**
     * Validates station assignment permissions
     * 
     * @param string $creator_role Role of the user making the assignment
     * @param int $creator_station_id Station ID of the user making the assignment
     * @param int $target_station_id Station ID being assigned
     * @return bool True if assignment is allowed
     */
    public static function validateStationAssignment($creator_role, $creator_station_id, $target_station_id) {
        try {
            $calculated_target = self::getTargetStationForUserCreation(
                $creator_role, 
                $creator_station_id, 
                $target_station_id
            );
            return $calculated_target === (int)$target_station_id;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Gets station display configuration for UI rendering
     * 
     * @param string $user_role Role of the current user
     * @param int $user_station_id Station ID of the current user
     * @param string $user_station_name Station name of the current user
     * @return array UI configuration array
     */
    public static function getStationUIConfig($user_role, $user_station_id, $user_station_name) {
        if (strtolower($user_role) === 'superadmin') {
            // Get all active stations for radio button selection
            $stations = self::getActiveStations();
            return [
                'type' => 'radio_buttons',
                'required' => true,
                'stations' => $stations,
                'readonly' => false,
                'help_text' => 'Select the station for this user'
            ];
        }
        
        if (in_array(strtolower($user_role), ['admin', 'manager'])) {
            return [
                'type' => 'readonly_field',
                'value' => $user_station_name,
                'hidden_input_value' => $user_station_id,
                'readonly' => true,
                'help_text' => 'Staff will be assigned to your station automatically'
            ];
        }
        
        throw new Exception("Invalid role for user creation: " . $user_role);
    }
    
    /**
     * Validates if a station ID exists and is active
     * 
     * @param int $station_id Station ID to validate
     * @return bool True if station is valid and active
     */
    public static function isValidActiveStation($station_id) {
        global $pdo;
        
        if (empty($pdo)) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM stations WHERE id = ? AND status = 'active'");
            $stmt->execute([$station_id]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Station validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gets all active stations for dropdown population
     * 
     * @return array Array of active stations with id and name
     */
    public static function getActiveStations() {
        global $pdo;
        
        if (empty($pdo)) {
            return [];
        }
        
        try {
            $stmt = $pdo->query("SELECT id, name FROM stations WHERE status = 'active' ORDER BY name ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Failed to fetch active stations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Gets default station for system operations (like registration)
     * Returns the first active station alphabetically
     * 
     * @return int|null Default station ID or null if no active stations
     */
    public static function getDefaultStation() {
        $stations = self::getActiveStations();
        return !empty($stations) ? $stations[0]['id'] : null;
    }
    
    /**
     * Logs station assignment attempt for security monitoring
     * 
     * @param int $user_id ID of user attempting assignment
     * @param string $user_role Role of user attempting assignment
     * @param int $user_station User's current station
     * @param int $attempted_station Station they tried to assign
     * @param bool $success Whether the attempt was successful
     */
    public static function logStationAssignmentAttempt($user_id, $user_role, $user_station, $attempted_station, $success = false) {
        // Only log if audit_logging function exists
        if (function_exists('log_activity')) {
            global $pdo;
            $action_type = $success ? 'Station Assignment' : 'Station Assignment Violation';
            $details = json_encode([
                'user_role' => $user_role,
                'user_station' => $user_station,
                'attempted_station' => $attempted_station,
                'success' => $success,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            if ($pdo instanceof PDO) {
                log_activity($pdo, $user_id, $action_type, $details);
            } else {
                $status = $success ? 'SUCCESS' : 'VIOLATION';
                error_log("STATION_ASSIGNMENT_{$status}: User {$user_id} ({$user_role}) at station {$user_station} attempted to assign station {$attempted_station}");
            }
        } else {
            // Fallback to error log
            $status = $success ? 'SUCCESS' : 'VIOLATION';
            error_log("STATION_ASSIGNMENT_{$status}: User {$user_id} ({$user_role}) at station {$user_station} attempted to assign station {$attempted_station}");
        }
    }
}

/**
 * Helper function to get station name by ID
 * 
 * @param int $station_id Station ID
 * @return string Station name or default string
 */
function get_station_name($station_id) {
    global $pdo;
    
    if (empty($pdo) || empty($station_id)) {
        return "Station " . ($station_id ?? 'Unknown');
    }
    
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $stmt->execute([$station_id]);
        $result = $stmt->fetchColumn();
        return $result ?: "Station " . $station_id;
    } catch (Exception $e) {
        return "Station " . $station_id;
    }
}