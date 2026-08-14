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
$has_phone           = col_exists($pdo, 'users', 'phone_number');
$has_profile_picture = col_exists($pdo, 'users', 'profile_picture');

if (!$has_first_name)      { try { $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT NULL AFTER id");        $has_first_name = true;      } catch (Exception $e) {} }
if (!$has_last_name)       { try { $pdo->exec("ALTER TABLE users ADD COLUMN last_name VARCHAR(100) DEFAULT NULL AFTER first_name"); $has_last_name = true;       } catch (Exception $e) {} }
if (!$has_profile_picture) { try { $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");             $has_profile_picture = true; } catch (Exception $e) {} }

// Refresh current user data from database
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$me['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { 
        unset($row['password_hash']); 
        $me = $row; 
        $_SESSION['user'] = array_merge($_SESSION['user'], $me); 
    }
} catch (Exception $e) {}

$raw_role = strtolower(trim((string)($me['role'] ?? 'staff')));

// Determine standard role details & station details per specification
$station_name = 'Petron Station — Vamenta Blvd.';
$branch_location = 'Vamenta Blvd., Carmen, Cagayan de Oro City, Misamis Oriental';

$role_display_title = 'OPERATIONS STAFF';
$role_badge = 'Operations Staff';
$shift_label = '';
$schedule_label = '';

if ($raw_role === 'admin') {
    $role_display_title = 'ADMIN / OWNER';
    $role_badge = 'Admin / Owner';
} elseif ($raw_role === 'manager') {
    $role_display_title = 'MANAGER';
    $role_badge = 'Manager';
} elseif ($raw_role === 'superadmin' || $raw_role === 'developer') {
    $role_display_title = 'DEVELOPER';
    $role_badge = 'Developer';
} else {
    // Operations Staff Shift Detection
    $shift_val = $me['assigned_shift'] ?? $me['shift_assignment'] ?? '';
    if (stripos($shift_val, '1') !== false) {
        $role_display_title = 'OPERATIONS STAFF — SHIFT 1';
        $role_badge = 'Operations Staff';
        $shift_label = 'Shift 1';
        $schedule_label = '6:00 AM – 2:00 PM';
    } elseif (stripos($shift_val, '2') !== false) {
        $role_display_title = 'OPERATIONS STAFF — SHIFT 2';
        $role_badge = 'Operations Staff';
        $shift_label = 'Shift 2';
        $schedule_label = '2:00 PM – 12:00 MN';
    } else {
        $role_display_title = 'OPERATIONS STAFF';
        $role_badge = 'Operations Staff';
        $shift_label = $shift_val ?: 'Shift 1';
        $schedule_label = (stripos($shift_label, '2') !== false) ? '2:00 PM – 12:00 MN' : '6:00 AM – 2:00 PM';
    }
}

$last_login = 'Never';
try {
    $stmt = $pdo->prepare("SELECT created_at FROM activity_logs WHERE user_id = ? AND (action = 'Login' OR action LIKE '%Login%') ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$me['id']]);
    $lr = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lr) {
        $last_login = date('M j, Y \a\t h:i A', strtotime($lr['created_at']));
    } else {
        $last_login = date('M j, Y \a\t h:i A');
    }
} catch (Exception $e) {
    $last_login = date('M j, Y \a\t h:i A');
}

$msg = ''; $msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Profile Form (Full Name / First Name / Last Name / Phone / Email if permitted)
    if ($action === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $phone      = trim($_POST['phone']      ?? '');

        if ($first_name === '' && $last_name === '') {
            $msg = 'First name or Last name is required.'; 
            $msg_type = 'error';
        } else {
            try {
                $full_name = trim("$first_name $last_name");
                $sets = []; 
                $params = [];

                if ($has_first_name) { $sets[] = "first_name = ?"; $params[] = $first_name; }
                if ($has_last_name)  { $sets[] = "last_name = ?";  $params[] = $last_name;  }
                if ($has_phone)      { $sets[] = "phone_number = ?"; $params[] = $phone; }
                
                if (!empty($sets)) {
                    $params[] = $me['id'];
                    $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
                }

                $_SESSION['user']['name']         = $full_name;
                $_SESSION['user']['first_name']   = $first_name;
                $_SESSION['user']['last_name']    = $last_name;
                if ($has_phone) $_SESSION['user']['phone_number'] = $phone;

                $me['name']         = $full_name; 
                $me['first_name']   = $first_name;
                $me['last_name']    = $last_name; 
                $me['phone_number'] = $phone;

                // Log change to activity and audit logs
                try { 
                    $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Profile Update', 'User updated profile information', ?)")
                        ->execute([$me['id'], $_SERVER['REMOTE_ADDR']]); 
                } catch (Exception $e) {}

                $msg = 'Profile updated successfully!';
            } catch (Exception $e) { 
                $msg = 'Error: ' . $e->getMessage(); 
                $msg_type = 'error'; 
            }
        }
    }

    // 2. Upload / Replace Profile Picture
    if ($action === 'upload_picture') {
        if (!$has_profile_picture) {
            $msg = 'Profile picture feature not available.'; 
            $msg_type = 'error';
        } elseif (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['profile_picture'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
                $msg = 'Only JPG, JPEG, PNG, GIF, or WebP images are allowed.'; 
                $msg_type = 'error';
            } elseif ($file['size'] > 3 * 1024 * 1024) {
                $msg = 'Image must be under 3 MB.'; 
                $msg_type = 'error';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) $ext = 'jpg';
                
                $filename = 'profile_' . $me['id'] . '_' . time() . '.' . $ext;
                $upload_dir = __DIR__ . '/../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $old = $me['profile_picture'] ?? '';
                    if ($old && file_exists(__DIR__ . '/../' . ltrim($old, '/'))) {
                        @unlink(__DIR__ . '/../' . ltrim($old, '/'));
                    }
                    $rel = 'uploads/profiles/' . $filename;
                    $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$rel, $me['id']]);
                    $_SESSION['user']['profile_picture'] = $rel;
                    $me['profile_picture'] = $rel;
                    $msg = 'Profile photo updated successfully!';
                } else { 
                    $msg = 'Failed to save uploaded photo.'; 
                    $msg_type = 'error'; 
                }
            }
        } else {
            $msg = 'No photo uploaded or upload error occurred.'; 
            $msg_type = 'error';
        }
    }

    // 3. Remove Profile Picture (Return to default avatar)
    if ($action === 'remove_picture') {
        $old = $me['profile_picture'] ?? '';
        if ($old && file_exists(__DIR__ . '/../' . ltrim($old, '/'))) {
            @unlink(__DIR__ . '/../' . ltrim($old, '/'));
        }
        $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE id = ?")->execute([$me['id']]);
        $_SESSION['user']['profile_picture'] = null;
        $me['profile_picture'] = null;
        $msg = 'Profile photo removed. Returned to default avatar.';
    }
}

