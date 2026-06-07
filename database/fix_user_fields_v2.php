<?php
/**
 * Database Fix Script V2: Keep email in both username AND email fields
 * Login should work with email, phone, or username
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "=== User Fields Fix V2 ===\n\n";

try {
    $pdo->beginTransaction();
    
    // Fix User 21: stafftest@gmail.com
    echo "Fixing User 21 (stafftest):\n";
    $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = 21")
        ->execute(['stafftest@gmail.com', 'stafftest@gmail.com', '09916105744']);
    echo "  ✅ Username: stafftest@gmail.com\n";
    echo "  ✅ Email: stafftest@gmail.com\n";
    echo "  ✅ Phone: 09916105744\n\n";
    
    // Fix User 23: pepito@gmail.com
    echo "Fixing User 23 (pepito):\n";
    $pdo->prepare("UPDATE users SET username = ?, email = ?, phone = ? WHERE id = 23")
        ->execute(['pepito@gmail.com', 'pepito@gmail.com', '09095332320']);
    echo "  ✅ Username: pepito@gmail.com\n";
    echo "  ✅ Email: pepito@gmail.com\n";
    echo "  ✅ Phone: 09095332320\n\n";
    
    $pdo->commit();
    
    echo "✅ All users fixed!\n\n";
    
    // Show final state
    echo "=== Final User Records ===\n\n";
    $stmt = $pdo->query("SELECT id, username, email, phone, role FROM users ORDER BY id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['id']} | Role: " . strtoupper($row['role']) . "\n";
        echo "  Username: " . ($row['username'] ?? 'NULL') . "\n";
        echo "  Email:    " . ($row['email'] ?? 'NULL') . "\n";
        echo "  Phone:    " . ($row['phone'] ?? 'NULL') . "\n";
        echo "  ---\n";
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
