<?php
require __DIR__ . '/../public/db_connect.php';

try {
    $pdo->beginTransaction();

    // Delete users with IDs 5, 6, and 8
    $stmt = $pdo->prepare("DELETE FROM users WHERE id IN (5, 6, 8)");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    echo "Deleted $deletedCount users permanently from the database.\n";

    $pdo->commit();

    // Verify remaining users
    $stmtVerify = $pdo->query("SELECT id, first_name, last_name, username, role, assigned_shift FROM users");
    $users = $stmtVerify->fetchAll(PDO::FETCH_ASSOC);
    echo "\n--- CURRENT USERS IN DATABASE ---\n";
    echo json_encode($users, JSON_PRETTY_PRINT) . "\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage() . "\n";
}
