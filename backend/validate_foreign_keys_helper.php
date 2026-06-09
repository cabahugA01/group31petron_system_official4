<?php
/**
 * Foreign Key Validation Helper Functions
 * Prevents SQLSTATE[23000] foreign key constraint violations
 */

/**
 * Validates if a pump_id exists in fuel_pumps table
 * @param PDO $pdo Database connection
 * @param int $pump_id Pump ID to validate
 * @param int $station_id Optional station ID for additional validation
 * @return bool True if pump exists, false otherwise
 */
function validatePumpId($pdo, $pump_id, $station_id = null) {
    if (empty($pump_id) || $pump_id <= 0) {
        return false;
    }
    
    $sql = "SELECT id FROM fuel_pumps WHERE id = ?";
    $params = [$pump_id];
    
    if ($station_id !== null) {
        $sql .= " AND station_id = ?";
        $params[] = $station_id;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() !== false;
}

/**
 * Gets a valid pump_id for a given station and fuel type
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID
 * @param int $fuel_type_id Optional fuel type ID
 * @return int|null Valid pump ID or null if none found
 */
function getValidPumpId($pdo, $station_id, $fuel_type_id = null) {
    // First try to find pump for specific fuel type
    if ($fuel_type_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? AND fuel_type_id = ? LIMIT 1");
        $stmt->execute([$station_id, $fuel_type_id]);
        $pump = $stmt->fetch();
        if ($pump) {
            return (int)$pump['id'];
        }
    }
    
    // Fallback to any pump for this station
    $stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE station_id = ? LIMIT 1");
    $stmt->execute([$station_id]);
    $pump = $stmt->fetch();
    if ($pump) {
        return (int)$pump['id'];
    }
    
    return null;
}

/**
 * Validates if a station_id exists in stations table
 * @param PDO $pdo Database connection
 * @param int $station_id Station ID to validate
 * @return bool True if station exists, false otherwise
 */
function validateStationId($pdo, $station_id) {
    if (empty($station_id) || $station_id <= 0) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM stations WHERE id = ?");
    $stmt->execute([$station_id]);
    return $stmt->fetch() !== false;
}

/**
 * Validates if a fuel_type_id exists in fuel_types table
 * @param PDO $pdo Database connection
 * @param int $fuel_type_id Fuel type ID to validate
 * @return bool True if fuel type exists, false otherwise
 */
function validateFuelTypeId($pdo, $fuel_type_id) {
    if (empty($fuel_type_id) || $fuel_type_id <= 0) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE id = ?");
    $stmt->execute([$fuel_type_id]);
    return $stmt->fetch() !== false;
}

/**
 * Validates if a user_id exists in users table
 * @param PDO $pdo Database connection
 * @param int $user_id User ID to validate
 * @return bool True if user exists, false otherwise
 */
function validateUserId($pdo, $user_id) {
    if (empty($user_id) || $user_id <= 0) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch() !== false;
}

/**
 * Comprehensive validation for fuel_reconciliation table insertions
 * @param PDO $pdo Database connection
 * @param array $data Data to validate (should contain station_id, pump_id, fuel_type_id, etc.)
 * @return array ['valid' => bool, 'errors' => array, 'cleaned_data' => array]
 */
function validateFuelReconciliationData($pdo, $data) {
    $errors = [];
    $cleaned_data = $data;
    
    // Validate station_id
    if (!validateStationId($pdo, $data['station_id'])) {
        $errors[] = "Invalid station_id: {$data['station_id']}";
    }
    
    // Validate and/or fix pump_id
    if (!validatePumpId($pdo, $data['pump_id'], $data['station_id'])) {
        // Try to get a valid pump_id for this station
        $valid_pump_id = getValidPumpId($pdo, $data['station_id'], $data['fuel_type_id'] ?? null);
        if ($valid_pump_id !== null) {
            $cleaned_data['pump_id'] = $valid_pump_id;
            $errors[] = "Invalid pump_id replaced with valid pump_id: $valid_pump_id";
        } else {
            $errors[] = "No valid pump_id found for station {$data['station_id']}";
        }
    }
    
    // Validate fuel_type_id if provided
    if (isset($data['fuel_type_id']) && !validateFuelTypeId($pdo, $data['fuel_type_id'])) {
        $errors[] = "Invalid fuel_type_id: {$data['fuel_type_id']}";
    }
    
    // Validate user_id fields if present
    $user_fields = ['verified_by', 'investigated_by', 'created_by'];
    foreach ($user_fields as $field) {
        if (isset($data[$field]) && !validateUserId($pdo, $data[$field])) {
            $errors[] = "Invalid $field: {$data[$field]}";
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'cleaned_data' => $cleaned_data
    ];
}

/**
 * Logs foreign key validation errors for debugging
 * @param PDO $pdo Database connection
 * @param string $operation Operation being performed
 * @param array $data Data that failed validation
 * @param array $errors Validation errors
 */
function logForeignKeyError($pdo, $operation, $data, $errors) {
    $error_details = json_encode([
        'operation' => $operation,
        'data' => $data,
        'errors' => $errors,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
    $stmt = $pdo->prepare("
        INSERT INTO system_logs (log_level, message, details, created_at) 
        VALUES ('ERROR', ?, ?, NOW())
    ");
    $stmt->execute([
        "Foreign Key Validation Failed: $operation",
        $error_details
    ]);
}
?>
