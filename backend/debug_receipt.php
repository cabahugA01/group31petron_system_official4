<?php
require_once __DIR__ . '/../public/db_connect.php';

$search_id = $_GET['id'] ?? 'MERCH202612535096';

echo "<h2>Receipt Debug Tool</h2>";
echo "<p>Searching for: <strong>" . htmlspecialchars($search_id) . "</strong></p>";
echo "<hr>";

try {
    // Try to find in merchandise_transactions
    $stmt = $pdo->prepare("
        SELECT id, transaction_id, customer_name, total_amount, validation_status, created_at
        FROM merchandise_transactions 
        WHERE transaction_id = ? OR transaction_id LIKE ? OR id = ?
        ORDER BY id DESC
        LIMIT 10
    ");
    $numeric_id = is_numeric($search_id) ? (int)$search_id : 0;
    $stmt->execute([$search_id, $search_id . '%', $numeric_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Merchandise Transactions (search results):</h3>";
    if (count($results) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>DB ID</th><th>Transaction ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr>";
        foreach ($results as $row) {
            $receipt_url = "../public/receipt.php?id=" . urlencode($row['transaction_id']) . "&type=merchandise";
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td><strong>" . htmlspecialchars($row['transaction_id']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($row['customer_name']) . "</td>";
            echo "<td>₱" . number_format($row['total_amount'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['validation_status']) . "</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "<td><a href='" . $receipt_url . "' target='_blank'>View Receipt</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color:red;'>No matching transactions found.</p>";
    }
    
    // Show all recent transactions
    echo "<hr>";
    echo "<h3>All Recent Transactions:</h3>";
    $stmt2 = $pdo->query("
        SELECT id, transaction_id, customer_name, total_amount, validation_status, created_at
        FROM merchandise_transactions 
        ORDER BY id DESC
        LIMIT 10
    ");
    $all_results = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($all_results) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>DB ID</th><th>Transaction ID</th><th>Customer</th><th>Amount</th><th>Status</th><th>Date</th><th>Action</th></tr>";
        foreach ($all_results as $row) {
            $receipt_url = "../public/receipt.php?id=" . urlencode($row['transaction_id']) . "&type=merchandise";
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td><strong>" . htmlspecialchars($row['transaction_id']) . "</strong></td>";
            echo "<td>" . htmlspecialchars($row['customer_name']) . "</td>";
            echo "<td>₱" . number_format($row['total_amount'], 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['validation_status']) . "</td>";
            echo "<td>" . $row['created_at'] . "</td>";
            echo "<td><a href='" . $receipt_url . "' target='_blank' style='color:blue;'>View Receipt</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No transactions found in database.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h3>Test Receipt Link:</h3>";
echo "<p>Try this link: <a href='../public/receipt.php?id=MERCH2026125350963&type=merchandise' target='_blank'>Receipt for MERCH2026125350963</a></p>";
?>
