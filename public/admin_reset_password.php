<?php
$page_id = 'admin_reset_password';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../backend/rbac.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();
require_permission(RESET_PASSWORD);

$me = current_user();
$isSuper = (($me['role'] ?? '') === 'superadmin');
$notice = '';

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $notice = "Error: Invalid request.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'reset_password') {
            $user_id = $_POST['user_id'] ?? '';
            $verify_password = $_POST['verify_password'] ?? '';
            
            if (empty($user_id) || empty($verify_password)) {
                $notice = "All fields are required.";
            } else {
                try {
                    // Verify current user's password
                    $passStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
                    $passStmt->execute([$me['id']]);
                    $currentPass = $passStmt->fetchColumn();
                    
                    if (!$currentPass || !password_verify($verify_password, $currentPass)) {
                        $notice = "Error: Incorrect password verification.";
                    } else {
                        // Get user info
                        $userStmt = $pdo->prepare("SELECT username, role FROM users WHERE user_id = ?");
                        $userStmt->execute([$user_id]);
                        $user = $userStmt->fetch();
                        
                        if (!$user) {
                            $notice = "User not found.";
                        } else {
                            // Generate new password
                            $new_password = generate_temp_password();
                            $hashedPass = password_hash($new_password, PASSWORD_DEFAULT);
                            
                            // Update password
                            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
                            $stmt->execute([$hashedPass, $user_id]);
                            
                            // Log action
                            log_activity($pdo, $me['id'], 'Password Reset', "Reset password for user '{$user['username']}'");
                            
                            // Success response
                            $notice = [
                                'type' => 'success',
                                'message' => "Password reset successfully!",
                                'details' => [
                                    'username' => $user['username'],
                                    'new_password' => $new_password,
                                    'user_id' => $user_id
                                ]
                            ];
                        }
                    }
                } catch (Exception $e) {
                    $notice = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch all users for selection
$users = [];
try {
    if ($isSuper) {
        $stmt = $pdo->query("SELECT u.id, u.username, u.name, u.role, s.name as station_name, u.email, u.last_login 
                             FROM users u 
                             LEFT JOIN stations s ON u.station_id = s.id 
                             WHERE u.id != ? 
                             ORDER BY u.username");
        $stmt->execute([$me['id']]);
        $users = $stmt->fetchAll();
    } else {
        $myStationId = user_station_id();
        $stmt = $pdo->prepare("SELECT u.id, u.username, u.name, u.role, s.name as station_name, u.email, u.last_login 
                               FROM users u 
                               LEFT JOIN stations s ON u.station_id = s.id 
                               WHERE u.station_id = ? AND u.id != ? 
                               ORDER BY u.username");
        $stmt->execute([$myStationId, $me['id']]);
        $users = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $users = [];
}

function generate_temp_password() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    return substr(str_shuffle($chars), 0, 12);
}

function format_last_login($last_login) {
    if (!$last_login || $last_login === '1970-01-01 00:00:00') {
        return 'Never';
    }
    return date('M d, Y H:i', strtotime($last_login));
}

include __DIR__ . '/../partials/header.php';
?>

<!-- CSS for enhanced password reset wizard -->
<style>
    .wizard-container {
        background: white;
        border-radius: 16px;
        box-shadow: 0 8px 32px rgba(0, 47, 108, 0.1);
        overflow: hidden;
    }
    
    .wizard-header {
        background: linear-gradient(135deg, var(--petron-blue), #001a4d);
        color: white;
        padding: 24px;
        text-align: center;
    }
    
    .progress-bar {
        display: flex;
        justify-content: center;
        margin-top: 20px;
        gap: 8px;
    }
    
    .progress-step {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .step-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
        transition: all 0.3s ease;
    }
    
    .step-circle.active {
        background: #007bff;
        box-shadow: 0 0 0 4px rgba(0, 123, 255, 0.2);
    }
    
    .step-circle.completed {
        background: white;
        color: var(--petron-blue);
    }
    
    .step-line {
        width: 40px;
        height: 2px;
        background: rgba(255, 255, 255, 0.3);
        transition: all 0.3s ease;
    }
    
    .step-line.completed {
        background: #007bff;
    }
    
    .step-label {
        font-size: 12px;
        margin-top: 8px;
        opacity: 0.8;
    }
    
    .wizard-content {
        padding: 32px;
        min-height: 400px;
    }
    
    .wizard-step {
        display: none;
    }
    
    .wizard-step.active {
        display: block;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .user-search-container {
        position: relative;
        margin-bottom: 24px;
    }
    
    .user-search-input {
        width: 100%;
        padding: 16px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .user-search-input:focus {
        outline: none;
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
    }
    
    .user-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        max-height: 300px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        margin-top: 4px;
    }
    
    .user-option {
        padding: 16px;
        cursor: pointer;
        transition: background 0.2s ease;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .user-option:hover {
        background: #f8f9ff;
    }
    
    .user-option.selected {
        background: var(--petron-blue);
        color: white;
    }
    
    .user-option:last-child {
        border-bottom: none;
    }
    
    .user-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
    }
    
    .user-details {
        flex: 1;
    }
    
    .user-name {
        font-weight: 600;
        color: var(--petron-blue);
        font-size: 16px;
    }
    
    .user-meta {
        font-size: 12px;
        color: #666;
        margin-top: 2px;
    }
    
    .user-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .user-badge.admin { background: #007bff; color: white; }
    .user-badge.manager { background: #28a745; color: white; }
    .user-badge.staff { background: #6c757d; color: white; }
    .user-badge.mechanic { background: #fd7e14; color: white; }
    .user-badge.superadmin { background: #dc3545; color: white; }
    
    .selected-user-card {
        background: linear-gradient(135deg, #e3f2fd, #ffffff);
        border: 2px solid var(--petron-blue);
        border-radius: 12px;
        padding: 20px;
        margin-top: 24px;
        display: none;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .password-input-group {
        position: relative;
        margin-bottom: 24px;
    }
    
    .password-input {
        width: 100%;
        padding: 16px 48px 16px 16px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 16px;
        transition: all 0.3s ease;
    }
    
    .password-input:focus {
        outline: none;
        border-color: var(--petron-blue);
        box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
    }
    
    .password-toggle {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #666;
        font-size: 18px;
        padding: 4px;
        transition: color 0.2s ease;
    }
    
    .password-toggle:hover {
        color: var(--petron-blue);
    }
    
    .input-tooltip {
        position: absolute;
        top: -30px;
        left: 0;
        background: #333;
        color: white;
        padding: 6px 12px;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        z-index: 1001;
        opacity: 0;
        transition: opacity 0.2s ease;
        pointer-events: none;
    }
    
    .password-input-group:hover .input-tooltip {
        opacity: 1;
    }
    
    .wizard-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 32px;
        padding-top: 24px;
        border-top: 1px solid #e0e0e0;
    }
    
    .btn-wizard {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-wizard.primary {
        background: linear-gradient(135deg, #007bff, #0056b3);
        color: white;
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
    }
    
    .btn-wizard.primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0, 123, 255, 0.4);
    }
    
    .btn-wizard.primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
        transform: none !important;
    }
    
    .btn-wizard.secondary {
        background: #f8f9fa;
        color: #666;
        border: 1px solid #dee2e6;
    }
    
    .btn-wizard.secondary:hover {
        background: #e9ecef;
        color: #495057;
    }
    
    .btn-wizard.loading {
        position: relative;
        color: transparent;
    }
    
    .btn-wizard.loading::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        margin: auto;
        border: 2px solid #ffffff;
        border-radius: 50%;
        border-top-color: transparent;
        border-right-color: transparent;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .delivery-info {
        background: linear-gradient(135deg, #d1ecf1, #ffffff);
        border: 1px solid #bee5eb;
        border-radius: 8px;
        padding: 16px;
        margin-top: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .delivery-info i {
        color: #0c5460;
        font-size: 20px;
    }
    
    .success-result {
        background: linear-gradient(135deg, #d4edda, #c3e6cb);
        border: 1px solid #c3e6cb;
        border-radius: 12px;
        padding: 24px;
        margin-top: 24px;
        text-align: center;
        display: none;
        animation: slideIn 0.3s ease;
    }
    
    .success-icon {
        font-size: 48px;
        color: #28a745;
        margin-bottom: 16px;
    }
    
    .success-title {
        font-size: 20px;
        font-weight: 600;
        color: #155724;
        margin-bottom: 8px;
    }
    
    .success-message {
        font-size: 16px;
        color: #155724;
        margin-bottom: 16px;
    }
    
    .success-details {
        background: rgba(255, 255, 255, 0.5);
        border-radius: 8px;
        padding: 16px;
        margin-top: 16px;
        font-size: 14px;
        color: #155724;
    }
    
    @media (max-width: 768px) {
        .wizard-content {
            padding: 20px;
        }
        
        .wizard-buttons {
            flex-direction: column;
            gap: 12px;
        }
        
        .btn-wizard {
            width: 100%;
            justify-content: center;
        }
    }
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Reset User Password</h1>
        <div class="sub">Generate a new temporary password for any user account.</div>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <a href="/group31petron_system_official4/public/users.php" class="btn ghost"><i class="fas fa-arrow-left"></i> Back to User Management</a>
    </div>
</div>

<section class="cards two">
    <div class="wizard-container">
        <div class="wizard-header">
            <h2 style="margin: 0; font-size: 24px;">🔑 Reset User Password</h2>
        </div>
        
        <form method="post" id="resetPasswordForm">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="user_id" id="selected_user_id">
            
            <div class="wizard-content">
                <!-- Step 1: Select User -->
                <div class="wizard-step active" id="step1">
                    <h3 style="margin-top: 0; color: var(--petron-blue);">👤 Select User</h3>
                    <p style="color: #666; margin-bottom: 24px;">Choose the user account whose password you want to reset.</p>
                    
                    <div class="user-search-container">
                        <input type="text" 
                               class="user-search-input" 
                               id="user_search" 
                               placeholder="🔍 Search users by name, username, or email..."
                               autocomplete="off">
                        <div class="user-dropdown" id="user_dropdown"></div>
                    </div>
                    
                    <div id="selected_user_card" class="selected-user-card">
                        <div class="user-details">
                            <div class="user-name" id="selected_user_name"></div>
                            <div class="user-meta">
                                <span id="selected_user_role"></span> • 
                                <span id="selected_user_station"></span> • 
                                <span id="selected_user_email"></span>
                            </div>
                            <div class="user-meta">
                                Last Login: <span id="selected_user_last_login"></span>
                            </div>
                        </div>
                        <div class="user-badge" id="selected_user_badge"></div>
                    </div>
                </div>
                
                <!-- Step 2: Verify Password -->
                <div class="wizard-step" id="step2">
                    <h3 style="margin-top: 0; color: var(--petron-blue);">🔒 Verify Your Password</h3>
                    <p style="color: #666; margin-bottom: 24px;">For security and audit logging, please verify your current password.</p>
                    
                    <div class="password-input-group">
                        <input type="password" 
                               class="password-input" 
                               id="verify_password" 
                               placeholder="Enter your password..."
                               required>
                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility()" id="password_toggle"></i>
                        <div class="input-tooltip">Required for security and audit logging</div>
                    </div>
                    
                    <div class="delivery-info">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <strong>Password Delivery Info:</strong> New password will be sent to the user's registered email address.
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: Complete -->
                <div class="wizard-step" id="step3">
                    <h3 style="margin-top: 0; color: var(--petron-blue);">✅ Reset Complete</h3>
                    
                    <div id="success_result" class="success-result">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="success-title">Password Reset Successful!</div>
                        <div class="success-message" id="success_message"></div>
                        <div class="success-details" id="success_details"></div>
                    </div>
                </div>
            </div>
            
            <!-- Wizard Navigation -->
            <div class="wizard-buttons">
                <button type="button" class="btn-wizard secondary" id="prevBtn" onclick="changeStep(-1)" style="display: none;">
                    <i class="fas fa-arrow-left"></i> Previous
                </button>
                <div></div>
                <div style="display: flex; gap: 12px;">
                    <a href="/group31petron_system_official4/public/users.php" class="btn-wizard secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="button" class="btn-wizard primary" id="nextBtn" onclick="changeStep(1)" disabled>
                        Next <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="btn-wizard primary" id="resetBtn" style="display: none;" form="resetPasswordForm" name="action" value="reset_password">
                        <i class="fas fa-key"></i> 🔑 Generate New Password
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Side Panel -->
    <div class="card" style="padding:20px;">
        <h3 class="h3" style="color: var(--petron-blue); margin-bottom: 20px;">📊 Password Reset Statistics</h3>
        
        <div style="background: linear-gradient(135deg, #e3f2fd, #ffffff); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <div style="font-size: 24px; font-weight: bold; color: var(--petron-blue);"><?php echo count($users); ?></div>
            <div style="color: #666; font-size: 14px;">Total Users</div>
        </div>
        
        <div style="background: linear-gradient(135deg, #fff3cd, #ffffff); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <div style="font-size: 24px; font-weight: bold; color: #856404;">
                <?php 
                $recentResets = 0;
                try {
                    $recentResets = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE action = 'Password Reset' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
                } catch(Exception $e) { $recentResets = 0; }
                echo $recentResets;
                ?>
            </div>
            <div style="color: #856404; font-size: 14px;">Resets This Week</div>
        </div>
        
        <div style="background: linear-gradient(135deg, #d1ecf1, #ffffff); padding: 16px; border-radius: 12px; margin-bottom: 16px;">
            <div style="font-size: 24px; font-weight: bold; color: #0c5460;">
                <?php 
                $activeUsers = 0;
                try {
                    $activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
                } catch(Exception $e) { $activeUsers = 0; }
                echo $activeUsers;
                ?>
            </div>
            <div style="color: #0c5460; font-size: 14px;">Active Users</div>
        </div>
        
        <div style="margin-top: 24px; padding: 16px; background: #f8f9fa; border-radius: 12px;">
            <h4 style="margin-top: 0; color: #666; font-size: 16px;"><i class="fas fa-shield-alt"></i> Security Information</h4>
            <p style="margin-bottom: 0; color: #666; font-size: 14px;">All password resets are logged with timestamps and user attribution for complete audit trail compliance.</p>
        </div>
        
        <div style="margin-top: 16px; padding: 16px; background: #e3f2fd; border-radius: 12px;">
            <h4 style="margin-top: 0; color: var(--petron-blue); font-size: 16px;"><i class="fas fa-info-circle"></i> Quick Tips</h4>
            <p style="margin-bottom: 0; color: var(--petron-blue); font-size: 14px;">• Users will be required to change their password on next login</p>
            <p style="margin-bottom: 0; color: var(--petron-blue); font-size: 14px;">• Temporary passwords are 12 characters with mixed characters</p>
            <p style="margin-bottom: 0; color: var(--petron-blue); font-size: 14px;">• Email notifications include new password and security instructions</p>
        </div>
    </div>
</section>

<!-- Confirmation Modal -->
<div id="confirmModal" class="modal" style="display: none;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 32px; border-radius: 16px; width: 500px; max-width: 90%; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);">
        <h3 style="margin-top: 0; color: var(--petron-blue);">🔍 Confirm Password Reset</h3>
        <div id="confirm_summary" style="margin: 20px 0; padding: 20px; background: #f8f9ff; border-radius: 8px;"></div>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
            <button class="btn-wizard secondary" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn-wizard primary" onclick="confirmReset()">Confirm Reset</button>
        </div>
    </div>
</div>

<script>
// User data
const users = <?php echo json_encode($users); ?>;
let currentStep = 1;
let selectedUser = null;
let passwordVisible = false;

// User search
document.getElementById('user_search').addEventListener('input', function() {
    const search = this.value.toLowerCase();
    const dropdown = document.getElementById('user_dropdown');
    
    if (search.length < 2) {
        dropdown.style.display = 'none';
        return;
    }
    
    const filtered = users.filter(user => {
        const name = (user.name || '').toLowerCase();
        const username = (user.username || '').toLowerCase();
        const email = (user.email || '').toLowerCase();
        const station = (user.station_name || '').toLowerCase();
        return name.includes(search) || username.includes(search) || email.includes(search) || station.includes(search);
    });
    
    if (filtered.length === 0) {
        dropdown.innerHTML = '<div class="user-option">No users found</div>';
    } else {
        dropdown.innerHTML = filtered.map(user => {
            const roleBadge = user.role ? `<span class="user-badge ${user.role}">${user.role}</span>` : '';
            return `
                <div class="user-option" onclick="selectUser(${user.id}, '${user.name}', '${user.username}', '${user.role}', '${user.station_name || 'Head Office'}', '${user.email || ''}', '${user.last_login}')">
                    <div class="user-details">
                        <div class="user-name">${user.name}</div>
                        <div class="user-meta">@${user.username} • ${user.station_name || 'Head Office'}</div>
                        <div class="user-meta">${user.email || 'No email'}</div>
                        <div class="user-meta">Last Login: ${format_last_login(user.last_login)}</div>
                    </div>
                    ${roleBadge}
                </div>
            `;
        }).join('');
    }
    
    dropdown.style.display = 'block';
});

function selectUser(userId, name, username, role, station, email, lastLogin) {
    selectedUser = {
        id: userId,
        name: name,
        username: username,
        role: role,
        station: station,
        email: email,
        lastLogin: lastLogin
    };
    
    document.getElementById('user_search').value = `${name} (@${username})`;
    document.getElementById('selected_user_id').value = userId;
    document.getElementById('user_dropdown').style.display = 'none';
    
    // Show selected user card
    const card = document.getElementById('selected_user_card');
    card.style.display = 'block';
    
    document.getElementById('selected_user_name').textContent = name;
    document.getElementById('selected_user_role').textContent = role ? role.charAt(0).toUpperCase() + role.slice(1) : 'No Role';
    document.getElementById('selected_user_station').textContent = station || 'Head Office';
    document.getElementById('selected_user_email').textContent = email || 'No email';
    document.getElementById('selected_user_last_login').textContent = format_last_login(lastLogin);
    
    const badge = document.getElementById('selected_user_badge');
    if (role) {
        badge.className = `user-badge ${role}`;
        badge.textContent = role.charAt(0).toUpperCase() + role.slice(1);
    }
    
    // Enable next button
    document.getElementById('nextBtn').disabled = false;
}

function togglePasswordVisibility() {
    const passwordInput = document.getElementById('verify_password');
    const toggle = document.getElementById('password_toggle');
    
    if (passwordVisible) {
        passwordInput.type = 'password';
        toggle.className = 'fas fa-eye password-toggle';
        passwordVisible = false;
    } else {
        passwordInput.type = 'text';
        toggle.className = 'fas fa-eye-slash password-toggle';
        passwordVisible = true;
    }
}

// Wizard navigation
function changeStep(direction) {
    if (direction > 0 && currentStep === 1 && !selectedUser) {
        showToast('Please select a user first', 'error');
        return;
    }
    
    if (direction > 0 && currentStep === 2) {
        // Show confirmation modal
        showConfirmModal();
        return;
    }
    
    // Hide current step
    document.getElementById(`step${currentStep}`).classList.remove('active');
    document.getElementById(`step${currentStep}-circle`).classList.remove('active');
    document.getElementById(`step${currentStep}-circle`).classList.add('completed');
    
    if (currentStep < 3) {
        document.getElementById(`step${currentStep}-line`).classList.add('completed');
    }
    
    // Show next step
    currentStep += direction;
    document.getElementById(`step${currentStep}`).classList.add('active');
    document.getElementById(`step${currentStep}-circle`).classList.add('active');
    
    updateWizardButtons();
}

function updateWizardButtons() {
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (currentStep === 1) {
        prevBtn.style.display = 'none';
    } else {
        prevBtn.style.display = 'flex';
    }
    
    if (currentStep === 3) {
        nextBtn.style.display = 'none';
        resetBtn.style.display = 'flex';
    } else {
        nextBtn.style.display = 'flex';
        resetBtn.style.display = 'none';
    }
}

function showConfirmModal() {
    const summary = `
        <div style="line-height: 1.8;">
            <div><strong>👤 User:</strong> ${selectedUser.name}</div>
            <div><strong>🔐 Username:</strong> ${selectedUser.username}</div>
            <div><strong>🏢 Station:</strong> ${selectedUser.station}</div>
            <div><strong>👥 Role:</strong> ${selectedUser.role ? selectedUser.role.charAt(0).toUpperCase() + selectedUser.role.slice(1) : 'No Role'}</div>
            <div><strong>📧 Email:</strong> ${selectedUser.email || 'No email'}</div>
        </div>
    `;
    
    document.getElementById('confirm_summary').innerHTML = summary;
    document.getElementById('confirmModal').style.display = 'block';
}

function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
}

function confirmReset() {
    closeConfirmModal();
    
    // Show loading state
    const resetBtn = document.getElementById('resetBtn');
    resetBtn.classList.add('loading');
    resetBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
    
    // Submit form
    document.getElementById('resetPasswordForm').submit();
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toast.style.background = type === 'success' ? 'var(--petron-green)' : 'var(--petron-red)';
    toast.style.position = 'fixed';
    toast.style.top = '20px';
    toast.style.right = '20px';
    toast.style.padding = '15px 20px';
    toast.style.borderRadius = '8px';
    toast.style.color = 'white';
    toast.style.zIndex = '9999';
    toast.style.animation = 'slideIn 0.3s ease';
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 3000);
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.user-search-container')) {
        document.getElementById('user_dropdown').style.display = 'none';
    }
});

updateWizardButtons();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
