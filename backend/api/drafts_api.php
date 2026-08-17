<?php
/**
 * Global Drafts API
 * backend/api/drafts_api.php
 *
 * Provides REST endpoints for auto-saving, retrieving, and clearing form drafts.
 * Zero business effects: saving/retrieving drafts never touches inventory, sales, or ledger.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required', 'timeout' => true]);
    exit;
}

$user       = $_SESSION['user'];
$userId     = (int)($user['id'] ?? $user['user_id'] ?? 0);
$stationId  = (int)($user['station_id'] ?? 0);
$action     = trim($_GET['action'] ?? $_POST['action'] ?? 'get');
$method     = $_SERVER['REQUEST_METHOD'];

// Parse JSON body if applicable
$inputJson = [];
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $inputJson = $decoded;
    }
}

// ─────────────────────────────────────────────────────────────
// 1. GET DRAFT: ?action=get&module=XYZ
// ─────────────────────────────────────────────────────────────
if ($action === 'get') {
    $moduleKey = trim($_GET['module'] ?? $inputJson['module'] ?? '');
    if (empty($moduleKey)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing module parameter']);
        exit;
    }

    $draft = get_user_draft($pdo, $userId, $moduleKey);
    if ($draft) {
        echo json_encode([
            'ok' => true,
            'has_draft' => true,
            'draft' => [
                'id' => (int)$draft['id'],
                'module_key' => $draft['module_key'],
                'data' => $draft['data'],
                'created_at' => $draft['created_at'],
                'updated_at' => $draft['updated_at'],
                'formatted_time' => date('M j, Y g:i A', strtotime($draft['updated_at'])),
                'time_ago' => time_ago_draft($draft['updated_at'])
            ]
        ]);
    } else {
        echo json_encode(['ok' => true, 'has_draft' => false, 'draft' => null]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 2. SAVE DRAFT: POST ?action=save (body: {module, form_data})
// ─────────────────────────────────────────────────────────────
if ($action === 'save') {
    $moduleKey = trim($_POST['module'] ?? $inputJson['module'] ?? '');
    $formData  = $_POST['form_data'] ?? $inputJson['form_data'] ?? null;

    if (is_string($formData)) {
        $formData = json_decode($formData, true);
    }

    if (empty($moduleKey) || !is_array($formData) || empty($formData)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid module or empty form data']);
        exit;
    }

    $saved = save_user_draft($pdo, $userId, $stationId, $moduleKey, $formData);
    if ($saved) {
        echo json_encode([
            'ok' => true,
            'message' => 'Draft saved successfully',
            'module_key' => $moduleKey,
            'updated_at' => date('Y-m-d H:i:s'),
            'formatted_time' => date('g:i A')
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to save draft']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// 3. DISCARD DRAFT: POST ?action=discard (body: {module})
// ─────────────────────────────────────────────────────────────
if ($action === 'discard') {
    $moduleKey = trim($_POST['module'] ?? $inputJson['module'] ?? '');
    if (empty($moduleKey)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing module parameter']);
        exit;
    }

    discard_user_draft($pdo, $userId, $moduleKey);
    echo json_encode(['ok' => true, 'message' => 'Draft discarded successfully', 'module_key' => $moduleKey]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// 4. CLEAR DRAFT: POST ?action=clear (after official submit)
// ─────────────────────────────────────────────────────────────
if ($action === 'clear') {
    $moduleKey = trim($_POST['module'] ?? $inputJson['module'] ?? '');
    if (empty($moduleKey)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing module parameter']);
        exit;
    }

    clear_user_draft($pdo, $userId, $moduleKey);
    echo json_encode(['ok' => true, 'message' => 'Draft cleared on final submit', 'module_key' => $moduleKey]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);

function time_ago_draft($datetime) {
    $ts = strtotime((string)$datetime);
    if (!$ts) return 'recently';
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    return date('M j, Y g:i A', $ts);
}
