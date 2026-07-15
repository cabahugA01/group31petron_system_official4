<?php
/**
 * PASSWORD RESET CONFIGURATION
 * 
 * Whitelist restriction has been disabled to allow all active users
 * to reset their passwords via the forgot password flow.
 * 
 * Security is enforced by:
 * - Checking user status = 'active'
 * - Requiring a valid registered email address on the account
 * - OTP expiry (5 minutes) and single-use enforcement
 * 
 * Last Updated: 2026-07-13
 */

// Whitelist array kept for reference but not actively enforced
$password_reset_whitelist = [];

if (!function_exists('normalizePasswordResetIdentifier')) {
function normalizePasswordResetIdentifier($value) {
    $value = trim((string)$value);
    return preg_replace('/[\r\n\t]+/', '', $value);
}
}

if (!function_exists('normalizePasswordResetEmail')) {
function normalizePasswordResetEmail($email) {
    return strtolower(preg_replace('/\s+/', '', trim((string)$email)));
}
}

if (!function_exists('ensurePasswordResetTokensTable')) {
function ensurePasswordResetTokensTable(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
            `id`         INT(11)     NOT NULL AUTO_INCREMENT,
            `user_id`    INT(11)     NOT NULL,
            `token`      VARCHAR(10) NOT NULL,
            `token_type` VARCHAR(20) NOT NULL DEFAULT 'reset',
            `expires_at` DATETIME    NOT NULL,
            `used_at`    DATETIME    DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `is_used`    TINYINT(1)  NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_reset_user_type` (`user_id`, `token_type`, `is_used`),
            INDEX `idx_reset_token_type` (`token`, `token_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
}

if (!function_exists('cleanPasswordResetEmails')) {
function cleanPasswordResetEmails(PDO $pdo) {
    $pdo->exec("UPDATE users SET email = TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), '')) WHERE email IS NOT NULL");
}
}

if (!function_exists('findActivePasswordResetUser')) {
function findActivePasswordResetUser(PDO $pdo, $identifier) {
    $identifier = normalizePasswordResetIdentifier($identifier);
    if ($identifier === '') {
        return null;
    }

    $needle = strtolower($identifier);
    $stmt = $pdo->prepare("
        SELECT id AS user_id,
               username,
               TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), '')) AS email,
               role,
               status
        FROM users
        WHERE LOWER(TRIM(status)) = 'active'
          AND (
                LOWER(TRIM(username)) = ?
             OR LOWER(TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), ''))) = ?
          )
        ORDER BY CASE
            WHEN LOWER(TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), ''))) = ? THEN 0
            ELSE 1
        END
        LIMIT 1
    ");
    $stmt->execute([$needle, $needle, $needle]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $user['email'] = normalizePasswordResetEmail($user['email'] ?? '');
    return $user;
}
}

if (!function_exists('findActivePasswordResetUserByEmail')) {
function findActivePasswordResetUserByEmail(PDO $pdo, $email) {
    $email = normalizePasswordResetEmail($email);
    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id AS user_id,
               username,
               TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), '')) AS email,
               role,
               status
        FROM users
        WHERE LOWER(TRIM(status)) = 'active'
          AND LOWER(TRIM(REPLACE(REPLACE(email, CHAR(13), ''), CHAR(10), ''))) = ?
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $user['email'] = normalizePasswordResetEmail($user['email'] ?? '');
    return $user;
}
}

/**
 * Check if an email is allowed for password reset.
 * Returns true for all emails — access is controlled by role/status checks.
 * 
 * @param string $email Email address to check
 * @return bool Always true (whitelist restriction disabled)
 */
function isEmailWhitelistedForPasswordReset($email) {
    // Whitelist restriction disabled. All active users with valid emails may reset their password.
    // Status validation is handled in the forgot password flow.
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
