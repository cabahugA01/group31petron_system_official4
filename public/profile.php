<?php
$page_id = 'profile';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me            = current_user();
$my_role       = role_key($me['role'] ?? 'staff');
$my_station_id = user_station_id();

function col_exists(PDO $pdo, string $table, string $col): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch(); }
    catch (Exception $e) { return false; }
}

$has_first_name      = col_exists($pdo, 'users', 'first_name');
$has_last_name       = col_exists($pdo, 'users', 'last_name');
$has_phone = true; // phone_number column always exists
$has_profile_picture = col_exists($pdo, 'users', 'profile_picture');

if (!$has_first_name)      { try { $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER id");           $has_first_name = true;      } catch (Exception $e) {} }
if (!$has_last_name)       { try { $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name");    $has_last_name = true;       } catch (Exception $e) {} }
$has_profile_picture = false; // profile_picture column removed from schema

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { unset($row['password_hash']); $me = $row; $_SESSION['user'] = array_merge($_SESSION['user'], $me); }
} catch (Exception $e) {}

$station_name = '';
if ($my_station_id && $my_role !== 'superadmin') {
    try {
        $stmt = $pdo->prepare("SELECT name FROM stations WHERE id = ?");
        $stmt->execute([$my_station_id]);
        $station_name = $stmt->fetchColumn() ?: 'Unknown Station';
    } catch (Exception $e) { $station_name = 'Unknown Station'; }
}

$last_login = 'Never';
try {
    $stmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE user_id = ? AND action = 'Login' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$me['id']]);
    $lr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lr) $last_login = date('M j, Y \a\t h:i A', strtotime($lr['created_at']));
} catch (Exception $e) {}

$msg = ''; $msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $email      = trim($_POST['email']      ?? '');
        $phone      = trim($_POST['phone']      ?? '');
        if ($first_name === '' || $last_name === '') {
            $msg = 'First name and last name are required.'; $msg_type = 'error';
        } else {
            try {
                if ($email !== '') {
                    $chk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND id != ?");
                    $chk->execute([$email, $me['id']]);
                    if ($chk->fetch()) throw new Exception("Email already registered to another account.");
                }
                $full_name = trim("$first_name $last_name");
                $sets = []; $params = [$full_name];
                if ($has_first_name) { $sets[] = "first_name = ?"; $params[] = $first_name; }
                if ($has_last_name)  { $sets[] = "last_name = ?";  $params[] = $last_name;  }
                $sets[] = "email = ?"; $params[] = $email;
                if ($has_phone) { $sets[] = "phone_number = ?"; $params[] = $phone; }
                $params[] = $me['id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE user_id = ?")->execute($params);
                $_SESSION['user']['name']       = $full_name;
                $_SESSION['user']['first_name'] = $first_name;
                $_SESSION['user']['last_name']  = $last_name;
                $_SESSION['user']['email']      = $email;
                if ($has_phone) $_SESSION['user']['phone_number'] = $phone;
                $me['name'] = $full_name; $me['first_name'] = $first_name;
                $me['last_name'] = $last_name; $me['email'] = $email; $me['phone_number'] = $phone;
                try { $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Profile Update', 'User updated profile information', ?)")->execute([$me['id'], $_SERVER['REMOTE_ADDR']]); } catch (Exception $e) {}
                $msg = 'Profile updated successfully!';
            } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); $msg_type = 'error'; }
        }
    }

    // profile_picture removed
    if (false && $has_profile_picture) {
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) {
                $msg = 'Only JPEG, PNG, GIF, or WebP images are allowed.'; $msg_type = 'error';
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                $msg = 'Image must be under 2 MB.'; $msg_type = 'error';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = 'profile_' . $me['id'] . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $old = $me['profile_picture'] ?? '';
                    if ($old && file_exists(__DIR__ . '/../' . ltrim($old, '/'))) @unlink(__DIR__ . '/../' . ltrim($old, '/'));
                    $rel = 'uploads/profiles/' . $filename;
                    // profile_picture removed
                    $_SESSION['user']['profile_picture'] = $rel;
                    $me['profile_picture'] = $rel;
                    $msg = 'Profile picture updated!';
                } else { $msg = 'Failed to save image.'; $msg_type = 'error'; }
            }
        } else { $msg = 'No file uploaded or upload error.'; $msg_type = 'error'; }
    }
}

