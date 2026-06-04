<?php
/**
 * Export Validated Transactions (Manager)
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
    // Fetch validated transactions (Job Orders + Merchandise)
    $query = $pdo->prepare("
        SELECT 
            'Job Order' AS type,
            jo.id AS transaction_id,
            jo.jo_ref AS reference,
            c.name AS customer,
            CONCAT(u.first_name, ' ', u.last_name) AS staff,
            CONCAT(v.first_name, ' ', v.last_name) AS validated_by,
            jo.total_cost AS amount,
            jo.amount_paid,
            (jo.total_cost - COALESCE(jo.amount_paid, 0)) AS balance,
            jo.payment_status,
            jo.status,
            jo.validated_at
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.id
        LEFT JOIN users u ON jo.created_by = u.id
        LEFT JOIN users v ON jo.validated_by = v.id
        WHERE jo.station_id = ?
          AND LOWER(jo.validation_status) IN ('approved', 'validated')
        
        UNION ALL
        
        SELECT 
            'Merchandise' AS type,
            mt.id AS transaction_id,
            CONCAT('MT-', mt.id) AS reference,
            c.name AS customer,
            CONCAT(u.first_name, ' ', u.last_name) AS staff,
            CONCAT(v.first_name, ' ', v.last_name) AS validated_by,
            mt.total_amount AS amount,
            mt.amount_paid,
            (mt.total_amount - COALESCE(mt.amount_paid, 0)) AS balance,
            mt.payment_status,
            'Validated' AS status,
            mt.validated_at
        FROM merchandise_transactions mt
        LEFT JOIN customers c ON mt.customer_id = c.id
        LEFT JOIN users u ON mt.staff_id = u.id
        LEFT JOIN users v ON mt.validated_by = v.id
        WHERE mt.station_id = ?
          AND LOWER(mt.validation_status) IN ('approved', 'validated')
        
        ORDER BY validated_at DESC
        LIMIT 500
    ");
    $query->execute([$station_id, $station_id]);
    $transactions = $query->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="validated_transactions_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Type', 'Reference', 'Customer', 'Staff', 'Validated By', 'Amount', 'Paid', 'Balance', 'Payment Status', 'Status', 'Validated Date']);
        
        foreach ($transactions as $row) {
            fputcsv($output, [
                $row['type'],
                $row['reference'],
                $row['customer'],
                $row['staff'],
                $row['validated_by'],
                number_format($row['amount'], 2),
                number_format($row['amount_paid'], 2),
                number_format($row['balance'], 2),
                $row['payment_status'],
                $row['status'],
                date('Y-m-d H:i', strtotime($row['validated_at']))
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="validated_transactions_' . date('Y-m-d') . '.xls"');
        
        echo "<table border='1'>";
        echo "<tr><th>Type</th><th>Reference</th><th>Customer</th><th>Staff</th><th>Validated By</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Payment Status</th><th>Status</th><th>Validated Date</th></tr>";
        
        foreach ($transactions as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['reference']) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer']) . "</td>";
            echo "<td>" . htmlspecialchars($row['staff']) . "</td>";
            echo "<td>" . htmlspecialchars($row['validated_by']) . "</td>";
            echo "<td>" . number_format($row['amount'], 2) . "</td>";
            echo "<td>" . number_format($row['amount_paid'], 2) . "</td>";
            echo "<td>" . number_format($row['balance'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['payment_status']) . "</td>";
            echo "<td>" . htmlspecialchars($row['status']) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['validated_at'])) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        exit;
    }
    
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="validated_transactions_' . date('Y-m-d') . '.pdf"');
        
        echo "<!DOCTYPE html><html><head><title>Validated Transactions</title>";
        echo "<style>table{width:100%;border-collapse:collapse;font-size:10px;}th,td{border:1px solid #000;padding:6px;text-align:left;}th{background:#f0f0f0;}</style>";
        echo "</head><body>";
        echo "<h2>Validated Transactions - " . date('Y-m-d') . "</h2>";
        echo "<table><tr><th>Type</th><th>Reference</th><th>Customer</th><th>Staff</th><th>Validated By</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Payment Status</th><th>Validated Date</th></tr>";
        
        foreach ($transactions as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['type']) . "</td>";
            echo "<td>" . htmlspecialchars($row['reference']) . "</td>";
            echo "<td>" . htmlspecialchars($row['customer']) . "</td>";
            echo "<td>" . htmlspecialchars($row['staff']) . "</td>";
            echo "<td>" . htmlspecialchars($row['validated_by']) . "</td>";
            echo "<td>₱" . number_format($row['amount'], 2) . "</td>";
            echo "<td>₱" . number_format($row['amount_paid'], 2) . "</td>";
            echo "<td>₱" . number_format($row['balance'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['payment_status']) . "</td>";
            echo "<td>" . date('Y-m-d H:i', strtotime($row['validated_at'])) . "</td>";
            echo "</tr>";
        }
        
        echo "</table></body></html>";
        exit;
    }

} catch (Exception $e) {
    die('Export error: ' . $e->getMessage());
}
