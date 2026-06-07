<?php
/**
 * SMS Setup Tool — Enter your Semaphore API key and test SMS sending.
 * DELETE this file after setup is complete!
 */

$config_path = __DIR__ . '/../config/sms_config.php';
$log_path    = __DIR__ . '/../public/sms_sent.log';
$msg = '';
$msg_type = '';

// ── Handle Save ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'save_key') {
        $api_key     = trim($_POST['api_key'] ?? '');
        $sender_name = preg_replace('/[^A-Z0-9]/i', '', strtoupper(trim($_POST['sender_name'] ?? 'PETRON')));
        $sender_name = substr($sender_name ?: 'PETRON', 0, 11);
        $enabled     = !empty($api_key) && $api_key !== 'YOUR_SEMAPHORE_API_KEY_HERE';

        $new_config = "<?php\n/**\n * SMS Configuration — Petron Station Management System\n * Provider: Semaphore (Philippines)\n */\n\n\$sms_config = [\n    'provider'    => 'semaphore',\n    'api_key'     => " . var_export($api_key, true) . ",\n    'sender_name' => " . var_export($sender_name, true) . ",\n    'enabled'     => " . ($enabled ? 'true' : 'false') . ",\n];\n?>\n";

        if (file_put_contents($config_path, $new_config) !== false) {
            $msg = $enabled
                ? "✅ API key saved! SMS is now ENABLED."
                : "⚠️ Config saved but SMS is DISABLED (no valid API key).";
            $msg_type = $enabled ? 'ok' : 'warn';
        } else {
            $msg = "❌ Could not write to config file. Check folder permissions.";
            $msg_type = 'err';
        }
    }

    if ($_POST['action'] === 'test_sms') {
        $test_phone = preg_replace('/\D/', '', trim($_POST['test_phone'] ?? ''));
        $test_otp   = sprintf("%06d", random_int(100000, 999999));

        require_once $config_path;

        $api_key  = $sms_config['api_key']  ?? '';
        $sender   = $sms_config['sender_name'] ?? 'PETRON';
        $enabled  = $sms_config['enabled']  ?? false;

        if (!$enabled || empty($api_key) || $api_key === 'YOUR_SEMAPHORE_API_KEY_HERE') {
            // Simulate – write to log
            $entry = date('Y-m-d H:i:s') . " | TO: {$test_phone} | TEST OTP: {$test_otp} (SIMULATION – SMS not sent)\n";
            file_put_contents($log_path, $entry, FILE_APPEND | LOCK_EX);
            $msg = "📋 SIMULATION MODE — OTP <strong>{$test_otp}</strong> written to <code>sms_sent.log</code> (SMS not sent — add API key first).";
            $msg_type = 'warn';
        } else {
            $url  = 'https://api.semaphore.co/api/v4/messages';
            $data = http_build_query([
                'apikey'     => $api_key,
                'number'     => $test_phone,
                'message'    => "Your Petron OTP is {$test_otp}. Valid 5 minutes. Do not share.",
                'sendername' => $sender,
            ]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err      = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200) {
                $msg = "🎉 SMS SENT! OTP <strong>{$test_otp}</strong> → <strong>{$test_phone}</strong>. Check your phone!";
                $msg_type = 'ok';
                $entry = date('Y-m-d H:i:s') . " | TO: {$test_phone} | OTP: {$test_otp} | STATUS: SENT via Semaphore\n";
                file_put_contents($log_path, $entry, FILE_APPEND | LOCK_EX);
            } else {
                $decoded = json_decode($response, true);
                $detail  = $decoded['message'] ?? $decoded[0]['message'] ?? $response;
                $msg = "❌ Send failed (HTTP {$httpCode}): " . htmlspecialchars($detail ?: $err);
                $msg_type = 'err';
            }
        }
    }
}

// ── Read current config ───────────────────────────────────────────────
require_once $config_path;
$current_key     = $sms_config['api_key']     ?? '';
$current_sender  = $sms_config['sender_name'] ?? 'PETRON';
$current_enabled = $sms_config['enabled']     ?? false;
$is_configured   = ($current_enabled && !empty($current_key) && $current_key !== 'YOUR_SEMAPHORE_API_KEY_HERE');

