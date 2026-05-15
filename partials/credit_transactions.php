<?php
$page_id = 'credit_transactions';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
// Credit approval: Admin ONLY per hierarchy (Manager cannot approve credits)
$isAdmin = in_array($me['role'], ['admin', 'superadmin']);
$msg = '';

// Ensure sales table has customer_id for tracking
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id INT NULL AFTER user_id");
} catch (Exception $e) {}

// Handle Post Credit Sale
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_credit_sale') {
        $customer_id = $_POST['customer_id'];
        $item_name = $_POST['item_name'];
        $quantity = (float)$_POST['quantity'];
        $price = (float)$_POST['price'];
        $due_date = $_POST['due_date'];
    
        $total = $quantity * $price;
    
        try {
            $pdo->beginTransaction();
        
            // Get Customer Info & Check Limit
            $cStmt = $pdo->prepare("SELECT name, credit_limit, current_balance FROM customers WHERE id = ?");
            $cStmt->execute([$customer_id]);
            $cust = $cStmt->fetch();
        
            if (!$cust) throw new Exception("Customer not found.");
        
            if (($cust['current_balance'] + $total) > $cust['credit_limit']) {
                throw new Exception("Credit limit exceeded. Limit: ₱" . number_format($cust['credit_limit'], 2));
            }
        
            // 1. Insert Sale (Credit) - Status Pending, Balance NOT updated yet
            $stmt = $pdo->prepare("INSERT INTO sales (station_id, user_id, customer_id, customer, payment_method, total, sale_date, created_at, due_date, status) VALUES (?, ?, ?, ?, 'Credit', ?, CURDATE(), NOW(), ?, 'Pending')");
            $stmt->execute([$station_id, $me['id'], $customer_id, $cust['name'], $total, $due_date]);
            $sale_id = $pdo->lastInsertId();
        
            // 2. Insert Item
            $stmtItem = $pdo->prepare("INSERT INTO sale_items (sale_id, name, qty, price, amount) VALUES (?, ?, ?, ?, ?)");
            $stmtItem->execute([$sale_id, $item_name, $quantity, $price, $total]);
        
            // NOTE: Balance is updated only upon Admin Approval
        
            $pdo->commit();
            $msg = "✅ Credit transaction submitted for approval.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
    elseif ($action === 'approve' && $isAdmin) {
        $sale_id = $_POST['sale_id'];
        try {
            $pdo->beginTransaction();
            
            // Get Sale Details
            $sStmt = $pdo->prepare("SELECT total, customer_id, status FROM sales WHERE id = ?");
            $sStmt->execute([$sale_id]);
            $sale = $sStmt->fetch();

            if ($sale && $sale['status'] === 'Pending') {
                // Update Customer Balance
                $upd = $pdo->prepare("UPDATE customers SET current_balance = current_balance + ? WHERE id = ?");
                $upd->execute([$sale['total'], $sale['customer_id']]);

                // Update Sale Status
                $updSale = $pdo->prepare("UPDATE sales SET status = 'Approved' WHERE id = ?");
                $updSale->execute([$sale_id]);

                log_activity($pdo, $me['id'], 'Approve Credit', "Approved credit sale #$sale_id for ₱{$sale['total']}");
                $msg = "✅ Transaction approved. Customer balance updated.";
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
    elseif ($action === 'reject' && $isAdmin) {
        $sale_id = $_POST['sale_id'];
        try {
            $pdo->prepare("UPDATE sales SET status = 'Rejected' WHERE id = ?")->execute([$sale_id]);
            log_activity($pdo, $me['id'], 'Reject Credit', "Rejected credit sale #$sale_id");
            $msg = "⚠️ Transaction rejected.";
        } catch (Exception $e) {
            $msg = "❌ Error: " . $e->getMessage();
        }
    }
}

// Fetch Customers for Dropdown
$customers = $pdo->prepare("SELECT id, name FROM customers WHERE type = 'credit' AND status = 'active' AND station_id = ? ORDER BY name");
$customers->execute([$station_id]);
$customers_list = $customers->fetchAll(PDO::FETCH_ASSOC);

// Fetch Total Outstanding
$total_outstanding = $pdo->prepare("SELECT SUM(current_balance) FROM customers WHERE station_id = ?");
$total_outstanding->execute([$station_id]);
$total_outstanding_amount = $total_outstanding->fetchColumn() ?: 0;

// Fetch Credit Transactions
$sql = "SELECT s.*, u.name as staff_name, 
        (SELECT GROUP_CONCAT(CONCAT(name, ' (', qty, ')') SEPARATOR ', ') FROM sale_items WHERE sale_id = s.id) as items_summary
        FROM sales s 
        LEFT JOIN users u ON s.user_id = u.id 
        WHERE s.payment_method = 'Credit' AND s.station_id = ? 
        ORDER BY FIELD(s.status, 'Pending', 'Approved', 'Rejected') ASC, s.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute([$station_id]);
$transactions = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Credit / Utang Transactions</h1>
        <div class="sub">Admin View: Review, Approve, and Monitor Credit Sales</div>
    </div>
</div>

<?php if($msg): ?>
<div id="toast" class="toast show" style="background: <?php echo strpos($msg, 'Error')!==false ? '#dc3545' : '#ffc107'; ?>; color: #000;">
    <?php echo $msg; ?>
</div>
<script>setTimeout(() => document.getElementById('toast').remove(), 3000);</script>
<?php endif; ?>

<!-- Dashboard Card -->
<div class="cards three" style="margin-bottom: 20px;">
    <div class="card metric">
        <div class="metric-label">Total Outstanding Credit</div>
        <div class="metric-value">₱<?php echo number_format($total_outstanding_amount, 2); ?></div>
        <div class="metric-ico amber"><i class="fas fa-coins"></i></div>
    </div>
    <div class="card metric">
        <div class="metric-label">Pending Approvals</div>
        <div class="metric-value">
            <?php echo count(array_filter($transactions, fn($t) => $t['status'] === 'Pending')); ?>
        </div>
        <div class="metric-ico blue"><i class="fas fa-clock"></i></div>
    </div>
</div>

<div class="grid-2" style="gap: 20px; align-items: start; grid-template-columns: 350px 1fr;">
    <!-- Form Section (Collapsible or Always Visible) -->
    <div class="card" style="padding: 20px;">
        <h3 class="h3 mb-3"><i class="fas fa-plus-circle"></i> Encode Transaction</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_credit_sale">
            <div class="form-group mb-3">
                <label class="lbl">Customer</label>
                <select name="customer_id" class="inp full" required>
                    <option value="">-- Select Customer --</option>
                    <?php foreach($customers_list as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-3">
                <label class="lbl">Item / Fuel / Service</label>
                <input type="text" name="item_name" class="inp full" required placeholder="e.g. Diesel Max, Oil Change">
            </div>
            
            <div class="grid-2 mb-3" style="gap: 10px;">
                <div>
                    <label class="lbl">Qty/Liters</label>
                    <input type="number" name="quantity" class="inp full" value="1" step="0.01" required>
                </div>
                <div>
                    <label class="lbl">Unit Price</label>
                    <input type="number" name="price" class="inp full" step="0.01" required>
                </div>
            </div>
            
            <div class="form-group mb-3">
                <label class="lbl">Due Date</label>
                <input type="date" name="due_date" class="inp full" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
            
            <button type="submit" class="btn primary full">Submit for Approval</button>
        </form>
    </div>

    <!-- Table Section -->
    <div class="card" style="padding: 0;">
        <div class="card-head" style="padding: 15px; border-bottom: 1px solid #eee;">
            <h3 class="h3">Pending & Recent Transactions</h3>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer</th>
                        <th>Items / Details</th>
                        <th>Total</th>
                        <th>Due Date</th>
                        <th>Encoded By</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($transactions as $t): 
                        $statusClass = 'warning';
                        if($t['status'] == 'Approved') $statusClass = 'success';
                        elseif($t['status'] == 'Rejected') $statusClass = 'danger';
                        elseif($t['status'] == 'Pending') $statusClass = 'warning';
                        
                        // Overdue check
                        if($t['status'] == 'Approved' && strtotime($t['due_date']) < time()) {
                            $statusClass = 'danger';
                            $t['status'] = 'Overdue';
                        }
                    ?>
                    <tr>
                        <td><small>#<?php echo $t['id']; ?></small></td>
                        <td><b><?php echo htmlspecialchars($t['customer']); ?></b></td>
                        <td><?php echo htmlspecialchars($t['items_summary']); ?></td>
                        <td>₱<?php echo number_format($t['total'], 2); ?></td>
                        <td><small><?php echo $t['due_date']; ?></small></td>
                        <td><small><?php echo htmlspecialchars($t['staff_name']); ?></small></td>
                        <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $t['status']; ?></span></td>
                        <td>
                            <button class="btn ghost small icon" onclick="viewTransaction(<?php echo htmlspecialchars(json_encode($t)); ?>)"><i class="fas fa-eye"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($transactions)): ?>
                        <tr><td colspan="8" style="text-align:center; padding: 20px;">No transactions found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- View Transaction Modal -->
<div class="modal" id="viewModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Transaction Details</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modalContent"></div>
        </div>
        <div class="modal-footer" id="modalFooter">
            <!-- Buttons injected via JS -->
        </div>
    </div>
</div>

<script>
function viewTransaction(data) {
    const content = `
        <div style="margin-bottom: 15px;">
            <div style="font-size: 1.2em; font-weight: bold; color: var(--petron-blue);">${data.customer}</div>
            <div class="text-muted">Transaction #${data.id}</div>
        </div>
        <table class="table dense" style="margin-bottom: 15px;">
            <tr><td><strong>Items:</strong></td><td>${data.items_summary}</td></tr>
            <tr><td><strong>Total Amount:</strong></td><td style="font-size:1.1em; font-weight:bold;">₱${parseFloat(data.total).toLocaleString(undefined, {minimumFractionDigits:2})}</td></tr>
            <tr><td><strong>Encoded By:</strong></td><td>${data.staff_name} <br><small>${data.created_at}</small></td></tr>
            <tr><td><strong>Due Date:</strong></td><td>${data.due_date}</td></tr>
            <tr><td><strong>Status:</strong></td><td>${data.status}</td></tr>
        </table>
    `;
    document.getElementById('modalContent').innerHTML = content;

    let footer = `<button class="btn ghost" onclick="closeModal()">Close</button>`;
    
    // Only show Approve/Reject if Pending and User is Admin
    if (data.status === 'Pending' && <?php echo $isAdmin ? 'true' : 'false'; ?>) {
        footer = `
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="sale_id" value="${data.id}">
                <button type="submit" class="btn danger">Reject</button>
            </form>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="sale_id" value="${data.id}">
                <button type="submit" class="btn success">Approve</button>
            </form>
            <button class="btn ghost" onclick="closeModal()">Cancel</button>
        `;
    }
    
    document.getElementById('modalFooter').innerHTML = footer;
    document.getElementById('viewModal').classList.add('show');
}

function closeModal() {
    document.getElementById('viewModal').classList.remove('show');
}
</script>

<style>
    .table.dense th, .table.dense td { padding: 8px 12px; font-size: 0.9em; }
    .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.8em; color: white; }
    .bg-warning { background: #ffc107; color: #000; }
    .bg-success { background: #28a745; }
    .bg-danger { background: #dc3545; }
    .mb-3 { margin-bottom: 1rem; }
    .inp.full { width: 100%; }
    .btn.danger { background: #dc3545; color: white; border: none; }
    .btn.success { background: #28a745; color: white; border: none; }
    
    @media (max-width: 992px) {
        .grid-2 { grid-template-columns: 1fr !important; }
    }
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>
