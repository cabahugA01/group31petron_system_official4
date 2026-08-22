<?php
// ── Session Hardening: Strict Cookie Params before session_start() ──
if (session_status() === PHP_SESSION_NONE) {
    $is_secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_secure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Ensure a CSRF token exists in session (auto-healing for expired/idle sessions)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Generate fresh Math CAPTCHA (Addition only)
$a = random_int(5, 35);
$b = random_int(3, 25);
$answer = $a + $b;
$question = "{$a} + {$b}";

$_SESSION['captcha_answer']   = $answer;
$_SESSION['captcha_question'] = $question;

echo json_encode([
    'success'    => true,
    'question'   => $question,
    'csrf_token' => $_SESSION['csrf_token']
]);
exit;
