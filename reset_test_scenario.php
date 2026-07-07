<?php
/**
 * Test Scenario Database Reset
 * Resets database and sets up users and products for Scenario 1
 */
header('Content-Type: text/plain');

$host = "localhost";
$dbname = "petron_pos_db_secure";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "============================================================\n";
    echo "RESETTING DATABASE FOR SCENARIO 1\n";
    echo "============================================================\n\n";

    // Clear previous tests
    $pdo->exec("DELETE FROM transaction_adjustments WHERE customer_name = 'Juan Dela Cruz'");
    $pdo->exec("DELETE FROM voided_transactions WHERE customer_name = 'Juan Dela Cruz'");
    $pdo->exec("DELETE FROM job_orders WHERE customer_name = 'Juan Dela Cruz'");
    
    // Find previous combined transaction IDs to delete items
    $prev_ids_stmt = $pdo->prepare("SELECT id FROM merchandise_transactions WHERE customer_name = 'Juan Dela Cruz'");
    $prev_ids_stmt->execute();
    $prev_ids = $prev_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($prev_ids)) {
        $in_clause = implode(',', array_fill(0, count($prev_ids), '?'));
        $pdo->prepare("DELETE FROM merchandise_transaction_items WHERE transaction_id IN ($in_clause)")->execute($prev_ids);
        $pdo->prepare("DELETE FROM merchandise_transactions WHERE id IN ($in_clause)")->execute($prev_ids);
    }
    echo "  - Cleaned up previous test records for 'Juan Dela Cruz'\n";

    // Ensure Judy (Staff), Edgar (Manager), Kathrine (Admin) passwords are password123
    $users_to_update = ['Judy' => 'staff', 'Edgar' => 'manager', 'Kathrine' => 'admin'];
    foreach ($users_to_update as $username => $role) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $uid = $stmt->fetchColumn();
        if ($uid) {
            $hashed = password_hash('password123', PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash = ?, role = ? WHERE id = ?")->execute([$hashed, $role, $uid]);
            echo "  - Updated password_hash & role for $username ($role)\n";
        } else {
            // Create user if not exists
            $hashed = password_hash('password123', PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username, password_hash, first_name, last_name, role, station_id, status, created_at) VALUES (?, ?, ?, ?, ?, 1, 'Active', NOW())")
                ->execute([$username, $hashed, $username, 'User', $role]);
            echo "  - Created user $username ($role)\n";
        }
    }

    // Ensure correct assigned_shift for staff accounts
    $pdo->prepare("UPDATE users SET assigned_shift = 'Shift 1' WHERE username = 'Judy'")->execute();
    echo "  - Set Judy Lastimosa → Shift 1\n";
    $pdo->prepare("UPDATE users SET assigned_shift = 'Shift 2' WHERE username = 'yyangcabahug@gmail.com'")->execute();
    echo "  - Set Yyang Cabahug → Shift 2\n";

    // Get user IDs
    $judy_id = (int)$pdo->query("SELECT id FROM users WHERE username = 'Judy'")->fetchColumn();
    $yyang_id = (int)$pdo->query("SELECT id FROM users WHERE username = 'yyangcabahug@gmail.com'")->fetchColumn();

    // Ensure active shift labor session exists for Judy
    $pdo->prepare("DELETE FROM labor_sessions WHERE user_id = ? AND end_time IS NULL")->execute([$judy_id]);
    $pdo->prepare("INSERT INTO labor_sessions (user_id, station_id, start_time, shift_name, shift_period) VALUES (?, 1, NOW(), 'Shift 1', 'first')")
        ->execute([$judy_id]);
    echo "  - Created active Shift 1 labor session for Judy\n";

    // Only create Yyang labor session if she exists
    if ($yyang_id) {
        $pdo->prepare("DELETE FROM labor_sessions WHERE user_id = ? AND end_time IS NULL")->execute([$yyang_id]);
        $pdo->prepare("INSERT INTO labor_sessions (user_id, station_id, start_time, shift_name, shift_period) VALUES (?, 1, NOW(), 'Shift 2', 'second')")
            ->execute([$yyang_id]);
        echo "  - Created active Shift 2 labor session for Yyang\n";
    }

    // Ensure Products exist in database
    $products = [
        ['name' => 'Petron Engine Oil', 'category' => 'Lubricants', 'price' => 450.00],
        ['name' => 'Coolant', 'category' => 'Fluids', 'price' => 300.00]
    ];
    $product_ids = [];
    foreach ($products as $p) {
        $stmt = $pdo->prepare("SELECT id FROM inventory_products WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND station_id = 1");
        $stmt->execute([$p['name']]);
        $pid = $stmt->fetchColumn();
        if (!$pid) {
            $pdo->prepare("INSERT INTO inventory_products (product_name, category, unit_price, status, station_id, created_at) VALUES (?, ?, ?, 'active', 1, NOW())")
                ->execute([$p['name'], $p['category'], $p['price']]);
            $pid = $pdo->lastInsertId();
            echo "  - Inserted product: {$p['name']}\n";
        } else {
            $pdo->prepare("UPDATE inventory_products SET unit_price = ? WHERE id = ? AND station_id = 1")->execute([$p['price'], $pid]);
        }
        $product_ids[$p['name']] = (int)$pid;

        // Ensure stock exists in station 1
        $stock_stmt = $pdo->prepare("SELECT stock_level FROM station_inventory WHERE station_id = 1 AND product_id = ?");
        $stock_stmt->execute([$pid]);
        if ($stock_stmt->fetchColumn() === false) {
            $pdo->prepare("INSERT INTO station_inventory (station_id, product_id, stock_level, last_updated) VALUES (1, ?, 100.00, NOW())")
                ->execute([$pid]);
        } else {
            $pdo->prepare("UPDATE station_inventory SET stock_level = 100.00, last_updated = NOW() WHERE station_id = 1 AND product_id = ?")
                ->execute([$pid]);
        }
    }
    echo "  - Ensured 'Petron Engine Oil' and 'Coolant' exist with 100 units of stock.\n\n";
    echo "============================================================\n";
    echo "RESET COMPLETED! The database is now ready for testing.\n";
    echo "============================================================\n";

} catch (Exception $e) {
    echo "ERROR RUNNING RESET: " . $e->getMessage() . "\n";
}
