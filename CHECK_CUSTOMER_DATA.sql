-- ═══════════════════════════════════════════════════════════════════════════════
-- CHECK IF CUSTOMER DATA EXISTS IN DATABASE
-- ═══════════════════════════════════════════════════════════════════════════════

-- 1. CHECK TOTAL CUSTOMERS
SELECT COUNT(*) AS total_customers FROM customers;

-- 2. CHECK CUSTOMERS BY STATION
SELECT station_id, COUNT(*) AS customer_count 
FROM customers 
GROUP BY station_id;

-- 3. CHECK CUSTOMER TYPES
SELECT customer_type, COUNT(*) AS count 
FROM customers 
GROUP BY customer_type;

-- 4. CHECK CUSTOMER STATUS
SELECT status, COUNT(*) AS count 
FROM customers 
GROUP BY status;

-- 5. SAMPLE CUSTOMER DATA (FIRST 10 RECORDS)
SELECT 
    id,
    customer_id,
    CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS name,
    contact_number,
    customer_type,
    status,
    DATE(COALESCE(registered_at, created_at)) AS date_registered,
    station_id
FROM customers 
LIMIT 10;

-- ═══════════════════════════════════════════════════════════════════════════════
-- IF NO CUSTOMERS FOUND, INSERT SAMPLE DATA
-- ═══════════════════════════════════════════════════════════════════════════════

-- IMPORTANT: Replace station_id and registered_by with actual IDs from your system

-- Sample Walk-in Customers
INSERT INTO customers (station_id, customer_id, first_name, last_name, contact_number, customer_type, status, registered_by, registered_at, created_at) VALUES
(1, 'C-001', 'Juan', 'Dela Cruz', '09171234567', 'walk-in', 'active', 1, NOW(), NOW()),
(1, 'C-002', 'Maria', 'Santos', '09281234567', 'walk-in', 'active', 1, NOW(), NOW()),
(1, 'C-003', 'Pedro', 'Reyes', '09391234567', 'walk-in', 'active', 1, NOW(), NOW());

-- Sample Regular Customers
INSERT INTO customers (station_id, customer_id, first_name, last_name, contact_number, customer_type, status, registered_by, registered_at, created_at) VALUES
(1, 'C-004', 'Ana', 'Garcia', '09171111111', 'regular', 'active', 1, NOW(), NOW()),
(1, 'C-005', 'Jose', 'Fernandez', '09282222222', 'regular', 'active', 1, NOW(), NOW()),
(1, 'C-006', 'Linda', 'Torres', '09393333333', 'regular', 'active', 1, NOW(), NOW());

-- Sample Fleet Customers
INSERT INTO customers (station_id, customer_id, first_name, last_name, contact_number, customer_type, company_name, company_address, company_contact_person, company_contact_number, status, registered_by, registered_at, created_at) VALUES
(1, 'C-007', 'Roberto', 'Gonzales', '09174444444', 'fleet', 'ABC Transport Corp', '123 Business Ave, Davao City', 'Roberto Gonzales', '09174444444', 'active', 1, NOW(), NOW()),
(1, 'C-008', 'Carmen', 'Lopez', '09285555555', 'fleet', 'XYZ Logistics Inc', '456 Commerce St, Davao City', 'Carmen Lopez', '09285555555', 'active', 1, NOW(), NOW());

-- ═══════════════════════════════════════════════════════════════════════════════
-- VERIFY AFTER INSERT
-- ═══════════════════════════════════════════════════════════════════════════════

SELECT 
    id,
    customer_id,
    CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) AS customer_name,
    contact_number,
    customer_type,
    status,
    station_id
FROM customers 
ORDER BY id DESC 
LIMIT 20;

-- ═══════════════════════════════════════════════════════════════════════════════
