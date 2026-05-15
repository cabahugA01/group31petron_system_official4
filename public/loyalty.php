<?php
$page_id = 'loyalty';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = function_exists('role_key') ? role_key($me['role'] ?? '') : strtolower(trim($me['role'] ?? ''));
if (!in_array($role, ['staff', 'admin', 'manager', 'superadmin'])) { 
    header("Location: dashboard.php"); 
    exit; 
}
include __DIR__ . '/../partials/header.php';

$view = $_GET['view'] ?? 'encode';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'encode_points' && $view === 'encode') {
        $customer_id = $_POST['customer_id'] ?? '';
        $points = (int)($_POST['points'] ?? 0);
        $transaction_type = $_POST['transaction_type'] ?? 'earn';
        $reference = $_POST['reference'] ?? '';
        
        if ($customer_id && $points > 0) {
            try {
                $pdo->beginTransaction();
                
                // Add loyalty transaction
                $stmt = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, points, type, reference_no, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$customer_id, $points, $transaction_type, $reference]);
                
                // Update customer points
                if ($transaction_type === 'earn') {
                    $stmt = $pdo->prepare("UPDATE customers SET points = points + ? WHERE id = ?");
                } else {
                    $stmt = $pdo->prepare("UPDATE customers SET points = points - ? WHERE id = ? AND points >= ?");
                }
                $stmt->execute([$points, $customer_id, $points]);
                
                $pdo->commit();
                $msg = "✅ Points " . ($transaction_type === 'earn' ? 'earned' : 'redeemed') . " successfully!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Please fill in all required fields.";
        }
    }
}

    // Fetch data based on view
    $customers = [];
    $transactions = [];
    $rewards = [];
    $redemption_history = [];
    $points_issued = 0;
    $redemptions = 0;
    
    try {
        // Fetch customers for dropdown
        $stmt = $pdo->prepare("SELECT id, name, points FROM customers WHERE station_id = ? ORDER BY name");
        $stmt->execute([$station_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch loyalty transactions
        $stmt = $pdo->prepare("SELECT lt.*, c.name as customer_name FROM loyalty_transactions lt LEFT JOIN customers c ON lt.customer_id = c.id WHERE c.station_id = ? ORDER BY lt.created_at DESC LIMIT 50");
        $stmt->execute([$station_id]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate points issued today
        $stmt = $pdo->prepare("SELECT SUM(points) FROM loyalty_transactions lt LEFT JOIN customers c ON lt.customer_id = c.id WHERE c.station_id = ? AND lt.type='earn' AND DATE(lt.created_at) = CURDATE()");
        $points_issued = $stmt->fetchColumn() ?: 0;
        
        // Calculate redemptions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM loyalty_transactions lt LEFT JOIN customers c ON lt.customer_id = c.id WHERE c.station_id = ? AND lt.type='redeem'");
        $redemptions = $stmt->fetchColumn();
        
        // Fetch available rewards
        $stmt = $pdo->prepare("SELECT id, name, points_required as points, category FROM rewards WHERE is_active = 1 ORDER BY points_required ASC");
        $stmt->execute();
        $rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch redemption history
        $stmt = $pdo->prepare(
            "SELECT lt.id, c.name as customer_name, r.name as reward_name, lt.points, lt.created_at,
                    CASE 
                        WHEN DATE(lt.created_at) = CURDATE() THEN 'Today'
                        WHEN DATE(lt.created_at) = DATE(DATE_SUB(NOW(), INTERVAL 1 DAY)) THEN 'Yesterday'
                        ELSE 'Completed'
                    END as status
             FROM loyalty_transactions lt
             JOIN customers c ON lt.customer_id = c.id
             LEFT JOIN rewards r ON lt.points = r.points_required
             WHERE c.station_id = ? AND lt.type = 'redeem'
             ORDER BY lt.created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$station_id]);
        $redemption_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
?>
<div class="page-head">
    <div>
        <h1 class="h1">Loyalty Program</h1>
        <div class="sub">Manage points, rewards, and redemption history.</div>
    </div>
</div>

<section class="cards three">
    <div class="card metric">
        <div class="metric-label">Total Members</div>
        <div class="metric-value"><?php echo count($customers); ?></div>
        <div class="metric-ico blue"><i class="fas fa-gem"></i></div>
    </div>
    <div class="card metric">
        <div class="metric-label">Points Issued Today</div>
        <div class="metric-value"><?php echo number_format($points_issued); ?> pts</div>
        <div class="metric-ico amber"><i class="fas fa-star"></i></div>
    </div>
    <div class="card metric">
        <div class="metric-label">Redemptions</div>
        <div class="metric-value"><?php echo $redemptions; ?></div>
        <div class="metric-ico purple"><i class="fas fa-gift"></i></div>
    </div>
</section>

<div style="display:flex; gap:10px; flex-wrap:wrap; margin:20px 0;">
    <a class="btn <?php echo $view === 'encode' ? 'btn-primary' : 'ghost'; ?>" href="loyalty.php?view=encode">Encode Points</a>
    <a class="btn <?php echo $view === 'history' ? 'btn-primary' : 'ghost'; ?>" href="loyalty.php?view=history">Points History</a>
    <a class="btn <?php echo $view === 'rewards' ? 'btn-primary' : 'ghost'; ?>" href="loyalty.php?view=rewards">Available Rewards</a>
    <a class="btn <?php echo $view === 'redemptions' ? 'btn-primary' : 'ghost'; ?>" href="loyalty.php?view=redemptions">Redemption History</a>
</div>

<?php if(isset($msg)): ?>
    <div class="alert <?php echo strpos($msg, '✅') !== false ? 'alert-success' : 'alert-error'; ?>" style="margin-bottom:16px;">
        <?php echo htmlspecialchars($msg); ?>
    </div>
<?php endif; ?>

<?php if($view === 'encode'): ?>
<section class="card" style="padding:20px;">
    <h2 class="h2">Encode Loyalty Points</h2>
    <form method="post" style="max-width:600px;">
        <input type="hidden" name="action" value="encode_points">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div>
                <label>Customer *</label>
                <select name="customer_id" required style="width:100%;">
                    <option value="">Select Customer</option>
                    <?php foreach($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['name']); ?> (<?php echo (int)$customer['points']; ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Points *</label>
                <input type="number" name="points" min="1" required style="width:100%;">
            </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div>
                <label>Transaction Type *</label>
                <select name="transaction_type" required style="width:100%;">
                    <option value="earn">Earn Points</option>
                    <option value="redeem">Redeem Points</option>
                </select>
            </div>
            <div>
                <label>Reference Number</label>
                <input type="text" name="reference" placeholder="e.g., Receipt #1234" style="width:100%;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-plus"></i> Encode Points
        </button>
    </form>
</section>

<?php elseif($view === 'history'): ?>
<section class="card" style="padding:20px;">
    <h2 class="h2">Points History</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Points</th>
                    <th>Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($transactions)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:20px; color:#888;">No loyalty transactions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($transactions as $txn): ?>
                        <tr>
                            <td><?php echo date('M d, Y g:i A', strtotime($txn['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($txn['customer_name']); ?></td>
                            <td>
                                <span class="badge" style="background:<?php echo $txn['type'] === 'earn' ? '#28a745' : '#dc3545'; ?>; color:white; padding:2px 8px; border-radius:12px; font-size:12px;">
                                    <?php echo ucfirst($txn['type']); ?>
                                </span>
                            </td>
                            <td><?php echo number_format($txn['points']); ?></td>
                            <td><?php echo htmlspecialchars($txn['reference_no'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif($view === 'rewards'): ?>
<section class="card" style="padding:20px;">
    <h2 class="h2">Available Rewards</h2>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:16px;">
        <?php foreach($rewards as $reward): ?>
            <div style="border:1px solid #e9ecef; border-radius:8px; padding:16px; background:#fff;">
                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:8px;">
                    <h4 style="margin:0;"><?php echo htmlspecialchars($reward['name']); ?></h4>
                    <span class="badge" style="background:#ffc107; color:#000; padding:2px 8px; border-radius:12px; font-size:12px;">
                        <?php echo htmlspecialchars($reward['category']); ?>
                    </span>
                </div>
                <div style="font-size:24px; font-weight:bold; color:#0066cc; margin-bottom:8px;">
                    <?php echo number_format($reward['points']); ?> pts
                </div>
                <button class="btn btn-outline" style="width:100%;" disabled>
                    <i class="fas fa-gift"></i> Redeem
                </button>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php elseif($view === 'redemptions'): ?>
<section class="card" style="padding:20px;">
    <h2 class="h2">Redemption History</h2>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Reward</th>
                    <th>Points Used</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($redemption_history)): ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding:20px; color:#888;">No redemption history found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($redemption_history as $redemption): ?>
                        <tr>
                            <td><?php echo date('M d, Y g:i A', strtotime($redemption['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($redemption['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($redemption['reward_name'] ?? 'Custom Redemption'); ?></td>
                            <td><?php echo number_format($redemption['points']); ?></td>
                            <td>
                                <span class="badge" style="background:#28a745; color:white; padding:2px 8px; border-radius:12px; font-size:12px;">
                                    <?php echo htmlspecialchars($redemption['status']); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../partials/footer.php'; ?>
