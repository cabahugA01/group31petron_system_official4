<?php
/**
 * Pumps API - Fuel pump management
 * 
 * Provides endpoints for retrieving pump data for POS system
 * Supports filtering by fuel type, station, and status
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
         * Get all pumps for current user's station
         * Optional filters:
         * - station_id: Filter by specific station
         * - fuel_type_id: Filter by fuel type
         * - status: Filter by status (Active, Inactive, Maintenance)
         */
        try {
            $station_id = user_station_id();
            
            $sql = "SELECT 
                        p.id,
                        p.station_id,
                        p.pump_number,
                        p.fuel_type_id,
                        ft.name as fuel_type_name,
                        p.capacity,
                        p.status,
                        p.created_at
                    FROM fuel_pumps p
                    LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                    WHERE p.station_id = ?";
            
            $params = [$station_id];
            
            // Optional filters
            if (!empty($_GET['fuel_type_id'])) {
                $sql .= " AND p.fuel_type_id = ?";
                $params[] = $_GET['fuel_type_id'];
            }
            
            if (!empty($_GET['status'])) {
                $sql .= " AND p.status = ?";
                $params[] = $_GET['status'];
            }
            
            $sql .= " ORDER BY p.pump_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $pumps]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'get_by_fuel_type':
        /**
         * Get active pumps filtered by fuel type
         * Required parameters:
         * - fuel_type_id: The fuel type ID
         */
        try {
            $fuel_type_id = $_GET['fuel_type_id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($fuel_type_id)) {
                echo json_encode(['success' => false, 'error' => 'fuel_type_id is required']);
                break;
            }
            
            // Get active pumps for this fuel type
            $sql = "SELECT 
                        p.id,
                        p.station_id,
                        p.pump_number,
                        p.fuel_type_id,
                        ft.name as fuel_type_name,
                        p.status
                    FROM fuel_pumps p
                    LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                    WHERE p.station_id = ? 
                      AND p.fuel_type_id = ?
                      AND p.status = 'Active'
                    ORDER BY p.pump_number ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$station_id, $fuel_type_id]);
            $pumps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $pumps]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'get':
        /**
         * Get single pump details
         * Required parameters:
         * - id: Pump ID
         */
        try {
            $pump_id = $_GET['id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($pump_id)) {
                echo json_encode(['success' => false, 'error' => 'Pump ID is required']);
                break;
            }
            
            $sql = "SELECT 
                        p.id,
                        p.station_id,
                        p.pump_number,
                        p.fuel_type_id,
                        ft.name as fuel_type_name,
                        p.capacity,
                        p.status,
                        p.created_at,
                        (SELECT COUNT(*) FROM nozzles WHERE pump_id = p.id) as nozzle_count
                    FROM fuel_pumps p
                    LEFT JOIN fuel_types ft ON p.fuel_type_id = ft.id
                    WHERE p.id = ? AND p.station_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pump_id, $station_id]);
            $pump = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pump) {
                echo json_encode(['success' => true, 'data' => $pump]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Pump not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    case 'validate':
        /**
         * Validate if pump exists and is active for a transaction
         * Required parameters:
         * - pump_id: The pump ID to validate
         * - fuel_type_id: The fuel type being sold (optional, for validation)
         */
        try {
            $pump_id = $_GET['pump_id'] ?? 0;
            $station_id = user_station_id();
            
            if (empty($pump_id)) {
                echo json_encode(['success' => false, 'error' => 'pump_id is required']);
                break;
            }
            
            $sql = "SELECT id, pump_number, fuel_type_id, status 
                    FROM fuel_pumps 
                    WHERE id = ? AND station_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$pump_id, $station_id]);
            $pump = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pump) {
                echo json_encode(['success' => false, 'error' => 'Pump not found']);
                break;
            }
            
            if ($pump['status'] !== 'Active') {
                echo json_encode(['success' => false, 'error' => 'Pump is not active']);
                break;
            }
            
            // Optionally validate fuel type
            if (!empty($_GET['fuel_type_id']) && $pump['fuel_type_id'] != $_GET['fuel_type_id']) {
                echo json_encode(['success' => false, 'error' => 'Pump fuel type does not match']);
                break;
            }
            
            echo json_encode(['success' => true, 'data' => $pump]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>
