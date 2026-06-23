<?php
$file = 'c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php';
$content = file_get_contents($file);

// Find and replace the broken override block
$old = '// Track modal state to pause auto-refresh during admin actions
const originalAtoCloseModal = window.atoCloseModal;
window.atoCloseModal = function(id) {
    originalAtoCloseModal(id);
    isAdminModalOpen = false;
};

const originalAtoOpenRejectModal = window.atoOpenRejectModal;
window.atoOpenRejectModal = function(type, id, start, end, status, search, joSrc) {
    isAdminModalOpen = true;
    return originalAtoOpenRejectModal(type, id, start, end, status, search, joSrc);
};

const originalAtoOpenAdjustModal = window.atoOpenAdjustModal;
window.atoOpenAdjustModal = function(id, amount, start, end, status, search) {
    isAdminModalOpen = true;
    return originalAtoOpenAdjustModal(id, amount, start, end, status, search);
};

// Start auto-refresh timer (60 seconds - appropriate for admin oversight)
window.refreshAdminOversightTimer = setInterval(autoRefreshAdminOversight, 60000);

console.log(\'✅ Auto-refresh enabled for Admin Transactions Oversight (60s interval)\');';

$new = '// Track modal state to pause auto-refresh during admin actions
// Wrap modal functions using IIFE to safely capture their references
(function() {
    var _origClose  = atoCloseModal;
    var _origReject = atoOpenRejectModal;
    var _origAdjust = atoOpenAdjustModal;

    atoCloseModal = function(id) {
        _origClose(id);
        isAdminModalOpen = false;
    };
    atoOpenRejectModal = function(type, id, start, end, status, search, joSrc) {
        isAdminModalOpen = true;
        _origReject(type, id, start, end, status, search, joSrc);
    };
    atoOpenAdjustModal = function(id, amount, start, end, status, search) {
        isAdminModalOpen = true;
        _origAdjust(id, amount, start, end, status, search);
    };
})();

// Start auto-refresh timer (60 seconds - appropriate for admin oversight)
setInterval(autoRefreshAdminOversight, 60000);';

// Normalize line endings for comparison
$content_norm = str_replace("\r\n", "\n", $content);
$old_norm = str_replace("\r\n", "\n", $old);

if (strpos($content_norm, $old_norm) !== false) {
    $content_norm = str_replace($old_norm, $new, $content_norm);
    // Put back Windows line endings
    $content_out = str_replace("\n", "\r\n", $content_norm);
    file_put_contents($file, $content_out);
    echo "SUCCESS: Replacement made.\n";
} else {
    echo "ERROR: Target string not found. Trying partial match...\n";
    // Try to find just the key part
    $key = 'const originalAtoCloseModal = window.atoCloseModal;';
    $key_norm = str_replace("\r\n", "\n", $key);
    if (strpos($content_norm, $key_norm) !== false) {
        echo "Found key line - content is there but whitespace may differ.\n";
    } else {
        echo "Key line not found either.\n";
    }
}
