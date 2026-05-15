<?php
/**
 * Admin Unlock Interface
 * 100% Hierarchy Compliance: Admin (Owner) can unlock but not modify operational data
 * Requires password + reason with full audit trail
 */

$page_id = 'admin_unlock';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Admin only (Super Admin can see all, Admin only their station)
if (!in_array($role, ['admin', 'superadmin'])) {
    header("Location: dashboard.php");
    exit;
}

$msg = '';
$unlock_success = false;

// Handle unlock request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_record'])) {
    $table = $_POST['table'] ?? '';
    $record_id = (int)($_POST['record_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (empty($table) || empty($record_id) || empty($password) || empty($reason)) {
        $msg = "❌ All fields are required.";
    } else {
        try {
            // Call API
            $data = [
                'table' => $table,
                'record_id' => $record_id,
                'password' => $password,
                'reason' => $reason
            ];

            $ch = curl_init('backend/api/admin_unlock.php');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'action' => 'unlock_' . $table,
                'table' => $table,
                'record_id' => $record_id,
                'password' => $password,
                'reason' => $reason
            ]));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Cookie: ' . session_name() . '=' . session_id()
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($result && $result['success']) {
                $msg = "✅ {$result['message']}";
                $unlock_success = true;
                $unlock_history = null;
            } else {
                $msg = "❌ " . ($result['error'] ?? 'Unlock failed');
            }

        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Get unlock history if success
if ($unlock_success) {
    try {
        $ch = curl_init('backend/api/admin_unlock.php?action=get_unlock_history&table=' . $_POST['table'] . '&record_id=' . $_POST['record_id']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Cookie: ' . session_name() . '=' . session_id()
        ]);
        $history_response = curl_exec($ch);
        curl_close($ch);
        $history_result = json_decode($history_response, true);
        $unlock_history = $history_result['data'] ?? [];
    } catch (Exception $e) {
        $unlock_history = [];
    }
}

