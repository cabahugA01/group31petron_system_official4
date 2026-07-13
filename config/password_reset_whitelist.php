<?php
/**
 * PASSWORD RESET CONFIGURATION
 * 
 * Whitelist restriction has been disabled to allow all active users
 * to reset their passwords via the forgot password flow.
 * 
 * Security is enforced by:
 * - Checking user role (staff, manager, admin, developer, superadmin)
 * - Checking user status = 'active'
 * - Requiring a valid registered email address on the account
 * - OTP expiry (5 minutes) and single-use enforcement
 * 
 * Last Updated: 2026-07-13
 */

// Whitelist array kept for reference but not actively enforced
$password_reset_whitelist = [];

/**
 * Check if an email is allowed for password reset.
 * Returns true for all emails — access is controlled by role/status checks.
 * 
 * @param string $email Email address to check
 * @return bool Always true (whitelist restriction disabled)
 */
function isEmailWhitelistedForPasswordReset($email) {
    // Whitelist restriction disabled — all active users may reset their password.
    // Role and status validation is handled in forgot_password.php.
    return true;
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
