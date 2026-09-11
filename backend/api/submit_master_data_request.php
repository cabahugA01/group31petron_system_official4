<?php
/**
 * Submit Master Data Request API
 * backend/api/submit_master_data_request.php
 *
 * Serves both POST (submit request) and GET (list requests with filters).
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$me   = current_user();
$role = role_key($me['role'] ?? '');

// Only operational roles can submit/view requests
$allowed_roles = ['staff', 'manager', 'admin', 'superadmin', 'developer'];
if (!in_array($role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$stationId = !empty($me['station_id']) ? (int)$me['station_id'] : null;

// ── Handle POST: Submit new request ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $reqType     = $input['request_type'] ?? ''; // 'vehicle_type', 'service_type', 'product'
    $requestData = $input['request_data'] ?? null;
    
    if (empty($requestData) || !is_array($requestData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Request data is required.']);
        exit;
    }

    $category = '';
    $sourceModule = '';

    if ($reqType === 'product') {
        $category = 'Merchandise Product';
        $sourceModule = 'Merchandise';
        if (empty($requestData['product_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Product name is required.']);
            exit;
        }
        if (empty($requestData['category'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Category is required.']);
            exit;
        }
        if (empty($requestData['unit'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unit is required.']);
            exit;
        }
    } elseif ($reqType === 'service_type') {
        $category = 'Service Type';
        $sourceModule = 'Job Order';
        if (empty($requestData['service_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Service name is required.']);
            exit;
        }
        $svc_cat = $requestData['service_category'] ?? $requestData['category'] ?? '';
        if (empty($svc_cat)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Category is required.']);
            exit;
        }
        $svc_price = (float)($requestData['default_price'] ?? $requestData['suggested_price'] ?? 0);
        if ($svc_price < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid service fee is required.']);
            exit;
        }
    } elseif ($reqType === 'vehicle_type') {
        $category = 'Vehicle';
        $sourceModule = 'Job Order';
        if (empty($requestData['vehicle_brand'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle brand is required.']);
            exit;
        }
        if (empty($requestData['vehicle_model'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle model is required.']);
            exit;
        }
        if (empty($requestData['vehicle_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Vehicle type is required.']);
            exit;
        }
        if (empty($requestData['fuel_type'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Fuel type is required.']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request type.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $isAdmin = in_array($role, ['admin', 'superadmin'], true);

        if ($isAdmin) {
            // ── DIRECT AUTO-APPROVAL FOR ADMIN ──────────────────────────────
            $stmt = $pdo->prepare("
                INSERT INTO master_data_requests 
                    (category, source_module, requested_by, reviewed_by, station_id, status, data_payload, created_at, updated_at)
                VALUES 
                    (?, ?, ?, ?, ?, 'Approved', ?, NOW(), NOW())
            ");
            $stmt->execute([
                $category,
                $sourceModule,
                $me['id'],
                $me['id'],
                $stationId,
                json_encode($requestData)
            ]);

            $requestId = $pdo->lastInsertId();
            $requestNo = sprintf('MDR-%05d', $requestId);

            $update = $pdo->prepare("UPDATE master_data_requests SET request_no = ? WHERE id = ?");
            $update->execute([$requestNo, $requestId]);

            // Insert into production tables immediately
            $newId = null;
            $reqStationId = !empty($stationId) ? (int)$stationId : 1;

            if ($reqType === 'product') {
                $sku = !empty($requestData['sku'])
                    ? $requestData['sku']
                    : ('SKU-' . strtoupper(substr(md5(($requestData['product_name'] ?? '') . time()), 0, 8)));

                $unitPrice = floatval(
                    $requestData['unit_price'] ??
                    $requestData['selling_price'] ??
                    $requestData['suggested_price'] ??
                    0.00
                );
                $unitCost = $unitPrice * 0.70;

                $pStmt = $pdo->prepare("
                    INSERT INTO inventory_products
                        (product_name, category, sku, unit_price, unit_cost, selling_price, stock, stock_quantity, status, station_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 0, 0, 'active', ?, NOW(), NOW())
                ");
                $pStmt->execute([
                    $requestData['product_name'] ?? '',
                    $requestData['category']      ?? 'Lubricants',
                    $sku,
                    $unitPrice,
                    $unitCost,
                    $unitPrice,
                    $reqStationId
                ]);
                $newId = $pdo->lastInsertId();

                if ($newId) {
                    try {
                        $siStmt = $pdo->prepare("
                            INSERT INTO station_inventory (station_id, product_id, stock_level, status, last_updated)
                            VALUES (?, ?, 0, 'active', NOW())
                        ");
                        $siStmt->execute([$reqStationId, $newId]);
                    } catch (PDOException $e) {}
                }

            } elseif ($reqType === 'service_type') {
                $serviceName = $requestData['service_name'] ?? '';
                $serviceKey  = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $serviceName));
                $serviceKey  = trim($serviceKey, '_') . '_' . time();
                $suggestedPrice = isset($requestData['default_price']) ? floatval($requestData['default_price']) : (isset($requestData['suggested_price']) ? floatval($requestData['suggested_price']) : 0.00);

                $sStmt = $pdo->prepare("
                    INSERT INTO job_order_service_types
                        (service_key, service_name, category, service_price, pricing_notes, sort_order, status, submitted_by, reviewed_by, active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM job_order_service_types j2), 'approved', ?, ?, 1, NOW(), NOW())
                ");
                $sStmt->execute([
                    $serviceKey,
                    $serviceName,
                    $requestData['service_category'] ?? $requestData['category'] ?? 'Others',
                    $suggestedPrice,
                    $requestData['estimated_duration'] ?? null,
                    $me['id'],
                    $me['id']
                ]);
                $newId = $pdo->lastInsertId();

            } elseif ($reqType === 'vehicle_type') {
                $vehicleName = trim(($requestData['vehicle_brand'] ?? '') . ' ' . ($requestData['vehicle_model'] ?? ''));

                $vStmt = $pdo->prepare("
                    INSERT INTO vehicle_types
                        (category, vehicle_name, status, submitted_by, reviewed_by, is_active, created_at, updated_at)
                    VALUES (?, ?, 'approved', ?, ?, 1, NOW(), NOW())
                ");
                $vStmt->execute([
                    $requestData['vehicle_type'] ?? 'Sedan',
                    $vehicleName,
                    $me['id'],
                    $me['id']
                ]);
                $newId = $pdo->lastInsertId();
            }

            $pdo->commit();

            if (function_exists('log_activity')) {
                log_activity($pdo, $me['id'], "Admin Direct Master Data Add", "Category: {$category} | Request: {$requestNo}");
            }

            echo json_encode([
                'success'       => true,
                'auto_approved' => true,
                'request_id'    => $requestId,
                'request_no'    => $requestNo,
                'category'      => $category,
                'new_record_id' => $newId ?? null,
                'message'       => "{$category} added and automatically approved successfully."
            ]);
            exit;
        }

        // ── STANDARD PENDING SUBMISSION FOR REGULAR STAFF ───────────────────
        $stmt = $pdo->prepare("
            INSERT INTO master_data_requests 
                (category, source_module, requested_by, station_id, status, data_payload, created_at)
            VALUES 
                (?, ?, ?, ?, 'Pending', ?, NOW())
        ");
        $stmt->execute([
            $category,
            $sourceModule,
            $me['id'],
            $stationId,
            json_encode($requestData)
        ]);

        $requestId = $pdo->lastInsertId();
        $requestNo = sprintf('MDR-%05d', $requestId);

        $update = $pdo->prepare("UPDATE master_data_requests SET request_no = ? WHERE id = ?");
        $update->execute([$requestNo, $requestId]);

        $pdo->commit();

        // Send notification to Managers
        try {
            $requestedItem = '';
            if ($reqType === 'product') $requestedItem = $requestData['product_name'] ?? '';
            elseif ($reqType === 'service_type') $requestedItem = $requestData['service_name'] ?? '';
            else $requestedItem = ($requestData['vehicle_brand'] ?? '') . ' ' . ($requestData['vehicle_model'] ?? '');

            $requesterName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
            if (empty($requesterName)) $requesterName = $me['name'] ?? 'Staff';

            // ── Notify manager(s) — event-driven ────────────────────────
            if ($stationId && function_exists('notify_manager')) {
                notify_manager(
                    $pdo, $stationId,
                    'info', 'master_data_request', 'medium',
                    'New Master Data Request: ' . $category,
                    "{$requesterName} requested to add {$requestedItem}. Review required.",
                    "mdr_submitted_{$requestId}",
                    'manager_request_data_management.php',
                    'master_data_request', $requestId
                );
            }
        } catch (Exception $e) {
            // Non-critical notification failure
        }

        echo json_encode([
            'success'    => true,
            'request_id' => $requestId,
            'request_no' => $requestNo,
            'message'    => 'Request submitted successfully. Waiting for manager approval.'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ── Handle GET: Fetch requests (for manager request management page) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status      = $_GET['status'] ?? '';
    $category    = $_GET['category'] ?? '';
    $requestedBy = $_GET['requested_by'] ?? '';
    $dateFrom    = $_GET['date_from'] ?? '';
    $dateTo      = $_GET['date_to'] ?? '';
    $search      = $_GET['search'] ?? '';

    try {
        $query = "
            SELECT 
                r.*,
                COALESCE(CONCAT(u.first_name, ' ', u.last_name), u.name, 'Unknown Staff') as requester_name,
                COALESCE(CONCAT(rev.first_name, ' ', rev.last_name), rev.name, '') as reviewer_name
            FROM master_data_requests r
            LEFT JOIN users u ON r.requested_by = u.id
            LEFT JOIN users rev ON r.reviewed_by = rev.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($status)) {
            $query .= " AND r.status = ?";
            $params[] = $status;
        }

        if (!empty($category)) {
            $query .= " AND r.category = ?";
            $params[] = $category;
        }

        if (!empty($requestedBy)) {
            $query .= " AND r.requested_by = ?";
            $params[] = (int)$requestedBy;
        }

        if (!empty($dateFrom)) {
            $query .= " AND DATE(r.created_at) >= ?";
            $params[] = $dateFrom;
        }

        if (!empty($dateTo)) {
            $query .= " AND DATE(r.created_at) <= ?";
            $params[] = $dateTo;
        }

        if (!empty($search)) {
            $query .= " AND (r.request_no LIKE ? OR r.data_payload LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $query .= " ORDER BY r.created_at DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format dates and decode JSON payloads
        foreach ($requests as &$req) {
            $req['data_payload'] = json_decode($req['data_payload'], true);
            $req['date_submitted'] = date('M d, Y', strtotime($req['created_at']));
        }

        echo json_encode([
            'success'  => true,
            'requests' => $requests
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
