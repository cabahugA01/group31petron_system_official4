<?php
/**
 * Session Keepalive API Endpoint
 * Called when a user interacts or clicks "Stay Logged In" to extend their active session.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../backend/lib.php';
require_login();

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user'])) {
    $_SESSION['last_activity'] = time();
    echo json_encode([
        'success'   => true,
        'timestamp' => time(),
        'message'   => 'Session extended successfully'
    ]);
} else {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'No active session'
    ]);
}
