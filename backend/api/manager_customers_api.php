<?php
/**
 * Manager Customer Management API
 * Actions: list, export
 * Roles: manager, admin, superadmin
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';

header('Content-Type: application/json');
ob_clean();

$me   = current_user();
$role = role_key($me['role'] ?? '');

if (!$me || !in_array($role, ['manager', 'admin', 'superadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Manager access required.']);
    exit;
}

$station_id = user_station_id();
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

// Bootstrap customers table
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            station_id INT NOT NULL,
            name VARCHAR(200) NOT NULL,
            contact_number VARCHAR(50),
            email VARCHAR(200),
            address TEXT,
            credit_limit DECIMAL(12,2) DEFAULT 0.00,
            balance DECIMAL(12,2) DEFAULT 0.00,
            available_credit DECIMAL(12,2) GENERATED ALWAYS AS (credit_limit - balance),
            due_date DATE,
            status ENUM('active','inactive','overdue') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_station (station_id),
            INDEX idx_status (status),
            INDEX idx_name (name),
            FOREIGN KEY (station_id) REFERENCES stations(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            station_id INT NOT NULL,
            type ENUM('job_order','delivery','payment') NOT NULL,
            reference VARCHAR(100) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            date DATE NOT NULL,
            notes TEXT,
            encoded_by INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer (customer_id),
            INDEX idx_station (station_id),
            INDEX idx_date (date),
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
            FOREIGN KEY (station_id) REFERENCES stations(id),
            FOREIGN KEY (encoded_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insert sample data if table is empty
    $countStmt = $pdo->query("SELECT COUNT(*) FROM customers WHERE station_id = $station_id");
    $count = $countStmt->fetchColumn();
    
    if ($count == 0) {
        $sampleCustomers = [
            [
                'name' => 'Juan Dela Cruz',
                'contact_number' => '0912-345-6789',
                'email' => 'juan.delacruz@email.com',
                'address' => '123 Main St, Manila',
                'credit_limit' => 50000.00,
                'balance' => 12500.00,
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'status' => 'active'
            ],
            [
                'name' => 'Maria Santos',
                'contact_number' => '0917-234-5678',
                'email' => 'maria.santos@email.com',
                'address' => '456 Oak Ave, Quezon City',
                'credit_limit' => 30000.00,
                'balance' => 8500.00,
                'due_date' => date('Y-m-d', strtotime('+15 days')),
                'status' => 'active'
            ],
            [
                'name' => 'Roberto Reyes',
                'contact_number' => '0918-345-6789',
                'email' => 'roberto.reyes@email.com',
                'address' => '789 Pine St, Makati',
                'credit_limit' => 75000.00,
                'balance' => 25000.00,
                'due_date' => date('Y-m-d', strtotime('+45 days')),
                'status' => 'overdue'
            ]
        ];

        $insertStmt = $pdo->prepare("
            INSERT INTO customers (station_id, name, contact_number, email, address, credit_limit, balance, due_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($sampleCustomers as $customer) {
            $insertStmt->execute([
                $station_id,
                $customer['name'],
                $customer['contact_number'],
                $customer['email'],
                $customer['address'],
                $customer['credit_limit'],
                $customer['balance'],
                $customer['due_date'],
                $customer['status']
            ]);
        }

        // Insert sample transactions
        $transactions = [
            ['customer_id' => 1, 'type' => 'job_order', 'reference' => 'JO-2024-001', 'amount' => 2500.00, 'date' => '2024-04-15', 'notes' => 'Engine oil change', 'encoded_by' => $me['id']],
            ['customer_id' => 1, 'type' => 'delivery', 'reference' => 'DEL-2024-001', 'amount' => 1200.00, 'date' => '2024-04-20', 'notes' => 'Fuel delivery', 'encoded_by' => $me['id']],
            ['customer_id' => 1, 'type' => 'payment', 'reference' => 'PAY-2024-001', 'amount' => 1000.00, 'date' => '2024-04-25', 'notes' => 'Partial payment', 'encoded_by' => $me['id']],
            ['customer_id' => 2, 'type' => 'job_order', 'reference' => 'JO-2024-002', 'amount' => 1800.00, 'date' => '2024-04-18', 'notes' => 'Tire replacement', 'encoded_by' => $me['id']],
            ['customer_id' => 2, 'type' => 'delivery', 'reference' => 'DEL-2024-002', 'amount' => 800.00, 'date' => '2024-04-22', 'notes' => 'Oil delivery', 'encoded_by' => $me['id']],
            ['customer_id' => 3, 'type' => 'payment', 'reference' => 'PAY-2024-002', 'amount' => 500.00, 'date' => '2024-04-28', 'notes' => 'Settlement payment', 'encoded_by' => $me['id']]
        ];

        $transStmt = $pdo->prepare("
            INSERT INTO customer_transactions (customer_id, station_id, type, reference, amount, date, notes, encoded_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($transactions as $transaction) {
            $transStmt->execute([
                $transaction['customer_id'],
                $station_id,
                $transaction['type'],
                $transaction['reference'],
                $transaction['amount'],
                $transaction['date'],
                $transaction['notes'],
                $transaction['encoded_by']
            ]);
        }
    }

} catch (Exception $e) {
    error_log("Customer table bootstrap error: " . $e->getMessage());
}

switch ($action) {
    case 'list':
        // Get all customers with their transactions
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                COUNT(ct.id) as transaction_count,
                MAX(ct.created_at) as last_transaction_date
            FROM customers c
            LEFT JOIN customer_transactions ct ON c.id = ct.customer_id
            WHERE c.station_id = ?
            GROUP BY c.id
            ORDER BY c.name
        ");
        $stmt->execute([$station_id]);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get transactions for each customer
        foreach ($customers as &$customer) {
            $transStmt = $pdo->prepare("
                SELECT type, reference, amount, date, notes, created_at
                FROM customer_transactions
                WHERE customer_id = ? AND station_id = ?
                ORDER BY date DESC, created_at DESC
            ");
            $transStmt->execute([$customer['id'], $station_id]);
            $customer['transactions'] = $transStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode(['success' => true, 'data' => $customers]);
        break;

    case 'export':
        $startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_GET['end'] ?? date('Y-m-d');
        $status = $_GET['status'] ?? '';

        // Build WHERE clause
        $where = ["c.station_id = ?"];
        $params = [$station_id];
        
        if ($status) {
            $where[] = "c.status = ?";
            $params[] = $status;
        }

        $sql = "
            SELECT 
                c.id,
                c.name,
                c.contact_number,
                c.email,
                c.address,
                c.credit_limit,
                c.balance,
                c.available_credit,
                c.due_date,
                c.status,
                c.created_at,
                ct.type,
                ct.reference,
                ct.amount as transaction_amount,
                ct.date as transaction_date,
                ct.notes as transaction_notes,
                ct.created_at as transaction_created_at,
                u.username as encoded_by_name
            FROM customers c
            LEFT JOIN customer_transactions ct ON c.id = ct.customer_id
            LEFT JOIN users u ON ct.encoded_by = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY c.name, ct.date DESC, ct.created_at DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="customer_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header
        fputcsv($output, [
            'Customer ID', 'Customer Name', 'Contact Number', 'Email', 'Address',
            'Credit Limit', 'Balance', 'Available Credit', 'Due Date', 'Status',
            'Transaction Type', 'Transaction Reference', 'Transaction Amount', 
            'Transaction Date', 'Transaction Notes', 'Encoded By', 'Transaction Date'
        ]);
        
        // Data rows
        foreach ($results as $row) {
            fputcsv($output, [
                $row['id'],
                $row['name'],
                $row['contact_number'],
                $row['email'],
                $row['address'],
                $row['credit_limit'],
                $row['balance'],
                $row['available_credit'],
                $row['due_date'],
                $row['status'],
                $row['type'],
                $row['reference'],
                $row['transaction_amount'],
                $row['transaction_date'],
                $row['transaction_notes'],
                $row['encoded_by_name'],
                $row['transaction_created_at']
            ]);
        }
        
        fclose($output);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
}
?>
