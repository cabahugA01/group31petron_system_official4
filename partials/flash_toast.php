<?php
/**
 * partials/flash_toast.php
 * Drop-in replacement for inline SESSION flash messages.
 * Renders a fixed-position animated toast at the top of the viewport.
 * Usage: require __DIR__ . '/../partials/flash_toast.php';
 */
$_ft_success = $_SESSION['success'] ?? null; unset($_SESSION['success']);
$_ft_error  = $_SESSION['error']  ?? null; unset($_SESSION['error']);
if (!$_ft_success && !$_ft_error) return; // nothing to show
?>
<!-- ══ FLASH TOAST ══ -->
<style>
#petron-toast {  position: fixed;  top: 20px;  left: 50%;  transform: translateX(-50%) translateY(-80px);  z-index: 99999;  min-width: 340px;  max-width: 620px;  padding: 14px 20px;  border-radius: 10px;  font-size: 14px;  font-weight: 600;  display: flex;  align-items: center;  gap: 10px;  box-shadow: 0 8px 24px rgba(0,0,0,0.18);  opacity: 0;  transition: transform 0.38s cubic-bezier(0.34,1.56,0.64,1), opacity 0.32s ease;  pointer-events: none;  white-space: nowrap;
}
#petron-toast.show {  transform: translateX(-50%) translateY(0);  opacity: 1;  pointer-events: auto;
}
#petron-toast.toast-success {  background: #16a34a;  color: #fff;  border: 1.5px solid #15803d;
}
#petron-toast.toast-error {  background: #dc2626;  color: #fff;  border: 1.5px solid #b91c1c;
}
#petron-toast .toast-icon { font-size: 18px; flex-shrink: 0; }
#petron-toast .toast-msg  { flex: 1; white-space: normal; line-height: 1.4; }
#petron-toast .toast-close {  background: none;  border: none;  color: rgba(255,255,255,0.8);  font-size: 18px;  cursor: pointer;  padding: 0 0 0 8px;  flex-shrink: 0;  line-height: 1;
}
#petron-toast .toast-close:hover { color: #fff; }
</style>

<?php if ($_ft_success): ?>
<div id="petron-toast" class="toast-success" role="alert">  <span class="toast-icon"><i class="fas fa-check-circle"></i></span>  <span class="toast-msg"><?= htmlspecialchars($_ft_success) ?></span>  <button class="toast-close" onclick="closePetronToast()" title="Close">&times;</button>
</div>
<?php elseif ($_ft_error): ?>
<div id="petron-toast" class="toast-error" role="alert">  <span class="toast-icon"><i class="fas fa-exclamation-circle"></i></span>  <span class="toast-msg"><?= htmlspecialchars($_ft_error) ?></span>  <button class="toast-close" onclick="closePetronToast()" title="Close">&times;</button>
</div>
<?php endif; ?>

<script>
(function() {  var t = document.getElementById('petron-toast');  if (!t) return;  // Slide in after a tiny delay so transition fires  setTimeout(function() { t.classList.add('show'); }, 60);  // Auto-dismiss after 5 seconds  var autoHide = setTimeout(function() { closePetronToast(); }, 5500);  window.closePetronToast = function() {  clearTimeout(autoHide);  t.classList.remove('show');  setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 400);  };
})();
</script>
