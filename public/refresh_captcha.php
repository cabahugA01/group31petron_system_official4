<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Verify CSRF Token
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid or expired CSRF token'
    ]);
    exit;
}

// Generate new strong CAPTCHA
$operations = ['+', '-', 'x'];
$op = $operations[random_int(0, count($operations) - 1)];

if ($op === '+') {
    $a = random_int(5, 35);
    $b = random_int(3, 25);
    $answer = $a + $b;
    $question = "{$a} + {$b}";
} elseif ($op === '-') {
    $a = random_int(12, 45);
    $b = random_int(2, $a - 1);
    $answer = $a - $b;
    $question = "{$a} - {$b}";
} else {
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    $answer = $a * $b;
    $question = "{$a} × {$b}";
}

$_SESSION['captcha_answer'] = $answer;
$_SESSION['captcha_question'] = $question;

echo json_encode([
    'success' => true,
    'question' => $_SESSION['captcha_question']
]);
