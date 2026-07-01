<?php
/**
 * Service Types API
 *
 * GET  → returns all approved service types (for dropdown)
 * POST → staff submits a new service type (status = 'pending')
 * POST action=approve|reject → manager/admin validates a pending entry
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me   = current_user();
$role = role_key($me['role'] ?? '');

// ── Ensure table exists and has all required columns ─────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS job_order_service_types (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            service_key       VARCHAR(50)    UNIQUE NOT NULL,
            service_name      VARCHAR(100)   NOT NULL,
            category          VARCHAR(100)   NOT NULL DEFAULT 'Others',
            service_price     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            min_price         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            max_price         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
            price_description VARCHAR(255)   NULL,
            pricing_notes     TEXT           NULL,
            icon_class        VARCHAR(50)    NULL,
            color_class       VARCHAR(20)    NULL,
            allows_custom_input TINYINT(1)   DEFAULT 0,
            active            TINYINT(1)     DEFAULT 1,
            sort_order        INT            DEFAULT 0,
            status            ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            submitted_by      INT            NULL,
            reviewed_by       INT            NULL,
            review_note       VARCHAR(255)   NULL,
            created_at        TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
            updated_at        TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add missing columns to existing table
    $cols = $pdo->query("SHOW COLUMNS FROM job_order_service_types")->fetchAll(PDO::FETCH_COLUMN);
    $migrations = [
        'category'     => "ALTER TABLE job_order_service_types ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'Others' AFTER service_name",
        'status'       => "ALTER TABLE job_order_service_types ADD COLUMN status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER sort_order",
        'submitted_by' => "ALTER TABLE job_order_service_types ADD COLUMN submitted_by INT NULL AFTER status",
        'reviewed_by'  => "ALTER TABLE job_order_service_types ADD COLUMN reviewed_by  INT NULL AFTER submitted_by",
        'review_note'  => "ALTER TABLE job_order_service_types ADD COLUMN review_note  VARCHAR(255) NULL AFTER reviewed_by",
        'updated_at'   => "ALTER TABLE job_order_service_types ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ];
    foreach ($migrations as $col => $sql) {
        if (!in_array($col, $cols)) $pdo->exec($sql);
    }

    // ── Re-map any rows still using legacy categories to the new 15-category taxonomy ──
    $legacy_map = [
        'Oil Change'          => 'Lubrication',
        'Wheel Alignment'     => 'Tire Services',
        'Battery Services'    => 'Battery Services',
        'Brake Services'      => 'Brake',
        'Engine Services'     => 'Engine',
        'AC Services'         => 'Air Conditioning',
        'General Inspection'  => 'Inspection',
        'Wheel Alignment'     => 'Tire Services',
    ];
    foreach ($legacy_map as $old => $new) {
        $pdo->prepare("UPDATE job_order_service_types SET category = ? WHERE category = ?")->execute([$new, $old]);
    }

    // Mark all existing rows (no status yet) as approved
    $pdo->exec("UPDATE job_order_service_types SET status = 'approved' WHERE status IS NULL OR status = ''");

    // ── Deactivate any "Other (Manual Input)" rows — replaced by the + button ───
    $pdo->exec("
        UPDATE job_order_service_types
        SET    active = 0
        WHERE  LOWER(service_name) LIKE '%other%manual%'
            OR LOWER(service_name) LIKE '%manual%input%'
            OR service_key IN ('other','other_manual','other_service','custom_input')
    ");

    // ── Only seed if table is completely empty (production catalog already loaded) ──
    $count = (int)$pdo->query("SELECT COUNT(*) FROM job_order_service_types")->fetchColumn();
    if ($count === 0) {
        // No hardcoded seed — table should be populated via the official seeder script.
        // If empty, log a warning and continue cleanly.
        error_log('job_order_service_types is empty. Run the service catalog seeder to populate it.');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Migration error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: return approved and pending service types ───────────────────────────
if ($method === 'GET') {
    $rows = $pdo->query("
        SELECT id, service_key, service_name, category, service_price, min_price, max_price,
               price_description, pricing_notes, icon_class, color_class, status
        FROM   job_order_service_types
        WHERE  active = 1 AND status IN ('approved', 'pending')
        ORDER  BY sort_order ASC, service_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $types = array_map(function($r) {
        $name = $r['service_name'];
        if ($r['status'] === 'pending') {
            $name .= ' (Pending Approval)';
        }
        return [
            'id'       => (int)$r['id'],
            'key'      => $r['service_key'],
            'name'     => $name,
            'category' => $r['category'] ?? 'Others',
            'price'    => (float)$r['service_price'],
            'min'      => (float)$r['min_price'],
            'max'      => (float)$r['max_price'],
            'desc'     => $r['price_description'] ?? '',
            'notes'    => $r['pricing_notes']     ?? '',
            'icon'     => $r['icon_class']        ?? '',
            'color'    => $r['color_class']       ?? '',
        ];
    }, $rows);

    echo json_encode(['success' => true, 'types' => $types]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;

    $action = trim($data['action'] ?? '');

    // ── Approve / Reject (manager/admin only) ────────────────────────────────
    if (in_array($action, ['approve', 'reject'])) {
        if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            exit;
        }
        $id   = (int)($data['id'] ?? 0);
        $note = trim($data['note'] ?? '');
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing service type ID.']);
            exit;
        }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $pdo->prepare("
            UPDATE job_order_service_types
            SET    status = ?, reviewed_by = ?, review_note = ?
            WHERE  id = ?
        ")->execute([$newStatus, $me['id'], $note ?: null, $id]);
        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    }

    // ── Submit new service type (staff) ──────────────────────────────────────
    $service_name  = trim($data['service_name'] ?? '');
    $service_price = floatval($data['service_price'] ?? $data['price'] ?? 0.00);
    $notes         = trim($data['notes'] ?? $data['pricing_notes'] ?? '');
    $category      = trim($data['category'] ?? 'Others');
    // Whitelist category — 15 standardized service categories
    $allowed_categories = [
        'Lubrication', 'PMS', 'Engine', 'Fuel System', 'Cooling System',
        'Transmission', 'Brake', 'Suspension', 'Steering', 'Tire Services',
        'Battery Services', 'Electrical', 'Air Conditioning', 'Diagnostics',
        'Inspection', 'Others'
    ];
    if (!in_array($category, $allowed_categories)) $category = 'Others';

    if (!$service_name) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Service name is required.']);
        exit;
    }
    if (mb_strlen($service_name) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Service name is too long (max 100 characters).']);
        exit;
    }

    // Duplicate check (case-insensitive)
    $dup = $pdo->prepare("SELECT id, status FROM job_order_service_types WHERE LOWER(service_name) = LOWER(?)");
    $dup->execute([$service_name]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $msg = $existing['status'] === 'pending'
            ? 'This service type is already pending approval.'
            : 'This service type already exists.';
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // Generate a safe service_key
    $service_key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $service_name));
    $service_key = trim($service_key, '_');
    // Ensure uniqueness
    $keyCheck = $pdo->prepare("SELECT COUNT(*) FROM job_order_service_types WHERE service_key = ?");
    $keyCheck->execute([$service_key]);
    if ((int)$keyCheck->fetchColumn() > 0) {
        $service_key .= '_' . time();
    }

    $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM job_order_service_types")->fetchColumn();

    $pdo->prepare("
        INSERT INTO job_order_service_types
            (service_key, service_name, category, service_price, pricing_notes, sort_order, status, submitted_by, active)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 1)
    ")->execute([$service_key, $service_name, $category, $service_price, $notes ?: null, $maxSort + 1, $me['id']]);

    $newId = (int)$pdo->lastInsertId();
    echo json_encode([
        'success' => true,
        'id'      => $newId,
        'key'     => $service_key,
        'name'    => $service_name,
        'price'   => $service_price,
        'message' => 'Service type submitted for manager approval.',
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
