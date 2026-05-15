<?php

// ============================================================
// SuperAdmin – Admin Management API
// backend/api/superadmin_admin_management_api.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

// Auth
require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

// CSRF
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
}

$action = trim($_POST['action'] ?? '');

// ── Helper: send credentials email ───────────────────────────
function send_admin_credentials_email(string $to_email, string $name, string $password, string $station_name): bool {
    // Uses PHP mail() — configure SMTP in php.ini for Gmail relay
    $subject = 'Your Petron Station Admin Account Credentials';
    $body    = "Dear {$name},\r\n\r\n"
             . "Your Admin account has been created for Petron Station Management System.\r\n\r\n"
             . "Station : {$station_name}\r\n"
             . "Email   : {$to_email}\r\n"
             . "Password: {$password}\r\n\r\n"
             . "IMPORTANT: You are required to change your password upon first login.\r\n\r\n"
             . "Login at: " . (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . '/group31petron_system_official4/public/index.php' : 'your system URL') . "\r\n\r\n"
             . "This is an automated message. Do not reply.\r\n"
             . "Petron Station Management System";
    $headers = "From: noreply@petron-sms.com\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to_email, $subject, $body, $headers);
}

// ── Helper: auto-generate password ───────────────────────────
function generate_admin_password(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#!';
    $pass  = '';
    for ($i = 0; $i < 10; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

// ════════════════════════════════════════════════════════════
// ACTION: create_admin
// ════════════════════════════════════════════════════════════
if ($action === 'create_admin') {
    $full_name  = trim($_POST['full_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $station_id = (int)($_POST['station_id'] ?? 0);
    // Password is ALWAYS auto-generated — SuperAdmin cannot set it manually
    // (any submitted password field is intentionally ignored)

    // Validate
    if (empty($full_name))  { echo json_encode(['ok'=>false,'error'=>'Full name is required.']); exit; }
    if (empty($email))      { echo json_encode(['ok'=>false,'error'=>'Email address is required.']); exit; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'error'=>'Invalid email address.']); exit; }
    if ($station_id <= 0)   { echo json_encode(['ok'=>false,'error'=>'Please select a station.']); exit; }

    try {
        // Check email uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->rowCount() > 0) {
            echo json_encode(['ok'=>false,'error'=>'Email address is already in use.']); exit;
        }

        // Verify station exists
        $st = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) {
            echo json_encode(['ok'=>false,'error'=>'Selected station not found.']); exit;
        }

        // Always auto-generate password — SuperAdmin cannot manually set passwords
        $plain_password = generate_admin_password();
        $hashed         = password_hash($plain_password, PASSWORD_DEFAULT);

        // Derive username from email (before @)
        $username_base = strtolower(explode('@', $email)[0]);
        $username      = $username_base;
        $suffix        = 1;
        while (true) {
            $uchk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $uchk->execute([$username]);
            if ($uchk->rowCount() === 0) break;
            $username = $username_base . $suffix++;
        }

        // Insert
        $ins = $pdo->prepare(
            "INSERT INTO users (username, name, email, password, role, station_id, status, must_change_password, created_at)
             VALUES (?, ?, ?, ?, 'admin', ?, 'active', 1, NOW())"
        );
        $ins->execute([$username, $full_name, $email, $hashed, $station_id]);
        $new_id = $pdo->lastInsertId();

        // Audit log
        log_activity($pdo, $me['id'], 'Create Admin', "SuperAdmin created admin '{$full_name}' ({$email}) for station '{$station_name}'");

        // Send email
        $email_sent = send_admin_credentials_email($email, $full_name, $plain_password, $station_name);

        echo json_encode([
            'ok'         => true,
            'message'    => "Admin account created successfully." . ($email_sent ? " Credentials sent to {$email}." : " Note: email delivery failed — please share credentials manually."),
            'admin_id'   => $new_id,
            'email_sent' => $email_sent,
        ]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: edit_admin
// ════════════════════════════════════════════════════════════
if ($action === 'edit_admin') {
    $admin_id   = (int)($_POST['admin_id']   ?? 0);
    $full_name  = trim($_POST['full_name']   ?? '');
    $station_id = (int)($_POST['station_id'] ?? 0);
    $status     = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    // Email is intentionally NOT accepted from POST — it cannot be changed after creation

    if ($admin_id <= 0)    { echo json_encode(['ok'=>false,'error'=>'Invalid admin ID.']); exit; }
    if (empty($full_name)) { echo json_encode(['ok'=>false,'error'=>'Full name is required.']); exit; }
    if ($station_id <= 0)  { echo json_encode(['ok'=>false,'error'=>'Please select a station.']); exit; }

    try {
        // Ensure admin exists and is actually an admin
        $chk = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { echo json_encode(['ok'=>false,'error'=>'Admin account not found.']); exit; }

        // Verify station
        $st = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) { echo json_encode(['ok'=>false,'error'=>'Selected station not found.']); exit; }

        // Update — email is excluded from update (fixed after creation)
        $upd = $pdo->prepare("UPDATE users SET name=?, station_id=?, status=? WHERE id=?");
        $upd->execute([$full_name, $station_id, $status, $admin_id]);

        log_activity($pdo, $me['id'], 'Edit Admin', "SuperAdmin updated admin ID {$admin_id} ('{$full_name}') — station: '{$station_name}', status: {$status}");

        echo json_encode(['ok'=>true,'message'=>'Admin account updated successfully.']);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: deactivate_admin
// ════════════════════════════════════════════════════════════
if ($action === 'deactivate_admin') {
    $admin_id = (int)($_POST['admin_id'] ?? 0);
    if ($admin_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid admin ID.']); exit; }

    try {
        $chk = $pdo->prepare("SELECT name FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $adm = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$adm) { echo json_encode(['ok'=>false,'error'=>'Admin not found.']); exit; }

        $pdo->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$admin_id]);
        log_activity($pdo, $me['id'], 'Deactivate Admin', "SuperAdmin deactivated admin '{$adm['name']}' (ID {$admin_id})");

        echo json_encode(['ok'=>true,'message'=>"Admin '{$adm['name']}' has been deactivated."]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: activate_admin
// ════════════════════════════════════════════════════════════
if ($action === 'activate_admin') {
    $admin_id = (int)($_POST['admin_id'] ?? 0);
    if ($admin_id <= 0) { echo json_encode(['ok'=>false,'error'=>'Invalid admin ID.']); exit; }

    try {
        $chk = $pdo->prepare("SELECT name FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $adm = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$adm) { echo json_encode(['ok'=>false,'error'=>'Admin not found.']); exit; }

        $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$admin_id]);
        log_activity($pdo, $me['id'], 'Activate Admin', "SuperAdmin activated admin '{$adm['name']}' (ID {$admin_id})");

        echo json_encode(['ok'=>true,'message'=>"Admin '{$adm['name']}' has been activated."]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
