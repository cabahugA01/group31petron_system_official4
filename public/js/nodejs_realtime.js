/**
 * Petron Station Management System - Node.js Real-time Client
 * Connects browser to Node.js Service on http://localhost:3000
 */
(function() {
    const NODEJS_SERVER_URL = 'http://localhost:3000';
    let isConnected = false;

    // Load Socket.IO client library dynamically if not present
    function loadSocketIo(callback) {
        if (typeof io !== 'undefined') {
            callback();
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://cdn.socket.io/4.7.5/socket.io.min.js';
        script.onload = callback;
        script.onerror = function() {
            // Silently fallback without noisy warnings
        };
        document.head.appendChild(script);
    }

    function initRealtime() {
        loadSocketIo(function() {
            try {
                if (typeof io === 'undefined') return;

                const socket = io(NODEJS_SERVER_URL, {
                    transports: ['websocket', 'polling'],
                    reconnection: false, // Don't loop endlessly if service is stopped
                    timeout: 2000
                });

                socket.on('connect', function() {
                    isConnected = true;
                    console.log('%c[Node.js Realtime] Connected to Node.js Server on Port 3000!', 'color:#16a34a;font-weight:bold;');
                    
                    // Register role if user info is in window
                    const userRole = (window.currentUserRole || 'staff').toLowerCase();
                    socket.emit('register:role', userRole);
                });

                socket.on('system:connected', function(data) {
                    console.log('[Node.js Realtime]', data.message);
                });

                // Handle connection errors gracefully without retrying endlessly
                socket.on('connect_error', function() {
                    try { socket.disconnect(); } catch (e) {}
                });

                // Listen for real-time transaction events
                socket.on('transaction:new', function(data) {
                    if (typeof showTxnAlert === 'function') {
                        showTxnAlert('⚡ Real-time Notice: New transaction processed #' + (data.id || ''), 'info');
                    }
                });

                // Listen for job order updates
                socket.on('job_order:updated', function(data) {
                    if (typeof showTxnAlert === 'function') {
                        showTxnAlert('⚡ Real-time Notice: Job Order #' + (data.id || '') + ' updated to ' + (data.status || ''), 'info');
                    }
                });

                socket.on('disconnect', function() {
                    isConnected = false;
                });
            } catch (err) {
                // Silently handle
            }
        });
    }

    // Auto initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRealtime);
    } else {
        initRealtime();
    }
})();
