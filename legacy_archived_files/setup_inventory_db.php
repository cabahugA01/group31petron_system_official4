<?php
/**
 * Inventory Database Setup - Web Interface
 * Run this once to setup inventory categories and related tables
 */
session_start();
require_once __DIR__ . '/db_connect.php';

// Check if user is admin/superadmin
$user = $_SESSION['user'] ?? null;
if (!$user || !in_array(strtolower($user['role'] ?? ''), ['admin', 'administrator', 'superadmin', 'super admin', 'developer'])) {
    die('Access Denied: Admin privileges required');
}

$action = $_GET['action'] ?? '';
$messages = [];
$success_count = 0;
$error_count = 0;

if ($action === 'setup') {
    // Run setup
    
    // 1. Create inventory_categories table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS inventory_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL UNIQUE,
            category_type ENUM('Fuel', 'Merchandise', 'Service', 'Other') DEFAULT 'Merchandise',
            description TEXT,
            parent_category_id INT NULL,
            display_order INT DEFAULT 0,
            icon VARCHAR(50) DEFAULT 'fa-box',
            color VARCHAR(20) DEFAULT '#3b82f6',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT NULL,
            FOREIGN KEY (parent_category_id) REFERENCES inventory_categories(id) ON DELETE SET NULL,
            INDEX idx_category_type (category_type),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($sql);
        $messages[] = ['type' => 'success', 'text' => 'inventory_categories table created/verified'];
        $success_count++;
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => 'Error creating inventory_categories: ' . $e->getMessage()];
        $error_count++;
    }
    
    // 2. Insert default categories
    $default_categories = [
        ['Fuel', 'Fuel', 'All fuel products', 'fa-gas-pump', '#dc2626'],
        ['Gasoline', 'Fuel', 'Gasoline products', 'fa-gas-pump', '#dc2626'],
        ['Diesel', 'Fuel', 'Diesel products', 'fa-gas-pump', '#ea580c'],
        ['Oils / Lubes / Grease', 'Merchandise', 'Engine oils, lubricants, and grease', 'fa-oil-can', '#0891b2'],
        ['Engine Oil', 'Merchandise', 'Engine oil products', 'fa-oil-can', '#0891b2'],
        ['Transmission Oil', 'Merchandise', 'Transmission fluid products', 'fa-oil-can', '#06b6d4'],
        ['Gear Oil', 'Merchandise', 'Gear oil products', 'fa-cog', '#0e7490'],
        ['Brake Fluid', 'Merchandise', 'Brake fluid products', 'fa-brake', '#b91c1c'],
        ['Coolant', 'Merchandise', 'Engine coolant / antifreeze', 'fa-temperature-low', '#3b82f6'],
        ['Oil / Fuel Filters', 'Merchandise', 'Oil and fuel filters', 'fa-filter', '#7c3aed'],
        ['Oil Filter', 'Merchandise', 'Engine oil filters', 'fa-filter', '#8b5cf6'],
        ['Fuel Filter', 'Merchandise', 'Fuel filters', 'fa-filter', '#a855f7'],
        ['Air Filter', 'Merchandise', 'Air filters', 'fa-wind', '#c084fc'],
        ['Maintenance', 'Merchandise', 'General maintenance items', 'fa-wrench', '#059669'],
        ['Spark Plugs', 'Merchandise', 'Spark plugs', 'fa-bolt', '#10b981'],
        ['Belts', 'Merchandise', 'Drive belts', 'fa-link', '#34d399'],
        ['Hoses', 'Merchandise', 'Radiator and fuel hoses', 'fa-slash', '#6ee7b7'],
        ['Car Accessories', 'Merchandise', 'General car accessories', 'fa-car', '#f59e0b'],
        ['Tire', 'Merchandise', 'Tires and tire products', 'fa-circle', '#1e293b'],
        ['Tire Sealant', 'Merchandise', 'Tire sealant products', 'fa-fill-drip', '#475569'],
        ['Car Wax', 'Merchandise', 'Car wax and polish', 'fa-star', '#fbbf24'],
        ['Car Wash', 'Merchandise', 'Car wash products', 'fa-soap', '#60a5fa'],
        ['Air Freshener', 'Merchandise', 'Air fresheners', 'fa-wind', '#a78bfa'],
        ['Brake System', 'Merchandise', 'Brake system components', 'fa-brake', '#dc2626'],
        ['Brake Pads', 'Merchandise', 'Brake pad sets', 'fa-brake', '#ef4444'],
        ['Brake Shoes', 'Merchandise', 'Brake shoe sets', 'fa-shoe-prints', '#f87171'],
        ['Others (Snacks / Drinks)', 'Merchandise', 'Convenience store items', 'fa-shopping-basket', '#16a34a'],
        ['Beverages', 'Merchandise', 'Drinks and beverages', 'fa-coffee', '#22c55e'],
        ['Soft Drinks', 'Merchandise', 'Carbonated soft drinks', 'fa-glass', '#4ade80'],
        ['Water', 'Merchandise', 'Bottled water', 'fa-tint', '#86efac'],
        ['Energy Drinks', 'Merchandise', 'Energy drinks', 'fa-bolt', '#bef264'],
        ['Snacks', 'Merchandise', 'Chips, crackers, snacks', 'fa-cookie', '#fbbf24'],
        ['Chips', 'Merchandise', 'Potato chips', 'fa-cookie-bite', '#fcd34d'],
        ['Biscuits', 'Merchandise', 'Biscuits and cookies', 'fa-cookie', '#fde047'],
        ['Candy', 'Merchandise', 'Candies and sweets', 'fa-candy-cane', '#fb923c'],
        ['Chocolate', 'Merchandise', 'Chocolate products', 'fa-square', '#78350f'],
        ['Instant Noodles', 'Merchandise', 'Cup noodles and instant meals', 'fa-bowl-food', '#fb7185'],
        ['Service', 'Service', 'Service offerings', 'fa-tools', '#6366f1'],
        ['Oil Change', 'Service', 'Oil change service', 'fa-oil-can', '#4f46e5'],
        ['Tire Service', 'Service', 'Tire repair and replacement', 'fa-circle', '#312e81'],
        ['Car Wash Service', 'Service', 'Vehicle washing service', 'fa-car-side', '#0ea5e9'],
        ['Uncategorized', 'Other', 'Uncategorized items', 'fa-question', '#6b7280'],
        ['Chemical Additives', 'Merchandise', 'Fuel and engine additives', 'fa-flask', '#ec4899'],
    ];
    
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM inventory_categories WHERE category_name = ?");
    $stmt_insert = $pdo->prepare("
        INSERT INTO inventory_categories 
            (category_name, category_type, description, icon, color, display_order, is_active, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)
    ");
    
    $category_count = 0;
    foreach ($default_categories as $index => $cat) {
        try {
            $stmt_check->execute([$cat[0]]);
            if ($stmt_check->fetchColumn() == 0) {
                $stmt_insert->execute([
                    $cat[0], // category_name
                    $cat[1], // category_type
                    $cat[2], // description
                    $cat[3], // icon
                    $cat[4], // color
                    $index,  // display_order
                    $user['id'] // created_by
                ]);
                $category_count++;
            }
        } catch (Exception $e) {
            // Skip duplicates
        }
    }
    
    if ($category_count > 0) {
        $messages[] = ['type' => 'success', 'text' => "Inserted $category_count default categories"];
        $success_count++;
    } else {
        $messages[] = ['type' => 'info', 'text' => 'All default categories already exist'];
    }
    
    // 3. Update inventory_products table
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM inventory_products LIKE 'category_id'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE inventory_products ADD COLUMN category_id INT NULL AFTER category");
            $pdo->exec("ALTER TABLE inventory_products ADD FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE SET NULL");
            $pdo->exec("ALTER TABLE inventory_products ADD INDEX idx_category_id (category_id)");
            $messages[] = ['type' => 'success', 'text' => 'Added category_id column to inventory_products'];
            $success_count++;
        } else {
            $messages[] = ['type' => 'info', 'text' => 'category_id column already exists in inventory_products'];
        }
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => 'Error updating inventory_products: ' . $e->getMessage()];
        $error_count++;
    }
    
    // 4. Map existing categories
    try {
        $pdo->exec("
            UPDATE inventory_products ip
            JOIN inventory_categories ic ON LOWER(TRIM(ip.category)) = LOWER(TRIM(ic.category_name))
            SET ip.category_id = ic.id
            WHERE ip.category_id IS NULL AND ip.category IS NOT NULL
        ");
        
        $mapped = $pdo->query("SELECT COUNT(*) FROM inventory_products WHERE category_id IS NOT NULL")->fetchColumn();
        $messages[] = ['type' => 'success', 'text' => "Mapped $mapped products to categories"];
        $success_count++;
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => 'Error mapping categories: ' . $e->getMessage()];
        $error_count++;
    }
}

