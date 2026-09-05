<?php
/**
 * Export Merchandise History for Staff
 * Formats: Excel, CSV, PDF
 */
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
require_login();

$me = current_user();
$role = role_key($me['role'] ?? '');
$staff_id = (int)$me['id'];
$format = strtolower(trim($_GET['format'] ?? 'excel'));

// Staff, Manager, and Admin can access
if (!in_array($role, ['staff', 'cashier', 'pump_attendant', 'manager', 'admin', 'superadmin', 'developer'])) {
    die('Access denied');
}

try {
    $station_id = user_station_id();
    $is_elevated = in_array($role, ['admin', 'manager', 'superadmin', 'developer']);
    if ($is_elevated && $station_id > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                mt.transaction_id,
                COALESCE(mt.customer_name, 'Walk-in') AS customer,
                COALESCE(mt.item_sku, 'N/A') AS items,
                COALESCE(mt.quantity, 0) AS quantity,
                mt.total_amount AS amount,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.validation_status, 'Pending') AS status,
                DATE_FORMAT(COALESCE(mt.transaction_date, mt.created_at), '%Y-%m-%d %H:%i') AS date_created
            FROM merchandise_transactions mt
            WHERE mt.station_id = ? OR mt.staff_id = ?
            ORDER BY COALESCE(mt.transaction_date, mt.created_at) DESC
            LIMIT 1000
        ");
        $stmt->execute([$station_id, $staff_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                mt.transaction_id,
                COALESCE(mt.customer_name, 'Walk-in') AS customer,
                COALESCE(mt.item_sku, 'N/A') AS items,
                COALESCE(mt.quantity, 0) AS quantity,
                mt.total_amount AS amount,
                COALESCE(mt.payment_method, 'Cash') AS payment_method,
                COALESCE(mt.validation_status, 'Pending') AS status,
                DATE_FORMAT(COALESCE(mt.transaction_date, mt.created_at), '%Y-%m-%d %H:%i') AS date_created
            FROM merchandise_transactions mt
            WHERE mt.staff_id = ?
            ORDER BY COALESCE(mt.transaction_date, mt.created_at) DESC
            LIMIT 1000
        ");
        $stmt->execute([$staff_id]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        // ── CSV Export ──
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="merchandise_history_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Transaction ID', 'Customer', 'Items', 'Quantity', 'Amount', 'Payment Method', 'Status', 'Date']);
        
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['transaction_id'],
                $row['customer'],
                $row['items'],
                $row['quantity'],
                '₱' . number_format((float)$row['amount'], 2),
                $row['payment_method'],
                $row['status'],
                $row['date_created']
            ]);
        }
        
        fclose($output);
        exit;
        
    } elseif ($format === 'pdf') {
        // ── PDF Export ──
        $html = '<html><head><style>
            body { font-family: Arial, sans-serif; font-size: 11px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #002F70; color: white; padding: 8px; text-align: left; font-size: 10px; }
            td { border: 1px solid #ddd; padding: 6px; font-size: 10px; }
            h1 { color: #002F70; font-size: 18px; }
        </style></head><body>';
        $html .= '<h1>Merchandise History Export</h1>';
        $html .= '<p>Generated: ' . date('F d, Y H:i A') . '</p>';
        $html .= '<table><thead><tr>';
        $html .= '<th>Transaction ID</th><th>Customer</th><th>Items</th><th>Qty</th><th>Amount</th><th>Payment</th><th>Status</th><th>Date</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['transaction_id']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['customer']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['items']) . '</td>';
            $html .= '<td>' . (int)$row['quantity'] . '</td>';
            $html .= '<td>PHP ' . number_format((float)$row['amount'], 2) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['payment_method']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['status']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['date_created']) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</body></html>';

        require_once __DIR__ . '/../../vendor/autoload.php';
        if (class_exists('\Mpdf\Mpdf')) {
            try {
                $temp_dir = __DIR__ . '/../../scratch';
                if (!is_dir($temp_dir)) @mkdir($temp_dir, 0777, true);
                if (!is_writable($temp_dir)) $temp_dir = sys_get_temp_dir();

                $mpdf = new \Mpdf\Mpdf([
                    'mode' => 'utf-8',
                    'format' => 'A4-L',
                    'margin_left' => 8,
                    'margin_right' => 8,
                    'margin_top' => 8,
                    'margin_bottom' => 10,
                    'tempDir' => $temp_dir,
                    'autoScriptToLang' => true,
                    'autoLangToFont' => true
                ]);
                $mpdf->SetTitle('Merchandise History Export');
                $mpdf->WriteHTML($html);
                $pdf_filename = 'merchandise_history_' . date('Y-m-d') . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                $mpdf->Output($pdf_filename, 'D');
                exit;
            } catch (\Throwable $e) {}
        }

        header('Content-Type: text/html; charset=utf-8');
        $print_script = "<script>window.onload = function() { window.focus(); window.print(); };</script>";
        $html = str_replace("</body>", $print_script . "</body>", $html);
        echo $html;
        exit;
        
    } else {
        // ── Excel Export (HTML table with Excel headers) ──
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="merchandise_history_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1"><thead><tr>';
        echo '<th>Transaction ID</th><th>Customer</th><th>Items</th><th>Quantity</th><th>Amount</th><th>Payment Method</th><th>Status</th><th>Date</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['transaction_id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['customer']) . '</td>';
            echo '<td>' . htmlspecialchars($row['items']) . '</td>';
            echo '<td>' . (int)$row['quantity'] . '</td>';
            echo '<td>₱' . number_format((float)$row['amount'], 2) . '</td>';
            echo '<td>' . htmlspecialchars($row['payment_method']) . '</td>';
            echo '<td>' . htmlspecialchars($row['status']) . '</td>';
            echo '<td>' . htmlspecialchars($row['date_created']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        exit;
    }

} catch (Exception $e) {
    die('Export failed: ' . $e->getMessage());
}
