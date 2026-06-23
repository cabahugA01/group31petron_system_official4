<?php
// Load the oversight file
$file = 'C:/xampp/htdocs/group31petron_system_official4/public/admin_transactions_oversight.php';
$raw = file_get_contents($file);

// Normalize line endings 
$content = str_replace("\r\n", "\n", $raw);
$lines   = explode("\n", $content);

// Find "mb_strimwidth($r['staff_name']" line index
$staffLine = -1;
foreach ($lines as $i => $line) {
    if (strpos($line, "mb_strimwidth(\$r['staff_name']") !== false) {
        $staffLine = $i;
        break;
    }
}

if ($staffLine < 0) {
    die("ERROR: Could not find staff_name strimwidth line.\n");
}

// Check next lines
echo "Line $staffLine: " . trim($lines[$staffLine]) . "\n";
echo "Line " . ($staffLine+1) . ": " . trim($lines[$staffLine+1]) . "\n";
echo "Line " . ($staffLine+2) . ": " . trim($lines[$staffLine+2]) . "\n";
echo "Line " . ($staffLine+3) . ": " . trim($lines[$staffLine+3]) . "\n";

// We insert AFTER the </td> closing tag (staffLine+1) and BEFORE </tr> (staffLine+2)
// So insertion index = staffLine+2
$insertAt = $staffLine + 2;

// Build the actions block as an array of strings (no PHP tags, just raw HTML+PHP mixed strings)
// We use chr(60) for < and chr(63) for ? to avoid PHP parsing inside our script
$lt  = '<';
$gt  = '>';
$php_open  = $lt . '?php';
$php_echo  = $lt . '?=';
$php_close = '?' . $gt;

$block = [];
$block[] = '';
$block[] = '                    <!-- Actions -->';
$block[] = '                    ' . $lt . 'td style="text-align:center;white-space:nowrap;padding:6px 4px;"' . $gt;

// Merchandise condition
$block[] = '                    ' . $php_open . " if (\$r['_source'] === 'merchandise_transactions' && in_array(\$vs, ['approved','adjusted','completed','official','validated'])): " . $php_close;

// Return button
$block[] = '                        ' . $lt . 'button type="button"';
$block[] = "                            onclick=\"atoOpenRejectModal('merch'," . $php_echo . " (int)\$r['row_id'] " . $php_close . ",'" . $php_echo . " htmlspecialchars(\$start,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$end,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$status_f,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$search,ENT_QUOTES) " . $php_close . "')\"";
$block[] = '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;margin-bottom:3px;"';
$block[] = "                            onmouseover=\"this.style.background='#dc2626';this.style.color='#fff'\"";
$block[] = "                            onmouseout=\"this.style.background='#fff';this.style.color='#dc2626'\"";
$block[] = '                            title="Return transaction to staff">';
$block[] = '                            ' . $lt . 'i class="fas fa-undo-alt"' . $gt . $lt . '/i' . $gt . ' Return';
$block[] = '                        ' . $lt . '/button' . $gt . $lt . 'br' . $gt;

// Adjust button
$block[] = '                        ' . $lt . 'button type="button"';
$block[] = "                            onclick=\"atoOpenAdjustModal(" . $php_echo . " (int)\$r['row_id'] " . $php_close . "," . $php_echo . " (float)\$r['amount'] " . $php_close . ",'" . $php_echo . " htmlspecialchars(\$start,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$end,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$status_f,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$search,ENT_QUOTES) " . $php_close . "')\"";
$block[] = '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#6f42c1;border:1px solid #6f42c1;border-radius:5px;cursor:pointer;transition:all .15s;"';
$block[] = "                            onmouseover=\"this.style.background='#6f42c1';this.style.color='#fff'\"";
$block[] = "                            onmouseout=\"this.style.background='#fff';this.style.color='#6f42c1'\"";
$block[] = '                            title="Adjust transaction amount">';
$block[] = '                            ' . $lt . 'i class="fas fa-sliders-h"' . $gt . $lt . '/i' . $gt . ' Adjust';
$block[] = '                        ' . $lt . '/button' . $gt;

// Job order condition
$block[] = '                    ' . $php_open . " elseif (\$r['_source'] === 'job_orders' && in_array(\$vs, ['approved','in progress','pending'])): " . $php_close;
$block[] = '                        ' . $lt . 'button type="button"';
$block[] = "                            onclick=\"atoOpenRejectModal('jo'," . $php_echo . " (int)\$r['row_id'] " . $php_close . ",'" . $php_echo . " htmlspecialchars(\$start,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$end,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$status_f,ENT_QUOTES) " . $php_close . "','" . $php_echo . " htmlspecialchars(\$search,ENT_QUOTES) " . $php_close . "','job_orders')\"";
$block[] = '                            style="display:inline-flex;align-items:center;gap:3px;padding:4px 8px;font-size:10px;font-weight:600;background:#fff;color:#dc2626;border:1px solid #dc2626;border-radius:5px;cursor:pointer;transition:all .15s;"';
$block[] = "                            onmouseover=\"this.style.background='#dc2626';this.style.color='#fff'\"";
$block[] = "                            onmouseout=\"this.style.background='#fff';this.style.color='#dc2626'\"";
$block[] = '                            title="Reject job order">';
$block[] = '                            ' . $lt . 'i class="fas fa-times-circle"' . $gt . $lt . '/i' . $gt . ' Reject';
$block[] = '                        ' . $lt . '/button' . $gt;

// Else / endif
$block[] = '                    ' . $php_open . ' else: ' . $php_close;
$block[] = '                        ' . $lt . 'span style="color:#cbd5e1;font-size:10px;"' . $gt . '&mdash;' . $lt . '/span' . $gt;
$block[] = '                    ' . $php_open . ' endif; ' . $php_close;
$block[] = '                    ' . $lt . '/td' . $gt;

// Splice in the block
array_splice($lines, $insertAt, 0, $block);

$out = implode("\r\n", $lines);
file_put_contents($file, $out);
echo "SUCCESS: Inserted " . count($block) . " lines at index $insertAt.\n";

// Syntax check
$result = shell_exec('C:\xampp\php\php.exe -l "' . $file . '" 2>&1');
echo "Syntax: " . trim($result) . "\n";
