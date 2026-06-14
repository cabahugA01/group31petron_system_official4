<?php
session_start();
require_once 'db_connect.php';
require_once __DIR__ . '/../backend/station_management.php';

// 1. Redirect if already logged in (RBAC Logic)
if (isset($_SESSION['user'])) {
    $role = $_SESSION['user']['role'] ?? 'staff';
    if ($role === 'superadmin') {
        header("Location: dashboard.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

$msg = '';

// Ensure email column exists (Auto-migration for this update)
try {
    $pdo->exec("-- email column already exists");
} catch (PDOException $e) { /* Column likely exists */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']);
    $password = trim($_POST['password_hash']);
    $confirm_password = trim($_POST['confirm_password']);
    $terms = $_POST['terms'] ?? '';
    
    // Default Role Assignment
    $role = 'staff'; 

    if (!empty($username) && !empty($password) && !empty($email) && !empty($full_name)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = "Invalid email address format.";
        } elseif ($password !== $confirm_password) {
            $msg = "Passwords do not match.";
        } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[!@#$%^&*(),.?":{}|<>_\-]/', $password)) {
            $msg = "Password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one symbol.";
        } elseif (empty($terms)) {
            $msg = "You must agree to the Terms & Conditions.";
        } else {
            try {
                // Check if username exists
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->rowCount() > 0) {
                    $msg = "Username already taken.";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Get default station for registration
                    $default_station = StationManager::getDefaultStation();
                    if (!$default_station) {
                        throw new Exception('No active stations available for registration');
                    }
                    
                    $insert = $pdo->prepare("INSERT INTO users (username, password_hash, role, email, first_name, status, station_id) VALUES (?, ?, ?, ?, ?, 'Active', ?)");
                    if ($insert->execute([$username, $hashed_password, $role, $email, $full_name, $default_station])) {
                        // AUTO LOGIN: Diretso na login human register
                        $_SESSION['user_id'] = $pdo->lastInsertId();
                        $_SESSION['username'] = $username;
                        $_SESSION['role'] = $role;

                        // Map DB role to Dashboard role
                        $dashboard_role = 'staff'; // Default for new signups

                        $_SESSION['user'] = [
                            'username' => $username,
                            'role' => $dashboard_role, // Use the mapped role
                            'first_name' => $full_name,
                            'email' => $email,
                            'station_id' => $default_station,
                            'id' => $_SESSION['user_id']
                        ];
                        
                        // Audit Log
                        try {
                            $logStmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, 'Register', 'New user registration', ?)");
                            $logStmt->execute([$_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
                        } catch (Exception $e) {}

                        header("Location: dashboard.php");
                        exit;
                    } else {
                        $msg = "Error registering user.";
                    }
                }
            } catch (PDOException $e) {
                $msg = "Database Error: " . $e->getMessage();
            }
        }
    } else {
        $msg = "All fields are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | Petron Management System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
    <style>
        :root {
            --petron-blue: #002F6C;
            --petron-red: #E30613;
            --petron-gray: #CCCCCC;
            --bg-color: #f4f6f9;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--petron-blue) 0%, #001a4d 100%;
            margin: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: #ffffff;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
        }

        /* Branding */
        .brand-logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 15px;
        }

        .brand-title {
            color: var(--petron-blue);
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .brand-subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            color: #999;
            font-size: 18px;
            z-index: 10;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px 12px 45px; /* Space for icon */
            font-size: 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box;
        }

        .form-control:focus {
            border-color: var(--petron-blue);
            box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.1);
            outline: none;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            background: none;
            border: none;
            cursor: pointer;
            color: #999;
            font-size: 18px;
            padding: 0;
        }

        .toggle-password:hover {
            color: var(--petron-blue);
        }

        /* Checkbox */
        .checkbox-group {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #555;
            margin-bottom: 20px;
        }

        .checkbox-group input {
            margin-right: 10px;
            width: 16px;
            height: 16px;
        }

        /* Button */
        .btn-login {
            width: 100%;
            padding: 14px;
            background-color: var(--petron-blue);
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-login:hover {
            background-color: #001f4d;
        }

        /* Utilities */
        .error-banner {
            background-color: #fde8e8;
            color: var(--petron-red);
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid #fbd5d5;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .links {
            margin-top: 25px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: 14px;
        }

        .links a {
            color: var(--petron-blue);
            text-decoration: none;
            font-weight: 500;
        }

        .links a:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 40px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 12px;
        }

        /* Spinner Animation */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <div class="login-card">
        <!-- Branding -->
        <img src="<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>" alt="Petron logo" class="brand-logo">

        <div class="brand-title">Create Account</div>

        <!-- Error Message -->
        <?php if ($msg): ?>
            <div class="error-banner" role="alert">
                <span><i class="fas fa-exclamation-triangle"></i></span>
                <span><?php echo strip_tags($msg); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="registerForm">
            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-envelope"></i></span>
                    <input type="email" name="email" class="form-control" placeholder="Email Address" required aria-label="Email Address">
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-user"></i></span>
                    <input type="text" name="full_name" class="form-control" placeholder="Full Name" required aria-label="Full Name">
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-id-badge"></i></span>
                    <input type="text" name="username" class="form-control" placeholder="Username" required aria-label="Username">
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" name="password_hash" id="password_hash" class="form-control" placeholder="Password" required aria-label="Password">
                    <button type="button" class="toggle-password" onclick="togglePass('password_hash', this)" aria-label="Show password"><i class="fas fa-eye"></i></button>
                </div>
            </div>

            <div class="form-group">
                <div class="input-group">
                    <span class="input-icon"><i class="fas fa-lock"></i></span>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm Password" required aria-label="Confirm Password">
                    <button type="button" class="toggle-password" onclick="togglePass('confirm_password', this)" aria-label="Show password"><i class="fas fa-eye"></i></button>
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="terms" id="terms" required aria-label="Agree to Terms">
                <label for="terms">I agree to the <a href="#">Terms & Conditions</a> and <a href="#">Privacy Policy</a></label>
            </div>

            <button type="submit" class="btn-login" id="submitBtn">
                <div class="spinner" id="spinner"></div>
                <span id="btnText">Sign Up</span>
            </button>
        </form>

        <div class="links">
            <span>Already have an account? <a href="login.php">Sign In</a></span>
        </div>
    </div>

    <div class="footer">
        &copy; 2026 Petron Station & Service Center Management System. All Rights Reserved.
    </div>

    <script>
        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            const type = input.getAttribute('type') === 'password_hash' ? 'text' : 'password_hash';
            input.setAttribute('type', type);
            btn.innerHTML = type === 'password_hash' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        }

        // Loading State
        const form = document.getElementById('registerForm');
        const submitBtn = document.getElementById('submitBtn');
        const spinner = document.getElementById('spinner');
        const btnText = document.getElementById('btnText');

        form.addEventListener('submit', () => {
            // Disable button and show spinner
            submitBtn.disabled = true;
            spinner.style.display = 'block';
            btnText.textContent = 'Creating Account...';
        });
    </script>

</body>
</html>
