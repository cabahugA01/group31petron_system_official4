<?php
$page_id = 'update_password';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me    = current_user();
$msg   = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password))                        throw new Exception('Current password is required.');
        if (empty($new_password))                            throw new Exception('New password is required.');
        if (strlen($new_password) < 8)                       throw new Exception('New password must be at least 8 characters.');
        if ($new_password !== $confirm_password)             throw new Exception('New passwords do not match.');
        if ($current_password === $new_password)             throw new Exception('New password must be different from current password.');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$me['id']]);
        $row = $stmt->fetch();
        if (!$row)                                           throw new Exception('User not found.');
        if (!password_verify($current_password, $row['password'])) throw new Exception('Current password is incorrect.');

        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($new_password, PASSWORD_DEFAULT), $me['id']]);

        try { log_activity($pdo, $me['id'], 'Change Password', 'User changed their own password'); } catch (Exception $e) {}

        $msg = 'Password changed successfully!';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Derive display name for header
$disp_first = trim($me['first_name'] ?? '');
$disp_last  = trim($me['last_name']  ?? '');
if ($disp_first === '' && $disp_last === '' && !empty($me['name'])) {
    $parts = explode(' ', trim($me['name']), 2);
    $disp_first = $parts[0] ?? '';
    $disp_last  = $parts[1] ?? '';
}
$disp_full = trim("$disp_first $disp_last") ?: ($me['name'] ?? $me['username'] ?? 'User');
$disp_role = strtoupper(normalize_role($me['role'] ?? 'Staff'));

include __DIR__ . '/../partials/header.php';
?>
<style>
/* ═══════════════════════════════════════════
   CHANGE PASSWORD PAGE — dark blue theme
   ═══════════════════════════════════════════ */
.cp-page {
    max-width: 520px;
    margin: 0 auto 80px;
    padding: 0 4px;
}

