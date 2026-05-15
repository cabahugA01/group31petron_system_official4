<?php
$page_id = 'credit_transactions_admin';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
// Credit approval: Admin ONLY per hierarchy (Manager cannot approve credits)
$isAdmin = in_array($me['role'], ['admin', 'superadmin']);
$msg = '';

// Ensure sales table has user_id for tracking
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN user_id INT NULL");
} catch (Exception $e) {}

// Ensure sales table has customer_id for tracking
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN customer_id INT NULL AFTER user_id");
} catch (Exception $e) {}

// Ensure customers table has station_id
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN station_id INT NULL DEFAULT NULL");
} catch (Exception $e) {}

// Ensure sales table has station_id
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN station_id INT NULL DEFAULT NULL");
} catch (Exception $e) {}

// Ensure sales table has due_date if not exists
try {
    $pdo->exec("ALTER TABLE sales ADD COLUMN due_date DATE NULL");
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
    elseif ($action === 'adjust_terms' && $isAdmin) {
        $sale_id = $_POST['sale_id'];
        $new_due_date = $_POST['due_date'];
        try {
            $pdo->prepare("UPDATE sales SET due_date = ? WHERE id = ?")->execute([$new_due_date, $sale_id]);
            $msg = "✅ Terms adjusted.";
        } catch (Exception $e) { $msg = "❌ Error: " . $e->getMessage(); }
    }
    elseif ($action === 'mark_paid' && $isAdmin) {
        $customer_id = $_POST['customer_id'];
        $amount = (float)$_POST['amount'];
        try {
            $pdo->prepare("UPDATE customers SET current_balance = current_balance - ? WHERE id = ?")->execute([$amount, $customer_id]);
            log_activity($pdo, $me['id'], 'Payment Received', "Recorded payment of ₱$amount for customer #$customer_id");
            $msg = "✅ Payment recorded.";
        } catch (Exception $e) { $msg = "❌ Error: " . $e->getMessage(); }
    }
}

// Fetch Customers for Dropdown
$customers = $pdo->prepare("SELECT id, name FROM customers WHERE type = 'credit' AND status = 'active' AND (station_id = ? OR station_id IS NULL) ORDER BY name");
$customers->execute([$station_id]);
$customers_list = $customers->fetchAll(PDO::FETCH_ASSOC);

// Fetch Ledger (Customers with outstanding balance)
$ledger_sql = "SELECT * FROM customers WHERE current_balance > 0 AND (station_id = ? OR station_id IS NULL) ORDER BY current_balance DESC";
$ledger_stmt = $pdo->prepare($ledger_sql);
$ledger_stmt->execute([$station_id]);
$ledger = $ledger_stmt->fetchAll();

// Fetch Credit Transactions
$sql = "SELECT s.*, u.name as staff_name, 
        p.name as product_name, si.quantity, si.unit_price, si.total_amount as subtotal
        FROM sales s 
        JOIN sale_items si ON s.id = si.sale_id
        JOIN products p ON si.product_id = p.id
        LEFT JOIN users u ON s.user_id = u.id 
        WHERE s.payment_method = 'Credit' AND (s.station_id = ? OR s.station_id IS NULL) 
        ORDER BY FIELD(s.status, 'Pending', 'Approved', 'Rejected') ASC, s.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute([$station_id]);
$transactions = $stmt->fetchAll();

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Credit / Utang Transaction (Admin View)</h1>
        <div class="sub">Oversight of receivables. Admin validates credit entries and monitors outstanding balances.</div>
    </div>
</div>

<?php if($msg): ?>
<div id="toast" class="toast show" style="background: <?php echo strpos($msg, 'Error')!==false ? '#dc3545' : '#28a745'; ?>;">
    <?php echo $msg; ?>
</div>
<script>setTimeout(() => document.getElementById('toast').remove(), 3000);</script>
<?php endif; ?>

