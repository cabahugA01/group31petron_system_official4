<?php
$file = 'c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php';
$content = file_get_contents($file);
$lines = explode("\n", str_replace("\r\n", "\n", $content));

// Find the block by searching for specific key lines
$startLine = -1;
$endLine   = -1;

foreach ($lines as $i => $line) {
    if (strpos($line, 'const originalAtoCloseModal = window.atoCloseModal') !== false) {
        // Search backwards for the comment line
        $startLine = $i - 1; // "// Track modal state..."
        if ($startLine >= 0 && strpos($lines[$startLine], '// Track modal state') === false) {
            $startLine = $i; // fallback to this line if comment not before it
        }
    }
    if ($startLine >= 0 && strpos($line, "console.log('") !== false && strpos($line, 'Auto-refresh') !== false && $i > $startLine) {
        $endLine = $i;
        break;
    }
}

echo "Start: $startLine, End: $endLine\n";
if ($startLine < 0 || $endLine < 0) {
    echo "Could not find block. Dumping lines 1832-1858:\n";
    for ($i = 1832; $i <= 1858; $i++) {
        echo "[$i]: " . (isset($lines[$i]) ? var_export($lines[$i], true) : 'N/A') . "\n";
    }
    exit(1);
}

$replacement = [
    '// Track modal state to pause auto-refresh during admin actions',
    '// Wrap modal functions using IIFE to safely capture their references',
    '(function() {',
    '    var _origClose  = atoCloseModal;',
    '    var _origReject = atoOpenRejectModal;',
    '    var _origAdjust = atoOpenAdjustModal;',
    '',
    '    atoCloseModal = function(id) {',
    '        _origClose(id);',
    '        isAdminModalOpen = false;',
    '    };',
    '    atoOpenRejectModal = function(type, id, start, end, status, search, joSrc) {',
    '        isAdminModalOpen = true;',
    '        _origReject(type, id, start, end, status, search, joSrc);',
    '    };',
    '    atoOpenAdjustModal = function(id, amount, start, end, status, search) {',
    '        isAdminModalOpen = true;',
    '        _origAdjust(id, amount, start, end, status, search);',
    '    };',
    '})();',
    '',
    '// Start auto-refresh timer (60 seconds - appropriate for admin oversight)',
    'setInterval(autoRefreshAdminOversight, 60000);',
];

// Replace lines from $startLine to $endLine (inclusive)
array_splice($lines, $startLine, $endLine - $startLine + 1, $replacement);

$out = implode("\r\n", $lines);
file_put_contents($file, $out);
echo "SUCCESS: Replaced lines $startLine to $endLine with " . count($replacement) . " lines.\n";
