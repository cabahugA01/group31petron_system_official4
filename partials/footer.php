
  </main>

  <style>
    .fixed-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 40px !important;
        background-color: #ffffff !important;
        border-top: 1px solid #e0e0e0 !important;
        z-index: 990 !important;
        display: flex !important;
        align-items: center !important;
        font-size: 0.85em !important;
        color: #666666 !important;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.1) !important;
    }
    
    /* Footer always attached to sidebar - full width */
    @media (max-width: 991px) {
        .fixed-footer {
            left: 0 !important;
            width: 100% !important;
        }
    }
    
    /* Ensure footer is always visible */
    .fixed-footer * {
        pointer-events: auto !important;
    }
    
    .footer-sidebar-area {
        width: 280px !important;
        height: 100% !important;
        background-color: #ffffff !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        color: #666666 !important;
        font-size: 0.85em !important;
        font-weight: 500 !important;
        transition: width 0.3s ease !important;
        border-right: 1px solid #e0e0e0 !important;
        overflow: hidden !important;
        padding: 0 10px !important;
        box-sizing: border-box !important;
    }

    .footer-identity {
        display: flex !important;
        align-items: center !important;
        font-size: 11px !important;
        font-weight: 700 !important;
        color: var(--petron-blue, #00264D) !important;
        letter-spacing: 0.4px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        max-width: 100% !important;
    }

    .footer-identity-text {
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    /* Hide identity text when sidebar is collapsed, show only icon */
    body.sidebar-collapsed .footer-identity-text {
        display: none !important;
    }

    body.sidebar-collapsed .footer-identity i {
        margin-right: 0 !important;
    }
    
    .footer-content {
        flex: 1 !important;
        display: flex !important;
        align-items: center !important;
        padding: 0 20px !important;
        height: 100% !important;
        margin-left: 0 !important;
    }
    
    .footer-left {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-shrink: 0 !important;
    }
    
    .footer-center {
        flex-grow: 1 !important;
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
    }
    
    .footer-right {
        display: flex !important;
        align-items: center !important;
        gap: 20px !important;
        flex-shrink: 0 !important;
    }
    
    /* Sidebar collapsed state */
    body.sidebar-collapsed .footer-sidebar-area {
        width: 70px !important;
    }
    
    .footer-text {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
    }
    
    .footer-clock {
        font-size: 0.85em !important;
        color: #666666 !important;
        font-weight: 500 !important;
        white-space: nowrap !important;
    }
    
    .footer-clock i {
        margin-right: 5px !important;
        color: var(--petron-blue) !important;
    }
    
    /* Footer Toggle Button Styling */
    .footer-toggle {
        background: var(--petron-blue) !important;
        border: none !important;
        color: white !important;
        font-size: 14px !important;
        cursor: pointer !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        transition: all 0.3s ease !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin-right: 15px !important;
        min-width: 36px !important;
        height: 36px !important;
    }

    .footer-toggle:hover {
        background: #0040a0 !important;
        transform: translateY(-1px) !important;
        box-shadow: 0 2px 8px rgba(0, 47, 112, 0.3) !important;
    }

    .footer-toggle:active {
        transform: translateY(0) !important;
    }
    
    .footer-toggle i {
        font-size: 14px !important;
        margin: 0 !important;
    }
    
    /* Override any conflicting styles */
    body {
        padding-bottom: 40px !important; /* Account for fixed footer */
    }
    
    main {
        padding-bottom: 60px !important; /* Account for fixed footer */
    }
    
    /* Toggle Scroll Button Styling */
    .toggle-scroll-btn {
        position: fixed;
        bottom: 40px; /* flush against the top of the footer — out of content area */
        right: 20px;
        width: 40px;
        height: 40px;
        background: var(--petron-blue, #002F6C);
        border: 2px solid #ffffff;
        border-radius: 50%;
        color: white;
        font-size: 14px;
        cursor: pointer;
        z-index: 10001; /* Above footer (9999) */
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 14px rgba(0, 47, 112, 0.35), 0 2px 4px rgba(0,0,0,0.12);
        /* Hidden by default — shown only when scroll is needed */
        opacity: 0;
        transform: scale(0.75) translateY(8px);
        pointer-events: none;
        transition: opacity 0.25s ease, transform 0.25s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .toggle-scroll-btn.visible {
        opacity: 1;
        transform: scale(1) translateY(0);
        pointer-events: auto;
    }

    .toggle-scroll-btn:hover {
        background: #0040a0;
        box-shadow: 0 6px 18px rgba(0, 47, 112, 0.45), 0 3px 6px rgba(0,0,0,0.15);
        transform: scale(1.08) translateY(-1px);
    }

    .toggle-scroll-btn:active {
        transform: scale(0.94) translateY(0);
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.25);
    }

    /* Red highlight while the page is scrolling */
    .toggle-scroll-btn.scrolling {
        background: var(--petron-red, #E30613) !important;
        border-color: #ffffff !important;
        box-shadow: 0 4px 16px rgba(227, 6, 19, 0.5), 0 2px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.12) translateY(-1px) !important;
    }

    /* Arrow icon — no CSS rotation needed, icon class is swapped directly in JS */
    .toggle-scroll-btn i {
        display: block;
        line-height: 1;
        transition: none;
    }

    /* .arrow-up is kept as a state marker for JS but does NOT rotate the icon */
    .toggle-scroll-btn.arrow-up i {
        /* intentionally empty — icon swap handled in JS */
    }

    /* Mobile */
    @media (max-width: 768px) {
        .toggle-scroll-btn {
            width: 36px;
            height: 36px;
            font-size: 13px;
            bottom: 40px;
            right: 12px;
        }
    }
</style>

  <!-- TOGGLE SCROLL BUTTON — fixed bottom-right, above footer -->
  <button id="toggleScrollBtn" class="toggle-scroll-btn" aria-label="Scroll to bottom" title="Scroll to bottom">
    <i class="fas fa-arrow-down"></i>
  </button>

  <!-- FIXED FOOTER -->
  <footer class="fixed-footer">
    <div class="footer-sidebar-area" id="footerSidebarArea">
      <?php
      // Show user identity in sidebar footer: FIRSTNAME LASTNAME – ROLE
      if (isset($_SESSION['user'])) {
          $fu = $_SESSION['user'];
          $fu_first = trim($fu['first_name'] ?? '');
          $fu_last  = trim($fu['last_name']  ?? '');
          if ($fu_first !== '' || $fu_last !== '') {
              $fu_name = strtoupper(trim("$fu_first $fu_last"));
          } else {
              $fu_name = strtoupper($fu['name'] ?? $fu['username'] ?? 'USER');
          }
          $fu_role = strtoupper(function_exists('normalize_role') ? normalize_role($fu['role'] ?? 'Staff') : ucfirst(strtolower($fu['role'] ?? 'Staff')));
          echo '<span class="footer-identity" title="' . htmlspecialchars("$fu_name – $fu_role") . '">'
             . '<i class="fas fa-user-circle" style="margin-right:5px;color:var(--petron-blue,#00264D);font-size:13px;"></i>'
             . '<span class="footer-identity-text">'
             . htmlspecialchars($fu_name) . ' <span style="color:#aaa;">–</span> ' . htmlspecialchars($fu_role)
             . '</span></span>';
      }
      ?>
    </div>
    <div class="footer-content">
      <div class="footer-left">
        <!-- Left content can be added here if needed -->
      </div>
      <div class="footer-center">
        <span class="footer-text">© 2026 Petron Management System. All rights reserved.</span>
      </div>
      <div class="footer-right">
        <span id="footer-clock" class="footer-clock"></span>
      </div>
    </div>
    
    <script>
        // Footer is now attached to sidebar - no positioning logic needed
        // Footer automatically adjusts with sidebar CSS transitions
    </script>
  </footer>

  <div class="toast" id="toast"></div>
  
  <!-- Bootstrap JavaScript -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  
  <script src="../assets/js/app.js"></script>
</main>

  <script>
    function updateFooterClock() {
        const footerClock = document.getElementById('footer-clock');
        if (!footerClock) return;
        const now = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        footerClock.innerHTML = '<i class="far fa-clock"></i> ' + now.toLocaleDateString('en-US', options);
    }
    setInterval(updateFooterClock, 1000);
    updateFooterClock();
    
    // Toggle Scroll Button — targets .main (the real scrollable container on desktop)
    (function () {
        'use strict';

        var btn = document.getElementById('toggleScrollBtn');
        if (!btn) return;

        // The scrollable container is <main class="main"> on desktop (body overflow:hidden).
        // On mobile the page itself scrolls, so we fall back to document.documentElement.
        function getScroller() {
            var main = document.querySelector('main.main');
            if (main && main.scrollHeight > main.clientHeight) return main;
            // fallback: window / documentElement
            return null;
        }

        var isVisible  = false;
        var isAtBottom = false;

        function checkScrollNeeded(scroller) {
            if (scroller) {
                return scroller.scrollHeight > scroller.clientHeight + 4;
            }
            return document.documentElement.scrollHeight > window.innerHeight + 4;
        }

        function getScrollTop(scroller) {
            return scroller ? scroller.scrollTop
                            : (window.pageYOffset || document.documentElement.scrollTop);
        }

        function getScrollMax(scroller) {
            if (scroller) return scroller.scrollHeight - scroller.clientHeight;
            return document.documentElement.scrollHeight - window.innerHeight;
        }

        function update() {
            var scroller   = getScroller();
            var needed     = checkScrollNeeded(scroller);
            var scrollTop  = getScrollTop(scroller);
            var scrollMax  = getScrollMax(scroller);

            // Hide entirely when content fits on screen
            if (!needed) {
                if (isVisible) { btn.classList.remove('visible'); isVisible = false; }
                return;
            }

            // At bottom = within 6px of max scroll
            isAtBottom = scrollTop >= scrollMax - 6;

            // Show button whenever scroll is possible
            if (!isVisible) { btn.classList.add('visible'); isVisible = true; }

            var icon = btn.querySelector('i');

            if (isAtBottom) {
                // User is at the BOTTOM → arrow points UP → click will scroll to top
                btn.classList.add('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to top');
                btn.setAttribute('title', 'Scroll to top');
                if (icon) { icon.className = 'fas fa-arrow-up'; icon.style.transform = ''; }
            } else {
                // User is at the TOP or middle → arrow points DOWN → click will scroll to bottom
                btn.classList.remove('arrow-up');
                btn.setAttribute('aria-label', 'Scroll to bottom');
                btn.setAttribute('title', 'Scroll to bottom');
                if (icon) { icon.className = 'fas fa-arrow-down'; icon.style.transform = ''; }
            }
        }

        // Red highlight while scrolling is in progress
        var scrollingTimer = null;
        function markScrolling() {
            btn.classList.add('scrolling');
            clearTimeout(scrollingTimer);
            scrollingTimer = setTimeout(function () {
                btn.classList.remove('scrolling');
            }, 600); // stays red for 600ms after scroll stops
        }

        function doScroll() {
            var scroller = getScroller();
            if (isAtBottom) {
                if (scroller) scroller.scrollTo({ top: 0, behavior: 'smooth' });
                else window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                if (scroller) scroller.scrollTo({ top: scroller.scrollHeight, behavior: 'smooth' });
                else window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
            }
            // Flip icon immediately after click so it feels responsive
            setTimeout(update, 400);
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            doScroll();
        });

        // Attach scroll listener to the right target
        function attachScrollListener() {
            var scroller = document.querySelector('main.main');
            if (scroller) {
                scroller.addEventListener('scroll', function () {
                    markScrolling();
                    update();
                }, { passive: true });
            }
            // Also listen on window for mobile fallback
            window.addEventListener('scroll', function () {
                markScrolling();
                update();
            }, { passive: true });
        }

        // Re-check on resize (content height may change)
        window.addEventListener('resize', function () { setTimeout(update, 80); }, { passive: true });

        // Init
        attachScrollListener();
        // Run after DOM + any dynamic content settles
        setTimeout(update, 150);
        setTimeout(update, 600);
    })();
  </script>
</body>
</html>
