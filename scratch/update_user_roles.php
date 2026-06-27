<?php
require __DIR__ . '/../public/db_connect.php';

try {
    $pdo->beginTransaction();

    // 1. Change Yyang Cabahug (id 7) to role = 'staff', assigned_shift = 'Shift 2'
    $stmt1 = $pdo->prepare("UPDATE users SET role = 'staff', assigned_shift = 'Shift 2' WHERE id = 7");
    $stmt1->execute();
    echo "Updated Yyang Cabahug (ID 7) to Staff / Shift 2.\n";

    // 2. Change Jen Cruz (id 5) and King Perez (id 6) to role = 'staff'
    $stmt2 = $pdo->prepare("UPDATE users SET role = 'staff', assigned_shift = 'Shift 1' WHERE id IN (5, 6)");
    $stmt2->execute();
    echo "Updated Jen Cruz (ID 5) and King Perez (ID 6) to Staff.\n";

    $pdo->commit();

    // Fetch updated users to verify
    $stmt = $pdo->query("SELECT id, first_name, last_name, username, role, assigned_shift FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n--- VERIFICATION OF USERS ---\n";
    echo json_encode($users, JSON_PRETTY_PRINT) . "\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
