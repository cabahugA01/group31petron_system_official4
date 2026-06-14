<?php
/**
 * System Settings API
 * Handles all CRUD operations for SuperAdmin System Settings (Global and Station-Specific)
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
    echo json_encode(['success' => false, 'message' => 'Access denied. SuperAdmin/Developer only.']);
    exit;
}

$action     = $_GET['action'] ?? $_POST['action'] ?? '';
$station_id = isset($_GET['station_id']) ? (int)$_GET['station_id'] : (isset($_POST['station_id']) ? (int)$_POST['station_id'] : 0);

// ─── Helper: log audit ────────────────────────────────────────────────────────
function log_settings_audit(PDO $pdo, string $key, ?string $old, ?string $new, array $user, string $type = 'update', int $station_id = 0): void {
    // Use NULL when user_id = 0 to avoid FK constraint failure on users(id)
    $userId = ($user['id'] ?? 0) > 0 ? (int)($user['id']) : null;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings_audit
                (setting_key, old_value, new_value, changed_by, changed_by_name, change_type, ip_address, station_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $key,
            $old,
            $new,
            $userId,
            trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'System'),
            $type,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            $station_id
        ]);
    } catch (Exception $e) {
        // Audit failure must NOT break the save — log silently
        error_log('system_settings_audit insert failed: ' . $e->getMessage());
    }
}


// ─── Helper: get setting ──────────────────────────────────────────────────────
function get_setting(PDO $pdo, string $key, int $station_id = 0, ?string $default = null): ?string {
    if ($station_id > 0) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = ? LIMIT 1");
        $stmt->execute([$key, $station_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row['setting_value'];
        }
    }
    // Fallback to global setting (station_id = 0)
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? AND station_id = 0 LIMIT 1");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row !== false) ? $row['setting_value'] : $default;
}

// ─── Helper: save setting ─────────────────────────────────────────────────────
function save_setting(PDO $pdo, string $key, string $value, string $group, array $user, int $station_id = 0): void {
    $old    = get_setting($pdo, $key, $station_id);
    // Use NULL for updated_by when user_id = 0 (avoids FK constraint failure)
    $userId = ($user['id'] ?? 0) > 0 ? (int)($user['id']) : null;
    $stmt = $pdo->prepare("
        INSERT INTO system_settings
            (setting_key, setting_value, setting_group, category, updated_by, station_id, is_public, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            setting_group = VALUES(setting_group),
            category      = VALUES(category),
            updated_by    = VALUES(updated_by),
            updated_at    = NOW()
    ");
    $stmt->execute([$key, $value, $group, $group, $userId, $station_id]);
    log_settings_audit($pdo, $key, $old, $value, $user, 'update', $station_id);
}

// ─── Route actions ────────────────────────────────────────────────────────────
switch ($action) {

    // ── GET all settings ──────────────────────────────────────────────────────
    case 'get_all':
        // Get global settings (station_id = 0)
        $stmt0 = $pdo->prepare("SELECT setting_key, setting_value, setting_group, updated_at FROM system_settings WHERE station_id = 0");
        $stmt0->execute();
        $rows0 = $stmt0->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows0 as $r) {
            $settings[$r['setting_key']] = [
                'value'   => $r['setting_value'],
                'group'   => $r['setting_group'],
                'updated' => $r['updated_at'],
            ];
        }

        // If specific station is selected, override with per-station settings
        if ($station_id > 0) {
            $stmtS = $pdo->prepare("SELECT setting_key, setting_value, setting_group, updated_at FROM system_settings WHERE station_id = ?");
            $stmtS->execute([$station_id]);
            $rowsS = $stmtS->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rowsS as $r) {
                $settings[$r['setting_key']] = [
                    'value'   => $r['setting_value'],
                    'group'   => $r['setting_group'],
                    'updated' => $r['updated_at'],
                ];
            }
        }
        echo json_encode(['success' => true, 'settings' => $settings, 'station_id' => $station_id]);
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
        $filename = 'system_logo_' . ($station_id ? 'station_' . $station_id . '_' : '') . time() . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
            exit;
        }

        $logoUrl = 'assets/img/' . $filename;
        save_setting($pdo, 'system_logo', $logoUrl, 'branding', $me, $station_id);
        save_setting($pdo, 'system_logo_updated', date('Y-m-d H:i:s'), 'branding', $me, $station_id);
        
        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings – Logo Upload', "SuperAdmin uploaded new system logo for {$scopeName}: {$filename}");

        echo json_encode(['success' => true, 'message' => 'Logo updated successfully.', 'logo_url' => $logoUrl]);
        break;

    // ── SAVE theme ────────────────────────────────────────────────────────────
    case 'save_theme':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        // Color keys that need hex validation
        $color_keys = ['primary_color', 'secondary_color', 'accent_color', 'button_color', 'sidebar_color'];
        // All accepted keys
        $allowed_keys = array_merge($color_keys, [
            'theme_mode', 'theme_preset', 'font_family', 'text_size'
        ]);

        $saved = [];
        foreach ($allowed_keys as $key) {
            if (!isset($data[$key])) continue;
            $val = trim((string)$data[$key]);
            // Validate hex colors
            if (in_array($key, $color_keys)) {
                if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) continue; // skip invalid
            }
            save_setting($pdo, $key, $val, 'theme', $me, $station_id);
            // Sync primary/secondary/accent to ui_config for global settings
            if ($station_id === 0 && in_array($key, ['primary_color', 'secondary_color', 'accent_color'])) {
                try {
                    $pdo->prepare("
                        INSERT INTO ui_config (config_key, config_value, config_category)
                        VALUES (?, ?, 'theme')
                        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                    ")->execute([$key, $val]);
                } catch (Exception $e) {}
            }
            $saved[] = $key;
        }

        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings - Theme', "SuperAdmin saved theme settings for {$scopeName}. Keys: " . implode(', ', $saved));
        echo json_encode(['success' => true, 'message' => 'Theme settings saved.', 'saved_keys' => $saved]);
        break;

    // ── SAVE layout ────────────────────────────────────────────────────────────
    case 'save_layout':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $allowed_keys = ['sidebar_state', 'sidebar_position', 'dashboard_card_order', 'sidebar_style', 'font_scale_layout', 'dashboard_card_arrangement'];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $val = is_array($data[$key]) ? json_encode($data[$key]) : trim((string)$data[$key]);
                save_setting($pdo, $key, $val, 'layout', $me, $station_id);
                if ($station_id === 0 && in_array($key, ['sidebar_state', 'sidebar_position'])) {
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

        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings – Layout', "SuperAdmin saved layout settings for {$scopeName}.");
        echo json_encode(['success' => true, 'message' => 'Layout settings saved.']);
        break;

    // ── SAVE accessibility ────────────────────────────────────────────────────
    case 'save_accessibility':
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $allowed_keys = ['high_contrast', 'font_scale', 'reduce_motion', 'focus_indicators', 'screen_reader_hints', 'font_scale_accessibility'];
        foreach ($allowed_keys as $key) {
            if (isset($data[$key])) {
                $val = trim((string)$data[$key]);
                save_setting($pdo, $key, $val, 'accessibility', $me, $station_id);
            }
        }
        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings – Accessibility', "SuperAdmin saved accessibility settings for {$scopeName}.");

        echo json_encode(['success' => true, 'message' => 'Accessibility settings saved.']);
        break;

    // ── SAVE ALL settings at once ─────────────────────────────────────────────
    case 'save_all':
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($data)) $data = $_POST;

        $saved_groups = [];
        $errors = [];

        // Theme colors (all color fields)
        $color_fields = ['primary_color', 'button_color', 'sidebar_color', 'secondary_color', 'accent_color'];
        foreach ($color_fields as $key) {
            if (!isset($data[$key]) || trim($data[$key]) === '') continue;
            $val = trim((string)$data[$key]);
            if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $val)) {
                $errors[] = "Invalid color for $key: $val";
                continue;
            }
            save_setting($pdo, $key, $val, 'theme', $me, $station_id);
            // Also sync primary/secondary/accent to ui_config globally
            if ($station_id === 0 && in_array($key, ['primary_color', 'secondary_color', 'accent_color'])) {
                try {
                    $pdo->prepare("
                        INSERT INTO ui_config (config_key, config_value, config_category)
                        VALUES (?, ?, 'theme')
                        ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)
                    ")->execute([$key, $val]);
                } catch (Exception $e) {}
            }
            $saved_groups['theme'] = true;
        }

        // Layout settings
        $layout_fields = ['sidebar_style', 'font_scale_layout'];
        foreach ($layout_fields as $key) {
            if (!isset($data[$key])) continue;
            $val = trim((string)$data[$key]);
            save_setting($pdo, $key, $val, 'layout', $me, $station_id);
            $saved_groups['layout'] = true;
        }

        // Accessibility settings
        $access_fields = ['high_contrast', 'font_scale_accessibility'];
        foreach ($access_fields as $key) {
            if (!isset($data[$key])) continue;
            $val = trim((string)$data[$key]);
            save_setting($pdo, $key, $val, 'accessibility', $me, $station_id);
            $saved_groups['accessibility'] = true;
        }

        if (empty($saved_groups)) {
            echo json_encode(['success' => false, 'message' => 'No valid settings to save.' . (count($errors) ? ' Errors: ' . implode('; ', $errors) : '')]);
            break;
        }

        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings - Save All', "SuperAdmin saved all system settings for {$scopeName}: " . implode(', ', array_keys($saved_groups)));
        echo json_encode(['success' => true, 'message' => 'All settings saved successfully.', 'groups' => array_keys($saved_groups), 'warnings' => $errors]);
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

        // Filter audit by station_id if requested
        $where[]  = "a.station_id = ?";
        $params[] = $station_id;

        if ($group) {
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
            LEFT JOIN system_settings s ON a.setting_key = s.setting_key AND a.station_id = s.station_id
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
        $logo = get_setting($pdo, 'system_logo', $station_id, 'assets/img/Petron Logo.png');
        echo json_encode(['success' => true, 'logo_url' => $logo]);
        break;

    // ── DELETE logo (reset to default) ────────────────────────────────────────
    case 'reset_logo':
        $default = 'assets/img/Petron Logo.png';
        save_setting($pdo, 'system_logo', $default, 'branding', $me, $station_id);
        
        $scopeName = $station_id ? "Station ID: $station_id" : "Global";
        log_activity($pdo, $me['id'], 'System Settings – Logo Reset', "SuperAdmin reset system logo for {$scopeName} to default.");
        echo json_encode(['success' => true, 'message' => 'Logo reset to default.', 'logo_url' => $default]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
        break;
}
