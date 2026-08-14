<?php
/**
 * Petron Station Management System
 * SMTP Mail Configuration
 * -------------------------------------------------------
 * To enable email delivery, fill in your SMTP credentials.
 *
 * GMAIL SETUP:
 *  1. Enable 2-Step Verification on your Google account:
 *     https://myaccount.google.com/security
 *  2. Generate an App Password (16 characters, no spaces):
 *     https://myaccount.google.com/apppasswords
 *  3. Set SMTP_USER and SMTP_PASSWORD below.
 *
 * Never expose this file to the public.
 */

return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',              // 'tls' for STARTTLS (port 587) | 'ssl' for port 465
    'username'   => 'cabahug.amiedamas@gmail.com',   // Gmail address used to send OTP
    'password'   => 'wrfuplwbuxgyzfkq', // Google App Password (16 chars)
    'from_email' => 'cabahug.amiedamas@gmail.com',
    'from_name'  => 'Petron Station Management System',
];
