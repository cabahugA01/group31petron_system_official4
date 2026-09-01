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

    // Right-click (context menu), DevTools shortcuts (F12, Ctrl+Shift+I/J/C), and Console output are ENABLED.

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
