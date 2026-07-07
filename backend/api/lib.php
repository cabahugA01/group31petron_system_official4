<?php
// Simple JSON-based storage helpers (no DB required)
function data_path($file){ return __DIR__ . '/../data/' . $file; }

function read_json($file, $default){
  $path = data_path($file);
  if(!file_exists($path)) return $default;
  $raw = file_get_contents($path);
  $data = json_decode($raw, true);
  return $data === null ? $default : $data;
}

function write_json($file, $data){
  $path = data_path($file);
  $tmp = $path . '.tmp';
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
  rename($tmp, $path);
}

function json_response($data, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function require_login(){
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  if(empty($_SESSION['user'])){
    // If called from /backend/*, return JSON 401 to avoid fetch() HTML redirects.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if(strpos($script, '/backend/') !== false){
      json_response(['ok'=>false,'error'=>'Unauthorized'], 401);
    }
    // Redirect to the app's login page (index.php) in a way that works even when
    // the app is deployed inside a subfolder (e.g., /petron-pos/index.php).
    //
    // Examples:
    //  - /petron-pos/dashboard.php  -> /petron-pos/index.php
    //  - /petron-pos/partials/...   -> /petron-pos/index.php (included pages)
    //  - /petron-pos/backend/...    -> JSON 401 handled above
    $root = '';
    if(($pos = strpos($script, '/backend/')) !== false){
      $root = substr($script, 0, $pos);
    }elseif(($pos = strpos($script, '/auth/')) !== false){
      $root = substr($script, 0, $pos);
    }else{
      $root = rtrim(dirname($script), '/\\');
    }
    if($root === '' || $root === '.') $root = '/';
    $loginUrl = rtrim($root, '/') . '/index.php';
    header('Location: ' . $loginUrl);
    exit;
  }
}


function normalize_role($role){
  $r = strtolower(trim((string)$role));
  if($r === 'superadmin' || $r === 'super admin') return 'Super Admin';
  if($r === 'admin' || $r === 'manager' || $r === 'admin/manager' || $r === 'station admin') return 'Admin';
  return 'Staff';
}

// Canonical role key used for menus/routing.
// Keeps compatibility with the existing normalize_role() labels above.
function role_key($role){
  $r = strtolower(trim((string)$role));
  if(in_array($r, ['superadmin','super admin','super_admin'])) return 'superadmin';
  if(in_array($r, ['admin','station admin','station_admin'])) return 'admin';
  if(in_array($r, ['manager','supervisor','manager / supervisor','manager/supervisor','supervisor/manager'])) return 'manager';
  if(in_array($r, ['staff','operations staff','operations','ops'])) return 'staff';
  // Fallback: if legacy normalize_role labels indicate Admin, treat manager/admin as admin.
  $label = normalize_role($role);
  if($label === 'Super Admin') return 'superadmin';
  if($label === 'Admin') return 'admin';
  return 'staff';
}

function role_rank($role){
  $role = normalize_role($role);
  if($role === 'Super Admin') return 3;
  if($role === 'Admin') return 2;
  return 1; // Staff
}

function current_user(){
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  return $_SESSION['user'] ?? null;
}

function has_role_at_least($minRole){
  $u = current_user();
  $ur = $u ? role_rank($u['role'] ?? 'Staff') : 0;
  return $ur >= role_rank($minRole);
}

function require_role($minRole){
  require_login();
  if(!has_role_at_least($minRole)){
    json_response(['ok'=>false,'error'=>'Forbidden'], 403);
  }
}

function user_station_id(){
  $u = current_user();
  return $u['station_id'] ?? null;
}

function today_key(){
  return date('Y-m-d');
}

function money($n){
  return number_format((float)$n, 2, '.', ',');
}

function log_activity($pdo, $user_id, $action, $details) {
  try {
    if(!$pdo) return;
    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $details, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
  } catch (Exception $e) { /* Fail silently to not disrupt flow */ }
}
function is_shift_finalized(PDO $pdo, int $station_id, string $date, string $shift): bool {
  try {
    $stmt = $pdo->prepare("SELECT status FROM shift_reports WHERE station_id=? AND report_date=? AND shift=? LIMIT 1");
    $stmt->execute([$station_id, $date, $shift]);
    $st = $stmt->fetchColumn();
    return ($st === 'finalized');
  } catch(Exception $e) {
    return false;
  }
}

// --- RBAC HELPERS ---
function rbac_is_backend_request(): bool {
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  return (strpos($script, '/backend/') !== false) || (strpos($script, '\\backend\\') !== false);
}