// Prepare display values
$disp_first = htmlspecialchars($me['first_name'] ?? '');
$disp_last  = htmlspecialchars($me['last_name']  ?? '');
if ($disp_first === '' && $disp_last === '' && !empty($me['name'])) {
    $parts = explode(' ', trim($me['name']), 2);
    $disp_first = htmlspecialchars($parts[0] ?? '');
    $disp_last  = htmlspecialchars($parts[1] ?? '');
}
$disp_full = trim(strip_tags($disp_first) . ' ' . strip_tags($disp_last)) ?: htmlspecialchars($me['name'] ?? $me['username'] ?? 'User');

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
   MY PROFILE — COMPLETE SPECIFICATION STYLES
   ═══════════════════════════════════════════════ */

.pf-page {
    max-width: 840px;
    margin: 0 auto 80px;
    padding: 0 10px;
}

/* ── 1. Common Profile Header ── */
.pf-header-card {
    background: linear-gradient(145deg, #002244 0%, #003366 50%, #001a33 100%);
    border-radius: 18px;
    padding: 38px 24px 28px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0, 34, 68, 0.35);
    margin-bottom: 24px;
    border: 1px solid rgba(255, 255, 255, 0.12);
}

.pf-header-card::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 240px; height: 240px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(227, 6, 19, 0.25) 0%, rgba(227, 6, 19, 0) 70%);
    pointer-events: none;
}

