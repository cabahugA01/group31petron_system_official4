<?php
/**
 * Staff Customer Export
 * Export customer data to Excel/CSV
 */

require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = user_station_id();

// Staff only
if (!in_array($role, ['staff', 'superadmin', 'developer'])) {
    die('Unauthorized');
}

$format = $_GET['format'] ?? 'excel';
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

try {
    // Check if customers table exists
    try {
        $pdo->query("SELECT 1 FROM customers LIMIT 1");
    } catch (Exception $e) {
        die('Customers table does not exist');
    }
    
    // Build query
    $where = ['station_id = ?'];
    $params = [$station_id];
    
    if ($type) {
        $where[] = "customer_type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Get customers
    $stmt = $pdo->prepare("
        SELECT customer_id, first_name, middle_name, last_name, 
               contact_number, address, customer_type, status, registered_at
        FROM customers 
        WHERE $whereClause 
        ORDER BY registered_at DESC
    ");
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare export
    $filename = 'customers_' . date('Y-m-d_His');
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        // Headers
        fputcsv($output, ['Customer ID', 'First Name', 'Middle Name', 'Last Name', 'Contact Number', 'Address', 'Type', 'Status', 'Registered']);
        
        // Data
        foreach ($customers as $c) {
            fputcsv($output, [
                $c['customer_id'],
                $c['first_name'],
                $c['middle_name'],
                $c['last_name'],
                $c['contact_number'],
                $c['address'],
                ucfirst($c['customer_type']),
                ucfirst($c['status']),
                $c['registered_at'] ? date('M d, Y', strtotime($c['registered_at'])) : ''
            ]);
        }
        
        fclose($output);
    } else {
        // Excel format
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
        echo '<head><meta charset="UTF-8"></head>';
        echo '<body>';
        echo '<table border="1">';
        echo '<tr>';
        echo '<th>Customer ID</th>';
        echo '<th>First Name</th>';
        echo '<th>Middle Name</th>';
        echo '<th>Last Name</th>';
        echo '<th>Contact Number</th>';
        echo '<th>Address</th>';
        echo '<th>Type</th>';
        echo '<th>Status</th>';
        echo '<th>Registered</th>';
        echo '</tr>';
        
        foreach ($customers as $c) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($c['customer_id']) . '</td>';
            echo '<td>' . htmlspecialchars($c['first_name']) . '</td>';
            echo '<td>' . htmlspecialchars($c['middle_name']) . '</td>';
            echo '<td>' . htmlspecialchars($c['last_name']) . '</td>';
            echo '<td>' . htmlspecialchars($c['contact_number']) . '</td>';
            echo '<td>' . htmlspecialchars($c['address']) . '</td>';
            echo '<td>' . ucfirst($c['customer_type']) . '</td>';
            echo '<td>' . ucfirst($c['status']) . '</td>';
            echo '<td>' . ($c['registered_at'] ? date('M d, Y', strtotime($c['registered_at'])) : '') . '</td>';
            echo '</tr>';
        }
        
        echo '</table>';
        echo '</body>';
        echo '</html>';
    }
    
    // Log audit
    write_audit_log($pdo, 'Export', "Exported customer list to $format", 'customers', 0, 'report');
    
} catch (Exception $e) {
    die('Error exporting customers: ' . $e->getMessage());
}
?>
