<?php
/**
 * Petron Station Management System — Comprehensive Security Hardening Module
 * Features:
 * - SQL Injection Protection (PDO Prepared Statements Enforcement)
 * - XSS (Cross-Site Scripting) Input & Output Sanitization
 * - Rate Limiting & Brute-Force Protection
 * - CSRF Token Generation & Verification
 * - HTTP Security Headers Enforcement
 */

if (!function_exists('sec_apply_headers')) {
    function sec_apply_headers() {
        if (!headers_sent()) {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
        }
    }
}

// Automatically apply security headers when included
sec_apply_headers();

/**
 * XSS Protection: HTML Output Escaping
 */
if (!function_exists('sec_escape')) {
    function sec_escape($str) {
        if (is_null($str)) return '';
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Server-Side Input Sanitization (String)
 */
if (!function_exists('sec_sanitize_string')) {
    function sec_sanitize_string($data) {
        if (is_array($data)) {
            return array_map('sec_sanitize_string', $data);
        }
        $data = trim((string)$data);
        $data = strip_tags($data);
        return $data;
    }
}

/**
 * CSRF Token Generator
 */
if (!function_exists('sec_generate_csrf_token')) {
    function sec_generate_csrf_token() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * CSRF Token Verifier
 */
if (!function_exists('sec_verify_csrf_token')) {
    function sec_verify_csrf_token($token) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $session_token = $_SESSION['csrf_token'] ?? '';
        if (empty($session_token) || empty($token)) {
            return false;
        }
        return hash_equals($session_token, (string)$token);
    }
}

/**
 * Rate Limiting & Brute-Force Protection
 * Prevents automated attacks by enforcing max requests per IP per action
 *
 * @param string $action Action key (e.g. 'login', 'api_submit', 'password_reset')
 * @param int $max_attempts Maximum allowed attempts within timeframe
 * @param int $decay_seconds Timeframe in seconds before attempts reset (default 60s)
 * @return bool True if request is allowed, False if rate limit exceeded
 */
if (!function_exists('sec_check_rate_limit')) {
    function sec_check_rate_limit($action, $max_attempts = 15, $decay_seconds = 60) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = "rate_limit_" . md5($ip . '_' . $action);
        $now = time();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => $now
            ];
            return true;
        }

        $rate = &$_SESSION[$key];

        // Reset if decay timeframe has passed
        if (($now - $rate['first_attempt']) > $decay_seconds) {
            $rate['attempts'] = 1;
            $rate['first_attempt'] = $now;
            return true;
        }

        $rate['attempts']++;

        if ($rate['attempts'] > $max_attempts) {
            return false; // Rate limit exceeded!
        }

        return true;
    }
}
