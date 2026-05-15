<?php

// === DELETE MERCHANDISE ===
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM merchandise_inventory WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header("Location: merchandise_inventory.php");
    exit;
}

require_once __DIR__ . '/../backend/lib.php';
require_login();

include __DIR__ . '/../partials/header.php';

// Load products from JSON (single source of truth)
$products = read_json(__DIR__ . '/data/products.json', []);

// Filter non-fuel items
$merch = array_filter($products, function($p){
    return ($p['type'] ?? '') === 'merch';
});

// Group by category
$grouped = [];
foreach ($merch as $m) {
    $cat = $m['category'] ?? 'Others';
    $grouped[$cat][] = $m;
}
?>
<div class="container mt-4">
  <h3>Merchandise Inventory</h3>
  <?php foreach($grouped as $cat => $items): ?>
    <h5 class="mt-3"><?= htmlspecialchars($cat) ?></h5>
    <table class="table table-bordered table-sm">
      <thead>
        <tr>
          <th>Item</th>
          <th>Stock</th>
          <th>Unit</th>
          <th>Price</th>
        <th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach($items as $i): ?>
        <tr>
          <td><?= htmlspecialchars($i['name']) ?></td>
          <td><?= (float)$i['stock'] ?></td>
          <td><?= htmlspecialchars($i['unit'] ?? '-') ?></td>
          <td>₱<?= number_format((float)$i['price'],2) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>
