/**
 * Petron Station Management System — Frontend Anti-Tampering & Security Shield
 * 
 * CORE PRINCIPLES:
 * 1. Developer Tools (F12, Ctrl+Shift+I/C/J) remain fully open & functional.
 * 2. Form inputs, textareas, dropdowns, and data entry remain 100% usable (typing, copy, paste, select).
 * 3. Protected UI elements are guarded against casual copying, drag-drop scraping, and save-page shortcuts.
 * 4. DOM mutation observer prevents client-side tampering with disabled attributes and security tags.
 * 5. SERVER-SIDE PHP & MYSQL REMAIN THE AUTHORITATIVE SECURITY BOUNDARY.
 */

(function () {
    'use strict';

    // ── 1. HELPER: Determine if element is an editable form control ──
    function isEditableElement(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toUpperCase();
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'OPTION') {
            return true;
        }
        if (el.isContentEditable || el.getAttribute('contenteditable') === 'true') {
            return true;
        }
        if (el.closest && (el.closest('input') || el.closest('textarea') || el.closest('select') || el.closest('[contenteditable="true"]') || el.closest('.allow-select') || el.closest('.allow-copy') || el.closest('.allow-context-menu'))) {
            return true;
        }
        return false;
    }

    // ── 2. ANTI-COPY & CONTEXT MENU DETERRENT (Context-Aware) ──
    // Allows right-click inside form fields; blocks casual context-menu scraping on static UI
    document.addEventListener('contextmenu', function (e) {
        if (isEditableElement(e.target)) {
            return true; // Allow context menu for form inputs (paste, cut, copy, spellcheck)
        }
        e.preventDefault();
        return false;
    }, false);

    // ── 3. KEYBOARD SHORTCUT PROTECTION (Context-Aware) ──
    // Form fields allow all keys (Ctrl+C, Ctrl+V, Ctrl+A, Ctrl+X, Ctrl+Z, etc.)
    // Non-input areas block Ctrl+U (view-source), Ctrl+S (save-page), Ctrl+C (static text copy)
    // DEVTOOLS SHORTCUTS (F12, Ctrl+Shift+I, Ctrl+Shift+C, Ctrl+Shift+J) REMAIN FULLY ACCESSIBLE
    document.addEventListener('keydown', function (e) {
        var isCtrlOrCmd = e.ctrlKey || e.metaKey;
        var key = (e.key || '').toLowerCase();
        var code = e.keyCode || e.which;

        // If typing inside an input/textarea, never interfere with typing or clipboard shortcuts
        if (isEditableElement(e.target || document.activeElement)) {
            return true;
        }

        // Block Ctrl+U (View Page Source)
        if (isCtrlOrCmd && (key === 'u' || code === 85)) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Block Ctrl+S (Save Page As)
        if (isCtrlOrCmd && (key === 's' || code === 83)) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        // Block Ctrl+C and Ctrl+A on non-editable protected UI
        if (isCtrlOrCmd && (key === 'c' || code === 67 || key === 'a' || code === 65)) {
            if (!isEditableElement(e.target || document.activeElement)) {
                // If user selected text on non-editable area, block copy
                var sel = window.getSelection ? window.getSelection() : null;
                if (sel && sel.toString().length > 0) {
                    var anchor = sel.anchorNode ? sel.anchorNode.parentElement : null;
                    if (!isEditableElement(anchor)) {
                        e.preventDefault();
                        return false;
                    }
                }
            }
        }

        // NOTE: F12, Ctrl+Shift+I, Ctrl+Shift+C, Ctrl+Shift+J are intentionally NOT blocked.
        return true;
    }, true);

    // ── 4. PREVENT DRAG-AND-DROP SCRAPING OF PROTECTED ASSETS ──
    document.addEventListener('dragstart', function (e) {
        if (!isEditableElement(e.target)) {
            var tag = (e.target.tagName || '').toUpperCase();
            if (tag === 'IMG' || tag === 'TABLE' || tag === 'TH' || tag === 'TR' || e.target.classList.contains('brand-logo') || e.target.classList.contains('protected-ui')) {
                e.preventDefault();
                return false;
            }
        }
    }, false);

    // ── 5. DOM MUTATION OBSERVER (Client-Side Anti-Tampering Shield) ──
    // Restores critical attributes (e.g., disabled states, CSRF tokens) if modified via Elements panel
    if (window.MutationObserver) {
        var domObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'attributes') {
                    var target = mutation.target;
                    // Guard elements marked with data-enforce-disabled
                    if (target && target.hasAttribute && target.getAttribute('data-enforce-disabled') === 'true') {
                        if (!target.disabled) {
                            target.disabled = true;
                        }
                    }
                    // Guard elements marked with data-enforce-readonly
                    if (target && target.hasAttribute && target.getAttribute('data-enforce-readonly') === 'true') {
                        if (!target.readOnly) {
                            target.readOnly = true;
                        }
                    }
                }
            });
        });

        // Start observing once DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                domObserver.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['disabled', 'readonly'] });
            });
        } else if (document.body) {
            domObserver.observe(document.body, { attributes: true, subtree: true, attributeFilter: ['disabled', 'readonly'] });
        }
    }

    // ── 6. CONSOLE PROTECTION & SELF-XSS SECURITY BANNER ──
    try {
        var bannerStyleHeader = 'color: #002F70; font-size: 20px; font-weight: 800; font-family: sans-serif; padding: 4px 0;';
        var bannerStyleWarning = 'color: #dc2626; font-size: 13px; font-weight: 700; font-family: sans-serif; padding: 2px 0;';
        var bannerStyleBody = 'color: #475569; font-size: 11.5px; font-family: sans-serif;';
        
        console.log('%cPetron Station Management System — Security Shield', bannerStyleHeader);
        console.log('%c⚠️ CAUTION: This browser console is a developer diagnostic tool.', bannerStyleWarning);
        console.log('%cExecuting untrusted scripts or pasting commands here will NOT bypass server-side role validation, transaction authorization, or database security policies.', bannerStyleBody);
    } catch (e) {}

    // ── 7. AUTOMATIC CSRF TOKEN INJECTION (Fetch & XMLHttpRequest) ──
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

