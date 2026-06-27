<?php
$pdo = new PDO('mysql:host=localhost;dbname=petron_pos_db_secure', 'root', '');

// Judy Lastimosa → Shift 1
$pdo->prepare("UPDATE users SET assigned_shift = 'Shift 1' WHERE id = 2")->execute();
echo "Updated Judy Lastimosa (id=2) → Shift 1\n";

// Yyang Cabahug → Shift 2
$pdo->prepare("UPDATE users SET assigned_shift = 'Shift 2' WHERE id = 7")->execute();
echo "Updated Yyang Cabahug (id=7) → Shift 2\n";

// Verify
$stmt = $pdo->query("SELECT id, first_name, last_name, role, assigned_shift FROM users WHERE id IN (2, 7)");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "  [{$u['id']}] {$u['first_name']} {$u['last_name']} ({$u['role']}) → {$u['assigned_shift']}\n";
}
echo "\nDone!\n";
