<?php
$page_id = 'confirm_supplier_delivery';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_id'])) {
    $po_id = $_POST['po_id'];
    $delivery_date = $_POST['delivery_date'];
    
    try {
        $stmt = $pdo->prepare("UPDATE purchase_orders SET status = 'Confirmed', expected_delivery_date = ? WHERE id = ? AND station_id = ?");
        $stmt->execute([$delivery_date, $po_id, $station_id]);
        log_activity($pdo, $me['id'], 'Confirm PO', "Confirmed PO #$po_id");
        $msg = "✅ Purchase Order confirmed.";
    } catch (Exception $e) {
        $msg = "❌ Error: " . $e->getMessage();
    }
}

// Fetch Pending POs
$pos = $pdo->prepare("SELECT po.*, s.name as supplier_name, 
    (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.id) as item_count 
    FROM purchase_orders po 
    LEFT JOIN suppliers s ON po.supplier_id = s.id 
    WHERE po.station_id = ? AND po.status = 'Pending' 
    ORDER BY po.created_at DESC");
$pos->execute([$station_id]);
$pending_pos = $pos->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">Supplier Confirmation</h1>
        <div class="sub">Confirm delivery schedules for pending orders</div>
    </div>
</div>

<?php if($msg): ?><div class="card" style="padding:10px; margin-bottom:20px; background:#e6f4ea; color:green;"><?php echo $msg; ?></div><?php endif; ?>

<section class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Items</th>
                    <th>Date Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($pending_pos as $po): ?>
                <tr>
                    <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                    <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                    <td><?php echo $po['item_count']; ?> items</td>
                    <td><?php echo date('M d, Y', strtotime($po['created_at'])); ?></td>
                    <td>
                        <form method="post" style="display:flex; gap:5px;">
                            <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
                            <input type="date" name="delivery_date" class="inp small" required title="Expected Delivery Date">
                            <button type="submit" class="btn primary small">Confirm</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($pending_pos)): ?>
                    <tr><td colspan="5" style="text-align:center;">No pending purchase orders.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<style>
    .inp.small { padding: 4px; width: auto; }
    .btn.small { padding: 4px 8px; font-size: 0.85em; }
</style>
<?php include __DIR__ . '/../partials/footer.php'; ?>