.pf-header-card::after {
    content: '';
    position: absolute;
    bottom: -60px; left: -60px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(0, 102, 204, 0.3) 0%, rgba(0, 102, 204, 0) 70%);
    pointer-events: none;
}

/* Profile Image Container */
.pf-avatar-container {
    position: relative;
    margin-bottom: 18px;
    z-index: 2;
}

.pf-avatar-frame {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid #ffffff;
    background: linear-gradient(135deg, #003366, #001a33);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 52px;
    color: rgba(255, 255, 255, 0.85);
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    transition: transform 0.2s ease;
}

.pf-avatar-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
}

.pf-cam-overlay {
    position: absolute;
    bottom: 4px;
    right: 4px;
    background: #E30613;
    border: 2.5px solid #ffffff;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 3px 8px rgba(0,0,0,0.4);
    color: #ffffff;
}

.pf-cam-overlay:hover {
    background: #b8000b;
    transform: scale(1.1);
}

.pf-cam-overlay i {
    font-size: 14px;
    pointer-events: none;
}

/* Header Text */
.pf-full-name {
    font-size: 22px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    margin-bottom: 6px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
    z-index: 2;
}

.pf-role-title {
    font-size: 13.5px;
    font-weight: 700;
    color: #ff9999;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    margin-bottom: 4px;
    z-index: 2;
}

.pf-station-tagline {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
    margin-bottom: 18px;
    z-index: 2;
}

/* Photo Action Buttons */
.pf-photo-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
    z-index: 2;
}

.pf-btn-photo {
    padding: 7px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
}

.pf-btn-photo-change {
    background: #ffffff !important;
    color: #002244 !important;
    box-shadow: 0 3px 10px rgba(0,0,0,0.25) !important;
    border: 1.5px solid rgba(255,255,255,0.9) !important;
}
.pf-btn-photo-change:hover {
    background: #f0f4f8 !important;
    transform: translateY(-1px) !important;
}

.pf-btn-photo-remove {
    background: #E30613 !important;
    color: #ffffff !important;
    border: 1.5px solid rgba(255,255,255,0.3) !important;
    box-shadow: 0 3px 10px rgba(227,6,19,0.35) !important;
}
.pf-btn-photo-remove:hover {
    background: #c0000e !important;
    transform: translateY(-1px) !important;
}

/* ── 2. Body Tabs & Panels ── */
.pf-body-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 6px 25px rgba(0,0,0,0.08);
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.06);
}

.pf-tabs {
    display: flex !important;
    background: #f1f5f9 !important;
    border-bottom: 3px solid #00264D !important;
    border-top: 1px solid #d1d9e6 !important;
    border-left: 1px solid #d1d9e6 !important;
    border-right: 1px solid #d1d9e6 !important;
    overflow: hidden !important;
}

.pf-tab {
    flex: 1 !important;
    padding: 13px 12px !important;
    text-align: center !important;
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: #334155 !important;
    cursor: pointer !important;
    border: none !important;
    border-right: 1px solid #d1d9e6 !important;
    background: #ffffff !important;
    border-bottom: 3px solid transparent !important;
    transition: all 0.15s ease !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
}

.pf-tab:last-child {
    border-right: none !important;
}

.pf-tab:hover {
    background: #f1f5f9 !important;
    color: #00264D !important;
}

.pf-tab.active {
    background: #00264D !important;
    color: #ffffff !important;
    font-weight: 800 !important;
    border-bottom: 3px solid #E30613 !important;
}

.pf-tab i,
.pf-tab span {
    color: inherit !important;
}

.pf-panel {
    display: none;
    padding: 28px 30px 32px;
}
.pf-panel.active {
    display: block;
}

/* Section Titles */
.pf-sec-header {
    font-size: 12px;
    font-weight: 800;
    color: #002244;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid #f0f4f8;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pf-sec-header i {
    color: #E30613;
    font-size: 14px;
}

