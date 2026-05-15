<?php
/**
 * Nozzles API - Fuel nozzle management
 * 
 * Provides endpoints for retrieving nozzle data for POS system
 * Supports filtering by pump and status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

// Check authentication
require_login();

$action = $_GET['action'] ?? 'list';

switch($action) {
    case 'list':
        /**
         * Get all nozzles for current user's station
         * Optional filters:
         * - pump_id: Filter by pump
         * - fuel_type_id: Filter by fuel type
         * - status: Filter by status (active, inactive)
         */
        try {
            $station_id = user_station_id();
            
            $sql = "SELECT 
                        n.id,
                        n.pump_id,
                        n.nozzle_number,
                        n.fuel_type_id,
                        ft.name as fuel_type_name,
                        n.calibration_value,
                        n.status,
                        n.last_calibrated_date,
                        p.pump_number,
                        p.station_id
                    FROM nozzles n
                    LEFT JOIN fuel_types ft ON n.fuel_type_id = ft.id
                    LEFT JOIN fuel_pumps p ON n.pump_id = p.id
                    WHERE p.station_id = ?";
            
            $params = [$station_id];
            
            // Optional filters
            if (!empty($_GET['pump_id'])) {
                $sql .= " AND n.pump_id = ?";
                $params[] = $_GET['pump_id'];
            }
            
            if (!empty($_GET['fuel_type_id'])) {
                $sql .= " AND n.fuel_type_id = ?";
                $params[] = $_GET['fuel_type_id'];
            }
            
            if (!empty($_GET['status'])) {
                $sql .= " AND n.status = ?";
                $params[] = $_GET['status'];
            }
            
            $sql .= " ORDER BY p.pump_number ASC, n.nozzle_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $nozzles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $nozzles]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'get_by_pump':
        /**
         * Get active nozzles for a specific pump
         * Required parameters:
         * - pump_id: The pump ID
         */
        try {
            $pump_id = $_GET['pump_id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($pump_id)) {
                echo json_encode(['success' => false, 'error' => 'pump_id is required']);
                break;
            }
            
            // First verify pump belongs to this station
            $verify_stmt = $pdo->prepare("SELECT id FROM fuel_pumps WHERE id = ? AND station_id = ?");
            $verify_stmt->execute([$pump_id, $station_id]);
            if (!$verify_stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Pump not found']);
                break;
            }
            
            // Get active nozzles for this pump
            $sql = "SELECT 
                        n.id,
                        n.pump_id,
                        n.nozzle_number,
                        n.fuel_type_id,
                        ft.name as fuel_type_name,
                        n.calibration_value,
                        n.status
                    FROM nozzles n
                    LEFT JOIN fuel_types ft ON n.fuel_type_id = ft.id
                    WHERE n.pump_id = ? 
                      AND n.status = 'active'
                    ORDER BY n.nozzle_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pump_id]);
            $nozzles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $nozzles]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'get':
        /**
         * Get single nozzle details
         * Required parameters:
         * - id: Nozzle ID
         */
        try {
            $nozzle_id = $_GET['id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($nozzle_id)) {
                echo json_encode(['success' => false, 'error' => 'Nozzle ID is required']);
                break;
            }
            
            $sql = "SELECT 
                        n.id,
                        n.pump_id,
                        n.nozzle_number,
                        n.fuel_type_id,
                        ft.name as fuel_type_name,
                        n.calibration_value,
                        n.status,
                        n.last_calibrated_date,
                        p.pump_number,
                        p.station_id
                    FROM nozzles n
                    LEFT JOIN fuel_types ft ON n.fuel_type_id = ft.id
                    LEFT JOIN fuel_pumps p ON n.pump_id = p.id
                    WHERE n.id = ? AND p.station_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nozzle_id, $station_id]);
            $nozzle = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($nozzle) {
                echo json_encode(['success' => true, 'data' => $nozzle]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Nozzle not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'validate':
        /**
         * Validate if nozzle exists and is active for a transaction
         * Required parameters:
         * - nozzle_id: The nozzle ID to validate
         * - pump_id: The pump ID (for validation)
         */
        try {
            $nozzle_id = $_GET['nozzle_id'] ?? 0;
            $pump_id = $_GET['pump_id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($nozzle_id) || empty($pump_id)) {
                echo json_encode(['success' => false, 'error' => 'nozzle_id and pump_id are required']);
                break;
            }
            
            $sql = "SELECT 
                        n.id,
                        n.nozzle_number,
                        n.pump_id,
                        n.fuel_type_id,
                        n.status,
                        p.station_id
                    FROM nozzles n
                    LEFT JOIN fuel_pumps p ON n.pump_id = p.id
                    WHERE n.id = ? AND n.pump_id = ? AND p.station_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nozzle_id, $pump_id, $station_id]);
            $nozzle = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$nozzle) {
                echo json_encode(['success' => false, 'error' => 'Nozzle not found']);
                break;
            }
            
            if ($nozzle['status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Nozzle is not active']);
                break;
            }
            
            echo json_encode(['success' => true, 'data' => $nozzle]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
