<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../public/db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Check if receipt data is available in session
if (!isset($_SESSION['receipt_data'])) {
    $_SESSION['error'] = 'No receipt data available. Please complete a transaction first.';
    header('Location: ../public/staff_transactions.php');
    exit;
}

$receipt_data = $_SESSION['receipt_data'];
$station_id = user_station_id();

// Get station information for receipt
$station_info = [];
try {
    $stmt = $pdo->prepare("SELECT name, address, contact FROM stations WHERE id = ?");
    $stmt->execute([$station_id]);
    $station_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $station_info = ['name' => 'PETRON Station', 'address' => '', 'contact' => ''];
}

// Set content type for receipt
header('Content-Type: text/html; charset=UTF-8');

// Include receipt template
include_once __DIR__ . '/../templates/receipt_template.php';

// Generate receipt HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo htmlspecialchars($receipt_data['transaction_id']); ?></title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            margin: 0;
            padding: 20px;
            background: #f5f5f5;
        }
        .receipt-container {
            max-width: 400px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .r-head {
            text-align: center;
            margin-bottom: 20px;
        }
        .r-logo {
            text-align: center;
            margin-bottom: 10px;
        }
        .r-logo-img {
            width: 80px;
            height: 80px;
            margin-bottom: 5px;
        }
        .r-logo-text {
            font-size: 32px;
            font-weight: bold;
            color: #003d7a;
        }
        .r-sub {
            font-size: 14px;
            margin-bottom: 3px;
            font-weight: bold;
        }
        .r-tagline {
            font-size: 10px;
            color: #666;
            margin-bottom: 5px;
            font-style: italic;
        }
        .r-meta {
            font-size: 11px;
            color: #666;
            margin-bottom: 2px;
        }
        .r-title {
            font-size: 16px;
            font-weight: bold;
            margin: 10px 0;
            padding: 5px 0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        .r-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            font-size: 12px;
        }
        .r-right {
            text-align: right;
        }
        .r-hr {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }
        .r-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 11px;
        }
        .r-table th {
            text-align: left;
            padding: 5px 0;
            border-bottom: 1px solid #000;
        }
        .r-table td {
            padding: 3px 0;
        }
        .r-table .right {
            text-align: right;
        }
        .r-foot {
            margin-top: 20px;
            text-align: center;
            font-size: 11px;
        }
        .r-mini {
            font-size: 10px;
            color: #666;
            margin: 5px 0;
        }
        .action-buttons {
            margin-top: 20px;
            text-align: center;
        }
        .btn {
            padding: 10px 20px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-print {
            background: #007bff;
            color: white;
        }
        .btn-download {
            background: #28a745;
            color: white;
        }
        .btn-email {
            background: #17a2b8;
            color: white;
        }
        .btn-copy {
            background: #007bff;
            color: white;
        }
        .btn-close {
            background: #6c757d;
            color: white;
        }
        @media print {
            body { background: white; padding: 0; }
            .receipt-container { box-shadow: none; border-radius: 0; }
            .action-buttons { display: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <?php 
        // Include the receipt template with the data
        $receipt_data = $receipt_data;
        include __DIR__ . '/../templates/receipt_template.php'; 
        ?>
        
        <div class="action-buttons">
            <button class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Receipt
            </button>
            <button class="btn btn-download" onclick="downloadAsPDF()">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button class="btn btn-email" onclick="emailReceipt()">
                <i class="fas fa-envelope"></i> Email Receipt
            </button>
            <button class="btn btn-copy" onclick="copyToClipboard()">
                <i class="fas fa-copy"></i> Copy Text
            </button>
            <button class="btn btn-close" onclick="window.close()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>

    <script>
        function downloadAsPDF() {
            // Use browser's print to PDF functionality
            window.print();
        }
        
        function emailReceipt() {
            // Get receipt text content
            const receiptContent = document.querySelector('.receipt-container').innerText;
            const subject = encodeURIComponent('Receipt - ' + (document.querySelector('.r-title')?.textContent || 'Transaction'));
            const body = encodeURIComponent(receiptContent);
            
            // Open email client with receipt content
            window.location.href = `mailto:?subject=${subject}&body=${body}`;
        }
        
        function copyToClipboard() {
            // Get receipt text content
            const receiptContent = document.querySelector('.receipt-container').innerText;
            
            // Copy to clipboard
            navigator.clipboard.writeText(receiptContent).then(() => {
                // Show success message
                const btn = document.querySelector('.btn-copy');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                btn.style.background = '#28a745';
                
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.style.background = '#007bff';
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy receipt text:', err);
                alert('Failed to copy receipt text. Please select and copy manually.');
            });
        }
        
        // Auto-clear receipt data after viewing
        setTimeout(() => {
            fetch('../backend/clear_receipt_session.php')
                .then(response => response.json())
                .then(data => console.log('Receipt session cleared'))
                .catch(error => console.error('Error clearing receipt session:', error));
        }, 5000);
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'p':
                        e.preventDefault();
                        window.print();
                        break;
                    case 'c':
                        e.preventDefault();
                        copyToClipboard();
                        break;
                    case 'e':
                        e.preventDefault();
                        emailReceipt();
                        break;
                }
            }
        });
    </script>
</body>
</html>
<?php
$content = ob_get_clean();

echo $content;
?>
