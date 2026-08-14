<?php
global $pdo;

$host = "localhost";
$dbname = "petron_pos_db_secure";
$user = "root";
$pass = ""; // XAMPP default is empty

try {
  $pdo = new PDO(
    "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (PDOException $e) {
  die("DB connection failed: " . $e->getMessage());
}

// ── Self-healing Database schema for pending_price_approvals ────────────────
try {
    // Create table if it doesn't exist at all (full correct schema)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_price_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        product_type VARCHAR(50) NOT NULL DEFAULT 'merchandise',
        product_id INT NOT NULL DEFAULT 0,
        product_name VARCHAR(255) DEFAULT NULL,
        field_name VARCHAR(100) DEFAULT NULL,
        old_cost DECIMAL(12,2) DEFAULT 0,
        new_cost DECIMAL(12,2) DEFAULT 0,
        old_price DECIMAL(12,2) DEFAULT 0,
        new_price DECIMAL(12,2) DEFAULT 0,
        old_value DECIMAL(12,2) DEFAULT 0,
        new_value DECIMAL(12,2) DEFAULT 0,
        manager_id INT DEFAULT NULL,
        requested_by INT DEFAULT NULL,
        admin_id INT DEFAULT NULL,
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        rejection_reason TEXT DEFAULT NULL,
        reviewer_notes TEXT DEFAULT NULL,
        fuel_type_id INT DEFAULT NULL,
        service_type_id INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_station (station_id),
        INDEX idx_status (status),
        INDEX idx_product (product_type, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add any missing columns to existing table
    $check_cols = $pdo->query("SHOW COLUMNS FROM pending_price_approvals");
    $cols = $check_cols->fetchAll(PDO::FETCH_COLUMN);

    $add_cols = [
        'old_cost'         => "DECIMAL(12,2) DEFAULT 0",
        'new_cost'         => "DECIMAL(12,2) DEFAULT 0",
        'old_price'        => "DECIMAL(12,2) DEFAULT 0",
        'new_price'        => "DECIMAL(12,2) DEFAULT 0",
        'old_value'        => "DECIMAL(12,2) DEFAULT 0",
        'new_value'        => "DECIMAL(12,2) DEFAULT 0",
        'manager_id'       => "INT DEFAULT NULL",
        'requested_by'     => "INT DEFAULT NULL",
        'admin_id'         => "INT DEFAULT NULL",
        'reviewed_by'      => "INT DEFAULT NULL",
        'reviewed_at'      => "DATETIME DEFAULT NULL",
        'rejection_reason' => "TEXT DEFAULT NULL",
        'reviewer_notes'   => "TEXT DEFAULT NULL",
        'fuel_type_id'     => "INT DEFAULT NULL",
        'service_type_id'  => "INT DEFAULT NULL",
        'updated_at'       => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'product_name'     => "VARCHAR(255) DEFAULT NULL",
        'field_name'       => "VARCHAR(100) DEFAULT NULL",
    ];
    foreach ($add_cols as $col => $def) {
        if (!in_array($col, $cols)) {
            try {
                $pdo->exec("ALTER TABLE pending_price_approvals ADD COLUMN `$col` $def");
            } catch (Exception $ae) { /* column might already exist */ }
        }
    }
    // Drop foreign key constraints that would prevent inserting fuel or service products into pending_price_approvals
    try {
        $pdo->exec("ALTER TABLE pending_price_approvals DROP FOREIGN KEY fk_pending_price_approvals_product");
    } catch (Exception $e) { /* doesn't exist or already dropped */ }
    try {
        $pdo->exec("ALTER TABLE pending_price_approvals DROP FOREIGN KEY fk_pending_price_approvals_product_id");
    } catch (Exception $e) { /* doesn't exist or already dropped */ }

} catch (Exception $db_err) {
    error_log("Db self-heal error: " . $db_err->getMessage());
}

// ── Self-healing Database schema for vehicle_inspection_items ────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_inspection_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_name VARCHAR(100) NOT NULL UNIQUE,
        category VARCHAR(50) DEFAULT 'General',
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Populate default items if empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_inspection_items")->fetchColumn();
    if ($count === 0) {
        $default_items = [
            'Engine',
            'Battery',
            'Tires',
            'Brakes',
            'Lights',
            'Cooling System',
            'Suspension',
            'Transmission Fluid',
            'Air Filter',
            'Wipers & Washers',
            'Belts & Hoses',
            'Steering System',
            'Exhaust System'
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO vehicle_inspection_items (item_name) VALUES (?)");
        foreach ($default_items as $item) {
            $stmt->execute([$item]);
        }
    }
} catch (Exception $e) {
    error_log("vehicle_inspection_items self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for customer_credit_transactions ────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_credit_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        station_id INT NOT NULL DEFAULT 1,
        customer_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(50) DEFAULT 'Credit Payment',
        remarks TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_customer (customer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("customer_credit_transactions self-healing error: " . $e->getMessage());
}

// ── Self-healing Database schema for stock_request_audit & fuel_stock_request_audit ──
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_request_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stock_request_id INT NULL,
        request_id INT NULL,
        action_type VARCHAR(100) NOT NULL,
        performed_by INT NULL,
        performed_by_role VARCHAR(50) NULL,
        old_status VARCHAR(100) NULL,
        new_status VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_stock_req_id (stock_request_id),
        INDEX idx_req_id (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fuel_stock_request_audit (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NULL,
        action_type VARCHAR(100) NOT NULL,
        performed_by INT NULL,
        performed_by_role VARCHAR(50) NULL,
        old_status VARCHAR(100) NULL,
        new_status VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_fuel_req_id (request_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log("stock_request_audit self-healing error: " . $e->getMessage());
}
