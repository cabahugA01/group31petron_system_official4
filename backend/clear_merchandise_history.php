<?php
/**
 * Clear Merchandise Transaction History
 * 
 * WARNING: This script will DELETE ALL merchandise transaction records.
 * This action is IRREVERSIBLE.
 * 
 * Use this to reset the merchandise history for fresh data entry.
 */

require_once __DIR__ . '/../public/db_connect.php';

// Prevent accidental execution - require confirmation parameter
$confirm = $_GET['confirm'] ?? '';

if ($confirm !== 'YES_DELETE_ALL') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Clear Merchandise History</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #f8f9fa;
            }
            .warning-box {
                background: #fff3cd;
                border: 2px solid #ffc107;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .warning-box h2 {
                color: #856404;
                margin-top: 0;
            }
            .warning-box p {
                color: #856404;
                line-height: 1.6;
            }
            .danger-box {
                background: #f8d7da;
                border: 2px solid #dc3545;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .danger-box h3 {
                color: #721c24;
                margin-top: 0;
            }
            .danger-box ul {
                color: #721c24;
                line-height: 1.8;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                margin-right: 10px;
                cursor: pointer;
                border: none;
                font-size: 14px;
            }
            .btn-danger {
                background: #dc3545;
                color: white;
            }
            .btn-danger:hover {
                background: #c82333;
            }
            .btn-secondary {
                background: #6c757d;
                color: white;
            }
            .btn-secondary:hover {
                background: #5a6268;
            }
            .info-box {
                background: #d1ecf1;
                border: 1px solid #bee5eb;
                border-radius: 8px;
                padding: 15px;
                margin-top: 20px;
            }
            .info-box h4 {
                color: #0c5460;
                margin-top: 0;
            }
            .info-box ul {
                color: #0c5460;
                margin-bottom: 0;
            }
        </style>
    </head>
    <body>
        <div class="warning-box">
            <h2>⚠️ WARNING: Delete All Merchandise History</h2>
            <p>You are about to <strong>permanently delete ALL merchandise transaction records</strong> from the database.</p>
            <p>This action cannot be undone!</p>
        </div>

        <div class="danger-box">
            <h3>🚨 What will be deleted:</h3>
            <ul>
                <li><strong>All merchandise transactions</strong> (merchandise_transactions table)</li>
                <li><strong>All transaction line items</strong> (merchandise_transaction_items table)</li>
                <li>Transaction history in staff view</li>
                <li>Transaction history in manager validation</li>
                <li>All pending, approved, and rejected merchandise records</li>
            </ul>
            <p><strong style="color: #721c24;">This will remove ALL historical data. Reports will show no data after this operation.</strong></p>
        </div>

        <div class="info-box">
            <h4>✅ What will NOT be affected:</h4>
            <ul>
                <li>Product inventory (inventory_products)</li>
                <li>Station inventory levels (station_inventory)</li>
                <li>Customer records</li>
                <li>User accounts</li>
                <li>Fuel transactions</li>
                <li>Job orders</li>
                <li>System settings</li>
            </ul>
        </div>

        <div style="margin-top: 30px;">
            <a href="?confirm=YES_DELETE_ALL" class="btn btn-danger" 
               onclick="return confirm('Are you ABSOLUTELY SURE you want to delete ALL merchandise history? This cannot be undone!');">
                🗑️ Yes, Delete All Merchandise History
            </a>
            <a href="../public/staff_transactions_hub.php" class="btn btn-secondary">
                ← Cancel and Go Back
            </a>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: white; border-radius: 8px; border: 1px solid #dee2e6;">
            <p style="margin: 0; color: #6c757d; font-size: 13px;">
                <strong>Alternative:</strong> If you only want to test with fresh data, consider creating a test/development station instead of deleting production data.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// CONFIRMATION RECEIVED - PROCEED WITH DELETION
// ═══════════════════════════════════════════════════════════════════════════

try {
    $pdo->beginTransaction();
    
    // Count records before deletion
    $count_transactions = $pdo->query("SELECT COUNT(*) FROM merchandise_transactions")->fetchColumn();
    $count_items = $pdo->query("SELECT COUNT(*) FROM merchandise_transaction_items")->fetchColumn();
    
    // Delete all merchandise transaction items first (foreign key constraint)
    $pdo->exec("DELETE FROM merchandise_transaction_items");
    
    // Delete all merchandise transactions
    $pdo->exec("DELETE FROM merchandise_transactions");
    
    // Reset auto-increment counters (optional - starts IDs from 1 again)
    $pdo->exec("ALTER TABLE merchandise_transactions AUTO_INCREMENT = 1");
    $pdo->exec("ALTER TABLE merchandise_transaction_items AUTO_INCREMENT = 1");
    
    $pdo->commit();
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Deletion Complete</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #f8f9fa;
            }
            .success-box {
                background: #d4edda;
                border: 2px solid #28a745;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .success-box h2 {
                color: #155724;
                margin-top: 0;
            }
            .success-box p {
                color: #155724;
                line-height: 1.6;
            }
            .stats {
                background: white;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
            }
            .stats h3 {
                margin-top: 0;
                color: #495057;
            }
            .stats ul {
                color: #6c757d;
                line-height: 1.8;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                background: #007bff;
                color: white;
            }
            .btn:hover {
                background: #0056b3;
            }
        </style>
    </head>
    <body>
        <div class="success-box">
            <h2>✅ Merchandise History Cleared Successfully</h2>
            <p>All merchandise transaction records have been permanently deleted from the database.</p>
        </div>

        <div class="stats">
            <h3>📊 Deletion Summary:</h3>
            <ul>
                <li><strong><?php echo number_format($count_transactions); ?></strong> merchandise transactions deleted</li>
                <li><strong><?php echo number_format($count_items); ?></strong> transaction line items deleted</li>
                <li>Auto-increment counters reset</li>
                <li>Database is now clean and ready for fresh data</li>
            </ul>
        </div>

        <div style="background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <p style="margin: 0; color: #0c5460;">
                <strong>Note:</strong> Product inventory, customer records, and all other data remain intact. Only merchandise transaction history was removed.
            </p>
        </div>

        <a href="../public/staff_transactions_hub.php" class="btn">
            → Go to Staff Transactions
        </a>
    </body>
    </html>
    <?php
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background: #f8f9fa;
            }
            .error-box {
                background: #f8d7da;
                border: 2px solid #dc3545;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .error-box h2 {
                color: #721c24;
                margin-top: 0;
            }
            .error-box p {
                color: #721c24;
                line-height: 1.6;
            }
            .error-box code {
                background: #f5c6cb;
                padding: 10px;
                border-radius: 4px;
                display: block;
                margin-top: 10px;
                font-size: 12px;
            }
            .btn {
                display: inline-block;
                padding: 12px 24px;
                border-radius: 6px;
                text-decoration: none;
                font-weight: 600;
                background: #6c757d;
                color: white;
            }
            .btn:hover {
                background: #5a6268;
            }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h2>❌ Error During Deletion</h2>
            <p>An error occurred while deleting merchandise history. The operation has been rolled back and no changes were made.</p>
            <p><strong>Error message:</strong></p>
            <code><?php echo htmlspecialchars($e->getMessage()); ?></code>
        </div>

        <a href="../public/staff_transactions_hub.php" class="btn">
            ← Go Back
        </a>
    </body>
    </html>
    <?php
}
?>
