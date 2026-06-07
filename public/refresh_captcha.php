<?php
session_start();

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Generate new CAPTCHA
$captcha_a = random_int(1, 12);
$captcha_b = random_int(1, 12);

$_SESSION['captcha_answer'] = $captcha_a + $captcha_b;
$_SESSION['captcha_question'] = "{$captcha_a} + {$captcha_b}";

echo json_encode([
    'success' => true,
    'question' => $_SESSION['captcha_question']
]);
?>