// Get current stats
$stats = [];
try {
    $stmt = $pdo->query("SELECT category_type, COUNT(*) as count FROM inventory_categories WHERE is_active = 1 GROUP BY category_type");
    $stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['Total'] = $pdo->query("SELECT COUNT(*) FROM inventory_categories WHERE is_active = 1")->fetchColumn();
} catch (Exception $e) {
    $stats = ['Error' => $e->getMessage()];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Inventory Database Setup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 20px;
            min-height: 100vh;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #002F70;
            margin-bottom: 8px;
            font-size: 28px;
        }
        .subtitle {
            color: #64748b;
            margin-bottom: 32px;
            font-size: 14px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .stat-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        .stat-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #002F70;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #002F70, #00264D);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        .messages {
            margin-top: 32px;
        }
        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        .message.success {
            background: #dcfce7;
            border-left: 4px solid #16a34a;
            color: #15803d;
        }
        .message.error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            color: #b91c1c;
        }
        .message.info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            color: #1e40af;
        }
        .summary {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 20px;
            margin-top: 24px;
        }
        .summary h3 {
            color: #1e293b;
            margin-bottom: 12px;
            font-size: 16px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-database"></i> Inventory Database Setup</h1>
        <div class="subtitle">Setup and verify inventory categories and related tables</div>
        
        <div class="stats-grid">
            <?php foreach ($stats as $type => $count): ?>
            <div class="stat-card">
                <div class="stat-label"><?= htmlspecialchars($type) ?></div>
                <div class="stat-value"><?= number_format($count) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!empty($messages)): ?>
        <div class="messages">
            <?php foreach ($messages as $msg): ?>
            <div class="message <?= $msg['type'] ?>">
                <i class="fas fa-<?= $msg['type'] === 'success' ? 'check-circle' : ($msg['type'] === 'error' ? 'times-circle' : 'info-circle') ?>"></i>
                <span><?= htmlspecialchars($msg['text']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="summary">
            <h3>Setup Summary</h3>
            <div style="display:flex;gap:24px;font-size:14px;">
                <div><strong><i class="fas fa-check-circle"></i> Successful:</strong> <?= $success_count ?></div>
                <div><strong><i class="fas fa-times-circle"></i> Errors:</strong> <?= $error_count ?></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <?php if (empty($messages)): ?>
            <a href="?action=setup" class="btn">
                <i class="fas fa-play"></i> Run Setup
            </a>
            <?php else: ?>
            <a href="?" class="btn btn-secondary">
                <i class="fas fa-redo"></i> Refresh Status
            </a>
            <?php endif; ?>
            <a href="admin_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>
