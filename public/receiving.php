<?php
$page_id = 'receive_items';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$station_id = user_station_id();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_id'])) {
    $po_id = $_POST['po_id'];
    $received_items = $_POST['received'] ?? []; // Array of item_id => qty
    $feedback = $_POST['feedback'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        foreach ($received_items as $item_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                // Update PO Item
                $stmt = $pdo->prepare("UPDATE purchase_order_items SET received_quantity = received_quantity + ? WHERE id = ?");
                $stmt->execute([$qty, $item_id]);
                
                // Get Item Name
                $item = $pdo->prepare("SELECT item_name, unit_price FROM purchase_order_items WHERE id = ?");
                $item->execute([$item_id]);
                $i = $item->fetch();
                
                // Update Inventory (Add stock)
                // Check if exists
                $inv = $pdo->prepare("SELECT id FROM station_inventory WHERE product_name = ? AND station_id = ?");
                $inv->execute([$i['item_name'], $station_id]);
                if ($inv_id = $inv->fetchColumn()) {
                    $upd = $pdo->prepare("UPDATE station_inventory SET stock_level = stock_level + ? WHERE id = ?");
                    $upd->execute([$qty, $inv_id]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO station_inventory (station_id, product_name, stock_level, type, cost) VALUES (?, ?, ?, 'merch', ?)");
                    $ins->execute([$station_id, $i['item_name'], $qty, $i['unit_price']]);
                }
            }
        }
        
        // Update PO Status
        $updPo = $pdo->prepare("UPDATE purchase_orders SET status = 'Received', remarks = CONCAT(COALESCE(remarks,''), '\nFeedback: ', ?) WHERE id = ?");
        $updPo->execute([$feedback, $po_id]);
        
        log_activity($pdo, $me['id'], 'Receive Items', "Received items for PO #$po_id");
        $pdo->commit();
        $msg = "✅ Items received and inventory updated.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = "❌ Error: " . $e->getMessage();
    }
}

// Fetch Confirmed POs
$pos = $pdo->prepare("SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id WHERE po.station_id = ? AND po.status = 'Confirmed' ORDER BY po.created_at DESC");
$pos->execute([$station_id]);
$confirmed_pos = $pos->fetchAll();

include __DIR__ . '/../partials/header.php';
?>
<div class="page-head">
    <div>
        <h1 class="h1">Receiving & Feedback</h1>
        <div class="sub">Record received items and update inventory</div>
    </div>
</div>

<?php if($msg): ?><div class="card" style="padding:10px; margin-bottom:20px; background:#e6f4ea; color:green;"><?php echo $msg; ?></div><?php endif; ?>

<div class="grid-2">
    <?php foreach($confirmed_pos as $po): 
        $items = $pdo->prepare("SELECT * FROM purchase_order_items WHERE po_id = ?");
        $items->execute([$po['id']]);
        $po_items = $items->fetchAll();
    ?>
    <div class="card" style="padding:20px;">
        <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
            <h3 class="h3"><?php echo htmlspecialchars($po['po_number']); ?></h3>
            <span class="badge"><?php echo htmlspecialchars($po['supplier_name']); ?></span>
        </div>
        
        <form method="post">
            <input type="hidden" name="po_id" value="<?php echo $po['id']; ?>">
            <table class="table" style="margin-bottom:15px;">
                <thead><tr><th>Item</th><th>Ordered</th><th>Receive</th></tr></thead>
                <tbody>
                    <?php foreach($po_items as $item): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo $item['quantity']; ?></td>
                        <td>
                            <input type="number" name="received[<?php echo $item['id']; ?>]" class="inp small" value="<?php echo $item['quantity']; ?>" min="0" max="<?php echo $item['quantity']; ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <label class="lbl">Feedback / Notes</label>
            <textarea name="feedback" class="inp" rows="2" placeholder="Any damages or missing items?"></textarea>
            
            <button type="submit" class="btn primary full" style="margin-top:10px;">Submit Receiving</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if(empty($confirmed_pos)): ?>
        <div class="card" style="padding:20px; grid-column:1/-1; text-align:center;">No confirmed orders to receive.</div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
