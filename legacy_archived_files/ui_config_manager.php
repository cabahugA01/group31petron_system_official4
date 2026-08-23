<?php
$page_id = 'ui_config';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/ui_config.php';
require_login();

// Only allow Super Admin to access UI configuration
$me = current_user();
$my_role = role_key($me['role'] ?? 'staff');

if ($my_role !== 'superadmin') {
    header("Location: dashboard.php");
    exit;
}

$msg = '';

// Handle configuration updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_config') {
        $config_key = $_POST['config_key'] ?? '';
        $config_value = $_POST['config_value'] ?? '';
        
        if (!empty($config_key)) {
            if (UIConfig::set($config_key, $config_value)) {
                $msg = "Configuration updated successfully!";
                log_activity($pdo, $me['id'], 'UI Config Update', "Updated {$config_key} to {$config_value}");
            } else {
                $msg = "Failed to update configuration.";
            }
        }
    }
}

// Get all configuration values
$configs = [
    'modal_max_width' => UIConfig::get('modal_max_width'),
    'modal_max_height_vh' => UIConfig::get('modal_max_height_vh'),
    'station_selector_max_height' => UIConfig::get('station_selector_max_height'),
    'station_selector_padding' => UIConfig::get('station_selector_padding'),
    'station_selector_gap' => UIConfig::get('station_selector_gap'),
    'typeahead_max_height' => UIConfig::get('typeahead_max_height'),
    'modal_body_padding' => UIConfig::get('modal_body_padding'),
    'modal_footer_height_offset' => UIConfig::get('modal_footer_height_offset')
];

$config_descriptions = [
    'modal_max_width' => 'Maximum width of modal dialogs in pixels',
    'modal_max_height_vh' => 'Maximum height of modal dialogs in viewport height units (1-100)',
    'station_selector_max_height' => 'Maximum height of station selector in pixels',
    'station_selector_padding' => 'Padding for station selector in pixels',
    'station_selector_gap' => 'Gap between station selector items in pixels',
    'typeahead_max_height' => 'Maximum height of typeahead suggestions in pixels',
    'modal_body_padding' => 'Padding for modal body (CSS format: "top right bottom left")',
    'modal_footer_height_offset' => 'Height offset for modal footer calculations in pixels'
];

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">UI Configuration</h1>
        <div class="sub">Manage user interface settings and dimensions</div>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: <?php echo strpos($msg, 'Failed') !== false ? '#f8d7da' : '#d4edda'; ?>; color: <?php echo strpos($msg, 'Failed') !== false ? '#721c24' : '#155724'; ?>;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- UI Configuration Form -->
<div class="card">
    <div class="card-header">
        <h3>Interface Settings</h3>
    </div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="action" value="update_config">
            
            <div class="grid-2" style="gap: 20px;">
                <?php foreach ($configs as $key => $value): ?>
                    <div class="form-group">
                        <label class="lbl"><?php echo ucwords(str_replace('_', ' ', $key)); ?></label>
                        <input type="text" name="config_value" value="<?php echo htmlspecialchars($value); ?>" class="inp full" required>
                        <input type="hidden" name="config_key" value="<?php echo $key; ?>">
                        <small class="muted"><?php echo $config_descriptions[$key] ?? 'Configuration value'; ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="submit" class="btn primary">Update All Settings</button>
                <button type="button" class="btn ghost" onclick="location.reload()">Reset</button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Section -->
<div class="card">
    <div class="card-header">
        <h3>Live Preview</h3>
    </div>
    <div class="card-body">
        <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; background: #f9fafb;">
            <h4>Modal Preview</h4>
            <div style="border: 2px dashed #d1d5db; border-radius: 6px; padding: 15px; margin: 10px 0; max-width: <?php echo UIConfig::getWithUnit('modal_max_width', 'px', '600'); ?>; max-height: <?php echo UIConfig::getWithUnit('modal_max_height_vh', 'vh', '90'); ?>; position: relative;">
                <div style="background: #3b82f6; color: white; padding: 10px; border-radius: 4px 4px 0 0; margin: -15px -15px 10px -15px;">
                    Modal Header
                </div>
                <div style="padding: <?php echo UIConfig::get('modal_body_padding', '24px 20px'); ?>; max-height: calc(<?php echo UIConfig::get('modal_max_height_vh', '90'); ?>vh - <?php echo UIConfig::get('modal_footer_height_offset', '140'); ?>px); overflow-y: auto;">
                    <p>Modal content area with dynamic sizing based on configuration.</p>
                    
                    <h5>Station Selector Preview</h5>
                    <div style="border: 1px solid #ced4da; border-radius: 6px; background: #f8f9fa; padding: <?php echo UIConfig::get('station_selector_padding', '12'); ?>px; max-height: <?php echo UIConfig::getWithUnit('station_selector_max_height', 'px', '300'); ?>; overflow-y: auto;">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div style="display: flex; align-items: center; gap: <?php echo UIConfig::get('station_selector_gap', '8'); ?>px; padding: 8px; margin: 2px 0; border-radius: 4px; cursor: pointer;">
                                <input type="radio" name="preview_station" id="station_<?php echo $i; ?>">
                                <label for="station_<?php echo $i; ?>" style="cursor: pointer; margin: 0;">Sample Station <?php echo $i; ?></label>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <div style="background: #f3f4f6; padding: 10px; border-radius: 0 0 4px 4px; margin: 10px -15px -15px -15px; text-align: right;">
                    <button style="padding: 6px 12px; background: #3b82f6; color: white; border: none; border-radius: 4px; cursor: pointer;">Save</button>
                    <button style="padding: 6px 12px; background: #6b7280; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 8px;">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .lbl {
        font-size: 13px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .inp {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
    }
    
    .inp:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .muted {
        font-size: 12px;
        color: #6b7280;
    }
    
    .card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #e5e7eb;
        background: #f9fafb;
    }
    
    .card-header h3 {
        margin: 0;
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .btn {
        padding: 8px 16px;
        border: 1px solid #d1d5db;
        background: white;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
    }
    
    .btn.primary {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .btn.primary:hover {
        background: #2563eb;
    }
    
    .btn.ghost {
        background: transparent;
        border: 1px solid #d1d5db;
        color: #6b7280;
    }
    
    .btn.ghost:hover {
        background: #f3f4f6;
        color: #1f2937;
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
?>
