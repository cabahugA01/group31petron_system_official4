<?php
// expects $sale or $receipt_data
$data = $receipt_data ?? $sale;  // Get station information from database
$station_name = 'VAMENTA BLVD., CARMEN, CITY OF CAGAYAN DE ORO, MISAMIS ORIENTAL MINDANAO MISAMIS ORIENTAL SERVICE';
if (isset($data['station_name'])) {  $station_name = $data['station_name'];
} elseif (isset($data['station'])) {  $station_name = $data['station'];
}
?>
<div class="r-head">  <div class="r-logo">  <img src="../img/petron-logo.png" alt="Petron Logo" class="r-logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">  <div class="r-logo-text" style="display: none;">PETRON</div>  </div>  <div class="r-sub">PETRON STATION MANAGEMENT SYSTEM</div>  <div class="r-tagline"><?php echo htmlspecialchars($station_name); ?></div>  <div class="r-meta">VAT REG TIN: 236-002-207-0000</div>  <div class="r-hr"></div>  <div class="r-title">SALES INVOICE / OFFICIAL RECEIPT</div>  <div class="r-subtitle">TAX INVOICE</div>
</div>  <div class="r-row">  <div>Date: <?php echo htmlspecialchars($data['date'] ?? date('Y-m-d', strtotime($data['transaction_timestamp'] ?? 'now'))); ?></div>  <div class="right">Time: <?php echo htmlspecialchars($data['time'] ?? date('H:i:s', strtotime($data['transaction_timestamp'] ?? 'now'))); ?></div>
</div>
<div class="r-row">  <div>Transaction ID: <?php echo htmlspecialchars($data['transaction_id'] ?? $data['id'] ?? 'N/A'); ?></div>
</div>
<div class="r-row">  <div>Staff ID: <?php echo htmlspecialchars($data['staff_name'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($data['staff_id'] ?? 'N/A'); ?>)</div>
</div>
<?php if (!empty($data['shift_id'])): ?>
<div class="r-row">  <div>Shift ID: <?php echo htmlspecialchars($data['shift_id']); ?></div>
</div>
<?php endif; ?>
<div class="r-row">  <div>Customer: <?php echo htmlspecialchars($data['customer'] ?? $data['customer_name'] ?? 'Walk-in Customer'); ?></div>
</div>
<?php if (!empty($data['shift_name']) || !empty($data['shift_period'])): ?>
<div class="r-row">  <div>Shift: <?php echo htmlspecialchars($data['shift_name'] ?? $data['shift_period'] ?? ''); ?></div>
</div>
<?php endif; ?>
<div class="r-row">  <div>Payment: <?php echo htmlspecialchars(strtoupper($data['payment_method'] ?? $sale['payment_method'] ?? 'CASH')); ?></div>
</div>
<?php  $creditId = $data['credit_customer_id'] ?? $sale['credit_customer_id'] ?? null;
if ($creditId && $creditId !== '-1'): ?>
<div class="r-row">  <div>Credit ID: <?php echo htmlspecialchars($creditId); ?></div>
</div>
<?php elseif(strtolower($data['payment_method'] ?? $sale['payment_method'] ?? '') === 'credit (utang)'): ?>
<div class="r-row">  <div>Credit ID: N/A</div>
</div>
<?php endif; ?>
<div class="r-row">  <div>Staff ID: <?php echo htmlspecialchars($data['staff_name'] ?? 'Cashier'); ?> (<?php echo htmlspecialchars($data['staff_id'] ?? 'N/A'); ?>)</div>
</div>  <div class="r-hr"></div>  <table class="r-table">  <thead>  <tr><th>Item Name</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Subtotal</th></tr>  </thead>  <tbody>  <?php if(isset($sale['items'])): ?>  <?php foreach($sale['items'] as $it): ?>  <tr>  <td><?php echo htmlspecialchars($it['name']); ?></td>  <td class="right"><?php echo htmlspecialchars(number_format($it['qty'],2)); ?></td>  <td class="right">PHP <?php echo money($it['price']); ?></td>  <td class="right">PHP <?php echo money($it['amount']); ?></td>  </tr>  <?php endforeach; ?>  <?php else: ?>  <?php if(isset($data['items']) && is_array($data['items'])): ?>  <?php foreach($data['items'] as $item): ?>  <tr>  <td><?php echo htmlspecialchars($item['productName'] ?? $item['product_name'] ?? 'Item'); ?></td>  <td class="right"><?php echo htmlspecialchars($item['quantity'] ?? 1); ?></td>  <td class="right">PHP <?php echo money($item['unitPrice'] ?? $item['unit_price'] ?? 0); ?></td>  <td class="right">PHP <?php echo money($item['subtotal'] ?? $item['amount'] ?? 0); ?></td>  </tr>  <?php endforeach; ?>  <?php else: ?>  <tr>  <td><?php echo htmlspecialchars($data['product_name'] ?? $data['item_sku'] ?? 'Item'); ?></td>  <td class="right"><?php echo htmlspecialchars(number_format($data['quantity'] ?? 1, 2)); ?></td>  <td class="right">PHP <?php echo money($data['unit_price'] ?? 0); ?></td>  <td class="right">PHP <?php echo money($data['total_amount'] ?? $data['total'] ?? 0); ?></td>  </tr>  <?php endif; ?>  <?php endif; ?>  </tbody>
</table>  <div class="r-hr"></div>  <div class="r-row"><div>Grand Total</div><div class="right">PHP <?php echo money($data['total_amount'] ?? $sale['total'] ?? 0); ?></div></div>  <?php  $paymentMethod = strtolower($data['payment_method'] ?? $sale['payment_method'] ?? 'cash');  // Show payment details based on payment method
if($paymentMethod === 'cash'): ?>  <div class="r-row"><div>Amount Tendered</div><div class="right">PHP <?php echo money($data['amount_tendered'] ?? $data['amount_received'] ?? $sale['amount_received'] ?? $data['total_amount'] ?? $sale['total'] ?? 0); ?></div></div>  <div class="r-row"><div>Change</div><div class="right">PHP <?php echo money($data['change_amount'] ?? $data['change'] ?? $sale['change'] ?? 0); ?></div></div>
<?php elseif($paymentMethod === 'card'): ?>  <?php if(!empty($data['card_reference'] ?? $sale['card_reference'])): ?>  <div class="r-row"><div>Card Reference</div><div class="right"><?php echo htmlspecialchars($data['card_reference'] ?? $sale['card_reference']); ?></div></div>  <?php endif; ?>  <?php if(!empty($data['card_type'] ?? $sale['card_type'])): ?>  <div class="r-row"><div>Card Type</div><div class="right"><?php echo htmlspecialchars($data['card_type'] ?? $sale['card_type']); ?></div></div>  <?php endif; ?>
<?php elseif($paymentMethod === 'e-wallet'): ?>  <?php if(!empty($data['ewallet_reference'] ?? $sale['ewallet_reference'])): ?>  <div class="r-row"><div>E-Wallet Ref</div><div class="right"><?php echo htmlspecialchars($data['ewallet_reference'] ?? $sale['ewallet_reference']); ?></div></div>  <?php endif; ?>  <?php if(!empty($data['ewallet_provider'] ?? $sale['ewallet_provider'])): ?>  <div class="r-row"><div>Provider</div><div class="right"><?php echo htmlspecialchars($data['ewallet_provider'] ?? $sale['ewallet_provider']); ?></div></div>  <?php endif; ?>
<?php elseif($paymentMethod === 'e-fuel card'): ?>  <?php if(!empty($data['efuel_card_number'] ?? $sale['efuel_card_number'])): ?>  <div class="r-row"><div>E-Fuel Card</div><div class="right"><?php echo htmlspecialchars($data['efuel_card_number'] ?? $sale['efuel_card_number']); ?></div></div>  <?php endif; ?>
<?php elseif($paymentMethod === 'credit (utang)'): ?>  <div class="r-row"><div>Amount Tendered</div><div class="right">PHP 0.00</div></div>  <div class="r-row"><div>Change</div><div class="right">PHP 0.00</div></div>
<?php endif; ?>  <div class="r-row"><div>Payment Method</div><div class="right"><?php echo htmlspecialchars(strtoupper($data['payment_method'] ?? $sale['payment_method'] ?? 'CASH')); ?></div></div>
<div class="r-row"><div>Status</div><div class="right"><?php echo htmlspecialchars($data['transaction_status'] ?? ($paymentMethod === 'credit (utang)' ? 'Pending Payment' : 'Paid')); ?></div></div>  <div class="r-hr"></div>  <?php  // VAT Calculation
$totalAmount = $data['total_amount'] ?? $sale['total'] ?? 0;
$vatableAmount = $totalAmount / 1.12; // Remove VAT
$vatAmount = $totalAmount - $vatableAmount;
?>
<div class="r-row"><div>Vatable Sales</div><div class="right">PHP <?php echo money($vatableAmount); ?></div></div>
<div class="r-row"><div>VAT Amount</div><div class="right">PHP <?php echo money($vatAmount); ?></div></div>
<div class="r-row"><div>Zero Rated Sale</div><div class="right">PHP 0.00</div></div>
<div class="r-row"><div>VAT Exempt Sale</div><div class="right">PHP 0.00</div></div>  <?php if(!empty($data['remarks'])): ?>
<div class="r-hr"></div>
<div class="r-row"><div>Remarks:</div></div>
<div class="r-row"><div><?php echo htmlspecialchars($data['remarks']); ?></div></div>
<?php endif; ?>  <?php if(!empty($data['validation_status'])): ?>
<div class="r-row"><div>Status:</div><div class="right"><?php echo htmlspecialchars($data['validation_status']); ?></div></div>
<?php endif; ?>  <div class="r-hr"></div>
<div class="r-foot">  <div style="text-align: center; font-weight: bold; margin-bottom: 5px;">Thank you for your business!</div>  <div style="text-align: center; font-size: 10px; color: #666; margin-bottom: 3px;">VAT-Registered | TIN: 236-002-207-0000</div>  <div class="qr-mini" style="text-align: center; margin-top: 10px;">  <div style="font-size: 8px; color: #999;">QR Code: <?php echo htmlspecialchars($data['transaction_id'] ?? 'N/A'); ?></div>  <div style="font-size: 8px; color: #999;">Scan QR code for verification</div>  </div>
</div>
