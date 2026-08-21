<?php
$page_id = 'custom_module';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/module_config.php';
require_login();

$user = current_user();
$my_role = role_key($user['role'] ?? 'staff');
$moduleKey = trim($_GET['key'] ?? '');

// Fetch module details
$module = null;
if ($moduleKey) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM module_settings WHERE module_key = ?");
        $stmt->execute([$moduleKey]);
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

if (!$module) {
    header("Location: dashboard.php");
    exit;
}

$moduleName = $module['module_name'] ?? ucwords(str_replace('_', ' ', $moduleKey));
$page_title = $moduleName;

include __DIR__ . '/../partials/header.php';
?>

<div style="padding: 0 !important;">
    <!-- Page Head -->
    <div class="page-head" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <div>
            <h1 class="h1" style="margin: 0; font-size: 24px; font-weight: 700; color: #00264D;">
                <i class="fas fa-puzzle-piece" style="color: #0057b8;"></i> <?php echo htmlspecialchars($moduleName); ?>
            </h1>
            <p style="margin: 4px 0 0 0; color: #64748b; font-size: 13px;">
                <?php echo htmlspecialchars($module['module_description'] ?: 'Custom registered system module.'); ?>
            </p>
        </div>
        <div>
            <span style="background: #dcfce7; color: #15803d; font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 8px; border: 1px solid #bbf7d0;">
                <i class="fas fa-check-circle"></i> Module Active (<?php echo htmlspecialchars($module['version'] ?? 'v1.0.0'); ?>)
            </span>
        </div>
    </div>

    <!-- Main Module Container -->
    <div class="card" style="background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 24px; margin-bottom: 25px;">
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
            <div>
                <div style="font-size: 15px; font-weight: 700; color: #00264D; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                    Module Overview & Workspace
                </div>
                <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 18px; margin-bottom: 20px;">
                    <div style="font-size: 13px; color: #334155; line-height: 1.6;">
                        Welcome to <strong><?php echo htmlspecialchars($moduleName); ?></strong>! This module is fully registered, active, and accessible for <strong><?php echo htmlspecialchars($module['user_access'] ?? 'Admin, Manager, Staff'); ?></strong>.
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 11px; font-weight: 700; color: #1e40af; text-transform: uppercase;">Module Code</div>
                        <div style="font-size: 16px; font-weight: 700; color: #1e3a8a; margin-top: 4px; font-family: monospace;">
                            <?php echo htmlspecialchars($module['module_code'] ?? strtoupper($moduleKey)); ?>
                        </div>
                    </div>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px;">
                        <div style="font-size: 11px; font-weight: 700; color: #15803d; text-transform: uppercase;">User Access</div>
                        <div style="font-size: 14px; font-weight: 700; color: #166534; margin-top: 4px;">
                            <?php echo htmlspecialchars($module['user_access'] ?? 'Admin, Manager, Staff'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div style="font-size: 15px; font-weight: 700; color: #00264D; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                    Module Status
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px;">
                    <div style="margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #64748b;">Status:</span>
                        <span style="font-weight: 700; color: #16a34a; float: right;">Enabled</span>
                    </div>
                    <div style="margin-bottom: 12px;">
                        <span style="font-size: 12px; font-weight: 600; color: #64748b;">Registered Date:</span>
                        <span style="font-weight: 600; color: #334155; float: right;"><?php echo htmlspecialchars($module['last_updated'] ?? 'Today'); ?></span>
                    </div>
                    <div>
                        <span style="font-size: 12px; font-weight: 600; color: #64748b;">Version:</span>
                        <span style="font-weight: 600; color: #334155; float: right; font-family: monospace;"><?php echo htmlspecialchars($module['version'] ?? 'v1.0.0'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
