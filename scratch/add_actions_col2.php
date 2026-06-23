<?php
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/admin_transactions_oversight.php';
$content = file_get_contents($file);

// Find insertion point: after the closing </td> of the staff_name cell 
// which precedes the </tr><?php endforeach; ?>
// Strategy: find "mb_strimwidth($r['staff_name']" line, then the two lines after it (</td>, </tr>)
// and insert BEFORE the </tr>

$lines = explode("\n", str_replace("\r\n", "\n", $content));
$insertIdx = -1;

for ($i = 0; $i < count($lines); $i++) {
    if (strpos($lines[$i], "mb_strimwidth(\$r['staff_name']") !== false) {
        // Next line should be </td>
        if (isset($lines[$i+1]) && trim($lines[$i+1]) === '</td>') {
            $insertIdx = $i + 2; // insert before </tr>
            echo "Found at line $i, inserting at $insertIdx\n";
            echo "Context:\n";
            for ($j = $i; $j <= $i+3; $j++) echo "  [$j]: " . rtrim($lines[$j]) . "\n";
            break;
        }
    }
}

if ($insertIdx < 0) {
    echo "Could not find insertion point. Searching for context...\n";
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], 'staff_name') !== false) {
            echo "  Line $i: " . trim($lines[$i]) . "\n";
        }
    }
    exit(1);
}

$actions = <<<'HEREDOC'

                    <!-- Actions column -->
                    <td style="text-align:center;white-space:nowrap;padding:6px 4px;">
                    <?php if ($r['_source'] === 'merchandise_transactions' && in_array($vs, ['approved','adjusted','completed','official','validated'])): ?>
                        <button type="button"
                            onclick="atoOpenRejectModal('merch',<?= (int)$r['row_id'] ?>,'<?= htmlspecialchars($start,ENT_QUOTES) ?>','<?= htmlspecialchars($end,ENT_QUOTES) ?>','<?= htmlspecialchars($status_f,ENT_QUOTES) ?>','<?= htmlspecialchars($search,ENT_QUOTES) ?>')"
                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;margin-bottom:3px;"
                            onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                            onmouseout="this.style.background='#fff';this.style.color='#dc2626'"
                            title="Return transaction to staff">
                            <i class="fas fa-undo-alt"></i> Return
                        </button><br>
                        <button type="button"
                            onclick="atoOpenAdjustModal(<?= (int)$r['row_id'] ?>,<?= (float)$r['amount'] ?>,'<?= htmlspecialchars($start,ENT_QUOTES) ?>','<?= htmlspecialchars($end,ENT_QUOTES) ?>','<?= htmlspecialchars($status_f,ENT_QUOTES) ?>','<?= htmlspecialchars($search,ENT_QUOTES) ?>')"
                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#6f42c1;border:1px solid #6f42c1;border-radius:5px;cursor:pointer;transition:all .15s;"
                            onmouseover="this.style.background='#6f42c1';this.style.color='#fff'"
                            onmouseout="this.style.background='#fff';this.style.color='#6f42c1'"
                            title="Adjust transaction amount">
                            <i class="fas fa-sliders-h"></i> Adjust
                        </button>
                    <?php elseif ($r['_source'] === 'job_orders' && in_array($vs, ['approved','in progress','pending'])): ?>
                        <button type="button"
                            onclick="atoOpenRejectModal('jo',<?= (int)$r['row_id'] ?>,'<?= htmlspecialchars($start,ENT_QUOTES) ?>','<?= htmlspecialchars($end,ENT_QUOTES) ?>','<?= htmlspecialchars($status_f,ENT_QUOTES) ?>','<?= htmlspecialchars($search,ENT_QUOTES) ?>','job_orders')"
                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;"
                            onmouseover="this.style.background='#dc2626';this.style.color='#fff'"
                            onmouseout="this.style.background='#fff';this.style.color='#dc2626'"
                            title="Reject job order">
                            <i class="fas fa-times-circle"></i> Reject
                        </button>
                    <?php else: ?>
                        <span style="color:#cbd5e1;font-size:10px;">&mdash;</span>
                    <?php endif; ?>
                    </td>
HEREDOC;

$actionLines = explode("\n", $actions);
array_splice($lines, $insertIdx, 0, $actionLines);

$out = implode("\r\n", $lines);
file_put_contents($file, $out);
echo "SUCCESS: Inserted " . count($actionLines) . " lines at index $insertIdx.\n";

$result = shell_exec('C:\xampp\php\php.exe -l "' . $file . '" 2>&1');
echo "Syntax: " . trim($result) . "\n";
