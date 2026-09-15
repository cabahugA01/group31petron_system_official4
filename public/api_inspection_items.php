<?php
/**
 * API: Vehicle Inspection Items — add new inspection checklist item
 * Used by the + Add Inspection modal in staff_transactions_hub.php
 */
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
ob_end_clean();

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$me = current_user();
if (!$me) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: Return all active items ──────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, item_name, description, category, is_active
             FROM vehicle_inspection_items
             WHERE is_active = 1 AND station_id = ?
             ORDER BY id ASC"
        );
        $stmt->execute([$station_id]);
        echo json_encode(['success' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── POST: Add / Request new item ──────────────────────────────────────────────
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    $data = array_merge($_POST, $data);

    $item_name   = trim($data['item_name'] ?? $data['inspection_name'] ?? '');
    $description = trim($data['description'] ?? '');
    $category    = trim($data['category'] ?? 'General');
    $is_active   = isset($data['is_active']) ? (int)$data['is_active'] : 1;

    if ($item_name === '') {
        echo json_encode(['success' => false, 'error' => 'Inspection name is required.']);
        exit;
    }
    if (strlen($item_name) > 100) {
        echo json_encode(['success' => false, 'error' => 'Inspection name must not exceed 100 characters.']);
        exit;
    }

    $isAdmin = in_array($role, ['admin', 'superadmin', 'developer'], true);

    try {
        if ($isAdmin) {
            // Check duplicate in production table for this station
            $dup = $pdo->prepare("SELECT COUNT(*) FROM vehicle_inspection_items WHERE LOWER(TRIM(item_name)) = LOWER(TRIM(?)) AND station_id = ?");
            $dup->execute([$item_name, $station_id]);
            if ((int)$dup->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'error' => '"' . $item_name . '" already exists in the inspection checklist.']);
                exit;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO vehicle_inspection_items (station_id, item_name, description, category, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $station_id ?: null,
                $item_name,
                $description ?: null,
                $category ?: 'General',
                $is_active,
                $me['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            echo json_encode([
                'success'       => true,
                'auto_approved' => true,
                'message'       => '"' . $item_name . '" added to inspection checklist successfully!',
                'id'            => $newId,
                'item_name'     => $item_name,
            ]);
            exit;
        }

        // Staff submission: submit as master_data_request
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO master_data_requests 
                (category, source_module, requested_by, station_id, status, data_payload, created_at)
            VALUES 
                ('Inspection Item', 'Job Order', ?, ?, 'Pending', ?, NOW())
        ");
        $payload = [
            'item_name'   => $item_name,
            'description' => $description,
            'category'    => $category,
            'is_active'   => $is_active
        ];
        $stmt->execute([
            $me['id'],
            $station_id ?: null,
            json_encode($payload)
        ]);

        $requestId = $pdo->lastInsertId();
        $requestNo = sprintf('MDR-%05d', $requestId);

        $update = $pdo->prepare("UPDATE master_data_requests SET request_no = ? WHERE id = ?");
        $update->execute([$requestNo, $requestId]);

        $pdo->commit();

        // Notify manager
        try {
            $requesterName = trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? ''));
            if (empty($requesterName)) $requesterName = $me['name'] ?? 'Staff';

            if ($station_id && function_exists('notify_manager')) {
                notify_manager(
                    $pdo, $station_id,
                    'info', 'master_data_request', 'medium',
                    'New Master Data Request: Inspection Item',
                    "{$requesterName} requested to add inspection item: {$item_name}. Review required.",
                    "mdr_submitted_{$requestId}",
                    'manager_request_data_management.php',
                    'master_data_request', $requestId
                );
            }
        } catch (Exception $e) {}

        echo json_encode([
            'success'       => true,
            'auto_approved' => false,
            'request_id'    => $requestId,
            'request_no'    => $requestNo,
            'message'       => 'Request submitted successfully. Waiting for manager approval.'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request method']);