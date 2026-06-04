<?php
// Inventory system - same as manager view with sidebar
error_reporting(E_ALL);
ini_set("display_errors", 1);

$page_id = 'inventory';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Initialize variables
$fuel_inventory = [];
$merch_inventory = [];
$msg = '';

try {
    global $pdo;
    
    if (!$pdo) {
        throw new Exception("Database connection not established");
    }
    
    // Get fuel inventory from database
    $stmt = $pdo->query("SELECT ip.product_name as name, ip.unit_cost as price, 
                           COALESCE(fi.current_level, ip.stock_quantity) as stock_level, 
                           fi.capacity 
                           FROM inventory_products ip 
                           LEFT JOIN fuel_inventory fi ON ip.product_name = fi.fuel_type
                           WHERE ip.category = 'Fuel' ORDER BY ip.product_name");
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get merchandise inventory from database
    $stmt = $pdo->query("SELECT id, product_name as name, category as category_name, unit_price as price, unit_cost as cost, unit_price, sku, stock_quantity as stock_level,
                           10 as reorder_level,
                           null as inventory_id
                           FROM inventory_products WHERE category NOT IN ('Fuel') ORDER BY category, product_name");
    $merch_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format merchandise inventory for display
    foreach ($merch_products as $product) {
        $merch_inventory[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'category_name' => $product['category_name'],
            'price' => $product['price'],
            'cost' => $product['cost'],
            'unit_price' => $product['unit_price'],
            'sku' => $product['sku'] ?? '',
            'stock_level' => $product['stock_level'] ?? 0,
            'reorder_level' => $product['reorder_level'] ?? 10,
            'inventory_id' => $product['inventory_id']
        ];
    }
    
} catch (Exception $e) {
    $msg = "Error loading inventory data: " . $e->getMessage();
    $fuel_inventory = [];
    $merch_inventory = [];
}

include __DIR__ . '/../partials/header.php';
?>
<style>
.category-header {
  font-weight: bold;
  border-bottom: 2px solid #dee2e6;
  background-color: #e9ecef !important;
  color: #495057 !important;
  text-transform: uppercase;
  font-size: 0.9em;
  letter-spacing: 0.5px;
}

.merch-item {
  transition: all 0.2s ease;
}

.merch-item:hover {
  background-color: #f8f9fa;
}

.no-results {
  background-color: #fff3cd !important;
  color: #856404 !important;
  font-style: italic;
  text-align: center;
  padding: 20px !important;
}

#merchSearch {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  font-size: 14px;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

#merchSearch:focus {
  border-color: #80bdff;
  outline: 0;
  box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.searchbar.small {
  max-width: 300px;
}

/* Action Buttons Styling */
.action-buttons {
  display: flex;
  gap: 5px;
  flex-wrap: nowrap;
}

/* Stock Request Button - Petron Blue */
.btn-stock-request {
  background-color: #002f70 !important;
  border-color: #002f70 !important;
  color: #ffffff !important;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-stock-request:hover {
  background-color: #001f4d !important;
  border-color: #001f4d !important;
  color: #ffffff !important;
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(0, 47, 112, 0.3);
}

.btn-stock-request:focus {
  box-shadow: 0 0 0 0.25rem rgba(0, 47, 112, 0.25) !important;
  border-color: #002f70 !important;
}

/* Edit Button - Red */
.btn-edit-item {
  background-color: #dc3545 !important;
  border-color: #dc3545 !important;
  color: #ffffff !important;
  font-weight: 500;
  transition: all 0.3s ease;
}

.btn-edit-item:hover {
  background-color: #c82333 !important;
  border-color: #c82333 !important;
  color: #ffffff !important;
  transform: translateY(-1px);
  box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
}

.btn-edit-item:focus {
  box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
  border-color: #dc3545 !important;
}

/* Button responsiveness */
.btn-sm {
  padding: 4px 8px;
  font-size: 0.75rem;
  
}

@media (max-width: 768px) {
  .action-buttons {
    flex-direction: column;
    gap: 3px;
  }
  
  .btn-sm {
    font-size: 0.7rem;
    padding: 3px 6px;
    
  }
}

/* Enhanced Search Bar */
.searchbar.enhanced {
  position: relative;
  max-width: 400px;
  flex: 1;
}

.searchbar.enhanced input {
  padding-right: 120px;
}

.search-hint {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 0.75rem;
  color: #6c757d;
  pointer-events: none;
  opacity: 0.7;
}

.search-stats {
  display: flex;
  gap: 15px;
  align-items: center;
  font-size: 0.85rem;
  color: #6c757d;
  margin-left: 15px;
}

.items-count, .categories-count {
  background: #f8f9fa;
  padding: 4px 8px;
  border-radius: 12px;
  font-weight: 500;
}

/* Search Highlight */
.highlight {
  background: #fff3cd !important;
  color: #856404 !important;
  padding: 2px 4px;
  border-radius: 3px;
  font-weight: bold;
}

/* Enhanced Status Column */
.status-indicator {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-available {
  background: #d4edda;
  color: #155724;
  border: 1px solid #c3e6cb;
}

.status-low-stock {
  background: #fff3cd;
  color: #856404;
  border: 1px solid #ffeaa7;
}

.status-out-of-stock {
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
}

.status-indicator::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

/* Inline Form Styles */
.inline-form {
  background: #f8f9fa;
  padding: 10px;
  border-radius: 4px;
  margin: 5px 0;
  border-left: 3px solid #007bff;
}

.inline-form-row {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 8px;
}

.inline-form-row:last-child {
  margin-bottom: 0;
}

.inline-form input {
  padding: 4px 8px;
  border: 1px solid #ced4da;
  border-radius: 4px;
  font-size: 0.85rem;
}

.inline-form input:focus {
  border-color: #80bdff;
  outline: 0;
  box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.inline-form-actions {
  display: flex;
  gap: 5px;
  margin-top: 8px;
}

.inline-form-actions button {
  padding: 4px 12px;
  font-size: 0.8rem;
  border-radius: 4px;
  border: none;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-inline-submit {
  background: #28a745;
  color: white;
}

.btn-inline-submit:hover {
  background: #218838;
}

.btn-inline-cancel {
  background: #6c757d;
  color: white;
}

.btn-inline-cancel:hover {
  background: #5a6268;
}

/* Workflow Animation */
.workflow-transition {
  transition: all 0.3s ease;
}

.workflow-transition:hover {
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* Enhanced Table Row Styling */
.merch-item.workflow-active {
  background: #e7f3ff !important;
  border-left: 4px solid #007bff;
}

.merch-item.workflow-pending {
  background: #fff8e1 !important;
  border-left: 4px solid #ffc107;
}

.merch-item.workflow-updated {
  background: #e8f5e8 !important;
  border-left: 4px solid #28a745;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Inventory Management</h1>
        <div class="sub">Stock monitoring • Fuel levels • Merchandise tracking</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<div class="tabs pills">
    <button class="tab active" data-invtab="fuel">Fuel Inventory</button>
    <button class="tab" data-invtab="merch">Merchandise</button>
</div>

<!-- Fuel Inventory -->
<section class="card" id="fuelInv">
    <div class="card-head">
        <div class="card-title">Fuel Inventory</div>
        <div class="muted">View-only monitoring</div>
    </div>
    <div class="table-wrap">
        <table class="table" id="fuelTable">
            <thead>
                <tr>
                    <th>Fuel Type</th>
                    <th>Current Level</th>
                    <th>Capacity</th>
                    <th>Status</th>
                    <th>Price/L</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fuel_inventory as $fuel): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($fuel['name']); ?></td>
                        <td><?php echo number_format($fuel['stock_level'], 2); ?> L</td>
                        <td><?php echo number_format($fuel['capacity'] ?? 0, 2); ?> L</td>
                        <td>
                            <?php
                            $stock = (float)($fuel['stock_level'] ?? 0);
                            $capacity = (float)($fuel['capacity'] ?? 1);
                            $reorder = 500;
                            $percentage = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                            $status = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reorder ? 'LOW STOCK' : ($percentage <= 20 ? 'CRITICAL' : ($percentage <= 50 ? 'LOW' : 'AVAILABLE')));
                            $status_color = $stock <= 0 ? '#dc3545' : ($stock <= $reorder ? '#fd7e14' : ($percentage <= 20 ? '#dc3545' : ($percentage <= 50 ? '#fd7e14' : '#28a745')));
                            echo "<span style='color: $status_color; font-weight: bold;'>$status</span>";
                            ?>
                        </td>
                        <td>?<?php echo number_format($fuel['price'] ?? 0, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($fuel_inventory)): ?>
                    <tr><td colspan="5" style="text-align:center;">No fuel inventory data available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Merchandise Inventory -->
<section class="card hidden" id="merchInv">
    <div class="card-head">
        <div class="card-title">Merchandise Inventory</div>
        <div class="muted">View-only monitoring • <?php echo count($merch_inventory); ?> total products</div>
    </div>

    <div class="table-tools">
        <div class="searchbar enhanced">
            <span class="ico"><i class="fas fa-search"></i></span>
            <input id="merchSearch" placeholder="Search by Product Name, SKU, or Category..." autocomplete="off" />
            <span class="search-hint">Type to filter instantly</span>
        </div>
        <div class="search-stats" id="searchStats">
            <span class="items-count"><?php echo count($merch_inventory); ?> items</span>
            <span class="categories-count"><?php echo count($categories); ?> categories</span>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="merchTable">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Cost</th>
                    <th>Price</th>
                    <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                    <th>Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="merchTableBody">
                <?php 
                // Sort merchandise by category order
                $categories = [];
                foreach ($merch_inventory as $item) {
                    $category = $item['category_name'] ?? 'Uncategorized';
                    if (!isset($categories[$category])) {
                        $categories[$category] = [];
                    }
                    $categories[$category][] = $item;
                }
                
                // Define category order
                $category_order = [
                    'Oils / Lubes / Grease' => 'Oils / Lubes / Grease',
                    'Car Accessories' => 'Car Accessories',
                    'Brake System' => 'Brake System',
                    'Tire' => 'Tire',
                    'Maintenance' => 'Maintenance',
                    'Oil / Fuel Filters' => 'Oil / Fuel Filters',
                    'Others (Snacks / Drinks)' => 'Others (Snacks / Drinks)',
                    'Battery' => 'Battery'
                ];
                
                // Sort categories according to the defined order
                $sorted_categories = [];
                foreach ($category_order as $key => $label) {
                    if (isset($categories[$key])) {
                        $sorted_categories[$label] = $categories[$key];
                    }
                }
                
                // Add any remaining categories not in the predefined order
                foreach ($categories as $key => $value) {
                    if (!isset($category_order[$key])) {
                        $sorted_categories[$key] = $value;
                    }
                }
                
                // Display items by category
                foreach ($sorted_categories as $category_label => $items): ?>
                    <tr class="category-header">
                        <td colspan="7" class="category-header-cell">
                            <strong><?php echo htmlspecialchars($category_label); ?></strong>
                        </td>
                    </tr>
                    <?php foreach ($items as $item): ?>
                        <tr class="merch-item" 
                            data-item-id="<?php echo $item['id']; ?>"
                            data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                            data-sku="<?php echo strtolower(htmlspecialchars($item['sku'] ?? '')); ?>"
                            data-category="<?php echo strtolower(htmlspecialchars($item['category_name'] ?? '')); ?>">
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['category_name'] ?? ''); ?></td>
                            <td><?php echo number_format($item['stock_level'], 0); ?></td>
                            <td>
                                <?php
                                $stock = (float)($item['stock_level'] ?? 0);
                                $reorder = (float)($item['reorder_level'] ?? 10);
                                $percentage = $reorder > 0 ? ($stock / $reorder) * 100 : 100;
                                $status = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reorder ? 'LOW STOCK' : 'AVAILABLE');
                                $status_class = $stock <= 0 ? 'status-out-of-stock' : ($stock <= $reorder ? 'status-low-stock' : 'status-available');
                                ?>
                                <span class="status-indicator <?php echo $status_class; ?>">
                                    <?php echo $status; ?>
                                </span>
                            </td>
                            <td>?<?php echo number_format($item['cost'], 2); ?></td>
                            <td>?<?php echo number_format($item['price'], 2); ?></td>
                            <td>
                                <?php if (in_array($role, ['staff', 'cashier', 'pump_attendant'])): ?>
                                    <div class="action-buttons">
                                        <button class="btn btn-sm btn-stock-request stock-request-btn" 
                                                data-item-id="<?php echo $item['id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                                                data-item-sku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
                                                data-current-stock="<?php echo $item['stock_level']; ?>"
                                                data-item-cost="<?php echo $item['cost']; ?>"
                                                data-item-price="<?php echo $item['price']; ?>"
                                                title="Request Stock">
                                            <i class="fas fa-plus-circle"></i> Request
                                        </button>
                                        <button class="btn btn-sm btn-edit-item edit-item-btn" 
                                                data-item-id="<?php echo $item['id']; ?>"
                                                data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                                                data-item-sku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
                                                data-item-cost="<?php echo $item['cost']; ?>"
                                                data-item-price="<?php echo $item['price']; ?>"
                                                data-item-stock="<?php echo $item['stock_level']; ?>"
                                                data-item-category="<?php echo htmlspecialchars($item['category_name'] ?? ''); ?>"
                                                title="Edit Item">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                
                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="7" style="text-align:center;">No merchandise inventory data available.</td></tr>
                <?php else: ?>
                    <!-- Summary Row -->
                    <tr style="background: #f8f9fa; font-weight: bold; border-top: 2px solid #dee2e6;">
                        <td colspan="3">TOTAL MERCHANDISE PRODUCTS</td>
                        <td colspan="4"><?php echo count($merch_inventory); ?> items across <?php echo count($categories); ?> categories</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Stock Request Modal -->
<div class="modal fade" id="stockRequestModal" tabindex="-1" aria-labelledby="stockRequestModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="stockRequestModalLabel">
                    <i class="fas fa-plus-circle"></i> Stock Request
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="stockRequestForm">
                    <input type="hidden" id="requestItemId" name="item_id">
                    
                    <div class="mb-3">
                        <label for="requestItemName" class="form-label">Item Name</label>
                        <input type="text" class="form-control" id="requestItemName" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="requestItemSku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="requestItemSku" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="requestCurrentStock" class="form-label">Current Stock</label>
                        <input type="text" class="form-control" id="requestCurrentStock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="requestedQuantity" class="form-label">Requested Quantity *</label>
                        <input type="number" class="form-control" id="requestedQuantity" name="requested_quantity" 
                               min="1" max="100" required>
                        <small class="text-muted">Enter the quantity you need</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="requestRemarks" class="form-label">Remarks</label>
                        <textarea class="form-control" id="requestRemarks" name="remarks" rows="3" 
                                  placeholder="e.g., Need for Brake Pads replacement"></textarea>
                        <small class="text-muted">Optional: Provide additional details about this request</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="submitStockRequest">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editItemModalLabel">
                    <i class="fas fa-edit"></i> Edit Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editItemForm">
                    <input type="hidden" id="editItemId" name="item_id">
                    
                    <div class="mb-3">
                        <label for="editItemName" class="form-label">Item Name</label>
                        <input type="text" class="form-control" id="editItemName" readonly>
                        <small class="text-muted">Item name cannot be changed</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editItemSku" class="form-label">SKU</label>
                        <input type="text" class="form-control" id="editItemSku" readonly>
                        <small class="text-muted">SKU cannot be changed</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editItemCategory" class="form-label">Category</label>
                        <input type="text" class="form-control" id="editItemCategory" readonly>
                        <small class="text-muted">Category cannot be changed</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editItemStock" class="form-label">Current Stock *</label>
                        <input type="number" class="form-control" id="editItemStock" name="stock_level" 
                               min="0" max="9999" required>
                        <small class="text-muted">Update current stock quantity</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editItemCost" class="form-label">Unit Cost *</label>
                        <input type="number" class="form-control" id="editItemCost" name="unit_cost" 
                               min="0" step="0.01" required>
                        <small class="text-muted">Update unit cost price</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editItemPrice" class="form-label">Selling Price *</label>
                        <input type="number" class="form-control" id="editItemPrice" name="unit_price" 
                               min="0" step="0.01" required>
                        <small class="text-muted">Update selling price</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveItemChanges">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- My Stock Requests Modal -->
<div class="modal fade" id="myRequestsModal" tabindex="-1" aria-labelledby="myRequestsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="myRequestsModalLabel">
                    <i class="fas fa-list"></i> My Stock Requests
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm" id="myRequestsTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Item</th>
                                <th>SKU</th>
                                <th>Requested</th>
                                <th>Approved</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Requests will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Inline Stock Request Form (Hidden by default) -->
<div id="inlineStockRequestTemplate" style="display: none;">
    <tr class="inline-form-row">
        <td colspan="7">
            <div class="inline-form">
                <div class="inline-form-row">
                    <label>Item:</label>
                    <strong id="inlineItemName"></strong>
                    <span class="text-muted">(SKU: <span id="inlineItemSku"></span>)</span>
                </div>
                <div class="inline-form-row">
                    <label>Current Stock:</label>
                    <span id="inlineCurrentStock"></span>
                    <label style="margin-left: 20px;">Request Quantity:</label>
                    <input type="number" id="inlineRequestQuantity" min="1" max="100" value="1" style="width: 80px;">
                </div>
                <div class="inline-form-row">
                    <label>Remarks:</label>
                    <input type="text" id="inlineRequestRemarks" placeholder="Optional remarks..." style="flex: 1;">
                </div>
                <div class="inline-form-actions">
                    <button class="btn-inline-submit" onclick="submitInlineStockRequest()">
                        <i class="fas fa-paper-plane"></i> Submit Request
                    </button>
                    <button class="btn-inline-cancel" onclick="cancelInlineStockRequest()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </td>
    </tr>
</div>

<!-- Inline Edit Form (Hidden by default) -->
<div id="inlineEditTemplate" style="display: none;">
    <tr class="inline-form-row">
        <td colspan="7">
            <div class="inline-form">
                <div class="inline-form-row">
                    <label>Item:</label>
                    <strong id="inlineEditItemName"></strong>
                    <span class="text-muted">(SKU: <span id="inlineEditItemSku"></span>)</span>
                </div>
                <div class="inline-form-row">
                    <label>Category:</label>
                    <span id="inlineEditCategory"></span>
                </div>
                <div class="inline-form-row">
                    <label>Stock Level:</label>
                    <input type="number" id="inlineEditStock" min="0" max="9999" style="width: 100px;">
                    <label style="margin-left: 20px;">Unit Cost:</label>
                    <input type="number" id="inlineEditCost" min="0" step="0.01" style="width: 100px;">
                    <label style="margin-left: 20px;">Selling Price:</label>
                    <input type="number" id="inlineEditPrice" min="0" step="0.01" style="width: 100px;">
                </div>
                <div class="inline-form-actions">
                    <button class="btn-inline-submit" onclick="submitInlineEdit()">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button class="btn-inline-cancel" onclick="cancelInlineEdit()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </div>
        </td>
    </tr>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching (same as Manager Inventory)
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function() {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('section.card').forEach(s => s.classList.add('hidden'));
            
            this.classList.add('active');
            const tabId = this.getAttribute('data-invtab');
            if (tabId === 'fuel') {
                document.getElementById('fuelInv').classList.remove('hidden');
            } else if (tabId === 'merch') {
                document.getElementById('merchInv').classList.remove('hidden');
            }
        });
    });

    // Enhanced Search functionality - with error handling
    const merchSearchElement = document.getElementById('merchSearch');
    if (merchSearchElement) {
        merchSearchElement.addEventListener('input', function() {
            try {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('#merchTableBody .merch-item');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-name');
                    const sku = row.getAttribute('data-sku');
                    const category = row.getAttribute('data-category');
                    
                    // Search across name, SKU, and category
                    const matches = name.includes(searchTerm) || 
                                  (sku && sku.includes(searchTerm)) || 
                                  (category && category.includes(searchTerm));
                    
                    if (matches) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Update search stats
                updateSearchStats(visibleCount);
                
                // Highlight matching text
                highlightSearchResults(searchTerm);
            } catch (error) {
                console.error('Search error:', error);
            }
        });
    }

    function updateSearchStats(visibleCount) {
        try {
            const searchStats = document.getElementById('searchStats');
            if (searchStats) {
                const totalItems = <?php echo count($merch_inventory); ?>;
                
                if (visibleCount < totalItems) {
                    searchStats.innerHTML = `<span class="items-count">${visibleCount} of ${totalItems} items</span>`;
                } else {
                    searchStats.innerHTML = `<span class="items-count">${totalItems} items</span>
                                           <span class="categories-count"><?php echo count($categories); ?> categories</span>`;
                }
            }
        } catch (error) {
            console.error('Update search stats error:', error);
        }
    }

    function highlightSearchResults(searchTerm) {
        try {
            if (!searchTerm) {
                // Clear highlights
                document.querySelectorAll('.highlight').forEach(el => {
                    el.classList.remove('highlight');
                });
                return;
            }
            
            // Add highlights (simplified version)
            const rows = document.querySelectorAll('#merchTableBody .merch-item:not([style*="display: none"])');
            rows.forEach(row => {
                const nameCell = row.cells[0]; // Product name cell
                if (nameCell) {
                    const name = nameCell.textContent.toLowerCase();
                    if (name.includes(searchTerm)) {
                        nameCell.classList.add('highlight');
                    } else {
                        nameCell.classList.remove('highlight');
                    }
                }
            });
        } catch (error) {
            console.error('Highlight search results error:', error);
        }
    }

    // Stock Request functionality - with error handling
    let stockRequestModal, myRequestsModal, editItemModal;
    try {
        if (typeof bootstrap !== 'undefined') {
            stockRequestModal = new bootstrap.Modal(document.getElementById('stockRequestModal'));
            myRequestsModal = new bootstrap.Modal(document.getElementById('myRequestsModal'));
            editItemModal = new bootstrap.Modal(document.getElementById('editItemModal'));
        }
    } catch (error) {
        console.error('Modal initialization error:', error);
    }

    // Handle stock request button clicks
    document.addEventListener('click', function(e) {
        if (e.target.closest('.stock-request-btn')) {
            const btn = e.target.closest('.stock-request-btn');
            const itemId = btn.dataset.itemId;
            const itemName = btn.dataset.itemName;
            const itemSku = btn.dataset.itemSku;
            const currentStock = btn.dataset.currentStock;
            
            // Populate modal
            document.getElementById('requestItemId').value = itemId;
            document.getElementById('requestItemName').value = itemName;
            document.getElementById('requestItemSku').value = itemSku;
            document.getElementById('requestCurrentStock').value = currentStock;
            document.getElementById('requestedQuantity').value = 1;
            document.getElementById('requestRemarks').value = '';
            
            stockRequestModal.show();
        }
    });

    // Handle edit item button clicks
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-item-btn')) {
            const btn = e.target.closest('.edit-item-btn');
            const itemId = btn.dataset.itemId;
            const itemName = btn.dataset.itemName;
            const itemSku = btn.dataset.itemSku;
            const itemCost = btn.dataset.itemCost;
            const itemPrice = btn.dataset.itemPrice;
            const itemStock = btn.dataset.itemStock;
            const itemCategory = btn.dataset.itemCategory;
            
            // Populate modal
            document.getElementById('editItemId').value = itemId;
            document.getElementById('editItemName').value = itemName;
            document.getElementById('editItemSku').value = itemSku;
            document.getElementById('editItemCategory').value = itemCategory;
            document.getElementById('editItemStock').value = itemStock;
            document.getElementById('editItemCost').value = itemCost;
            document.getElementById('editItemPrice').value = itemPrice;
            
            editItemModal.show();
        }
    });

    // Submit stock request
    document.getElementById('submitStockRequest').addEventListener('click', function() {
        const form = document.getElementById('stockRequestForm');
        const formData = new FormData(form);
        
        // Validate form
        const requestedQuantity = parseInt(formData.get('requested_quantity'));
        if (requestedQuantity < 1 || requestedQuantity > 100) {
            alert('Please enter a valid quantity (1-100)');
            return;
        }
        
        // Submit request
        fetch('backend/api/stock_requests.php?action=create_request', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Stock request submitted successfully!');
                stockRequestModal.hide();
                form.reset();
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while submitting the request');
        });
    });

    // Add "My Requests" button for staff
    if (document.querySelector('.page-head')) {
        const pageHead = document.querySelector('.page-head');
        const headerActions = pageHead.querySelector('.header-actions');
        
        // Add "My Requests" button for staff roles
        const userRole = document.body.dataset.role;
        if (['staff', 'cashier', 'pump_attendant'].includes(userRole)) {
            const myRequestsBtn = document.createElement('button');
            myRequestsBtn.className = 'btn btn-info';
            myRequestsBtn.innerHTML = '<i class="fas fa-list"></i> My Requests';
            myRequestsBtn.onclick = function() {
                loadMyRequests();
                myRequestsModal.show();
            };
            headerActions.insertBefore(myRequestsBtn, headerActions.firstChild);
        }
    }

    // Load user's stock requests
    function loadMyRequests() {
        fetch('backend/api/stock_requests.php?action=get_my_requests')
        .then(response => response.json())
        .then(data => {
            const tbody = document.querySelector('#myRequestsTable tbody');
            tbody.innerHTML = '';
            
            if (data.requests && data.requests.length > 0) {
                data.requests.forEach(request => {
                    const row = document.createElement('tr');
                    const statusColor = getStatusColor(request.status);
                    const statusBadge = `<span class="badge" style="background-color: ${statusColor}; color: white;">${request.status}</span>`;
                    
                    row.innerHTML = `
                        <td>${formatDate(request.created_at)}</td>
                        <td>${request.item_name}</td>
                        <td>${request.item_sku}</td>
                        <td>${request.requested_quantity}</td>
                        <td>${request.approved_quantity || '-'}</td>
                        <td>${statusBadge}</td>
                        <td>${request.remarks || '-'}</td>
                    `;
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">No stock requests found</td></tr>';
            }
        })
        .catch(error => {
            console.error('Error loading requests:', error);
        });
    }

    function getStatusColor(status) {
        switch(status) {
            case 'Pending': return '#ffc107';
            case 'Approved': return '#28a745';
            case 'Rejected': return '#dc3545';
            case 'Completed': return '#17a2b8';
            default: return '#6c757d';
        }
    }

    // Save item changes
    document.getElementById('saveItemChanges').addEventListener('click', function() {
        const form = document.getElementById('editItemForm');
        const formData = new FormData(form);
        
        // Validate form
        const stockLevel = parseInt(formData.get('stock_level'));
        const unitCost = parseFloat(formData.get('unit_cost'));
        const unitPrice = parseFloat(formData.get('unit_price'));
        
        if (stockLevel < 0 || stockLevel > 9999) {
            alert('Please enter a valid stock level (0-9999)');
            return;
        }
        
        if (unitCost < 0 || unitPrice < 0) {
            alert('Please enter valid costs and prices');
            return;
        }
        
        if (unitPrice < unitCost) {
            alert('Selling price cannot be less than unit cost');
            return;
        }
        
        // Submit update
        fetch('backend/api/inventory_management.php?action=update_item', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Item updated successfully!');
                editItemModal.hide();
                location.reload(); // Reload to show updated data
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the item');
        });
    });

    // Inline Stock Request Functions
    function showInlineStockRequest(itemId, itemName, itemSku, currentStock) {
        // Hide any existing inline forms
        hideAllInlineForms();
        
        // Populate inline form data
        document.getElementById('inlineItemName').textContent = itemName;
        document.getElementById('inlineItemSku').textContent = itemSku;
        document.getElementById('inlineCurrentStock').textContent = currentStock;
        document.getElementById('inlineRequestQuantity').value = 1;
        document.getElementById('inlineRequestRemarks').value = '';
        
        // Store item data for submission
        window.currentInlineRequest = {
            itemId: itemId,
            itemName: itemName,
            itemSku: itemSku,
            currentStock: currentStock
        };
        
        // Insert inline form after the item row
        const itemRow = document.querySelector(`tr[data-item-id="${itemId}"]`);
        if (itemRow) {
            const template = document.getElementById('inlineStockRequestTemplate');
            const inlineForm = template.querySelector('tr').cloneNode(true);
            itemRow.parentNode.insertBefore(inlineForm, itemRow.nextSibling);
            
            // Add workflow styling
            itemRow.classList.add('workflow-active');
        }
    }

    function submitInlineStockRequest() {
        const quantity = parseInt(document.getElementById('inlineRequestQuantity').value);
        const remarks = document.getElementById('inlineRequestRemarks').value;
        
        if (quantity < 1 || quantity > 100) {
            alert('Please enter a valid quantity (1-100)');
            return;
        }
        
        // Create form data
        const formData = new FormData();
        formData.append('item_id', window.currentInlineRequest.itemId);
        formData.append('requested_quantity', quantity);
        formData.append('remarks', remarks);
        
        // Submit request
        fetch('backend/api/stock_requests.php?action=create_request', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Stock request submitted successfully!');
                hideAllInlineForms();
                // Optionally update the row to show pending status
                updateItemStatus(window.currentInlineRequest.itemId, 'pending');
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while submitting the request');
        });
    }

    function cancelInlineStockRequest() {
        hideAllInlineForms();
    }

    // Inline Edit Functions
    function showInlineEdit(itemId, itemName, itemSku, itemCost, itemPrice, itemStock, itemCategory) {
        // Hide any existing inline forms
        hideAllInlineForms();
        
        // Populate inline form data
        document.getElementById('inlineEditItemName').textContent = itemName;
        document.getElementById('inlineEditItemSku').textContent = itemSku;
        document.getElementById('inlineEditCategory').textContent = itemCategory;
        document.getElementById('inlineEditStock').value = itemStock;
        document.getElementById('inlineEditCost').value = itemCost;
        document.getElementById('inlineEditPrice').value = itemPrice;
        
        // Store item data for submission
        window.currentInlineEdit = {
            itemId: itemId,
            itemName: itemName,
            itemSku: itemSku
        };
        
        // Insert inline form after the item row
        const itemRow = document.querySelector(`tr[data-item-id="${itemId}"]`);
        if (itemRow) {
            const template = document.getElementById('inlineEditTemplate');
            const inlineForm = template.querySelector('tr').cloneNode(true);
            itemRow.parentNode.insertBefore(inlineForm, itemRow.nextSibling);
            
            // Add workflow styling
            itemRow.classList.add('workflow-active');
        }
    }

    function submitInlineEdit() {
        const stockLevel = parseInt(document.getElementById('inlineEditStock').value);
        const unitCost = parseFloat(document.getElementById('inlineEditCost').value);
        const unitPrice = parseFloat(document.getElementById('inlineEditPrice').value);
        
        if (stockLevel < 0 || stockLevel > 9999) {
            alert('Please enter a valid stock level (0-9999)');
            return;
        }
        
        if (unitCost < 0 || unitPrice < 0) {
            alert('Please enter valid costs and prices');
            return;
        }
        
        if (unitPrice < unitCost) {
            alert('Selling price cannot be less than unit cost');
            return;
        }
        
        // Create form data
        const formData = new FormData();
        formData.append('item_id', window.currentInlineEdit.itemId);
        formData.append('stock_level', stockLevel);
        formData.append('unit_cost', unitCost);
        formData.append('unit_price', unitPrice);
        
        // Submit update
        fetch('backend/api/inventory_management.php?action=update_item', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Item updated successfully!');
                hideAllInlineForms();
                location.reload(); // Reload to show updated data
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the item');
        });
    }

    function cancelInlineEdit() {
        hideAllInlineForms();
    }

    function hideAllInlineForms() {
        // Remove all inline forms
        document.querySelectorAll('.inline-form-row').forEach(row => {
            row.remove();
        });
        
        // Remove workflow styling from all rows
        document.querySelectorAll('.workflow-active').forEach(row => {
            row.classList.remove('workflow-active');
        });
    }

    function updateItemStatus(itemId, status) {
        // Update item row to show workflow status
        const itemRow = document.querySelector(`tr[data-item-id="${itemId}"]`);
        if (itemRow) {
            itemRow.classList.add('workflow-pending');
            setTimeout(() => {
                itemRow.classList.remove('workflow-pending');
                itemRow.classList.add('workflow-updated');
                setTimeout(() => {
                    itemRow.classList.remove('workflow-updated');
                }, 2000);
            }, 1000);
        }
    }

    // Update button handlers to use inline forms
    document.addEventListener('click', function(e) {
        if (e.target.closest('.stock-request-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.stock-request-btn');
            const itemId = btn.dataset.itemId;
            const itemName = btn.dataset.itemName;
            const itemSku = btn.dataset.itemSku;
            const currentStock = btn.dataset.currentStock;
            
            showInlineStockRequest(itemId, itemName, itemSku, currentStock);
        }
        
        if (e.target.closest('.edit-item-btn')) {
            e.preventDefault();
            const btn = e.target.closest('.edit-item-btn');
            const itemId = btn.dataset.itemId;
            const itemName = btn.dataset.itemName;
            const itemSku = btn.dataset.itemSku;
            const itemCost = btn.dataset.itemCost;
            const itemPrice = btn.dataset.itemPrice;
            const itemStock = btn.dataset.itemStock;
            const itemCategory = btn.dataset.itemCategory;
            
            showInlineEdit(itemId, itemName, itemSku, itemCost, itemPrice, itemStock, itemCategory);
        }
    });

    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    }
});
</script>

</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
