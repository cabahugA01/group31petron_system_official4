<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$page_id = 'product_management';
// Detect active tab from URL param or POST for sidebar active state
$active_tab = in_array($_GET['tab'] ?? '', ['fuel','merchandise']) ? $_GET['tab'] : 
              (in_array($_POST['_tab'] ?? '', ['fuel','merchandise']) ? $_POST['_tab'] : 'fuel');
if ($active_tab === 'fuel')            $page_id = 'pm_fuel';
elseif ($active_tab === 'merchandise') $page_id = 'pm_merchandise';
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');

// Restrict to managers only
if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    $_SESSION['error'] = 'Access denied. Manager access required.';
    header('Location: dashboard.php');
    exit;
}

$station_id = user_station_id();

// Handle product actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_product') {
        $name         = trim($_POST['product_name'] ?? '');
        $category     = trim($_POST['category'] ?? '');
        $unit_cost    = (float)($_POST['unit_cost']  ?? 0);
        $unit_price   = (float)($_POST['unit_price'] ?? $unit_cost);
        $sku          = trim($_POST['sku'] ?? '');
        $size         = trim($_POST['size'] ?? '');
        $supplier     = trim($_POST['supplier'] ?? '');
        $stock_level  = (int)($_POST['stock_level'] ?? 0);

        if (empty($name)) {
            $_SESSION['error'] = 'Product name is required.';
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        }

        if ($active_tab === 'fuel') {
            // ── FUEL: insert into fuel_inventory ──────────────────────
            $fuel_type       = $name;
            $price_per_liter = $unit_price ?: $unit_cost;
            $current_level   = (float)($_POST['current_level'] ?? 0);
            $capacity        = (float)($_POST['capacity'] ?? 25000);

            try {
                // Check if this fuel type already exists for this station
                $chk = $pdo->prepare("SELECT id FROM fuel_inventory WHERE station_id = ? AND LOWER(TRIM(fuel_type)) = LOWER(TRIM(?))");
                $chk->execute([$station_id, $fuel_type]);
                if ($chk->fetchColumn()) {
                    $_SESSION['error'] = "Fuel type '$fuel_type' already exists for this station.";
                    header('Location: product_management.php?tab=fuel');
                    exit;
                }

                // Look up fuel_type_id from fuel_types table (required NOT NULL column)
                $ft_stmt = $pdo->prepare("SELECT id FROM fuel_types WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                $ft_stmt->execute([$fuel_type]);
                $fuel_type_id = $ft_stmt->fetchColumn();

                // If not found, insert into fuel_types first
                if (!$fuel_type_id) {
                    $ins_ft = $pdo->prepare("INSERT INTO fuel_types (name) VALUES (?)");
                    $ins_ft->execute([$fuel_type]);
                    $fuel_type_id = (int)$pdo->lastInsertId();
                }

                $stmt = $pdo->prepare("
                    INSERT INTO fuel_inventory
                        (station_id, fuel_type_id, fuel_type, price_per_liter, current_level, capacity, status, last_updated)
                    VALUES (?, ?, ?, ?, ?, ?, 'Normal', NOW())
                ");
                $stmt->execute([$station_id, $fuel_type_id, $fuel_type, $price_per_liter, $current_level, $capacity]);

                // Also add to inventory_products so it appears in product catalog (scoped to station)
                $ip_chk = $pdo->prepare("SELECT id FROM inventory_products WHERE station_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND category = 'Fuel'");
                $ip_chk->execute([$station_id, $fuel_type]);
                if (!$ip_chk->fetchColumn()) {
                    $ip_stmt = $pdo->prepare("INSERT INTO inventory_products (station_id, product_name, category, unit_cost, unit_price, sku, stock, created_at) VALUES (?, ?, 'Fuel', ?, ?, ?, 0, NOW())");
                    $ip_stmt->execute([$station_id, $fuel_type, $price_per_liter, $price_per_liter, strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $fuel_type), 0, 6))]);
                }


                $_SESSION['success'] = "Fuel type '$fuel_type' added successfully.";
                header('Location: product_management.php?tab=fuel');
                exit;
            } catch (Exception $e) {
                $_SESSION['error'] = 'Error adding fuel product: ' . $e->getMessage();
                header('Location: product_management.php?tab=fuel');
                exit;
            }

        } else {
            // ── MERCHANDISE: insert into inventory_products + station_inventory ──
            if (empty($category)) $category = 'Merchandise';

            try {
                // Check if product already exists in inventory_products for THIS station
                $ip_chk = $pdo->prepare("SELECT id FROM inventory_products WHERE station_id = ? AND LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND LOWER(COALESCE(category,'')) NOT IN ('fuel', 'fuel products') LIMIT 1");
                $ip_chk->execute([$station_id, $name]);
                $existing_ip_id = $ip_chk->fetchColumn();

                // Check if already linked to THIS station
                if ($existing_ip_id) {
                    $si_chk = $pdo->prepare("SELECT id FROM station_inventory WHERE station_id = ? AND product_id = ?");
                    $si_chk->execute([$station_id, $existing_ip_id]);
                    if ($si_chk->fetchColumn()) {
                        $_SESSION['error'] = "Product '$name' already exists in this station's inventory.";
                        header('Location: product_management.php?tab=merchandise');
                        exit;
                    }
                }

                $pdo->beginTransaction();

                if ($existing_ip_id) {
                    // Product exists globally — just link it to this station
                    $new_product_id = (int)$existing_ip_id;
                    // Update prices/stock if provided
                    if ($unit_cost > 0 || $unit_price > 0) {
                        $pdo->prepare("UPDATE inventory_products SET unit_cost = COALESCE(NULLIF(?,0), unit_cost), unit_price = COALESCE(NULLIF(?,0), unit_price) WHERE id = ?")
                            ->execute([$unit_cost, $unit_price, $new_product_id]);
                    }
                } else {
                    // Brand new product — insert into global catalog
                    $ip_stmt = $pdo->prepare("
                        INSERT INTO inventory_products
                            (product_name, category, unit_cost, unit_price, sku, size, stock, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ip_stmt->execute([$name, $category, $unit_cost, $unit_price, $sku, $size, $stock_level]);
                    $new_product_id = (int)$pdo->lastInsertId();
                }

                // Create station_inventory row for this station
                $si_stmt = $pdo->prepare("
                    INSERT INTO station_inventory
                        (station_id, product_id, stock_level, cost, price, unit, status, last_updated)
                    VALUES (?, ?, ?, ?, ?, 'pieces', 'active', NOW())
                ");
                $si_stmt->execute([$station_id, $new_product_id, $stock_level, $unit_cost, $unit_price]);


                $pdo->commit();
                $_SESSION['success'] = "Product '$name' added successfully to this station.";
                header('Location: product_management.php?tab=merchandise');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['error'] = 'Error adding product: ' . $e->getMessage();
                header('Location: product_management.php?tab=merchandise');
                exit;
            }
        }
    }
    
    if ($action === 'update_product') {
        $product_id = $_POST['product_id'] ?? 0;
        $name       = trim($_POST['product_name'] ?? '');
        $category   = $_POST['category']   ?? '';
        $unit_cost  = (float)($_POST['unit_cost']  ?? 0);
        $unit_price = (float)($_POST['unit_price'] ?? $unit_cost);
        $stock_level = (int)($_POST['stock_level'] ?? 0);
        
        try {
            // Validate product category matches current tab
            $stmt = $pdo->prepare("SELECT category FROM inventory_products WHERE id = ? AND station_id = ?");
            $stmt->execute([$product_id, $station_id]);
            $product_category = $stmt->fetchColumn();
            
            if ($active_tab === 'fuel' && $product_category !== 'Fuel') {
                $_SESSION['error'] = 'Cannot edit merchandise product from Fuel tab';
                header('Location: product_management.php?tab=' . $active_tab);
                exit;
            }
            
            if ($active_tab === 'merchandise' && $product_category === 'Fuel') {
                $_SESSION['error'] = 'Cannot edit fuel product from Merchandise tab';
                header('Location: product_management.php?tab=' . $active_tab);
                exit;
            }
            $stmt = $pdo->prepare("
                UPDATE inventory_products
                SET product_name = ?, category = ?, unit_cost = ?, unit_price = ?, stock = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $category, $unit_cost, $unit_price, $stock_level, $product_id]);
            
            
            $_SESSION['success'] = "Product '$name' updated successfully.";
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating product: ' . $e->getMessage();
        }
    }
    
    if ($action === 'toggle_status') {
        $product_id = (int)($_POST['product_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'active';
        $new_status = in_array($new_status, ['active','inactive']) ? $new_status : 'active';

        try {
            // Toggle status in station_inventory (inventory_products has no status column)
            $stmt = $pdo->prepare("UPDATE station_inventory SET status = ?, last_updated = NOW() WHERE station_id = ? AND product_id = ?");
            $stmt->execute([$new_status, $station_id, $product_id]);

            $stmt = $pdo->prepare("SELECT product_name FROM inventory_products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product_name = $stmt->fetchColumn() ?: "Product #$product_id";


            $_SESSION['success'] = "Product '$product_name' status updated to $new_status.";
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating product status: ' . $e->getMessage();
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        }
    }
    
    // Fuel Inventory Actions
    if ($action === 'update_fuel_product') {
        $fuel_id         = (int)($_POST['fuel_id'] ?? 0);
        $price_per_liter = (float)($_POST['price_per_liter'] ?? 0);
        $status          = $_POST['status'] ?? 'Normal';

        try {
            // ── 1. Update fuel_inventory (primary / authoritative price source) ──
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET price_per_liter = ?, status = ?, last_updated = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$price_per_liter, $status, $me['id'], $fuel_id]);

            // ── 2. Fetch the fuel_type name & fuel_type_id for downstream sync ──
            $stmt = $pdo->prepare("SELECT fuel_type, fuel_type_id, station_id FROM fuel_inventory WHERE id = ?");
            $stmt->execute([$fuel_id]);
            $fi_row       = $stmt->fetch(PDO::FETCH_ASSOC);
            $fuel_type    = $fi_row['fuel_type']    ?? '';
            $fuel_type_id = $fi_row['fuel_type_id'] ?? null;
            $fi_station   = $fi_row['station_id']   ?? null;

            // ── 3. Sync to fuel_types.price_per_liter (so meter reading display stays consistent) ──
            if ($fuel_type_id) {
                $pdo->prepare("UPDATE fuel_types SET price_per_liter = ? WHERE id = ?")
                    ->execute([$price_per_liter, $fuel_type_id]);
            }

            // ── 4. Sync to fuel_pricing (used by Inventory / Reports pages) ──
            if ($fuel_type_id && $fi_station) {
                // Check if an active row exists for this station + fuel_type
                $fp_stmt = $pdo->prepare("SELECT id FROM fuel_pricing WHERE station_id = ? AND fuel_type_id = ? AND is_active = 1 LIMIT 1");
                $fp_stmt->execute([$fi_station, $fuel_type_id]);
                $fp_id = $fp_stmt->fetchColumn();

                if ($fp_id) {
                    // Update existing active pricing row
                    $pdo->prepare("UPDATE fuel_pricing SET price_per_liter = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$price_per_liter, $fp_id]);
                } else {
                    // Insert new pricing row
                    $pdo->prepare("INSERT INTO fuel_pricing (station_id, fuel_type_id, price_per_liter, effective_date, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, NOW(), 1, ?, NOW(), NOW())")
                        ->execute([$fi_station, $fuel_type_id, $price_per_liter, $me['id']]);
                }
            }

            // ── 5. Log the price change to fuel_price_log ──
            try {
                $pdo->prepare("INSERT INTO fuel_price_log (station_id, fuel_type_id, fuel_type, old_price, new_price, price_difference, change_type, reason_for_change, changed_by, changed_by_name, change_timestamp) SELECT ?, ?, ?, price_per_liter, ?, ? - price_per_liter, 'manual_update', 'Updated via Product Management', ?, ?, NOW() FROM fuel_inventory WHERE id = ?")
                    ->execute([$fi_station, $fuel_type_id, $fuel_type, $price_per_liter, $price_per_liter, $me['id'], ($me['name'] ?? $me['username'] ?? ''), $fuel_id]);
            } catch (Exception $logEx) { /* non-critical */ }

            $_SESSION['success'] = 'Fuel price updated successfully. Inventory and all pricing tables synced.';
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating fuel price: ' . $e->getMessage();
        }
    }
    
    if ($action === 'toggle_fuel_status') {
        $fuel_id = $_POST['fuel_id'] ?? 0;
        $raw_status = $_POST['status'] ?? 'Normal';

        // Map active/inactive to fuel_inventory status values (enum: 'Normal', 'Low Stock')
        if ($raw_status === 'active') {
            $status = 'Normal';
        } elseif ($raw_status === 'inactive' || $raw_status === 'Inactive') {
            $status = 'Low Stock';
        } else {
            // Only allow valid enum values
            $status = in_array($raw_status, ['Normal', 'Low Stock']) ? $raw_status : 'Normal';
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE fuel_inventory SET status = ?, last_updated = NOW(), updated_by = ? WHERE id = ?");
            $stmt->execute([$status, $me['id'], $fuel_id]);
            
            // Get fuel type for logging
            $stmt = $pdo->prepare("SELECT fuel_type FROM fuel_inventory WHERE id = ?");
            $stmt->execute([$fuel_id]);
            $fuel_type = $stmt->fetchColumn();
            
            
            $_SESSION['success'] = 'Fuel status updated successfully';
            header('Location: product_management.php?tab=' . $active_tab);
            exit;
        } catch (Exception $e) {
            $_SESSION['error'] = 'Error updating fuel status: ' . $e->getMessage();
        }
    }
}

// Get products from database
$products = [];
$fuel_inventory_products_only = [];
$fuel_inventory_product_count = 0;

// Define required fuel types outside try-catch to ensure it's always available
$required_fuel_types = ['Diesel', 'Kerosene', 'Turbo Diesel', 'XCS Plus', 'XTRA UNL'];

// Initialize arrays before try-catch so they are always defined
$merchandise_products = [];
$fuel_inventory_rows  = [];

try {
    // Get merchandise products for THIS station via station_inventory join.
    // LEFT JOIN so stations with no station_inventory records still see the global catalog.
    $stmt = $pdo->prepare("
        SELECT
            ip.id,
            ip.product_name,
            COALESCE(ip.category, 'General')                    AS category,
            COALESCE(ip.sku, '')                                AS sku,
            COALESCE(ip.size, '')                               AS size,
            COALESCE(si.cost,  ip.unit_cost,  0)                AS unit_cost,
            COALESCE(si.price, ip.unit_price, ip.unit_cost, 0)  AS unit_price,
            COALESCE(si.stock_level, ip.stock, 0)               AS quantity,
            COALESCE(si.status, 'active')                       AS status,
            COALESCE(si.reorder_level, 24)                      AS reorder_level,
            COALESCE(si.critical_level, 10)                     AS critical_level,
            CASE WHEN si.id IS NOT NULL THEN 1 ELSE 0 END       AS in_station
        FROM inventory_products ip
        LEFT JOIN station_inventory si
            ON si.product_id = ip.id
           AND si.station_id = ?
        WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
        ORDER BY ip.category, ip.product_name
    ");
    $stmt->execute([$station_id]);
    $merchandise_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($merchandise_products as &$product) {
        $product['supplier']   = 'Petron Corporation';
        $product['unit_cost']  = (float)$product['unit_cost'];
        $product['unit_price'] = (float)$product['unit_price'];
        $product['source']     = 'inventory_products';
    }
    unset($product);

    // Fetch ALL fuel inventory records for this station (not just hardcoded types)
    try {
        $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
        $stmt->execute([$station_id]);
        $fuel_inventory_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $fuel_inventory_rows = [];
    }

} catch (Exception $e) {
    $_SESSION['error'] = 'Error loading products: ' . $e->getMessage();
    try {
        $stmt = $pdo->prepare("SELECT * FROM fuel_inventory WHERE station_id = ? ORDER BY fuel_type");
        $stmt->execute([$station_id]);
        $fuel_inventory_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $fe) { $fuel_inventory_rows = []; }
    try {
        $stmt = $pdo->prepare("
            SELECT ip.id, ip.product_name, COALESCE(ip.category,'General') AS category,
                   COALESCE(ip.sku,'') AS sku, COALESCE(ip.size,'') AS size,
                   COALESCE(si.cost, ip.unit_cost, 0) AS unit_cost,
                   COALESCE(si.price, ip.unit_price, ip.unit_cost, 0) AS unit_price,
                   COALESCE(si.stock_level, ip.stock, 0) AS quantity,
                   COALESCE(si.status,'active') AS status,
                   COALESCE(si.reorder_level, 24)  AS reorder_level,
                   COALESCE(si.critical_level, 10) AS critical_level,
                   CASE WHEN si.id IS NOT NULL THEN 1 ELSE 0 END AS in_station
            FROM inventory_products ip
            LEFT JOIN station_inventory si ON si.product_id = ip.id AND si.station_id = ?
            WHERE LOWER(COALESCE(ip.category,'')) NOT IN ('fuel', 'fuel products')
            ORDER BY ip.category, ip.product_name
        ");
        $stmt->execute([$station_id]);
        $merchandise_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($merchandise_products as &$product) {
            $product['supplier']   = 'Petron Corporation';
            $product['unit_cost']  = (float)$product['unit_cost'];
            $product['unit_price'] = (float)$product['unit_price'];
            $product['source']     = 'inventory_products';
        }
        unset($product);
    } catch (Exception $pe) {}
}

// Build fuel_products from ALL fuel_inventory rows for this station
$fuel_inventory_products = [];
if (!empty($fuel_inventory_rows)) {
    foreach ($fuel_inventory_rows as $row) {
        $fuel_inventory_products[] = [
            'id'             => $row['id'],
            'product_name'   => $row['fuel_type'],
            'category'       => 'Fuel',
            'unit_cost'      => (float)($row['price_per_liter'] ?? 0),
            'unit_price'     => (float)($row['price_per_liter'] ?? 0),
            'sku'            => null,
            'quantity'       => (float)($row['current_level'] ?? 0),
            'supplier'       => 'Petron Corporation',
            'status'         => strtolower($row['status'] ?? 'normal') === 'normal' ? 'active' : 'inactive',
            'display_status' => $row['status'] ?? 'Normal',
            'source'         => 'fuel_inventory',
        ];
    }
}

// Combine fuel and merchandise products separately
$fuel_products = $fuel_inventory_products ?? [];
$merchandise_products = $merchandise_products ?? [];

// For display purposes, we'll use separate arrays
// Fuel tab will use $fuel_products array
// Merchandise tab will use $merchandise_products array

// Set counts for tab navigation
$fuel_inventory_product_count = count($fuel_products);
$merchandise_product_count = count($merchandise_products);

// ── Pre-load merchandise batches (grouped by product_id) ─────────────────────
$merch_batches_by_product = [];
try {
    $bStmt = $pdo->prepare("
        SELECT mb.*, u.name AS encoded_by_name
        FROM merchandise_batches mb
        LEFT JOIN users u ON mb.encoded_by = u.id
        WHERE mb.station_id = ? AND LOWER(COALESCE(mb.status, 'active')) NOT IN ('cancelled', 'disabled')
        ORDER BY mb.date_received ASC, mb.id ASC
    ");
    $bStmt->execute([$station_id]);
    foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $merch_batches_by_product[(int)$b['product_id']][] = $b;
    }
} catch (Exception $e) {}

// Read and clear flash messages BEFORE header include so they render in page body
$flash_success = $_SESSION['success'] ?? ''; unset($_SESSION['success']);
$flash_error   = $_SESSION['error']   ?? ''; unset($_SESSION['error']);

include __DIR__ . '/../partials/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="h1">Product Management</h1>
        <div class="sub">Inventory oversight • Product catalog • Stock management</div>
    </div>
    <div class="header-actions">
        <button onclick="location.reload()" class="btn ghost"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
</div>

<?php if ($flash_success): ?>
<div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#dcfce7;border:1px solid #86efac;border-radius:10px;color:#166534;font-weight:600;margin-bottom:18px;">
    <i class="fas fa-check-circle" style="font-size:18px;"></i>
    <?= htmlspecialchars($flash_success) ?>
</div>
<?php endif; ?>
<?php if ($flash_error): ?>
<div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#fee2e2;border:1px solid #fca5a5;border-radius:10px;color:#991b1b;font-weight:600;margin-bottom:18px;">
    <i class="fas fa-exclamation-circle" style="font-size:18px;"></i>
    <?= htmlspecialchars($flash_error) ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-list text-primary"></i> Product List</h3>
        <div class="header-actions">
            <div class="tab-navigation">
                <?php if ($active_tab === 'fuel'): ?>
                    <button class="tab-btn active" onclick="showTab('fuel', this)">
Fuel Products
                        <span class="tab-count"><?php echo $fuel_inventory_product_count; ?></span>
                    </button>
                <?php elseif ($active_tab === 'merchandise'): ?>
                    <button class="tab-btn active" onclick="showTab('merchandise', this)">
                        <i class="fas fa-shopping-cart"></i> Merchandise Products
                        <span class="tab-count"><?php echo $merchandise_product_count; ?></span>
                    </button>
                <?php endif; ?>
            </div>
            <input type="text" id="productSearch" placeholder="Search products..." class="form-control" style="width:200px;">
            <button onclick="openAddProductModal()" class="btn primary" style="margin-left: 10px;">
                <i class="fas fa-plus"></i>
                <?php echo $active_tab === 'fuel' ? 'Add Fuel Type' : 'Add Product'; ?>
            </button>
        </div>
    </div>
    <div class="card-body">
        <?php if ($active_tab === 'fuel'): ?>
            <!-- Fuel Products Tab -->
            <div id="fuel-tab" class="tab-content active">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product ID</th>
                                <th>UGT No.</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Unit Price</th>
                                <th>Stock Level</th>
                                <th>Supplier</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Use all fuel products from this station's fuel_inventory

                            foreach ($fuel_products as $product): ?>
                                <tr>
                                    <td><?php echo $product['id'] ? '#' . $product['id'] : 'N/A'; ?></td>
                                    <td style="font-family:monospace;font-weight:700;color:#002F70;"><?php echo !empty($product['ugt_no']) ? htmlspecialchars($product['ugt_no']) : ('UGT #' . ((int)($product['id'] ?? 1))); ?></td>
                                    <td><?php echo htmlspecialchars($product['product_name']); ?></td>
                                    <td>
                                        <span class="badge">
                                            <?php echo htmlspecialchars($product['category']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($product['unit_cost'], 2); ?></td>
                                    <td>
                                        <span style="<?php 
                                        // Use appropriate stock level thresholds for fuel (in liters)
                                        $stockLevel = $product['quantity'];
                                        $stockColor = '#28a745'; // Green for normal
                                        if ($stockLevel <= 500) {
                                            $stockColor = '#dc3545'; // Red for critical
                                        } elseif ($stockLevel <= 2000) {
                                            $stockColor = '#fd7e14'; // Orange for low
                                        }
                                        echo 'color:' . $stockColor . ';font-weight:700;';
                                        ?>">
                                            <?php echo number_format($stockLevel, 2); ?> L
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['supplier'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php
                                            $displayStatus = $product['display_status'] ?? ucfirst($product['status']);
                                            $isActive = strtolower($product['status']) === 'active' || strtolower($displayStatus) === 'normal';
                                            $statusColor = $isActive ? '#28a745' : '#dc3545';
                                            if (stripos($displayStatus, 'low') !== false) {
                                                $statusColor = '#fd7e14';
                                            } elseif (stripos($displayStatus, 'critical') !== false) {
                                                $statusColor = '#dc3545';
                                            }
                                        ?>
                                        <span class="badge" style="color:<?php echo $statusColor; ?>;font-weight:700;">
                                            <?php echo htmlspecialchars($displayStatus); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (($product['source'] ?? '') === 'fuel_inventory'): ?>
                                            <?php if ($product['id']): // Only show actions if we have a real ID
                                                $isNormal = strtolower($product['display_status'] ?? '') === 'normal';
                                                $toggleTarget = $isNormal ? 'inactive' : 'active';
                                                $toggleLabel  = $isNormal ? 'Deactivate' : 'Activate';
                                                $toggleClass  = $isNormal ? 'btn-danger' : 'btn-success';
                                                $toggleIcon   = $isNormal ? 'fa-times' : 'fa-check';
                                            ?>
                                                <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                                                    <button class="btn btn-sm" style="width:100%;background:#28a745;color:white;border:none;" onclick="viewFuelProduct(<?php echo $product['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button class="btn btn-sm" style="width:100%;background:#002F70;color:white;border:none;" onclick="editFuelProduct(<?php echo $product['id']; ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-sm <?php echo $toggleClass; ?>" style="width:100%;"
                                                            onclick="toggleFuelProductStatus(<?php echo $product['id']; ?>, '<?php echo $toggleTarget; ?>')">
                                                        <i class="fas <?php echo $toggleIcon; ?>"></i> 
                                                        <?php echo $toggleLabel; ?>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="badge" style="color:#6c757d;font-weight:700;">Missing Data</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;">
                                                <button class="btn btn-sm" style="width:100%;background:#28a745;color:white;border:none;" onclick="viewProduct(<?php echo $product['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <button class="btn btn-sm" style="width:100%;background:#002F70;color:white;border:none;" onclick="editProduct(<?php echo $product['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm <?php echo $product['status'] == 'active' ? 'btn-danger' : 'btn-success'; ?>" style="width:100%;"
                                                    onclick="toggleProductStatus(<?php echo $product['id']; ?>, '<?php echo $product['status'] == 'active' ? 'inactive' : 'active'; ?>')">
                                                    <i class="fas <?php echo $product['status'] == 'active' ? 'fa-times' : 'fa-check'; ?>"></i> 
                                                    <?php echo $product['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($fuel_products)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px;">
                                        <div style="color: #666;">No fuel products found.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($active_tab === 'merchandise'): ?>
            <!-- Merchandise Products Tab -->
            <div id="merchandise-tab" class="tab-content active">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>SKU / Code</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Brand / Supplier</th>
                                <th>UOM</th>
                                <th>Default Selling Price</th>
                                <th>Total Stock</th>
                                <th>Status</th>
                                <th style="text-align:center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            foreach ($merchandise_products as $product):
                                $cost    = (float)($product['unit_cost']  ?? 0);
                                $price   = (float)($product['unit_price'] ?? $cost);
                                $qty     = (int)($product['quantity'] ?? 0);
                                $reorder = (int)($product['reorder_level']  ?? 24);
                                $critical= (int)($product['critical_level'] ?? 10);
                                if ($qty <= 0)        $qcolor = '#dc3545';
                                elseif ($qty <= $critical) $qcolor = '#dc3545';
                                elseif ($qty <= $reorder)  $qcolor = '#ff9500';
                                else                  $qcolor = '#28a745';
                                $pid     = (int)$product['id'];
                                $batches = $merch_batches_by_product[$pid] ?? [];
                                $batch_count = count($batches);
                                $prod_sku = trim($product['sku'] ?? '');
                                if (empty($prod_sku) || $prod_sku === '-') {
                                    $prod_sku = 'P' . str_pad((string)$pid, 4, '0', STR_PAD_LEFT);
                                }
                                $uom = htmlspecialchars($product['unit'] ?? 'pcs');
                                $brand = htmlspecialchars($product['brand'] ?? $product['supplier'] ?? 'Petron Corporation');
                            ?>
                                <tr>
                                    <td style="font-family:monospace;font-weight:700;color:#002F70;font-size:13px;"><?php echo htmlspecialchars($prod_sku); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge">
                                            <?php echo htmlspecialchars($product['category']); ?>
                                        </span>
                                    </td>
                                    <td style="color:#475569;font-size:13px;"><?php echo $brand; ?></td>
                                    <td style="font-weight:600;color:#64748b;"><?php echo $uom; ?></td>
                                    <td style="color:#28a745;font-weight:700;">
                                        ₱<?php echo number_format($price, 2); ?>
                                    </td>
                                    <td>
                                        <strong style="color:<?php echo $qcolor; ?>;font-size:14px;">
                                            <?php echo number_format($qty); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="badge" style="color:<?php echo $product['status'] == 'active' ? '#28a745' : '#dc3545'; ?>;font-weight:700;">
                                            <?php echo $product['status'] == 'active' ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                                            <button class="btn btn-sm" style="background:#002F70;color:white;border:none;" onclick="editProduct(<?php echo $pid; ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm <?php echo $product['status'] == 'active' ? 'btn-danger' : 'btn-success'; ?>"
                                                    onclick="toggleProductStatus(<?php echo $pid; ?>, '<?php echo $product['status'] == 'active' ? 'inactive' : 'active'; ?>')">
                                                <i class="fas <?php echo $product['status'] == 'active' ? 'fa-ban' : 'fa-check'; ?>"></i>
                                                <?php echo $product['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                            <button class="btn btn-sm" style="background:#6b21a8;color:white;border:none;" onclick="toggleBatches(<?= $pid ?>)">
                                                <i class="fas fa-boxes"></i> View Batches (<?= $batch_count ?>)
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php if ($batch_count > 0): ?>
                                <tr id="batch-rows-<?= $pid ?>" style="display:none;">
                                    <td colspan="10" style="padding:0;background:#f8faff;border-left:4px solid #002F70;">
                                        <table style="width:100%;border-collapse:collapse;font-size:12px;">
                                            <thead>
                                                <tr style="background:#e8f4fd;">
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;text-align:left;">Batch No</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;text-align:right;">Rcvd Qty</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;text-align:right;">Remaining</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;text-align:right;">Unit Cost</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;text-align:right;">Selling Price</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;">Supplier</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;">Date Received</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;">Encoded By</th>
                                                    <th style="padding:7px 12px;color:#002F70;font-weight:700;">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($batches as $i => $b):
                                                    $is_fifo_next = ($i === 0 && $b['status'] === 'active');
                                                    $rem = (int)$b['remaining_qty'];
                                                    $bSelling = (float)($b['selling_price'] ?? 0);
                                                ?>
                                                <tr style="<?= $is_fifo_next ? 'background:#fff9e6;' : '' ?>">
                                                    <td style="padding:6px 12px;">
                                                        <span style="font-family:monospace;font-weight:700;color:#002F70;background:#e8f4fd;padding:2px 8px;border-radius:4px;">
                                                            <?= htmlspecialchars($b['batch_number'] ?? ('B' . str_pad((string)$b['id'], 4, '0', STR_PAD_LEFT))) ?>
                                                        </span>
                                                        <?php if ($is_fifo_next): ?>
                                                        <span style="font-size:10px;background:#fd7e14;color:#fff;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:700;">NEXT FIFO</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td style="padding:6px 12px;text-align:right;"><?= number_format((int)$b['quantity_received']) ?></td>
                                                    <td style="padding:6px 12px;text-align:right;font-weight:700;color:<?= $rem <= 0 ? '#dc3545' : '#28a745' ?>;"><?= number_format($rem) ?></td>
                                                    <td style="padding:6px 12px;text-align:right;">₱<?= number_format((float)$b['unit_cost'], 2) ?></td>
                                                    <td style="padding:6px 12px;text-align:right;font-weight:700;color:#28a745;">₱<?= number_format($bSelling, 2) ?></td>
                                                    <td style="padding:6px 12px;"><?= htmlspecialchars($b['supplier'] ?? '—') ?></td>
                                                    <td style="padding:6px 12px;white-space:nowrap;"><?= $b['date_received'] ? date('M d, Y', strtotime($b['date_received'])) : '—' ?></td>
                                                    <td style="padding:6px 12px;"><?= htmlspecialchars($b['encoded_by_name'] ?? '—') ?></td>
                                                    <td style="padding:6px 12px;">
                                                        <span style="color:<?= $b['status'] === 'active' ? '#28a745' : '#6c757d' ?>;font-weight:700;text-transform:uppercase;font-size:10px;"><?= htmlspecialchars($b['status']) ?></span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($merchandise_products)): ?>
                                <tr><td colspan="10" style="text-align:center;padding:40px;color:#666;">No merchandise products found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
    <div class="modal-content" style="max-width:560px;">
        <div class="modal-header">
            <h3>
                <?php echo $active_tab === 'fuel' ? 'Add New Fuel Type' : 'Add New Merchandise Product'; ?>
            </h3>
            <button class="close" onclick="closeAddProductModal()">&times;</button>
        </div>

        <form method="POST" action="product_management.php?tab=<?php echo $active_tab; ?>">
            <input type="hidden" name="action" value="add_product">
            <input type="hidden" name="_tab"   value="<?php echo $active_tab; ?>">

            <div class="modal-body">

                <?php if ($active_tab === 'fuel'): ?>
                <!-- ── FUEL FIELDS ─────────────────────────────────── -->

                <div class="form-group">
                    <label>Fuel Type Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" class="form-control" required
                           placeholder="e.g. Diesel, XCS, Turbo Diesel">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Price per Liter (₱) <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="unit_price" class="form-control" step="0.01" min="0" required placeholder="0.00">
                        <small style="color:#6c757d;font-size:11px;">Selling price per liter</small>
                    </div>
                    <div class="form-group">
                        <label>Cost per Liter (₱)</label>
                        <input type="number" name="unit_cost" class="form-control" step="0.01" min="0" placeholder="0.00">
                        <small style="color:#6c757d;font-size:11px;">Acquisition cost (optional)</small>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Current Level (Liters)</label>
                        <input type="number" name="current_level" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Tank Capacity (Liters)</label>
                        <input type="number" name="capacity" class="form-control" step="0.01" min="0" value="25000" placeholder="25000">
                    </div>
                </div>

                <div class="form-group">
                    <label>Category</label>
                    <input type="text" class="form-control" value="Fuel" readonly
                           style="background:#f8f9fa;color:#495057;cursor:not-allowed;">
                </div>

                <?php else: ?>
                <!-- ── MERCHANDISE FIELDS ──────────────────────────── -->

                <div class="form-group">
                    <label>Product Name <span style="color:#dc3545;">*</span></label>
                    <input type="text" name="product_name" class="form-control" required
                           placeholder="e.g. Castrol GTX 10W-40 1L">
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Category <span style="color:#dc3545;">*</span></label>
                        <input type="text" name="category" class="form-control" required
                               placeholder="e.g. Oils, Tires, Accessories">
                    </div>
                    <div class="form-group">
                        <label>Size / Variant</label>
                        <input type="text" name="size" class="form-control"
                               placeholder="e.g. 1L, 4L, 195/65R15">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Cost Price (₱) <span style="color:#dc3545;">*</span>
                            <small style="font-weight:400;color:#6c757d;">— purchase cost</small>
                        </label>
                        <input type="number" name="unit_cost" id="addUnitCost" class="form-control"
                               step="0.01" min="0" required placeholder="0.00"
                               oninput="updateAddProfitPreview()">
                    </div>
                    <div class="form-group">
                        <label>Selling Price (₱) <span style="color:#dc3545;">*</span>
                            <small style="font-weight:400;color:#6c757d;">— charged to customer</small>
                        </label>
                        <input type="number" name="unit_price" id="addUnitPrice" class="form-control"
                               step="0.01" min="0" required placeholder="0.00"
                               oninput="updateAddProfitPreview()">
                    </div>
                </div>
                <div id="addProfitPreview" style="font-size:12px;color:#002F70;margin:-10px 0 14px;min-height:16px;"></div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div class="form-group">
                        <label>Initial Stock Level <span style="color:#dc3545;">*</span></label>
                        <input type="number" name="stock_level" class="form-control" min="0" value="0" required>
                    </div>
                    <div class="form-group">
                        <label>SKU / Barcode</label>
                        <input type="text" name="sku" class="form-control" placeholder="Auto-generated if blank">
                    </div>
                </div>

                <div class="form-group">
                    <label>Supplier</label>
                    <input type="text" name="supplier" class="form-control"
                           placeholder="e.g. Petron Corporation" value="Petron Corporation">
                </div>
                <?php endif; ?>

            </div><!-- /modal-body -->

            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeAddProductModal()">Cancel</button>
                <button type="submit" class="btn primary">
                    <i class="fas fa-plus"></i>
                    <?php echo $active_tab === 'fuel' ? 'Add Fuel Type' : 'Add Product'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit text-warning"></i> Edit Product</h3>
            <button class="close" onclick="closeEditProductModal()">&times;</button>
        </div>
        <form method="POST" action="product_management.php?tab=<?php echo $active_tab; ?>">
            <input type="hidden" name="action" value="update_product">
            <input type="hidden" name="_tab" value="<?php echo $active_tab; ?>">
            <input type="hidden" name="product_id" id="editProductId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" name="product_name" id="editProductName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <input type="text" name="category" id="editCategory" class="form-control" readonly
                           style="background:#f8f9fa;color:#495057;cursor:not-allowed;">
                    <small class="text-muted" style="font-size:11px;color:#6c757d;">Category is auto-filled from the product record</small>
                </div>
                <div class="form-group">
                    <label>Cost Price (unit_cost) — Purchase / Acquisition Cost</label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#6c757d;">₱</span>
                        <input type="number" name="unit_cost" id="editUnitCost" class="form-control" step="0.01" min="0" required placeholder="0.00">
                    </div>
                </div>
                <div class="form-group">
                    <label>Selling Price (unit_price) — Price Charged to Customer</label>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-weight:600;color:#28a745;">₱</span>
                        <input type="number" name="unit_price" id="editUnitPrice" class="form-control" step="0.01" min="0" required placeholder="0.00">
                    </div>
                    <small id="editProfitPreview" style="color:#002F70;font-size:12px;margin-top:4px;display:block;"></small>
                </div>
                <div class="form-group">
                    <label>Stock Level</label>
                    <input type="number" name="stock_level" id="editStockLevel" class="form-control" min="0" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeEditProductModal()">Cancel</button>
                <button type="submit" class="btn primary">Update Product</button>
            </div>
        </form>
    </div>
</div>

<!-- View Product Modal -->
<div id="viewProductModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-eye" style="color:#002F70;"></i> Product Details</h3>
            <button class="close" onclick="closeViewProductModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="product-detail-grid">
                <div class="detail-item"><label>Product ID</label><div id="viewProductId" class="detail-value"></div></div>
                <div class="detail-item"><label>Product Name</label><div id="viewProductName" class="detail-value"></div></div>
                <div class="detail-item"><label>Category</label><div id="viewCategory" class="detail-value"></div></div>
                <div class="detail-item"><label>Cost Price (unit_cost)</label><div id="viewUnitCost" class="detail-value" style="color:#6c757d;"></div></div>
                <div class="detail-item"><label>Selling Price (unit_price)</label><div id="viewUnitPrice" class="detail-value" style="color:#28a745;font-weight:700;"></div></div>
                <div class="detail-item"><label>Profit Margin</label><div id="viewProfit" class="detail-value" style="color:#002F70;"></div></div>
                <div class="detail-item"><label>Stock Level</label><div id="viewStockLevel" class="detail-value"></div></div>
                <div class="detail-item"><label>Status</label><div id="viewStatus" class="detail-value"></div></div>
                <div class="detail-item"><label>Created At</label><div id="viewCreatedAt" class="detail-value"></div></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn ghost" onclick="closeViewProductModal()">Close</button>
        </div>
    </div>
</div>

<!-- Toggle Status Form -->
<form id="toggleStatusForm" method="POST" action="product_management.php?tab=<?php echo $active_tab; ?>" style="display: none;">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="_tab" value="<?php echo $active_tab; ?>">
    <input type="hidden" name="product_id" id="toggleProductId">
    <input type="hidden" name="status" id="toggleStatus">
</form>

<!-- View Fuel Product Modal -->
<div id="viewFuelProductModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Fuel Inventory Details</h3>
            <button class="close" onclick="closeViewFuelProductModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="product-detail-grid">
                <div class="detail-item">
                    <label>Fuel ID</label>
                    <div id="viewFuelId" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Fuel Type</label>
                    <div id="viewFuelType" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Price per Liter</label>
                    <div id="viewFuelPrice" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Current Level</label>
                    <div id="viewFuelLevel" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Tank Capacity</label>
                    <div id="viewFuelCapacity" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Status</label>
                    <div id="viewFuelStatus" class="detail-value"></div>
                </div>
                <div class="detail-item">
                    <label>Last Updated</label>
                    <div id="viewFuelUpdated" class="detail-value"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn ghost" onclick="closeViewFuelProductModal()">Close</button>
        </div>
    </div>
</div>

<!-- Edit Fuel Product Modal -->
<div id="editFuelProductModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit text-warning"></i> Edit Fuel Inventory</h3>
            <button class="close" onclick="closeEditFuelProductModal()">&times;</button>
        </div>
        <form method="POST" action="product_management.php?tab=<?php echo $active_tab; ?>">
            <input type="hidden" name="action" value="update_fuel_product">
            <input type="hidden" name="_tab" value="<?php echo $active_tab; ?>">
            <input type="hidden" name="fuel_id" id="editFuelId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Fuel Type</label>
                    <input type="text" id="editFuelType" class="form-control" readonly>
                    <small class="text-muted">Fuel type cannot be changed</small>
                </div>
                <div class="form-group">
                    <label>Price per Liter (₱)</label>
                    <input type="number" name="price_per_liter" id="editFuelPrice" class="form-control" step="0.01" min="0" required>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="editFuelStatus" class="form-control" required>
                        <option value="Normal">Normal</option>
                        <option value="Low Stock">Low Stock</option>
                        
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Current Level (Liters)</label>
                    <input type="number" id="editFuelLevel" class="form-control" readonly>
                    <small class="text-muted">Current level is managed through fuel deliveries and sales</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ghost" onclick="closeEditFuelProductModal()">Cancel</button>
                <button type="submit" class="btn primary">Update Fuel</button>
            </div>
        </form>
    </div>
</div>

<!-- Toggle Fuel Status Form -->
<form id="toggleFuelStatusForm" method="POST" action="product_management.php?tab=<?php echo $active_tab; ?>" style="display: none;">
    <input type="hidden" name="action" value="toggle_fuel_status">
    <input type="hidden" name="_tab" value="<?php echo $active_tab; ?>">
    <input type="hidden" name="fuel_id" id="toggleFuelId">
    <input type="hidden" name="status" id="toggleFuelStatus">
</form>

<style>
/* === Clean Product Management Design === */
body{overflow-x:hidden !important;max-width:100vw !important;}

/* Table styles */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef;}
.table{width:100%;border-collapse:collapse;font-size:0.875rem;}
.table th{background:#002F70 !important;color:#fff !important;padding:14px 16px;text-align:left;font-weight:600;text-transform:uppercase;letter-spacing:0.3px;border:none !important;}
.table td{padding:12px 16px;border-bottom:1px solid #e9ecef;vertical-align:middle;color:#212529;}
.table tbody tr:hover td{background:#e3f2fd;}
.table tbody tr:last-child td{border-bottom:none;}

/* Plain text badges - NO backgrounds */
.badge{color:#6c757d !important;font-weight:700 !important;font-size:0.813rem !important;background:none !important;padding:0 !important;border:none !important;}

/* Tab Navigation */
.tab-navigation{display:flex;gap:8px;margin-right:20px;}
.tab-btn{display:flex;align-items:center;gap:8px;padding:10px 18px;background:#f8f9fa;border:none;border-bottom:3px solid transparent;font-size:14px;font-weight:600;color:#475569 !important;cursor:pointer;transition:all 0.2s;margin-bottom:-2px;}
.tab-btn:hover{color:#002F70 !important;background:rgba(0,47,108,0.1);}
.tab-btn.active{background:#002F70 !important;color:#ffffff !important;border-bottom-color:#002F70;font-weight:800;}
.tab-btn.active i{color:#ffffff !important;}
.tab-btn i{font-size:14px;}
.tab-count{background:rgba(0,47,108,0.1);color:#002F70;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;}
.tab-btn.active .tab-count{background:rgba(0,47,108,0.15);}
.tab-content{display:none;}
.tab-content.active{display:block;}

/* Buttons */
.btn{padding:8px 16px;border:none;border-radius:6px;font-size:0.813rem;font-weight:600;cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
.btn-sm{padding:6px 12px;font-size:0.75rem;min-width:85px;justify-content:center;}
.btn.primary{background:#002F70;color:#fff;}.btn.primary:hover{background:#001a4d;}
.btn.ghost{background:#f8f9fa;color:#495057;border:1px solid #dee2e6;}.btn.ghost:hover{background:#e9ecef;}
.btn-success{background:#28a745;color:#fff;}.btn-success:hover{background:#218838;}
.btn-danger{background:#dc3545;color:#fff;}.btn-danger:hover{background:#c82333;}
.btn-warning{background:#002F70;color:#fff;}.btn-warning:hover{background:#001a4d;}
.btn-info{background:#002F70;color:#fff;}.btn-info:hover{background:#001a4d;}

/* Modal */
.modal{display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);align-items:center;justify-content:center;}
.modal.open{display:flex;}
.modal-content{background-color:#fff;margin:0;padding:0;border-radius:12px;width:90%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.25);}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid #e9ecef;}
.modal-header h3{margin:0;font-size:18px;font-weight:600;color:#002F70;}
.close{color:#aaa;font-size:28px;font-weight:bold;cursor:pointer;background:none;border:none;}
.close:hover{color:#000;}
.modal-body{padding:24px;}
.form-group{margin-bottom:20px;}
.form-group label{display:block;margin-bottom:8px;font-weight:600;color:#495057;font-size:0.813rem;text-transform:uppercase;letter-spacing:0.3px;}
.form-control{width:100%;padding:10px 12px;border:1px solid #dee2e6;border-radius:6px;font-size:14px;box-sizing:border-box;}
.form-control:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,108,0.1);}
.modal-footer{display:flex;justify-content:flex-end;gap:12px;padding:20px 24px;border-top:1px solid #e9ecef;}
.product-detail-grid{display:grid;grid-template-columns:1fr;gap:16px;}
.detail-item{display:flex;flex-direction:column;}
.detail-item label{font-weight:600;color:#6c757d;margin-bottom:4px;font-size:0.75rem;text-transform:uppercase;}
.detail-value{padding:8px 12px;background:#f8f9fa;border-radius:6px;font-size:14px;color:#212529;}

@media (max-width:768px){.modal-content{width:95%;margin:10% auto;}}
</style>

<style>
/* === Product Management - Clean Table Design v2.0 === */
/* Last Updated: <?php echo date('Y-m-d H:i:s'); ?> */

body .card{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);border:1px solid #e9ecef;margin-bottom:20px;overflow:hidden;}
body .card-header{padding:16px 20px;border-bottom:1px solid #e9ecef;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
body .card-header h3{font-size:16px;font-weight:700;color:#002F70;margin:0;display:flex;align-items:center;gap:8px;}
body .card-body{padding:20px;overflow-x:hidden;}
body .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
body .table{width:100%;border-collapse:collapse;font-size:0.875rem;background:#fff;}
body .table thead th{background:#002F70 !important;color:#fff !important;padding:14px 16px !important;text-align:left !important;font-weight:600 !important;text-transform:uppercase !important;letter-spacing:0.3px !important;border:none !important;}
body .table tbody td{padding:12px 16px !important;border-bottom:1px solid #e9ecef !important;vertical-align:middle !important;color:#212529 !important;background:#fff !important;}
body .table tbody tr:hover td{background:#e3f2fd !important;}
body .table tbody tr{transition:background 0.2s ease;}
body .badge{display:inline-block !important;padding:0 !important;margin:0 !important;background:transparent !important;border:none !important;font-size:12px !important;font-weight:600 !important;}
body .header-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
body .tab-navigation{display:flex;gap:8px;}
body .tab-btn{padding:8px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;color:#495057;transition:all 0.2s;}
body .tab-btn:hover{background:#e9ecef;}
body .tab-btn.active{background:#002F70 !important;color:#fff !important;border-color:#002F70 !important;}
body .tab-count{display:inline-block;margin-left:6px;padding:2px 6px;background:rgba(255,255,255,0.2);border-radius:10px;font-size:11px;}

/* Modal Styling */
.modal{display:none;position:fixed;z-index:10000;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,0.5);}
.modal.open{display:flex !important;align-items:center;justify-content:center;}
.modal-content{background-color:#fff;margin:auto;padding:0;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.15);width:90%;max-width:600px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;}
.modal-header{padding:20px;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;align-items:center;}
.modal-header h3{margin:0;font-size:18px;font-weight:700;color:#002F70;}
.modal-body{padding:20px;overflow-y:auto;flex:1;}
.modal-footer{padding:16px 20px;border-top:1px solid #e9ecef;display:flex;justify-content:flex-end;gap:10px;}
.close{color:#aaa;font-size:28px;font-weight:bold;cursor:pointer;border:none;background:none;padding:0;line-height:1;}
.close:hover,.close:focus{color:#000;}

/* Form Styling */
.form-group{margin-bottom:16px;}
.form-group label{display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#495057;}
.form-control{width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;font-size:14px;box-sizing:border-box;}
.form-control:focus{outline:none;border-color:#002F70;box-shadow:0 0 0 3px rgba(0,47,112,0.1);}

/* Product Detail Grid */
.product-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.detail-item{display:flex;flex-direction:column;gap:4px;}
.detail-item label{font-size:11px;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:0.5px;}
.detail-value{font-size:14px;color:#212529;font-weight:600;}

/* Button Styling */
.btn{padding:10px 16px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:6px;border:none;text-decoration:none;}
.btn.primary{background:#002F70;color:#fff;}
.btn.primary:hover{background:#001f4d;}
.btn.ghost{background:#f8f9fa;color:#495057;border:1px solid #dee2e6;}
.btn.ghost:hover{background:#e9ecef;}
.btn-sm{padding:6px 10px !important;font-size:12px !important;}
.btn-success{background:#28a745 !important;color:#fff !important;}
.btn-success:hover{background:#218838 !important;}
.btn-danger{background:#dc3545 !important;color:#fff !important;}
.btn-danger:hover{background:#c82333 !important;}
</style>

<script>
// Merchandise product data for editing/viewing
const products = <?php echo json_encode(array_values($merchandise_products ?? [])); ?>;

// Fuel inventory data for fuel-specific operations
const fuelInventoryData = <?php echo json_encode(array_values($fuel_products ?? [])); ?>;

function showTab(tabName, btn) {
    // Since we're now using single tab pages, redirect to the appropriate tab page
    window.location.href = 'product_management.php?tab=' + tabName;
}

function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function openAddProductModal() {
    openModal('addProductModal');
}

function closeAddProductModal() {
    closeModal('addProductModal');
}

function updateAddProfitPreview() {
    const cost  = parseFloat(document.getElementById('addUnitCost')?.value  || 0);
    const price = parseFloat(document.getElementById('addUnitPrice')?.value || 0);
    const el    = document.getElementById('addProfitPreview');
    if (!el) return;
    if (!cost && !price) { el.textContent = ''; return; }
    const profit = price - cost;
    el.textContent = profit >= 0
        ? `Profit margin: ₱${profit.toFixed(2)} per unit`
        : `<i class="fas fa-exclamation-triangle"></i> Selling below cost by ₱${Math.abs(profit).toFixed(2)}`;
    el.style.color = profit >= 0 ? '#002F70' : '#dc3545';
}

function editProduct(productId) {
    const product = products.find(p => p.id == productId);
    if (product) {
        document.getElementById('editProductId').value    = product.id;
        document.getElementById('editProductName').value  = product.product_name;
        document.getElementById('editCategory').value     = product.category;
        document.getElementById('editUnitCost').value     = parseFloat(product.unit_cost  || 0).toFixed(2);
        document.getElementById('editUnitPrice').value    = parseFloat(product.unit_price || product.unit_cost || 0).toFixed(2);
        document.getElementById('editStockLevel').value   = product.quantity;
        updateProfitPreview();
        openModal('editProductModal');
    }
}

function updateProfitPreview() {
    const cost  = parseFloat(document.getElementById('editUnitCost').value  || 0);
    const price = parseFloat(document.getElementById('editUnitPrice').value || 0);
    const profit = price - cost;
    const el = document.getElementById('editProfitPreview');
    if (el) {
        el.textContent = profit >= 0
            ? `Profit margin: ₱${profit.toFixed(2)} per unit`
            : `<i class="fas fa-exclamation-triangle"></i> Selling below cost by ₱${Math.abs(profit).toFixed(2)}`;
        el.style.color = profit >= 0 ? '#002F70' : '#dc3545';
    }
}

function closeEditProductModal() {
    closeModal('editProductModal');
}

function viewProduct(productId) {
    const product = products.find(p => p.id == productId);
    if (product) {
        const cost   = parseFloat(product.unit_cost  || 0);
        const price  = parseFloat(product.unit_price || cost);
        const profit = price - cost;
        document.getElementById('viewProductId').textContent   = '#' + product.id;
        document.getElementById('viewProductName').textContent = product.product_name;
        document.getElementById('viewCategory').textContent    = product.category;
        document.getElementById('viewUnitCost').textContent    = '₱' + cost.toFixed(2);
        document.getElementById('viewUnitPrice').textContent   = '₱' + price.toFixed(2);
        document.getElementById('viewProfit').textContent      = profit >= 0
            ? `+₱${profit.toFixed(2)} per unit`
            : `<i class="fas fa-exclamation-triangle"></i> Below cost by ₱${Math.abs(profit).toFixed(2)}`;
        document.getElementById('viewStockLevel').textContent  = product.quantity;
        document.getElementById('viewStatus').textContent      = product.status;
        document.getElementById('viewCreatedAt').textContent   = 'N/A';
        openModal('viewProductModal');
    }
}

function closeViewProductModal() {
    closeModal('viewProductModal');
}

function toggleProductStatus(productId, newStatus) {
    if (confirm(`Are you sure you want to ${newStatus} this product?`)) {
        document.getElementById('toggleProductId').value = productId;
        document.getElementById('toggleStatus').value = newStatus;
        document.getElementById('toggleStatusForm').submit();
    }
}

// Fuel product specific functions
function viewFuelProduct(fuelId) {
    const fuelData = fuelInventoryData.find(f => f.id == fuelId);
    if (fuelData) {
        document.getElementById('viewFuelId').textContent = '#' + fuelData.id;
        document.getElementById('viewFuelType').textContent = fuelData.product_name;
        document.getElementById('viewFuelPrice').textContent = '₱' + parseFloat(fuelData.unit_cost).toFixed(2);
        document.getElementById('viewFuelLevel').textContent = parseFloat(fuelData.quantity).toFixed(2) + ' L';
        document.getElementById('viewFuelCapacity').textContent = 'N/A';
        document.getElementById('viewFuelStatus').textContent = fuelData.display_status || fuelData.status;
        document.getElementById('viewFuelUpdated').textContent = 'N/A';
        openModal('viewFuelProductModal');
    } else {
        fetch(`get_fuel_inventory.php?id=${fuelId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('viewFuelId').textContent = '#' + data.fuel.id;
                    document.getElementById('viewFuelType').textContent = data.fuel.fuel_type;
                    document.getElementById('viewFuelPrice').textContent = '₱' + parseFloat(data.fuel.price_per_liter).toFixed(2);
                    document.getElementById('viewFuelLevel').textContent = parseFloat(data.fuel.current_level).toFixed(2) + ' L';
                    document.getElementById('viewFuelCapacity').textContent = parseFloat(data.fuel.capacity).toFixed(2) + ' L';
                    document.getElementById('viewFuelStatus').textContent = data.fuel.status;
                    document.getElementById('viewFuelUpdated').textContent = data.fuel.last_updated ? new Date(data.fuel.last_updated).toLocaleString() : 'N/A';
                    openModal('viewFuelProductModal');
                } else {
                    alert('Error loading fuel inventory data');
                }
            })
            .catch(() => alert('Error loading fuel inventory data'));
    }
}

function editFuelProduct(fuelId) {
    const fuelData = fuelInventoryData.find(f => f.id == fuelId);
    if (fuelData) {
        document.getElementById('editFuelId').value = fuelData.id;
        document.getElementById('editFuelType').value = fuelData.product_name;
        document.getElementById('editFuelPrice').value = fuelData.unit_cost;
        document.getElementById('editFuelStatus').value = fuelData.display_status || 'Normal';
        document.getElementById('editFuelLevel').value = parseFloat(fuelData.quantity).toFixed(2);
        openModal('editFuelProductModal');
    } else {
        fetch(`get_fuel_inventory.php?id=${fuelId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('editFuelId').value = data.fuel.id;
                    document.getElementById('editFuelType').value = data.fuel.fuel_type;
                    document.getElementById('editFuelPrice').value = data.fuel.price_per_liter;
                    document.getElementById('editFuelStatus').value = data.fuel.status;
                    document.getElementById('editFuelLevel').value = parseFloat(data.fuel.current_level).toFixed(2);
                    openModal('editFuelProductModal');
                } else {
                    alert('Error loading fuel inventory data');
                }
            })
            .catch(() => alert('Error loading fuel inventory data'));
    }
}

function toggleFuelProductStatus(fuelId, newStatus) {
    if (confirm(`Are you sure you want to change the fuel status to ${newStatus}?`)) {
        document.getElementById('toggleFuelId').value = fuelId;
        document.getElementById('toggleFuelStatus').value = newStatus;
        document.getElementById('toggleFuelStatusForm').submit();
    }
}

function closeViewFuelProductModal() {
    closeModal('viewFuelProductModal');
}

function closeEditFuelProductModal() {
    closeModal('editFuelProductModal');
}

// Search functionality (works across tabs)
document.getElementById('productSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const activeTab = document.querySelector('.tab-content.active');
    const rows = activeTab.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Handle form submissions to maintain tab state
function handleFormSubmission(formElement, successCallback) {
    formElement.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(formElement);
        const activeTab = document.querySelector('.tab-btn.active').textContent.toLowerCase().includes('fuel') ? 'fuel' : 'merchandise';
        
        fetch(formElement.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            // Parse the response to check for success/error messages
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Check for success or error messages
            const successMsg = doc.querySelector('.alert-success');
            const errorMsg = doc.querySelector('.alert-danger');
            
            if (successMsg) {
                // Show success message
                showNotification(successMsg.textContent, 'success');
                // Close modal if open
                document.querySelectorAll('.modal.open').forEach(modal => {
                    modal.classList.remove('open');
                });
                // Reload the page to show updated data while maintaining tab
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else if (errorMsg) {
                // Show error message
                showNotification(errorMsg.textContent, 'error');
            } else {
                // If no messages, assume success and reload
                showNotification('Operation completed successfully', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('An error occurred. Please try again.', 'error');
        });
    });
}

// Show notification function
function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'}`;
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000; padding: 15px 20px; border-radius: 6px; background: ' + (type === 'success' ? '#28a745' : '#dc3545') + '; color: white; font-weight: 500; box-shadow: 0 4px 12px rgba(0,0,0,0.15);';
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Initialize form handlers and restore active tab
document.addEventListener('DOMContentLoaded', function() {
    // Restore active tab from URL parameter
    const urlParams = new URLSearchParams(window.location.search);
    const activeTabFromUrl = urlParams.get('tab');
    
    if (activeTabFromUrl && (activeTabFromUrl === 'fuel' || activeTabFromUrl === 'merchandise')) {
        // Find the corresponding tab button and click it programmatically
        const tabButton = Array.from(document.querySelectorAll('.tab-btn')).find(btn => {
            const btnText = btn.textContent.toLowerCase();
            return (activeTabFromUrl === 'fuel' && btnText.includes('fuel')) ||
                   (activeTabFromUrl === 'merchandise' && btnText.includes('merchandise'));
        });
        
        if (tabButton) {
            // Remove active class from all tabs and buttons
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(button => {
                button.classList.remove('active');
            });
            
            // Activate the correct tab
            document.getElementById(activeTabFromUrl + '-tab').classList.add('active');
            tabButton.classList.add('active');
        }
    }
    
    // Handle add product form
    const addForm = document.querySelector('form[action*="action=add_product"]');
    if (addForm) handleFormSubmission(addForm);
    
    // Handle edit product form
    const editForm = document.querySelector('form[action*="action=update_product"]');
    if (editForm) handleFormSubmission(editForm);
    
    // Handle edit fuel form
    const editFuelForm = document.querySelector('form[action*="action=update_fuel_product"]');
    if (editFuelForm) handleFormSubmission(editFuelForm);
    
    // Handle toggle status forms
    const toggleForm = document.getElementById('toggleStatusForm');
    if (toggleForm) {
        toggleForm.addEventListener('submit', function(e) {
            // Let this one submit normally since it's a simple status toggle
            const activeTab = document.querySelector('.tab-btn.active').textContent.toLowerCase().includes('fuel') ? 'fuel' : 'merchandise';
            // The form already has the correct tab parameter in its action
        });
    }
    
    const toggleFuelForm = document.getElementById('toggleFuelStatusForm');
    if (toggleFuelForm) {
        toggleFuelForm.addEventListener('submit', function(e) {
            // Let this one submit normally since it's a simple status toggle
            const activeTab = document.querySelector('.tab-btn.active').textContent.toLowerCase().includes('fuel') ? 'fuel' : 'merchandise';
            // The form already has the correct tab parameter in its action
        });
    }
});

// Close modals when clicking the backdrop
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('open');
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal.open').forEach(modal => {
            modal.classList.remove('open');
        });
    }
});

function toggleBatches(productId) {
    const row = document.getElementById('batch-rows-' + productId);
    const icon = document.getElementById('batch-icon-' + productId);
    if (row.style.display === 'none' || !row.style.display) {
        row.style.display = 'table-row';
        if (icon) {
            icon.className = 'fas fa-chevron-up';
        }
    } else {
        row.style.display = 'none';
        if (icon) {
            icon.className = 'fas fa-layer-group';
        }
    }
}
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
