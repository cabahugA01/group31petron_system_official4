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
            console.warn('[Node.js Realtime] Socket.io CDN unavailable, falling back to HTTP polling.');
        };
        document.head.appendChild(script);
    }

    function initRealtime() {
        loadSocketIo(function() {
            try {
                const socket = io(NODEJS_SERVER_URL, {
                    transports: ['websocket', 'polling'],
                    reconnectionAttempts: 5,
                    timeout: 4000
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

                // Listen for real-time transaction events
                socket.on('transaction:new', function(data) {
                    console.log('[Node.js Realtime] New Transaction:', data);
                    if (typeof showTxnAlert === 'function') {
                        showTxnAlert('⚡ Real-time Notice: New transaction processed #' + (data.id || ''), 'info');
                    }
                });

                // Listen for job order updates
                socket.on('job_order:updated', function(data) {
                    console.log('[Node.js Realtime] Job Order Updated:', data);
                    if (typeof showTxnAlert === 'function') {
                        showTxnAlert('⚡ Real-time Notice: Job Order #' + (data.id || '') + ' updated to ' + (data.status || ''), 'info');
                    }
                });

                socket.on('disconnect', function() {
                    isConnected = false;
                    console.log('[Node.js Realtime] Disconnected from Node.js service');
                });
            } catch (err) {
                console.warn('[Node.js Realtime] Connection error:', err.message);
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
