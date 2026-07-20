<?php
/**
 * Compatibility bridge for session flash messages.
 * Uses the shared top-right toast helper from partials/header.php.
 */
$__ft_map = [
    'success' => ['type' => 'success', 'keys' => ['success', 'flash_success', 'ok']],
    'error'   => ['type' => 'error',   'keys' => ['error', 'flash_error', 'err']],
    'warning' => ['type' => 'warning', 'keys' => ['warning', 'flash_warning']],
    'info'    => ['type' => 'info',    'keys' => ['info', 'flash_info']],
];

$__ft_messages = [];
foreach ($__ft_map as $__ft_meta) {
    foreach ($__ft_meta['keys'] as $__ft_key) {
        if (!empty($_SESSION[$__ft_key])) {
            $__ft_messages[] = [
                'type' => $__ft_meta['type'],
                'message' => (string) $_SESSION[$__ft_key],
            ];
            unset($_SESSION[$__ft_key]);
        }
    }
}

if (!$__ft_messages) {
    return;
}
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var messages = <?php echo json_encode($__ft_messages, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function fallbackToast(message, type) {
        var container = document.getElementById('petron-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'petron-toast-container';
            container.style.cssText = 'position:fixed;top:84px;right:22px;z-index:2147483000;display:flex;flex-direction:column;gap:10px;width:min(390px,calc(100vw - 32px));pointer-events:none;';
            document.body.appendChild(container);
        }

        var colors = {
            success: '#16a34a',
            error: '#dc2626',
            warning: '#f59e0b',
            info: '#2563eb'
        };
        var toast = document.createElement('div');
        toast.style.cssText = 'position:relative;width:100%;padding:13px 42px 13px 15px;border-radius:8px;border:1px solid #dbe4f0;border-left:5px solid ' + (colors[type] || colors.info) + ';background:#fff;color:#0f172a;font:600 14px/1.4 Arial,sans-serif;box-shadow:0 12px 28px rgba(15,23,42,.18);pointer-events:auto;';
        toast.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close notification');
        close.innerHTML = '&times;';
        close.style.cssText = 'position:absolute;top:9px;right:10px;width:24px;height:24px;border:0;border-radius:50%;background:transparent;color:#64748b;cursor:pointer;font-size:18px;line-height:1;';
        close.onclick = function() { toast.remove(); };
        toast.appendChild(close);

        container.appendChild(toast);
        setTimeout(function() { toast.style.opacity = '0'; toast.style.transform = 'translateX(34px)'; toast.style.transition = 'all .35s ease'; }, 4000);
        setTimeout(function() { if (toast.parentNode) toast.remove(); }, 4400);
    }

    messages.forEach(function(item) {
        if (window.showPetronFlash) {
            window.showPetronFlash(item.message, item.type, 4000);
        } else if (window.showToast) {
            window.showToast(item.message, item.type, 4000);
        } else {
            fallbackToast(item.message, item.type);
        }
    });
});
</script>
