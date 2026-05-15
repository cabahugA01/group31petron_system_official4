<?php
$page_id = 'services_staff';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

// Check if user is staff
$me = current_user();
if ($me['role'] !== 'staff') {
    header('Location: services.php');
    exit();
}

$station_id = user_station_id();
$msg = '';

// Handle Staff Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // ACTION 1: Add New Service Entry
    if ($action === 'add_service') {
        $service_category_id = $_POST['service_category_id'] ?? 0;
        $vehicle_plate = $_POST['vehicle_plate'] ?? '';
        $vehicle_type = $_POST['vehicle_type'] ?? '';
        $customer_name = $_POST['customer_name'] ?? '';
        $service_description = $_POST['service_description'] ?? '';
        $parts_cost = (float)($_POST['parts_cost'] ?? 0);
        $labor_cost = (float)($_POST['labor_cost'] ?? 0);
        $estimated_duration = (int)($_POST['estimated_duration'] ?? 60);
        $notes = $_POST['notes'] ?? '';
        
        $total_cost = $parts_cost + $labor_cost;
        
        if ($service_description) {
            try {
                $stmt = $pdo->prepare("INSERT INTO service_entries (station_id, service_category_id, vehicle_plate, vehicle_type, customer_name, service_description, parts_cost, labor_cost, total_cost, estimated_duration, user_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$station_id, $service_category_id, $vehicle_plate, $vehicle_type, $customer_name, $service_description, $parts_cost, $labor_cost, $total_cost, $estimated_duration, $me['id'], $notes]);
                
                $service_id = $pdo->lastInsertId();
                
                // Log activity
                log_activity($pdo, $me['id'], 'Add Service', "Added service #$service_id: $service_description", 'services');
                
                $msg = "✅ Service entry added successfully! Status: Pending (Awaiting Admin review)";
            } catch (PDOException $e) {
                $msg = "❌ Error: " . $e->getMessage();
            }
        } else {
            $msg = "❌ Error: Service description is required.";
        }
    }
    
    // ACTION 2: Update Service Status (Staff can only go Pending → In Progress → Completed)
    elseif ($action === 'update_status') {
        $id = $_POST['id'];
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        
        // Staff can only update their own services
        $stmt = $pdo->prepare("SELECT * FROM service_entries WHERE id = ? AND user_id = ? AND station_id = ?");
        $stmt->execute([$id, $me['id'], $station_id]);
        $service = $stmt->fetch();
        
        if (!$service) {
            $msg = "❌ Error: Service not found or unauthorized.";
        } else {
            $allowed_transitions = [
                'Pending' => ['In Progress'],
                'In Progress' => ['Completed'],
                'Completed' => ['Completed'] // Can't change from Completed
            ];
            
            if (!in_array($status, $allowed_transitions[$service['status']] ?? [])) {
                $msg = "❌ Error: Invalid status transition from {$service['status']} to $status";
            } else {
                $update_data = ['status' => $status];
                
                if ($status === 'In Progress') {
                    $update_data['started_at'] = date('Y-m-d H:i:s');
                } elseif ($status === 'Completed') {
                    $update_data['completed_at'] = date('Y-m-d H:i:s');
                }
                
                $set_clause = implode(', ', array_map(function($k, $v) {
                    return "$k = " . ($v === null ? 'NULL' : "'$v'");
                }, array_keys($update_data), $update_data));
                
                $stmt = $pdo->prepare("UPDATE service_entries SET $set_clause, notes = CONCAT(COALESCE(notes,''), '\n[Status Update] ', ?) WHERE id = ?");
                $stmt->execute([$notes, $id]);
                
                log_activity($pdo, $me['id'], 'Update Service Status', "Changed service #$id from {$service['status']} to $status", 'services');
                $msg = "✅ Service #$id status updated to $status.";
            }
        }
    }
    
    // ACTION 3: Add Parts to Service
    elseif ($action === 'add_parts') {
        $service_id = $_POST['service_id'];
        $inventory_id = $_POST['inventory_id'];
        $quantity = (int)$_POST['quantity'];
        
        // Check if service belongs to staff
        $stmt = $pdo->prepare("SELECT * FROM service_entries WHERE id = ? AND user_id = ? AND station_id = ?");
        $stmt->execute([$service_id, $me['id'], $station_id]);
        $service = $stmt->fetch();
        
        if (!$service) {
            $msg = "❌ Error: Service not found or unauthorized.";
        } elseif ($service['status'] === 'Finalized' || $service['status'] === 'Cancelled') {
            $msg = "❌ Error: Cannot add parts to a finalized or cancelled service.";
        } else {
            // Get inventory item details
            $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND station_id = ?");
            $stmt->execute([$inventory_id, $station_id]);
            $item = $stmt->fetch();
            
            if (!$item) {
                $msg = "❌ Error: Inventory item not found.";
            } elseif ($item['stock_level'] < $quantity) {
                $msg = "❌ Error: Insufficient stock. Available: {$item['stock_level']}";
            } else {
                $unit_cost = $item['selling_price'] ?? $item['cost_price'] ?? 0;
                $total_cost = $unit_cost * $quantity;
                
                // Add part to service
                $stmt = $pdo->prepare("INSERT INTO service_parts (service_id, inventory_id, product_name, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$service_id, $inventory_id, $item['product_name'], $quantity, $unit_cost, $total_cost]);
                
                // Update service total cost
                $stmt = $pdo->prepare("UPDATE service_entries SET parts_cost = parts_cost + ?, total_cost = total_cost + ? WHERE id = ?");
                $stmt->execute([$total_cost, $total_cost, $service_id]);
                
                log_activity($pdo, $me['id'], 'Add Parts to Service', "Added {$item['product_name']} (x$quantity) to service #$service_id", 'services');
                $msg = "✅ Parts added to service #$service_id. Cost: ₱" . number_format($total_cost, 2);
            }
        }
    }
}

// Fetch data for staff
$service_categories = [];
$my_services = [];
$inventory_items = [];

try {
    // Get service categories (global + station specific)
    $stmt = $pdo->prepare("SELECT * FROM service_categories WHERE station_id = 0 OR station_id = ? ORDER BY name");
    $stmt->execute([$station_id]);
    $service_categories = $stmt->fetchAll();
    
    // Get my services
    $stmt = $pdo->prepare("SELECT se.*, sc.name as category_name FROM service_entries se LEFT JOIN service_categories sc ON se.service_category_id = sc.id WHERE se.station_id = ? AND se.user_id = ? ORDER BY se.created_at DESC LIMIT 50");
    $stmt->execute([$station_id, $me['id']]);
    $my_services = $stmt->fetchAll();
    
    // Get inventory items for parts selection
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE station_id = ? AND stock_level > 0 AND type = 'merch' ORDER BY product_name");
    $stmt->execute([$station_id]);
    $inventory_items = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Services Staff Error: " . $e->getMessage());
}

require_once __DIR__ . '/partials/header.php';
?>

<style>
.service-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}
.service-form {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}
.status-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}
.status-pending { background: #fff3cd; color: #856404; }
.status-inprogress { background: #cce7ff; color: #004085; }
.status-completed { background: #d4edda; color: #155724; }
.status-verified { background: #d1ecf1; color: #0c5460; }
.status-finalized { background: #155724; color: white; }
.status-cancelled { background: #f8d7da; color: #721c24; }
.parts-list {
    max-height: 200px;
    overflow-y: auto;
}
</style>

<div class="page">
  <div class="page-head">
    <div>
      <h1>Service Entries - Staff</h1>
      <div class="muted">Encode customer service requests and track progress</div>
    </div>
    <div class="actions">
      <button class="btn dark" data-bs-toggle="modal" data-bs-target="#modalAddService">
        <i class="fas fa-plus"></i> New Service Entry
      </button>
    </div>
  </div>

  <?php if($msg): ?>
    <div class="alert <?php echo strpos($msg, '✅') !== false ? 'alert-success' : 'alert-danger'; ?>" style="margin-top:15px;">
      <?php echo $msg; ?>
    </div>
  <?php endif; ?>

  <!-- STAFF STATS -->
  <div class="cards four" style="margin-top:18px">
    <div class="card metric">
      <div class="metric-ico blue"><i class="fas fa-tasks"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Active Services</div>
        <div class="metric-value">
          <?php 
          $active = array_filter($my_services, function($s) {
              return in_array($s['status'], ['Pending', 'In Progress']);
          });
          echo count($active);
          ?>
        </div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico green"><i class="fas fa-check-circle"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Completed Today</div>
        <div class="metric-value">
          <?php 
          $today = date('Y-m-d');
          $completed_today = array_filter($my_services, function($s) use ($today) {
              return $s['status'] === 'Completed' && 
                     $s['completed_at'] && 
                     substr($s['completed_at'], 0, 10) === $today;
          });
          echo count($completed_today);
          ?>
        </div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico purple"><i class="fas fa-money-bill-wave"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Revenue Pending</div>
        <div class="metric-value">
          ₱<?php 
          $pending_revenue = array_sum(array_column(
              array_filter($my_services, function($s) {
                  return $s['status'] === 'Completed';
              }), 
              'total_cost'
          ));
          echo number_format($pending_revenue, 0);
          ?>
        </div>
      </div>
    </div>
    <div class="card metric">
      <div class="metric-ico amber"><i class="fas fa-clock"></i></div>
      <div class="metric-meta">
        <div class="metric-label">Avg. Duration</div>
        <div class="metric-value">
          <?php 
          $completed = array_filter($my_services, function($s) {
              return $s['status'] === 'Completed' && $s['started_at'] && $s['completed_at'];
          });
          if (count($completed) > 0) {
              $total_minutes = 0;
              foreach($completed as $s) {
                  $start = strtotime($s['started_at']);
                  $end = strtotime($s['completed_at']);
                  $total_minutes += ($end - $start) / 60;
              }
              echo round($total_minutes / count($completed)) . ' min';
          } else {
              echo 'N/A';
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <!-- MY SERVICES TABLE -->
  <div class="service-card" style="margin-top:20px;">
    <h4><i class="fas fa-list"></i> My Service Entries</h4>
    <div class="muted">All services you have created</div>
    
    <div class="table-responsive mt-3">
      <table class="table table-hover">
        <thead>
          <tr>
            <th>ID</th>
            <th>Customer/Vehicle</th>
            <th>Service Description</th>
            <th>Cost</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($my_services as $service): ?>
          <tr>
            <td>#<?php echo $service['id']; ?></td>
            <td>
              <?php if($service['vehicle_plate']): ?>
                <b><?php echo htmlspecialchars($service['vehicle_plate']); ?></b><br>
                <small><?php echo htmlspecialchars($service['vehicle_type']); ?></small>
              <?php elseif($service['customer_name']): ?>
                <b><?php echo htmlspecialchars($service['customer_name']); ?></b>
              <?php else: ?>
                <span class="text-muted">Walk-in</span>
              <?php endif; ?>
            </td>
            <td>
              <b><?php echo htmlspecialchars($service['category_name'] ?? 'Custom'); ?></b><br>
              <small><?php echo nl2br(htmlspecialchars(substr($service['service_description'], 0, 100))); ?>...</small>
            </td>
            <td>
              <b>₱<?php echo number_format($service['total_cost'], 2); ?></b><br>
              <small>Parts: ₱<?php echo number_format($service['parts_cost'], 2); ?></small>
            </td>
            <td>
              <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $service['status'])); ?>">
                <?php echo $service['status']; ?>
              </span>
              <?php if($service['started_at']): ?>
                <br><small>Started: <?php echo date('H:i', strtotime($service['started_at'])); ?></small>
              <?php endif; ?>
            </td>
            <td>
              <?php echo date('M d', strtotime($service['created_at'])); ?><br>
              <small><?php echo date('H:i', strtotime($service['created_at'])); ?></small>
            </td>
            <td>
              <div class="btn-group btn-group-sm">
                <?php if($service['status'] === 'Pending'): ?>
                  <button class="btn btn-primary" onclick="updateStatus(<?php echo $service['id']; ?>, 'In Progress')">
                    <i class="fas fa-play"></i> Start
                  </button>
                  <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAddParts" onclick="setServiceId(<?php echo $service['id']; ?>)">
                    <i class="fas fa-box"></i> Parts
                  </button>
                <?php elseif($service['status'] === 'In Progress'): ?>
                  <button class="btn btn-success" onclick="updateStatus(<?php echo $service['id']; ?>, 'Completed')">
                    <i class="fas fa-check"></i> Complete
                  </button>
                  <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAddParts" onclick="setServiceId(<?php echo $service['id']; ?>)">
                    <i class="fas fa-box"></i> Parts
                  </button>
                <?php elseif($service['status'] === 'Completed'): ?>
                  <span class="badge bg-secondary">Waiting Admin</span>
                <?php elseif($service['status'] === 'Finalized'): ?>
                  <span class="badge bg-success">Finalized</span>
                <?php endif; ?>
                
                <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalViewService" onclick="viewService(<?php echo $service['id']; ?>)">
                  <i class="fas fa-eye"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          
          <?php if(empty($my_services)): ?>
          <tr>
            <td colspan="7" class="text-center py-4">
              <div class="text-muted">
                <i class="fas fa-tools fa-2x mb-3"></i><br>
                No service entries yet. Create your first one!
              </div>
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- MODALS -->

<!-- Modal: Add New Service Entry -->
<div class="modal fade" id="modalAddService" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-plus"></i> New Service Entry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="add_service">
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Service Type</label>
                <select name="service_category_id" class="form-control" onchange="updateServiceDefaults(this)">
                  <option value="">-- Select Service Type --</option>
                  <?php foreach($service_categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" 
                            data-parts="<?php echo $cat['default_parts_cost']; ?>"
                            data-labor="<?php echo $cat['default_labor_cost']; ?>"
                            data-duration="<?php echo $cat['default_duration']; ?>">
                      <?php echo htmlspecialchars($cat['name']); ?>
                      <?php if($cat['default_parts_cost'] > 0 || $cat['default_labor_cost'] > 0): ?>
                        (₱<?php echo number_format($cat['default_parts_cost'] + $cat['default_labor_cost'], 0); ?>)
                      <?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                  <option value="0">-- Custom Service --</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Vehicle Plate (Optional)</label>
                <input type="text" name="vehicle_plate" class="form-control" placeholder="ABC-123">
              </div>
            </div>
          </div>
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Vehicle Type (Optional)</label>
                <input type="text" name="vehicle_type" class="form-control" placeholder="SUV, Sedan, Motorcycle">
              </div>
            </div>
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Customer Name (Optional)</label>
                <input type="text" name="customer_name" class="form-control" placeholder="John Doe">
              </div>
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Service Description *</label>
            <textarea name="service_description" class="form-control" rows="3" required placeholder="Describe the service requested by customer..."></textarea>
          </div>
          
          <div class="row">
            <div class="col-md-4">
              <div class="mb-3">
                <label class="form-label">Parts Cost (₱)</label>
                <input type="number" step="0.01" name="parts_cost" id="partsCost" class="form-control" value="0">
              </div>
            </div>
            <div class="col-md-4">
              <div class="mb-3">
                <label class="form-label">Labor Cost (₱)</label>
                <input type="number" step="0.01" name="labor_cost" id="laborCost" class="form-control" value="0">
              </div>
            </div>
            <div class="col-md-4">
              <div class="mb-3">
                <label class="form-label">Total Cost (₱)</label>
                <input type="text" id="totalCost" class="form-control" readonly style="background:#e9ecef;">
              </div>
            </div>
          </div>
          
          <div class="row">
            <div class="col-md-6">
              <div class="mb-3">
                <label class="form-label">Estimated Duration (minutes)</label>
                <input type="number" name="estimated_duration" id="estDuration" class="form-control" value="60">
              </div>
            </div>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Notes (Optional)</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Service Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Add Parts to Service -->
<div class="modal fade" id="modalAddParts" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-box"></i> Add Parts to Service</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="add_parts">
        <input type="hidden" name="service_id" id="partsServiceId">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Select Inventory Item</label>
            <select name="inventory_id" class="form-control" required>
              <option value="">-- Select Item --</option>
              <?php foreach($inventory_items as $item): ?>
                <option value="<?php echo $item['id']; ?>">
                  <?php echo htmlspecialchars($item['product_name']); ?>
                  (Stock: <?php echo $item['stock_level']; ?>)
                  - ₱<?php echo number_format($item['selling_price'] ?? $item['cost_price'] ?? 0, 2); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Quantity</label>
            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
          </div>
          
          <div class="alert alert-info">
            <small>
              <i class="fas fa-info-circle"></i> Parts will be deducted from inventory and added to service cost.
            </small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Add Parts</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Update Status -->
<div class="modal fade" id="modalUpdateStatus" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Update Service Status</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="id" id="statusServiceId">
        <input type="hidden" name="status" id="statusValue">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">New Status</label>
            <input type="text" id="statusDisplay" class="form-control" readonly style="background:#e9ecef;">
          </div>
          
          <div class="mb-3">
            <label class="form-label">Notes (Optional)</label>
            <textarea name="notes" class="form-control" rows="3" placeholder="Progress update, observations..."></textarea>
          </div>
          
          <div id="statusWarning" class="alert alert-warning" style="display:none;">
            <small>
              <i class="fas fa-exclamation-triangle"></i> 
              Once marked as Completed, this service will await Admin verification.
            </small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Status</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: View Service Details -->
<div class="modal fade" id="modalViewService" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Service Details #<span id="viewServiceId"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="viewServiceContent">
        <!-- Loaded via AJAX -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
// Auto-calculate total cost
function calculateTotal() {
    const parts = parseFloat(document.getElementById('partsCost')?.value) || 0;
    const labor = parseFloat(document.getElementById('laborCost')?.value) || 0;
    const total = parts + labor;
    if (document.getElementById('totalCost')) {
        document.getElementById('totalCost').value = '₱' + total.toFixed(2);
    }
}

// Update form with service defaults
function updateServiceDefaults(select) {
    const option = select.options[select.selectedIndex];
    const parts = option.getAttribute('data-parts') || 0;
    const labor = option.getAttribute('data-labor') || 0;
    const duration = option.getAttribute('data-duration') || 60;
    
    if (document.getElementById('partsCost')) {
        document.getElementById('partsCost').value = parts;
    }
    if (document.getElementById('laborCost')) {
        document.getElementById('laborCost').value = labor;
    }
    if (document.getElementById('estDuration')) {
        document.getElementById('estDuration').value = duration;
    }
    calculateTotal();
}

// Set service ID for parts modal
function setServiceId(id) {
    document.getElementById('partsServiceId').value = id;
}

// Update status modal
function updateStatus(id, status) {
    document.getElementById('statusServiceId').value = id;
    document.getElementById('statusValue').value = status;
    document.getElementById('statusDisplay').value = status;
    
    if (status === 'Completed') {
        document.getElementById('statusWarning').style.display = 'block';
    } else {
        document.getElementById('statusWarning').style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('modalUpdateStatus')).show();
}

// View service details via AJAX
function viewService(id) {
    document.getElementById('viewServiceId').innerText = id;
    
    fetch(`backend/get_service_details.php?id=${id}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('viewServiceContent').innerHTML = html;
        })
        .catch(error => {
            document.getElementById('viewServiceContent').innerHTML = 
                '<div class="alert alert-danger">Error loading service details.</div>';
        });
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Calculate total when parts/labor costs change
    const costInputs = ['partsCost', 'laborCost'];
    costInputs.forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', calculateTotal);
        }
    });
    
    // Auto-calculate on service type change
    const serviceSelect = document.querySelector('select[name="service_category_id"]');
    if (serviceSelect) {
        serviceSelect.addEventListener('change', function() {
            updateServiceDefaults(this);
        });
    }
    
    calculateTotal(); // Initial calculation
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
