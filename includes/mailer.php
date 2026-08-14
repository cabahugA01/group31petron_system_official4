<?php
/**
 * Petron Station Management System
 * Central Mailer — includes/mailer.php
 *
 * Sends OTP emails using PHPMailer + SMTP.
 * No local/test fallback. If SMTP fails, the function returns false.
 *
 * Requirements:
 *  - PHPMailer installed at includes/PHPMailer/
 *  - SMTP credentials configured in config/mail.php
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';

/**
 * Load SMTP config from config/mail.php
 * Falls back to email_config.php globals if needed.
 */
function getMailConfig(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $path = __DIR__ . '/../config/mail.php';
    if (file_exists($path)) {
        $cfg = require $path;
    } else {
        // Legacy fallback — read from $email_config global
        global $email_config;
        $cfg = [
            'host'       => $email_config['host']       ?? 'smtp.gmail.com',
            'port'       => $email_config['port']       ?? 587,
            'encryption' => $email_config['encryption'] ?? 'tls',
            'username'   => $email_config['username']   ?? '',
            'password'   => $email_config['password_hash'] ?? '',
            'from_email' => $email_config['from_email'] ?? '',
            'from_name'  => $email_config['from_name']  ?? 'Petron System',
        ];
    }
    return $cfg;
}

/**
 * Build the OTP email HTML body.
 */
function buildOtpEmailHtml(string $otp): string {
    $logo_path = __DIR__ . '/../assets/img/Petron Logo.png';
    $logo_cid  = 'petron_logo_cid';
    $logo_html = file_exists($logo_path)
        ? "<img src='cid:{$logo_cid}' alt='Petron' style='height:70px;display:block;margin:0 auto 12px;' />"
        : "<div style='font-size:28px;font-weight:900;color:#fff;text-align:center;margin-bottom:8px;'>PETRON</div>";

    return "
<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;'>
  <div style='background:linear-gradient(135deg,#002F6C 0%,#004a9e 100%);padding:36px 20px 28px;text-align:center;'>
    {$logo_html}
    <h1 style='margin:0;font-size:20px;font-weight:700;color:#fff;letter-spacing:0.5px;'>Station Management System</h1>
  </div>
  <div style='padding:40px 30px;background:#fff;'>
    <h2 style='color:#002F6C;margin-top:0;font-size:20px;'>Password Reset Request</h2>
    <p style='color:#333;line-height:1.7;'>Hello,</p>
    <p style='color:#333;line-height:1.7;'>
      You requested to reset your password for the <strong>Petron Station Management System</strong>.
      Use the 6-digit OTP below to proceed. This OTP will expire in <strong>5 minutes</strong>.
    </p>
    <div style='background:#f8f9fa;padding:28px;border-radius:8px;margin:28px 0;border-left:5px solid #002F6C;text-align:center;'>
      <div style='color:#002F6C;font-size:12px;font-weight:700;margin-bottom:10px;text-transform:uppercase;letter-spacing:1px;'>Your One-Time Password</div>
      <span style='font-size:44px;font-weight:900;letter-spacing:12px;color:#002F6C;font-family:monospace;'>{$otp}</span>
    </div>
    <div style='background:#fff3cd;border-left:4px solid #ffc107;padding:14px 16px;border-radius:5px;margin:20px 0;'>
      <p style='margin:0;color:#856404;font-weight:700;font-size:13px;'>
        ⚠ Do NOT share this OTP with anyone. Petron staff will never ask for your OTP.
      </p>
    </div>
    <p style='color:#6c757d;font-size:13px;line-height:1.6;'>
      If you did not request a password reset, please ignore this email. Your password will remain unchanged.
    </p>
  </div>
  <div style='background:#002F6C;color:rgba(255,255,255,.8);padding:20px;text-align:center;font-size:12px;'>
    <p style='margin:0 0 4px;'>This is an automated message. Do not reply to this email.</p>
    <p style='margin:0;color:#fff;font-weight:700;'>&copy; 2026 Petron Station &amp; Service Center Management System</p>
  </div>
</div>";
}

/**
 * Send a password reset OTP to a registered email address.
 *
 * @param string $to_email  The user's registered email
 * @param string $otp       The plaintext 6-digit OTP (NEVER stored; only sent)
 * @return bool             true if email was accepted by SMTP, false otherwise
 */
function sendOtpEmail(string $to_email, string $otp): bool {
    $cfg = getMailConfig();

    // Abort if SMTP password is not configured
    if (empty($cfg['password'])) {
        error_log('[Mailer] SMTP password is not configured in config/mail.php');
        return false;
    }

    $to_email = filter_var(trim($to_email), FILTER_VALIDATE_EMAIL);
    if (!$to_email) {
        error_log('[Mailer] Invalid recipient email address');
        return false;
    }

    $logo_path = __DIR__ . '/../assets/img/Petron Logo.png';
    $html_body = buildOtpEmailHtml($otp);
    $alt_body  = "Your Petron password reset OTP is: {$otp}\n\nThis OTP expires in 5 minutes.\n\nDo NOT share this code with anyone.\n\n-- Petron Station Management System";

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host        = $cfg['host'];
        $mail->Port        = (int)$cfg['port'];
        $mail->SMTPAuth    = true;
        $mail->Username    = $cfg['username'];
        $mail->Password    = $cfg['password'];
        $mail->Timeout     = 20;
        $mail->CharSet     = 'UTF-8';
        $mail->Encoding    = 'base64';

        // Encryption
        $enc = strtolower($cfg['encryption'] ?? 'tls');
        if ($enc === 'ssl' || $enc === 'smtps') {
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_SMTPS;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure  = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
        }

        // Skip SSL cert verification for local dev (self-signed)
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];

        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to_email);
        $mail->addReplyTo($cfg['from_email'], $cfg['from_name']);

        // Embed logo if it exists
        if (file_exists($logo_path)) {
            $mail->addEmbeddedImage($logo_path, 'petron_logo_cid', 'Petron Logo.png');
        }

        $mail->Subject = 'Petron Station Management System - Password Reset OTP';
        $mail->isHTML(true);
        $mail->Body    = $html_body;
        $mail->AltBody = $alt_body;

        $mail->send();
        error_log("[Mailer] OTP email sent successfully to: {$to_email}");
        return true;

    } catch (PHPMailerException $e) {
        error_log("[Mailer] PHPMailer error for {$to_email}: " . $e->getMessage());
        return false;
    } catch (\Exception $e) {
        error_log("[Mailer] Unexpected error for {$to_email}: " . $e->getMessage());
        return false;
    }
}
