/**
 * Universal Live System Synchronization Engine
 * assets/js/live_sync.js  v2.0
 *
 * Automatically refreshes critical data in real time across the entire system:
 *  - Header notifications & alert badges (#notificationBadge)
 *  - Sidebar navigation badges ([data-sidebar-badge="..."])
 *  - Footer live connection & sync status pulse indicator
 *  - Dashboard statistics via page-specific AJAX callbacks (window.refreshLivePageData)
 *  - Tables marked with [data-live-table] via background AJAX fetch
 *  - "Refresh" buttons automatically converted to AJAX reloads (no page reload needed)
 */

(function () {
    'use strict';

    // Prevent duplicate initialization
    if (window.LiveSyncEngine) return;

    const SYNC_INTERVAL_MS  = 8000;  // global badge/notif poll: 8 seconds
    const TABLE_REFRESH_MS  = 15000; // live table auto-refresh: 15 seconds
    let isPolling           = false;
    let lastSyncTimestamp   = null;
    let syncErrorCount      = 0;
    let lastNotifCount      = -1;

    // ── Detect base application path ──────────────────────────────
    function getAppBasePath() {
        const scripts = document.querySelectorAll('script[src*="live_sync.js"]');
        for (const s of scripts) {
            const src = s.getAttribute('src') || '';
            if (src.includes('/assets/js/')) {
                return src.split('/assets/js/')[0];
            }
        }
        const pathname = window.location.pathname;
        const publicPos = pathname.indexOf('/public/');
        if (publicPos !== -1) {
            return pathname.substring(0, publicPos);
        }
        return '';
    }

    const basePath    = getAppBasePath();
    const syncEndpoint = basePath + '/backend/api/live_system_sync.php';

    // ── Helper: Is user actively interacting? ────────────────────
    function isUserBusy() {
        const active = document.activeElement;
        if (active && (
            active.tagName === 'INPUT'    ||
            active.tagName === 'TEXTAREA' ||
            active.tagName === 'SELECT'   ||
            active.isContentEditable
        )) return true;
        if (document.querySelector(
            '.modal.show, .modal[style*="display: block"], .swal2-container, ' +
            '.popover.show, .dropdown-menu.show'
        )) return true;
        return false;
    }

    // ── Helper: Escape HTML ───────────────────────────────────────
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ── Update Header Notification Badge (#notificationBadge) ────
    function updateNotificationBadge(count) {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'block';
                badge.style.background = '#dc3545';
            } else {
                badge.style.display = 'none';
            }
        }
        document.querySelectorAll('.header-notif-badge, .notif-badge-count').forEach(b => {
            b.textContent = count > 0 ? (count > 99 ? '99+' : count) : '';
            b.style.display = count > 0 ? 'inline-flex' : 'none';
        });
    }

    // ── Update Sidebar Badges ([data-sidebar-badge="..."]) ────────
    function updateSidebarBadges(badges) {
        if (!badges) return;
        const keyMap = {
            'transactions' : ['transactions'],
            'fuel'         : ['fuel', 'admin_fuel', 'admin_fuel_management'],
            'inventory'    : ['inventory', 'admin_inventory'],
            'customers'    : ['customers', 'mgr_customers'],
            'pricing'      : ['mgr_product_pricing', 'prod_pricing', 'admin_product_pricing'],
            'reports'      : ['reports', 'admin_reports'],
            'users'        : ['users'],
        };
        Object.keys(badges).forEach(key => {
            const val        = badges[key];
            const sidebarKeys = keyMap[key] || [key];
            sidebarKeys.forEach(sk => {
                document.querySelectorAll(`[data-sidebar-badge="${sk}"]`).forEach(el => {
                    if (val > 0) {
                        el.textContent   = val > 99 ? '99+' : val;
                        el.style.display = 'flex';
                    } else {
                        el.style.display = 'none';
                    }
                });
            });
        });
    }

    // ── Footer Live Pulse Indicator ───────────────────────────────
    function injectPulseStyles() {
        if (document.getElementById('liveSyncPulseStyles')) return;
        const style = document.createElement('style');
        style.id = 'liveSyncPulseStyles';
        style.textContent = `
            @keyframes livePulse {
                0%   { transform:scale(0.95); box-shadow:0 0 0 0 rgba(16,185,129,.7); }
                70%  { transform:scale(1.05); box-shadow:0 0 0 6px rgba(16,185,129,0); }
                100% { transform:scale(0.95); box-shadow:0 0 0 0 rgba(16,185,129,0); }
            }
            #liveSyncFooterBadge { transition: background .3s, border-color .3s; }
            .live-sync-refresh-btn.ls-loading { opacity:.6; pointer-events:none; }
            .live-sync-refresh-btn.ls-loading i { animation: spin 0.7s linear infinite; }
            @keyframes spin { to { transform:rotate(360deg); } }
        `;
        document.head.appendChild(style);
    }

    function updateFooterSyncStatus(success, formattedTime) {
        injectPulseStyles();
        let badge = document.getElementById('liveSyncFooterBadge');
        if (!badge) {
            const footerContent = document.querySelector('.footer-content');
            if (!footerContent) return;
            badge = document.createElement('div');
            badge.id = 'liveSyncFooterBadge';
            badge.style.cssText = [
                'display:inline-flex', 'align-items:center', 'gap:5px',
                'font-size:11px', 'font-weight:700', 'padding:2px 8px',
                'border-radius:12px', 'margin-left:12px', 'white-space:nowrap',
                'flex-shrink:0', 'pointer-events:none',
            ].join(';');
            footerContent.appendChild(badge);
        }
        if (success) {
            badge.style.cssText += ';background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;';
            badge.innerHTML = `<span style="width:7px;height:7px;border-radius:50%;background:#10b981;animation:livePulse 2s infinite;display:inline-block;flex-shrink:0;"></span> Live &bull; ${escapeHtml(formattedTime || 'Now')}`;
        } else {
            badge.style.cssText += ';background:#fffbeb;border:1px solid #fde68a;color:#b45309;';
            badge.innerHTML = `<span style="width:7px;height:7px;border-radius:50%;background:#f59e0b;display:inline-block;flex-shrink:0;"></span> Reconnecting...`;
        }
    }

    // ────────────────────────────────────────────────────────────────
    // AJAX TABLE REFRESH  ([data-live-table="selector"])
    // Usage: <div data-live-table="#myTableWrapper" data-live-url="page.php?table_only=1">
    // The server renders just the table HTML when ?table_only=1 is present.
    // ────────────────────────────────────────────────────────────────
    const _tableTimers = new Map();

    async function refreshAjaxTable(container, showSpinner) {
        if (!container) return;
        const url = container.dataset.liveUrl || window.location.href;
        if (container._lsInflight) return;
        container._lsInflight = true;

        if (showSpinner) {
            const spin = document.createElement('div');
            spin.className = 'ls-table-spinner';
            spin.style.cssText = 'position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;z-index:9;border-radius:inherit;';
            spin.innerHTML = '<i class="fas fa-sync-alt" style="font-size:20px;color:#002F70;animation:spin .7s linear infinite;"></i>';
            if (getComputedStyle(container).position === 'static') container.style.position = 'relative';
            container.appendChild(spin);
        }

        try {
            const ctrl = new AbortController();
            const tid  = setTimeout(() => ctrl.abort(), 8000);
            const res  = await fetch(url, {
                signal: ctrl.signal, credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-Live-Table': '1', 'Accept': 'text/html' }
            });
            clearTimeout(tid);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();

            // Parse and extract just the matching fragment
            const parser = new DOMParser();
            const doc    = parser.parseFromString(html, 'text/html');
            const targetSel = container.dataset.liveTable;
            const newFrag = targetSel ? doc.querySelector(targetSel) : null;
            if (newFrag) {
                container.innerHTML = newFrag.innerHTML;
            }
        } catch (e) {
            // silent
        } finally {
            container._lsInflight = false;
            container.querySelector('.ls-table-spinner')?.remove();
        }
    }

    function initLiveTables() {
        document.querySelectorAll('[data-live-table]').forEach(container => {
            const intervalMs = parseInt(container.dataset.liveInterval || TABLE_REFRESH_MS, 10);
            if (!_tableTimers.has(container)) {
                const tid = setInterval(() => {
                    if (!document.hidden && !isUserBusy()) {
                        refreshAjaxTable(container, false);
                    }
                }, intervalMs);
                _tableTimers.set(container, tid);
            }
        });
    }

    // ────────────────────────────────────────────────────────────────
    // AUTO-INTERCEPT "REFRESH" BUTTONS → AJAX reload instead of page reload
    // Targets all buttons with onclick="location.reload()" or class containing "refresh"
    // that are simple reload triggers (not form submitters)
    // ────────────────────────────────────────────────────────────────
    function interceptRefreshButtons() {
        // Find buttons whose onclick attribute contains location.reload
        document.querySelectorAll('[onclick*="location.reload"], [onclick*="window.location.reload"]').forEach(btn => {
            // Only intercept if it's a plain refresh (not inside a form/submit chain)
            if (btn.closest('form')) return;
            if (btn.dataset.lsIntercepted) return;
            btn.dataset.lsIntercepted = '1';
            btn.classList.add('live-sync-refresh-btn');

            const origOnclick = btn.getAttribute('onclick');
            btn.removeAttribute('onclick');

            btn.addEventListener('click', async function (e) {
                e.preventDefault();
                e.stopPropagation();
                btn.classList.add('ls-loading');

                // Find the nearest live-table ancestor, or just call refreshLivePageData
                const liveTableAncestor = btn.closest('[data-live-table]');
                if (liveTableAncestor) {
                    await refreshAjaxTable(liveTableAncestor, true);
                } else if (typeof window.refreshLivePageData === 'function') {
                    await window.refreshLivePageData();
                } else {
                    // No AJAX handler — fall back to the original reload (safe fallback)
                    window.location.reload();
                    return;
                }

                btn.classList.remove('ls-loading');
            });
        });
    }

    // ── Main Async Sync Function ──────────────────────────────────
    async function performLiveSync() {
        if (isPolling) return;
        isPolling = true;
        try {
            const ctrl = new AbortController();
            const tId  = setTimeout(() => ctrl.abort(), 7000);
            const response = await fetch(syncEndpoint, {
                method: 'GET', signal: ctrl.signal,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' }
            });
            clearTimeout(tId);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (data && data.success) {
                syncErrorCount = 0;
                lastSyncTimestamp = data.formatted_time;

                // 1. Header notification badge
                const newCount = data.unread_notifications || 0;
                if (newCount !== lastNotifCount) {
                    updateNotificationBadge(newCount);
                    lastNotifCount = newCount;
                    if (typeof window._petronNotifUpdateBadge === 'function') {
                        window._petronNotifUpdateBadge(newCount, null);
                    }
                }

                // 2. Sidebar badges
                updateSidebarBadges(data.sidebar_badges || {});

                // 3. Footer pulse
                updateFooterSyncStatus(true, data.formatted_time);

                // 4. Page-specific live refresh callback
                if (typeof window.refreshLivePageData === 'function' && !isUserBusy()) {
                    try { window.refreshLivePageData(data); } catch (e) { /* silent */ }
                }
            }
        } catch (err) {
            syncErrorCount++;
            if (syncErrorCount > 3) updateFooterSyncStatus(false);
        } finally {
            isPolling = false;
        }
    }

    // ── Start everything after DOM ready ─────────────────────────
    function startEngine() {
        injectPulseStyles();
        performLiveSync();
        setInterval(performLiveSync, SYNC_INTERVAL_MS);

        // Init live tables and refresh-button interception after DOM settles
        setTimeout(() => {
            initLiveTables();
            interceptRefreshButtons();
        }, 500);

        // Re-intercept on visibility change (catches dynamically added buttons)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                interceptRefreshButtons();
                performLiveSync();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startEngine);
    } else {
        startEngine();
    }

    // ── Expose global API ─────────────────────────────────────────
    window.LiveSyncEngine = {
        triggerSync      : performLiveSync,
        refreshTable     : refreshAjaxTable,
        isUserBusy       : isUserBusy,
        getLastSyncTime  : () => lastSyncTimestamp,
        getBasePath      : () => basePath,
        interceptButtons : interceptRefreshButtons,
    };

})();
