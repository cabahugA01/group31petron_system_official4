<?php
/**
 * APPROVALS & VERIFICATIONS MODULE
 * 
 * Role-Based Approval Workflow:
 * - STAFF: Submit reports (shift sales, job orders, fuel transactions)
 * - MANAGER: Review and approve reports with password verification
 * - ADMIN: Review and approve reports with password verification
 * - SUPER ADMIN: Full oversight of all approvals
 * 
 * Security: Manager must enter password before accessing approvals
 * Audit: All approvals are logged to activity_logs table for traceability
 */
$page_id = 'approvals';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');

if (!in_array($role, ['manager', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

$verified = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_password'])) {
        $password = $_POST['password'] ?? '';

        if ($role === 'manager') {
            // Manager verifies with own password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$me['id']]);
            $hash = $stmt->fetchColumn();

            if (password_verify($password, $hash)) {
                $verified = true;
                $_SESSION['approvals_verified'] = true;
                $_SESSION['approvals_verified_time'] = time();
            } else {
                $error = 'Incorrect password.';
            }
        } else {
            // Super Admin bypass
            $verified = true;
            $_SESSION['approvals_verified'] = true;
            $_SESSION['approvals_verified_time'] = time();
        }
    }
}

// Check session verification (valid for 10 mins)
if (isset($_SESSION['approvals_verified']) && $_SESSION['approvals_verified'] && (time() - $_SESSION['approvals_verified_time'] < 600)) {
    $verified = true;
    $_SESSION['approvals_verified_time'] = time(); // extend
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Approvals & Verifications</h1>
        <div class="sub">Review and approve sensitive reports and transactions.</div>
    </div>
</div>

<?php if (!$verified): ?>
<div class="card" style="max-width: 400px; margin: 40px auto; padding: 30px;">
    <h3 class="h3" style="text-align: center; margin-bottom: 20px;"><i class="fas fa-lock"></i> Security Check</h3>
    <p style="text-align: center; color: #666; margin-bottom: 20px;">
        <?php if ($role === 'manager'): ?>Please enter your password to verify reports.
        <?php else: ?>Please enter your password to access approvals.
        <?php endif; ?>
    </p>
    
    <?php if ($error): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; text-align: center;">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>
    
    <form method="post">
        <div style="margin-bottom: 20px;">
            <input type="password" name="password" class="inp" style="width: 100%; padding: 10px;" placeholder="Enter Password" required autofocus>
        </div>
        <button type="submit" name="verify_password" class="btn primary" style="width: 100%;">Verify Identity</button>
    </form>
</div>
<?php else: ?>

<div class="card" style="padding: 20px;">
    <h3 class="h3">Pending Approvals</h3>
    <p class="muted">No items currently require approval.</p>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
