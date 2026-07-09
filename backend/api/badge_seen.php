<?php
/**
 * badge_seen.php — Mark a sidebar module as "seen" by the current user.
 * Called via fetch() from JS when user lands on a module page.
 * Stores: user_preferences.preference_key = 'badge_seen_{module}', value = current UTC timestamp.
 */

session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Must be logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthenticated']);
    exit;
}

$raw    = file_get_contents('php://input');
$data   = json_decode($raw, true);
$module = trim($data['module'] ?? '');

// Validate module key — alphanumeric + underscores only
if (!preg_match('/^[a-z0-9_]{2,60}$/', $module)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid module key']);
    exit;
}

require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$user_id  = (int)$_SESSION['user_id'];
$pref_key = 'badge_seen_' . $module;
$ts       = gmdate('Y-m-d H:i:s'); // UTC timestamp

try {
    // UPSERT: insert or update using NOW()
    $stmt = $pdo->prepare("
        INSERT INTO user_preferences (user_id, preference_key, preference_value)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE preference_value = NOW(), updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$user_id, $pref_key]);

    // Fetch the updated timestamp to return in response
    $stmt_get = $pdo->prepare("SELECT preference_value FROM user_preferences WHERE user_id = ? AND preference_key = ?");
    $stmt_get->execute([$user_id, $pref_key]);
    $db_ts = $stmt_get->fetchColumn() ?: date('Y-m-d H:i:s');

    echo json_encode(['ok' => true, 'module' => $module, 'ts' => $db_ts]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB error']);
}
