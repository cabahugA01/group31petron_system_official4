<?php
// ============================================================
// SuperAdmin – Integration Settings API
// backend/api/superadmin_integration_api.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../backend/lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$me   = current_user();
$role = role_key($me['role'] ?? '');
if (!in_array($role, ['superadmin', 'developer'])) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']); exit;
}

// ── Bootstrap tables ─────────────────────────────────────────
function ensure_integration_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_pos_parsers (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        file_type   ENUM('csv','excel') NOT NULL DEFAULT 'csv',
        delimiter   VARCHAR(5) NOT NULL DEFAULT ',',
        has_header  TINYINT(1) NOT NULL DEFAULT 1,
        column_map  JSON NOT NULL COMMENT 'JSON: {\"source_col\":\"system_field\"}',
        sample_data TEXT,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_by  INT NOT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_api_endpoints (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(120) NOT NULL,
        endpoint_url    VARCHAR(500) NOT NULL,
        auth_type       ENUM('none','api_key','bearer','basic') NOT NULL DEFAULT 'api_key',
        auth_value      TEXT,
        allowed_methods SET('GET','POST','PUT','DELETE') NOT NULL DEFAULT 'GET',
        module_target   VARCHAR(100) NOT NULL DEFAULT '',
        last_tested_at  DATETIME NULL,
        last_test_status ENUM('ok','fail','untested') NOT NULL DEFAULT 'untested',
        is_active       TINYINT(1) NOT NULL DEFAULT 1,
        created_by      INT NOT NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_sync_rules (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        name                VARCHAR(120) NOT NULL,
        module_key          VARCHAR(100) NOT NULL,
        frequency           ENUM('realtime','hourly','daily','weekly') NOT NULL DEFAULT 'daily',
        conflict_resolution ENUM('system_override','external_override') NOT NULL DEFAULT 'system_override',
        is_active           TINYINT(1) NOT NULL DEFAULT 1,
        last_synced_at      DATETIME NULL,
        created_by          INT NOT NULL,
        created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_audit (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        action_type VARCHAR(80) NOT NULL,
        target_type ENUM('pos_parser','api_endpoint','sync_rule') NOT NULL,
        target_id   INT NOT NULL,
        target_name VARCHAR(200) NOT NULL DEFAULT '',
        details     TEXT,
        ip_address  VARCHAR(45),
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user      (user_id),
        INDEX idx_created   (created_at),
        INDEX idx_target    (target_type, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

try { ensure_integration_tables($pdo); } catch (Exception $e) { /* already exist */ }

// ── CSRF check for POST ───────────────────────────────────────
$get_action = trim($_GET['action'] ?? '');

// GET-only endpoints (no CSRF needed)
if ($get_action === 'get_audit') {
    try {
        $rows = $pdo->query(
            "SELECT ia.*, u.name AS user_name
             FROM integration_audit ia
             LEFT JOIN users u ON u.user_id = ia.user_id
             ORDER BY ia.created_at DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'rows' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'rows' => []]);
    }
    exit;
}

if ($get_action === 'test_endpoint') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
    try {
        $ep = $pdo->prepare("SELECT * FROM integration_api_endpoints WHERE id=? LIMIT 1");
        $ep->execute([$id]);
        $endpoint = $ep->fetch(PDO::FETCH_ASSOC);
        if (!$endpoint) { echo json_encode(['ok' => false, 'error' => 'Endpoint not found.']); exit; }

        // Attempt a real HTTP ping using file_get_contents or curl
        $url     = $endpoint['endpoint_url'];
        $status  = 'fail';
        $message = '';
        $latency = 0;

        $ctx = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => 5,
                'ignore_errors'   => true,
                'header'          => $endpoint['auth_type'] === 'api_key'
                    ? "Authorization: ApiKey {$endpoint['auth_value']}\r\n"
                    : ($endpoint['auth_type'] === 'bearer'
                        ? "Authorization: Bearer {$endpoint['auth_value']}\r\n"
                        : ''),
            ],
        ]);

        $t0 = microtime(true);
        $resp = @file_get_contents($url, false, $ctx);
        $latency = round((microtime(true) - $t0) * 1000);

        if ($resp !== false) {
            $status  = 'ok';
            $message = "Connected in {$latency}ms. Response length: " . strlen($resp) . " bytes.";
        } else {
            $message = "Could not reach endpoint. Check URL and auth settings.";
        }

        // Update last_tested_at
        $pdo->prepare("UPDATE integration_api_endpoints SET last_tested_at=NOW(), last_test_status=? WHERE id=?")
            ->execute([$status, $id]);

        // Audit
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,details,ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$me['id'], 'test_connection', 'api_endpoint', $id, $endpoint['name'], $message, $_SERVER['REMOTE_ADDR'] ?? '']);

        log_activity($pdo, $me['id'], 'Integration Test', "Tested endpoint '{$endpoint['name']}': {$status}");
        echo json_encode(['ok' => true, 'status' => $status, 'message' => $message, 'latency_ms' => $latency]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// POST actions — require CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']); exit;
}
$action = trim($_POST['action'] ?? '');

