<?php
/**
 * Vehicle Types API
 *
 * GET  → returns all approved vehicle types grouped by category
 * POST → staff submits a new vehicle type (status = 'pending')
 * POST action=approve|reject → manager/admin validates a pending entry
 */
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

header('Content-Type: application/json');

$me   = current_user();
$role = role_key($me['role'] ?? '');

// ── Ensure table exists (auto-migrate) ───────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_types (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            category     VARCHAR(100)  NOT NULL,
            vehicle_name VARCHAR(150)  NOT NULL,
            sort_order   INT           NOT NULL DEFAULT 0,
            status       ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            submitted_by INT           NULL,
            reviewed_by  INT           NULL,
            review_note  VARCHAR(255)  NULL,
            is_active    TINYINT(1)    NOT NULL DEFAULT 1,
            created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_vehicle_name (vehicle_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Add columns to existing table if missing
    $cols = $pdo->query("SHOW COLUMNS FROM vehicle_types")->fetchAll(PDO::FETCH_COLUMN);
    $add  = [
        'status'       => "ALTER TABLE vehicle_types ADD COLUMN status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER sort_order",
        'submitted_by' => "ALTER TABLE vehicle_types ADD COLUMN submitted_by INT NULL AFTER status",
        'reviewed_by'  => "ALTER TABLE vehicle_types ADD COLUMN reviewed_by  INT NULL AFTER submitted_by",
        'review_note'  => "ALTER TABLE vehicle_types ADD COLUMN review_note  VARCHAR(255) NULL AFTER reviewed_by",
        'updated_at'   => "ALTER TABLE vehicle_types ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($add as $col => $sql) {
        if (!in_array($col, $cols)) $pdo->exec($sql);
    }

    // ── Seed default approved data if table is empty ─────────────────────────
    $count = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_types")->fetchColumn();
    if ($count === 0) {
        $seed = [
            ['Sedans / Hatchbacks', 'Toyota Vios',           1],
            ['Sedans / Hatchbacks', 'Honda City',            2],
            ['Sedans / Hatchbacks', 'Mitsubishi Mirage',     3],
            ['Sedans / Hatchbacks', 'Honda Civic',           4],
            ['Sedans / Hatchbacks', 'Toyota Corolla Altis',  5],
            ['Sedans / Hatchbacks', 'Mazda 3',               6],
            ['Sedans / Hatchbacks', 'Hyundai Accent',        7],
            ['Sedans / Hatchbacks', 'Kia Rio',               8],
            ['Sedans / Hatchbacks', 'Suzuki Swift',          9],
            ['Sedans / Hatchbacks', 'Nissan Almera',        10],
            ['SUVs', 'Toyota Fortuner',        11],
            ['SUVs', 'Mitsubishi Montero',      12],
            ['SUVs', 'Ford Everest',            13],
            ['SUVs', 'Isuzu MU-X',             14],
            ['SUVs', 'Nissan Terra',            15],
            ['SUVs', 'Chevrolet Trailblazer',   16],
            ['SUVs', 'Hyundai Tucson',          17],
            ['SUVs', 'Kia Sportage',            18],
            ['Pickups', 'Toyota Hilux',     19],
            ['Pickups', 'Mitsubishi Strada', 20],
            ['Pickups', 'Ford Ranger',       21],
            ['Pickups', 'Isuzu D-Max',       22],
            ['Pickups', 'Nissan Navara',     23],
            ['Pickups', 'Mazda BT-50',       24],
            ['Vans', 'Toyota Hiace',     25],
            ['Vans', 'Nissan Urvan',     26],
            ['Vans', 'Hyundai Starex',   27],
            ['Vans', 'Kia Carnival',     28],
            ['Vans', 'Mitsubishi L300',  29],
            ['Light Trucks / Utility', 'Isuzu Elf',         30],
            ['Light Trucks / Utility', 'Mitsubishi Canter', 31],
            ['Light Trucks / Utility', 'Suzuki Multicab',   32],
            ['Light Trucks / Utility', 'Jeepney',           33],
            ['Motorcycles', 'Honda Click',      34],
            ['Motorcycles', 'Yamaha Mio',       35],
            ['Motorcycles', 'Honda Wave',       36],
            ['Motorcycles', 'Suzuki Raider',    37],
            ['Motorcycles', 'Kawasaki Rouser',  38],
            ['Motorcycles', 'Honda Beat',       39],
            ['Motorcycles', 'Yamaha Aerox',     40],
            ['Motorcycles', 'Honda ADV',        41],
            ['Tricycles / E-bikes', 'Tricycle', 42],
            ['Tricycles / E-bikes', 'E-bike',   43],
        ];
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO vehicle_types (category, vehicle_name, sort_order, status) VALUES (?, ?, ?, 'approved')"
        );
        foreach ($seed as $row) $stmt->execute($row);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Migration error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: return approved and pending vehicle types ───────────────────────────
if ($method === 'GET') {
    $rows = $pdo->query("
        SELECT id, category, vehicle_name, sort_order, status
        FROM   vehicle_types
        WHERE  is_active = 1 AND status IN ('approved', 'pending')
        ORDER  BY sort_order ASC, category ASC, vehicle_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $r) {
        $name = $r['vehicle_name'];
        if ($r['status'] === 'pending') {
            $name .= ' (Pending Approval)';
        }
        $grouped[$r['category']][] = ['id' => (int)$r['id'], 'name' => $name];
    }
    echo json_encode(['success' => true, 'groups' => $grouped]);
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
            echo json_encode(['success' => false, 'error' => 'Missing vehicle type ID.']);
            exit;
        }
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("
            UPDATE vehicle_types
            SET    status = ?, reviewed_by = ?, review_note = ?
            WHERE  id = ?
        ");
        $stmt->execute([$newStatus, $me['id'], $note ?: null, $id]);
        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    }

    // ── Submit new vehicle type (staff) ──────────────────────────────────────
    $vehicle_name = trim($data['vehicle_name'] ?? '');
    $category     = trim($data['category']     ?? 'Other');

    if (!$vehicle_name) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vehicle name is required.']);
        exit;
    }
    if (mb_strlen($vehicle_name) > 150) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vehicle name is too long (max 150 characters).']);
        exit;
    }

    // Check for duplicate (case-insensitive)
    $dup = $pdo->prepare("SELECT id, status FROM vehicle_types WHERE LOWER(vehicle_name) = LOWER(?)");
    $dup->execute([$vehicle_name]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $msg = $existing['status'] === 'pending'
            ? 'This vehicle is already pending approval.'
            : 'This vehicle already exists in the list.';
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }

    // Get next sort_order
    $maxSort = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM vehicle_types")->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO vehicle_types (category, vehicle_name, sort_order, status, submitted_by)
        VALUES (?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([$category, $vehicle_name, $maxSort + 1, $me['id']]);
    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'id'      => $newId,
        'message' => 'Vehicle type submitted for manager approval.',
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
