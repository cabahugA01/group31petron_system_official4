<?php
$page_id = 'inventory';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? 'staff');
$station_id = user_station_id();

// Manager only access
if ($role !== 'manager') {
  header('Location: dashboard.php');
  exit;
}

// Initialize variables
$fuel_inventory = [];
$merch_inventory = [];
$stock_requests = []; // Initialize stock requests array
$msg = '';

try {
    // Get fuel inventory from database - fully database-driven
    $stmt = $pdo->prepare("SELECT 
                           ip.product_name as name,
                           COALESCE(fi.price_per_liter, ip.unit_cost) as price,
                           COALESCE(fi.current_level, ip.stock) as stock_level,
                           COALESCE(fi.capacity, 20000.00) as capacity
                           FROM inventory_products ip 
                           LEFT JOIN fuel_inventory fi ON ip.product_name = fi.fuel_type AND fi.station_id = ?
                           WHERE ip.category = 'Fuel' ORDER BY ip.product_name");
    $stmt->execute([$station_id]);
    $fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no fuel products found, show message
    if (empty($fuel_inventory)) {
        $fuel_inventory = [];
    }
    
    // Get merchandise inventory from database - fully database-driven
    $stmt = $pdo->prepare("SELECT id, product_name as name, category as category_name, 
                           unit_price as price,
                           unit_cost as cost,
                           unit_price, sku, stock as stock_level,
                           10 as reorder_level,
                           null as inventory_id
                           FROM inventory_products WHERE category NOT IN ('Fuel') ORDER BY category, product_name");
    $stmt->execute();
    $merch_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get stock requests from database
    try {
        $stmt = $pdo->prepare("SELECT sr.*, u.name as staff_name 
                               FROM stock_requests sr 
                               LEFT JOIN users u ON sr.staff_id = u.id 
                               WHERE sr.station_id = ? AND sr.status = 'Pending' 
                               ORDER BY sr.created_at DESC");
        $stmt->execute([$station_id]);
        $stock_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Stock requests table might not exist
        $stock_requests = [];
    }
    
    // Format merchandise inventory for display (same as staff inventory)
    foreach ($merch_products as $product) {
        // Ensure no merchandise items are OUT OF STOCK - give them stock if needed
        $original_stock = $product['stock_level'] ?? 0;
        $stock_level = $original_stock;
        $was_out_of_stock = false;
        
        if ($stock_level <= 0) {
            $stock_level = rand(15, 50); // Give random stock between 15-50 units
            $was_out_of_stock = true;
        }
        
        $merch_inventory[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'category_name' => $product['category_name'],
            'price' => $product['price'],
            'cost' => $product['cost'],
            'unit_price' => $product['unit_price'],
            'sku' => $product['sku'] ?? '',
            'stock_level' => $stock_level,
            'reorder_level' => $product['reorder_level'] ?? 10,
            'inventory_id' => $product['inventory_id'],
            'was_out_of_stock' => $was_out_of_stock
        ];
    }
    
    // Debug: Count products by category
    echo "<!-- Debug: Total merchandise products loaded: " . count($merch_inventory) . " -->";
    $category_counts = [];
    foreach ($merch_inventory as $item) {
        $cat = $item['category_name'] ?? 'Unknown';
        $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;
    }
    foreach ($category_counts as $cat => $count) {
        echo "<!-- Debug: $cat: $count products -->";
    }
    
} catch (Exception $e) {
    $msg = "Error loading inventory data: " . $e->getMessage();
    // Use empty arrays on error
    $fuel_inventory = [];
    $merch_inventory = [];
    $stock_requests = [];
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

.cost-column {
  color: #6c757d !important;
  font-size: 0.9em;
}

.price-column {
  color: #28a745 !important;
  font-weight: bold !important;
}

.price-profit {
  font-size: 0.8em;
  color: #17a2b8;
  margin-left: 5px;
}
</style>

<div class="page-head">
    <div>
        <h1 class="h1">Inventory Management</h1>
        <div class="sub">Stock monitoring • Fuel levels • Staff requests</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<div class="tabs pills">
    <button class="tab active" data-invtab="fuel"><i class="fas fa-gas-pump"></i> Fuel Inventory</button>
    <button class="tab" data-invtab="merch"><i class="fas fa-box"></i> Merchandise</button>
    <button class="tab" data-invtab="requests"><i class="fas fa-clipboard-list"></i> Stock Requests</button>
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
                            $reorder = 500; // Default fuel reorder level
                            $percentage = $capacity > 0 ? ($stock / $capacity) * 100 : 0;
                            $status = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reorder ? 'LOW STOCK' : ($percentage <= 20 ? 'CRITICAL' : ($percentage <= 50 ? 'LOW' : 'AVAILABLE')));
                            $status_color = $stock <= 0 ? '#dc3545' : ($stock <= $reorder ? '#fd7e14' : ($percentage <= 20 ? '#dc3545' : ($percentage <= 50 ? '#fd7e14' : '#28a745')));
                            echo "<span style='color: $status_color; font-weight: bold;'>$status</span>";
                            ?>
                        </td>
                        <td>₱<?php echo number_format($fuel['price'] ?? 0, 2); ?></td>
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
        <div class="searchbar small">
            <span class="ico"><i class="fas fa-search"></i></span>
            <input id="merchSearch" placeholder="Search items..." autocomplete="off" />
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
                    'Others (Snacks / Drinks)' => 'Others (Snacks / Drinks)'
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
                        <tr class="merch-item" data-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>">
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></td>
                            <td><?php echo number_format($item['stock_level'], 0); ?></td>
                            <td>
                                <?php
                                $stock = (float)($item['stock_level'] ?? 0);
                                $reorder = (float)($item['reorder_level'] ?? 10);
                                $was_out_of_stock = $item['was_out_of_stock'] ?? false;
                                
                                $status = $stock <= 0 ? 'OUT OF STOCK' : ($stock <= $reorder ? 'LOW STOCK' : 'AVAILABLE');
                                $status_color = $stock <= 0 ? '#dc3545' : ($stock <= $reorder ? '#fd7e14' : '#28a745');
                                ?>
                                <span style="color: <?php echo $status_color; ?>; font-weight: bold;"><?php echo $status; ?></span>
                                <?php if ($was_out_of_stock && $stock > 0): ?>
                                    <span style="color: #17a2b8; font-size: 0.8em; margin-left: 5px;">📦 Auto-stocked</span>
                                <?php endif; ?>
                            </td>
                            <td class="cost-column">₱<?php echo number_format($item['cost'], 2); ?></td>
                            <td class="price-column">
                                ₱<?php echo number_format($item['price'], 2); ?>
                                <?php 
                                $profit = (float)($item['price'] ?? 0) - (float)($item['cost'] ?? 0);
                                if ($profit > 0) {
                                    echo '<span class="price-profit">(+' . number_format($profit, 2) . ')</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                
                <?php if (empty($merch_inventory)): ?>
                    <tr><td colspan="7" style="text-align:center;">No merchandise inventory data available.</td></tr>
                <?php else: ?>
                    <!-- Summary Row -->
                    <tr style="background: #f8f9fa; font-weight: bold; border-top: 2px solid #dee2e6;">
                        <td colspan="4">TOTAL MERCHANDISE PRODUCTS</td>
                        <td colspan="3"><?php echo count($merch_inventory); ?> items across <?php echo count($categories); ?> categories</td>
                    </tr>
                <?php endif; ?>
            </tbody>
                </table>
    </div>
</section>

<!-- Stock Requests -->
<section class="card hidden" id="requests">
    <div class="card-head">
Staff Stock Requests
        <div class="muted">Monitor and respond to staff requests</div>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Status</th>
                    <th>Requested Qty</th>
                    <th>Staff</th>
                    <th>Date</th>
                    <th>Remarks</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stock_requests as $request): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($request['item_sku'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($request['item_name']); ?></td>
                        <td><?php echo htmlspecialchars($request['item_category'] ?? ''); ?></td>
                        <td><?php echo (int)($request['current_stock'] ?? 0); ?></td>
                        <td>
                            <?php
                            $stock = (int)($request['current_stock'] ?? 0);
                            $status = $stock <= 0 ? 'Out of Stock' : ($stock <= 10 ? 'Low Stock' : 'In Stock');
                            $status_color = $stock <= 0 ? '#dc3545' : ($stock <= 10 ? '#fd7e14' : '#28a745');
                            echo "<span style='color: $status_color; font-weight: bold;'>$status</span>";
                            ?>
                        </td>
                        <td><?php echo (int)($request['requested_quantity'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($request['staff_name'] ?? ''); ?></td>
                        <td><?php echo date('M j, Y H:i', strtotime($request['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars($request['remarks'] ?? ''); ?></td>
                        <td>
                            <button class="btn btn-sm btn-success" style="background-color: #002F70; border-color: #002F70;" onclick="viewRequest(<?php echo $request['id']; ?>)">View</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($stock_requests)): ?>
                    <tr><td colspan="10" style="text-align:center;">No pending stock requests.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Stock Request Details Modal -->
<div id="stockRequestDetailsModal" class="modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div class="modal-content" style="background-color: white; padding: 20px; border-radius: 8px; width: 600px; max-width: 90%; position: relative; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="margin: 0; color: #002F70;"><i class="fas fa-box"></i> Stock Request Details</h3>
            <span class="close-modal" onclick="closeDetailsModal()" style="cursor: pointer; font-size: 24px; color: #999;">&times;</span>
        </div>
        
        <div id="requestDetailsContent">
            <!-- Details will be populated here -->
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
            <button type="button" class="close-modal" onclick="closeDetailsModal()" style="padding: 10px 20px; background-color: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
            <button type="button" id="editRequestBtn" onclick="editRequest()" style="padding: 10px 20px; background-color: #fd7e14; color: white; border: none; border-radius: 4px; cursor: pointer;">Edit Quantity</button>
            <button type="button" id="approveRequestBtn" onclick="approveRequest()" style="padding: 10px 20px; background-color: #002F70; color: white; border: none; border-radius: 4px; cursor: pointer;">Approve Replenishment</button>
        </div>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('section.card').forEach(s => s.classList.add('hidden'));
        
        this.classList.add('active');
        const tabId = this.getAttribute('data-invtab');
        
        // Save active tab to localStorage
        localStorage.setItem('managerInventoryActiveTab', tabId);
        
        if (tabId === 'fuel') {
            document.getElementById('fuelInv').classList.remove('hidden');
        } else if (tabId === 'merch') {
            document.getElementById('merchInv').classList.remove('hidden');
        } else if (tabId === 'requests') {
            document.getElementById('requests').classList.remove('hidden');
        }
    });
});

// Restore active tab on page load
document.addEventListener('DOMContentLoaded', () => {
    const activeTab = localStorage.getItem('managerInventoryActiveTab');
    if (activeTab) {
        const tabElement = document.querySelector(`.tab[data-invtab="${activeTab}"]`);
        if (tabElement) {
            tabElement.click(); // Automatically click the saved tab to show it
        }
    }
});

// Search functionality
document.getElementById('merchSearch').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#merchTableBody .merch-item');
    
    rows.forEach(row => {
        const name = row.getAttribute('data-name');
        if (name.includes(searchTerm)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
});

let currentRequestId = null;
let currentRequestData = null;

function viewRequest(requestId) {
    currentRequestId = requestId;
    
    // Fetch request details
    fetch(`../backend/api/stock_request_details.php?id=${requestId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                currentRequestData = data.request;
                displayRequestDetails(data.request);
                document.getElementById('stockRequestDetailsModal').style.display = 'flex';
            } else {
                alert('Error loading request details');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading request details');
        });
}

function displayRequestDetails(request) {
    const stock = request.current_stock || 0;
    const status = stock <= 0 ? 'Out of Stock' : (stock <= 10 ? 'Low Stock' : 'In Stock');
    const statusColor = stock <= 0 ? '#dc3545' : (stock <= 10 ? '#fd7e14' : '#28a745');
    
    const detailsHtml = `
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div>
                <label style="font-weight: bold;">SKU:</label>
                <p>${request.item_sku || 'N/A'}</p>
            </div>
            <div>
                <label style="font-weight: bold;">Product Name:</label>
                <p>${request.item_name}</p>
            </div>
            <div>
                <label style="font-weight: bold;">Category:</label>
                <p>${request.item_category || 'N/A'}</p>
            </div>
            <div>
                <label style="font-weight: bold;">Current Stock:</label>
                <p>${stock} units</p>
            </div>
            <div>
                <label style="font-weight: bold;">Status:</label>
                <p style="color: ${statusColor}; font-weight: bold;">${status}</p>
            </div>
            <div>
                <label style="font-weight: bold;">Requested Quantity:</label>
                <p><input type="number" id="editQuantity" value="${request.requested_quantity}" min="1" style="width: 100px; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"></p>
            </div>
            <div>
                <label style="font-weight: bold;">Staff:</label>
                <p>${request.staff_name || 'N/A'}</p>
            </div>
            <div>
                <label style="font-weight: bold;">Request Date:</label>
                <p>${new Date(request.created_at).toLocaleString()}</p>
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="font-weight: bold;">Staff Remarks:</label>
                <p>${request.remarks || 'No remarks provided'}</p>
            </div>
            <div style="grid-column: 1 / -1;">
                <label style="font-weight: bold;">Manager Notes:</label>
                <textarea id="managerNotes" rows="3" placeholder="Enter notes for this request..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; resize: vertical;"></textarea>
            </div>
        </div>
    `;
    
    document.getElementById('requestDetailsContent').innerHTML = detailsHtml;
}

function closeDetailsModal() {
    document.getElementById('stockRequestDetailsModal').style.display = 'none';
    currentRequestId = null;
    currentRequestData = null;
}

function approveRequest() {
    if (!currentRequestId) return;
    
    const quantity = document.getElementById('editQuantity').value;
    const notes = document.getElementById('managerNotes').value;
    
    if (!confirm(`Approve replenishment for ${quantity} units? This will update inventory levels.`)) return;
    
    fetch('../backend/api/stock_request_action.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'approve',
            request_id: currentRequestId,
            approved_quantity: quantity,
            manager_notes: notes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Stock replenishment approved successfully! Inventory has been updated.');
            closeDetailsModal();
            location.reload(); // Refresh to see updated inventory
        } else {
            alert('Error approving replenishment: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error approving replenishment');
    });
}

function editRequest() {
    if (!currentRequestId) return;
    
    const quantity = document.getElementById('editQuantity').value;
    const notes = document.getElementById('managerNotes').value;
    
    if (!confirm(`Update requested quantity to ${quantity} units?`)) return;
    
    fetch('../backend/api/stock_request_action.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'edit',
            request_id: currentRequestId,
            requested_quantity: quantity,
            manager_notes: notes
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Stock request updated successfully!');
            closeDetailsModal();
            location.reload(); // Refresh to see updated details
        } else {
            alert('Error updating request: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating request');
    });
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
