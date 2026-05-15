<?php
/**
 * System Settings API
 * Handles all CRUD operations for SuperAdmin System Settings
 * Steps: Logo, Theme, Layout, Accessibility, Audit Trail
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

require_login();

$me   = current_user();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));

// Only superadmin and developer can access system settings
if (!in_array($role, ['superadmin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. SuperAdmin only.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ─── Ensure tables exist ──────────────────────────────────────────────────────
function ensure_system_settings_tables(PDO $pdo): void {
    // system_settings table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            setting_group VARCHAR(50) NOT NULL DEFAULT 'general',
            updated_by  INT,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // system_settings_audit table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings_audit (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            setting_key  VARCHAR(100) NOT NULL,
            old_value    TEXT,
            new_value    TEXT,
            changed_by   INT NOT NULL,
            changed_by_name VARCHAR(150),
            change_type  VARCHAR(50) DEFAULT 'update',
            ip_address   VARCHAR(45),
            created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

try {
    ensure_system_settings_tables($pdo);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB init error: ' . $e->getMessage()]);
    exit;
}

// ─── Helper: log audit ────────────────────────────────────────────────────────
function log_settings_audit(PDO $pdo, string $key, ?string $old, ?string $new, array $user, string $type = 'update'): void {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings_audit
            (setting_key, old_value, new_value, changed_by, changed_by_name, change_type, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $key,
        $old,
        $new,
        $user['id'] ?? 0,
        $user['name'] ?? (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        $type,
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
    ]);
}

// ─── Helper: get setting ──────────────────────────────────────────────────────
function get_setting(PDO $pdo, string $key, ?string $default = null): ?string {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['setting_value'] : $default;
}

// ─── Helper: save setting ─────────────────────────────────────────────────────
function save_setting(PDO $pdo, string $key, string $value, string $group, array $user): void {
    $old = get_setting($pdo, $key);
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_group, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                setting_group  = VALUES(setting_group),
                                updated_by     = VALUES(updated_by),
                                updated_at     = NOW()
    ");
    $stmt->execute([$key, $value, $group, $user['id'] ?? 0]);
    log_settings_audit($pdo, $key, $old, $value, $user);
}

// ─── Route actions ────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET all settings ──────────────────────────────────────────────────────
    case 'get_all':
        $stmt = $pdo->query("SELECT setting_key, setting_value, setting_group, updated_at FROM system_settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = [
                'value'   => $r['setting_value'],
                'group'   => $r['setting_group'],
                'updated' => $r['updated_at'],
            ];
        }
        echo json_encode(['success' => true, 'settings' => $settings]);
        break;

    // ── SAVE logo ─────────────────────────────────────────────────────────────
    case 'save_logo':
        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
            exit;
        }
        $file     = $_FILES['logo'];
        $allowed  = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'];
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowed)) {
            echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PNG, JPG, GIF, SVG, WEBP.']);
            exit;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Max 2MB.']);
            exit;
        }

        $uploadDir = __DIR__ . '/../../assets/img/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'system_logo_' . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
            exit;
        }

        $logoUrl = 'assets/img/' . $filename;
        save_setting($pdo, 'system_logo', $logoUrl, 'branding', $me);
        save_setting($pdo, 'system_logo_updated', date('Y-m-d H:i:s'), 'branding', $me);
        log_activity($pdo, $me['id'], 'System Settings – Logo Upload', "SuperAdmin uploaded new system logo: {$filename}");

        echo json_encode(['success' => true, 'message' => 'Logo updated successfully.', 'logo_url' => $logoUrl]);
        break;

    // ── SAVE theme ────────────────────────────────────────────────────────────
    case 'save_theme':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $allowed_keys = [
            'theme_mode', 'primary_color', 'secondary_color', 'accent_color',
            'theme_preset', 'font_family', 'text_size'
        ];

        $saved = [];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $val = trim((string)$data[$key]);
                // Validate color hex
                if (in_array($key, ['primary_color', 'secondary_color', 'accent_color'])) {
                    if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) {
                        echo json_encode(['success' => false, 'message' => "Invalid color value for $key"]);
                        exit;
                    }
                }
                save_setting($pdo, $key, $val, 'theme', $me);
                // Also sync to ui_config for generate_theme_css.php compatibility
                try {
                    $pdo->prepare("
                        INSERT INTO ui_config (config_key, config_value, config_category)
                        VALUES (?, ?, 'theme')
                        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                    ")->execute([$key, $val]);
                } catch (Exception $e) { /* ui_config may not exist */ }
                $saved[] = $key;
            }
        }

        log_activity($pdo, $me['id'], 'System Settings – Theme', "SuperAdmin saved color theme/UI settings. Keys: " . implode(', ', $saved));
        echo json_encode(['success' => true, 'message' => 'Theme settings saved.', 'saved_keys' => $saved]);
        break;

    // ── SAVE layout ────────────────────────────────────────────────────────────
    case 'save_layout':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $allowed_keys = ['sidebar_state', 'sidebar_position', 'dashboard_card_order', 'sidebar_style'];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $val = is_array($data[$key]) ? json_encode($data[$key]) : trim((string)$data[$key]);
                save_setting($pdo, $key, $val, 'layout', $me);
                // Sync sidebar_state / sidebar_position to ui_config
                if (in_array($key, ['sidebar_state', 'sidebar_position'])) {
                    try {
                        $pdo->prepare("
                            INSERT INTO ui_config (config_key, config_value, config_category)
                            VALUES (?, ?, 'layout')
                            ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                        ")->execute([$key, $val]);
                    } catch (Exception $e) {}
                }
            }
        }

        log_activity($pdo, $me['id'], 'System Settings – Layout', "SuperAdmin saved sidebar & layout settings.");
        echo json_encode(['success' => true, 'message' => 'Layout settings saved.']);
        break;

    // ── SAVE accessibility ────────────────────────────────────────────────────
    case 'save_accessibility':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $allowed_keys = ['high_contrast', 'font_scale', 'reduce_motion', 'focus_indicators', 'screen_reader_hints'];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $val = trim((string)$data[$key]);
                save_setting($pdo, $key, $val, 'accessibility', $me);
            }
        }
        log_activity($pdo, $me['id'], 'System Settings – Accessibility', "SuperAdmin saved accessibility settings.");

        echo json_encode(['success' => true, 'message' => 'Accessibility settings saved.']);
        break;

    // ── GET audit trail ───────────────────────────────────────────────────────
    case 'get_audit':
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;
        $group    = $_GET['group'] ?? '';
        $search   = $_GET['search'] ?? '';

        $where  = [];
        $params = [];

        if ($group) {
            // Join to get group
            $where[]  = "a.setting_key IN (SELECT setting_key FROM system_settings WHERE setting_group = ?)";
            $params[] = $group;
        }
        if ($search) {
            $where[]  = "(a.setting_key LIKE ? OR a.changed_by_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings_audit a $whereSQL");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT a.*, s.setting_group
            FROM system_settings_audit a
            LEFT JOIN system_settings s ON a.setting_key = s.setting_key
            $whereSQL
            ORDER BY a.created_at DESC
            LIMIT $per_page OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => ceil($total / $per_page),
        ]);
        break;

    // ── GET logo ──────────────────────────────────────────────────────────────
    case 'get_logo':
        $logo = get_setting($pdo, 'system_logo', 'assets/img/Petron Logo.png');
        echo json_encode(['success' => true, 'logo_url' => $logo]);
        break;

    // ── DELETE logo (reset to default) ────────────────────────────────────────
    case 'reset_logo':
        $default = 'assets/img/Petron Logo.png';
        save_setting($pdo, 'system_logo', $default, 'branding', $me);
        log_activity($pdo, $me['id'], 'System Settings – Logo Reset', "SuperAdmin reset system logo to default.");
        echo json_encode(['success' => true, 'message' => 'Logo reset to default.', 'logo_url' => $default]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
