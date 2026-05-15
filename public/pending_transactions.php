<?php
/**
 * PENDING TRANSACTIONS & APPROVALS
 * 
 * Workflow:
 * 1. STAFF encodes and submits daily transactions (sales, fuel, merchandise)
 * 2. Transactions enter "Pending" status awaiting approval
 * 3. MANAGER reviews and approves/rejects transactions
 * 4. Upon approval: inventory is updated and transaction is finalized
 * 5. All actions are logged to activity_logs for audit trail
 * 
 * Authorization: Manager, Admin, and Super Admin can approve transactions
 */
$page_id = 'pending_transactions_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$role = role_key($me['role'] ?? 'staff');
$isAdmin = in_array($role, ['manager', 'admin', 'superadmin']);
$msg = '';

// Handle Post Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';
    $sale_id = $_POST['sale_id'] ?? '';

    if ($action === 'approve' && $sale_id) {
        try {
            $pdo->beginTransaction();

            // Get Sale Details
            $stmt = $pdo->prepare("SELECT si.name, si.qty FROM sales s JOIN sale_items si ON s.id = si.sale_id WHERE s.id = ?");
            $stmt->execute([$sale_id]);
            $items = $stmt->fetchAll();

            // Deduct Inventory
            foreach ($items as $item) {
                $upd = $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level - ? WHERE product_name = ? AND station_id = ?");
                $upd->execute([$item['qty'], $item['name'], $station_id]);
            }

            // Update Sale Status
            $pdo->prepare("UPDATE sales SET status = 'Approved' WHERE id = ?")->execute([$sale_id]);

            log_activity($pdo, $me['id'], 'Approve Transaction', "Approved sale #$sale_id");
            $msg = "✅ Transaction approved.";
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Error: " . $e->getMessage();
        }
    } elseif ($action === 'reject' && $sale_id) {
        try {
            $pdo->prepare("UPDATE sales SET status = 'Rejected' WHERE id = ?")->execute([$sale_id]);
            log_activity($pdo, $me['id'], 'Reject Transaction', "Rejected sale #$sale_id");
            $msg = "⚠️ Transaction rejected.";
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch Pending Transactions
$sql = "SELECT s.*, u.name as staff_name,
        si.name as product_name, si.qty, si.price, si.amount as subtotal,
        COALESCE(i.type, 'Merchandise') as category
        FROM sales s
        JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN station_inventory i ON si.name = i.product_name AND i.station_id = s.station_id
        WHERE s.status = 'Pending' AND s.payment_method != 'Credit' AND (s.station_id = ? OR s.station_id IS NULL)
        ORDER BY s.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$station_id]);
$transactions = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">New Transaction (Admin View)</h1>
        <div class="sub">Reviewer/approver role. Admin does not encode, only validates staff entries.</div>
    </div>
</div>

<?php if($msg): ?>
<div id="toast" class="toast show" style="background: <?php echo strpos($msg, 'Error')!==false ? '#dc3545' : '#28a745'; ?>;">
    <?php echo $msg; ?>
</div>
<script>setTimeout(() => document.getElementById('toast').remove(), 3000);</script>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h3 class="h3">Pending Transactions</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer</th>
                    <th>Category</th>
                    <th>Product Name</th>
                    <th>Qty/Liters</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                    <th>Payment Type</th>
                    <th>Staff Encoder</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($transactions as $t): ?>
                <tr>
                    <td>#<?php echo $t['id']; ?></td>
                    <td><?php echo htmlspecialchars($t['customer']); ?></td>
                    <td><?php echo htmlspecialchars($t['category']); ?></td>
                    <td><?php echo htmlspecialchars($t['product_name']); ?></td>
                    <td><?php echo number_format($t['qty'], 2); ?></td>
                    <td>₱<?php echo number_format($t['price'], 2); ?></td>
                    <td>₱<?php echo number_format($t['subtotal'], 2); ?></td>
                    <td><?php echo htmlspecialchars($t['payment_method']); ?></td>
                    <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    <td><span class="badge bg-warning">Pending</span></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="sale_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="btn small success" title="Approve">✅</button>
                        </form>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="sale_id" value="<?php echo $t['id']; ?>">
                            <button type="submit" class="btn small danger" title="Reject">❌</button>
                        </form>
                        <button class="btn small ghost" onclick="viewTransaction(<?php echo $t['id']; ?>)" title="View">👁️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="11" style="text-align:center; padding:20px;">No pending transactions.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="modal" id="viewModal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">Transaction Details</h3>
            <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
        </div>
        <div class="modal-body" id="transactionDetails">
            <!-- Details loaded here -->
        </div>
        <div class="modal-footer">
            <button class="btn primary" onclick="closeModal('viewModal')">Close</button>
        </div>
    </div>
</div>

<script>
function viewTransaction(id) {
    // Fetch details via AJAX or load statically for now
    fetch('backend/get_transaction_details.php?id=' + id)
        .then(response => response.json())
        .then(data => {
            document.getElementById('transactionDetails').innerHTML = `
                <p><strong>Customer:</strong> ${data.customer}</p>
                <p><strong>Staff:</strong> ${data.staff_name}</p>
                <p><strong>Payment:</strong> ${data.payment_method}</p>
                <p><strong>Products:</strong></p>
                <ul>${data.items.map(item => `<li>${item.name} - ${item.qty} x ₱${item.price} = ₱${item.amount}</li>`).join('')}</ul>
            `;
            document.getElementById('viewModal').classList.add('show');
        });
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
}
</script>

<style>
    .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.8em; color: white; }
    .bg-warning { background: #ffc107; color: #000; }
    .bg-success { background: #28a745; }
    .bg-danger { background: #dc3545; }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
