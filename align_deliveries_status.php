<?php
$file = __DIR__ . '/public/manager_fuel_management_complete.php';
if (!file_exists($file)) {
    die("File not found!\n");
}

$content = file_get_contents($file);
$content = str_replace("\r\n", "\n", $content);

// Target the old broken status tag rendering block in deliveries and replace it with status_badge
$searchStatusBlock = '            <td>
                <?php if ($st === \'verified\'): ?>
                    <span class="tag-resolved"><i class="fas fa-check"></i> Verified</span>
                <?php elseif ($st === \'rejected\'): ?>
                    <span class="tag-investigate"><i class="fas fa-times"></i> Rejected</span>
                <?php else: ?>
                    <span class="tag-open"><i class="fas fa-clock"></i> Pending</span>
                <?php endif; ?>
            </td>';

$replaceStatusBlock = '            <td>
                <?php echo status_badge($d[\'status\'] ?? \'pending\'); ?>
            </td>';

$content = str_replace($searchStatusBlock, $replaceStatusBlock, $content);

file_put_contents($file, $content);
echo "Deliveries status rendering updated to status_badge helper successfully.\n";