// Get locked records for current table
$locked_records = [];
try {
    if ($role === 'superadmin') {
        // Super Admin sees all locked records
        $stmt = $pdo->query("SELECT id, table_name FROM admin_unlocks ORDER BY unlocked_at DESC LIMIT 50");
        $all_unlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by table
        $by_table = [];
        foreach ($all_unlocks as $unlock) {
            $table = $unlock['table_name'];
            if (!isset($by_table[$table])) {
                $by_table[$table] = [];
            }
            $by_table[$table][] = $unlock['record_id'];
        }
        $locked_records = $by_table;
    } else {
        // Admin sees only their station's locked records
        $stmt = $pdo->prepare("SELECT id, table_name, unlocked_by FROM admin_unlocks WHERE unlocked_by IN (SELECT id FROM users WHERE station_id = ?) ORDER BY unlocked_at DESC LIMIT 50");
        $stmt->execute([$station_id]);
        $all_unlocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by table
        $by_table = [];
        foreach ($all_unlocks as $unlock) {
            $table = $unlock['table_name'];
            if (!isset($by_table[$table])) {
                $by_table[$table] = [];
            }
            $by_table[$table][] = $unlock['record_id'];
        }
        $locked_records = $by_table;
    }
} catch (Exception $e) {
    $locked_records = [];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Admin Unlock Interface</h1>
        <div class="sub">Unlock finalized records with password verification and audit trail</div>
    </div>
    <div class="actions">
        <a href="reports.php" class="btn ghost"><i class="fas fa-arrow-left"></i> Back to Reports</a>
    </div>
</div>

<?php if($msg): ?>
<div class="card" style="padding:15px; margin-bottom:20px; background: <?php echo strpos($msg, '✅') !== false ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo strpos($msg, '✅') !== false ? '#155724' : '#721c24'; ?>;">
    <?php echo $msg; ?>
</div>
<?php endif; ?>

<!-- Available Tables -->
<div class="card" style="padding:20px;">
    <h3 class="h3">Available Locked Records</h3>

    <?php if(empty($locked_records)): ?>
    <p class="muted">No locked records found. All records are accessible.</p>
    <?php else: ?>
    <?php foreach($locked_records as $table => $record_ids): ?>
        <div style="margin-bottom:30px; border-bottom:1px solid #e5e7eb; padding-bottom:20px;">
            <h4 style="margin-bottom:15px; color:#003d7a;">
                <i class="fas fa-lock"></i> <?php echo ucfirst(str_replace('_', ' ', $table)); ?>
            </h4>

            <?php foreach($record_ids as $rid): ?>
                <?php
                // Get record details for display
                $record = null;
                try {
                    if ($table === 'fuel_reconciliation') {
                        $stmt = $pdo->prepare("SELECT fr.*, s.name as station_name, p.name as product_name FROM fuel_reconciliation fr LEFT JOIN stations s ON fr.station_id = s.id LEFT JOIN products p ON fr.product_id = p.id WHERE fr.id = ?");
                        $stmt->execute([$rid]);
                        $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    } elseif ($table === 'shift_reports') {
                        $stmt = $pdo->prepare("SELECT sr.*, s.name as station_name, u.name as user_name FROM shift_reports sr LEFT JOIN stations s ON sr.station_id = s.id LEFT JOIN users u ON sr.user_id = u.id WHERE sr.id = ?");
                        $stmt->execute([$rid]);
                        $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    } elseif ($table === 'job_orders') {
                        $stmt = $pdo->prepare("SELECT jo.*, s.name as station_name, u.name as customer_name FROM job_orders jo LEFT JOIN stations s ON jo.station_id = s.id LEFT JOIN users u ON jo.customer_id = u.id WHERE jo.id = ?");
                        $stmt->execute([$rid]);
                        $record = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                } catch (Exception $e) {}
                ?>

                <?php if($record): ?>
                <div style="background:#f8fafc; padding:15px; border-radius:8px; border-left:4px solid #003d7a; margin-bottom:10px;">
                    <div style="display:flex; justify-content:space-between; align-items:start;">
                        <div>
                            <strong><?php echo htmlspecialchars($record['product_name'] ?? $record['user_name'] ?? $record['customer_name'] ?? 'Record #' . $rid); ?></strong>
                            <div style="font-size:12px; color:#64748b; margin-top:5px;">
                                <?php echo htmlspecialchars($record['station_name'] ?? 'Station'); ?>
                                <?php echo ' | ' . date('M d, Y H:i', strtotime($record['finalized_at'] ?? $record['unlocked_at'] ?? 'now')); ?>
                            </div>
                        </div>
                        <button type="button" class="btn small primary" onclick="openUnlockModal('<?php echo $table; ?>', <?php echo $rid; ?>)"
                                style="background:#003d7a; color:white; padding:8px 16px;">
                            <i class="fas fa-unlock"></i> Unlock
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Unlock Modal -->
<div id="unlockModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:30px; max-width:500px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; color:#0f172a;">Unlock Record</h3>
            <button type="button" onclick="closeUnlockModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <form method="post">
            <input type="hidden" name="unlock_record" value="1">
            <input type="hidden" name="table" id="unlock_table">
            <input type="hidden" name="record_id" id="unlock_record_id">

            <div style="margin-bottom:20px;">
                <label class="lbl">Admin Password *</label>
                <input type="password" name="password" class="inp full" required placeholder="Enter your admin password" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px;">
                <small class="muted">You must verify your password to unlock this record.</small>
            </div>

            <div style="margin-bottom:20px;">
                <label class="lbl">Reason for Unlock *</label>
                <textarea name="reason" class="inp full" required placeholder="Please provide a detailed reason for unlocking this record (minimum 10 characters)" style="width:100%; padding:12px; border:1px solid #cbd5e1; border-radius:8px; min-height:100px; resize:vertical;"></textarea>
                <small class="muted">Required for audit trail. Must be at least 10 characters.</small>
            </div>

            <div style="background:#fef3c7; padding:15px; border-radius:8px; border-left:4px solid #f59e0b; margin-bottom:20px; font-size:14px; color:#92400e;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong style="display:block; margin-bottom:5px;">Warning:</strong>
                Unlocking a record will:
                <ul style="margin-left:20px; margin-top:5px;">
                    <li>Unlock the record for editing</li>
                    <li>Log this action in the audit trail</li>
                    <li>Record who, when, and why the unlock occurred</li>
                </ul>
            </div>

            <div style="display:grid; gap:10px;">
                <button type="submit" class="btn primary" style="width:100%; background:#003d7a; color:white; padding:12px;">
                    <i class="fas fa-unlock"></i> Confirm Unlock
                </button>
                <button type="button" onclick="closeUnlockModal()" class="btn ghost" style="width:100%; padding:12px;">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Unlock History Modal -->
<div id="historyModal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:12px; padding:30px; max-width:600px; width:90%; max-height:80vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; color:#0f172a;">Unlock History</h3>
            <button type="button" onclick="closeHistoryModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <?php if(!empty($unlock_history)): ?>
            <div style="border:1px solid #e5e7eb; border-radius:8px; overflow:hidden;">
                <?php foreach($unlock_history as $log): ?>
                    <div style="padding:15px; border-bottom:1px solid #e5e7eb; font-size:14px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                            <strong style="color:#003d7a;"><?php echo htmlspecialchars($log['admin_name'] ?? 'Unknown'); ?></strong>
                            <span style="color:#64748b;"><?php echo date('M d, Y H:i', strtotime($log['unlocked_at'])); ?></span>
                        </div>
                        <div style="margin-bottom:5px; color:#0f172a;"><?php echo htmlspecialchars(substr($log['unlock_reason'], 0, 100)); ?>...</div>
                        <div style="font-size:12px; color:#64748b;">
                            <span class="muted">IP:</span> <?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if(count($unlock_history) >= 50): ?>
                <p class="muted" style="margin-top:15px; font-size:13px;">(Showing last 50 records)</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">No unlock history found.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function openUnlockModal(table, recordId) {
    document.getElementById('unlock_table').value = table;
    document.getElementById('unlock_record_id').value = recordId;
    document.getElementById('unlockModal').style.display = 'flex';
}

function closeUnlockModal() {
    document.getElementById('unlockModal').style.display = 'none';
    document.getElementById('unlockModal').querySelector('form').reset();
}

function openHistoryModal() {
    document.getElementById('historyModal').style.display = 'flex';
}

function closeHistoryModal() {
    document.getElementById('historyModal').style.display = 'none';
}

// Close modals when clicking outside
document.getElementById('unlockModal').addEventListener('click', function(e) {
    if (e.target === this) closeUnlockModal();
});

document.getElementById('historyModal').addEventListener('click', function(e) {
    if (e.target === this) closeHistoryModal();
});
</script>

<style>
.lbl {
    display:block;
    font-size:14px;
    font-weight:600;
    color:#334155;
    margin-bottom:8px;
}
.inp {
    width:100%;
    padding:12px;
    border:1px solid #cbd5e1;
    border-radius:8px;
    font-size:14px;
}
.inp:focus {
    outline:none;
    border-color:#003d7a;
    box-shadow:0 0 0 3px rgba(0,61,122,0.1);
}
.card {
    background:white;
    border-radius:12px;
    padding:24px;
    box-shadow:0 2px 8px rgba(0,0,0,0.06);
    border:1px solid #e5e7eb;
}
.h3 {
    margin:0 0 15px 0;
    font-size:18px;
    font-weight:700;
    color:#0f172a;
}
.muted {
    color:#64748b;
    font-size:13px;
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
