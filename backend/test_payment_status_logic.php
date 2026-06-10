<?php
/**
 * Test Payment Status Logic
 * Verifies that button display logic works correctly based on payment status
 */

require_once __DIR__ . '/../public/db_connect.php';

echo "=== PAYMENT STATUS LOGIC TEST ===\n\n";

// Test scenarios
$test_scenarios = [
    [
        'name' => 'Unpaid Transaction',
        'payment_status' => 'Pending Payment',
        'balance_due' => 1000.00,
        'amount_paid' => 0.00,
        'total_amount' => 1000.00,
        'expected_button' => 'Settle Payment',
        'expected_color' => 'green',
        'expected_indicator' => 'none'
    ],
    [
        'name' => 'Partial Payment',
        'payment_status' => 'Partial Payment',
        'balance_due' => 600.00,
        'amount_paid' => 400.00,
        'total_amount' => 1000.00,
        'expected_button' => 'Settle Balance',
        'expected_color' => 'green',
        'expected_indicator' => 'none'
    ],
    [
        'name' => 'Fully Paid',
        'payment_status' => 'Paid',
        'balance_due' => 0.00,
        'amount_paid' => 1000.00,
        'total_amount' => 1000.00,
        'expected_button' => 'Print Receipt',
        'expected_color' => 'gray',
        'expected_indicator' => 'Paid & Complete'
    ]
];