/* ── Header banner ── */
.cp-banner {
    background: linear-gradient(135deg, #00264D 0%, #003a70 60%, #004d99 100%);
    border-radius: 14px 14px 0 0;
    padding: 24px 28px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    overflow: hidden;
}
.cp-banner::before {
    content: '';
    position: absolute; top: -30px; right: -30px;
    width: 130px; height: 130px; border-radius: 50%;
    background: rgba(255,255,255,0.05);
}
.cp-banner-icon {
    width: 52px; height: 52px; border-radius: 14px;
    background: rgba(255,255,255,0.15);
    border: 1.5px solid rgba(255,255,255,0.25);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #fff; flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    z-index: 1;
}
.cp-banner-text { z-index: 1; }
.cp-banner-title {
    font-size: 18px; font-weight: 800; color: #fff;
    letter-spacing: 0.3px; margin-bottom: 3px;
    text-transform: uppercase;
}
.cp-banner-sub {
    font-size: 12px; color: rgba(255,255,255,0.65);
    display: flex; align-items: center; gap: 6px;
}
.cp-banner-sub strong { color: rgba(255,255,255,0.9); }

/* ── Card body ── */
.cp-body {
    background: #fff;
    border-radius: 0 0 14px 14px;
    box-shadow: 0 6px 24px rgba(0,0,0,0.09);
    padding: 26px 28px 28px;
}

/* ── Alert ── */
.cp-alert {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 13px 16px; border-radius: 10px;
    margin-bottom: 20px; font-size: 13px; font-weight: 600;
    border: 1.5px solid;
}
.cp-alert-icon {
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; flex-shrink: 0;
}
.cp-alert.success {
    background: #edfaf2; color: #155724;
    border-color: #b8dfc4;
}
.cp-alert.success .cp-alert-icon { background: #28a745; color: #fff; }
.cp-alert.error {
    background: #fff0f0; color: #721c24;
    border-color: #f5c6cb;
}
.cp-alert.error .cp-alert-icon { background: #CC0000; color: #fff; }

/* ── Field group ── */
.cp-fg { margin-bottom: 18px; }
.cp-fg label {
    display: block; margin-bottom: 6px;
    font-size: 11px; font-weight: 800; color: #00264D;
    text-transform: uppercase; letter-spacing: 0.6px;
}
.cp-input-wrap {
    position: relative; display: flex; align-items: center;
}
.cp-input-icon {
    position: absolute; left: 13px;
    color: #00264D; font-size: 14px; opacity: 0.5;
    pointer-events: none;
}
.cp-input {
    width: 100%; padding: 11px 42px 11px 38px;
    border: 1.5px solid #d0d8e4; border-radius: 9px;
    font-size: 14px; color: #1a1a2e;
    box-sizing: border-box;
    background: #f8fafd;
    transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
.cp-input:focus {
    outline: none;
    border-color: #00264D;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0,38,77,0.12);
}
.cp-input.valid   { border-color: #28a745; background: #fff; }
.cp-input.invalid { border-color: #CC0000; background: #fff; }

.cp-eye {
    position: absolute; right: 12px;
    background: none; border: none; cursor: pointer;
    color: #888; font-size: 14px; padding: 4px;
    transition: color 0.2s;
}
.cp-eye:hover { color: #00264D; }

/* ── Strength bar ── */
.cp-strength { margin-top: 8px; }
.cp-strength-bar {
    height: 4px; border-radius: 4px;
    background: #e9ecef; overflow: hidden; margin-bottom: 4px;
}
.cp-strength-fill {
    height: 100%; border-radius: 4px;
    transition: width 0.3s ease, background 0.3s ease;
    width: 0%;
}
.cp-strength-label {
    font-size: 11px; font-weight: 600; color: #888;
}

/* ── Requirements checklist ── */
.cp-reqs {
    background: #f4f7fb;
    border: 1.5px solid #dce4f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 22px;
}
.cp-reqs-title {
    font-size: 11px; font-weight: 800; color: #00264D;
    text-transform: uppercase; letter-spacing: 0.5px;
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.cp-req-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
.cp-req {
    display: flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 600; color: #888;
    transition: color 0.2s;
}
.cp-req-dot {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid #ccc;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; color: transparent;
    transition: all 0.2s; flex-shrink: 0;
}
.cp-req.met { color: #28a745; }
.cp-req.met .cp-req-dot {
    background: #28a745; border-color: #28a745; color: #fff;
}
.cp-req.fail { color: #CC0000; }
.cp-req.fail .cp-req-dot {
    background: #CC0000; border-color: #CC0000; color: #fff;
}

/* ── Divider ── */
.cp-divider {
    height: 1px; background: #eef0f4;
    margin: 22px 0;
}

/* ── Buttons ── */
.cp-btn-row { display: flex; gap: 10px; }
.cp-btn {
    flex: 1; padding: 12px 16px;
    border: none; border-radius: 9px;
    font-size: 14px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all 0.2s; text-decoration: none;
    letter-spacing: 0.2px;
}
.cp-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.15); }
.cp-btn:active { transform: translateY(0); box-shadow: none; }

.cp-btn-primary {
    background: linear-gradient(135deg, #00264D 0%, #003a70 100%);
    color: #fff;
}
.cp-btn-primary:hover { background: linear-gradient(135deg, #003a70 0%, #004d99 100%); }
.cp-btn-primary:disabled {
    background: #b0bec5; cursor: not-allowed;
    transform: none; box-shadow: none;
}

.cp-btn-back {
    background: #f4f7fb;
    color: #00264D;
    border: 1.5px solid #dce4f0;
    flex: 0 0 auto;
    padding: 12px 20px;
}
.cp-btn-back:hover { background: #e8eef7; border-color: #00264D; }
</style>

<div class="cp-page">

    <?php if ($msg): ?>
    <div class="cp-alert success" id="cpAlert">
        <div class="cp-alert-icon"><i class="fas fa-check"></i></div>
        <div><?php echo htmlspecialchars($msg); ?></div>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="cp-alert error" id="cpAlert">
        <div class="cp-alert-icon"><i class="fas fa-exclamation"></i></div>
        <div><?php echo htmlspecialchars($error); ?></div>
    </div>
    <?php endif; ?>

    <!-- Banner -->
    <div class="cp-banner">
        <div class="cp-banner-icon">
            <i class="fas fa-lock"></i>
        </div>
        <div class="cp-banner-text">
            <div class="cp-banner-title">Change Password</div>
            <div class="cp-banner-sub">
                <i class="fas fa-user-circle"></i>
                <strong><?php echo htmlspecialchars(strtoupper($disp_full)); ?></strong>
                &nbsp;·&nbsp; <?php echo htmlspecialchars($disp_role); ?>
            </div>
        </div>
    </div>

    <!-- Body -->
    <div class="cp-body">

        <form method="POST" id="cpForm" autocomplete="off" novalidate>

            <!-- Current Password -->
            <div class="cp-fg">
                <label for="current_password">Current Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" id="current_password" name="current_password"
                           class="cp-input" placeholder="Enter your current password" required
                           autocomplete="current-password">
                    <button type="button" class="cp-eye" onclick="toggleEye('current_password','eye0')" tabindex="-1">
                        <i class="fas fa-eye" id="eye0"></i>
                    </button>
                </div>
            </div>

            <div class="cp-divider"></div>

            <!-- New Password -->
            <div class="cp-fg">
                <label for="new_password">New Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-key"></i></span>
                    <input type="password" id="new_password" name="new_password"
                           class="cp-input" placeholder="Enter your new password" required
                           autocomplete="new-password" oninput="checkStrength(); checkReqs();">
                    <button type="button" class="cp-eye" onclick="toggleEye('new_password','eye1')" tabindex="-1">
                        <i class="fas fa-eye" id="eye1"></i>
                    </button>
                </div>
                <!-- Strength bar -->
                <div class="cp-strength">
                    <div class="cp-strength-bar">
                        <div class="cp-strength-fill" id="strengthFill"></div>
                    </div>
                    <span class="cp-strength-label" id="strengthLabel">Enter a password</span>
                </div>
            </div>

            <!-- Confirm Password -->
            <div class="cp-fg">
                <label for="confirm_password">Confirm New Password</label>
                <div class="cp-input-wrap">
                    <span class="cp-input-icon"><i class="fas fa-check-circle"></i></span>
                    <input type="password" id="confirm_password" name="confirm_password"
                           class="cp-input" placeholder="Re-enter your new password" required
                           autocomplete="new-password" oninput="checkReqs();">
                    <button type="button" class="cp-eye" onclick="toggleEye('confirm_password','eye2')" tabindex="-1">
                        <i class="fas fa-eye" id="eye2"></i>
                    </button>
                </div>
            </div>

            <!-- Requirements checklist -->
            <div class="cp-reqs">
                <div class="cp-reqs-title">
                    <i class="fas fa-shield-alt"></i> Password Requirements
                </div>
                <ul class="cp-req-list">
                    <li class="cp-req" id="req-len">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        At least 8 characters long
                    </li>
                    <li class="cp-req" id="req-diff">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Different from current password
                    </li>
                    <li class="cp-req" id="req-match">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Both passwords match
                    </li>
                    <li class="cp-req" id="req-upper">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Contains uppercase letter (recommended)
                    </li>
                    <li class="cp-req" id="req-num">
                        <span class="cp-req-dot"><i class="fas fa-check"></i></span>
                        Contains a number (recommended)
                    </li>
                </ul>
            </div>

            <!-- Buttons -->
            <div class="cp-btn-row">
                <a href="profile.php" class="cp-btn cp-btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <button type="submit" class="cp-btn cp-btn-primary" id="submitBtn">
                    <i class="fas fa-lock"></i> Update Password
                </button>
            </div>

        </form>
    </div><!-- /cp-body -->
</div><!-- /cp-page -->

<script>
/* ── Toggle password visibility ── */
function toggleEye(inputId, iconId) {
    var inp  = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!inp || !icon) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'fas fa-eye';
    }
}

/* ── Password strength ── */
function checkStrength() {
    var val  = document.getElementById('new_password').value;
    var fill = document.getElementById('strengthFill');
    var lbl  = document.getElementById('strengthLabel');
    if (!fill || !lbl) return;

    var score = 0;
    if (val.length >= 8)              score++;
    if (val.length >= 12)             score++;
    if (/[A-Z]/.test(val))            score++;
    if (/[0-9]/.test(val))            score++;
    if (/[^A-Za-z0-9]/.test(val))     score++;

    var pct   = ['0%','20%','40%','65%','85%','100%'][score];
    var color = ['#e0e0e0','#CC0000','#ff8c00','#f0c040','#28a745','#00264D'][score];
    var text  = ['Enter a password','Very Weak','Weak','Fair','Strong','Very Strong'][score];

    fill.style.width     = pct;
    fill.style.background = color;
    lbl.textContent      = text;
    lbl.style.color      = color;
}

/* ── Requirements live check ── */
function checkReqs() {
    var np  = document.getElementById('new_password').value;
    var cp  = document.getElementById('confirm_password').value;
    var cur = document.getElementById('current_password').value;

    setReq('req-len',   np.length >= 8);
    setReq('req-diff',  np.length > 0 && cur.length > 0 && np !== cur);
    setReq('req-match', np.length > 0 && cp.length > 0 && np === cp);
    setReq('req-upper', /[A-Z]/.test(np));
    setReq('req-num',   /[0-9]/.test(np));

    /* Visual feedback on confirm field */
    var conf = document.getElementById('confirm_password');
    if (cp.length > 0) {
        conf.classList.toggle('valid',   np === cp);
        conf.classList.toggle('invalid', np !== cp);
    } else {
        conf.classList.remove('valid','invalid');
    }
}

function setReq(id, met) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('met',  met);
    el.classList.toggle('fail', !met && document.getElementById('new_password').value.length > 0);
    if (!document.getElementById('new_password').value.length) {
        el.classList.remove('fail');
    }
}

/* ── Form submit guard ── */
document.getElementById('cpForm').addEventListener('submit', function(e) {
    var np  = document.getElementById('new_password').value;
    var cp  = document.getElementById('confirm_password').value;
    var cur = document.getElementById('current_password').value;

    if (!cur) { e.preventDefault(); alert('Please enter your current password.'); return; }
    if (np.length < 8) { e.preventDefault(); alert('New password must be at least 8 characters.'); return; }
    if (np !== cp)     { e.preventDefault(); alert('Passwords do not match.'); return; }
    if (np === cur)    { e.preventDefault(); alert('New password must be different from current password.'); return; }

    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
});

/* ── Auto-dismiss success alert ── */
setTimeout(function() {
    var a = document.getElementById('cpAlert');
    if (a && a.classList.contains('success')) {
        a.style.transition = 'opacity 0.5s';
        a.style.opacity = '0';
        setTimeout(function() { a.remove(); }, 500);
    }
}, 5000);
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
