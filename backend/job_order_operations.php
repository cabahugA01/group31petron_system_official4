<?php
/**
 * Job Order Management Backend
 * Staff-driven workflow with admin supervision
 * 
 * Flow:
 * 1. Staff encodes job order
 * 2. Admin reviews and validates
 * 3. Admin approves (if high-value/sensitive)
 * 4. Job execution
 * 5. Inventory deduction
 * 6. Billing calculation
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';
require_once __DIR__ . '/customer_module_helpers.php';

class JobOrderOperations {
    
    private $pdo;
    private $station_id;
    private $user;
    
    public function __construct($pdo, $user, $station_id) {
        $this->pdo = $pdo;
        $this->user = $user;
        $this->station_id = $station_id;
    }

    private function ensureStationItemTables(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS station_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            item_type ENUM('product','service','part') NOT NULL,
            product_id INT NULL,
            category_id INT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station_items_station (station_id),
            INDEX idx_station_items_type (item_type),
            INDEX idx_station_items_product (product_id),
            INDEX idx_station_items_category (category_id),
            INDEX idx_station_items_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $this->pdo->exec("ALTER TABLE station_items ADD COLUMN product_id INT NULL AFTER item_type"); } catch (Exception $e) {}
        try { $this->pdo->exec("ALTER TABLE station_items ADD INDEX idx_station_items_product (product_id)"); } catch (Exception $e) {}
        try { $this->pdo->exec("ALTER TABLE station_items ADD COLUMN category_id INT NULL AFTER item_type"); } catch (Exception $e) {}
        try { $this->pdo->exec("ALTER TABLE station_items ADD INDEX idx_station_items_category (category_id)"); } catch (Exception $e) {}

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS job_order_item_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            job_order_id INT NOT NULL,
            station_item_id INT NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            linked_by INT NOT NULL,
            executed_by INT NULL,
            executed_notes TEXT NULL,
            linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            executed_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_jo_links_station (station_id),
            INDEX idx_jo_links_job (job_order_id),
            INDEX idx_jo_links_item (station_item_id),
            INDEX idx_jo_links_exec (executed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function addStationItem(array $data): array {
        try {
            $this->ensureStationItemTables();
            $this->pdo->beginTransaction();

            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Only manager can add station items');
            }

            $itemType = strtolower(trim((string)($data['item_type'] ?? 'product')));
            $name = trim((string)($data['name'] ?? ''));
            $description = trim((string)($data['description'] ?? ''));
            $unitPrice = (float)($data['unit_price'] ?? 0);
            $categoryId = (int)($data['category_id'] ?? 0);

            if ($itemType !== 'product') {
                throw new Exception('Only products can be added in this workflow');
            }
            if ($name === '') {
                throw new Exception('Item name is required');
            }
            if ($categoryId <= 0) {
                throw new Exception('Product category is required');
            }

            $catStmt = $this->pdo->prepare("SELECT id FROM product_categories WHERE id = ? LIMIT 1");
            $catStmt->execute([$categoryId]);
            if (!$catStmt->fetchColumn()) {
                throw new Exception('Invalid product category selected');
            }

            $stmt = $this->pdo->prepare("INSERT INTO station_items
                (station_id, item_type, category_id, name, description, unit_price, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?)");
            $stmt->execute([
                $this->station_id,
                $itemType,
                $categoryId,
                $name,
                $description !== '' ? $description : null,
                $unitPrice,
                $this->user['id'] ?? null
            ]);

            $itemId = (int)$this->pdo->lastInsertId();

            $inventorySynced = false;
            if ($itemType === 'product') {
                $productId = $this->syncStationItemToInventory($name, $description, $unitPrice, $categoryId);
                $updItem = $this->pdo->prepare("UPDATE station_items SET product_id = ? WHERE id = ?");
                $updItem->execute([$productId, $itemId]);
                $inventorySynced = true;
            }

            log_activity(
                $this->pdo,
                $this->user['id'] ?? 0,
                'Station Item Added',
                sprintf('Station %d | Item #%d | Type: %s | Name: %s', $this->station_id, $itemId, $itemType, $name)
            );

            $this->pdo->commit();

            return [
                'success' => true,
                'message' => $inventorySynced ? 'Station item added and synced to inventory' : 'Station item added',
                'item_id' => $itemId,
                'inventory_synced' => $inventorySynced
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function syncStationItemToInventory(string $name, string $description, float $unitPrice, int $categoryId): int {
        $name = trim($name);
        if ($name === '') {
            throw new Exception('Cannot sync empty item name to inventory');
        }

        // Resolve merchandise type id, fallback to 2 (current merch default in this system)
        $typeStmt = $this->pdo->prepare("SELECT id FROM product_types WHERE LOWER(name) = 'merch' LIMIT 1");
        $typeStmt->execute();
        $merchTypeId = (int)($typeStmt->fetchColumn() ?: 2);

        // Use existing product if same name + merchandise type exists
        $prodStmt = $this->pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND type_id = ? LIMIT 1");
        $prodStmt->execute([$name, $merchTypeId]);
        $productId = (int)($prodStmt->fetchColumn() ?: 0);

        if ($productId <= 0) {
            $sku = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $name), 0, 32));
            if ($sku === '') {
                $sku = 'ITEM';
            }
            $sku .= '-S' . (int)$this->station_id . '-' . date('His');

            $insProd = $this->pdo->prepare("INSERT INTO products (sku, name, description, type_id, category_id, cost, price) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insProd->execute([
                $sku,
                $name,
                $description !== '' ? $description : null,
                $merchTypeId,
                $categoryId > 0 ? $categoryId : null,
                $unitPrice,
                $unitPrice
            ]);
            $productId = (int)$this->pdo->lastInsertId();
        } else {
            $updProd = $this->pdo->prepare("UPDATE products SET description = COALESCE(NULLIF(?, ''), description), category_id = COALESCE(?, category_id), cost = ?, price = ? WHERE id = ?");
            $updProd->execute([$description, $categoryId > 0 ? $categoryId : null, $unitPrice, $unitPrice, $productId]);
        }

        // Ensure station inventory record exists for this station/product
        $invStmt = $this->pdo->prepare("SELECT id FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1");
        $invStmt->execute([$this->station_id, $productId]);
        $invId = (int)($invStmt->fetchColumn() ?: 0);

        if ($invId <= 0) {
            $insInv = $this->pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, cost, price, reorder_level, capacity, unit, status) VALUES (?, ?, 0, ?, ?, 0, 1000, 'pcs', 'active')");
            $insInv->execute([$this->station_id, $productId, $unitPrice, $unitPrice]);
        } else {
            $updInv = $this->pdo->prepare("UPDATE station_inventory SET cost = ?, price = ?, status = 'Active', unit = COALESCE(NULLIF(unit, ''), 'pcs') WHERE id = ?");
            $updInv->execute([$unitPrice, $unitPrice, $invId]);
        }

        return $productId;
    }

    public function getStationItems(string $itemType = ''): array {
        try {
            $this->ensureStationItemTables();

            if ($itemType !== '' && in_array($itemType, ['product', 'service', 'part'], true)) {
                $stmt = $this->pdo->prepare("SELECT si.*, pc.name AS category_name
                    FROM station_items si
                    LEFT JOIN product_categories pc ON pc.id = si.category_id
                    WHERE si.station_id = ? AND si.is_active = 1 AND si.item_type = ?
                    ORDER BY pc.name ASC, si.name ASC");
                $stmt->execute([$this->station_id, $itemType]);
            } else {
                $stmt = $this->pdo->prepare("SELECT si.*, pc.name AS category_name
                    FROM station_items si
                    LEFT JOIN product_categories pc ON pc.id = si.category_id
                    WHERE si.station_id = ? AND si.is_active = 1
                    ORDER BY si.item_type ASC, pc.name ASC, si.name ASC");
                $stmt->execute([$this->station_id]);
            }

            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }

    public function linkStationItemToJobOrder($jobId, $stationItemId, $quantity = 1, $notes = null): array {
        try {
            $this->ensureStationItemTables();
            $this->pdo->beginTransaction();

            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Only manager can link items/services to job orders');
            }

            $jobId = (int)$jobId;
            $stationItemId = (int)$stationItemId;
            $quantity = (float)$quantity;
            if ($jobId <= 0 || $stationItemId <= 0) {
                throw new Exception('Invalid job or item selection');
            }
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than zero');
            }

            $jobStmt = $this->pdo->prepare("SELECT `user_id`, job_order_number, station_id FROM job_orders WHERE id = ? LIMIT 1");
            $jobStmt->execute([$jobId]);
            $job = $jobStmt->fetch(PDO::FETCH_ASSOC);
            if (!$job || (int)$job['station_id'] !== (int)$this->station_id) {
                throw new Exception('Job order not found for your station');
            }

            $itemStmt = $this->pdo->prepare("SELECT id, name, item_type, station_id, is_active FROM station_items WHERE id = ? LIMIT 1");
            $itemStmt->execute([$stationItemId]);
            $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
            if (!$item || (int)$item['station_id'] !== (int)$this->station_id || (int)$item['is_active'] !== 1) {
                throw new Exception('Station item is invalid or inactive');
            }
            if (($item['item_type'] ?? '') !== 'product') {
                throw new Exception('Only products can be linked in this workflow');
            }

            $ins = $this->pdo->prepare("INSERT INTO job_order_item_links
                (station_id, job_order_id, station_item_id, quantity, notes, linked_by)
                VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([
                $this->station_id,
                $jobId,
                $stationItemId,
                $quantity,
                $notes,
                $this->user['id'] ?? 0
            ]);

            $linkId = (int)$this->pdo->lastInsertId();
            log_activity(
                $this->pdo,
                $this->user['id'] ?? 0,
                'Job Item Linked',
                sprintf('Station %d | Job %s (#%d) | Linked %s "%s" x %s | Link #%d',
                    $this->station_id,
                    (string)($job['job_order_number'] ?? 'N/A'),
                    $jobId,
                    (string)($item['item_type'] ?? 'item'),
                    (string)($item['name'] ?? ''),
                    rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.'),
                    $linkId
                )
            );

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Item/service linked to job order', 'link_id' => $linkId];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function executeLinkedJobItem($linkId, $executionNotes = null): array {
        try {
            $this->ensureStationItemTables();
            $this->pdo->beginTransaction();

            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'staff') {
                throw new Exception('Only staff can execute linked job items/services');
            }

            $linkId = (int)$linkId;
            if ($linkId <= 0) {
                throw new Exception('Invalid link id');
            }

            $stmt = $this->pdo->prepare("SELECT l.*, j.job_order_number, si.name as station_item_name, si.item_type,
                        si.product_id as station_product_id, si.description as station_item_description,
                        si.unit_price as station_item_unit_price, si.category_id as station_item_category_id
                FROM job_order_item_links l
                INNER JOIN job_orders j ON j.id = l.job_order_id
                INNER JOIN station_items si ON si.id = l.station_item_id
                WHERE l.id = ? AND l.station_id = ? LIMIT 1");
            $stmt->execute([$linkId, $this->station_id]);
            $link = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$link) {
                throw new Exception('Linked item not found for your station');
            }
            if (!empty($link['executed_at'])) {
                throw new Exception('Linked item already executed');
            }

            $productId = (int)($link['station_product_id'] ?? 0);
            if ($productId <= 0) {
                $productId = $this->syncStationItemToInventory(
                    (string)($link['station_item_name'] ?? ''),
                    (string)($link['station_item_description'] ?? ''),
                    (float)($link['station_item_unit_price'] ?? 0),
                    (int)($link['station_item_category_id'] ?? 0)
                );

                $updStationItem = $this->pdo->prepare("UPDATE station_items SET product_id = ? WHERE id = ?");
                $updStationItem->execute([$productId, (int)$link['station_item_id']]);
            }

            $quantity = (float)($link['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new Exception('Invalid linked quantity');
            }

            $unitCost = (float)($link['station_item_unit_price'] ?? 0);
            $partTotal = $quantity * $unitCost;

            $deduct = $this->pdo->prepare("UPDATE station_inventory
                SET stock_level = stock_level - ?
                WHERE station_id = ? AND product_id = ? AND stock_level >= ?");
            $deduct->execute([$quantity, $this->station_id, $productId, $quantity]);

            if ($deduct->rowCount() === 0) {
                throw new Exception('Insufficient inventory stock to execute this linked product');
            }

            $insPart = $this->pdo->prepare("INSERT INTO job_order_parts
                (job_order_id, product_id, quantity_used, unit_cost, total_cost, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())");
            $insPart->execute([
                (int)$link['job_order_id'],
                $productId,
                $quantity,
                $unitCost,
                $partTotal
            ]);

            $updJobCosts = $this->pdo->prepare("UPDATE job_orders
                SET actual_parts_cost = COALESCE(actual_parts_cost, 0) + ?,
                    total_cost = COALESCE(total_cost, 0) + ?
                WHERE id = ?");
            $updJobCosts->execute([$partTotal, $partTotal, (int)$link['job_order_id']]);

            $upd = $this->pdo->prepare("UPDATE job_order_item_links
                SET executed_by = ?, executed_notes = ?, executed_at = NOW()
                WHERE id = ?");
            $upd->execute([$this->user['id'] ?? 0, $executionNotes, $linkId]);

            log_activity(
                $this->pdo,
                $this->user['id'] ?? 0,
                'Job Item Executed',
                sprintf('Station %d | Job %s (#%d) | Executed %s "%s" x %s | Link #%d',
                    $this->station_id,
                    (string)($link['job_order_number'] ?? 'N/A'),
                    (int)$link['job_order_id'],
                    (string)($link['item_type'] ?? 'item'),
                    (string)($link['station_item_name'] ?? ''),
                    rtrim(rtrim(number_format((float)$link['quantity'], 2, '.', ''), '0'), '.'),
                    $linkId
                )
            );

            $this->pdo->commit();
            return ['success' => true, 'message' => 'Linked item marked as executed'];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getLinkedJobItems($jobId = null, $onlyPending = false): array {
        try {
            $this->ensureStationItemTables();

            $sql = "SELECT l.*, j.job_order_number, j.status as job_status,
                              si.item_type, si.name as station_item_name, si.unit_price, pc.name as category_name,
                           u1.name as linked_by_name, u2.name as executed_by_name
                    FROM job_order_item_links l
                    INNER JOIN job_orders j ON j.id = l.job_order_id
                    INNER JOIN station_items si ON si.id = l.station_item_id
                          LEFT JOIN product_categories pc ON pc.id = si.category_id
                    LEFT JOIN users u1 ON u1.user_id = l.linked_by
                    LEFT JOIN users u2 ON u2.user_id = l.executed_by
                    WHERE l.station_id = ?";
            $params = [$this->station_id];

            if ($jobId !== null && (int)$jobId > 0) {
                $sql .= " AND l.job_order_id = ?";
                $params[] = (int)$jobId;
            }

            if ($onlyPending) {
                $sql .= " AND l.executed_at IS NULL";
            }

            $sql .= " ORDER BY l.executed_at IS NULL DESC, l.linked_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'data' => []];
        }
    }
    
    /**
     * Create Job Order (Staff Action)
     * Staff encodes all job order details
     */
    public function createJobOrder($data) {
        try {
            $this->pdo->beginTransaction();

            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'staff') {
                throw new Exception('Only operations staff can create job orders');
            }

            if (empty($data['service_category_id'])) {
                throw new Exception('Please select a service type');
            }
            
            // Generate job order number
            $job_order_number = $this->generateJobOrderNumber();

            // Customers are Manager-controlled. Staff must select an approved
            // customer or submit a new customer request from the transaction UI.
            customer_ensure_optional_columns($this->pdo);
            $customer_id = (int)($data['customer_id'] ?? 0);
            if (!$customer_id) {
                throw new Exception('Please select an approved customer before creating a job order.');
            }

            $customerNameExpr = customer_display_name_expr($this->pdo, 'c');
            $customerStatusExpr = customer_status_expr($this->pdo, 'c');
            $stmt = $this->pdo->prepare("
                SELECT c.id, {$customerNameExpr} AS customer_name
                FROM customers c
                WHERE c.id = ?
                  AND c.station_id = ?
                  AND LOWER({$customerStatusExpr}) = 'active'
                LIMIT 1
            ");
            $stmt->execute([$customer_id, $this->station_id]);
            $existingCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingCustomer) {
                throw new Exception('Selected customer was not found or is inactive.');
            }
            $data['customer_name'] = $existingCustomer['customer_name'];

            // Resolve or create mechanic (required)
            $assigned_mechanic_id = $data['assigned_mechanic_id'] ?? null;
            $mechanic_name = trim((string)($data['mechanic_name'] ?? ''));
            if (!$assigned_mechanic_id && $mechanic_name !== '') {
                $stmt = $this->pdo->prepare("SELECT id FROM mechanics WHERE full_name = ? AND station_id = ? LIMIT 1");
                $stmt->execute([$mechanic_name, $this->station_id]);
                $existingMech = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingMech) {
                    $assigned_mechanic_id = $existingMech['id'];
                } else {
                    $ins = $this->pdo->prepare("INSERT INTO mechanics (station_id, full_name, status) VALUES (?, ?, 'active')");
                    $ins->execute([$this->station_id, $mechanic_name]);
                    $assigned_mechanic_id = $this->pdo->lastInsertId();
                }
            }

            if (!$assigned_mechanic_id) {
                throw new Exception('Please select or enter a mechanic');
            }
            
            // Validate mechanic availability (duty roster check)
            if (!$this->validateMechanicAvailability($assigned_mechanic_id)) {
                throw new Exception('Selected mechanic is not available or on duty');
            }
            
            // Determine if admin approval is required
            $requires_approval = $this->requiresAdminApproval($data);
            $initial_status = $requires_approval ? 'Pending' : 'Pending';
            
            // Calculate estimated costs
            $estimated_costs = $this->calculateEstimatedCosts($data);
            
            // Insert job order with retry mechanism to handle race conditions
            $maxRetries = 3;
            $job_order_number = $this->generateJobOrderNumber();
            $lastException = null;
            $job_id = null;

            for ($retry = 0; $retry < $maxRetries; $retry++) {
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO job_orders
                        (job_order_number, station_id, customer_id, vehicle_plate, vehicle_type,
                         service_category_id, assigned_mechanic_id, assigned_by, service_description, 
                         estimated_duration, status, notes, created_at, requires_approval,
                         estimated_labor_cost, estimated_parts_cost)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $job_order_number,
                        $this->station_id,
                        $customer_id ?: null,
                        $data['vehicle_plate'] ?? null,
                        $data['vehicle_type'] ?? null,
                        $data['service_category_id'],
                        $assigned_mechanic_id,
                        $this->user['id'],
                        $data['service_description'] ?? 'General Service',
                        (int)($data['estimated_duration'] ?? 60),
                        $initial_status,
                        $data['notes'] ?? null,
                        $requires_approval ? 1 : 0,
                        $estimated_costs['labor'],
                        $estimated_costs['parts']
                    ]);
                    
                    // Insert successful - exit retry loop
                    $job_id = $this->pdo->lastInsertId();
                    break;
                    
                } catch (PDOException $e) {
                    $lastException = $e;
                    
                    if ($this->isDuplicateKeyException($e)) {
                        // Generate new sequence number and retry
                        $sequence = intval(explode('-', $job_order_number)[3]) + 1;
                        $job_order_number = $this->generateJobOrderNumber($sequence);
                        
                        if ($retry < $maxRetries - 1) {
                            continue; // Retry
                        }
                    }
                    
                    // Not a duplicate key or retries exhausted
                    throw $e;
                }
            }
            
            if (!$job_id) {
                throw new Exception('Failed to create job order after multiple attempts: ' . $lastException->getMessage());
            }
            
            // Log activity
            log_activity(
                $this->pdo,
                $this->user['id'], 
                'Create Job Order', 
                'Job order created by staff' . ($requires_approval ? ' - Admin approval required' : '')
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Job order created successfully' . ($requires_approval ? '. Awaiting admin approval.' : ''),
                'job_id' => $job_id,
                'job_order_number' => $job_order_number,
                'requires_approval' => $requires_approval
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Manager Review and Approval
     * Manager approves job order and validates service charges
     * ENFORCES: Staff cannot override totals after approval
     */
    public function managerApproveJobOrder($job_id, $action, $remarks = null) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: ONLY Manager can approve job orders per hierarchy
            // Admin should NOT do Manager work - Admin only has unlock capability
            $role = role_key($this->user['role'] ?? '');
            if ($role !== 'manager') {
                throw new Exception('Manager privileges required for job order approval. Admin cannot override manager decisions.');
            }
            
            $job = $this->getJobOrderDetails($job_id);
            if (!$job) {
                throw new Exception('Job order not found');
            }
            
            if ($job['status'] !== 'Pending') {
                throw new Exception('Job order must be in Pending status to approve');
            }
            
            if (!$this->validateMechanicAvailability($job['assigned_mechanic_id'])) {
                throw new Exception('Assigned mechanic is no longer available');
            }
            
             if ($action === 'approve') {
                  // APPROVAL: Move directly to In Progress (day-to-day manager operation)
                  $stmt = $this->pdo->prepare("
                      UPDATE job_orders
                      SET status = 'In Progress',
                          reviewed_by = ?,
                          reviewed_at = NOW(),
                          started_at = NOW(),
                          admin_remarks = ?
                      WHERE id = ?
                  ");
                  $stmt->execute([$this->user['id'], $remarks, $job_id]);
                  
                  log_activity(
                      $this->pdo,
                      $this->user['id'],
                      'Job Order Approved',
                      sprintf('Job %s approved and started by manager. Total: ₱%.2f', $job['job_order_number'], $job['estimated_labor_cost'] + $job['estimated_parts_cost'])
                  );
                  
                  $message = 'Job order approved and started!';
                 
            } elseif ($action === 'reject') {
                // REJECTION: Return to pending - use reviewed_by/reviewed_at for tracking
                $stmt = $this->pdo->prepare("
                    UPDATE job_orders
                    SET status = 'Rejected',
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        admin_remarks = ?
                    WHERE id = ?
                ");
                $stmt->execute([$this->user['id'], $remarks, $job_id]);
                
                log_activity(
                    $this->pdo,
                    $this->user['id'],
                    'Job Order Rejected',
                    'Rejected reason: ' . ($remarks ?? 'Not specified')
                );
                
                $message = 'Job order rejected and returned to staff.';
            }
            
            $this->pdo->commit();
            
            return ['success' => true, 'message' => $message];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Manager Finalize Job Order
     * Manager views approved jobs (no staff can create)
     * Requires manager password for security checkpoint
     */
    public function managerFinalApproval($job_id, $manager_password) {
        try {
            $this->pdo->beginTransaction();
            
            // RBAC: Manager or Super Admin only (Admin is read-only for hierarchy compliance)
            $role = role_key($this->user['role'] ?? '');
            if (!in_array($role, ['manager', 'superadmin'])) {
                throw new Exception('Manager privileges required');
            }
            
            $job = $this->getJobOrderDetails($job_id);
            if (!$job) {
                throw new Exception('Job order not found');
            }
            
            if ($job['status'] !== 'Reviewed') {
                throw new Exception('Job order must be in Reviewed status to finalize');
            }
            
            // SECURITY: Super Admin bypasses password verification
            if ($role === 'superadmin') {
                // Super Admin bypass - no password check needed
            } else {
                // MANAGER: Verify manager password
                $stmt = $this->pdo->prepare("
                    SELECT u.password FROM users u
                    WHERE u.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$this->user['id']]);
                $manager = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$manager || !password_verify($manager_password, $manager['password_hash'])) {
                    throw new Exception('Invalid manager password verification');
                }
            }
            
             // FINALIZE: Move to In Progress and lock all edits
             $stmt = $this->pdo->prepare("
                 UPDATE job_orders
                 SET status = 'In Progress',
                     finalized_by = ?,
                     finalized_at = NOW(),
                     staff_editable = 0,
                     started_at = NOW()
                 WHERE id = ? AND status = 'Reviewed'
             ");
            $stmt->execute([$this->user['id'], $job_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Job order must be manager-approved before admin finalization');
            }
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Job Order Finalized',
                sprintf('Job %s finalized by %s. Ready for execution. Manager password verified.', $job['job_order_number'], $role)
            );
            
            $this->pdo->commit();
            
            return ['success' => true, 'message' => 'Job order finalized and ready for execution'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Start Job Order (For non-approval required jobs)
     */
    public function startJobOrder($job_id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE job_orders
                SET status = 'In Progress',
                    started_at = NOW()
                WHERE id = ? AND status IN ('Pending', 'Reviewed')
            ");
            $stmt->execute([$job_id]);
            
            log_activity($this->pdo, $this->user['id'], 'Job Order Started', 'Job execution started');
            
            return ['success' => true, 'message' => 'Job order started'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Complete Job Order with Inventory Deduction
     * ENFORCES: Auto-deduct parts from inventory
     * ENFORCES: Fail if stock insufficient
     * ENFORCES: Billing total locked (cannot be overridden by staff)
     */
    public function completeJobOrder($job_id, $parts_used = [], $actual_labor_hours = 0) {
        try {
            $this->pdo->beginTransaction();
            
            $job = $this->getJobOrderDetails($job_id);
            if (!$job) {
                throw new Exception('Job order not found');
            }
            
            if ($job['status'] !== 'In Progress') {
                throw new Exception('Job order must be in progress to complete');
            }

            $existingPartsStmt = $this->pdo->prepare("SELECT DISTINCT product_id FROM job_order_parts WHERE job_order_id = ? AND product_id IS NOT NULL");
            $existingPartsStmt->execute([$job_id]);
            $alreadyRecordedProductIds = array_map('intval', $existingPartsStmt->fetchAll(PDO::FETCH_COLUMN));
            $alreadyRecordedLookup = array_fill_keys($alreadyRecordedProductIds, true);

            $validatedParts = [];
            $requestProductLookup = [];

            foreach ($parts_used as $part) {
                $productId = (int)($part['product_id'] ?? 0);
                $quantity = (float)($part['quantity'] ?? 0);
                $unitCost = (float)($part['unit_cost'] ?? 0);

                if ($productId <= 0 || $quantity <= 0) {
                    continue;
                }

                if (isset($alreadyRecordedLookup[$productId])) {
                    throw new Exception('Product already recorded for this job. Remove duplicate from completion list. Product ID: ' . $productId);
                }

                if (isset($requestProductLookup[$productId])) {
                    throw new Exception('Duplicate product detected in completion list. Product ID: ' . $productId);
                }

                $requestProductLookup[$productId] = true;
                $validatedParts[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost
                ];
            }

            $parts_used = $validatedParts;
            
            // INVENTORY DEDUCTION: Check stock before processing
             foreach ($parts_used as $part) {
                 // Get product type to use correct inventory table
                 $typeStmt = $this->pdo->prepare("SELECT type_id FROM products WHERE id = ?");
                 $typeStmt->execute([$part['product_id']]);
                 $product = $typeStmt->fetch(PDO::FETCH_ASSOC);
                 
                 if (!$product) {
                     throw new Exception('Product not found: ID ' . $part['product_id']);
                 }
                 
                 // Note: Job orders typically use merchandise parts, not fuel
                 // But we check based on product type to be safe
                 if ($product['type_id'] == 1) {
                     // Fuel - check fuel_inventory
                     $stmt = $this->pdo->prepare("
                         SELECT stock_level FROM fuel_inventory
                         WHERE station_id = ? AND product_id = ?
                     ");
                 } else {
                     // Merchandise - check station_inventory
                     $stmt = $this->pdo->prepare("
                         SELECT stock_level FROM station_inventory
                         WHERE station_id = ? AND product_id = ?
                     ");
                 }
                 $stmt->execute([$this->station_id, $part['product_id']]);
                 $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
                 
                 if (!$inventory) {
                     throw new Exception('Product not in inventory: ID ' . $part['product_id']);
                 }
                 
                 if ($inventory['stock_level'] < $part['quantity']) {
                     throw new Exception(
                         sprintf('Insufficient stock. Need %d but only %d available.',
                         $part['quantity'], $inventory['stock_level'])
                     );
                 }
             }
            
            // DEDUCTION: Process all parts (now safe since stock verified)
             $total_parts_cost = 0;
             foreach ($parts_used as $part) {
                 // Get product type to use correct inventory table
                 $typeStmt = $this->pdo->prepare("SELECT type_id FROM products WHERE id = ?");
                 $typeStmt->execute([$part['product_id']]);
                 $product = $typeStmt->fetch(PDO::FETCH_ASSOC);
                 
                 // Auto-deduct from appropriate inventory table based on product type
                 if ($product['type_id'] == 1) {
                     // Fuel - deduct from fuel_inventory
                     $stmt = $this->pdo->prepare("
                         UPDATE fuel_inventory
                         SET stock_level = stock_level - ?
                         WHERE station_id = ? AND product_id = ?
                     ");
                 } else {
                     // Merchandise - deduct from station_inventory
                     $stmt = $this->pdo->prepare("
                         UPDATE station_inventory
                         SET stock_level = stock_level - ?
                         WHERE station_id = ? AND product_id = ?
                     ");
                 }
                 $stmt->execute([$part['quantity'], $this->station_id, $part['product_id']]);
                 
                 if (function_exists('log_inventory_movement')) {
                     try {
                         $cur_stock_stmt = $this->pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = ? AND product_id = ? LIMIT 1");
                         $cur_stock_stmt->execute([$this->station_id, $part['product_id']]);
                         $stock_after = (int)$cur_stock_stmt->fetchColumn();
                         $stock_before = $stock_after + (int)$part['quantity'];

                         $pname_stmt = $this->pdo->prepare("SELECT name FROM products WHERE id = ? LIMIT 1");
                         $pname_stmt->execute([$part['product_id']]);
                         $pname = $pname_stmt->fetchColumn() ?: ('Part #' . $part['product_id']);

                         log_inventory_movement(
                             $this->pdo, $this->station_id, (int)$part['product_id'], $pname,
                             'Job Order Usage', $stock_before, $stock_after, -(int)$part['quantity'],
                             'job_order', $job['job_order_number'],
                             $this->user['name'] ?? 'System', 'Job Order Usage - Ref: ' . $job['job_order_number']
                         );
                     } catch (Exception $log_err) {}
                 }

                 // Record parts used
                 $stmt = $this->pdo->prepare("
                     INSERT INTO job_order_parts
                     (job_order_id, product_id, quantity_used, unit_cost, total_cost, created_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                 ");
                 
                 $part_total = $part['quantity'] * $part['unit_cost'];
                 $stmt->execute([
                     $job_id,
                     $part['product_id'],
                     $part['quantity'],
                     $part['unit_cost'],
                     $part_total
                 ]);
                 
                 $total_parts_cost += $part_total;
                 
                 log_activity(
                     $this->pdo,
                     $this->user['id'],
                     'Inventory Deduction',
                     sprintf('Job %s: %d units deducted for product ID %d', $job['job_order_number'], $part['quantity'], $part['product_id'])
                 );
             }
            
            // BILLING: Calculate and lock total (staff cannot override)
            $labor_cost = $this->calculateLaborCost($job, $actual_labor_hours);
            $total_cost = $total_parts_cost + $labor_cost;
            
            // LOCK: Update job order with final billing
            $stmt = $this->pdo->prepare("
                UPDATE job_orders
                SET status = 'Completed',
                    completed_at = NOW(),
                    actual_parts_cost = ?,
                    actual_labor_cost = ?,
                    total_cost = ?,
                    actual_duration = ?,
                    staff_editable = 0,
                    billing_locked = 1
                WHERE id = ?
            ");
            
            $actual_duration = $this->calculateActualDuration($job);
            $stmt->execute([
                $total_parts_cost,
                $labor_cost,
                $total_cost,
                $actual_duration,
                $job_id
            ]);
            
            log_activity(
                $this->pdo,
                $this->user['id'],
                'Job Order Completed',
                sprintf('Total locked: ₱%.2f (Parts: ₱%.2f, Labor: ₱%.2f)', $total_cost, $total_parts_cost, $labor_cost)
            );
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Job order completed. Billing locked.',
                'billing' => [
                    'parts_cost' => $total_parts_cost,
                    'labor_cost' => $labor_cost,
                    'total_cost' => $total_cost
                ]
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
     }
     
     /**
     * Update Job Status with Complete Workflow
     * ENFORCES: Proper status flow based on user role
     */
    public function updateJobStatus($job_id, $status, $notes = '') {
        try {
            $this->pdo->beginTransaction();
            
            $job = $this->getJobOrderDetails($job_id, true);
            if (!$job) {
                throw new Exception('Job order not found');
            }
            
            // Complete status flow with role-based restrictions
            $status_flow = [
                'Draft' => ['Pending'],
                'Pending' => ['Approved', 'Rejected'],
                'Approved' => ['In Progress'],
                'In Progress' => ['Completed', 'On Hold', 'Awaiting Parts'],
                'Completed' => ['Reviewed'],
                'Reviewed' => ['Ready for Billing'],
                'Ready for Billing' => ['Paid'],
                'Paid' => ['Released'],
                'On Hold' => ['In Progress', 'Cancelled'],
                'Awaiting Parts' => ['In Progress', 'Cancelled'],
                'Rejected' => ['Pending'], // Can be resubmitted
                'Cancelled' => [] // Terminal state
            ];
            
            $valid_statuses = array_keys($status_flow);
            if (!in_array($status, $valid_statuses)) {
                throw new Exception('Invalid status: ' . $status);
            }
            
            // Check if transition is allowed
            $current_status = $job['status'];
            if (!in_array($status, $status_flow[$current_status] ?? [])) {
                throw new Exception("Cannot change status from '{$current_status}' to '{$status}'. Invalid transition.");
            }
            
            // Role-based status restrictions
            $user_role = $this->user['role'] ?? 'staff';
            $role_key = role_key($user_role);
            
            // Define which roles can change to which statuses
            $role_permissions = [
                'staff' => ['In Progress', 'On Hold', 'Awaiting Parts'],
                'technician' => ['In Progress', 'Completed', 'On Hold', 'Awaiting Parts'],
                'manager' => array_keys($status_flow), // Managers can change any status
                'admin' => array_keys($status_flow), // Admins can change any status
                'superadmin' => array_keys($status_flow) // Super admins can change any status
            ];
            
            if (!in_array($status, $role_permissions[$role_key] ?? [])) {
                throw new Exception("Users with role '{$user_role}' cannot change status to '{$status}'.");
            }
            
            // Special validations for certain statuses
            if ($status === 'Completed' && $role_key === 'technician') {
                // Ensure technician is assigned to this job
                if ($job['assigned_technician_id'] != $this->user['id'] && $job['assigned_mechanic_id'] != $this->user['id']) {
                    throw new Exception('Only assigned technician can mark job as completed');
                }
            }
            
            if ($status === 'Reviewed' && $role_key === 'manager') {
                // Manager can only review completed jobs
                if ($current_status !== 'Completed') {
                    throw new Exception('Only completed jobs can be reviewed');
                }
            }
            
            if ($status === 'Ready for Billing' && $role_key === 'manager') {
                // Manager final approval
                if ($current_status !== 'Reviewed') {
                    throw new Exception('Only reviewed jobs can be marked as ready for billing');
                }
            }
            
            // Prepare notes update
            $current_notes = $job['notes'] ?? '';
            $timestamp = date('Y-m-d H:i:s');
            $user_name = $this->user['name'] ?? $this->user['username'] ?? 'Unknown';
            
            $status_note = "\n[{$timestamp}] Status changed to '{$status}' by {$user_name} ({$user_role})";
            if (!empty($notes)) {
                $status_note .= ": " . $notes;
            }
            
            $updated_notes = trim($current_notes . $status_note);
            
            // Update status and append notes
            $stmt = $this->pdo->prepare("
                UPDATE job_orders
                SET status = ?,
                    notes = ?,
                    updated_at = NOW()
            ");
            
            // Add status-specific timestamps
            $update_fields = "status = ?, notes = ?, updated_at = NOW()";
            $params = [$status, $updated_notes];
            
            switch ($status) {
                case 'In Progress':
                    if (empty($job['started_at'])) {
                        $update_fields .= ", started_at = NOW()";
                    }
                    break;
                case 'Completed':
                    if (empty($job['completed_at'])) {
                        $update_fields .= ", completed_at = NOW()";
                    }
                    break;
                case 'Reviewed':
                    $update_fields .= ", reviewed_by = ?, reviewed_at = NOW()";
                    $params[] = $this->user['id'];
                    break;
                case 'Ready for Billing':
                    $update_fields .= ", billing_approved_at = NOW()";
                    break;
                case 'Paid':
                    $update_fields .= ", paid_at = NOW()";
                    break;
                case 'Released':
                    $update_fields .= ", released_at = NOW()";
                    break;
            }
            
            $params[0] = $status; // Update the status parameter
            $params[1] = $updated_notes; // Update the notes parameter
            
            $stmt = $this->pdo->prepare("
                UPDATE job_orders
                SET {$update_fields}
                WHERE id = ?
            ");
            $params[] = $job_id;
            $stmt->execute($params);
             
             // Log status change
             log_activity(
                 $this->pdo,
                 $this->user['user_id'] ?? $this->user['id'] ?? 0,
                 'Job Status Updated',
                 sprintf('Job %s status changed from %s to %s by %s. Notes: %s', 
                     $job['job_order_number'], 
                     $current_status, 
                     $status, 
                     $user_name,
                     $notes ?: 'None'
                 )
             );
             
             $this->pdo->commit();
             
             return ['success' => true, 'message' => 'Job status updated successfully'];
             
         } catch (Exception $e) {
             if ($this->pdo->inTransaction()) {
                 $this->pdo->rollBack();
             }
             return ['success' => false, 'message' => $e->getMessage()];
         }
      }
      
      /**
       * Confirm Parts Used (Record parts without completing job)
       */
      public function confirmPartsUsed($job_id, $parts_used = [], $notes = '') {
          try {
              $this->pdo->beginTransaction();
              
              $job = $this->getJobOrderDetails($job_id);
              if (!$job) {
                  throw new Exception('Job order not found');
              }
              
              // Parts can be added to jobs in progress
              if ($job['status'] !== 'In Progress') {
                  throw new Exception('Parts can only be added to jobs in progress');
              }
              
              // Record all parts
              foreach ($parts_used as $part) {
                  // Insert parts using part_name instead of product_id
                  $stmt = $this->pdo->prepare("
                      INSERT INTO job_order_parts
                      (job_order_id, part_name, quantity_used, unit_cost, total_cost, created_at)
                      VALUES (?, ?, ?, ?, ?, NOW())
                  ");
                  
                  $part_total = $part['quantity'] * $part['unit_cost'];
                  $stmt->execute([
                      $job_id,
                      $part['part_name'],
                      $part['quantity'],
                      $part['unit_cost'],
                      $part_total
                  ]);
                  
                  log_activity(
                      $this->pdo,
                      $this->user['id'],
                      'Parts Added',
                      sprintf('Job %s: %s (Qty: %d)', $job['job_order_number'], $part['part_name'], $part['quantity'])
                  );
              }
              
              // Update job notes if provided
              if ($notes) {
                  $stmt = $this->pdo->prepare("
                      UPDATE job_orders
                      SET notes = CONCAT(IFNULL(notes, ''), '\n', ?)
                      WHERE id = ?
                  ");
                  $stmt->execute([$notes, $job_id]);
              }
              
              $this->pdo->commit();
              
              return [
                  'success' => true,
                  'message' => sprintf('Parts recorded for job #%d', $job_id)
              ];
              
          } catch (Exception $e) {
              $this->pdo->rollBack();
              return ['success' => false, 'message' => $e->getMessage()];
          }
      }
      
      /**
       * Validate Mechanic Availability Based on Duty Roster
       */
    private function validateMechanicAvailability($mechanic_id) {
        // Check if mechanic exists and is active
        $stmt = $this->pdo->prepare("
            SELECT id, status FROM mechanics WHERE id = ?
        ");
        $stmt->execute([$mechanic_id]);
        $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mechanic || $mechanic['status'] !== 'active') {
            return false;
        }
        
        // Check current workload (not overloaded)
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as active_jobs
            FROM job_orders
            WHERE assigned_mechanic_id = ?
              AND status = 'In Progress'
              AND station_id = ?
        ");
        $stmt->execute([$mechanic_id, $this->station_id]);
        $workload = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Limit: Max 3 active jobs per mechanic
        if ($workload['active_jobs'] >= 3) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Determine if Admin Approval is Required
     * Based on service type and estimated cost
     */
    private function requiresAdminApproval($data) {
        if (empty($data['service_category_id'])) {
            return false;
        }

        // Get service category details
        $stmt = $this->pdo->prepare("
            SELECT default_parts_cost, default_labor_cost
            FROM service_categories
            WHERE id = ?
        ");
        $stmt->execute([$data['service_category_id']]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$service) {
            return false;
        }
        
        $estimated_total = $service['default_parts_cost'] + $service['default_labor_cost'];
        
        // Require approval for high-value jobs (> 5000 PHP)
        if ($estimated_total > 5000) {
            return true;
        }
        
        // Require approval for sensitive service types
        $sensitive_services = ['Engine Tune-up', 'Transmission Service', 'Major Repair'];
        $stmt = $this->pdo->prepare("SELECT name FROM service_categories WHERE id = ?");
        $stmt->execute([$data['service_category_id']]);
        $service_name = $stmt->fetchColumn();
        
        if (in_array($service_name, $sensitive_services)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Calculate Estimated Costs
     */
    private function calculateEstimatedCosts($data) {
        $stmt = $this->pdo->prepare("
            SELECT default_parts_cost, default_labor_cost
            FROM service_categories
            WHERE id = ?
        ");
        $stmt->execute([$data['service_category_id']]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            return ['parts' => 0, 'labor' => 0];
        }
        
        return [
            'parts' => $service['default_parts_cost'] ?? 0,
            'labor' => $service['default_labor_cost'] ?? 0
        ];
    }
    
    /**
     * Deduct Inventory
     */
    private function deductInventory($product_id, $quantity) {
        $stmt = $this->pdo->prepare("
            UPDATE station_inventory
            SET stock_level = stock_level - ?
            WHERE station_id = ? AND product_id = ? AND stock_level >= ?
        ");
        $stmt->execute([$quantity, $this->station_id, $product_id, $quantity]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Insufficient inventory for product ID: ' . $product_id);
        }
        
        // Log inventory deduction
        $stmt = $this->pdo->prepare("
            INSERT INTO inventory_transactions
            (station_id, product_id, transaction_type, quantity, notes, created_at)
            VALUES (?, ?, 'deduction', ?, 'Job order parts usage', NOW())
        ");
        $stmt->execute([$this->station_id, $product_id, $quantity]);
    }
    
    /**
     * Calculate Labor Cost - FIXED RATE PER SERVICE
     * ENFORCES: Fixed rate regardless of actual time spent
     */
    private function calculateLaborCost($job, $actual_hours = null) {
        // Get FIXED rate from service category (not hourly!)
        $stmt = $this->pdo->prepare("
            SELECT fixed_labor_rate, default_labor_cost
            FROM service_categories 
            WHERE id = ?
        ");
        $stmt->execute([$job['service_category_id']]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Use fixed rate if available, fallback to default labor cost
        $fixed_rate = $service['fixed_labor_rate'] ?? $service['default_labor_cost'] ?? 0;
        
        // FIXED RATE: Ignore actual hours completely
        return (float)$fixed_rate;
    }
    
    /**
     * Calculate Actual Duration
     */
    private function calculateActualDuration($job) {
        if ($job['started_at']) {
            $start = new DateTime($job['started_at']);
            $end = new DateTime();
            $interval = $start->diff($end);
            return ($interval->h * 60) + $interval->i; // Convert to minutes
        }
        return $job['estimated_duration'] ?? 60;
    }
    
    /**
      * Generate Job Order Number
      */
    private function generateJobOrderNumber($sequence = null) {
        $date = date('Y-m-d');
        
        if ($sequence === null) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) + 1 as next_number
                FROM job_orders
                WHERE DATE(created_at) = CURDATE()
                  AND station_id = ?
            ");
            $stmt->execute([$this->station_id]);
            $next = $stmt->fetchColumn();
        } else {
            $next = $sequence;
        }
        
        return 'JO-' . $date . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    private function isDuplicateKeyException($e) {
        return $e instanceof PDOException && 
               $e->errorInfo[0] === '23000' && // Integrity constraint violation
               ($e->errorInfo[1] === 1062 || $e->errorInfo[1] === 1586); // Duplicate entry
    }
    
    /**
     * Get Job Order Details
     */
    private function getJobOrderDetails($job_id, $bypass_station_filter = false) {
        if ($bypass_station_filter) {
            // For status updates - allow cross-station operations for authorized users
            $stmt = $this->pdo->prepare("
                SELECT jo.*, 
                       c.name as customer_name,
                       m.full_name as mechanic_name,
                       sc.name as service_category_name,
                       u.name as assigned_by_name
                FROM job_orders jo
                LEFT JOIN customers c ON c.id = jo.customer_id
                LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
                LEFT JOIN users u ON u.user_id = jo.assigned_by
                WHERE jo.id = ?
            ");
            $stmt->execute([$job_id]);
        } else {
            // Regular operation - filter by station for security
            $stmt = $this->pdo->prepare("
                SELECT jo.*, 
                       c.name as customer_name,
                       m.full_name as mechanic_name,
                       sc.name as service_category_name,
                       u.name as assigned_by_name
                FROM job_orders jo
                LEFT JOIN customers c ON c.id = jo.customer_id
                LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
                LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
                LEFT JOIN users u ON u.user_id = jo.assigned_by
                WHERE jo.id = ? AND jo.station_id = ?
            ");
            $stmt->execute([$job_id, $this->station_id]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get Job Orders by Status
     */
    public function getJobOrdersByStatus($status) {
        $stmt = $this->pdo->prepare("
            SELECT jo.*,
                   c.name as customer_name,
                   m.full_name as mechanic_name,
                   sc.name as service_category_name,
                   u.name as assigned_by_name
            FROM job_orders jo
            LEFT JOIN customers c ON c.id = jo.customer_id
            LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
            LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
            LEFT JOIN users u ON u.user_id = jo.assigned_by
            WHERE jo.station_id = ? AND jo.status = ?
            ORDER BY jo.created_at DESC
        ");
        $stmt->execute([$this->station_id, $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get Complete Job Order Details with Parts Used
     * Hybrid approach: Product name + inventory details
     */
    public function getJobDetailsWithParts($job_id) {
        // Get basic job order details
        $stmt = $this->pdo->prepare("
            SELECT jo.*,
                   c.name as customer_name,
                   c.phone as customer_phone,
                   c.email as customer_email,
                   m.full_name as mechanic_name,
                   sc.name as service_name,
                   u.name as created_by_name,
                   r.name as reviewed_by_name
            FROM job_orders jo
            LEFT JOIN customers c ON c.id = jo.customer_id
            LEFT JOIN mechanics m ON m.id = jo.assigned_mechanic_id
            LEFT JOIN service_categories sc ON sc.id = jo.service_category_id
            LEFT JOIN users u ON u.user_id = jo.assigned_by
            LEFT JOIN users r ON r.user_id = jo.reviewed_by
            WHERE jo.id = ? AND jo.station_id = ?
        ");
        $stmt->execute([$job_id, $this->station_id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return null;
        }

         // Get parts used with hybrid product information
         $stmt = $this->pdo->prepare("
             SELECT jop.*,
                    p.name as product_name,
                    p.type_id,
                    COALESCE(si.stock_level, fi.stock_level, 0) as current_stock
             FROM job_order_parts jop
             LEFT JOIN products p ON p.id = jop.product_id
             LEFT JOIN station_inventory si ON si.station_id = ? AND si.product_id = jop.product_id AND p.type_id = 2
             LEFT JOIN fuel_inventory fi ON fi.station_id = ? AND fi.product_id = jop.product_id AND p.type_id = 1
             WHERE jop.job_order_id = ?
             ORDER BY jop.id ASC
         ");
         $stmt->execute([$this->station_id, $this->station_id, $job_id]);
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add parts to job array
        $job['parts_used'] = $parts;

        // Calculate totals for breakdown
        $total_parts_cost = 0;
        foreach ($parts as $part) {
            $total_parts_cost += ($part['total_cost'] ?? 0);
        }
        $job['total_parts_cost'] = $total_parts_cost;

        return $job;
    }
}

// Handle API requests if called directly
if (basename($_SERVER['PHP_SELF']) === 'job_order_operations.php') {
    require_login();
    
    $user = current_user();
    $station_id = user_station_id();
    
    $jobOrderOps = new JobOrderOperations($pdo, $user, $station_id);
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'add_station_item':
                $result = $jobOrderOps->addStationItem($_POST);
                break;

            case 'get_station_items':
                $result = $jobOrderOps->getStationItems((string)($_POST['item_type'] ?? ''));
                break;

            case 'link_station_item':
                $result = $jobOrderOps->linkStationItemToJobOrder(
                    $_POST['job_id'] ?? 0,
                    $_POST['station_item_id'] ?? 0,
                    $_POST['quantity'] ?? 1,
                    $_POST['notes'] ?? null
                );
                break;

            case 'execute_station_item':
                $result = $jobOrderOps->executeLinkedJobItem(
                    $_POST['link_id'] ?? 0,
                    $_POST['execution_notes'] ?? null
                );
                break;

            case 'get_linked_job_items':
                $result = $jobOrderOps->getLinkedJobItems(
                    $_POST['job_id'] ?? null,
                    !empty($_POST['only_pending'])
                );
                break;

            case 'create_job_order':
                $result = $jobOrderOps->createJobOrder($_POST);
                break;
                
            case 'manager_review_approve':
                $result = $jobOrderOps->managerApproveJobOrder(
                    $_POST['job_id'],
                    'approve',
                    $_POST['remarks'] ?? null
                );
                break;
                
            case 'manager_review_reject':
                $result = $jobOrderOps->managerApproveJobOrder(
                    $_POST['job_id'],
                    'reject',
                    $_POST['remarks'] ?? null
                );
                break;
                
                
            case 'start_job_order':
                $result = $jobOrderOps->startJobOrder($_POST['job_id']);
                break;
                
            case 'complete_job_order':
                $parts_used = json_decode($_POST['parts_used'] ?? '[]', true);
                $result = $jobOrderOps->completeJobOrder(
                    $_POST['job_id'],
                    $parts_used,
                    $_POST['actual_labor_hours'] ?? 0
                );
                break;

            case 'get_job_details':
                $result = $jobOrderOps->getJobDetailsWithParts($_POST['job_id']);
                break;

            default:
                $result = ['success' => false, 'message' => 'Invalid action'];
        }
        
        json_response($result);
        
    } catch (Exception $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}
