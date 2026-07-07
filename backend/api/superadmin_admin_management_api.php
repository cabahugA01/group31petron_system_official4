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
    if (trim($_POST['action'] ?? $_GET['action'] ?? '') === 'export_admins') {
        die('Unauthorized');
    }
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

// CSRF
if ($action === 'export_admins') {
    $csrf_token = $_GET['csrf_token'] ?? '';
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
} else {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (empty($csrf_token) || $csrf_token !== ($_SESSION['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
    }
}

// Dynamic Column Detection
$user_cols = [];
try {
    $col_query = $pdo->query("SHOW COLUMNS FROM users");
    while ($col = $col_query->fetch(PDO::FETCH_ASSOC)) {
        $user_cols[] = $col['Field'];
    }
} catch (Exception $e) { /* ignore */ }
$s_phone = 'phone_number';
$s_pass  = in_array('password_hash', $user_cols) ? 'password_hash' : 'password_hash';

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
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $station_id = (int)($_POST['station_id'] ?? 0);
    // Password is ALWAYS auto-generated — SuperAdmin cannot set it manually

    // Validate
    if (empty($first_name)) { echo json_encode(['ok'=>false,'error'=>'First name is required.']); exit; }
    if (empty($last_name))  { echo json_encode(['ok'=>false,'error'=>'Last name is required.']); exit; }
    if (empty($email))      { echo json_encode(['ok'=>false,'error'=>'Email address is required.']); exit; }
    if ($station_id <= 0)   { echo json_encode(['ok'=>false,'error'=>'Please select a station.']); exit; }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok'=>false,'error'=>'Invalid email address format.']); exit;
    }

    // Build full name
    $full_name = trim($first_name . ' ' . $last_name);
    $username  = $email; // Email is the login credential
    $phone     = null;

    try {
        // Check email uniqueness - use 'id' as primary key (most common)
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1');
        $chk->execute([$email, $email]);
        if ($chk->rowCount() > 0) {
            echo json_encode(['ok'=>false,'error'=>'This email address is already in use.']); exit;
        }

        // Verify station exists
        $st = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) {
            echo json_encode(['ok'=>false,'error'=>'Selected station not found.']); exit;
        }

        // Check one-admin-per-station rule
        $admChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND station_id=? AND status='Active'");
        $admChk->execute([$station_id]);
        if ((int)$admChk->fetchColumn() > 0) {
            echo json_encode(['ok'=>false,'error'=>'This station already has an active Admin.']); exit;
        }

        // Auto-generate password
        $plain_password = generate_admin_password();
        $hashed         = password_hash($plain_password, PASSWORD_DEFAULT);

        // Build INSERT query dynamically based on available columns
        $insert_cols = ['username', 'first_name', 'last_name', 'email', $s_pass, 'role', 'station_id', 'status', 'created_at'];
        $insert_vals = [$username, $first_name, $last_name, $email, $hashed, 'admin', $station_id, 'active', date('Y-m-d H:i:s')];
        
        // Add phone_number if available
        if (in_array('phone_number', $user_cols) || in_array($s_phone, $user_cols)) {
            $insert_cols[] = $s_phone;
            $insert_vals[] = $phone;
        }
        
        // Add name column if it exists
        if (in_array('name', $user_cols)) {
            $insert_cols[] = 'name';
            $insert_vals[] = $full_name;
        }
        
        $placeholders = implode(', ', array_fill(0, count($insert_vals), '?'));
        $columns = implode(', ', $insert_cols);
        
        $ins = $pdo->prepare("INSERT INTO users ({$columns}) VALUES ({$placeholders})");
        $ins->execute($insert_vals);
        $new_id = $pdo->lastInsertId();

        // Audit log
        log_activity($pdo, $me['id'], 'Create Admin', "SuperAdmin created admin '{$full_name}' (email: {$email}) for station '{$station_name}'");

        // Send credentials via email
        $email_sent = send_admin_credentials_email($email, $full_name, $plain_password, $station_name);
        $cred_sent_msg = $email_sent ? " Credentials sent to {$email}." : " Note: email delivery failed — share credentials manually.";

        echo json_encode([
            'ok'         => true,
            'message'    => "Admin account created successfully." . $cred_sent_msg,
            'admin_id'   => $new_id,
            'cred_sent'  => !empty($cred_sent_msg),
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
    $first_name = trim($_POST['first_name']  ?? '');
    $last_name  = trim($_POST['last_name']   ?? '');
    $station_id = (int)($_POST['station_id'] ?? 0);
    
    $status_input = trim($_POST['status'] ?? 'Active');
    $status = 'Active';
    if (strcasecmp($status_input, 'inactive') === 0 || strcasecmp($status_input, 'Disabled') === 0) {
        $status = 'Disabled';
    }
    // Email is intentionally NOT accepted from POST — it cannot be changed after creation

    if ($admin_id <= 0)     { echo json_encode(['ok'=>false,'error'=>'Invalid admin ID.']); exit; }
    if (empty($first_name)) { echo json_encode(['ok'=>false,'error'=>'First name is required.']); exit; }
    if (empty($last_name))  { echo json_encode(['ok'=>false,'error'=>'Last name is required.']); exit; }
    if ($station_id <= 0)   { echo json_encode(['ok'=>false,'error'=>'Please select a station.']); exit; }

    // Build full name
    $full_name = trim($first_name . ' ' . $last_name);

    try {
        // Ensure admin exists and is actually an admin - use 'id' column
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { echo json_encode(['ok'=>false,'error'=>'Admin account not found.']); exit; }

        // Verify station
        $st = $pdo->prepare("SELECT name FROM stations WHERE id = ? LIMIT 1");
        $st->execute([$station_id]);
        $station_name = $st->fetchColumn();
        if (!$station_name) { echo json_encode(['ok'=>false,'error'=>'Selected station not found.']); exit; }

        // Check one-admin-per-station rule
        if ($status === 'Active') {
            $admChk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND station_id=? AND id != ? AND status='Active'");
            $admChk->execute([$station_id, $admin_id]);
            if ((int)$admChk->fetchColumn() > 0) {
                echo json_encode(['ok'=>false,'error'=>'This station already has an active Admin.']); exit;
            }
        }

        // Update — email is excluded from update (fixed after creation)
        // Build UPDATE query dynamically based on available columns
        $update_cols = ['first_name = ?', 'last_name = ?', 'station_id = ?', 'status = ?'];
        $update_vals = [$first_name, $last_name, $station_id, $status];
        
        // Add name column if it exists
        if (in_array('name', $user_cols)) {
            $update_cols[] = 'name = ?';
            $update_vals[] = $full_name;
        }
        
        $update_vals[] = $admin_id; // WHERE clause parameter
        
        $upd = $pdo->prepare("UPDATE users SET " . implode(', ', $update_cols) . " WHERE id = ?");
        $upd->execute($update_vals);

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
        $chk = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) AS name FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $adm = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$adm) { echo json_encode(['ok'=>false,'error'=>'Admin not found.']); exit; }

        $pdo->prepare("UPDATE users SET status = 'Disabled' WHERE id = ?")->execute([$admin_id]);
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
        $chk = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) AS name FROM users WHERE id = ? AND LOWER(role) IN ('admin','station admin','station_admin') LIMIT 1");
        $chk->execute([$admin_id]);
        $adm = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$adm) { echo json_encode(['ok'=>false,'error'=>'Admin not found.']); exit; }

        $pdo->prepare("UPDATE users SET status = 'Active' WHERE id = ?")->execute([$admin_id]);
        log_activity($pdo, $me['id'], 'Activate Admin', "SuperAdmin activated admin '{$adm['name']}' (ID {$admin_id})");

        echo json_encode(['ok'=>true,'message'=>"Admin '{$adm['name']}' has been activated."]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: add_station
// ════════════════════════════════════════════════════════════
if ($action === 'add_station') {
    $station_name = trim($_POST['station_name'] ?? '');
    $location     = trim($_POST['location'] ?? '');
    $region       = trim($_POST['region'] ?? '');
    $contact      = trim($_POST['contact'] ?? '');

    // Validate
    if (empty($station_name)) { echo json_encode(['ok'=>false,'error'=>'Station name is required.']); exit; }
    if (empty($location))     { echo json_encode(['ok'=>false,'error'=>'Location is required.']); exit; }

    try {
        // Check if station name already exists
        $chk = $pdo->prepare('SELECT id FROM stations WHERE name = ? LIMIT 1');
        $chk->execute([$station_name]);
        if ($chk->rowCount() > 0) {
            echo json_encode(['ok'=>false,'error'=>'A station with this name already exists.']); exit;
        }

        // Insert new station
        $ins = $pdo->prepare(
            "INSERT INTO stations (name, location, address, region, contact_number, status, created_at) 
             VALUES (?, ?, ?, ?, ?, 'active', NOW())"
        );
        $ins->execute([$station_name, $location, $location, $region, $contact]);
        $new_id = $pdo->lastInsertId();

        // Audit log
        log_activity($pdo, $me['id'], 'Create Station', "SuperAdmin created station '{$station_name}' (ID {$new_id})");

        echo json_encode([
            'ok'         => true,
            'message'    => "Station '{$station_name}' has been created successfully.",
            'station_id' => $new_id,
        ]);

    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════
// ACTION: export_admins
// ════════════════════════════════════════════════════════════
if ($action === 'export_admins') {
    try {
        $stmt = $pdo->query(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.status, u.station_id,
                    s.name AS station_name, s.address AS station_address, s.region AS station_region, s.contact_number AS station_contact,
                    (SELECT MAX(created_at) FROM activity_logs WHERE user_id = u.id AND action = 'Login') AS last_login
             FROM users u
             LEFT JOIN stations s ON s.id = u.station_id
             WHERE u.role = 'admin'
             ORDER BY u.first_name, u.last_name"
        );
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="admin_list_and_coverage_' . date('Ymd_His') . '.csv"');
        
        $out = fopen('php://output', 'w');
        // BOM for Excel compatibility with UTF-8
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($out, [
            'Admin ID',
            'First Name',
            'Last Name',
            'Email',
            'Status',
            'Station ID',
            'Station Name',
            'Station Address',
            'Region',
            'Station Contact',
            'Last Login'
        ]);

        foreach ($data as $r) {
            fputcsv($out, [
                $r['id'],
                $r['first_name'],
                $r['last_name'],
                $r['email'],
                $r['status'],
                $r['station_id'] ?? '',
                $r['station_name'] ?? 'Unassigned',
                $r['station_address'] ?? '—',
                $r['station_region'] ?? '—',
                $r['station_contact'] ?? '—',
                $r['last_login'] ? date('Y-m-d H:i:s', strtotime($r['last_login'])) : 'Never'
            ]);
        }
        fclose($out);
        log_activity($pdo, $me['id'], 'Export Admins', "SuperAdmin exported admin list and station coverage CSV");
        exit;
    } catch (Exception $e) {
        die("Export failed: " . $e->getMessage());
    }
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
