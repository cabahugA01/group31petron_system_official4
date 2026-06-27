<?php
require __DIR__ . '/../public/db_connect.php';

$userIds = [9];

try {
    $pdo->beginTransaction();

    // 1. Delete from password_reset_tokens
    $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id IN (9)");
    $stmt->execute();

    // 2. Delete from user_activity_logs
    try {
        $stmt = $pdo->prepare("DELETE FROM user_activity_logs WHERE user_id IN (9)");
        $stmt->execute();
    } catch (Exception $ex) {}

    // 3. Delete from users
    $stmt = $pdo->prepare("DELETE FROM users WHERE id IN (9)");
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
