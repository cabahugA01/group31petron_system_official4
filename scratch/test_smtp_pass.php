<?php
require_once __DIR__ . '/../config/email_config.php';

$passwords = ['ojgyravyufedqgfl', 'ojgy ravy ufed qgfl'];
$transports = [
    ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'label' => 'Primary TLS 587'],
    ['host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'label' => 'Gmail SSL 465']
];

foreach ($passwords as $pwd) {
    foreach ($transports as $t) {
        echo "Testing pwd: '$pwd' | Transport: {$t['label']}...\n";
        $cfg = $email_config;
        $cfg['password_hash'] = $pwd;
        $mail = buildOtpMailer($cfg, $t, 'Test Subject', 'Test Body', 'Test Alt', '');
        $mail->addAddress('amda.cabahug.coc@phinmaed.com');
        $mail->SMTPDebug = 0;
        try {
            $sent = $mail->send();
            if ($sent) {
                echo "--> SUCCESS! Working Password: '$pwd' | Transport: {$t['label']}\n";
                exit;
            } else {
                echo "--> Failed: " . $mail->ErrorInfo . "\n";
            }
        } catch (Exception $e) {
            echo "--> Error: " . $e->getMessage() . "\n";
        }
    }
}
