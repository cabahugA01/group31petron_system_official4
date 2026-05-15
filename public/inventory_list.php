<?php
/**
 * INVENTORY LIST - Inventory View
 * 
 * Displays current inventory with:
 * - Product names and SKUs
 * - Current stock quantities for the user's station
 * - Cost and selling prices (hidden for staff - read-only view)
 * - Last updated timestamps
 * - Search and filter functionality
 * 
 * Access:
 * - Staff: Read-only view (stock levels and selling prices only)
 * - Manager/Admin/Superadmin: Full view with cost prices and margins
 */

$page_id = 'inventory_list';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Staff, Manager, Admin, and Superadmin can view inventory list
// Staff gets read-only access with limited information
if (!in_array($role, ['staff', 'manager', 'admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit;
}

// Determine if user can see cost/margin data (staff cannot)
$can_see_costs = in_array($role, ['manager', 'admin', 'superadmin']);

// Get search and filter parameters
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'name';
$order = $_GET['order'] ?? 'ASC';

// Validate sort column - staff can't sort by cost
$allowed_sorts = ['name', 'sku', 'stock_level', 'price', 'last_updated'];
if ($can_see_costs) {
    $allowed_sorts[] = 'cost';
}
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'name';
}

// Initialize search variables
$where_sql = "";
$params = [];

// Build search condition if search term is provided
if (!empty($search)) {
    $where_sql = " AND (p.name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = "%$search%";
}

// Add station filter for managers and staff
if ($station_id && in_array($role, ['manager', 'staff'])) {
    $where_sql .= " AND si.station_id = :station_id";
    $params[':station_id'] = $station_id;
}

// Build query
// Use INNER JOIN for station_inventory when filtering by station to ensure only that station's items are shown
$join_type = ($station_id && in_array($role, ['manager', 'staff'])) ? 'INNER JOIN' : 'LEFT JOIN';

$sql = "
    SELECT
        p.id,
        p.name,
        p.sku,
        p.cost,
        p.price,
        p.updated_at as price_updated,
        si.stock_level,
        si.last_updated,
        s.name as station_name
    FROM products p
    {$join_type} station_inventory si ON p.id = si.product_id
    {$join_type} stations s ON si.station_id = s.id
    WHERE 1=1
    {$where_sql}
    ORDER BY p.{$sort} {$order}
    ";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $inventory = [];
    $error = "Error fetching inventory: " . $e->getMessage();
}

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Inventory List</h1>
        <div class="sub">
            <?php if ($can_see_costs): ?>
                View and verify product prices and stock quantities
            <?php else: ?>
                View product stock levels and selling prices (read-only)
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin: 20px auto; max-width: 1400px;">
    <div style="padding: 20px;">
        
        <!-- Search and Filter Section -->
        <div style="display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
            <form method="get" style="display: flex; gap: 10px; flex-grow: 1;">
                <input type="text" 
                    name="search" 
                    placeholder="Search product name or SKU..." 
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; flex-grow: 1;">
                
                <select name="sort" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Sort by Name</option>
                    <option value="sku" <?php echo $sort === 'sku' ? 'selected' : ''; ?>>Sort by SKU</option>
                    <option value="stock_level" <?php echo $sort === 'stock_level' ? 'selected' : ''; ?>>Sort by Stock</option>
                    <?php if ($can_see_costs): ?>
                    <option value="cost" <?php echo $sort === 'cost' ? 'selected' : ''; ?>>Sort by Cost Price</option>
                    <?php endif; ?>
                    <option value="price" <?php echo $sort === 'price' ? 'selected' : ''; ?>>Sort by Selling Price</option>
                    <option value="last_updated" <?php echo $sort === 'last_updated' ? 'selected' : ''; ?>>Sort by Updated</option>
                </select>
                
                <select name="order" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                </select>
                
                <button type="submit" class="btn primary" style="padding: 10px 20px;">
                    <i class="fas fa-search"></i> Search
                </button>
                
                <?php if (!empty($search) || $sort !== 'name' || $order !== 'ASC'): ?>
                <a href="inventory_list.php" class="btn secondary" style="padding: 10px 20px;">
                    <i class="fas fa-times"></i> Clear
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Summary Stats -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Items</div>
                <div style="font-size: 24px; font-weight: bold; color: #002F6C;"><?php echo count($inventory); ?></div>
            </div>
            
            <?php 
            $total_cost_value = 0;
            $total_selling_value = 0;
            foreach ($inventory as $item) {
                if ($item['stock_level']) {
                    $total_cost_value += ($item['cost'] ?? 0) * $item['stock_level'];
                    $total_selling_value += ($item['price'] ?? 0) * $item['stock_level'];
                }
            }
            ?>
            
            <?php if ($can_see_costs): ?>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Cost Value</div>
                <div style="font-size: 24px; font-weight: bold; color: #002F6C;">₱<?php echo number_format($total_cost_value, 2); ?></div>
            </div>
            <?php endif; ?>
            
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Selling Value</div>
                <div style="font-size: 24px; font-weight: bold; color: #002F6C;">₱<?php echo number_format($total_selling_value, 2); ?></div>
            </div>
            
            <?php if ($can_see_costs): ?>
            <?php 
            $total_margin = 0;
            if ($total_cost_value > 0) {
                $total_margin = (($total_selling_value - $total_cost_value) / $total_cost_value) * 100;
            }
            ?>
            
            <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; text-align: center;">
                <div style="font-size: 12px; color: #666; margin-bottom: 5px;">Overall Margin</div>
                <div style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo number_format($total_margin, 1); ?>%</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Inventory Table -->
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #002F6C; color: white;">
                        <th style="padding: 12px; text-align: left;">Product Name</th>
                        <th style="padding: 12px; text-align: center;">SKU</th>
                        <th style="padding: 12px; text-align: right;">Stock Qty</th>
                        <?php if ($can_see_costs): ?>
                        <th style="padding: 12px; text-align: right;">Cost Price</th>
                        <?php endif; ?>
                        <th style="padding: 12px; text-align: right;">Selling Price</th>
                        <?php if ($can_see_costs): ?>
                        <th style="padding: 12px; text-align: right;">Margin</th>
                        <th style="padding: 12px; text-align: center;">Stock Value</th>
                        <?php endif; ?>
                        <th style="padding: 12px; text-align: center;">Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($inventory) > 0): ?>
                        <?php foreach ($inventory as $item): ?>
                        <tr style="border-bottom: 1px solid #ddd; hover-background: #f5f5f5;">
                            <td style="padding: 12px;">
                                <div style="font-weight: bold;"><?php echo htmlspecialchars($item['name']); ?></div>
                                <?php if (!empty($item['station_name'])): ?>
                                <div style="font-size: 11px; color: #999;"><?php echo htmlspecialchars($item['station_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <code style="background: #f0f0f0; padding: 3px 8px; border-radius: 3px; font-size: 12px;">
                                    <?php echo htmlspecialchars($item['sku'] ?? 'N/A'); ?>
                                </code>
                            </td>
                            <td style="padding: 12px; text-align: right;">
                                <strong style="font-size: 16px;">
                                    <?php echo number_format($item['stock_level'] ?? 0, 2); ?>
                                </strong>
                            </td>
                            <?php if ($can_see_costs): ?>
                            <td style="padding: 12px; text-align: right;">
                                ₱<?php echo number_format($item['cost'] ?? 0, 2); ?>
                            </td>
                            <?php endif; ?>
                            <td style="padding: 12px; text-align: right;">
                                <strong>₱<?php echo number_format($item['price'] ?? 0, 2); ?></strong>
                            </td>
                            <?php if ($can_see_costs): ?>
                            <td style="padding: 12px; text-align: right;">
                                <?php 
                                $margin = 0;
                                if (($item['cost'] ?? 0) > 0) {
                                    $margin = (($item['price'] - $item['cost']) / $item['cost']) * 100;
                                }
                                $margin_color = $margin < 0 ? '#dc3545' : ($margin < 10 ? '#ff9800' : '#28a745');
                                ?>
                                <span style="color: <?php echo $margin_color; ?>; font-weight: bold;">
                                    <?php echo number_format($margin, 1); ?>%
                                </span>
                            </td>
                            <td style="padding: 12px; text-align: center;">
                                <?php 
                                $stock_value = ($item['price'] ?? 0) * ($item['stock_level'] ?? 0);
                                ?>
                                <strong>₱<?php echo number_format($stock_value, 2); ?></strong>
                            </td>
                            <?php endif; ?>
                            <td style="padding: 12px; text-align: center;">
                                <div style="font-size: 12px; color: #666;">
                                    <?php 
                                    if (!empty($item['last_updated'])) {
                                        echo date('M d, Y', strtotime($item['last_updated']));
                                        echo '<br>';
                                        echo date('H:i', strtotime($item['last_updated']));
                                    } else {
                                        echo 'Never';
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $can_see_costs ? '8' : '5'; ?>" style="padding: 30px; text-align: center; color: #999;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                            No inventory items found. Try adjusting your search filters.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Legend (only for roles that can see margins) -->
        <?php if ($can_see_costs): ?>
        <div style="margin-top: 20px; padding: 15px; background: #f5f5f5; border-radius: 8px;">
            <div style="font-weight: bold; margin-bottom: 10px;">Legend:</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 12px;">
                <div>
                    <span style="display: inline-block; width: 12px; height: 12px; background: #dc3545; border-radius: 2px; margin-right: 5px;"></span>
                    <strong>Negative Margin</strong> - Selling below cost (loss)
                </div>
                <div>
                    <span style="display: inline-block; width: 12px; height: 12px; background: #ff9800; border-radius: 2px; margin-right: 5px;"></span>
                    <strong>Low Margin</strong> - Less than 10% profit margin
                </div>
                <div>
                    <span style="display: inline-block; width: 12px; height: 12px; background: #28a745; border-radius: 2px; margin-right: 5px;"></span>
                    <strong>Healthy Margin</strong> - 10% or more profit margin
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
tr:hover {
    background-color: #f5f5f5 !important;
}
</style>

<?php include __DIR__ . '/../partials/footer.php'; ?>