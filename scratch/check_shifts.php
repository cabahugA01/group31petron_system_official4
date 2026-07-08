<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

// Show all active labor_sessions
$stmt = $pdo->query("
    SELECT ls.id, ls.user_id, ls.shift_period, ls.start_time, ls.end_time,
           u.username, u.first_name, u.last_name
    FROM labor_sessions ls
    LEFT JOIN users u ON u.id = ls.user_id
    ORDER BY ls.start_time DESC
    LIMIT 20
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:8px;text-align:left}th{background:#eee}</style>";
echo "<h2>labor_sessions (last 20)</h2><table>";
echo "<tr><th>ID</th><th>user_id</th><th>username</th><th>Name</th><th>shift_period</th><th>start_time</th><th>end_time</th></tr>";
foreach ($rows as $r) {
    $end = $r['end_time'] ?? '<span style="color:green;font-weight:bold">ACTIVE</span>';
    echo "<tr>
        <td>{$r['id']}</td>
        <td>{$r['user_id']}</td>
        <td>{$r['username']}</td>
        <td>{$r['first_name']} {$r['last_name']}</td>
        <td><b>{$r['shift_period']}</b></td>
        <td>{$r['start_time']}</td>
        <td>{$end}</td>
    </tr>";
}
echo "</table>";

// Also show users named judy and yyang
echo "<h2>Users: Judy / Yyang</h2><table>";
echo "<tr><th>id</th><th>username</th><th>first_name</th><th>last_name</th><th>role</th></tr>";
$stmt2 = $pdo->query("SELECT id, username, first_name, last_name, role FROM users WHERE username LIKE '%yyang%' OR username LIKE '%judy%' OR first_name LIKE '%judy%' OR first_name LIKE '%yyang%' OR last_name LIKE '%lastimosa%'");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "<tr><td>{$u['id']}</td><td>{$u['username']}</td><td>{$u['first_name']}</td><td>{$u['last_name']}</td><td>{$u['role']}</td></tr>";
}
echo "</table>";
