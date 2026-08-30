/**
 * Petron Station Management System — Frontend Security & Anti-Tampering Shield
 * 
 * Implements practical frontend deterrents per security specification:
 * - Disables context menu (right-click)
 * - Disables common view-source and DevTools keyboard shortcuts (F12, Ctrl+U, Ctrl+Shift+I/J/C, etc.)
 * - Suppresses console debug output in production
 * - Automatically injects CSRF token headers for AJAX/fetch requests
 * 
 * SERVER-SIDE PHP & MYSQL REMAIN THE AUTHORITATIVE SECURITY BOUNDARY.
 */

(function () {
    'use strict';

    // 1. Disable Right-Click (Context Menu)
    document.addEventListener('contextmenu', function (e) {
        e.preventDefault();
        return false;
    }, false);

    // 2. Disable Common View-Source and DevTools Keyboard Shortcuts
    document.addEventListener('keydown', function (e) {
        // F12
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl+Shift+I (DevTools), Ctrl+Shift+J (Console), Ctrl+Shift+C (Inspect Element), Ctrl+Shift+K
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c' || e.key === 'K' || e.key === 'k')) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Ctrl+U (View Source), Ctrl+S (Save Page), Ctrl+P (Print Page)
        if (e.ctrlKey && (e.key === 'U' || e.key === 'u' || e.key === 'S' || e.key === 's' || e.key === 'P' || e.key === 'p')) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, false);

    // 3. Console Purging / Debug Log Suppression
    (function suppressConsole() {
        if (window.console) {
            var emptyFn = function () {};
            window.console.log = emptyFn;
            window.console.debug = emptyFn;
            window.console.info = emptyFn;
            window.console.dir = emptyFn;
            window.console.trace = emptyFn;
        }
    })();

    // 4. Automatic CSRF Token Injection for fetch() and XMLHttpRequest
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    if (window.fetch) {
        var originalFetch = window.fetch;
        window.fetch = function (resource, init) {
            init = init || {};
            var token = getCsrfToken();
            if (token) {
                if (!init.headers) {
                    init.headers = {};
                }
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('X-CSRF-Token')) {
                        init.headers.append('X-CSRF-Token', token);
                    }
                } else if (typeof init.headers === 'object') {
                    if (!init.headers['X-CSRF-Token'] && !init.headers['x-csrf-token']) {
                        init.headers['X-CSRF-Token'] = token;
                    }
                }
            }
            return originalFetch.call(this, resource, init);
        };
    }

    if (window.XMLHttpRequest) {
        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            this._method = method;
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function (data) {
            var token = getCsrfToken();
            if (token && this._method && ['POST', 'PUT', 'DELETE', 'PATCH'].indexOf(this._method.toUpperCase()) !== -1) {
                try {
                    this.setRequestHeader('X-CSRF-Token', token);
                } catch (e) {}
            }
            return originalSend.apply(this, arguments);
        };
    }
})();