// ── POS Parser ────────────────────────────────────────────────
if ($action === 'save_pos_parser') {
    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $file_type  = in_array($_POST['file_type'] ?? '', ['csv','excel']) ? $_POST['file_type'] : 'csv';
    $delimiter  = substr(trim($_POST['delimiter'] ?? ','), 0, 5) ?: ',';
    $has_header = (int)($_POST['has_header'] ?? 1);
    $col_map    = $_POST['column_map'] ?? '{}';

    if (empty($name)) { echo json_encode(['ok' => false, 'error' => 'Parser name is required.']); exit; }

    // Validate JSON
    $decoded = json_decode($col_map, true);
    if (!is_array($decoded)) { echo json_encode(['ok' => false, 'error' => 'Invalid column map JSON.']); exit; }
    $col_map = json_encode($decoded); // re-encode clean

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE integration_pos_parsers SET name=?,file_type=?,delimiter=?,has_header=?,column_map=?,updated_at=NOW() WHERE id=?")
                ->execute([$name, $file_type, $delimiter, $has_header, $col_map, $id]);
            $action_type = 'update_parser';
        } else {
            $pdo->prepare("INSERT INTO integration_pos_parsers (name,file_type,delimiter,has_header,column_map,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$name, $file_type, $delimiter, $has_header, $col_map, $me['id']]);
            $id = (int)$pdo->lastInsertId();
            $action_type = 'create_parser';
        }
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,details,ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$me['id'], $action_type, 'pos_parser', $id, $name, "file_type={$file_type}, delimiter={$delimiter}", $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration POS Parser', "{$action_type}: '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'id' => $id, 'message' => "Parser '{$name}' saved."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_pos_parser') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
    try {
        $row = $pdo->prepare("SELECT name FROM integration_pos_parsers WHERE id=?");
        $row->execute([$id]);
        $name = $row->fetchColumn() ?: "ID {$id}";
        $pdo->prepare("DELETE FROM integration_pos_parsers WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$me['id'], 'delete_parser', 'pos_parser', $id, $name, $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration POS Parser', "Deleted parser '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'message' => "Parser '{$name}' deleted."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── API Endpoints ─────────────────────────────────────────────
if ($action === 'save_api_endpoint') {
    $id              = (int)($_POST['id'] ?? 0);
    $name            = trim($_POST['name'] ?? '');
    $url             = trim($_POST['endpoint_url'] ?? '');
    $auth_type       = in_array($_POST['auth_type'] ?? '', ['none','api_key','bearer','basic']) ? $_POST['auth_type'] : 'api_key';
    $auth_value      = trim($_POST['auth_value'] ?? '');
    $methods         = array_intersect((array)($_POST['allowed_methods'] ?? ['GET']), ['GET','POST','PUT','DELETE']);
    $module_target   = trim($_POST['module_target'] ?? '');

    if (empty($name)) { echo json_encode(['ok' => false, 'error' => 'Endpoint name is required.']); exit; }
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) { echo json_encode(['ok' => false, 'error' => 'Valid URL is required.']); exit; }
    if (empty($methods)) $methods = ['GET'];
    $methods_str = implode(',', $methods);

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE integration_api_endpoints SET name=?,endpoint_url=?,auth_type=?,auth_value=?,allowed_methods=?,module_target=?,updated_at=NOW() WHERE id=?")
                ->execute([$name, $url, $auth_type, $auth_value, $methods_str, $module_target, $id]);
            $action_type = 'update_endpoint';
        } else {
            $pdo->prepare("INSERT INTO integration_api_endpoints (name,endpoint_url,auth_type,auth_value,allowed_methods,module_target,created_by) VALUES (?,?,?,?,?,?,?)")
                ->execute([$name, $url, $auth_type, $auth_value, $methods_str, $module_target, $me['id']]);
            $id = (int)$pdo->lastInsertId();
            $action_type = 'create_endpoint';
        }
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,details,ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$me['id'], $action_type, 'api_endpoint', $id, $name, "url={$url}, methods={$methods_str}", $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration API Endpoint', "{$action_type}: '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'id' => $id, 'message' => "Endpoint '{$name}' saved."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_api_endpoint') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
    try {
        $row = $pdo->prepare("SELECT name FROM integration_api_endpoints WHERE id=?");
        $row->execute([$id]);
        $name = $row->fetchColumn() ?: "ID {$id}";
        $pdo->prepare("DELETE FROM integration_api_endpoints WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$me['id'], 'delete_endpoint', 'api_endpoint', $id, $name, $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration API Endpoint', "Deleted endpoint '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'message' => "Endpoint '{$name}' deleted."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Sync Rules ────────────────────────────────────────────────
if ($action === 'save_sync_rule') {
    $id                  = (int)($_POST['id'] ?? 0);
    $name                = trim($_POST['name'] ?? '');
    $module_key          = trim($_POST['module_key'] ?? '');
    $frequency           = in_array($_POST['frequency'] ?? '', ['realtime','hourly','daily','weekly']) ? $_POST['frequency'] : 'daily';
    $conflict_resolution = in_array($_POST['conflict_resolution'] ?? '', ['system_override','external_override']) ? $_POST['conflict_resolution'] : 'system_override';
    $is_active           = (int)($_POST['is_active'] ?? 1);

    if (empty($name))       { echo json_encode(['ok' => false, 'error' => 'Rule name is required.']); exit; }
    if (empty($module_key)) { echo json_encode(['ok' => false, 'error' => 'Module is required.']); exit; }

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE integration_sync_rules SET name=?,module_key=?,frequency=?,conflict_resolution=?,is_active=?,updated_at=NOW() WHERE id=?")
                ->execute([$name, $module_key, $frequency, $conflict_resolution, $is_active, $id]);
            $action_type = 'update_sync_rule';
        } else {
            $pdo->prepare("INSERT INTO integration_sync_rules (name,module_key,frequency,conflict_resolution,is_active,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$name, $module_key, $frequency, $conflict_resolution, $is_active, $me['id']]);
            $id = (int)$pdo->lastInsertId();
            $action_type = 'create_sync_rule';
        }
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,details,ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$me['id'], $action_type, 'sync_rule', $id, $name, "module={$module_key}, freq={$frequency}, conflict={$conflict_resolution}", $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration Sync Rule', "{$action_type}: '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'id' => $id, 'message' => "Sync rule '{$name}' saved."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_sync_rule') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid ID.']); exit; }
    try {
        $row = $pdo->prepare("SELECT name FROM integration_sync_rules WHERE id=?");
        $row->execute([$id]);
        $name = $row->fetchColumn() ?: "ID {$id}";
        $pdo->prepare("DELETE FROM integration_sync_rules WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$me['id'], 'delete_sync_rule', 'sync_rule', $id, $name, $_SERVER['REMOTE_ADDR'] ?? '']);
        log_activity($pdo, $me['id'], 'Integration Sync Rule', "Deleted sync rule '{$name}' (ID {$id})");
        echo json_encode(['ok' => true, 'message' => "Sync rule '{$name}' deleted."]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'toggle_active') {
    $id          = (int)($_POST['id'] ?? 0);
    $target_type = trim($_POST['target_type'] ?? '');
    $is_active   = (int)($_POST['is_active'] ?? 0);
    $table_map   = ['pos_parser' => 'integration_pos_parsers', 'api_endpoint' => 'integration_api_endpoints', 'sync_rule' => 'integration_sync_rules'];
    if (!isset($table_map[$target_type]) || $id <= 0) { echo json_encode(['ok' => false, 'error' => 'Invalid parameters.']); exit; }
    try {
        $table = $table_map[$target_type];
        $pdo->prepare("UPDATE `{$table}` SET is_active=?, updated_at=NOW() WHERE id=?")->execute([$is_active, $id]);
        $pdo->prepare("INSERT INTO integration_audit (user_id,action_type,target_type,target_id,target_name,ip_address) VALUES (?,?,?,?,?,?)")
            ->execute([$me['id'], $is_active ? 'activate' : 'deactivate', $target_type, $id, '', $_SERVER['REMOTE_ADDR'] ?? '']);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
