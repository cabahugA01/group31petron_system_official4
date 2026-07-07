<?php
/**
 * Transaction History Tab
 * Shows ALL transaction types (job_order, merchandise, combined)
 */
?>

<div class="history-container">
    <h2>Transaction History</h2>
    <p style="color: #6b7280; margin-bottom: 2rem;">
        View all transactions including job orders, merchandise sales, and combined transactions.
    </p>
    
    <!-- Filters -->
    <form method="GET" class="filters">
        <input type="hidden" name="tab" value="transaction_history">
        
        <div class="filters-grid">
            <div class="form-group">
                <label for="filter_type">Transaction Type</label>
                <select name="type" id="filter_type">
                    <option value="">All Types</option>
                    <option value="job_order" <?= ($_GET['type'] ?? '') === 'job_order' ? 'selected' : '' ?>>Job Order Only</option>
                    <option value="merchandise" <?= ($_GET['type'] ?? '') === 'merchandise' ? 'selected' : '' ?>>Merchandise Only</option>
                    <option value="combined" <?= ($_GET['type'] ?? '') === 'combined' ? 'selected' : '' ?>>Combined Transaction</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="filter_date_from">Date From</label>
                <input type="date" name="date_from" id="filter_date_from" 
                       value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="filter_date_to">Date To</label>
                <input type="date" name="date_to" id="filter_date_to" 
                       value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
            </div>
        </div>
        
        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="?tab=transaction_history" class="btn btn-secondary">Clear Filters</a>
        </div>
    </form>
    
    <!-- Transactions Table -->
    <table class="data-table">
        <thead>
            <tr>
                <th>Transaction ID</th>
                <th>Transaction Type</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transaction_history)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; padding: 2rem; color: #6b7280;">
                        No transactions found
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($transaction_history as $txn): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($txn['transaction_id']) ?></strong>
                        </td>
                        <td>
                            <?php
                            $type = $txn['transaction_type'];
                            $type_labels = [
                                'job_order' => 'Job Order Only',
                                'merchandise' => 'Merchandise Only',
                                'combined' => 'Combined Transaction'
                            ];
                            $type_colors = [
                                'job_order' => '#2563eb',
                                'merchandise' => '#16a34a',
                                'combined' => '#9333ea'
                            ];
                            ?>
                            <span style="color: <?= $type_colors[$type] ?? '#6b7280' ?>; font-weight: 600;">
                                <?= $type_labels[$type] ?? ucfirst($type) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($txn['customer_name']) ?></td>
                        <td>₱<?= number_format($txn['total_amount'], 2) ?></td>
                        <td><?= htmlspecialchars($txn['payment_method']) ?></td>
                        <td><?= date('M d, Y g:i A', strtotime($txn['date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?tab=transaction_history&page=<?= $page - 1 ?><?= !empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>">
                    Previous
                </a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php if ($i == $page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?tab=transaction_history&page=<?= $i ?><?= !empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?tab=transaction_history&page=<?= $page + 1 ?><?= !empty($_GET['type']) ? '&type=' . urlencode($_GET['type']) : '' ?>">
                    Next
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
