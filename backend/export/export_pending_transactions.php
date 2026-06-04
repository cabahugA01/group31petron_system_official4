<?php
/**
 * Export Pending Transactions (Manager)
 * Supports Excel, CSV, PDF formats
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

if (!in_array($role, ['manager', 'admin', 'superadmin'])) {
    die('Access denied');
}

$format = $_GET['format'] ?? 'excel';

try {
    // Fetch pending transactions (Job Orders + Merchandise)
    $query = $pdo->prepare("
        SELECT 
            'Job Order' AS type,
            jo.id AS transaction_id,
            jo.jo_ref AS reference,
            c.name AS customer,
            CONCAT(u.first_name, ' ', u.last_name) AS staff,
            jo.total_cost AS amount,
            jo.status,
            jo.validation_status,
            jo.created_at
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.id
        LEFT JOIN users u ON jo.created_by = u.id
        WHERE jo.station_id = ?
          AND LOWER(jo.validation_status) IN ('pending', 'pending validation')
        
        UNION ALL
        
        SELECT 
            'Merchandise' AS type,
            mt.id AS transaction_id,
            CONCAT('MT-', mt.id) AS reference,
            c.name AS customer,
            CONCAT(u.first_name, ' ', u.last_name) AS staff,
            mt.total_amount AS amount,
            'Pending' AS status,
            mt.validation_status,
            mt.created_at
        FROM merchandise_transactions mt
        LEFT JOIN customers c ON mt.customer_id = c.id
        LEFT JOIN users u ON mt.staff_id = u.id
        WHERE mt.station_id = ?
          AND LOWER(mt.validation_status) IN ('pending', 'pending validation')
        
        ORDER BY created_at DESC
    ");
    $query->execute([$station_id, $station_id]);
    $transactions = $query->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="pending_transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Type', 'Reference', 'Customer', 'Staff', 'Amount', 'Status', 'Validation Status', 'Date']);
        
        foreach ($transactions as $row) {
            fputcsv($output, [
                $row['type'],
                $row['reference'],
                $row['customer'],
                $row['staff'],
                number_format($row['amount'], 2),
                $row['status'],
                $row['validation_status'],
                date('Y-m-d H:i', strtotime($row['created_at']))
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="pending_transactions_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th>Type</th><th>Reference</th><th>Customer</th><th>Staff</th><th>Amount</th><th>Status</th><th>Validation Status</th><th>Date</th></tr>";
        
        foreach ($transactions as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['reference']) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer']) . "</td>";
            echo "<td>" . htmlspecialchars($row['staff']) . "</td>";
            echo "<td>" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['validation_status']) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['created_at'])) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        exit;
    }
    
    if ($format === 'pdf') {
        // PDF format - simple HTML to PDF conversion
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="pending_transactions_' . date('Y-m-d') . '.pdf"');
        
        // For now, use HTML output (can be enhanced with PDF library later)
        echo "<!DOCTYPE html><html><head><title>Pending Transactions</title>";
        echo "<style>table{width:100%;border-collapse:collapse;}th,td{border:1px solid #000;padding:8px;text-align:left;}th{background:#f0f0f0;}</style>";
        echo "</head><body>";
        echo "<h2>Pending Transactions - " . date('Y-m-d') . "</h2>";
        echo "<table><tr><th>Type</th><th>Reference</th><th>Customer</th><th>Staff</th><th>Amount</th><th>Status</th><th>Validation Status</th><th>Date</th></tr>";
        
        foreach ($transactions as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['reference']) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer']) . "</td>";
            echo "<td>" . htmlspecialchars($row['staff']) . "</td>";
            echo "<td>₱" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['validation_status']) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['created_at'])) . "</td>";
            echo "</tr>";
        }
        
        echo "</table></body></html>";
        exit;
    }

} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}
