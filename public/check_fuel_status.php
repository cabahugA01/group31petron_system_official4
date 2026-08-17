<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../backend/lib.php';
require_login();

require_once __DIR__ . '/db_connect.php';
$rows = $pdo->query("SELECT id, station_id, fuel_type, status FROM fuel_inventory ORDER BY station_id, id")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='6' style='font-family:monospace;font-size:13px;border-collapse:collapse'>";
echo "<tr style='background:#002F70;color:#fff'><th>ID</th><th>Station</th><th>Fuel Type</th><th>Status (raw)</th></tr>";
foreach ($rows as $r) {
    $c = $r['status'] === 'inactive' ? '#dc2626' : ($r['status'] === 'active' ? '#16a34a' : '#d97706');
    echo "<tr><td>{$r['id']}</td><td>{$r['station_id']}</td><td>" . htmlspecialchars($r['fuel_type']) . "</td><td style='color:{$c};font-weight:700'>" . htmlspecialchars($r['status']) . "</td></tr>";
}
echo "</table>";
echo "<p style='font-family:sans-serif'>Click Deactivate on a fuel, then visit this URL again to check if it saved.</p>";
echo "<p><a href='manager_set_prices.php?tab=fuel'>← Back</a></p>";
