<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user'] = ['id' => 1, 'role' => 'staff', 'station_id' => 1];

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';

try {
    $search = '';
    $station_id = 1;
    $where = ['c.station_id = ?'];
    $params = [$station_id];
    $wc = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT c.id,
            COALESCE(c.customer_id, CAST(c.id AS CHAR)) AS customer_id,
            COALESCE(c.first_name,'') AS first_name,
            COALESCE(c.middle_name,'') AS middle_name,
            COALESCE(c.last_name,'') AS last_name,
            COALESCE(c.contact_number,'') AS contact_number,
            COALESCE(c.customer_type,'walk-in') AS customer_type,
            COALESCE(c.status,'active') AS status,
            COALESCE(c.registered_at, c.created_at) AS registered_at,
            (SELECT COUNT(*) FROM merchandise_transactions mt WHERE mt.customer_id = c.id AND mt.station_id = c.station_id) +
            (SELECT COUNT(*) FROM job_orders jo WHERE jo.customer_id = c.id AND jo.station_id = c.station_id)
            AS total_transactions
        FROM customers c
        WHERE $wc
        ORDER BY c.registered_at DESC, c.id DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "SUCCESS: Found " . count($rows) . " rows\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
