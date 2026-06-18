
  </main>

  <style>
    /* ── Footer: strip ALL underlines (Bootstrap 5 sets a { text-decoration: underline } globally) ── */
    .fixed-footer,
    .fixed-footer *,
    .fixed-footer a,
    .fixed-footer a:hover,
    .fixed-footer a:visited,
    .fixed-footer a:focus,
    .fixed-footer span,
    .fixed-footer .footer-text,
    .fixed-footer .footer-clock,
    .fixed-footer .footer-identity,
    .fixed-footer .footer-identity-text {
        text-decoration: none !important;
    }

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
        width: 250px !important;
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
        padding-bottom: 110px !important; /* fixed footer (40px) + scroll btn (50px from bottom, 40px tall) = 90px, +20px buffer */
    }
    
    /* Toggle Scroll Button Styling */
    .toggle-scroll-btn {
        position: fixed !important;
        bottom: 50px !important; /* sits just above the 40px footer */
        right: 20px !important;
        width: 40px !important;
        height: 40px !important;
        background: var(--petron-blue, #002F6C) !important;
        border: 2px solid #ffffff !important;
        border-radius: 50% !important;
        color: white !important;
        font-size: 14px !important;
        cursor: pointer !important;
        z-index: 10001 !important; /* Above footer (990) and modals */
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        box-shadow: 0 4px 14px rgba(0, 47, 112, 0.35), 0 2px 4px rgba(0,0,0,0.12) !important;
        /* Hidden by default — shown only when scroll is needed */
        opacity: 0 !important;
        transform: scale(0.75) translateY(8px) !important;
        pointer-events: none !important;
        transition: opacity 0.25s ease, transform 0.25s ease, background 0.2s ease, box-shadow 0.2s ease !important;
    }

    .toggle-scroll-btn.visible {
        opacity: 1 !important;
        transform: scale(1) translateY(0) !important;
        pointer-events: auto !important;
    }

    .toggle-scroll-btn:hover {
        background: #0040a0 !important;
        box-shadow: 0 6px 18px rgba(0, 47, 112, 0.45), 0 3px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.08) translateY(-1px) !important;
    }

    .toggle-scroll-btn:active {
        transform: scale(0.94) translateY(0) !important;
        box-shadow: 0 2px 6px rgba(0, 47, 112, 0.25) !important;
    }

    /* Red highlight while the page is scrolling */
    .toggle-scroll-btn.scrolling {
        background: var(--petron-red, #E30613) !important;
        border-color: #ffffff !important;
        box-shadow: 0 4px 16px rgba(227, 6, 19, 0.5), 0 2px 6px rgba(0,0,0,0.15) !important;
        transform: scale(1.12) translateY(-1px) !important;
    }

    /* Arrow icon */
    .toggle-scroll-btn i {
        display: block !important;
        line-height: 1 !important;
        transition: none !important;
    }

    .toggle-scroll-btn.arrow-up i {
        /* intentionally empty — icon swap handled in JS */
    }

    /* Mobile */
    @media (max-width: 768px) {
        .toggle-scroll-btn {
            width: 36px !important;
            height: 36px !important;
            font-size: 13px !important;
            bottom: 50px !important;
            right: 12px !important;
        }
    }

    /* ══ GLOBAL PRINT — hide all system chrome ══════════════════════ */
    @media print {
        .fixed-footer,
        .footer-sidebar-area,
        .footer-content,
        .toggle-scroll-btn,
        #toggleScrollBtn,
        .toast {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            overflow: hidden !important;
        }
        body {
            padding-bottom: 0 !important;
        }
        main {
            padding-bottom: 0 !important;
        }
    }

    /* Clean Rows per page select dropdown */
    .rows-select {
        background-color: #ffffff !important;
        background: #ffffff !important;
        color: #333333 !important;
        border: 1px solid #cbd5e1 !important;
        outline: none !important;
        box-shadow: none !important;
    }
</style>

  <!-- TOGGLE SCROLL BUTTON — injected into body by JS to avoid fixed-in-overflow-container bug -->
  <script>
  (function() {
    // Remove any button that may have been rendered inside <main>
    var existing = document.getElementById('toggleScrollBtn');
    if (existing) existing.remove();

    // Create fresh button as a direct child of <body>
    var btn = document.createElement('button');
    btn.id = 'toggleScrollBtn';
    btn.className = 'toggle-scroll-btn';
    btn.setAttribute('aria-label', 'Scroll to bottom');
    btn.setAttribute('title', 'Scroll to bottom');
    btn.innerHTML = '<i class="fas fa-arrow-down"></i>';
    document.body.appendChild(btn);
  })();
  </script>

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

    // ══ Universal Export & Pagination Helpers ══
    function exportTableToCSV(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var csv = [];
        var headers = [];
        var ths = table.querySelectorAll('thead th');
        ths.forEach(function(th) {
            if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') return;
            headers.push('"' + th.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(headers.join(','));
        
        var rows = table.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            if (row.style.display === 'none' || row.classList.contains('search-hidden')) return;
            var cols = row.querySelectorAll('td');
            if (cols.length === 0) return;
            var rowData = [];
            var skipIdx = -1;
            ths.forEach(function(th, idx) {
                if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') {
                    skipIdx = idx;
                }
            });
            cols.forEach(function(col, idx) {
                if (idx === skipIdx) return;
                var text = col.innerText || col.textContent;
                text = text.trim().replace(/\s+/g, ' ');
                rowData.push('"' + text.replace(/"/g, '""') + '"');
            });
            csv.push(rowData.join(','));
        });
        
        var blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", filename || 'export.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function exportTableToExcel(tableId, filename) {
        var table = document.getElementById(tableId);
        if (!table) return;
        
        // Clone table and strip off action column
        var clone = table.cloneNode(true);
        var headers = clone.querySelectorAll('thead th');
        var skipIdx = -1;
        headers.forEach(function(th, idx) {
            if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') {
                skipIdx = idx;
                th.remove();
            }
        });
        if (skipIdx !== -1) {
            clone.querySelectorAll('tbody tr').forEach(function(tr) {
                var tds = tr.querySelectorAll('td');
                if (tds[skipIdx]) tds[skipIdx].remove();
            });
        }
        
        var html = clone.outerHTML;
        var blob = new Blob(['\ufeff' + html], {
            type: 'application/vnd.ms-excel'
        });
        var link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.setAttribute("download", filename || 'export.xls');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function exportTableToPDF(tableId, title) {
        var table = document.getElementById(tableId);
        if (!table) return;
        
        var win = window.open('', '', 'height=700,width=900');
        win.document.write('<html><head><title>' + (title || 'Export') + '</title>');
        win.document.write('<style>');
        win.document.write('body { font-family: sans-serif; padding: 20px; color: #333; }');
        win.document.write('h1 { color: #002F6C; font-size: 20px; margin-bottom: 20px; text-align: center; }');
        win.document.write('table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }');
        win.document.write('th { background-color: #002F6C; color: #fff; text-align: left; padding: 8px; font-weight: bold; text-transform: uppercase; }');
        win.document.write('td { border-bottom: 1px solid #ddd; padding: 8px; }');
        win.document.write('tr:nth-child(even) { background-color: #f9f9f9; }');
        win.document.write('.badge, .sbadge { font-weight: bold; text-transform: uppercase; font-size: 9px; padding: 2px 6px; border-radius: 4px; display: inline-block; }');
        win.document.write('.no-print { display: none !important; }');
        win.document.write('</style></head><body>');
        win.document.write('<h1>' + (title || 'Petron Inventory Report') + '</h1>');
        win.document.write('<p style="text-align:center;font-size:11px;color:#666;">Generated on: ' + new Date().toLocaleString() + '</p>');
        
        var clone = table.cloneNode(true);
        var headers = clone.querySelectorAll('thead th');
        var skipIdx = -1;
        headers.forEach(function(th, idx) {
            if (th.textContent.trim().toLowerCase() === 'actions' || th.textContent.trim() === '') {
                skipIdx = idx;
                th.classList.add('no-print');
            }
        });
        if (skipIdx !== -1) {
            clone.querySelectorAll('tbody tr').forEach(function(tr) {
                var tds = tr.querySelectorAll('td');
                if (tds[skipIdx]) tds[skipIdx].classList.add('no-print');
            });
        }
        
        win.document.write(clone.outerHTML);
        win.document.write('</body></html>');
        win.document.close();
        
        // Wait a tiny bit for the content to render in the popup window before printing
        setTimeout(function() {
            win.print();
        }, 300);
    }

    function setupTablePagination(tableId, selectId, paginationContainerId, defaultRows) {
        var table = document.getElementById(tableId);
        if (!table) return;
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        var container = document.getElementById(paginationContainerId);
        if (!container) return;
        
        var rowsPerPage = defaultRows || 10;
        var currentPage = 1;
        
        function updatePagination() {
            var allRows = Array.from(tbody.querySelectorAll('tr'));
            var visibleRows = allRows.filter(function(row) {
                return !row.classList.contains('search-hidden') && !row.classList.contains('no-paginate');
            });
            
            var totalRows = visibleRows.length;
            var totalPages = Math.ceil(totalRows / rowsPerPage) || 1;
            
            if (currentPage > totalPages) currentPage = totalPages;
            
            allRows.forEach(function(row) {
                if (row.classList.contains('no-paginate')) return;
                row.style.display = 'none';
            });
            
            var start = (currentPage - 1) * rowsPerPage;
            var end = start + rowsPerPage;
            
            var pageRows = visibleRows.slice(start, end);
            pageRows.forEach(function(row) {
                row.style.display = '';
            });
            
            var html = '';
            html += '<button class="cust-btn" style="padding:4px 8px;margin:2px;font-size:11px;background:#f1f5f9;color:#333;border:1px solid #ccc;border-radius:4px;cursor:pointer;" ' + (currentPage === 1 ? 'disabled' : '') + ' onclick="setTablePage(\''+tableId+'\',' + (currentPage - 1) + ')">Prev</button>';
            
            for (var i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
                    var isActive = (i === currentPage);
                    html += '<button class="cust-btn" style="padding:4px 8px;margin:2px;font-size:11px;border-radius:4px;cursor:pointer;' + (isActive ? 'background:#002F6C;color:#fff;font-weight:bold;border:1px solid #002F6C;' : 'background:#f1f5f9;color:#333;border:1px solid #ccc;') + '" onclick="setTablePage(\''+tableId+'\',' + i + ')">' + i + '</button>';
                } else if (i === currentPage - 3 || i === currentPage + 3) {
                    html += '<span style="padding:4px 8px;margin:2px;font-size:11px;color:#6c757d;">...</span>';
                }
            }
            
            html += '<button class="cust-btn" style="padding:4px 8px;margin:2px;font-size:11px;background:#f1f5f9;color:#333;border:1px solid #ccc;border-radius:4px;cursor:pointer;" ' + (currentPage === totalPages ? 'disabled' : '') + ' onclick="setTablePage(\''+tableId+'\',' + (currentPage + 1) + ')">Next</button>';
            
            var selectHtml = '<select class="rows-select" style="padding:4px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;color:#333333;background:#ffffff;cursor:pointer;margin-right:12px;outline:none;">';
            var options = [10, 20, 25, 50, 100];
            options.forEach(function(opt) {
                var selectedAttr = (opt === rowsPerPage) ? 'selected' : '';
                selectHtml += '<option value="' + opt + '" ' + selectedAttr + '>' + opt + ' rows</option>';
            });
            selectHtml += '</select>';
            
            container.innerHTML = '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:10px;justify-content:flex-end;">' + selectHtml + html + '</div>';
            
            var freshSelect = container.querySelector('.rows-select');
            if (freshSelect) {
                freshSelect.addEventListener('change', function() {
                    rowsPerPage = parseInt(this.value);
                    currentPage = 1;
                    updatePagination();
                });
            }
        }
        
        window.setTablePage = function(tId, page) {
            if (tId === tableId) {
                currentPage = page;
                updatePagination();
            }
        };
        
        // Re-run setup check regularly to integrate search filter classes
        var searchInput = document.querySelector('input[type="text"][oninput*="ft"], input[id="sq"], input[id="encodeSearch"]');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                setTimeout(function() {
                    tbody.querySelectorAll('tr').forEach(function(row) {
                        if (row.style.display === 'none') {
                            row.classList.add('search-hidden');
                        } else {
                            row.classList.remove('search-hidden');
                        }
                    });
                    currentPage = 1;
                    updatePagination();
                }, 10);
            });
        }
        
        var selectFilters = document.querySelectorAll('select[onchange*="ft"], select[id="cf"], select[id="sf"]');
        selectFilters.forEach(function(sel) {
            sel.addEventListener('change', function() {
                setTimeout(function() {
                    tbody.querySelectorAll('tr').forEach(function(row) {
                        if (row.style.display === 'none') {
                            row.classList.add('search-hidden');
                        } else {
                            row.classList.remove('search-hidden');
                        }
                    });
                    currentPage = 1;
                    updatePagination();
                }, 10);
            });
        });
        
        updatePagination();
    }
  </script>
</body>
</html>
