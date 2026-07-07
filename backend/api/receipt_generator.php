<?php
require_once __DIR__ . '/../../config/database_config.php';

class ReceiptGenerator {
    private $pdo;
    private $station_id;
    
    public function __construct($pdo, $station_id = 1) {
        $this->pdo = $pdo;
        $this->station_id = $station_id;
    }
    
    public function generateTransactionID() {
        return 'TXN' . time() . rand(1000, 9999);
    }
    
    public function calculateVAT($amount) {
        $vatRate = 0.12; // 12% VAT
        $vatableAmount = $amount / 1.12;
        $vatAmount = $amount - $vatableAmount;
        return [
            'vatable_sales' => round($vatableAmount, 2),
            'vat_amount' => round($vatAmount, 2)
        ];
    }
    
    public function getStationInfo() {
        return [
            'dealer' => 'PETRON CORPORATION',
            'branch' => 'VAMENTA BLVD., CARMEN, CITY OF CAGAYAN DE ORO, MISAMIS ORIENTAL SERVICE STATION',
            'vat_tin' => '000-168-801-00289'
        ];
    }
    
    public function processTransaction($data) {
        try {
            $transactionID = $this->generateTransactionID();
            $currentDateTime = date('Y-m-d H:i:s');
            
            // Calculate totals
            $grandTotal = 0;
            $items = [];
            
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $amount = $item['qty'] * $item['unit_price'];
                    $grandTotal += $amount;
                    
                    $items[] = [
                        'description' => $item['description'],
                        'qty' => $item['qty'],
                        'unit_price' => $item['unit_price'],
                        'amount' => $amount
                    ];
                }
            }
            
            // Calculate change
            $amountTendered = isset($data['amount_tendered']) ? $data['amount_tendered'] : $grandTotal;
            $change = $amountTendered - $grandTotal;
            
            // Calculate VAT
            $vatCalculation = $this->calculateVAT($grandTotal);
            
            // Prepare transaction data
            $transactionData = [
                'transaction_id' => $transactionID,
                'date_time' => $currentDateTime,
                'customer_name' => isset($data['customer_name']) ? $data['customer_name'] : 'Walk-in Customer',
                'payment_method' => isset($data['payment_method']) ? $data['payment_method'] : 'Cash',
                'staff_id' => isset($data['staff_id']) ? $data['staff_id'] : 'STAFF001',
                'items' => $items,
                'grand_total' => $grandTotal,
                'amount_tendered' => $amountTendered,
                'change' => $change,
                'vatable_sales' => $vatCalculation['vatable_sales'],
                'vat_amount' => $vatCalculation['vat_amount']
            ];
            
            // Save to database (optional - for record keeping)
            $this->saveTransaction($transactionData);
            