$disp_first = htmlspecialchars($me['first_name'] ?? '');
$disp_last  = htmlspecialchars($me['last_name']  ?? '');
if ($disp_first === '' && $disp_last === '' && !empty($me['name'])) {
    $parts = explode(' ', trim($me['name']), 2);
    $disp_first = htmlspecialchars($parts[0] ?? '');
    $disp_last  = htmlspecialchars($parts[1] ?? '');
}
$disp_full = trim(strip_tags($disp_first) . ' ' . strip_tags($disp_last)) ?: htmlspecialchars($me['name'] ?? $me['username'] ?? 'User');
$disp_role = strtoupper(normalize_role($me['role'] ?? 'Staff'));

$pic_url = '';
if (!empty($me['profile_picture'])) {
    $sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pp = strpos($sn, '/public/');
    $ab = $pp !== false ? substr($sn, 0, $pp) : rtrim(dirname($sn), '/');
    $pic_url = $ab . '/' . ltrim($me['profile_picture'], '/');
}

require_once __DIR__ . '/../partials/header.php';
?>

<style>
/* ═══════════════════════════════════════════════
   PROFILE PAGE — full-page layout inside .main
   ═══════════════════════════════════════════════ */

.pf-page {
    max-width: 780px;
    margin: 0 auto 80px;
    padding: 0 4px;
}

