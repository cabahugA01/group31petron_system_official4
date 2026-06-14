<?php
/**
 * INTEGRATION SETTINGS - MENU TEST PAGE
 * This page will show you the actual menu structure from rbac_menu.php
 */
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Get menu structure
$menu_file = __DIR__ . '/../partials/rbac_menu.php';
$menu_items = include $menu_file;

// Find Integration Settings menu
$integration_menu = null;
foreach ($menu_items as $item) {
    if (($item['id'] ?? '') === 'integration_settings') {
        $integration_menu = $item;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Integration Settings Menu Test</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #00264D;
            border-bottom: 3px solid #CC0000;
            padding-bottom: 10px;
        }
        .status {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-weight: bold;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .menu-tree {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #00264D;
        }
        .menu-item {
            padding: 8px 0;
            font-size: 14px;
        }
        .parent {
            font-weight: bold;
            color: #00264D;
            font-size: 16px;
        }
        .child {
            padding-left: 30px;
            color: #666;
        }
        .new-badge {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        .old-badge {
            background: #dc3545;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            margin-left: 8px;
        }
        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .timestamp {
            text-align: right;
            color: #999;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔌 Integration Settings - Menu Structure Test</h1>
        
        <?php if ($integration_menu): ?>
            <div class="status success">
                ✅ Integration Settings menu found in rbac_menu.php
            </div>
            
            <div class="menu-tree">
                <div class="menu-item parent">
                    <i class="<?php echo $integration_menu['ico'] ?? 'fas fa-plug'; ?>"></i>
                    <?php echo $integration_menu['label'] ?? 'Integration Settings'; ?>
                </div>
                
                <?php if (!empty($integration_menu['sub_items'])): ?>
                    <?php foreach ($integration_menu['sub_items'] as $sub): ?>
                        <div class="menu-item child">
                            ├─ <?php echo htmlspecialchars($sub['label']); ?>
                            
                            <?php 
                            // Check if this is a new item
                            $new_items = ['API Connections', 'Git Workflow', 'External System Sync'];
                            $old_items = ['API Endpoints', 'Sync Rules'];
                            
                            if (in_array($sub['label'], $new_items)): ?>
                                <span class="new-badge">NEW</span>
                            <?php elseif (in_array($sub['label'], $old_items)): ?>
                                <span class="old-badge">OLD</span>
                            <?php endif; ?>
                            
                            <br>
                            <small style="color:#999;padding-left:20px;">
                                ID: <?php echo $sub['id'] ?? 'N/A'; ?> | 
                                URL: <?php echo $sub['href'] ?? 'N/A'; ?>
                            </small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="info">
                <strong>📋 Expected Menu Items:</strong><br>
                ✅ POS Import Config<br>
                ✅ API Connections (NEW)<br>
                ✅ Git Workflow (NEW)<br>
                ✅ External System Sync (NEW)<br><br>
                
                <strong>❌ Old Items (Should NOT appear):</strong><br>
                ❌ API Endpoints<br>
                ❌ Sync Rules
            </div>
            
            <h2>Current Sub-Items Count:</h2>
            <p style="font-size:24px;font-weight:bold;color:#00264D;">
                <?php echo count($integration_menu['sub_items'] ?? []); ?> items
            </p>
            
            <?php 
            $expected_items = ['int_pos_import', 'int_api_connections', 'int_git_workflow', 'int_external_sync'];
            $actual_items = array_column($integration_menu['sub_items'] ?? [], 'id');
            $all_correct = empty(array_diff($expected_items, $actual_items)) && empty(array_diff($actual_items, $expected_items));
            ?>
            
            <?php if ($all_correct): ?>
                <div class="status success">
                    🎉 PERFECT! All menu items are correctly updated!<br>
                    <small>If sidebar still shows old items, please clear browser cache (Ctrl+Shift+Delete)</small>
                </div>
            <?php else: ?>
                <div class="status error">
                    ⚠️ Menu structure mismatch detected!<br>
                    Expected: <?php echo implode(', ', $expected_items); ?><br>
                    Actual: <?php echo implode(', ', $actual_items); ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="status error">
                ❌ Integration Settings menu NOT found in rbac_menu.php!
            </div>
        <?php endif; ?>
        
        <div class="timestamp">
            Page generated: <?php echo date('F d, Y H:i:s'); ?><br>
            Menu file: <?php echo $menu_file; ?>
        </div>
        
        <hr style="margin:30px 0;">
        
        <h2>🔧 Quick Actions:</h2>
        <p>
            <a href="superadmin_integration_settings.php" style="display:inline-block;padding:10px 20px;background:#00264D;color:white;text-decoration:none;border-radius:5px;">
                Open Integration Settings
            </a>
            &nbsp;
            <a href="javascript:location.reload(true)" style="display:inline-block;padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;">
                🔄 Refresh This Page
            </a>
        </p>
        
        <h2>📝 Instructions to Fix Sidebar:</h2>
        <ol>
            <li>If menu structure above is correct but sidebar still shows old items:</li>
            <li>Open browser DevTools (Press F12)</li>
            <li>Go to Application tab → Clear Storage</li>
            <li>Click "Clear site data"</li>
            <li>Or simply press: <strong>Ctrl + Shift + Delete</strong></li>
            <li>Reload the Integration Settings page</li>
        </ol>
    </div>
</body>
</html>
