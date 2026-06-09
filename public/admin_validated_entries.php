<?php
$page_id = 'admin_validated_entries';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict access to Admin/Owner roles only
if (!in_array($role, ['admin', 'owner', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Admin/Owner access required for Validated Entries.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();

// Handle filters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$transaction_type = $_GET['transaction_type'] ?? 'all';
$staff_filter = $_GET['staff_filter'] ?? '';

// Get validated entries with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    // Base query for validated entries
    $base_query = "
        SELECT 
            combined.transaction_id,
            combined.type,
            combined.status,
            combined.created_at,
            combined.staff_id,
            COALESCE(u.name, 'System') as staff_name,
            CASE 
                WHEN combined.type = 'fuel' THEN ft.fuel_type
                ELSE GROUP_CONCAT(DISTINCT si.name SEPARATOR ', ')
            END as product_name,
            COALESCE(ft.total_amount, s.total, 0) as amount,
            CASE 
                WHEN combined.type = 'fuel' THEN ft.payment_method
                ELSE s.payment_method
            END as payment_method,
            CASE 
                WHEN combined.type = 'fuel' THEN ft.liters_sold
                ELSE SUM(si.quantity)
            END as quantity,
            CASE 
                WHEN combined.type = 'fuel' THEN ft.price_per_liter
                ELSE AVG(si.unit_price)
            END as unit_price,
            combined.manager_id,
            manager.name as manager_name,
            combined.action,
            combined.reason
        FROM (
            SELECT transaction_id, status, total_amount, created_at, staff_id, manager_id, action, reason, 'fuel' as type 
            FROM fuel_transactions WHERE station_id = ? AND status = 'Complete'
            UNION ALL
            SELECT id as transaction_id, status, total as total_amount, created_at, user_id as staff_id, manager_id, action, reason, 'merchandise' as type 
            FROM sales WHERE station_id = ? AND status = 'Complete'
        ) combined
        LEFT JOIN fuel_transactions ft ON combined.transaction_id = ft.transaction_id AND combined.type = 'fuel'
        LEFT JOIN sales s ON combined.transaction_id = s.id AND combined.type = 'merchandise'
        LEFT JOIN users u ON combined.staff_id = u.id
        LEFT JOIN users manager ON combined.manager_id = manager.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE DATE(combined.created_at) BETWEEN ? AND ?
    ";

    $params = [$station_id, $station_id, $start_date, $end_date];

    // Apply filters
    if ($transaction_type !== 'all') {
        $base_query .= " AND combined.type = ?";
        $params[] = $transaction_type;
    }

    if (!empty($staff_filter)) {
        $base_query .= " AND u.name LIKE ?";
        $params[] = "%$staff_filter%";
    }

    $base_query .= " GROUP BY combined.transaction_id ORDER BY combined.created_at DESC";

    // Get total count for pagination
    $count_query = str_replace("SELECT combined.transaction_id,", "SELECT COUNT(DISTINCT combined.transaction_id) as total,", $base_query);
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_entries = $stmt->fetch()['total'];
    $total_pages = ceil($total_entries / $limit);

    // Get paginated results
    $query_with_pagination = $base_query . " LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($query_with_pagination);
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $validated_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get staff list for filter dropdown
    $stmt = $pdo->prepare("
        SELECT DISTINCT u.name 
        FROM users u
        WHERE u.station_id = ? AND u.status = 'Active'
        ORDER BY u.name
    ");
    $stmt->execute([$station_id]);
    $staff_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_validated,
            SUM(CASE WHEN combined.type = 'fuel' THEN 1 ELSE 0 END) as fuel_count,
            SUM(CASE WHEN combined.type = 'merchandise' THEN 1 ELSE 0 END) as merchandise_count,
            SUM(COALESCE(ft.total_amount, 0) + COALESCE(s.total, 0)) as total_amount,
            AVG(COALESCE(ft.total_amount, 0) + COALESCE(s.total, 0)) as avg_amount
        FROM (
            SELECT transaction_id, status, total_amount, created_at, 'fuel' as type 
            FROM fuel_transactions WHERE station_id = ? AND status = 'Complete'
            UNION ALL
            SELECT id as transaction_id, status, total as total_amount, created_at, 'merchandise' as type 
            FROM sales WHERE station_id = ? AND status = 'Complete'
        ) combined
        LEFT JOIN fuel_transactions ft ON combined.transaction_id = ft.transaction_id AND combined.type = 'fuel'
        LEFT JOIN sales s ON combined.transaction_id = s.id AND combined.type = 'merchandise'
        WHERE DATE(combined.created_at) BETWEEN ? AND ?
    ";
    
    $stmt = $pdo->prepare($summary_query);
    $stmt->execute([$station_id, $station_id, $start_date, $end_date]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Admin validated entries error: " . $e->getMessage());
    $validated_entries = [];
    $total_entries = 0;
    $total_pages = 0;
    $staff_list = [];
    $summary = ['total_validated' => 0, 'fuel_count' => 0, 'merchandise_count' => 0, 'total_amount' => 0, 'avg_amount' => 0];
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Validated Entries</h1>
        <div class="sub">View and monitor all validated transactions from Manager approval</div>
    </div>
    <div class="actions">
        <button onclick="exportToExcel()" class="btn ghost"><i class="fas fa-file-excel"></i> Export Excel</button>
        <button onclick="window.print()" class="btn ghost"><i class="fas fa-print"></i> Print</button>
        <a href="dashboard.php" class="btn primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>

<!-- Summary Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div class="card" style="text-align: center; padding: 15px;">
        <div style="font-size: 2rem; font-weight: bold; color: #28a745;"><?php echo number_format($summary['total_validated']); ?></div>
        <div style="color: #666;">Total Validated</div>
    </div>
    <div class="card" style="text-align: center; padding: 15px;">
        <div style="font-size: 1.5rem; font-weight: bold; color: #dc3545;"><?php echo number_format($summary['fuel_count']); ?></div>
        <div style="color: #666;">Fuel Transactions</div>
    </div>
    <div class="card" style="text-align: center; padding: 15px;">
        <div style="font-size: 1.5rem; font-weight: bold; color: #007bff;"><?php echo number_format($summary['merchandise_count']); ?></div>
        <div style="color: #666;">Merchandise Transactions</div>
    </div>
    <div class="card" style="text-align: center; padding: 15px;">
        <div style="font-size: 1.5rem; font-weight: bold; color: #17a2b8;"><?php echo number_format($summary['total_amount'], 2); ?></div>
        <div style="color: #666;">Total Amount</div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header">
        <h3><i class="fas fa-filter"></i> Filters</h3>
    </div>
    <div class="card-body">
        <form method="GET" style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap;">
            <div>
                <label class="lbl">Date Range</label>
                <div style="display: flex; gap: 5px;">
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="inp">
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="inp">
                </div>
            </div>
            <div>
                <label class="lbl">Transaction Type</label>
                <select name="transaction_type" class="inp">
                    <option value="all" <?php echo $transaction_type === 'all' ? 'selected' : ''; ?>>All Types</option>
                    <option value="fuel" <?php echo $transaction_type === 'fuel' ? 'selected' : ''; ?>>Fuel</option>
                    <option value="merchandise" <?php echo $transaction_type === 'merchandise' ? 'selected' : ''; ?>>Merchandise</option>
                </select>
            </div>
            <div>
                <label class="lbl">Staff Name</label>
                <select name="staff_filter" class="inp">
                    <option value="">All Staff</option>
                    <?php foreach ($staff_list as $staff): ?>
                        <option value="<?php echo htmlspecialchars($staff); ?>" <?php echo $staff_filter === $staff ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($staff); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn primary">Apply Filters</button>
            <a href="admin_validated_entries.php" class="btn ghost">Clear</a>
        </form>
    </div>
</div>

<!-- Validated Entries Table -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-check-circle"></i> Validated Transactions</h3>
        <div style="font-size: 0.9rem; color: #666;">
            Showing <?php echo count($validated_entries); ?> of <?php echo $total_entries; ?> entries
        </div>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Type</th>
                        <th>Product(s)</th>
                        <th>Staff</th>
                        <th>Manager</th>
                        <th>Quantity</th>
                        <th>Unit Price</th>
                        <th>Total Amount</th>
                        <th>Payment</th>
                        <th>Date/Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($validated_entries)): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 30px; color: #666;">
                                <i class="fas fa-search" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                No validated entries found for the selected filters
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($validated_entries as $entry): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($entry['transaction_id']); ?></td>
                                <td>
                                    <span class="badge" style="background: <?php echo $entry['type'] === 'fuel' ? '#dc3545' : '#007bff'; ?>; color: white;">
                                        <?php echo ucfirst($entry['type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($entry['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($entry['staff_name']); ?></td>
                                <td><?php echo htmlspecialchars($entry['manager_name'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($entry['quantity'], 2); ?></td>
                                <td><?php echo number_format($entry['unit_price'], 2); ?></td>
                                <td style="font-weight: bold;"><?php echo number_format($entry['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($entry['payment_method']); ?></td>
                                <td><?php echo date('M d, H:i', strtotime($entry['created_at'])); ?></td>
                                <td>
                                    <span class="badge" style="background: #28a745; color: white;">
                                        <?php echo ucfirst($entry['action'] ?? 'Approved'); ?>
                                    </span>
                                    <?php if (!empty($entry['reason'])): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($entry['reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 10px; margin-top: 20px;">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" class="btn ghost">Previous</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" 
                       class="btn <?php echo $i === $page ? 'primary' : 'ghost'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_diff_key($_GET, ['page' => ''])); ?>" class="btn ghost">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function exportToExcel() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'admin_validated_entries_export.php?' + params.toString();
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
