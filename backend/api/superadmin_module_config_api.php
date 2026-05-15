<?php
// ============================================================
// SuperAdmin – Module Configuration API
// backend/api/superadmin_module_config_api.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

$get_action = trim($_GET['action'] ?? '');

// Export is a GET action — handle before JSON header
if ($get_action === 'export_audit') {
    require_login();
    $me   = current_user();
    $role = role_key($me['role'] ?? '');
    if (!in_array($role, ['superadmin', 'developer'])) { http_response_code(403); exit; }
    $csrf = $_GET['csrf_token'] ?? '';
    if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) { http_response_code(403); exit; }

    $rows = $pdo->query(
        "SELECT a.timestamp, a.module_key, a.config_key, a.action_type,
                a.old_value, a.new_value, a.changed_by_role, a.ip_address,
                u.name AS user_name
         FROM module_config_audit a
         LEFT JOIN users u ON u.id = a.changed_by
         ORDER BY a.timestamp DESC LIMIT 1000"
    )->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="module_config_audit_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp','Module','Setting','Action','Old Value','New Value','Role','IP','Changed By']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['timestamp'],$r['module_key'],$r['config_key']??'',$r['action_type'],$r['old_value']??'',$r['new_value']??'',$r['changed_by_role'],$r['ip_address'],$r['user_name']??'System']);
    }
    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit;
}

// ── GET actions (no CSRF needed for reads) ────────────────────
if ($get_action === 'get_audit') {
    try {
        $rows = $pdo->query(
            "SELECT a.timestamp, a.module_key, a.config_key, a.action_type,
                    a.old_value, a.new_value, u.name AS user_name
             FROM module_config_audit a
             LEFT JOIN users u ON u.id = a.changed_by
             ORDER BY a.timestamp DESC LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true,'rows'=>$rows]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>true,'rows'=>[]]);
    }
    exit;
}

if ($get_action === 'verify_modules') {
    try {
        $modules = $pdo->query(
            "SELECT ms.module_key, ms.module_name, ms.is_enabled,
                    COUNT(mc.id) AS settings_count
             FROM module_settings ms
             LEFT JOIN module_config mc ON mc.module_key = ms.module_key
             GROUP BY ms.module_key, ms.module_name, ms.is_enabled
             ORDER BY ms.module_order"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Cast types
        foreach ($modules as &$m) {
            $m['is_enabled']     = (bool)(int)$m['is_enabled'];
            $m['settings_count'] = (int)$m['settings_count'];
        }
        unset($m);

        echo json_encode(['ok' => true, 'modules' => $modules]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ── POST actions ──────────────────────────────────────────────
$csrf = $_POST['csrf_token'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token.']); exit;
}

$action = trim($_POST['action'] ?? '');

// ── toggle_module ─────────────────────────────────────────────
if ($action === 'toggle_module') {
    $module_key = trim($_POST['module_key'] ?? '');
    $enabled    = (int)($_POST['enabled'] ?? 0);

    if (empty($module_key)) { echo json_encode(['ok'=>false,'error'=>'Module key required.']); exit; }

    try {
        // Verify module exists
        $chk = $pdo->prepare("SELECT module_key FROM module_settings WHERE module_key=? LIMIT 1");
        $chk->execute([$module_key]);
        if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Module not found.']); exit; }

        // Get old value for audit
        $old = $pdo->prepare("SELECT is_enabled FROM module_settings WHERE module_key=?");
        $old->execute([$module_key]);
        $old_val = (int)$old->fetchColumn();

        $pdo->prepare("UPDATE module_settings SET is_enabled=?, updated_at=NOW() WHERE module_key=?")
            ->execute([$enabled, $module_key]);

        // Audit
        $pdo->prepare("INSERT INTO module_config_audit (module_key,config_key,action_type,old_value,new_value,changed_by,changed_by_role,ip_address) VALUES (?,NULL,?,?,?,?,?,?)")
            ->execute([$module_key, $enabled ? 'enable' : 'disable', $old_val ? 'enabled' : 'disabled', $enabled ? 'enabled' : 'disabled', $me['id'], $role, $_SERVER['REMOTE_ADDR']??'']);

        log_activity($pdo, $me['id'], 'Module Toggle', "SuperAdmin " . ($enabled?'enabled':'disabled') . " module '{$module_key}'");

        echo json_encode(['ok'=>true,'message'=>"Module '{$module_key}' " . ($enabled?'enabled':'disabled') . "."]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ── save_settings ─────────────────────────────────────────────
if ($action === 'save_settings') {
    $module_key = trim($_POST['module_key'] ?? '');
    $settings   = json_decode($_POST['settings'] ?? '{}', true);

    if (empty($module_key))       { echo json_encode(['ok'=>false,'error'=>'Module key required.']); exit; }
    if (!is_array($settings))     { echo json_encode(['ok'=>false,'error'=>'Invalid settings payload.']); exit; }

    try {
        $pdo->beginTransaction();

        $get_old = $pdo->prepare("SELECT config_value FROM module_config WHERE module_key=? AND config_key=? LIMIT 1");
        $upd     = $pdo->prepare("UPDATE module_config SET config_value=?, updated_at=NOW() WHERE module_key=? AND config_key=?");
        $audit   = $pdo->prepare("INSERT INTO module_config_audit (module_key,config_key,action_type,old_value,new_value,changed_by,changed_by_role,ip_address) VALUES (?,?,'update',?,?,?,?,?)");

        foreach ($settings as $config_key => $new_value) {
            // Sanitize key
            if (!preg_match('/^[a-z0-9_]+$/', $config_key)) continue;

            $get_old->execute([$module_key, $config_key]);
            $old_value = $get_old->fetchColumn();
            if ($old_value === false) continue; // key doesn't exist — skip

            if ((string)$old_value === (string)$new_value) continue; // no change

            $upd->execute([$new_value, $module_key, $config_key]);
            $audit->execute([$module_key, $config_key, $old_value, $new_value, $me['id'], $role, $_SERVER['REMOTE_ADDR']??'']);
        }

        $pdo->commit();

        log_activity($pdo, $me['id'], 'Module Settings Saved', "SuperAdmin saved settings for module '{$module_key}'");

        echo json_encode(['ok'=>true,'message'=>"Settings for '{$module_key}' saved successfully."]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok'=>false,'error'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Unknown action.']);
