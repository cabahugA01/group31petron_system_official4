/**
 * ============================================================================
 * Petron Station Management System - Real-Time Node.js Microservice
 * Stack: Node.js (v20+) + Express + Socket.IO + MySQL2
 * Port: 3000
 * ============================================================================
 */

const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');
const mysql = require('mysql2/promise');
require('dotenv').config();

const app = express();
const server = http.createServer(app);
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors({ origin: '*' }));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Socket.IO setup with permissive CORS
const io = new Server(server, {
    cors: {
        origin: '*',
        methods: ['GET', 'POST']
    }
});

// MySQL Connection Pool
const dbConfig = {
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'petron_pos_db_secure',
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
};

const pool = mysql.createPool(dbConfig);

// Keep track of connected clients
let activeClients = 0;

// Socket.IO connection handling
io.on('connection', (socket) => {
    activeClients++;
    console.log(`[Socket.IO] Client connected: ${socket.id} | Total active: ${activeClients}`);
    
    socket.emit('system:connected', {
        message: 'Connected to Petron Station Node.js Real-time Service',
        timestamp: new Date().toISOString(),
        socketId: socket.id
    });

    socket.on('register:role', (role) => {
        socket.join(`role:${role}`);
        console.log(`[Socket.IO] Client ${socket.id} joined room: role:${role}`);
    });

    socket.on('transaction:created', (data) => {
        console.log('[Socket.IO] Transaction created event broadcasted:', data);
        io.emit('transaction:new', data);
    });

    socket.on('job_order:status_change', (data) => {
        console.log('[Socket.IO] Job Order status updated:', data);
        io.emit('job_order:updated', data);
    });

    socket.on('disconnect', () => {
        activeClients = Math.max(0, activeClients - 1);
        console.log(`[Socket.IO] Client disconnected: ${socket.id} | Remaining: ${activeClients}`);
    });
});

// ── REST API ENDPOINTS ────────────────────────────────────────────────────────

// 1. Root / Health Check
app.get('/', (req, res) => {
    res.json({
        system: 'Petron Station Management System',
        service: 'Node.js Real-Time Service & WebSocket Microservice',
        version: '1.0.0',
        status: 'ONLINE',
        port: PORT,
        activeSocketClients: activeClients,
        uptimeSeconds: Math.floor(process.uptime()),
        timestamp: new Date().toISOString()
    });
});

// 2. Health & DB Status
app.get('/api/status', async (req, res) => {
    try {
        const [dbRows] = await pool.query('SELECT 1 as is_alive');
        res.json({
            success: true,
            nodeVersion: process.version,
            serverStatus: 'running',
            databaseStatus: dbRows[0].is_alive === 1 ? 'connected' : 'unreachable',
            activeClients: activeClients,
            uptime: `${Math.floor(process.uptime() / 60)} minutes`,
            memoryUsage: process.memoryUsage()
        });
    } catch (err) {
        res.status(500).json({
            success: false,
            serverStatus: 'running',
            databaseStatus: 'error',
            error: err.message
        });
    }
});

// 3. Real-Time Dashboard Stats
app.get('/api/stats', async (req, res) => {
    try {
        const today = new Date().toISOString().slice(0, 10);
        
        let totalTxns = 0;
        let totalSales = 0;
        try {
            const [txnRows] = await pool.query(
                `SELECT COUNT(*) as total_txns, COALESCE(SUM(total_amount), 0) as total_sales 
                 FROM merchandise_transactions 
                 WHERE DATE(created_at) = ? AND validation_status != 'Voided'`,
                [today]
            );
            totalTxns = txnRows[0]?.total_txns || 0;
            totalSales = parseFloat(txnRows[0]?.total_sales || 0);
        } catch (e) {}

        let completedJo = 0;
        try {
            const [joRows] = await pool.query(
                `SELECT COUNT(*) as completed_jo 
                 FROM job_orders 
                 WHERE DATE(created_at) = ? AND status = 'Completed'`,
                [today]
            );
            completedJo = joRows[0]?.completed_jo || 0;
        } catch (e) {}

        res.json({
            success: true,
            date: today,
            stats: {
                todayTransactions: totalTxns,
                todaySales: totalSales,
                completedJobOrders: completedJo,
                connectedSockets: activeClients
            }
        });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

// 4. Live Transactions Stream
app.get('/api/transactions/live', async (req, res) => {
    try {
        const [rows] = await pool.query(
            `SELECT id, customer_name, total_amount, payment_method, 
                    payment_status, validation_status, created_at 
             FROM merchandise_transactions 
             ORDER BY created_at DESC LIMIT 10`
        );
        res.json({ success: true, count: rows.length, transactions: rows });
    } catch (err) {
        res.status(500).json({ success: false, error: err.message });
    }
});

// 5. Trigger Real-Time Notification (called by PHP or external webhook)
app.post('/api/notify', (req, res) => {
    const { event, data, targetRole } = req.body;
    if (!event) {
        return res.status(400).json({ success: false, error: 'Missing event parameter' });
    }

    if (targetRole) {
        io.to(`role:${targetRole}`).emit(event, data || {});
    } else {
        io.emit(event, data || {});
    }

    console.log(`[API Notify] Broadcasted event: ${event}`, data);
    res.json({ success: true, broadcasted: true, event, clientsNotified: activeClients });
});

// Start Server
server.listen(PORT, () => {
    console.log('=================================================================');
    console.log(` PETRON STATION NODE.JS REAL-TIME SERVICE IS ONLINE!`);
    console.log(` Port: ${PORT}`);
    console.log(` URL:  http://localhost:${PORT}`);
    console.log(` DB:   ${dbConfig.database} @ ${dbConfig.host}`);
    console.log('=================================================================');
});
