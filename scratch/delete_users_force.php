<?php
require __DIR__ . '/../public/db_connect.php';

$userIds = [5, 6, 8];

try {
    $pdo->beginTransaction();

    // 1. Delete from password_reset_tokens
    $stmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id IN (5, 6, 8)");
    $stmt->execute();
    echo "Deleted password reset tokens for users " . implode(', ', $userIds) . ".\n";

    // 2. Let's see if user_activity_logs exists and has fk constraints
    // Try to delete from user_activity_logs or check if it references user_id
    try {
        $stmt = $pdo->prepare("DELETE FROM user_activity_logs WHERE user_id IN (5, 6, 8)");
        $stmt->execute();
        echo "Deleted user activity logs for users.\n";
    } catch (Exception $ex) {
        echo "Note on user_activity_logs: " . $ex->getMessage() . "\n";
    }

    // 3. Delete from users
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