/* ── Hero banner ── */
.pf-hero {
    background: linear-gradient(135deg, var(--petron-blue,#00264D) 0%, #003a70 60%, #004d99 100%);
    border-radius: 14px 14px 0 0;
    padding: 28px 28px 22px;
    display: flex;
    align-items: center;
    gap: 22px;
    position: relative;
    overflow: hidden;
}
.pf-hero::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 180px; height: 180px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
}
.pf-hero::after {
    content: '';
    position: absolute;
    bottom: -30px; right: 60px;
    width: 120px; height: 120px;
    border-radius: 50%;
    background: rgba(255,255,255,0.03);
}

/* Avatar */
.pf-avatar-wrap { position: relative; flex-shrink: 0; z-index: 1; }
.pf-avatar {
    width: 90px; height: 90px;
    border-radius: 50%;
    border: 3px solid rgba(255,255,255,0.45);
    background: rgba(255,255,255,0.12);
    display: flex; align-items: center; justify-content: center;
    font-size: 38px; color: rgba(255,255,255,0.9);
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(0,0,0,0.25);
}
.pf-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.pf-cam-btn {
    position: absolute; bottom: 2px; right: 2px;
    background: #CC0000; border: 2px solid #fff;
    border-radius: 50%; width: 26px; height: 26px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background 0.2s;
    box-shadow: 0 2px 6px rgba(0,0,0,0.3);
}
.pf-cam-btn:hover { background: #a00000; }
.pf-cam-btn i { color: #fff; font-size: 11px; pointer-events: none; }

/* Hero text */
.pf-hero-info { flex: 1; min-width: 0; z-index: 1; }
.pf-hero-name {
    font-size: 20px; font-weight: 800;
    color: #fff; letter-spacing: 0.5px;
    text-transform: uppercase;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 6px;
    text-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.pf-hero-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.pf-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.25);
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
    color: #fff; letter-spacing: 0.6px;
    backdrop-filter: blur(4px);
}
.pf-badge-station {
    background: rgba(255,255,255,0.1);
    border-color: rgba(255,255,255,0.15);
    font-weight: 500;
}
.pf-hero-meta {
    font-size: 11px; color: rgba(255,255,255,0.6);
    display: flex; align-items: center; gap: 5px;
}

/* ── Body card ── */
.pf-body {
    background: #fff;
    border-radius: 0 0 14px 14px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.09);
    overflow: hidden;
}

/* Tab nav */
.pf-tabs {
    display: flex;
    border-bottom: 2px solid #f0f0f0;
    background: #fafafa;
}
.pf-tab {
    flex: 1; padding: 13px 10px;
    text-align: center; font-size: 12px; font-weight: 700;
    color: #888; cursor: pointer; border: none; background: none;
    border-bottom: 3px solid transparent; margin-bottom: -2px;
    transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px;
    display: flex; align-items: center; justify-content: center; gap: 6px;
}
.pf-tab:hover { color: var(--petron-blue,#00264D); background: rgba(0,38,77,0.04); }
.pf-tab.active {
    color: var(--petron-blue,#00264D);
    border-bottom-color: var(--petron-blue,#00264D);
    background: #fff;
}

/* Tab panels */
.pf-panel { display: none; padding: 22px 24px 24px; }
.pf-panel.active { display: block; }

/* Info rows */
.pf-section-title {
    font-size: 11px; font-weight: 800; color: #aaa;
    text-transform: uppercase; letter-spacing: 0.8px;
    margin: 0 0 12px; padding-bottom: 6px;
    border-bottom: 1px solid #f0f0f0;
    display: flex; align-items: center; gap: 7px;
}
.pf-section-title i { color: var(--petron-blue,#00264D); font-size: 12px; }

.pf-row-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    margin-bottom: 20px;
}
.pf-row {
    display: flex; flex-direction: column;
    padding: 10px 14px 10px 0;
    border-bottom: 1px solid #f5f5f5;
}
.pf-row:nth-child(odd) { padding-right: 20px; border-right: 1px solid #f5f5f5; }
.pf-row:nth-child(even) { padding-left: 20px; }
.pf-row-label {
    font-size: 10px; font-weight: 700; color: #aaa;
    text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;
}
.pf-row-value {
    font-size: 13px; font-weight: 600; color: #1a1a2e;
    word-break: break-word;
}
.pf-row-value.muted { color: #bbb; font-weight: 400; font-style: italic; }
.pf-row-value.role-val { color: var(--petron-blue,#00264D); font-weight: 800; }
.pf-row-value.active-val { color: #28a745; }

/* ── Edit form (tab 2) ── */
.pf-form-section { margin-bottom: 20px; }
.pf-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.pf-fg { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.pf-fg label {
    font-size: 10px; font-weight: 800; color: #888;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.pf-fg label span { color: #cc0000; }
.pf-input {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid #e0e0e0; border-radius: 8px;
    font-size: 13px; color: #1a1a2e;
    box-sizing: border-box;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #fff;
}
.pf-input:focus {
    outline: none;
    border-color: var(--petron-blue,#00264D);
    box-shadow: 0 0 0 3px rgba(0,38,77,0.1);
}
.pf-input[readonly] {
    background: #f5f5f5; color: #aaa;
    cursor: not-allowed; border-color: #eee;
}
.pf-lock-note {
    font-size: 10px; color: #bbb;
    display: flex; align-items: center; gap: 4px; margin-top: 2px;
}

/* Buttons */
.pf-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
.pf-btn {
    padding: 9px 20px; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 700; cursor: pointer;
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.2s; text-decoration: none; letter-spacing: 0.2px;
}
.pf-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
.pf-btn:active { transform: translateY(0); }
.pf-btn-primary   { background: var(--petron-blue,#00264D); color: #fff; }
.pf-btn-secondary { background: #6c757d; color: #fff; }
.pf-btn-danger    { background: #CC0000; color: #fff; }
.pf-btn-ghost     { background: transparent; border: 1.5px solid #ddd; color: #555; }
.pf-btn-ghost:hover { border-color: var(--petron-blue,#00264D); color: var(--petron-blue,#00264D); }

/* Alert */
.pf-alert {
    padding: 11px 15px; border-radius: 8px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 10px; font-size: 13px;
    font-weight: 600;
}
.pf-alert.success { background: #d4edda; color: #155724; border: 1px solid #b8dfc4; }
.pf-alert.error   { background: #fde8e8; color: #721c24; border: 1px solid #f5c6cb; }

/* Activity timeline */
.pf-timeline { display: flex; flex-direction: column; gap: 0; }
.pf-tl-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 12px 0; border-bottom: 1px solid #f5f5f5;
}
.pf-tl-item:last-child { border-bottom: none; }
.pf-tl-icon {
    width: 34px; height: 34px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.pf-tl-icon.blue  { background: rgba(0,38,77,0.1);  color: var(--petron-blue,#00264D); }
.pf-tl-icon.green { background: rgba(40,167,69,0.1); color: #28a745; }
.pf-tl-icon.gray  { background: #f0f0f0; color: #888; }
.pf-tl-body { flex: 1; }
.pf-tl-label { font-size: 12px; font-weight: 700; color: #333; margin-bottom: 2px; }
.pf-tl-value { font-size: 12px; color: #666; }

/* Responsive */
@media (max-width: 600px) {
    .pf-hero { flex-direction: column; text-align: center; }
    .pf-hero-badges { justify-content: center; }
    .pf-row-grid { grid-template-columns: 1fr; }
    .pf-row:nth-child(odd) { padding-right: 0; border-right: none; }
    .pf-row:nth-child(even) { padding-left: 0; }
    .pf-form-grid { grid-template-columns: 1fr; }
    .pf-hero-name { font-size: 16px; }
    .pf-tabs .pf-tab span.tab-label { display: none; }
}
</style>

<?php if ($msg): ?>
<div class="pf-alert <?php echo $msg_type; ?>" id="pfAlert">
    <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
    <?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>

<div class="pf-page">

    <!-- ── Hero Banner ── -->
    <div class="pf-hero">
        <div class="pf-avatar-wrap">
            <div class="pf-avatar">
                <?php if ($pic_url): ?>
                    <img src="<?php echo htmlspecialchars($pic_url); ?>" alt="Profile picture">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <label class="pf-cam-btn" for="picInput" title="Change profile picture">
                <i class="fas fa-camera"></i>
            </label>
        </div>
        <div class="pf-hero-info">
            <div class="pf-hero-name"><?php echo htmlspecialchars(strtoupper($disp_full)); ?></div>
            <div class="pf-hero-badges">
                <span class="pf-badge">
                    <i class="fas fa-shield-alt"></i>
                    <?php echo htmlspecialchars($disp_role); ?>
                </span>
                <?php if ($station_name): ?>
                <span class="pf-badge pf-badge-station">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($station_name); ?>
                </span>
                <?php endif; ?>
                <span class="pf-badge" style="background:rgba(40,167,69,0.25);border-color:rgba(40,167,69,0.3);">
                    <i class="fas fa-circle" style="font-size:7px;color:#6dff9a;"></i> Active
                </span>
            </div>
            <div class="pf-hero-meta">
                <i class="fas fa-id-badge"></i> User #<?php echo (int)$me['id']; ?>
                &nbsp;·&nbsp;
                <i class="fas fa-clock"></i> Last login: <?php echo htmlspecialchars($last_login); ?>
            </div>
        </div>
    </div>

    <!-- Hidden picture upload form -->
    <form method="post" enctype="multipart/form-data" id="picForm" style="display:none;">
        <input type="hidden" name="action" value="upload_picture">
        <input type="file" id="picInput" name="profile_picture"
               accept="image/jpeg,image/png,image/gif,image/webp"
               onchange="document.getElementById('picForm').submit();">
    </form>

    <!-- ── Body Card ── -->
    <div class="pf-body">

        <!-- Tab Navigation -->
        <div class="pf-tabs" role="tablist">
            <button class="pf-tab active" onclick="switchTab('info')" id="tab-info" role="tab" aria-selected="true">
                <i class="fas fa-id-card"></i>
                <span class="tab-label">Profile Info</span>
            </button>
            <button class="pf-tab" onclick="switchTab('edit')" id="tab-edit" role="tab" aria-selected="false">
                <i class="fas fa-edit"></i>
                <span class="tab-label">Edit Profile</span>
            </button>
            <button class="pf-tab" onclick="switchTab('activity')" id="tab-activity" role="tab" aria-selected="false">
                <i class="fas fa-history"></i>
                <span class="tab-label">Activity</span>
            </button>
        </div>

        <!-- ── TAB 1: Profile Info ── -->
        <div class="pf-panel active" id="panel-info" role="tabpanel">

            <p class="pf-section-title"><i class="fas fa-user"></i> Personal Information</p>
            <div class="pf-row-grid">
                <div class="pf-row">
                    <span class="pf-row-label">First Name</span>
                    <span class="pf-row-value <?php echo $disp_first ? '' : 'muted'; ?>">
                        <?php echo $disp_first ?: 'Not set'; ?>
                    </span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">Last Name</span>
                    <span class="pf-row-value <?php echo $disp_last ? '' : 'muted'; ?>">
                        <?php echo $disp_last ?: 'Not set'; ?>
                    </span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">Username</span>
                    <span class="pf-row-value"><?php echo htmlspecialchars($me['username'] ?? '—'); ?></span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">Email</span>
                    <span class="pf-row-value <?php echo empty($me['email']) ? 'muted' : ''; ?>">
                        <?php echo htmlspecialchars($me['email'] ?? 'Not set'); ?>
                    </span>
                </div>
                <?php if ($has_phone): ?>
                <div class="pf-row">
                    <span class="pf-row-label">Phone</span>
                    <span class="pf-row-value <?php echo empty($me['phone_number']) ? 'muted' : ''; ?>">
                        <?php echo htmlspecialchars($me['phone_number'] ?? 'Not set'); ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <p class="pf-section-title"><i class="fas fa-briefcase"></i> Role &amp; Assignment</p>
            <div class="pf-row-grid">
                <div class="pf-row">
                    <span class="pf-row-label">Role</span>
                    <span class="pf-row-value role-val"><?php echo htmlspecialchars($disp_role); ?></span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">Status</span>
                    <span class="pf-row-value active-val"><i class="fas fa-circle" style="font-size:8px;margin-right:4px;"></i>Active</span>
                </div>
                <?php if ($station_name): ?>
                <div class="pf-row">
                    <span class="pf-row-label">Station</span>
                    <span class="pf-row-value"><?php echo htmlspecialchars($station_name); ?></span>
                </div>
                <?php endif; ?>
                <div class="pf-row">
                    <span class="pf-row-label">User ID</span>
                    <span class="pf-row-value">#<?php echo (int)$me['id']; ?></span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">Account Created</span>
                    <span class="pf-row-value"><?php echo !empty($me['created_at']) ? date('M j, Y', strtotime($me['created_at'])) : 'N/A'; ?></span>
                </div>
                <div class="pf-row">
                    <span class="pf-row-label">IP Address</span>
                    <span class="pf-row-value"><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—'); ?></span>
                </div>
            </div>

            <div class="pf-actions">
                <button class="pf-btn pf-btn-primary" onclick="switchTab('edit')">
                    <i class="fas fa-edit"></i> Edit Profile
                </button>
                <a href="update_password.php" class="pf-btn pf-btn-ghost">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>

        <!-- ── TAB 2: Edit Profile ── -->
        <div class="pf-panel" id="panel-edit" role="tabpanel">

            <form method="post" id="editForm" autocomplete="off">
                <input type="hidden" name="action" value="update_profile">

                <p class="pf-section-title"><i class="fas fa-user-edit"></i> Editable Fields</p>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label for="first_name">First Name <span>*</span></label>
                        <input type="text" id="first_name" name="first_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_first)); ?>"
                               required placeholder="e.g. Maria">
                    </div>
                    <div class="pf-fg">
                        <label for="last_name">Last Name <span>*</span></label>
                        <input type="text" id="last_name" name="last_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_last)); ?>"
                               required placeholder="e.g. Santos">
                    </div>
                    <div class="pf-fg">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="pf-input"
                               value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>"
                               placeholder="email@example.com">
                    </div>
                    <?php if ($has_phone): ?>
                    <div class="pf-fg">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="pf-input"
                               value="<?php echo htmlspecialchars($me['phone_number'] ?? ''); ?>"
                               placeholder="+63 9XX XXX XXXX">
                    </div>
                    <?php endif; ?>
                </div>

                <p class="pf-section-title" style="margin-top:4px;"><i class="fas fa-lock"></i> Read-Only Fields</p>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label>Username</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($me['username'] ?? ''); ?>" readonly>
                        <span class="pf-lock-note"><i class="fas fa-lock"></i> Cannot be changed</span>
                    </div>
                    <div class="pf-fg">
                        <label>Role</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($disp_role); ?>" readonly>
                        <span class="pf-lock-note"><i class="fas fa-lock"></i> Assigned by administrator</span>
                    </div>
                    <?php if ($station_name): ?>
                    <div class="pf-fg">
                        <label>Station Assignment</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($station_name); ?>" readonly>
                        <span class="pf-lock-note"><i class="fas fa-lock"></i> Assigned by administrator</span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="pf-actions">
                    <button type="submit" class="pf-btn pf-btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="pf-btn pf-btn-ghost" onclick="switchTab('info')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- ── TAB 3: Activity ── -->
        <div class="pf-panel" id="panel-activity" role="tabpanel">

            <p class="pf-section-title"><i class="fas fa-history"></i> Account Activity</p>

            <div class="pf-timeline">
                <div class="pf-tl-item">
                    <div class="pf-tl-icon blue"><i class="fas fa-sign-in-alt"></i></div>
                    <div class="pf-tl-body">
                        <div class="pf-tl-label">Last Login</div>
                        <div class="pf-tl-value"><?php echo htmlspecialchars($last_login); ?></div>
                    </div>
                </div>
                <div class="pf-tl-item">
                    <div class="pf-tl-icon green"><i class="fas fa-user-plus"></i></div>
                    <div class="pf-tl-body">
                        <div class="pf-tl-label">Account Created</div>
                        <div class="pf-tl-value"><?php echo !empty($me['created_at']) ? date('F j, Y', strtotime($me['created_at'])) : 'N/A'; ?></div>
                    </div>
                </div>
                <div class="pf-tl-item">
                    <div class="pf-tl-icon gray"><i class="fas fa-network-wired"></i></div>
                    <div class="pf-tl-body">
                        <div class="pf-tl-label">Current IP Address</div>
                        <div class="pf-tl-value"><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—'); ?></div>
                    </div>
                </div>
                <div class="pf-tl-item">
                    <div class="pf-tl-icon blue"><i class="fas fa-shield-alt"></i></div>
                    <div class="pf-tl-body">
                        <div class="pf-tl-label">Role</div>
                        <div class="pf-tl-value" style="font-weight:700;color:var(--petron-blue,#00264D);"><?php echo htmlspecialchars($disp_role); ?></div>
                    </div>
                </div>
                <?php if ($station_name): ?>
                <div class="pf-tl-item">
                    <div class="pf-tl-icon gray"><i class="fas fa-map-marker-alt"></i></div>
                    <div class="pf-tl-body">
                        <div class="pf-tl-label">Station Assignment</div>
                        <div class="pf-tl-value"><?php echo htmlspecialchars($station_name); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="pf-actions" style="margin-top:18px;">
                <a href="update_password.php" class="pf-btn pf-btn-ghost">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>

    </div><!-- /pf-body -->
</div><!-- /pf-page -->

<script>
function switchTab(name) {
    document.querySelectorAll('.pf-tab').forEach(function(t) {
        t.classList.remove('active');
        t.setAttribute('aria-selected', 'false');
    });
    document.querySelectorAll('.pf-panel').forEach(function(p) {
        p.classList.remove('active');
    });
    var tab = document.getElementById('tab-' + name);
    var panel = document.getElementById('panel-' + name);
    if (tab)   { tab.classList.add('active');   tab.setAttribute('aria-selected', 'true'); }
    if (panel) { panel.classList.add('active'); }
}

// Auto-open edit tab if there was a form error
<?php if ($msg_type === 'error' && $msg): ?>
switchTab('edit');
<?php endif; ?>

// Auto-dismiss success alert
setTimeout(function() {
    var a = document.getElementById('pfAlert');
    if (a) { a.style.transition = 'opacity 0.5s'; a.style.opacity = '0'; setTimeout(function(){ a.remove(); }, 500); }
}, 4000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