<!-- Outstanding Credit Ledger Card -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-head">
        <h3 class="h3">Outstanding Credit Ledger</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Balance</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($ledger as $l): 
                    // Determine status based on due date logic (simplified: if balance > 0, check if any overdue)
                    // For this view, we'll just show Active if balance > 0
                    $status = 'Active';
                    $statusClass = 'success';
                    // In a real app, we'd query the earliest due date of unpaid invoices
                ?>
                <tr>
                    <td><b><?php echo htmlspecialchars($l['name']); ?></b></td>
                    <td style="font-weight:bold; color:var(--petron-red);">₱<?php echo number_format($l['current_balance'], 2); ?></td>
                    <td>-</td> <!-- Could be populated with next due date -->
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $status; ?></span></td>
                    <td>
                        <button class="btn small primary" onclick="openPaymentModal(<?php echo $l['id']; ?>, '<?php echo htmlspecialchars($l['name']); ?>', <?php echo $l['current_balance']; ?>)">Mark Paid</button>
                        <button class="btn small ghost">Follow-up</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($ledger)): ?>
                    <tr><td colspan="5" style="text-align:center; padding:15px;">No outstanding credits.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Credit Transactions Table -->
<div class="card">
    <div class="card-head">
        <h3 class="h3">Credit Transactions</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Transaction ID</th>
                    <th>Customer Name</th>
                    <th>Product/Service</th>
                    <th>Qty/Liters</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                    <th>Due Date</th>
                    <th>Staff Encoder</th>
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
                    <td>#<?php echo $t['id']; ?></td>
                    <td><b><?php echo htmlspecialchars($t['customer']); ?></b></td>
                    <td><?php echo htmlspecialchars($t['product_name']); ?></td>
                    <td><?php echo number_format($t['quantity'], 2); ?></td>
                    <td>₱<?php echo number_format($t['unit_price'], 2); ?></td>
                    <td>₱<?php echo number_format($t['subtotal'], 2); ?></td>
                    <td><?php echo $t['due_date']; ?></td>
                    <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo $t['status']; ?></span></td>
                    <td>
                        <?php if($t['status'] == 'Pending'): ?>
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
                        <?php endif; ?>
                        <button class="btn small ghost" onclick="adjustTerms(<?php echo $t['id']; ?>, '<?php echo $t['due_date']; ?>')" title="Adjust Terms">✏️</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($transactions)): ?>
                    <tr><td colspan="10" style="text-align:center; padding: 20px;">No transactions found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Adjust Terms Modal -->
<div class="modal" id="adjustModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Adjust Terms</h3>
            <button class="modal-close" onclick="closeModal('adjustModal')">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="adjust_terms">
                <input type="hidden" name="sale_id" id="adjustSaleId">
                <div class="form-group">
                    <label class="lbl">New Due Date</label>
                    <input type="date" name="due_date" id="adjustDueDate" class="inp full" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal" id="paymentModal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">Record Payment</h3>
            <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <form method="post">
            <div class="modal-body">
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="customer_id" id="payCustId">
                <p>Customer: <strong id="payCustName"></strong></p>
                <p>Current Balance: <strong id="payCustBal"></strong></p>
                <div class="form-group">
                    <label class="lbl">Payment Amount</label>
                    <input type="number" name="amount" class="inp full" step="0.01" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn primary">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
function adjustTerms(id, date) {
    document.getElementById('adjustSaleId').value = id;
    document.getElementById('adjustDueDate').value = date;
    document.getElementById('adjustModal').classList.add('show');
}

function openPaymentModal(id, name, balance) {
    document.getElementById('payCustId').value = id;
    document.getElementById('payCustName').innerText = name;
    document.getElementById('payCustBal').innerText = '₱' + parseFloat(balance).toLocaleString(undefined, {minimumFractionDigits:2});
    document.getElementById('paymentModal').classList.add('show');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('show');
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