// ── Recent log ────────────────────────────────────────────────────────
$recent_log = '';
if (file_exists($log_path)) {
    $lines = array_reverse(array_filter(explode("\n", file_get_contents($log_path))));
    $recent_log = implode("\n", array_slice($lines, 0, 10));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SMS Setup | Petron System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:#f0f4ff;min-height:100vh;padding:30px 16px}
.page{max-width:640px;margin:auto}
h1{font-size:22px;font-weight:800;color:#002F6C;margin-bottom:4px}
.sub{font-size:13px;color:#666;margin-bottom:24px}
.card{background:#fff;border-radius:16px;box-shadow:0 2px 16px rgba(0,47,108,.08);margin-bottom:20px;overflow:hidden}
.card-head{padding:16px 22px;background:#002F6C;color:#fff;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:700}
.card-body{padding:22px}
.field{margin-bottom:16px}
.field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:#444;letter-spacing:.5px;margin-bottom:6px}
.field input{width:100%;padding:11px 14px;border:1.5px solid #dde3f0;border-radius:10px;font-size:14px;font-family:inherit;outline:none;transition:border .2s}
.field input:focus{border-color:#002F6C;box-shadow:0 0 0 3px rgba(0,47,108,.1)}
.field .hint{font-size:11.5px;color:#888;margin-top:5px}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700}
.badge-ok{background:#e8f5e9;color:#1b5e20}
.badge-warn{background:#fff8e1;color:#f57f17}
.badge-off{background:#fce4ec;color:#b71c1c}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;border:none;font-family:inherit;transition:all .2s}
.btn-primary{background:#002F6C;color:#fff}
.btn-primary:hover{background:#001a40}
.btn-success{background:#1b5e20;color:#fff}
.btn-success:hover{background:#114015}
.btn-danger{background:#b71c1c;color:#fff}
.btn-danger:hover{background:#7f0000}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.msg{padding:13px 16px;border-radius:10px;font-size:13.5px;font-weight:600;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px}
.msg.ok{background:#e8f5e9;border:1.5px solid #a5d6a7;color:#1b5e20}
.msg.warn{background:#fff8e1;border:1.5px solid #ffe082;color:#f57f17}
.msg.err{background:#fce4ec;border:1.5px solid #ef9a9a;color:#b71c1c}
.log-box{background:#0d1117;color:#58a6ff;font-family:monospace;font-size:12px;padding:14px;border-radius:10px;max-height:200px;overflow-y:auto;white-space:pre-wrap;word-break:break-all}
.divider{border:none;border-top:1px solid #eee;margin:16px 0}
.step-list{list-style:none;counter-reset:steps}
.step-list li{counter-increment:steps;display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;font-size:13px;color:#444}
.step-list li::before{content:counter(steps);display:flex;align-items:center;justify-content:center;min-width:24px;height:24px;background:#002F6C;color:#fff;border-radius:50%;font-size:11px;font-weight:700;flex-shrink:0;margin-top:1px}
.step-list a{color:#002F6C;font-weight:600}
.warn-box{background:#fff3e0;border:1.5px solid #ffcc02;border-radius:10px;padding:12px 16px;font-size:12.5px;color:#e65100;margin-top:16px}
</style>
</head>
<body>
<div class="page">

<h1><i class="fas fa-sms"></i> SMS OTP Setup</h1>
<p class="sub">Configure Semaphore to send real OTP SMS messages to Philippine numbers.</p>

<?php if ($msg): ?>
<div class="msg <?php echo $msg_type; ?>">
    <i class="fas fa-<?php echo $msg_type === 'ok' ? 'check-circle' : ($msg_type === 'warn' ? 'exclamation-triangle' : 'times-circle'); ?>"></i>
    <span><?php echo $msg; ?></span>
</div>
<?php endif; ?>

<!-- Status Card -->
<div class="card">
    <div class="card-head"><i class="fas fa-signal"></i> Current SMS Status</div>
    <div class="card-body">
        <?php if ($is_configured): ?>
            <span class="status-badge badge-ok">✅ SMS ENABLED — Semaphore Active</span>
        <?php elseif (!empty($current_key) && $current_key !== 'YOUR_SEMAPHORE_API_KEY_HERE'): ?>
            <span class="status-badge badge-warn">⚠️ API Key set but SMS DISABLED</span>
        <?php else: ?>
            <span class="status-badge badge-off">❌ SMS DISABLED — No API Key</span>
        <?php endif; ?>
        <p style="font-size:12.5px;color:#666;margin-top:10px">
            Provider: <strong>Semaphore</strong> &nbsp;|&nbsp;
            Sender: <strong><?php echo htmlspecialchars($current_sender); ?></strong> &nbsp;|&nbsp;
            Enabled: <strong><?php echo $current_enabled ? 'YES' : 'NO'; ?></strong>
        </p>
    </div>
</div>

<!-- Steps Guide -->
<div class="card">
    <div class="card-head"><i class="fas fa-list-ol"></i> How to Get Your Semaphore API Key</div>
    <div class="card-body">
        <ol class="step-list">
            <li>Go to <a href="https://semaphore.co/" target="_blank">semaphore.co</a> → Click <strong>Sign Up Free</strong></li>
            <li>Verify your email and log in to the dashboard</li>
            <li>Load credits: minimum <strong>₱100</strong> (top-up via GCash, Maya, etc.)</li>
            <li>Go to <strong>Account Settings → API Key</strong> → copy your key</li>
            <li>Paste it below and click <strong>Save Configuration</strong></li>
        </ol>
        <div class="warn-box">
            <i class="fas fa-trash"></i>
            <strong>Security:</strong> Delete this file (<code>database/setup_sms.php</code>) after setup is done!
        </div>
    </div>
</div>

<!-- Save Config Form -->
<div class="card">
    <div class="card-head"><i class="fas fa-key"></i> Enter API Key</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="save_key">
            <div class="field">
                <label>Semaphore API Key *</label>
                <input type="text" name="api_key"
                       value="<?php echo htmlspecialchars($current_key !== 'YOUR_SEMAPHORE_API_KEY_HERE' ? $current_key : ''); ?>"
                       placeholder="Paste your Semaphore API key here..."
                       autocomplete="off" spellcheck="false">
                <div class="hint">Get this from semaphore.co → Account Settings → API Key</div>
            </div>
            <div class="field">
                <label>Sender Name (max 11 chars)</label>
                <input type="text" name="sender_name"
                       value="<?php echo htmlspecialchars($current_sender); ?>"
                       placeholder="PETRON" maxlength="11">
                <div class="hint">Alphanumeric only. This appears as the sender in the SMS.</div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Configuration
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Test SMS Form -->
<div class="card">
    <div class="card-head"><i class="fas fa-paper-plane"></i> Test SMS Sending</div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="test_sms">
            <div class="field">
                <label>Test Phone Number (Philippine format)</label>
                <input type="text" name="test_phone"
                       placeholder="09XXXXXXXXX" maxlength="11"
                       pattern="\d{11}" required>
                <div class="hint">Enter an 11-digit Philippine mobile number to receive the test OTP.</div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn <?php echo $is_configured ? 'btn-success' : 'btn-danger'; ?>">
                    <i class="fas fa-<?php echo $is_configured ? 'sms' : 'flask'; ?>"></i>
                    <?php echo $is_configured ? 'Send Real SMS' : 'Simulate (Log Only)'; ?>
                </button>
            </div>
        </form>

        <?php if ($recent_log): ?>
        <hr class="divider">
        <p style="font-size:12px;font-weight:700;color:#555;margin-bottom:8px">📋 Recent SMS Log (latest 10):</p>
        <div class="log-box"><?php echo htmlspecialchars($recent_log); ?></div>
        <?php endif; ?>
    </div>
</div>

</div>
</body>
</html>
