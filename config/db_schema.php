<?php
/**
 * db_schema.php — Auto-detects actual column names in the users table.
 * Works whether database uses old schema (id, phone, password)
 * or new schema (user_id, phone_number, password_hash).
 *
 * Usage: require_once __DIR__ . '/../config/db_schema.php';
 * Then use: $DB_UID, $DB_PHONE, $DB_PASS, $DB_STATUS_ACTIVE
 */

if (!isset($pdo)) return;

if (!isset($GLOBALS['_schema_detected'])) {
    try {
        $cols = array_column(
            $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC),
            'Field'
        );

        // Primary key column
        $GLOBALS['DB_UID']   = in_array('user_id',     $cols) ? 'user_id'     : 'id';
        // Phone column
        $GLOBALS['DB_PHONE'] = in_array('phone_number', $cols) ? 'phone_number' : 'phone';
        // Password column
        $GLOBALS['DB_PASS']  = in_array('password_hash', $cols) ? 'password_hash' : 'password';
        // Status value for active users
        $row = $pdo->query("SELECT DISTINCT status FROM users LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $GLOBALS['DB_STATUS_ACTIVE'] = in_array('Active', $row) ? 'Active' : 'active';

        $GLOBALS['_schema_detected'] = true;
    } catch (Exception $e) {
        // Fallback to old schema if detection fails
        $GLOBALS['DB_UID']           = 'id';
        $GLOBALS['DB_PHONE']         = 'phone';
        $GLOBALS['DB_PASS']          = 'password';
        $GLOBALS['DB_STATUS_ACTIVE'] = 'active';
        $GLOBALS['_schema_detected'] = true;
    }
}

$DB_UID           = $GLOBALS['DB_UID'];
$DB_PHONE         = $GLOBALS['DB_PHONE'];
$DB_PASS          = $GLOBALS['DB_PASS'];
$DB_STATUS_ACTIVE = $GLOBALS['DB_STATUS_ACTIVE'];
