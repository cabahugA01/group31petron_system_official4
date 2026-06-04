<?php
/**
 * Fuel Management Status Alignment Script
 * 
 * This script aligns all status rendering in manager_fuel_management_complete.php
 * to use the centralized status_badge() helper function.
 * 
 * Changed sections:
 * 1. Fuel Deliveries Validation table - status column
 * 2. Fuel Transactions History table - status column  
 * 3. Fuel Adjustments History table - status column
 * 
 * All now use: status_badge($record['status'] ?? 'pending')
 * Instead of: manual <span class="tag-*"> elements
 */

$file = __DIR__ . '/public/manager_fuel_management_complete.php';
if (!file_exists($file)) {
    die("File not found!\n");
}

$content = file_get_contents($file);
$content = str_replace("\r\n", "\n", $content);
$changes = 0;

// 1. Deliveries table status column
$searchDeliveriesStatus = '            <td>
                <?php if ($st === \'verified\'): ?>
                    <span class="tag-resolved"><i class="fas fa-check"></i> Verified</span>
                <?php elseif ($st === \'rejected\'): ?>
                    <span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>
                <?php else: ?>
                    <span class="tag-open"><i class="fas fa-clock"></i> Pending</span>
                <?php endif; ?>
            </td>';

$replaceDeliveriesStatus = '            <td>
                <?php echo status_badge($d[\'status\'] ?? \'pending\'); ?>
            </td>';

if (strpos($content, $searchDeliveriesStatus) !== false) {
    $content = str_replace($searchDeliveriesStatus, $replaceDeliveriesStatus, $content);
    $changes++;
    echo "✓ Updated Deliveries table status column\n";
}

// 2. Transactions History table status column
$searchTransactionsStatus = '        <td>
            <?php if ($st_norm === \'verified\'): ?>
                <span class="tag-resolved"><i class="fas fa-check"></i> Approved</span>
            <?php elseif ($st_norm === \'rejected\'): ?>
                <span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>
            <?php else: ?>
                <span class="tag-open"><i class="fas fa-clock"></i> Pending</span>
            <?php endif; ?>
        </td>';

$replaceTransactionsStatus = '        <td>
            <?php echo status_badge($r[\'status\'] ?? \'pending\'); ?>
        </td>';

if (strpos($content, $searchTransactionsStatus) !== false) {
    $content = str_replace($searchTransactionsStatus, $replaceTransactionsStatus, $content);
    $changes++;
    echo "✓ Updated Transactions History table status column\n";
}

// 3. Adjustments History table status column
$searchAdjustmentsStatus = '            <td>
                <?php
                if ($st===\'approved\' || $st===\'verified\') echo \'<span class="tag-resolved"><i class="fas fa-check"></i> Approved</span>\';
                elseif ($st===\'rejected\') echo \'<span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>\';
                else echo \'<span class="tag-open"><i class="fas fa-clock"></i> Pending</span>\';
                ?>
            </td>';

$replaceAdjustmentsStatus = '            <td>
                <?php echo status_badge($adj[\'status\'] ?? \'pending\'); ?>
            </td>';

if (strpos($content, $searchAdjustmentsStatus) !== false) {
    $content = str_replace($searchAdjustmentsStatus, $replaceAdjustmentsStatus, $content);
    $changes++;
    echo "✓ Updated Adjustments History table status column\n";
}

if ($changes > 0) {
    file_put_contents($file, $content);
    echo "\n✅ Successfully aligned $changes status rendering sections to use status_badge() helper.\n";
} else {
    echo "\n✅ All status sections already aligned. No changes needed.\n";
}

