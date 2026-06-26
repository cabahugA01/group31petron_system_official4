<?php
/**
 * Submit Master Data Request API
 * 
 * Staff submits requests for new Vehicle Types, Service Types, or Products
 * These require manager approval before being added to the system
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me   = current_user();
$role = role_key($me['role'] ?? '');

// Only staff and above can submit requests
if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

// Ensure table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_data_requests (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            request_type        ENUM('vehicle_type', 'service_type', 'product') NOT NULL,
            request_data        JSON NOT NULL,
            requested_by        INT NOT NULL,
            station_id          INT NULL,
            request_reason      TEXT NULL,
            status              ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            reviewed_by         INT NULL,
            review_note         VARCHAR(500) NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at         DATETIME NULL,
            INDEX idx_status (status),
            INDEX idx_request_type (request_type),
            INDEX idx_requested_by (requested_by),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    // Table might already exist, continue
}

// ── Handle POST: Submit new request ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $requestType = $input['request_type'] ?? '';
    $requestData = $input['request_data'] ?? null;
    $reason      = trim($input['reason'] ?? '');
    
    // Validation
    if (!in_array($requestType, ['vehicle_type', 'service_type', 'product'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request type.']);
        exit;
    }
    
    if (empty($requestData) || !is_array($requestData)) {
        echo json_encode(['success' => false, 'error' => 'Request data is required.']);
        exit;
    }
    
    if (empty($reason)) {
        echo json_encode(['success' => false, 'error' => 'Reason for request is required.']);
        exit;
    }
    
    // Specific validation based on request type
    if ($requestType === 'vehicle_type') {
        if (empty($requestData['vehicle_name'])) {
            echo json_encode(['success' => false, 'error' => 'Vehicle type name is required.']);
            exit;
        }
        if (empty($requestData['category'])) {
            echo json_encode(['success' => false, 'error' => 'Vehicle category is required.']);
            exit;
        }
    } elseif ($requestType === 'service_type') {
        if (empty($requestData['service_name'])) {
            echo json_encode(['success' => false, 'error' => 'Service name is required.']);
            exit;
        }
        if (empty($requestData['service_category'])) {
            echo json_encode(['success' => false, 'error' => 'Service category is required.']);
            exit;
        }
        if (!isset($requestData['default_price']) || $requestData['default_price'] < 0) {
            echo json_encode(['success' => false, 'error' => 'Valid service fee is required.']);
            exit;
        }
    } elseif ($requestType === 'product') {
        if (empty($requestData['product_name'])) {
            echo json_encode(['success' => false, 'error' => 'Product name is required.']);
            exit;
        }
        if (empty($requestData['category'])) {
            echo json_encode(['success' => false, 'error' => 'Product category is required.']);
            exit;
        }
    }
    
    try {
        // Get user's station ID if available
        $stationId = !empty($me['station_id']) ? (int)$me['station_id'] : null;
        
        $stmt = $pdo->prepare("
            INSERT INTO master_data_requests 
                (request_type, request_data, requested_by, station_id, request_reason, status, created_at)
            VALUES 
                (:request_type, :request_data, :requested_by, :station_id, :reason, 'pending', NOW())
        ");
        
        $stmt->execute([
            ':request_type'  => $requestType,
            ':request_data'  => json_encode($requestData),
            ':requested_by'  => $me['id'],
            ':station_id'    => $stationId,
            ':reason'        => $reason
        ]);
        
        $requestId = $pdo->lastInsertId();
        
        echo json_encode([
            'success'    => true,
            'request_id' => $requestId,
            'message'    => 'Request submitted successfully. Waiting for manager approval.'
        ]);
        
    } catch (PDOException $e) {
        error_log("Submit master data request error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    
    exit;
}

// ── Handle GET: Fetch requests (for manager approval page) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['status'] ?? 'pending';
    $type   = $_GET['type'] ?? '';
    
    try {
        $query = "
            SELECT 
                r.*,
                CONCAT(u.first_name, ' ', u.last_name) as requester_name,
                u.role as requester_role,
                s.name as station_name,
                CONCAT(rev.first_name, ' ', rev.last_name) as reviewer_name
            FROM master_data_requests r
            LEFT JOIN users u ON r.requested_by = u.id
            LEFT JOIN stations s ON r.station_id = s.id
            LEFT JOIN users rev ON r.reviewed_by = rev.id
            WHERE r.status = :status
        ";
        
        if (!empty($type) && in_array($type, ['vehicle_type', 'service_type', 'product'])) {
            $query .= " AND r.request_type = :type";
        }
        
        $query .= " ORDER BY r.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindValue(':status', $status);
        if (!empty($type)) {
            $stmt->bindValue(':type', $type);
        }
        $stmt->execute();
        
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON data for each request
        foreach ($requests as &$req) {
            $req['request_data'] = json_decode($req['request_data'], true);
        }
        
        echo json_encode([
            'success'  => true,
            'requests' => $requests
        ]);
        
    } catch (PDOException $e) {
        error_log("Fetch master data requests error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error.']);
    }
    
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