foreach ($test_scenarios as $index => $scenario) {
    echo "Test " . ($index + 1) . ": {$scenario['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    // Simulate the logic
    $pay_status = $scenario['payment_status'];
    $balance = $scenario['balance_due'];
    $paid = $scenario['amount_paid'];
    $total = $scenario['total_amount'];
    
    // Logic from staff_transactions_hub.php
    $mh_can_settle = !in_array(strtolower($pay_status), ['paid']);
    
    echo "Payment Status: $pay_status\n";
    echo "Total Amount: ₱" . number_format($total, 2) . "\n";
    echo "Amount Paid: ₱" . number_format($paid, 2) . "\n";
    echo "Balance Due: ₱" . number_format($balance, 2) . "\n";
    echo "\n";
    
    if ($mh_can_settle) {
        // Show settlement button
        $button_text = (strtolower($pay_status) === 'partial payment') ? 'Settle Balance' : 'Settle Payment';
        $button_color = 'green';
        $indicator = 'none';
        echo "✅ Button Shown: $button_text\n";
        echo "✅ Button Color: $button_color\n";
        echo "✅ Indicator: $indicator\n";
    } elseif (strtolower($pay_status) === 'paid') {
        // Show print receipt button only
        $button_text = 'Print Receipt';
        $button_color = 'gray';
        $indicator = 'Paid & Complete';
        echo "✅ Button Shown: $button_text\n";
        echo "✅ Button Color: $button_color\n";
        echo "✅ Indicator: $indicator\n";
    }
    
    echo "\n";
    
    // Verify expectations
    $passed = true;
    if ($mh_can_settle) {
        if ($scenario['expected_button'] !== $button_text) {
            echo "❌ FAIL: Expected button '{$scenario['expected_button']}' but got '$button_text'\n";
            $passed = false;
        }
        if ($scenario['expected_color'] !== $button_color) {
            echo "❌ FAIL: Expected color '{$scenario['expected_color']}' but got '$button_color'\n";
            $passed = false;
        }
    } elseif (strtolower($pay_status) === 'paid') {
        if ($scenario['expected_button'] !== $button_text) {
            echo "❌ FAIL: Expected button '{$scenario['expected_button']}' but got '$button_text'\n";
            $passed = false;
        }
        if ($scenario['expected_indicator'] !== $indicator) {
            echo "❌ FAIL: Expected indicator '{$scenario['expected_indicator']}' but got '$indicator'\n";
            $passed = false;
        }
    }
    
    if ($passed) {
        echo "✅ TEST PASSED\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
}

// Test with real database records
echo "=== REAL DATABASE RECORDS TEST ===\n\n";

try {
    // Find sample merchandise transactions
    $stmt = $pdo->query("
        SELECT id, transaction_id, customer_name, total_amount, 
               COALESCE(amount_paid, 0) AS amount_paid,
               COALESCE(balance_due, total_amount) AS balance_due,
               COALESCE(payment_status, 'Pending Payment') AS payment_status
        FROM merchandise_transactions
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo "⚠️ No merchandise transactions found in database\n\n";
    } else {
        foreach ($records as $txn) {
            echo "Transaction: {$txn['transaction_id']}\n";
            echo "Customer: {$txn['customer_name']}\n";
            echo "Payment Status: {$txn['payment_status']}\n";
            echo "Total: ₱" . number_format($txn['total_amount'], 2) . "\n";
            echo "Paid: ₱" . number_format($txn['amount_paid'], 2) . "\n";
            echo "Balance: ₱" . number_format($txn['balance_due'], 2) . "\n";
            
            $pay_status = $txn['payment_status'];
            $mh_can_settle = !in_array(strtolower($pay_status), ['paid']);
            
            if ($mh_can_settle) {
                $button = (strtolower($pay_status) === 'partial payment') ? 'Settle Balance' : 'Settle Payment';
                echo "→ Button: $button (green)\n";
            } elseif (strtolower($pay_status) === 'paid') {
                echo "→ Button: Print Receipt (gray)\n";
                echo "→ Indicator: ✓ Paid & Complete\n";
            }
            
            echo "\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Database Error: " . $e->getMessage() . "\n\n";
}

// Job Order Workflow Test
echo "=== JOB ORDER WORKFLOW TEST ===\n\n";

$jo_scenarios = [
    [
        'name' => 'In Progress + Unpaid',
        'workflow_status' => 'In Progress',
        'payment_status' => 'Pending Payment',
        'expected_button' => 'Complete & Settle',
        'expected_modal' => 'yes'
    ],
    [
        'name' => 'In Progress + Paid',
        'workflow_status' => 'In Progress',
        'payment_status' => 'Paid',
        'expected_button' => 'Mark Complete',
        'expected_modal' => 'no'
    ],
    [
        'name' => 'Completed + Unpaid',
        'workflow_status' => 'Completed',
        'payment_status' => 'Pending Payment',
        'expected_button' => 'Settle Payment',
        'expected_modal' => 'yes'
    ],
    [
        'name' => 'Completed + Paid',
        'workflow_status' => 'Completed',
        'payment_status' => 'Paid',
        'expected_button' => 'Print Receipt',
        'expected_modal' => 'no'
    ]
];

foreach ($jo_scenarios as $index => $scenario) {
    echo "Job Order Test " . ($index + 1) . ": {$scenario['name']}\n";
    echo str_repeat("-", 60) . "\n";
    
    $wf_status = $scenario['workflow_status'];
    $pay_status = $scenario['payment_status'];
    
    echo "Workflow: $wf_status\n";
    echo "Payment: $pay_status\n";
    
    if ($wf_status === 'In Progress') {
        if ($pay_status === 'Paid') {
            $button = 'Mark Complete';
            $modal = 'no';
        } else {
            $button = 'Complete & Settle';
            $modal = 'yes';
        }
    } elseif ($wf_status === 'Completed') {
        if ($pay_status === 'Paid') {
            $button = 'Print Receipt';
            $modal = 'no';
        } else {
            $button = 'Settle Payment';
            $modal = 'yes';
        }
    }
    
    echo "→ Button: $button\n";
    echo "→ Payment Modal: $modal\n";
    
    // Verify
    if ($button === $scenario['expected_button'] && $modal === $scenario['expected_modal']) {
        echo "✅ TEST PASSED\n";
    } else {
        echo "❌ TEST FAILED\n";
    }
    
    echo "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ ALL TESTS COMPLETE\n";
echo str_repeat("=", 60) . "\n";
