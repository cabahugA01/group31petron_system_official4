<?php
// Quick test of whitelist function
require_once __DIR__ . '/config/password_reset_whitelist.php';

echo "<h1>Whitelist Function Test</h1>";
echo "<style>body{font-family:Arial;padding:20px;} .pass{color:green;font-weight:bold;} .fail{color:red;font-weight:bold;}</style>";

$test_emails = [
    'yyangcabahug@gmail.com',
    'YYANGCABAHUG@GMAIL.COM',
    '  yyangcabahug@gmail.com  ',
    'pepito@gmail.com',
    'admin@example.com',
];

echo "<h2>Testing Whitelist Function:</h2>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Email</th><th>Result</th><th>Status</th></tr>";

foreach ($test_emails as $email) {
    $result = isEmailWhitelistedForPasswordReset($email);
    $status_class = $result ? 'pass' : 'fail';
    $status_text = $result ? '✓ ALLOWED' : '✗ BLOCKED';
    
    echo "<tr>";
    echo "<td><code>" . htmlspecialchars($email) . "</code></td>";
    echo "<td>" . ($result ? 'true' : 'false') . "</td>";
    echo "<td class='$status_class'>$status_text</td>";
    echo "</tr>";
}

echo "</table>";

echo "<hr>";
echo "<h2>Current Whitelist:</h2>";
echo "<pre>";
print_r(getPasswordResetWhitelist());
echo "</pre>";
?>
