<?php
require_once __DIR__ . '/../public/db_connect.php';
// Fix staff who have shift_assignment but no shift_start_time/end_time
$rows = $pdo->query("SELECT id, assigned_shift, shift_assignment FROM users WHERE role='staff' AND (shift_start_time IS NULL OR shift_start_time='00:00:00') AND assigned_shift IN ('Shift 1','Shift 2')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $shift = $r['assigned_shift'];
    $start = ($shift === 'Shift 1') ? '06:00:00' : '14:00:00';
    $end   = ($shift === 'Shift 1') ? '14:00:00' : '00:00:00';
    $pdo->prepare("UPDATE users SET shift_start_time=?, shift_end_time=? WHERE id=?")->execute([$start, $end, $r['id']]);
    echo "Fixed ID {$r['id']}: $shift -> start=$start end=$end" . PHP_EOL;
}
echo "Done." . PHP_EOL;
