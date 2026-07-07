<?php
/**
 * Fix service status: Set proper status values based on active column
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

try {
    // Update status based on active column
    // If active = 1, set status = 'active'
    // If active = 0, set status = 'inactive'
    
    $stmt = $pdo->exec("
        UPDATE job_order_service_types 
        SET status = CASE 
            WHEN active = 1 THEN 'active'
            ELSE 'inactive'
        END
        WHERE status IS NULL OR status = ''
    ");
    
    echo "✅ Fixed service status for {$stmt} service type(s)\n\n";
    
    // Show updated status
    $stmt = $pdo->query("SELECT id, service_name, status, active FROM job_order_service_types ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Updated Service Type Status:\n";
    echo str_repeat("=", 80) . "\n";
    printf("%-5s | %-30s | %-10s | %-6s\n", "ID", "Service Name", "Status", "Active");
    echo str_repeat("-", 80) . "\n";
    
    foreach ($services as $svc) {
        printf("%-5s | %-30s | %-10s | %-6s\n", 
            $svc['id'], 
            $svc['service_name'], 
            $svc['status'], 
            $svc['active']
        );
    }
    
    echo str_repeat("=", 80) . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
