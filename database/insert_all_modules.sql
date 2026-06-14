-- ============================================================
-- Insert All Operational Modules into module_settings
-- ============================================================

-- Clear existing modules (optional - comment out if you want to keep existing)
-- TRUNCATE TABLE module_settings;

-- Insert all operational modules
INSERT INTO module_settings (module_key, module_name, module_description, is_enabled, module_order, created_at) VALUES
('transactions', 'Transactions (Merchandise POS)', 'Encode and manage sales, payments for merchandise and fuel', 1, 1, NOW()),
('fuel_management', 'Fuel Management', 'Meter readings, reconciliation, variance rules, calibration tracking', 1, 2, NOW()),
('merchandise_deliveries', 'Merchandise Deliveries', 'Delivery validation, approval workflow, stock updates', 1, 3, NOW()),
('inventory', 'Inventory', 'FIFO rules, stock requests, alerts, stock movement tracking', 1, 4, NOW()),
('product_management', 'Product Management', 'Merchandise catalog setup, pricing, categories', 1, 5, NOW()),
('customers', 'Customers', 'Loyalty program, customer balances, account linkage', 1, 6, NOW()),
('calendar', 'Calendar', 'Shift scheduling, events, calibration schedules', 1, 7, NOW()),
('reports', 'Reports', 'Analytics, compliance documentation, financial reports', 1, 8, NOW()),
('job_orders', 'Job Orders', 'Service/maintenance workflows, job tracking, pricing', 1, 9, NOW()),
('purchase_orders', 'Purchase Orders', 'PO creation, approval workflow, supplier management', 1, 10, NOW()),
('staff_management', 'Staff Management', 'Attendance, performance tracking, shift management', 1, 11, NOW()),
('admin_unlock', 'Admin Unlock', 'Override approvals, unlock voided transactions, emergency access', 1, 12, NOW())
ON DUPLICATE KEY UPDATE
    module_name = VALUES(module_name),
    module_description = VALUES(module_description),
    module_order = VALUES(module_order);

-- Verify the insert
SELECT * FROM module_settings ORDER BY module_order;
