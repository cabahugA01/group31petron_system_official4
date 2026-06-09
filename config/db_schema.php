<?php
/**
 * db_schema.php — Configures the standard column names for the users table.
 */
if (!isset($GLOBALS['_schema_detected'])) {
    $GLOBALS['DB_UID']           = 'id';
    $GLOBALS['DB_PHONE']         = 'phone_number';
    $GLOBALS['DB_PASS']          = 'password_hash';
    $GLOBALS['DB_STATUS_ACTIVE'] = 'Active';
    $GLOBALS['_schema_detected'] = true;
}

$DB_UID           = $GLOBALS['DB_UID'];
$DB_PHONE         = $GLOBALS['DB_PHONE'];
$DB_PASS          = $GLOBALS['DB_PASS'];
$DB_STATUS_ACTIVE = $GLOBALS['DB_STATUS_ACTIVE'];