/* Two-column data table grid */
.pf-info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    margin-bottom: 24px;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    overflow: hidden;
}

.pf-info-row {
    display: flex;
    flex-direction: column;
    padding: 12px 16px;
    background: #ffffff;
    border-bottom: 1px solid #edf2f7;
}

.pf-info-row:nth-child(odd) {
    border-right: 1px solid #edf2f7;
}

.pf-info-row:last-child,
.pf-info-row:nth-last-child(2):nth-child(odd) {
    border-bottom: none;
}

.pf-label {
    font-size: 10.5px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    margin-bottom: 4px;
}

.pf-value {
    font-size: 13.5px;
    font-weight: 600;
    color: #1e293b;
    word-break: break-word;
}

.pf-badge-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #16a34a;
    font-weight: 700;
}
.pf-badge-status i { font-size: 8px; }

.pf-badge-role {
    color: #002244;
    font-weight: 800;
}

/* Edit form */
.pf-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
}

.pf-fg {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.pf-fg label {
    font-size: 11px;
    font-weight: 800;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.pf-fg label span { color: #E30613; }

.pf-input {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #cbd5e1;
    border-radius: 10px;
    font-size: 13.5px;
    color: #1e293b;
    box-sizing: border-box;
    transition: all 0.2s ease;
    background: #ffffff;
}

.pf-input:focus {
    outline: none;
    border-color: #003366;
    box-shadow: 0 0 0 3px rgba(0, 51, 102, 0.12);
}

.pf-input[readonly] {
    background: #f1f5f9;
    color: #64748b;
    cursor: not-allowed;
    border-color: #e2e8f0;
}

.pf-readonly-badge {
    font-size: 10px;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 3px;
    font-weight: 500;
}

/* Modal for Photo Preview */
.pf-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 20px;
    backdrop-filter: blur(4px);
}
.pf-modal-backdrop.open {
    display: flex;
}

.pf-modal-box {
    background: #ffffff;
    border-radius: 16px;
    max-width: 440px;
    width: 100%;
    padding: 24px;
    text-align: center;
    box-shadow: 0 12px 40px rgba(0,0,0,0.4);
    animation: modalPop 0.25s ease;
}
@keyframes modalPop {
    from { opacity: 0; transform: scale(0.92); }
    to   { opacity: 1; transform: scale(1); }
}

.pf-modal-preview-img {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    object-fit: cover;
    margin: 16px auto;
    border: 4px solid #002244;
    display: block;
    box-shadow: 0 4px 16px rgba(0,0,0,0.2);
}

/* Action Buttons */
.pf-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.pf-btn {
    padding: 10px 22px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    text-decoration: none;
    border: none;
}

.pf-btn-primary {
    background: #00264D;
    color: #ffffff;
    border: 1.5px solid #00264D;
    box-shadow: 0 2px 8px rgba(0, 38, 77, 0.18);
}
.pf-btn-primary:hover {
    background: #003d7a;
    border-color: #003d7a;
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(0, 38, 77, 0.28);
}

.pf-btn-secondary {
    background: #ffffff;
    color: #334155;
    border: 1.5px solid #cbd5e1;
}
.pf-btn-secondary:hover {
    background: #e2e8f0;
}

/* Alert Notification */
.pf-alert {
    padding: 12px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13.5px;
    font-weight: 600;
}
.pf-alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.pf-alert.error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

@media (max-width: 650px) {
    .pf-info-grid { grid-template-columns: 1fr; }
    .pf-info-row:nth-child(odd) { border-right: none; }
    .pf-form-grid { grid-template-columns: 1fr; }
}
</style>

<div class="pf-page">

    <?php if ($msg): ?>
    <div class="pf-alert <?php echo $msg_type; ?>" id="pfAlert">
        <i class="fas fa-<?php echo $msg_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo htmlspecialchars($msg); ?></span>
    </div>
    <?php endif; ?>

    <!-- ── 1. COMMON PROFILE HEADER ── -->
    <div class="pf-header-card">
        <div class="pf-avatar-container">
            <div class="pf-avatar-frame" id="headerAvatarFrame" onclick="previewCurrentPhoto()" style="cursor:pointer;" title="Click to view full photo">
                <?php if ($pic_url): ?>
                    <img src="<?php echo htmlspecialchars($pic_url); ?>" alt="<?php echo htmlspecialchars($disp_full); ?>" id="avatarImg">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <label class="pf-cam-overlay" for="photoFileInput" title="Change Profile Photo">
                <i class="fas fa-camera"></i>
            </label>
        </div>

        <div class="pf-full-name"><?php echo htmlspecialchars(strtoupper($disp_full)); ?></div>
        <div class="pf-role-title"><?php echo htmlspecialchars($role_display_title); ?></div>
        <div class="pf-station-tagline">Petron Station &amp; Service Center &nbsp;•&nbsp; <?php echo htmlspecialchars($station_name); ?></div>

        <div class="pf-photo-actions">
            <button type="button" class="pf-btn-photo pf-btn-photo-change" onclick="document.getElementById('photoFileInput').click();">
                <i class="fas fa-image"></i> Change Profile Photo
            </button>
            <?php if ($pic_url): ?>
            <button type="button" class="pf-btn-photo pf-btn-photo-change" onclick="previewCurrentPhoto()">
                <i class="fas fa-eye"></i> Preview Photo
            </button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove your profile photo and return to default avatar?');">
                <input type="hidden" name="action" value="remove_picture">
                <button type="submit" class="pf-btn-photo pf-btn-photo-remove">
                    <i class="fas fa-trash-alt"></i> Remove Photo
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hidden Photo Upload Form -->
    <form method="post" enctype="multipart/form-data" id="photoUploadForm" style="display:none;">
        <input type="hidden" name="action" value="upload_picture">
        <input type="file" id="photoFileInput" name="profile_picture"
               accept=".jpg,.jpeg,.png,.webp"
               onchange="handlePhotoSelected(this);">
    </form>

    <!-- ── 2. PROFILE INFORMATION CARD ── -->
    <div class="pf-body-card">

        <!-- Tabs -->
        <div class="pf-tabs" role="tablist">
            <button class="pf-tab active" onclick="switchProfileTab('info')" id="tab-info" role="tab" aria-selected="true">
                <i class="fas fa-id-card"></i>
                <span>Profile Info</span>
            </button>
            <button class="pf-tab" onclick="switchProfileTab('edit')" id="tab-edit" role="tab" aria-selected="false">
                <i class="fas fa-user-edit"></i>
                <span>Edit Profile</span>
            </button>
            <button class="pf-tab" onclick="switchProfileTab('activity')" id="tab-activity" role="tab" aria-selected="false">
                <i class="fas fa-history"></i>
                <span>Account Activity</span>
            </button>
        </div>

        <!-- ── TAB 1: Profile Information (Summary Grid) ── -->
        <div class="pf-panel active" id="panel-info" role="tabpanel">

            <div class="pf-sec-header">
                <i class="fas fa-user"></i>
                <span>Profile Information</span>
            </div>

            <div class="pf-info-grid">
                <div class="pf-info-row">
                    <span class="pf-label">Profile Photo</span>
                    <span class="pf-value" style="color:#003366;font-weight:700;">
                        <?php echo $pic_url ? 'Custom Photo Uploaded' : 'Default System Avatar'; ?>
                    </span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">First Name</span>
                    <span class="pf-value"><?php echo $disp_first ?: 'Not set'; ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Last Name</span>
                    <span class="pf-value"><?php echo $disp_last ?: 'Not set'; ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Username</span>
                    <span class="pf-value"><?php echo htmlspecialchars($me['username'] ?? '—'); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Role</span>
                    <span class="pf-value pf-badge-role"><?php echo htmlspecialchars($role_badge); ?></span>
                </div>
                <?php if ($shift_label): ?>
                <div class="pf-info-row">
                    <span class="pf-label">Shift</span>
                    <span class="pf-value pf-badge-role"><?php echo htmlspecialchars($shift_label); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Schedule</span>
                    <span class="pf-value"><?php echo htmlspecialchars($schedule_label); ?></span>
                </div>
                <?php endif; ?>
                <div class="pf-info-row">
                    <span class="pf-label">Email Address</span>
                    <span class="pf-value"><?php echo htmlspecialchars($me['email'] ?? 'Not set'); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Contact Number</span>
                    <span class="pf-value"><?php echo htmlspecialchars($me['phone_number'] ?? 'Not set'); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Account Status</span>
                    <span class="pf-value pf-badge-status">
                        <i class="fas fa-circle"></i> Active
                    </span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Last Login</span>
                    <span class="pf-value"><?php echo htmlspecialchars($last_login); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Date Created</span>
                    <span class="pf-value"><?php echo !empty($me['created_at']) ? date('M j, Y', strtotime($me['created_at'])) : 'N/A'; ?></span>
                </div>
            </div>

            <!-- Role-Specific Additional Information -->
            <?php if ($raw_role === 'admin'): ?>
            <div class="pf-sec-header">
                <i class="fas fa-building"></i>
                <span>Admin / Owner Additional Information</span>
            </div>
            <div class="pf-info-grid">
                <div class="pf-info-row">
                    <span class="pf-label">Station / Branch</span>
                    <span class="pf-value">Petron Franchise Branch</span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Branch Location</span>
                    <span class="pf-value"><?php echo htmlspecialchars($branch_location); ?></span>
                </div>
            </div>
            <?php elseif ($raw_role === 'manager'): ?>
            <div class="pf-sec-header">
                <i class="fas fa-building"></i>
                <span>Manager Additional Information</span>
            </div>
            <div class="pf-info-grid">
                <div class="pf-info-row" style="grid-column: 1 / -1;">
                    <span class="pf-label">Assigned Branch</span>
                    <span class="pf-value"><?php echo htmlspecialchars($station_name); ?></span>
                </div>
            </div>
            <?php endif; ?>

            <div class="pf-actions">
                <button type="button" class="pf-btn pf-btn-primary" onclick="switchProfileTab('edit')">
                    <i class="fas fa-user-edit"></i> Edit Profile
                </button>
                <a href="update_password.php" class="pf-btn pf-btn-secondary">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>

        <!-- ── TAB 2: Edit Profile ── -->
        <div class="pf-panel" id="panel-edit" role="tabpanel">

            <form method="post" id="editProfileForm" autocomplete="off">
                <input type="hidden" name="action" value="update_profile">

                <div class="pf-sec-header">
                    <i class="fas fa-edit"></i>
                    <span>Editable Information</span>
                </div>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label for="first_name">First Name <span>*</span></label>
                        <input type="text" id="first_name" name="first_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_first)); ?>"
                               required placeholder="e.g. Romeca Katherine Jane">
                    </div>
                    <div class="pf-fg">
                        <label for="last_name">Last Name <span>*</span></label>
                        <input type="text" id="last_name" name="last_name" class="pf-input"
                               value="<?php echo htmlspecialchars(strip_tags($disp_last)); ?>"
                               required placeholder="e.g. Tello Pepito">
                    </div>
                    <div class="pf-fg" style="grid-column: 1 / -1;">
                        <label for="phone">Contact Number <span>*</span></label>
                        <input type="text" id="phone" name="phone" class="pf-input"
                               value="<?php echo htmlspecialchars($me['phone_number'] ?? ''); ?>"
                               placeholder="+63 917 791 8140">
                    </div>
                </div>

                <div class="pf-sec-header" style="margin-top: 10px;">
                    <i class="fas fa-lock"></i>
                    <span>System Controlled / Read-Only Information</span>
                </div>

                <div class="pf-form-grid">
                    <div class="pf-fg">
                        <label>Username</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($me['username'] ?? ''); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Cannot be changed directly</span>
                    </div>
                    <div class="pf-fg">
                        <label>Email Address</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Read-only (requires verification to change)</span>
                    </div>
                    <div class="pf-fg">
                        <label>Role</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($role_badge); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Assigned by System Administrator</span>
                    </div>
                    <?php if ($shift_label): ?>
                    <div class="pf-fg">
                        <label>Shift Assignment</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($shift_label . ' (' . $schedule_label . ')'); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Controlled by Manager / Admin</span>
                    </div>
                    <?php endif; ?>
                    <div class="pf-fg">
                        <label>Branch / Station</label>
                        <input type="text" class="pf-input" value="<?php echo htmlspecialchars($station_name); ?>" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Station assignment</span>
                    </div>
                    <div class="pf-fg">
                        <label>Account Status</label>
                        <input type="text" class="pf-input" value="Active" readonly>
                        <span class="pf-readonly-badge"><i class="fas fa-lock"></i> Active account</span>
                    </div>
                </div>

                <div class="pf-actions">
                    <button type="submit" class="pf-btn pf-btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" class="pf-btn pf-btn-secondary" onclick="switchProfileTab('info')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- ── TAB 3: Account Activity ── -->
        <div class="pf-panel" id="panel-activity" role="tabpanel">
            <div class="pf-sec-header">
                <i class="fas fa-history"></i>
                <span>Recent System Activity</span>
            </div>

            <div class="pf-info-grid" style="grid-template-columns: 1fr;">
                <div class="pf-info-row">
                    <span class="pf-label">Last Login</span>
                    <span class="pf-value"><?php echo htmlspecialchars($last_login); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Date Created</span>
                    <span class="pf-value"><?php echo !empty($me['created_at']) ? date('M j, Y', strtotime($me['created_at'])) : 'N/A'; ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Current IP Address</span>
                    <span class="pf-value"><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '::1'); ?></span>
                </div>
                <div class="pf-info-row">
                    <span class="pf-label">Role Category</span>
                    <span class="pf-value pf-badge-role"><?php echo htmlspecialchars($role_badge); ?></span>
                </div>
            </div>

            <div class="pf-actions">
                <a href="update_password.php" class="pf-btn pf-btn-primary">
                    <i class="fas fa-key"></i> Change Password
                </a>
            </div>
        </div>

    </div>
</div>

<!-- ── Photo Preview Modal ── -->
<div class="pf-modal-backdrop" id="photoPreviewModal">
    <div class="pf-modal-box">
        <h3 style="margin:0 0 10px;font-size:16px;color:#002244;font-weight:800;">PROFILE PHOTO PREVIEW</h3>
        <img src="<?php echo htmlspecialchars($pic_url ?: '../assets/img/default-avatar.png'); ?>" alt="Profile Preview" class="pf-modal-preview-img" id="modalPreviewImg">
        <p style="font-size:12px;color:#64748b;margin:0 0 16px;">Allowed formats: JPG, JPEG, PNG, WebP</p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button type="button" class="pf-btn pf-btn-primary" onclick="document.getElementById('photoFileInput').click();closePreviewModal();">
                <i class="fas fa-upload"></i> Upload / Replace
            </button>
            <button type="button" class="pf-btn pf-btn-secondary" onclick="closePreviewModal();">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function switchProfileTab(name) {
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

function previewCurrentPhoto() {
    document.getElementById('photoPreviewModal').classList.add('open');
}

function closePreviewModal() {
    document.getElementById('photoPreviewModal').classList.remove('open');
}

function handlePhotoSelected(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        var validTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!validTypes.includes(file.type)) {
            alert('Please select a valid image file (JPG, JPEG, PNG, or WebP).');
            return;
        }
        if (file.size > 3 * 1024 * 1024) {
            alert('File size exceeds 3MB limit.');
            return;
        }
        document.getElementById('photoUploadForm').submit();
    }
}

// Close modal on click outside
document.getElementById('photoPreviewModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePreviewModal();
    }
});

// Auto-dismiss alert banner
setTimeout(function() {
    var a = document.getElementById('pfAlert');
    if (a) {
        a.style.transition = 'opacity 0.5s ease';
        a.style.opacity = '0';
        setTimeout(function(){ a.remove(); }, 500);
    }
}, 4000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
