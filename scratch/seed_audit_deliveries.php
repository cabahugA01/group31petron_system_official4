<?php
require_once __DIR__ . '/../public/db_connect.php';

echo "Cleaning existing audit logs for fuel_deliveries...\n";
$pdo->exec("DELETE FROM audit_logs WHERE entity_type = 'fuel_deliveries'");

$deliveries = $pdo->query("SELECT id, status, invoice_no, received_by, verified_by FROM fuel_deliveries WHERE station_id = 1253")->fetchAll(PDO::FETCH_ASSOC);

foreach ($deliveries as $del) {
    // 1. Initial entry: Staff recorded the receipt of delivery
    $stmt = $pdo->prepare("INSERT INTO audit_logs 
        (user_id, log_type, action_type, action_details, entity_type, entity_id, ip_address, status) 
        VALUES (?, 'system', 'Recorded Delivery', ?, 'fuel_deliveries', ?, '127.0.0.1', 'Success')");
    
    $stmt->execute([
        $del['received_by'] ?: 2, // Judy
        "Staff recorded fuel receipt for DR: {$del['invoice_no']}.",
        $del['id']
    ]);

    // 2. Action based on status
    $st = strtolower($del['status']);
    if ($st === 'verified') {
        $stmt = $pdo->prepare("INSERT INTO audit_logs 
            (user_id, log_type, action_type, action_details, entity_type, entity_id, ip_address, status) 
            VALUES (?, 'system', 'Verified Delivery', ?, 'fuel_deliveries', ?, '127.0.0.1', 'Success')");
        $stmt->execute([
            $del['verified_by'] ?: 3, // Edgar
            "Manager verified and approved fuel delivery DR: {$del['invoice_no']}.",
            $del['id']
        ]);
    } elseif ($st === 'rejected') {
        $stmt = $pdo->prepare("INSERT INTO audit_logs 
            (user_id, log_type, action_type, action_details, entity_type, entity_id, ip_address, status) 
            VALUES (?, 'system', 'Rejected Delivery', ?, 'fuel_deliveries', ?, '127.0.0.1', 'Success')");
        $stmt->execute([
            $del['verified_by'] ?: 3, // Edgar
            "Manager rejected fuel delivery DR: {$del['invoice_no']} due to discrepancy/quality standards.",
            $del['id']
        ]);
    }
}

echo "Successfully seeded audit logs for " . count($deliveries) . " fuel deliveries.\n";
