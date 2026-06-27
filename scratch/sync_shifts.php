<?php
/**
 * Sync assigned_shift -> shift_assignment and set shift_start_time/shift_end_time
 * for all existing staff who have assigned_shift but empty shift_assignment
 */
require_once __DIR__ . '/../public/db_connect.php';

$users = $pdo->query("SELECT id, assigned_shift, shift_assignment FROM users WHERE role = 'staff'")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;
foreach ($users as $u) {
    $as = $u['assigned_shift'];
    $sa = $u['shift_assignment'];
    // If assigned_shift is set but shift_assignment is empty, sync it
    if ($as && empty(trim($sa))) {
        $start = ($as === 'Shift 1') ? '06:00:00' : (($as === 'Shift 2') ? '14:00:00' : null);
        $end   = ($as === 'Shift 1') ? '14:00:00' : (($as === 'Shift 2') ? '00:00:00' : null);
        $stmt = $pdo->prepare("UPDATE users SET shift_assignment = ?, shift_start_time = ?, shift_end_time = ? WHERE id = ?");
        $stmt->execute([$as, $start, $end, $u['id']]);
        $updated++;
        echo "Updated user ID {$u['id']}: assigned_shift=$as, shift_assignment=$as" . PHP_EOL;
    }
}
echo PHP_EOL . "Done. $updated user(s) updated." . PHP_EOL;