            return $transactionData;
            
        } catch (Exception $e) {
            throw new Exception('Error processing transaction: ' . $e->getMessage());
        }
    }
    
    private function saveTransaction($transactionData) {
        try {
            // Insert into sales table
            $stmt = $this->pdo->prepare("
                INSERT INTO sales (
                    transaction_id, customer_name, payment_method, 
                    total_amount, amount_tendered, change_amount,
                    staff_id, station_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $transactionData['transaction_id'],
                $transactionData['customer_name'],
                $transactionData['payment_method'],
                $transactionData['grand_total'],
                $transactionData['amount_tendered'],
                $transactionData['change'],
                $transactionData['staff_id'],
                $this->station_id,
                $transactionData['date_time']
            ]);
            
            $saleId = $this->pdo->lastInsertId();
            
            // Insert sale items
            foreach ($transactionData['items'] as $item) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO sale_items (
                        sale_id, product_name, quantity, unit_price, 
                        total_price, category
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $saleId,
                    $item['description'],
                    $item['qty'],
                    $item['unit_price'],
                    $item['amount'],
                    'Merchandise'
                ]);
            }
            
        } catch (Exception $e) {
            // Log error but don't fail the receipt generation
            error_log('Error saving transaction: ' . $e->getMessage());
        }
    }
    
    public function getReceiptHTML($transactionData) {
        $stationInfo = $this->getStationInfo();
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Petron Merchandise Receipt</title>
            <style>
                @media print {
                    body { margin: 0; padding: 10px; }
                    .no-print { display: none !important; }
                }
                
                .receipt-container {
                    max-width: 400px;
                    margin: 0 auto;
                    padding: 20px;
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                    line-height: 1.4;
                    border: 1px solid #ccc;
                    background: white;
                }
                
                .header {
                    text-align: center;
                    margin-bottom: 20px;
                }
                
                .logo {
                    width: 80px;
                    height: 80px;
                    margin-bottom: 10px;
                }
                
                .dealer-info {
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                
                .branch-info {
                    font-size: 10px;
                    margin-bottom: 5px;
                    line-height: 1.2;
                }
                
                .vat-info {
                    font-size: 10px;
                    margin-bottom: 15px;
                }
                
                .title {
                    font-weight: bold;
                    font-size: 14px;
                    border-top: 2px solid #000;
                    border-bottom: 2px solid #000;
                    padding: 5px 0;
                    margin-bottom: 15px;
                }
                
                .transaction-details {
                    margin-bottom: 15px;
                }
                
                .detail-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 3px;
                }
                
                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 15px;
                }
                
                .items-table th,
                .items-table td {
                    text-align: left;
                    padding: 2px;
                    font-size: 11px;
                }
                
                .items-table th {
                    border-bottom: 1px solid #000;
                    font-weight: bold;
                }
                
                .items-table .amount {
                    text-align: right;
                }
                
                .summary-section {
                    margin-bottom: 15px;
                }
                
                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 3px;
                }
                
                .grand-total {
                    font-weight: bold;
                    border-top: 1px solid #000;
                    border-bottom: 1px solid #000;
                    padding: 3px 0;
                }
                
                .footer {
                    text-align: center;
                    margin-top: 20px;
                    font-size: 10px;
                }
                
                .thank-you {
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                
                .qr-code {
                    width: 60px;
                    height: 60px;
                    margin: 10px auto;
                    border: 1px solid #ccc;
                }
                
                .action-buttons {
                    text-align: center;
                    margin: 20px 0;
                }
                
                .btn {
                    padding: 10px 20px;
                    margin: 0 5px;
                    border: none;
                    cursor: pointer;
                    font-size: 14px;
                }
                
                .btn-print {
                    background: #007bff;
                    color: white;
                }
                
                .btn-new {
                    background: #28a745;
                    color: white;
                }
            </style>
        </head>
        <body>
            <div class="receipt-container">
                <!-- Header Section -->
                <div class="header">
                    <img src="../assets/img/Petron Logo.png" alt="Petron Logo" class="logo">
                    <div class="dealer-info"><?php echo htmlspecialchars($stationInfo['dealer']); ?></div>
                    <div class="branch-info"><?php echo htmlspecialchars($stationInfo['branch']); ?></div>
                    <div class="vat-info">VAT REG TIN: <?php echo htmlspecialchars($stationInfo['vat_tin']); ?></div>
                </div>
                
                <!-- Title -->
                <div class="title">
                    Sales Invoice / Official Receipt
                </div>
                
                <!-- Transaction Details -->
                <div class="transaction-details">
                    <div class="detail-row">
                        <span>Transaction ID:</span>
                        <span><?php echo htmlspecialchars($transactionData['transaction_id']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Date/Time:</span>
                        <span><?php echo htmlspecialchars($transactionData['date_time']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Customer Name:</span>
                        <span><?php echo htmlspecialchars($transactionData['customer_name']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Payment Method:</span>
                        <span><?php echo htmlspecialchars($transactionData['payment_method']); ?></span>
                    </div>
                    <div class="detail-row">
                        <span>Staff ID:</span>
                        <span><?php echo htmlspecialchars($transactionData['staff_id']); ?></span>
                    </div>
                </div>
                
                <!-- Itemized List -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th class="amount">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactionData['items'] as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['description']); ?></td>
                            <td><?php echo $item['qty']; ?></td>
                            <td>PHP <?php echo number_format($item['unit_price'], 2); ?></td>
                            <td class="amount">PHP <?php echo number_format($item['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Summary Section -->
                <div class="summary-section">
                    <div class="summary-row grand-total">
                        <span>Grand Total:</span>
                        <span>PHP <?php echo number_format($transactionData['grand_total'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Amount Tendered:</span>
                        <span>PHP <?php echo number_format($transactionData['amount_tendered'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Change:</span>
                        <span>PHP <?php echo number_format($transactionData['change'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Vatable Sales:</span>
                        <span>PHP <?php echo number_format($transactionData['vatable_sales'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>VAT Amount:</span>
                        <span>PHP <?php echo number_format($transactionData['vat_amount'], 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span>Zero Rated Sale:</span>
                        <span>PHP 0.00</span>
                    </div>
                    <div class="summary-row">
                        <span>VAT Exempt Sale:</span>
                        <span>PHP 0.00</span>
                    </div>
                </div>
                
                <!-- Footer Section -->
                <div class="footer">
                    <div class="thank-you">Thank you for your business!</div>
                    <div>VAT-Registered | TIN: <?php echo htmlspecialchars($stationInfo['vat_tin']); ?></div>
                    <div class="qr-code">
                        <!-- QR Code placeholder -->
                        <div style="font-size: 8px; text-align: center; padding: 20px;">
                            QR Code<br>Verification
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons (not printed) -->
            <div class="action-buttons no-print">
                <button class="btn btn-print" onclick="window.print()">Print Receipt</button>
                <button class="btn btn-new" onclick="window.location.href='staff_pos.php'">New Transaction</button>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

// API endpoint for generating receipts
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $generator = new ReceiptGenerator($pdo);
        
        // Get JSON data
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid input data');
        }
        
        // Process transaction
        $transactionData = $generator->processTransaction($input);
        
        // Generate HTML receipt
        $receiptHTML = $generator->getReceiptHTML($transactionData);
        
        echo json_encode([
            'success' => true,
            'transaction_data' => $transactionData,
            'receipt_html' => $receiptHTML
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}
?>
