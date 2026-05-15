<?php
$page_id = 'help';
require_once __DIR__ . '/../backend/lib.php';
require_login();
include __DIR__ . '/../partials/header.php';
?>
  <div class="page-head">
    <div>
      <h1 class="h1">Help / Support</h1>
      <div class="sub">Quick guide for common tasks.</div>
    </div>
  </div>
  
  <section class="card" style="padding:16px;">
    <h2 class="h2">Common workflows</h2>
    <ul style="margin-left:18px;">
      <li><b>Fuel entry:</b> Fuel Entry → add Present/Previous/Calibration → save</li>
      <li><b>POS:</b> POS Transactions → add items/fuel → checkout → receipt</li>
      <li><b>Inventory:</b> Inventory → add stock received → monitor low stock</li>
      <li><b>Job order:</b> Job Orders → create job order → assign mechanic → record parts/labor</li>
    </ul>
    <div class="sub">If something is missing, ask your Admin to confirm your role and station assignment.</div>
  </section>

<?php include __DIR__ . '/../partials/footer.php'; ?>
