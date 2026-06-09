<?php
$page_id = 'inventory';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = $me['role'] ?? 'staff';
$isAdminOrSuper = in_array($role, ['admin', 'superadmin']);
$canStock = can('inventory.stock');
$station_id = user_station_id();

$msg = '';

// CSRF Token for Security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') 
    // CSRF Check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $msg = "❌ Error: Invalid request.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_fuel') {
            if (!$canStock) {
                $msg = "❌ Error: Not permitted by RBAC to manage fuel inventory.";
            } else {
                $fuel_type = trim($_POST['fuel_type'] ?? '');
                $liters = (float)($_POST['liters'] ?? 0);
                $station = $role === 'superadmin' ? trim($_POST['station_id'] ?? '') : $station_id;

                // Input validation
                if (empty($fuel_type)) {
                    $msg = "❌ Error: Fuel type is required.";
                } elseif (!in_array($fuel_type, ["Diesel", "XCS Plus", "XTRA UNL", "Turbo Diesel", "Kerosene"])) {
                    $msg = "❌ Error: Invalid fuel type.";
                } elseif ($liters <= 0 || $liters > 100000) { // Reasonable max to prevent abuse
                    $msg = "❌ Error: Liters must be a positive number and less than 100,000.";
                } elseif ($role === 'superadmin' && empty($station)) {
                    $msg = "❌ Error: Station is required for Super Admin.";
                } else {
                    try {
                        // Check if fuel type exists in inventory
                        $stmt = $pdo->prepare("SELECT id FROM inventory WHERE station_id = ? AND product_name = ? AND type = 'fuel'");
                        $stmt->execute([$station, $fuel_type]);
                        if ($stmt->rowCount() > 0) {
                            // Update existing
                            $stmt = $pdo->prepare("UPDATE inventory SET stock_level = stock_level + ? WHERE station_id = ? AND product_name = ? AND type = 'fuel'");
                            $stmt->execute([$liters, $station, $fuel_type]);
                            $msg = "✅ Fuel stock updated successfully.";
                            log_activity($pdo, $me['id'], 'Update Fuel Inventory', "Added $liters liters of $fuel_type to station $station");
                        } else {
                            // Insert new
                            $stmt = $pdo->prepare("INSERT INTO inventory (station_id, product_name, stock_level, type) VALUES (?, ?, ?, 'fuel')");
                            $stmt->execute([$station, $fuel_type, $liters]);
                            $msg = "✅ Fuel stock added successfully.";
                            log_activity($pdo, $me['id'], 'Create Fuel Inventory', "Created $liters liters of $fuel_type for station $station");
                        }
                    } catch (PDOException $e) {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'save_merch') {
            if (!$canStock) {
                $msg = "❌ Error: Not permitted by RBAC to manage merchandise inventory.";
            } else {
                $id = trim($_POST['id'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $sku = trim($_POST['sku'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $stock = (int)($_POST['stock'] ?? 0);
                $cost = (float)($_POST['cost'] ?? 0);
                $price = (float)($_POST['price'] ?? 0);
                $station = $role === 'superadmin' ? trim($_POST['station_id'] ?? '') : $station_id;

                // Input validation
                if (empty($name) || strlen($name) > 255) {
                    $msg = "❌ Error: Product name is required and must be less than 255 characters.";
                } elseif (strlen($sku) > 100) {
                    $msg = "❌ Error: SKU must be less than 100 characters.";
                } elseif (strlen($category) > 100) {
                    $msg = "❌ Error: Category must be less than 100 characters.";
                } elseif ($stock < 0 || $stock > 1000000) { // Reasonable max
                    $msg = "❌ Error: Stock must be non-negative and less than 1,000,000.";
                } elseif ($cost < 0 || $cost > 100000) {
                    $msg = "❌ Error: Cost must be non-negative and less than 100,000.";
                } elseif ($price < 0 || $price > 100000) {
                    $msg = "❌ Error: Price must be non-negative and less than 100,000.";
                } elseif ($price < $cost) {
                    $msg = "❌ Error: Selling price must be at least equal to cost.";
                } elseif ($role === 'superadmin' && empty($station)) {
                    $msg = "❌ Error: Station is required for Super Admin.";
                } else {
                    try {
                        if ($id) {
                            // Update
                            $stmt = $pdo->prepare("UPDATE inventory SET product_name=?, sku=?, category=?, stock_level=?, cost=?, price=? WHERE id=? AND station_id=? AND type='merch'");
                            $stmt->execute([$name, $sku, $category, $stock, $cost, $price, $id, $station]);
                            $msg = "✅ Merchandise updated successfully.";
                            log_activity($pdo, $me['id'], 'Update Merchandise Inventory', "Updated $name (ID: $id)");
                        } else {
                            // Insert
                            $stmt = $pdo->prepare("INSERT INTO inventory (station_id, product_name, sku, category, stock_level, cost, price, type) VALUES (?, ?, ?, ?, ?, ?, ?, 'merch')");
                            $stmt->execute([$station, $name, $sku, $category, $stock, $cost, $price]);
                            $msg = "✅ Merchandise added successfully.";
                            log_activity($pdo, $me['id'], 'Create Merchandise Inventory', "Created $name for station $station");
                        }
                    } catch (PDOException $e) {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'delete_merch') {
            if (!$canStock) {
                $msg = "❌ Error: Not permitted by RBAC to delete merchandise.";
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $msg = "❌ Error: Invalid item ID.";
                } else {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM inventory WHERE id=? AND type='merch'");
                        $stmt->execute([$id]);
                        $msg = "✅ Merchandise deleted successfully.";
                        log_activity($pdo, $me['id'], 'Delete Merchandise Inventory', "Deleted item ID: $id");
                    } catch (PDOException $e) {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'request_stock') {
            $req_type = ($_POST['req_type'] ?? '') === 'merch' ? 'merch' : 'fuel';
            $product = trim((string)($_POST['product_name'] ?? ''));
            // qty and notes are now set by the manager — staff just submits the request
            $qty   = 0;
            $notes = '';

            $station = ($role === 'superadmin') ? (int)($_POST['station_id'] ?? 0) : (int)$station_id;

            if ($station <= 0) {
                $msg = "❌ Error: Station is required.";
            } elseif ($product === '') {
                $msg = "❌ Error: Product is required.";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO stock_requests (station_id, requested_by, type, product_name, qty, notes, status, created_at)
                                          VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                    $stmt->execute([$station, (int)($me['id'] ?? 0), $req_type, $product, $qty, $notes]);
                    $msg = "✅ Stock request submitted. Waiting for manager approval.";
                    log_activity($pdo, $me['id'], 'Create Stock Request', "Requested $product ($req_type) for station $station");
                } catch (PDOException $e) {
                    // Most common cause: table doesn't exist yet
                    $msg = "❌ Error: Unable to save stock request. (Make sure stock_requests table exists.)";
                }
            }
        }

        // Admin/Super Admin: Approve/Reject a stock request
        elseif (in_array($action, ['approve_request','reject_request'], true)) {
            if (!in_array($role, ['admin','superadmin','manager'], true)) {
                $msg = "❌ Error: Not permitted.";
            } else {
                $rid = (int)($_POST['request_id'] ?? 0);
                if ($rid <= 0) {
                    $msg = "❌ Error: Invalid request.";
                } else {
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM stock_requests WHERE id=?");
                        $stmt->execute([$rid]);
                        $req = $stmt->fetch(PDO::FETCH_ASSOC);
                        if (!$req) {
                            $msg = "❌ Error: Request not found.";
                        } elseif (($req['status'] ?? '') !== 'pending') {
                            $msg = "❌ Error: Request already processed.";
                        } else {
                            // Station-level admins can only act on their station
                            if ($role !== 'superadmin' && (int)$req['station_id'] !== (int)$station_id) {
                                $msg = "❌ Error: You can only process requests from your station.";
                            } else {
                                if ($action === 'reject_request') {
                                    $stmt = $pdo->prepare("UPDATE stock_requests SET status='rejected', processed_by=?, processed_at=NOW() WHERE id=?");
                                    $stmt->execute([(int)($me['id'] ?? 0), $rid]);
                                    $msg = "✅ Request rejected.";
                                } else {
                                    // Approve: update inventory based on type
                                    $reqType = ($req['type'] ?? '') === 'merch' ? 'merch' : 'fuel';
                                    $product = (string)($req['product_name'] ?? '');
                                    $qty = (float)($req['qty'] ?? 0);
                                    $station = (int)($req['station_id'] ?? 0);

                                    if ($reqType === 'fuel') {
                                        // Add liters to fuel inventory
                                        $stmtI = $pdo->prepare("SELECT id FROM inventory WHERE station_id=? AND product_name=? AND type='fuel'");
                                        $stmtI->execute([$station, $product]);
                                        if ($stmtI->rowCount() > 0) {
                                            $stmtU = $pdo->prepare("UPDATE inventory SET stock_level = stock_level + ? WHERE station_id=? AND product_name=? AND type='fuel'");
                                            $stmtU->execute([$qty, $station, $product]);
                                        } else {
                                            $stmtIns = $pdo->prepare("INSERT INTO inventory (station_id, product_name, stock_level, type) VALUES (?, ?, ?, 'fuel')");
                                            $stmtIns->execute([$station, $product, $qty]);
                                        }
                                    } else {
                                        // Add pieces to merchandise inventory
                                        $stmtI = $pdo->prepare("SELECT id FROM inventory WHERE station_id=? AND product_name=? AND type='merch'");
                                        $stmtI->execute([$station, $product]);
                                        if ($stmtI->rowCount() > 0) {
                                            $stmtU = $pdo->prepare("UPDATE inventory SET stock_level = stock_level + ? WHERE station_id=? AND product_name=? AND type='merch'");
                                            $stmtU->execute([(int)$qty, $station, $product]);
                                        } else {
                                            $stmtIns = $pdo->prepare("INSERT INTO inventory (station_id, product_name, stock_level, type) VALUES (?, ?, ?, 'merch')");
                                            $stmtIns->execute([$station, $product, (int)$qty]);
                                        }
                                    }

                                    $stmt = $pdo->prepare("UPDATE stock_requests SET status='approved', processed_by=?, processed_at=NOW() WHERE id=?");
                                    $stmt->execute([(int)($me['id'] ?? 0), $rid]);
                                    $msg = "✅ Request approved and stock updated.";
                                    log_activity($pdo, $me['id'], 'Approve Stock Request', "Approved request #$rid ($qty $product) station $station");
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        $msg = "❌ Error: " . $e->getMessage();
                    }
                }
            }
        }
                }

// Fetch Fuel Inventory
$fuel_inventory = [];
if ($role === 'superadmin') {
    $stmt = $pdo->prepare("SELECT i.*, s.name as station_name FROM inventory i LEFT JOIN stations s ON i.station_id = s.id WHERE i.type = 'fuel' ORDER BY s.name, i.product_name");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT i.*, s.name as station_name FROM inventory i LEFT JOIN stations s ON i.station_id = s.id WHERE i.type = 'fuel' AND i.station_id = ? ORDER BY i.product_name");
    $stmt->execute([$station_id]);
}
$fuel_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Merchandise Inventory
$merch_inventory = [];
if ($role === 'superadmin') {
    $stmt = $pdo->prepare("SELECT i.*, s.name as station_name FROM inventory i LEFT JOIN stations s ON i.station_id = s.id WHERE i.type = 'merch' ORDER BY s.name, i.product_name");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT i.*, s.name as station_name FROM inventory i LEFT JOIN stations s ON i.station_id = s.id WHERE i.type = 'merch' AND i.station_id = ? ORDER BY i.product_name");
    $stmt->execute([$station_id]);
}
$merch_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch stations for superadmin
$stations = [];
if ($role === 'superadmin') {
    $stations = $pdo->query("SELECT id, name FROM stations ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
}

include __DIR__ . '/../partials/header.php';
?>
  <div class="page-head">
    <div>
      <h1 class="h1">Inventory Management</h1>
      <div class="sub">Track fuel levels and merchandise stock</div>
    </div>
  </div>

  <?php if($msg): ?><div class="card" style="padding:10px; margin-top:10px; background:#e6f4ea; color:green;"><?php echo $msg; ?></div><?php endif; ?>

  <div class="tabs pills">
    <button class="tab active" data-invtab="fuel"><i class="fas fa-gas-pump"></i> Fuel Inventory</button>
    <button class="tab" data-invtab="merch"><i class="fas fa-box"></i> Merchandise</button>
    <button class="tab" data-invtab="req"><i class="fas fa-clipboard-list"></i> Stock Requests</button>
  </div>

  <!-- Fuel Inventory -->
  <section class="card" id="fuelInv">
    <div class="card-head">
      <div class="card-title">Fuel Inventory</div>
      <?php if ($canStock): ?>
        <button class="btn primary" onclick="openFuelModal()">+ Stock In</button>
      <?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table" id="fuelTable">
        <thead>
          <tr>
            <?php if ($role === 'superadmin'): ?><th>Station</th><?php endif; ?>
            <th>Fuel Type</th>
            <th>Current Level</th>
            <th>Capacity</th>
            <th>Status</th>
            <th>Price/L</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fuel_inventory as $item): ?>
            <tr>
              <?php if ($role === 'superadmin'): ?><td><?php echo htmlspecialchars($item['station_name'] ?? 'Unknown'); ?></td><?php endif; ?>
              <td><?php echo htmlspecialchars($item['product_name']); ?></td>
              <td><?php echo number_format($item['stock_level'], 2); ?> L</td>
              <td><?php echo number_format($item['capacity'] ?? 10000, 2); ?> L</td>
              <td>
                <?php
                $level = (float)($item['stock_level'] ?? 0);
                $capacity = (float)($item['capacity'] ?? 10000);
                $percentage = $capacity > 0 ? ($level / $capacity) * 100 : 0;
                $status = $percentage < 20 ? 'Low Stock' : ($percentage > 90 ? 'Near Full' : 'Normal');
                $status_color = $percentage < 20 ? 'red' : ($percentage > 90 ? 'orange' : 'green');
                echo "<span style='color: $status_color;'>$status</span>";
                ?>
              </td>
              <td>₱<?php echo number_format($item['price'] ?? 0, 2); ?></td>
              <td class="right">
                <?php if ($canStock): ?>
                  <button class="btn ghost small" onclick="editFuel('<?php echo $item['product_name']; ?>', <?php echo $item['stock_level']; ?>)">Edit</button>
                <?php else: ?>
                  <span class="muted"><a href="stock_request.php?item_id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus-circle"></i> Request Stock</a></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($fuel_inventory)): ?>
            <tr><td colspan="<?php echo $role === 'superadmin' ? 7 : 6; ?>" style="text-align:center;">No fuel inventory data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Merchandise Inventory -->
  <section class="card hidden" id="merchInv">
    <div class="card-head">
      <div class="card-title">Merchandise Inventory</div>
      <?php if ($canStock): ?>
        <button class="btn primary" onclick="openMerchModal()">+ Add Item</button>
      <?php endif; ?>
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
            <?php if ($role === 'superadmin'): ?><th>Station</th><?php endif; ?>
            <th>Product</th>
            <th>SKU</th>
            <th>Category</th>
            <th>Stock</th>
            <th>Cost</th>
            <th>Price</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($merch_inventory as $item): ?>
            <tr>
              <?php if ($role === 'superadmin'): ?><td><?php echo htmlspecialchars($item['station_name'] ?? 'Unknown'); ?></td><?php endif; ?>
              <td><?php echo htmlspecialchars($item['product_name']); ?></td>
              <td><?php echo htmlspecialchars($item['sku'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($item['category'] ?? ''); ?></td>
              <td><?php echo number_format($item['stock_level'], 0); ?></td>
              <td>₱<?php echo number_format($item['cost'] ?? 0, 2); ?></td>
              <td>₱<?php echo number_format($item['price'] ?? 0, 2); ?></td>
              <td class="right">
                <?php if ($canStock): ?>
                  <button class="btn ghost small" onclick="editMerch(<?php echo $item['id']; ?>)">Edit</button>
                  <button class="btn ghost small red" onclick="deleteMerch(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['product_name']); ?>')">Delete</button>
                <?php else: ?>
                  <span class="muted"><a href="stock_request.php?item_id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus-circle"></i> Request Stock</a></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($merch_inventory)): ?>
            <tr><td colspan="<?php echo $role === 'superadmin' ? 8 : 7; ?>" style="text-align:center;">No merchandise inventory data available.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Stock Requests -->
  <section class="card hidden" id="reqInv">
    <div class="card-head">
      <div class="card-title">Stock Requests</div>
      <div class="muted">Staff submit requests here; admin approves and stock updates automatically.</div>
    </div>

    <?php
      // Fetch requests (pending first)
      $requests = [];
      try {
        if ($role === 'superadmin') {
          $stmt = $pdo->query("SELECT r.*, s.name AS station_name, u.name AS requester_name
                               FROM stock_requests r
                               LEFT JOIN stations s ON r.station_id = s.id
                               LEFT JOIN users u ON r.requested_by = u.id
                               ORDER BY (r.status='pending') DESC, r.created_at DESC");
          $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
          $stmt = $pdo->prepare("SELECT r.*, s.name AS station_name, u.name AS requester_name
                                 FROM stock_requests r
                                 LEFT JOIN stations s ON r.station_id = s.id
                                 LEFT JOIN users u ON r.requested_by = u.id
                                 WHERE r.station_id = ?
                                 ORDER BY (r.status='pending') DESC, r.created_at DESC");
          $stmt->execute([$station_id]);
          $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
      } catch (Exception $e) {
        $requests = [];
      }
    ?>

    <!-- Request form (any logged-in role) -->
    <div class="card" style="margin:14px 0; padding:14px;">
      <div style="font-weight:600; margin-bottom:10px;"><i class="fas fa-paper-plane"></i> Create a request</div>
      <form method="post" class="grid" style="display:grid; grid-template-columns: 180px 1fr; gap:10px; align-items:end;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="request_stock" />

        <div>
          <label class="pay-label">Type</label>
          <select class="select" name="req_type">
            <option value="fuel">Fuel (Liters)</option>
            <option value="merch">Merchandise (Pieces)</option>
          </select>
        </div>

        <div>
          <label class="pay-label">Product name</label>
          <input class="input" name="product_name" placeholder="e.g., Diesel Max / Engine Oil" required />
        </div>

        <?php if ($role === 'superadmin'): ?>
          <div style="grid-column: 1 / -1;">
            <label class="pay-label">Station</label>
            <select class="select" name="station_id" required>
              <option value="">-- Select Station --</option>
              <?php foreach ($stations as $sid => $sname): ?>
                <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div style="grid-column: 1 / -1; display:flex; gap:10px; justify-content:flex-end;">
          <button class="btn primary" type="submit">Submit Request</button>
        </div>
      </form>
    </div>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($role === 'superadmin'): ?><th>Station</th><?php endif; ?>
            <th>Date</th>
            <th>Requested By</th>
            <th>Type</th>
            <th>Product</th>
            <th>Qty</th>
            <th>Status</th>
            <th class="right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <?php if ($role === 'superadmin'): ?><td><?php echo htmlspecialchars($r['station_name'] ?? ''); ?></td><?php endif; ?>
              <td><?php echo htmlspecialchars($r['created_at'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($r['requester_name'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars(($r['type'] ?? '') === 'merch' ? 'Merch' : 'Fuel'); ?></td>
              <td><?php echo htmlspecialchars($r['product_name'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($r['qty'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars(ucfirst($r['status'] ?? '')); ?></td>
              <td class="right">
                <?php if (($r['status'] ?? '') === 'pending' && in_array($role, ['admin','superadmin','manager'], true)): ?>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
                    <input type="hidden" name="action" value="approve_request" />
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>" />
                    <button class="btn ghost small" type="submit">Approve</button>
                  </form>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
                    <input type="hidden" name="action" value="reject_request" />
                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>" />
                    <button class="btn ghost small red" type="submit">Reject</button>
                  </form>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($requests)): ?>
            <tr><td colspan="<?php echo $role === 'superadmin' ? 8 : 7; ?>" style="text-align:center;">No requests yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Fuel Stock In Modal -->
  <div class="modal" id="fuelModal" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-head">
        <div class="modal-title" id="fuelModalTitle">Stock In Fuel</div>
        <button class="icon-btn" onclick="document.getElementById('fuelModal').classList.remove('active')">✕</button>
      </div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="save_fuel">
        <?php if ($role === 'superadmin'): ?>
          <div class="pay-section">
            <label class="pay-label">Station</label>
            <select class="select" name="station_id" required>
              <option value="">-- Select Station --</option>
              <?php foreach ($stations as $sid => $sname): ?>
                <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($station_id); ?>">
        <?php endif; ?>
        <div class="pay-section">
          <label class="pay-label">Fuel Type</label>
          <select class="select" name="fuel_type" id="fuelSelect" required>
            <option value="">-- Select Fuel Type --</option>
            <option value="Diesel">Diesel</option>
            <option value="XCS Plus">XCS Plus</option>
            <option value="XTRA UNL">XTRA UNL</option>
            <option value="Turbo Diesel">Turbo Diesel</option>
            <option value="Kerosene">Kerosene</option>
          </select>
        </div>
        <div class="pay-section">
          <label class="pay-label">Liters to add</label>
          <input class="input" name="liters" id="fuelLiters" type="number" min="0.01" step="0.01" placeholder="0.00" required />
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" onclick="document.getElementById('fuelModal').classList.remove('active')">Cancel</button>
          <button type="submit" class="btn primary">Save</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Add/Edit Merchandise Modal -->
  <div class="modal" id="merchModal" aria-hidden="true">
    <div class="modal-card">
      <div class="modal-head">
        <div class="modal-title" id="merchModalTitle">Add Item</div>
        <button class="icon-btn" data-close="merchModal">✕</button>
      </div>

      <form method="post" id="merchForm">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" />
        <input type="hidden" name="action" value="save_merch" />
        <input type="hidden" name="id" id="mId" value="" />

        <?php if ($role === 'superadmin'): ?>
          <div class="pay-section" style="margin-bottom:10px;">
            <label class="pay-label">Station</label>
            <select class="select" name="station_id" id="mStation" required>
              <option value="">-- Select Station --</option>
              <?php foreach ($stations as $sid => $sname): ?>
                <option value="<?php echo $sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="station_id" value="<?php echo htmlspecialchars($station_id); ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div>
            <label>Product name</label>
            <input class="input" id="mName" name="name" placeholder="e.g., Coke Can" required />
          </div>
          <div>
            <label>SKU</label>
            <input class="input" id="mSku" name="sku" placeholder="e.g., BVG-001" />
          </div>
          <div>
            <label>Category</label>
            <input class="input" id="mCategory" name="category" placeholder="e.g., beverages" />
          </div>
          <div>
            <label>Stock</label>
            <input class="input" id="mStock" name="stock" type="number" min="0" step="1" value="0" />
          </div>
          <div>
            <label>Cost</label>
            <input class="input" id="mCost" name="cost" type="number" min="0" step="0.01" value="0" />
          </div>
          <div>
            <label>Price</label>
            <input class="input" id="mPrice" name="price" type="number" min="0" step="0.01" value="0" />
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn ghost" type="button" data-close="merchModal">Cancel</button>
          <button class="btn primary" id="merchSaveBtn" type="submit">Save</button>
        </div>
      </form>
    </div>
  </div>

<script>
// Tab switching (fixed)
const invTabs = document.querySelectorAll('.tab[data-invtab]');
function showInvTab(key){
  invTabs.forEach(b => b.classList.toggle('active', b.dataset.invtab === key));
  document.getElementById('fuelInv')?.classList.toggle('hidden', key !== 'fuel');
  document.getElementById('merchInv')?.classList.toggle('hidden', key !== 'merch');
  document.getElementById('reqInv')?.classList.toggle('hidden', key !== 'req');
}
invTabs.forEach(btn => btn.addEventListener('click', () => showInvTab(btn.dataset.invtab)));
showInvTab('fuel');

// Fuel Modal Functions
function openFuelModal() {
    document.getElementById('fuelModalTitle').textContent = 'Stock In Fuel';
    document.getElementById('fuelModal').classList.add('active');
    // Reset form
    document.querySelector('#fuelModal form').reset();
}

function editFuel(fuelType, currentLevel) {
    document.getElementById('fuelModalTitle').textContent = 'Update Fuel Stock';
    document.getElementById('fuelSelect').value = fuelType;
    document.getElementById('fuelLiters').value = currentLevel;
    document.getElementById('fuelModal').classList.add('active');
}

// Merchandise Modal Functions
function openMerchModal() {
    document.getElementById('merchModalTitle').textContent = 'Add Item';
    document.getElementById('merchSaveBtn').textContent = 'Add Item';
    document.getElementById('merchModal').classList.add('active');
    // Reset form
    document.getElementById('merchForm').reset();
    document.getElementById('mId').value = '';
}

function editMerch(id) {
    // Fetch merchandise data and populate modal
    fetch('backend/inventory.php?action=get&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                const item = data.data.item;
                document.getElementById('merchModalTitle').textContent = 'Edit Item';
                document.getElementById('merchSaveBtn').textContent = 'Update Item';
                document.getElementById('mId').value = item.id;
                document.getElementById('mName').value = item.product_name;
                document.getElementById('mSku').value = item.sku || '';
                document.getElementById('mCategory').value = item.category || '';
                document.getElementById('mStock').value = item.stock_level;
                document.getElementById('mCost').value = item.cost || 0;
                document.getElementById('mPrice').value = item.price || 0;
                if (document.getElementById('mStation')) {
                    document.getElementById('mStation').value = item.station_id;
                }
                document.getElementById('merchModal').classList.add('active');
            } else {
                alert('Error loading merchandise data');
            }
        })
        .catch(error => console.error('Error:', error));
}

function deleteMerch(id, name) {
    if (confirm('Are you sure you want to delete "' + name + '"?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>" /><input name="action" value="delete_merch"><input name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