function rbac_default_matrix(): array {
  // Default permissions if DB/JSON not available
  $modules = [
    'dashboard.view',
    'users.create_admin','users.approve_manager','users.reset_passwords','users.deactivate','rbac.manage',
    'inventory.purchase_orders','inventory.receiving','inventory.stock','reconciliation.fuel_reports',
    'pos.process_sales','receivables.manage','shift_reports.view',
    'joborder.create','joborder.assign_mechanics','joborder.parts_tracking','joborder.billing',
    'audit.logins','audit.password_changes','audit.account_actions','audit.recon_approvals','audit.settings_changes',
    'security.rbac_enforcement','security.account_lockouts','security.password_policy','security.unauthorized_attempts',
    'reports.daily','reports.monthly','reports.receivables_aging','reports.fuel_variance','reports.nationwide'
  ];
  $all = array_fill_keys($modules, 1);
  return [
    'superadmin' => $all,
    'admin' => [
      'dashboard.view'=>1,'users.reset_passwords'=>1,'users.deactivate'=>1,
      'inventory.purchase_orders'=>1,'inventory.receiving'=>1,'inventory.stock'=>1,
      'reconciliation.fuel_reports'=>1,
      'pos.process_sales'=>1,'receivables.manage'=>1,'shift_reports.view'=>1,
      'joborder.create'=>1,'joborder.assign_mechanics'=>1,'joborder.parts_tracking'=>1,'joborder.billing'=>1,
      'audit.logins'=>1,'audit.password_changes'=>1,'audit.account_actions'=>1,'audit.recon_approvals'=>1,
      'reports.daily'=>1,'reports.monthly'=>1
    ],
    'manager' => [
      'dashboard.view'=>1,'inventory.stock'=>1,'reconciliation.fuel_reports'=>1,'shift_reports.view'=>1,'audit.logins'=>1,'reports.daily'=>1
    ],
    'mechanic' => [ 'dashboard.view'=>1,'joborder.parts_tracking'=>1 ],
    'staff' => [ 'dashboard.view'=>1,'pos.process_sales'=>1 ]
  ];
}

function rbac_permissions(): array {
  if(session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (!empty($_SESSION['rbac_cache']) && is_array($_SESSION['rbac_cache'])) {
    $ts = (int)($_SESSION['rbac_cache']['ts'] ?? 0);
    if ($ts > (time() - 60)) return $_SESSION['rbac_cache']['data'];
  }

  $matrix = [];
  // Try DB
  try {
    global $pdo; // available in most pages after db_connect.php
    if ($pdo) {
      $stmt = $pdo->query("SELECT role, permission, allowed FROM role_permissions");
      $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($rows as $r) {
        $role = strtolower(trim($r['role']));
        if (!isset($matrix[$role])) $matrix[$role] = [];
        if ((int)$r['allowed'] === 1) $matrix[$role][$r['permission']] = 1;
      }
    }
  } catch(Exception $e) { /* ignore */ }

  if (empty($matrix)) {
    // Try JSON fallback
    $json = read_json('permissions.json', []);
    if (!empty($json['data']) && is_array($json['data'])) {
      // Normalize keys to lowercase roles
      foreach ($json['data'] as $role => $perms) {
        $matrix[strtolower(trim($role))] = $perms;
      }
    }
  }

  if (empty($matrix)) {
    $matrix = rbac_default_matrix();
  }

  $_SESSION['rbac_cache'] = ['ts' => time(), 'data' => $matrix];
  return $matrix;
}

function can(string $permission): bool {
  $u = current_user();
  $role = strtolower(trim((string)($u['role'] ?? 'staff')));
  if ($role === 'superadmin' || $role === 'super admin') return true;
  $perm = rbac_permissions();
  return !empty($perm[$role][$permission]);
}

function rbac_forbidden_html(string $message = 'Access denied by RBAC policy.'){
  http_response_code(403);
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
     . '<title>403 Forbidden</title>'
     . '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f8fa;margin:0;padding:40px;} .card{max-width:720px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.05);} .h{margin:0;padding:16px 20px;border-bottom:1px solid #e5e7eb;font-size:18px;color:#111827;} .b{padding:18px 20px;color:#4b5563;} .b a{color:#002F6C;text-decoration:none;border:1px solid #002F6C;padding:6px 10px;border-radius:4px;}</style></head><body>'
     . '<div class="card"><div class="h">403 Forbidden</div><div class="b">' . htmlspecialchars($message) . '<div style="margin-top:12px;"><a href="index.php">Go to Home</a></div></div></div>'
     . '</body></html>';
  exit;
}

function require_permission(string $permission){
  require_login();
  if (can($permission)) return; // allowed
  // Log and respond
  try {
    global $pdo; $u = current_user();
    if (isset($pdo) && $pdo) log_activity($pdo, $u['id'] ?? 0, 'RBAC Deny', "Denied '$permission' on " . ($_SERVER['REQUEST_URI'] ?? ''));
  } catch(Exception $e) { }

  if (rbac_is_backend_request()) {
    json_response(['ok'=>false, 'error'=>'Forbidden', 'permission'=>$permission], 403);
  }
  rbac_forbidden_html();
}

?>