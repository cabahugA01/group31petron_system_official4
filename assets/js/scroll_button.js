// Simple Scroll Toggle Button - silent/no-console version
(function initScrollButton() {
    if (!document.body) return;
    if (document.getElementById('scrollToggleBtn')) return;

    const scrollBtn = document.createElement('button');
    scrollBtn.id = 'scrollToggleBtn';
    scrollBtn.innerHTML = '<i class="fas fa-arrow-down"></i>';
    scrollBtn.style.cssText = `
        position: fixed !important;
        bottom: 20px !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        width: 45px !important;
        height: 45px !important;
        background: linear-gradient(135deg, #002F6C, #0040a0) !important;
        border: 2px solid white !important;
        border-radius: 50% !important;
        color: white !important;
        font-size: 16px !important;
        cursor: pointer !important;
        z-index: 9999 !important;
        display: none !important;
        align-items: center !important;
        justify-content: center !important;
        box-shadow: 0 4px 12px rgba(0, 47, 112, 0.4) !important;
        transition: all 0.3s ease !important;
    `;
    document.body.appendChild(scrollBtn);

    let isAtBottom = false;

    function updateScrollButton() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop || 0;
        const scrollHeight = document.documentElement.scrollHeight || 0;
        const clientHeight = window.innerHeight || 0;
        isAtBottom = (scrollTop + clientHeight) >= (scrollHeight - 50);

        scrollBtn.style.display = scrollTop > 100 ? 'flex' : 'none';

        const icon = scrollBtn.querySelector('i');
        if (!icon) return;
        if (isAtBottom) {
            icon.className = 'fas fa-arrow-up';
            scrollBtn.title = 'Scroll to top';
        } else {
            icon.className = 'fas fa-arrow-down';
            scrollBtn.title = 'Scroll to bottom';
        }
    }

    scrollBtn.addEventListener('click', function() {
        if (isAtBottom) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        } else {
            window.scrollTo({ top: document.body.scrollHeight || 0, behavior: 'smooth' });
        }
    });

    window.addEventListener('scroll', updateScrollButton, { passive: true });
    window.addEventListener('resize', updateScrollButton);
    setTimeout(updateScrollButton, 100);
})();
