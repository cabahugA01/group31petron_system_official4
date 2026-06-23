<?php
$file = 'c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php';
$content = file_get_contents($file);
$lines = explode("\n", str_replace("\r\n", "\n", $content));

// Find the staff_name td followed immediately by </tr> and <?php endforeach; ?>
// Looking for the pattern ending the row
$startLine = -1;
for ($i = 0; $i < count($lines); $i++) {
    if (strpos($lines[$i], "mb_strimwidth(\$r['staff_name']") !== false) {
        // Check if the next two lines close the td and tr
        if (isset($lines[$i+1]) && strpos($lines[$i+1], '</td>') !== false &&
            isset($lines[$i+2]) && strpos($lines[$i+2], '</tr>') !== false) {
            $startLine = $i + 1; // the </td> line
            break;
        }
    }
}

echo "Found staff_name end at line: $startLine\n";

if ($startLine < 0) {
    // Try alternative: find line with </tr> followed by endforeach
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], '</tr>') !== false && 
            isset($lines[$i+1]) && strpos($lines[$i+1], 'endforeach') !== false) {
            $startLine = $i - 1;
            echo "Found via endforeach at line: " . ($i-1) . "\n";
            for ($j = max(0, $i-5); $j <= $i+2; $j++) {
                echo "  [$j]: " . trim($lines[$j]) . "\n";
            }
            break;
        }
    }
    exit(1);
}

// Insert actions column after the staff_name </td> line (startLine)
$actionsBlock = [
    '',
    '                    <!-- Actions -->',
    '                    <td style="text-align:center;white-space:nowrap;padding:6px 4px;">',
    '                    <?php if ($r[\'_source\'] === \'merchandise_transactions\' && in_array($vs, [\'approved\',\'adjusted\',\'completed\',\'official\',\'validated\'])): ?>',
    '                        <button type="button"',
    '                            onclick="atoOpenRejectModal(\'merch\',<?= (int)$r[\'row_id\'] ?>,\'<?= htmlspecialchars($start,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($end,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($status_f,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($search,ENT_QUOTES) ?>\')"',
    '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;margin-bottom:3px;"',
    '                            onmouseover="this.style.background=\'#dc2626\';this.style.color=\'#fff\'"',
    '                            onmouseout="this.style.background=\'#fff\';this.style.color=\'#dc2626\'"',
    '                            title="Return transaction to staff for correction">',
    '                            <i class="fas fa-undo-alt"></i> Return',
    '                        </button><br>',
    '                        <button type="button"',
    '                            onclick="atoOpenAdjustModal(<?= (int)$r[\'row_id\'] ?>,<?= (float)$r[\'amount\'] ?>,\'<?= htmlspecialchars($start,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($end,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($status_f,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($search,ENT_QUOTES) ?>\')"',
    '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#6f42c1;border:1px solid #6f42c1;border-radius:5px;cursor:pointer;transition:all .15s;"',
    '                            onmouseover="this.style.background=\'#6f42c1\';this.style.color=\'#fff\'"',
    '                            onmouseout="this.style.background=\'#fff\';this.style.color=\'#6f42c1\'"',
    '                            title="Adjust transaction amount">',
    '                            <i class="fas fa-sliders-h"></i> Adjust',
    '                        </button>',
    '                    <?php elseif ($r[\'_source\'] === \'job_orders\' && in_array($vs, [\'approved\',\'in progress\',\'pending\'])): ?>',
    '                        <button type="button"',
    '                            onclick="atoOpenRejectModal(\'jo\',<?= (int)$r[\'row_id\'] ?>,\'<?= htmlspecialchars($start,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($end,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($status_f,ENT_QUOTES) ?>\',\'<?= htmlspecialchars($search,ENT_QUOTES) ?>\',\'job_orders\')"',
    '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;"',
    '                            onmouseover="this.style.background=\'#dc2626\';this.style.color=\'#fff\'"',
    '                            onmouseout="this.style.background=\'#fff\';this.style.color=\'#dc2626\'"',
    '                            title="Reject this job order">',
    '                            <i class="fas fa-times-circle"></i> Reject',
    '                        </button>',
    '                    <?php else: ?>',
    '                        <span style="color:#cbd5e1;font-size:10px;">&mdash;</span>',
    '                    <?php endif; ?>',
    '                    </td>',
];

// Insert after the closing </td> of staff_name (startLine is the </td> line index)
array_splice($lines, $startLine + 1, 0, $actionsBlock);

$out = implode("\r\n", $lines);
file_put_contents($file, $out);
echo "SUCCESS: Inserted " . count($actionsBlock) . " lines after line $startLine.\n";

// Verify syntax
$result = shell_exec('C:\xampp\php\php.exe -l "' . $file . '" 2>&1');
echo "Syntax check: $result";
