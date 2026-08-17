/**
 * Petron Station Management System
 * Global Draft & Autosave Engine — assets/js/global_draft_engine.js
 *
 * Provides non-destructive auto-saving and recovery across all data-entry forms.
 * Drafts do not trigger any database transactions, inventory deductions, or financial reports.
 */
(function(window, document) {
    'use strict';

    const BASE_PATH = (window.pageData && window.pageData.appBasePath)
        ? window.pageData.appBasePath.replace(/\/$/, '')
        : (window.location.pathname.includes('/public/') ? window.location.pathname.split('/public/')[0] : '');

    const DRAFTS_API = BASE_PATH + '/backend/api/drafts_api.php';
    const USER_ID = (window.pageData && window.pageData.userId) || (window.CURRENT_USER_ID) || 0;

    const PetronDraft = {
        timers: {},
        activeDrafts: {},

        /**
         * Initialize draft autosave on a specific form or elements
         * @param {string} moduleKey - Unique module key (e.g., 'merchandise_sale', 'job_order')
         * @param {string|HTMLFormElement} formSelector - Form selector or element
         * @param {Object} options - Custom options (customCollector, customRestorer, onSave)
         */
        init: function(moduleKey, formSelector, options) {
            options = options || {};
            const form = typeof formSelector === 'string' ? document.querySelector(formSelector) : formSelector;
            if (!form) return;

            form.setAttribute('data-petron-draft-module', moduleKey);

            // 1. Check for existing draft on load
            this.checkForDraft(moduleKey, form, options);

            // 2. Attach input listeners with debounce
            const self = this;
            const inputHandler = function(e) {
                // Ignore submit buttons, passwords, and tokens
                if (e.target && (e.target.type === 'password' || e.target.type === 'submit')) return;
                self.scheduleSave(moduleKey, form, options);
            };

            form.addEventListener('input', inputHandler, { passive: true });
            form.addEventListener('change', inputHandler, { passive: true });

            // 3. Save on beforeunload if dirty
            window.addEventListener('beforeunload', function() {
                self.saveNow(moduleKey, form, options, true);
            });

            // 4. Auto-clear draft when form is successfully submitted
            form.addEventListener('submit', function(e) {
                // Let the submit proceed, then clear draft
                setTimeout(function() {
                    self.clear(moduleKey);
                }, 500);
            });
        },

        /**
         * Collect form data into a serializable JSON object
         */
        collectFormData: function(form, options) {
            if (typeof options.customCollector === 'function') {
                return options.customCollector(form);
            }

            const data = {};
            const elements = form.querySelectorAll('input, select, textarea');
            let hasMeaningfulContent = false;

            elements.forEach(function(el) {
                const name = el.name || el.id;
                if (!name || el.type === 'password' || el.type === 'submit' || el.type === 'button') return;

                if (el.type === 'checkbox') {
                    data[name] = el.checked;
                } else if (el.type === 'radio') {
                    if (el.checked) data[name] = el.value;
                } else {
                    data[name] = el.value;
                    if (el.value && String(el.value).trim() !== '' && el.value !== '0') {
                        hasMeaningfulContent = true;
                    }
                }
            });

            // If there's an active cart or dynamic item table, collect it
            if (window.activeCart && Array.isArray(window.activeCart) && window.activeCart.length > 0) {
                data._activeCart = window.activeCart;
                hasMeaningfulContent = true;
            }
            if (window.jobOrderItems && Array.isArray(window.jobOrderItems) && window.jobOrderItems.length > 0) {
                data._jobOrderItems = window.jobOrderItems;
                hasMeaningfulContent = true;
            }

            return hasMeaningfulContent ? data : null;
        },

        /**
         * Restore form data from draft object
         */
        restoreFormData: function(form, data, options) {
            if (typeof options.customRestorer === 'function') {
                options.customRestorer(form, data);
                return;
            }

            if (!data || typeof data !== 'object') return;

            Object.keys(data).forEach(function(name) {
                if (name.startsWith('_')) return; // Metadata or custom array

                const el = form.querySelector(`[name="${name}"], #${name}`);
                if (!el) return;

                if (el.type === 'checkbox') {
                    el.checked = !!data[name];
                } else if (el.type === 'radio') {
                    const radio = form.querySelector(`[name="${name}"][value="${data[name]}"]`);
                    if (radio) radio.checked = true;
                } else {
                    el.value = data[name];
                }

                // Dispatch event so reactive UI calculations update
                try {
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                } catch (e) {}
            });

            // Restore custom cart arrays if present
            if (data._activeCart && Array.isArray(data._activeCart) && typeof window.renderCartTable === 'function') {
                window.activeCart = data._activeCart;
                window.renderCartTable();
            }
            if (data._jobOrderItems && Array.isArray(data._jobOrderItems) && typeof window.renderJobOrderItems === 'function') {
                window.jobOrderItems = data._jobOrderItems;
                window.renderJobOrderItems();
            }
        },

        /**
         * Schedule debounced autosave (1.5s)
         */
        scheduleSave: function(moduleKey, form, options) {
            const self = this;
            if (this.timers[moduleKey]) clearTimeout(this.timers[moduleKey]);

            this.updateBadge(form, 'saving');

            this.timers[moduleKey] = setTimeout(function() {
                self.saveNow(moduleKey, form, options, false);
            }, 1200);
        },

        /**
         * Save draft immediately to LocalStorage and Server API
         */
        saveNow: function(moduleKey, form, options, isSync) {
            const data = this.collectFormData(form, options);
            if (!data) return;

            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;
            const draftPayload = {
                module: moduleKey,
                data: data,
                timestamp: Date.now(),
                formatted_time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
            };

            // 1. Client-side instant save
            try {
                localStorage.setItem(storageKey, JSON.stringify(draftPayload));
            } catch (e) {}

            // 2. Server-side async save
            const self = this;
            if (navigator.onLine) {
                const url = DRAFTS_API + '?action=save';
                const body = JSON.stringify({ module: moduleKey, form_data: data });

                if (isSync && navigator.sendBeacon) {
                    try {
                        const blob = new Blob([body], { type: 'application/json' });
                        navigator.sendBeacon(url, blob);
                    } catch (e) {}
                } else {
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: body,
                        credentials: 'same-origin'
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(resData) {
                        if (resData && resData.ok) {
                            self.updateBadge(form, 'saved', resData.formatted_time || draftPayload.formatted_time);
                        }
                    })
                    .catch(function() {
                        self.updateBadge(form, 'saved_local', draftPayload.formatted_time);
                    });
                }
            } else {
                self.updateBadge(form, 'saved_local', draftPayload.formatted_time);
            }
        },

        /**
         * Check for existing draft on page load
         */
        checkForDraft: function(moduleKey, form, options) {
            const self = this;
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;

            // Check LocalStorage first for instant response
            let localDraft = null;
            try {
                const stored = localStorage.getItem(storageKey);
                if (stored) localDraft = JSON.parse(stored);
            } catch (e) {}

            // Also check Server DB
            fetch(DRAFTS_API + '?action=get&module=' + encodeURIComponent(moduleKey), {
                credentials: 'same-origin'
            })
            .then(function(res) { return res.json(); })
            .then(function(resData) {
                let candidateDraft = null;
                if (resData && resData.ok && resData.has_draft && resData.draft) {
                    candidateDraft = {
                        data: resData.draft.data,
                        formatted_time: resData.draft.formatted_time || resData.draft.time_ago,
                        source: 'server'
                    };
                } else if (localDraft && localDraft.data) {
                    candidateDraft = {
                        data: localDraft.data,
                        formatted_time: localDraft.formatted_time || 'Earlier today',
                        source: 'local'
                    };
                }

                if (candidateDraft && candidateDraft.data) {
                    self.showDraftRecoveryBanner(moduleKey, form, candidateDraft, options);
                }
            })
            .catch(function() {
                if (localDraft && localDraft.data) {
                    self.showDraftRecoveryBanner(moduleKey, form, {
                        data: localDraft.data,
                        formatted_time: localDraft.formatted_time || 'Earlier today',
                        source: 'local'
                    }, options);
                }
            });
        },

        /**
         * Render non-intrusive Draft Recovery Banner above form
         */
        showDraftRecoveryBanner: function(moduleKey, form, draft, options) {
            const self = this;
            const existingBanner = document.getElementById(`draftBanner_${moduleKey}`);
            if (existingBanner) existingBanner.remove();

            const banner = document.createElement('div');
            banner.id = `draftBanner_${moduleKey}`;
            banner.style.cssText = 'background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 14px; box-shadow: 0 4px 12px rgba(0,47,108,0.06); animation: toastSlideIn .3s ease;';

            banner.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; color: #1e40af; font-size: 13.5px; font-weight: 600;">
                    <i class="fas fa-history" style="font-size: 18px; color: #3b82f6;"></i>
                    <span><strong>Unsaved Draft Found</strong> — Saved automatically at <em>${draft.formatted_time}</em>.</span>
                </div>
                <div style="display: flex; gap: 8px; flex-shrink: 0;">
                    <button type="button" id="btnContinueDraft_${moduleKey}" style="padding: 6px 14px; background: #002F6C; color: #fff; border: none; border-radius: 6px; font-size: 12.5px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 2px 6px rgba(0,47,108,0.2);">
                        <i class="fas fa-check"></i> Continue Draft
                    </button>
                    <button type="button" id="btnDiscardDraft_${moduleKey}" style="padding: 6px 12px; background: #fff; color: #dc2626; border: 1px solid #fca5a5; border-radius: 6px; font-size: 12.5px; font-weight: 600; cursor: pointer;">
                        Discard Draft
                    </button>
                </div>
            `;

            form.parentNode.insertBefore(banner, form);

            // Continue Draft Action
            document.getElementById(`btnContinueDraft_${moduleKey}`).addEventListener('click', function() {
                self.restoreFormData(form, draft.data, options);
                banner.remove();
                self.showNotificationToast('Draft restored successfully! You can continue editing.');
                self.updateBadge(form, 'saved', draft.formatted_time);
            });

            // Discard Draft Action
            document.getElementById(`btnDiscardDraft_${moduleKey}`).addEventListener('click', function() {
                self.clear(moduleKey);
                banner.remove();
                self.showNotificationToast('Draft discarded. Starting with blank form.', 'info');
                self.updateBadge(form, 'cleared');
            });
        },

        /**
         * Visual Autosave status indicator
         */
        updateBadge: function(form, status, timeStr) {
            let badge = form.querySelector('.petron-draft-status-badge');
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'petron-draft-status-badge';
                badge.style.cssText = 'font-size: 12px; color: #64748b; margin-top: 6px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;';
                const submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
                if (submitBtn && submitBtn.parentNode) {
                    submitBtn.parentNode.appendChild(badge);
                } else {
                    form.appendChild(badge);
                }
            }

            if (status === 'saving') {
                badge.innerHTML = '<i class="fas fa-circle-notch fa-spin" style="color:#3b82f6;"></i> Autosaving draft...';
                badge.style.color = '#3b82f6';
            } else if (status === 'saved' || status === 'saved_local') {
                badge.innerHTML = `<i class="fas fa-check-circle" style="color:#16a34a;"></i> Draft autosaved ${timeStr ? 'at ' + timeStr : ''}`;
                badge.style.color = '#16a34a';
            } else if (status === 'cleared') {
                badge.innerHTML = '';
            }
        },

        /**
         * Clear draft on client and server
         */
        clear: function(moduleKey) {
            const storageKey = `petron_draft_${USER_ID}_${moduleKey}`;
            try { localStorage.removeItem(storageKey); } catch (e) {}

            fetch(DRAFTS_API + '?action=discard', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ module: moduleKey }),
                credentials: 'same-origin'
            }).catch(function() {});
        },

        /**
         * Lightweight feedback toast
         */
        showNotificationToast: function(message, type) {
            const toast = document.createElement('div');
            const isInfo = type === 'info';
            toast.style.cssText = `position: fixed; top: 82px; right: 24px; z-index: 100005; background: #fff; border: 1.5px solid ${isInfo ? '#93c5fd' : '#86efac'}; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); padding: 12px 18px; display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; color: ${isInfo ? '#1e40af' : '#166534'}; animation: toastSlideIn .3s ease;`;
            toast.innerHTML = `<i class="fas ${isInfo ? 'fa-info-circle' : 'fa-check-circle'}" style="font-size: 16px; color: ${isInfo ? '#3b82f6' : '#16a34a'};"></i> <span>${message}</span>`;
            document.body.appendChild(toast);
            setTimeout(function() {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.4s ease';
                setTimeout(function() { toast.remove(); }, 400);
            }, 4000);
        },

        /**
         * Auto-scan and initialize forms with [data-draft-module] or standard form IDs/pages
         */
        autoScan: function() {
            const self = this;
            const pathname = window.location.pathname.split('/').pop().replace(/\.php$/, '');

            // 1. Explicit data-draft-module forms
            document.querySelectorAll('form[data-draft-module]').forEach(function(f) {
                const mod = f.getAttribute('data-draft-module');
                if (mod) self.init(mod, f);
            });

            // 2. Standard ID mappings
            const knownFormMap = {
                'merchandiseForm': 'merchandise_transaction',
                'jobOrderForm': 'job_order',
                'addUserForm': 'user_creation_form',
                'stockRequestForm': 'stock_request_form',
                'fuelReadingForm': 'fuel_reading',
                'fuelClosingForm': 'fuel_sales_closing',
                'deliveryForm': 'delivery_receipt',
                'poForm': 'purchase_order',
                'adjustmentForm': 'transaction_adjustment',
                'voidForm': 'void_request'
            };

            Object.keys(knownFormMap).forEach(function(formId) {
                const formEl = document.getElementById(formId);
                if (formEl && !formEl.hasAttribute('data-petron-draft-module')) {
                    self.init(knownFormMap[formId], formEl);
                }
            });

            // 3. Fallback: Intelligent detection for primary data-entry forms on encoding pages
            const encodingPages = [
                'pos', 'transactions', 'job_orders', 'joborder_create', 'staff_fuel_reading',
                'fuel_sales_closing', 'stock_request', 'staff_stock_requests', 'manager_purchase_orders',
                'staff_merchandise_transactions', 'users'
            ];

            if (encodingPages.includes(pathname)) {
                document.querySelectorAll('form').forEach(function(f, idx) {
                    if (f.hasAttribute('data-petron-draft-module')) return;
                    if (f.method && f.method.toUpperCase() === 'GET' && f.classList.contains('filters')) return;
                    if (f.id === 'loginForm' || f.id === 'logoutForm') return;

                    // Must have meaningful input fields
                    const inputs = f.querySelectorAll('input:not([type="hidden"]):not([type="submit"]), select, textarea');
                    if (inputs.length >= 2) {
                        const autoKey = 'page_' + pathname + (idx > 0 ? '_' + idx : '');
                        self.init(autoKey, f);
                    }
                });
            }
        }
    };

    window.PetronDraft = PetronDraft;

    document.addEventListener('DOMContentLoaded', function() {
        PetronDraft.autoScan();
    });

})(window, document);
