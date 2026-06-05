<?php
session_start();
ob_start();

// Include database connection
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/../backend/lib.php';

$message = '';
$message_type = '';
$error = '';

// Prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['temp_2fa_user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['temp_2fa_user_id'];
$login_type = $_SESSION['temp_2fa_login_type'] ?? 'Username';

// Fetch user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header("Location: login.php");
        exit;
    }
} catch (Exception $e) {
    error_log("DB Error: " . $e->getMessage());
    header("Location: login.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');
    
    if (empty($otp)) {
        $error = "Please enter the 6-digit OTP.";
    } elseif (strlen($otp) !== 6 || !is_numeric($otp)) {
        $error = "Please enter a valid 6-digit OTP.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT prt.token, prt.expires_at, prt.is_used
                FROM password_reset_tokens prt
                WHERE prt.token = ? AND prt.token_type = 'login' AND prt.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$otp, $user_id]);
            $token_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$token_data) {
                $error = "Invalid OTP. Please check the code and try again.";
            } elseif (strtotime($token_data['expires_at']) < time()) {
                $error = "OTP has expired. Please login again.";
                // Clean up expired token
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token_type = 'login'")->execute([$user_id]);
            } else {
                // OTP is valid! Proceed with full login
                
                // Clear the token
                $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = ? AND token_type = 'login'")->execute([$user_id]);

                // Update last login
                try {
                    $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$user['id']]);
                } catch (Exception $e) { /* ignore */ }

                // Normal login success session
                unset($user['password']);
                $_SESSION['user'] = $user;
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                
                unset($_SESSION['temp_2fa_user_id']);
                unset($_SESSION['temp_2fa_login_type']);

                try {
                    // Check if activity_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Login', ?, ?)");
                        $logStmt->execute([$user['id'], "User logged in via {$login_type} (2FA Verified)", $_SERVER['REMOTE_ADDR']]);
                    }

                    // Check if audit_logs table exists before inserting
                    $tables = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->fetchAll();
                    if (!empty($tables)) {
                        $login_name   = $user['name'] ?? $user['username'] ?? 'Unknown';
                        $login_role   = ucfirst(strtolower($user['role'] ?? 'staff'));
                        $login_detail = "{$login_name} ({$login_role}) logged in via {$login_type} (2FA Verified)";
                        $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, log_type, action_type, action_details, entity_type, entity_id, status, ip_address, user_agent, created_at) VALUES (?, 'user', 'Login', ?, 'users', ?, 'Success', ?, ?, NOW())");
                        $auditStmt->execute([
                            $user['id'],
                            $login_detail,
                            $user['id'],
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null,
                        ]);
                    }
                } catch (Exception $e) { /* Fail silently if logs table missing */ }

                // Auto Clock In for staff roles on login
                $role = role_key($user['role'] ?? '');
                $staff_roles = ['staff'];
                if (in_array($role, $staff_roles)) {
                    try {
                        $station_id = $user['station_id'] ?? null;
                        // Only clock in if not already clocked in
                        $check = $pdo->prepare("SELECT id FROM labor_sessions WHERE user_id = ? AND end_time IS NULL");
                        $check->execute([$user['id']]);
                        if (!$check->fetch() && $station_id) {
                            // Determine current shift
                            $sp = $pdo->prepare(
                                "SELECT shift_key, shift_name FROM shift_periods
                                 WHERE is_active = 1 AND start_time <= TIME(NOW()) AND end_time >= TIME(NOW())
                                 ORDER BY sort_order ASC LIMIT 1"
                            );
                            $sp->execute();
                            $shift = $sp->fetch(PDO::FETCH_ASSOC);
                            if (!$shift) {
                                // Fallback: use the last active shift
                                $sp2 = $pdo->query(
                                    "SELECT shift_key, shift_name FROM shift_periods
                                     WHERE is_active = 1 ORDER BY sort_order DESC LIMIT 1"
                                 );
                                $shift = $sp2 ? $sp2->fetch(PDO::FETCH_ASSOC) : null;
                            }
                            if (!$shift) {
                                $shift = ['shift_key' => 'first', 'shift_name' => 'First Shift'];
                            }
                            $pdo->prepare(
                                "INSERT INTO labor_sessions (user_id, station_id, start_time, shift_period, shift_name)
                                 VALUES (?, ?, NOW(), ?, ?)"
                            )->execute([$user['id'], $station_id, $shift['shift_key'], $shift['shift_name']]);
                            // Log the auto clock-in
                            $tables = $pdo->query("SHOW TABLES LIKE 'activity_logs'")->fetchAll();
                            if (!empty($tables)) {
                                $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Clock In', ?, ?)")
                                    ->execute([$user['id'], "Auto clock-in on login - Station {$station_id} - {$shift['shift_name']}", $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
                            }
                        }
                    } catch (Exception $e) { /* Fail silently, do not block login */ }
                }

                // RBAC Redirect Logic
                if ($role === 'superadmin') {
                    header("Location: super_admin_dashboard.php");
                } elseif ($role === 'admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($role === 'manager') {
                    header("Location: manager_dashboard.php");
                } else {
                    header("Location: staff_dashboard.php");
                }
                exit;
            }
        } catch (PDOException $e) {
            error_log("OTP validation error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Login OTP | Petron Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root {
            --blue-glow: rgba(0, 100, 255, 0.45);
            --red-glow: rgba(227, 6, 19, 0.35);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: url('../assets/img/background.jpg') center center / cover no-repeat;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            position: relative;
        }

        .login-wrap {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 2;
        }

        .login-card {
            background: rgba(0, 15, 45, 0.9);
            backdrop-filter: blur(24px) saturate(1.8) brightness(1.05);
            -webkit-backdrop-filter: blur(24px) saturate(1.8) brightness(1.05);
            width: 100%;
            max-width: 520px;
            border-radius: 28px;
            padding: 48px 40px 36px;
            box-shadow: 
                0 4px 0 rgba(255,255,255,.05) inset, 
                0 -2px 0 rgba(0,0,0,.6) inset, 
                0 12px 40px rgba(0,0,0,.6), 
                0 32px 80px rgba(0,0,0,.65), 
                0 0 0 1px rgba(255,255,255,.08), 
                0 0 50px var(--blue-glow);
            position: relative;
            animation: cardGlowFlow 8s linear infinite;
        }

        .login-card::before {
            content: '';
            position: absolute;
            inset: -1.5px;
            border-radius: 29px;
            background: linear-gradient(90deg, #002F6C, #E30613, #002F6C);
            background-size: 200% auto;
            animation: borderFlow 6s linear infinite;
            z-index: -1;
            opacity: 0.85;
        }

        @keyframes borderFlow {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        @keyframes cardGlowFlow {
            0%, 100% { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 50px var(--blue-glow); }
            50% { box-shadow: 0 4px 0 rgba(255,255,255,.05) inset, 0 -2px 0 rgba(0,0,0,.6) inset, 0 12px 40px rgba(0,0,0,.6), 0 32px 80px rgba(0,0,0,.65), 0 0 0 1px rgba(255,255,255,.08), 0 0 60px var(--red-glow); }
        }

        .brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 32px;
            text-align: center;
        }

        .brand-logo {
            width: 88px;
            height: auto;
            object-fit: contain;
            margin-bottom: 16px;
            filter: drop-shadow(0 8px 16px rgba(0,0,0,.6));
            animation: logoFloat 6s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); filter: drop-shadow(0 8px 16px rgba(0,0,0,.6)); }
            50% { transform: translateY(-8px); filter: drop-shadow(0 14px 20px rgba(0,0,0,.4)); }
        }

        .brand-title {
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: .5px;
            text-shadow: 0 2px 4px rgba(0,0,0,.6);
            margin-bottom: 4px;
        }

        .brand-tagline {
            color: rgba(255,255,255,.8);
            font-size: 13.5px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            text-shadow: 0 1px 3px rgba(0,0,0,.6);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,.3);
            border-left: 4px solid transparent;
        }

        .alert-error {
            background: rgba(227, 6, 19, 0.15);
            color: #ffb3b3;
            border-color: #E30613;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            color: #a7f3d0;
            border-color: #10b981;
        }
        
        .alert-info {
            background: rgba(0, 47, 108, 0.4);
            color: #bfdbfe;
            border-color: #3b82f6;
            margin-bottom: 30px;
        }

        .field-group {
            margin-bottom: 24px;
            position: relative;
        }

        .input-wrap {
            position: relative;
            display: flex;
            align-items: center;
            border-radius: 14px;
            background: rgba(0,0,0,.45);
            border: 1.5px solid rgba(255,255,255,.15);
            box-shadow: 0 2px 6px rgba(0,0,0,.35) inset;
            transition: border-color .25s, box-shadow .25s;
        }

        .input-wrap:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 14px rgba(59,130,246,.5), 0 2px 6px rgba(0,0,0,.3) inset;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            color: rgba(255,255,255,.92);
            font-size: 16px;
            pointer-events: none;
            transition: color .2s, text-shadow .2s;
            z-index: 2;
            text-shadow: 0 0 10px rgba(255,255,255,.5), 0 1px 3px rgba(0,0,0,.6);
        }

        .input-wrap:focus-within .input-icon {
            color: #ffffff;
            text-shadow: 0 0 16px rgba(96,165,250,.9), 0 1px 3px rgba(0,0,0,.6);
        }

        .field-input {
            width: 100%;
            height: 48px;
            background: transparent;
            border: none;
            outline: none;
            padding: 0 16px 0 46px;
            color: #ffffff;
            font-family: inherit;
            font-size: 18px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 4px;
        }

        .field-input::placeholder {
            color: rgba(255,255,255,.35);
            font-weight: 500;
            letter-spacing: normal;
        }

        .btn {
            width: 100%;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #002F6C 0%, #0047a3 100%);
            color: #fff;
            border: 1px solid rgba(255,255,255,.1);
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,.4), 0 2px 0 rgba(255,255,255,.15) inset;
            transition: transform .2s, box-shadow .2s, background .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,.5), 0 0 20px rgba(0,100,255,.4), 0 2px 0 rgba(255,255,255,.2) inset;
            background: linear-gradient(135deg, #003d8a 0%, #0056cc 100%);
        }

        .btn:active {
            transform: translateY(1px);
            box-shadow: 0 2px 6px rgba(0,0,0,.4), 0 2px 0 rgba(0,0,0,.2) inset;
        }

        .links {
            margin-top: 28px;
            display: flex;
            justify-content: center;
            gap: 20px;
            font-size: 13.5px;
            font-weight: 600;
        }

        .link-item {
            color: rgba(255,255,255,.75);
            text-decoration: none;
            transition: color .2s, text-shadow .2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .link-item:hover {
            color: #ffffff;
            text-shadow: 0 0 12px rgba(255,255,255,.6);
        }
        
        .timer-text {
            text-align: center;
            font-size: 13px;
            color: rgba(255,255,255,.7);
            margin-top: 15px;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <div class="login-wrap">
        <div class="login-card">
            
            <div class="brand">
                <img src="../assets/img/Petron Logo.png" alt="Petron Logo" class="brand-logo" onerror="this.src='../assets/img/default-logo.png'">
                <div class="brand-title">PETRON</div>
                <div class="brand-tagline">Station Management System</div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                <i class="fas fa-shield-halved"></i>
                For security, please verify your login. We've sent a 6-digit OTP code to your registered contact.
            </div>

            <form action="" method="POST" id="otpForm">
                
                <div class="field-group">
                    <div class="input-wrap">
                        <i class="fas fa-key input-icon"></i>
                        <input type="text" 
                               name="otp" 
                               class="field-input" 
                               placeholder="------" 
                               maxlength="6" 
                               autocomplete="one-time-code" 
                               inputmode="numeric" 
                               pattern="\d{6}"
                               required>
                    </div>
                </div>

                <button type="submit" class="btn">
                    Verify &amp; Login <i class="fas fa-arrow-right"></i>
                </button>
                
                <div class="timer-text">
                    Code expires in <span id="timer">05:00</span>
                </div>
            </form>

            <div class="links">
                <a href="login.php?logout=1" class="link-item">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>

        </div>
    </div>

    <script>
        // Simple countdown timer (5 minutes)
        let timeLeft = 300;
        const timerEl = document.getElementById('timer');
        
        const countdown = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(countdown);
                timerEl.textContent = "Expired";
                timerEl.style.color = "#ffb3b3";
            } else {
                let m = Math.floor(timeLeft / 60);
                let s = timeLeft % 60;
                timerEl.textContent = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
                timeLeft--;
            }
        }, 1000);
    </script>
</body>
</html>
