<?php
// expects $sale
?>
<div class="r-head">
  <div class="r-logo">PETRON</div>
  <div class="r-sub">PETRON CORPORATION</div>
  <div class="r-meta">Dealer: PETRON CORPORATION</div>
  <div class="r-meta">VAT REG TIN: 000-168-801-00289</div>
  <div class="r-hr"></div>
  <div class="r-title">SALES INVOICE</div>
</div>

<div class="r-row">
  <div>Date: <?php echo htmlspecialchars($sale['date']); ?></div>
  <div class="right">Time: <?php echo htmlspecialchars($sale['time']); ?></div>
</div>
<div class="r-row">
  <div>POS S/N: <?php echo htmlspecialchars($sale['id']); ?></div>
</div>
<div class="r-row">
  <div>Name: <?php echo htmlspecialchars($sale['customer']); ?></div>
</div>

<div class="r-hr"></div>

<table class="r-table">
  <thead>
    <tr><th>Description</th><th class="right">Qty.</th><th class="right">Price</th><th class="right">Amount</th></tr>
  </thead>
  <tbody>
  <?php foreach($sale['items'] as $it): ?>
    <tr>
      <td><?php echo htmlspecialchars($it['name']); ?></td>
      <td class="right"><?php echo htmlspecialchars(number_format($it['qty'],2)); ?></td>
      <td class="right">₱<?php echo money($it['price']); ?></td>
      <td class="right">₱<?php echo money($it['amount']); ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="r-hr"></div>

<div class="r-row"><div>Total (incl. VAT)</div><div class="right">₱<?php echo money($sale['total']); ?></div></div>
<div class="r-row"><div>Payment:</div><div class="right"><?php echo htmlspecialchars(strtoupper($sale['payment_method'])); ?></div></div>
<?php if(strtolower($sale['payment_method']) === 'cash'): ?>
  <div class="r-row"><div>Cash</div><div class="right">₱<?php echo money($sale['amount_received']); ?></div></div>
  <div class="r-row"><div>Change</div><div class="right">₱<?php echo money($sale['change']); ?></div></div>
<?php else: ?>
  <div class="r-row"><div>Amount</div><div class="right">₱<?php echo money($sale['total']); ?></div></div>
<?php endif; ?>

<div class="r-hr"></div>
<div class="r-foot">
  <div>Cashier: <?php echo htmlspecialchars($sale['cashier']); ?></div>
  <div class="r-mini">Thank you! This is a demo receipt.</div>
  <div class="qr-mini"></div>
</div>
