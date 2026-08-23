<?php
$page_id = 'inventory';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/job_order_operations.php';
require_login();

$u = current_user();
$role = role_key($u['role'] ?? 'staff');
$station_id = user_station_id();

if (!in_array($role, ['manager', 'staff', 'admin', 'superadmin'], true)) {
    header('Location: dashboard.php');
    exit;
}

$ops = new JobOrderOperations($pdo, $u, $station_id);
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_station_item') {
        $result = $ops->addStationItem($_POST);
        $message = $result['message'] ?? '';
        $messageType = !empty($result['success']) ? 'success' : 'error';
    }

    if ($action === 'link_station_item') {
        $result = $ops->linkStationItemToJobOrder(
            $_POST['job_id'] ?? 0,
            $_POST['station_item_id'] ?? 0,
            $_POST['quantity'] ?? 1,
            $_POST['notes'] ?? null
        );
        $message = $result['message'] ?? '';
        $messageType = !empty($result['success']) ? 'success' : 'error';
    }

    if ($action === 'execute_station_item') {
        $result = $ops->executeLinkedJobItem(
            $_POST['link_id'] ?? 0,
            $_POST['execution_notes'] ?? null
        );
        $message = $result['message'] ?? '';
        $messageType = !empty($result['success']) ? 'success' : 'error';
    }
}

$stationItems = $ops->getStationItems('product');
$stationItems = $stationItems['data'] ?? [];

$linkedItemsPending = $ops->getLinkedJobItems(null, true);
$linkedItemsPending = $linkedItemsPending['data'] ?? [];

$linkedItemsAll = $ops->getLinkedJobItems(null, false);
$linkedItemsAll = $linkedItemsAll['data'] ?? [];

$activeJobs = [];
try {
    $stmt = $pdo->prepare("SELECT id, job_order_number, status FROM job_orders WHERE station_id = ? AND status IN ('Pending','In Progress','Awaiting Parts') ORDER BY created_at DESC LIMIT 200");
    $stmt->execute([$station_id]);
    $activeJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activeJobs = [];
}

$productCategories = [];
try {
    $catStmt = $pdo->query("SELECT id, name FROM product_categories ORDER BY name ASC");
    $productCategories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
    $productCategories = [];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Products & Job Links</h1>
        <div class="sub">Manager defines products with categories, links them to job orders, and staff executes assigned products.</div>
    </div>
</div>

<?php if ($message !== ''): ?>
<div class="card" style="padding:12px; margin-bottom:14px; background: <?php echo $messageType === 'success' ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $messageType === 'success' ? '#155724' : '#721c24'; ?>;">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($role === 'manager'): ?>
<div class="card" style="padding:16px; margin-bottom:16px;">
    <h3 style="margin:0 0 12px 0;">1) Add Product</h3>
    <form method="post" class="grid-4" style="display:grid; grid-template-columns:1fr 1.2fr 1.2fr .8fr auto; gap:8px; align-items:end;">
        <input type="hidden" name="action" value="add_station_item">
        <input type="hidden" name="item_type" value="product">
        <div>
            <label class="lbl">Category</label>
            <select name="category_id" class="inp full" required>
                <option value="">Select category</option>
                <?php foreach ($productCategories as $category): ?>
                    <option value="<?php echo (int)$category['id']; ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="lbl">Product Name</label>
            <input type="text" name="name" class="inp full" required>
        </div>
        <div>
            <label class="lbl">Description</label>
            <input type="text" name="description" class="inp full">
        </div>
        <div>
            <label class="lbl">Unit Price</label>
            <input type="number" step="0.01" min="0" name="unit_price" class="inp full" value="0">
        </div>
        <button class="btn primary" type="submit">Add</button>
    </form>
</div>

<div class="card" style="padding:16px; margin-bottom:16px;">
    <h3 style="margin:0 0 12px 0;">2) Link Product to Job Order</h3>
    <form method="post" style="display:grid; grid-template-columns:1.2fr 1.2fr .6fr 1.2fr auto; gap:8px; align-items:end;">
        <input type="hidden" name="action" value="link_station_item">
        <div>
            <label class="lbl">Job Order</label>
            <select name="job_id" class="inp full" required>
                <option value="">Select job order</option>
                <?php foreach ($activeJobs as $job): ?>
                    <option value="<?php echo (int)$job['id']; ?>"><?php echo htmlspecialchars(($job['job_order_number'] ?: ('JO-' . $job['id'])) . ' (' . $job['status'] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="lbl">Product</label>
            <select name="station_item_id" class="inp full" required>
                <option value="">Select product</option>
                <?php foreach ($stationItems as $item): ?>
                    <option value="<?php echo (int)$item['id']; ?>"><?php echo htmlspecialchars(($item['category_name'] ? '[' . $item['category_name'] . '] ' : '') . $item['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="lbl">Qty</label>
            <input type="number" step="0.01" min="0.01" name="quantity" class="inp full" value="1" required>
        </div>
        <div>
            <label class="lbl">Notes</label>
            <input type="text" name="notes" class="inp full" placeholder="Optional linking note">
        </div>
        <button class="btn primary" type="submit">Link</button>
    </form>
</div>
<?php endif; ?>

<div class="card" style="padding:16px; margin-bottom:16px;">
    <h3 style="margin:0 0 10px 0;">3) Staff Execution Queue</h3>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Job Order</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Linked By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linkedItemsPending as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['job_order_number'] ?: ('JO-' . $row['job_order_id'])); ?></td>
                    <td><?php echo htmlspecialchars($row['station_item_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($row['linked_by_name'] ?? 'N/A'); ?></td>
                    <td>
                        <?php if ($role === 'staff'): ?>
                            <form method="post" style="display:flex; gap:6px; align-items:center;">
                                <input type="hidden" name="action" value="execute_station_item">
                                <input type="hidden" name="link_id" value="<?php echo (int)$row['id']; ?>">
                                <input type="text" name="execution_notes" class="inp" placeholder="Execution note" style="max-width:180px;">
                                <button class="btn small success" type="submit">Execute</button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-secondary">Pending Staff</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($linkedItemsPending)): ?>
                <tr><td colspan="6" style="text-align:center;">No pending linked products.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="padding:16px;">
    <h3 style="margin:0 0 10px 0;">4) Audit Trace (Station + Job Order + Product)</h3>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Job Order</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Linked By</th>
                    <th>Linked At</th>
                    <th>Executed By</th>
                    <th>Executed At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linkedItemsAll as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['job_order_number'] ?: ('JO-' . $row['job_order_id'])); ?></td>
                    <td><?php echo htmlspecialchars($row['station_item_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($row['linked_by_name'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($row['linked_at'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['executed_by_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['executed_at'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($linkedItemsAll)): ?>
                <tr><td colspan="8" style="text-align:center;">No linked records yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
