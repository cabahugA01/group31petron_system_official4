<?php
/**
 * PASSWORD RESET EMAIL WHITELIST CONFIGURATION
 * 
 * This file contains the list of email addresses that are allowed
 * to receive password reset OTP emails.
 * 
 * Security Note:
 * - Only emails listed here can request password reset
 * - This prevents unauthorized password reset attempts
 * - Add or remove emails as needed for your security policy
 * 
 * Last Updated: 2026-07-12
 */

// List of allowed email addresses for password reset
$password_reset_whitelist = [
    'yyangcabahug@gmail.com',
    
    // Add more whitelisted emails below (one per line)
    // Example:
    // 'admin@example.com',
    // 'manager@example.com',
    // 'staff@example.com',
];

/**
 * Check if an email is whitelisted for password reset
 * 
 * @param string $email Email address to check
 * @return bool True if email is whitelisted, false otherwise
 */
function isEmailWhitelistedForPasswordReset($email) {
    global $password_reset_whitelist;
    
    // Normalize email (trim and lowercase)
    $normalized_email = strtolower(trim($email));
    
    // Check if email is in whitelist
    foreach ($password_reset_whitelist as $whitelisted_email) {
        if (strtolower(trim($whitelisted_email)) === $normalized_email) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get all whitelisted emails
 * 
 * @return array List of whitelisted email addresses
 */
function getPasswordResetWhitelist() {
    global $password_reset_whitelist;
    return $password_reset_whitelist;
}
