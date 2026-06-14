-- ============================================================
-- Sample Station Coordinates for Philippines
-- database/sample_station_coordinates.sql
-- Use this to populate your stations with realistic coordinates
-- ============================================================

-- ============================================================
-- NCR Stations - Accurate Coordinates
-- ============================================================
-- Quezon City - Near Quezon Memorial Circle
UPDATE stations SET latitude = 14.6760, longitude = 121.0437, region = 'NCR', contact_number = '(02) 8888-0001' WHERE name LIKE '%Quezon City%' OR name LIKE '%QC%' LIMIT 1;

-- Makati - Ayala Avenue
UPDATE stations SET latitude = 14.5547, longitude = 121.0244, region = 'NCR', contact_number = '(02) 8888-0002' WHERE name LIKE '%Makati%' LIMIT 1;

-- Manila - Rizal Park area
UPDATE stations SET latitude = 14.5764, longitude = 120.9772, region = 'NCR', contact_number = '(02) 8888-0003' WHERE name LIKE '%Manila%' AND name NOT LIKE '%Paranaque%' AND name NOT LIKE '%Las Pinas%' LIMIT 1;

-- Caloocan - Monument area
UPDATE stations SET latitude = 14.6540, longitude = 120.9840, region = 'NCR', contact_number = '(02) 8888-0004' WHERE name LIKE '%Caloocan%' LIMIT 1;

-- San Juan - Wilson Street area
UPDATE stations SET latitude = 14.6019, longitude = 121.0355, region = 'NCR', contact_number = '(02) 8888-0005' WHERE name LIKE '%San Juan%' LIMIT 1;

-- Pasig - Ortigas Center
UPDATE stations SET latitude = 14.5858, longitude = 121.0577, region = 'NCR', contact_number = '(02) 8888-0006' WHERE name LIKE '%Pasig%' OR name LIKE '%Ortigas%' LIMIT 1;

-- Taguig - BGC area
UPDATE stations SET latitude = 14.5378, longitude = 121.0168, region = 'NCR', contact_number = '(02) 8888-0007' WHERE name LIKE '%Taguig%' OR name LIKE '%BGC%' OR name LIKE '%Bonifacio%' LIMIT 1;

-- Parañaque - Near airport
UPDATE stations SET latitude = 14.4899, longitude = 121.0158, region = 'NCR', contact_number = '(02) 8888-0008' WHERE name LIKE '%Paranaque%' OR name LIKE '%Parañaque%' LIMIT 1;

-- Mandaluyong - EDSA Shangri-La area
UPDATE stations SET latitude = 14.5794, longitude = 121.0359, region = 'NCR', contact_number = '(02) 8888-0009' WHERE name LIKE '%Mandaluyong%' LIMIT 1;

-- Las Piñas
UPDATE stations SET latitude = 14.4378, longitude = 120.9816, region = 'NCR', contact_number = '(02) 8888-0010' WHERE name LIKE '%Las Pinas%' OR name LIKE '%Las Piñas%' LIMIT 1;

-- Muntinlupa - Alabang area
UPDATE stations SET latitude = 14.3777, longitude = 121.0370, region = 'NCR', contact_number = '(02) 8888-0011' WHERE name LIKE '%Muntinlupa%' OR name LIKE '%Alabang%' LIMIT 1;

-- Marikina
UPDATE stations SET latitude = 14.6507, longitude = 121.1029, region = 'NCR', contact_number = '(02) 8888-0012' WHERE name LIKE '%Marikina%' LIMIT 1;

-- Valenzuela
UPDATE stations SET latitude = 14.6940, longitude = 120.9830, region = 'NCR', contact_number = '(02) 8888-0013' WHERE name LIKE '%Valenzuela%' LIMIT 1;

-- Navotas
UPDATE stations SET latitude = 14.6618, longitude = 120.9411, region = 'NCR', contact_number = '(02) 8888-0014' WHERE name LIKE '%Navotas%' LIMIT 1;

-- Malabon
UPDATE stations SET latitude = 14.6626, longitude = 120.9568, region = 'NCR', contact_number = '(02) 8888-0015' WHERE name LIKE '%Malabon%' LIMIT 1;

-- Pasay - Near MOA
UPDATE stations SET latitude = 14.5378, longitude = 120.9896, region = 'NCR', contact_number = '(02) 8888-0016' WHERE name LIKE '%Pasay%' OR name LIKE '%MOA%' LIMIT 1;

-- CALABARZON (Region IV-A)
UPDATE stations SET latitude = 14.1008, longitude = 121.0794, region = 'Region IV-A', contact_number = '(043) 123-4001' WHERE name LIKE '%Batangas%' LIMIT 1;
UPDATE stations SET latitude = 14.2456, longitude = 121.1619, region = 'Region IV-A', contact_number = '(043) 123-4002' WHERE name LIKE '%Lipa%' LIMIT 1;
UPDATE stations SET latitude = 14.2167, longitude = 121.1500, region = 'Region IV-A', contact_number = '(02) 8888-0009' WHERE name LIKE '%Sta Rosa%' OR name LIKE '%Santa Rosa%' LIMIT 1;
UPDATE stations SET latitude = 14.4092, longitude = 121.0415, region = 'Region IV-A', contact_number = '(02) 8888-0010' WHERE name LIKE '%Muntinlupa%' LIMIT 1;
UPDATE stations SET latitude = 14.1753, longitude = 121.1628, region = 'Region IV-A', contact_number = '(049) 123-4003' WHERE name LIKE '%San Pablo%' LIMIT 1;

-- Central Luzon (Region III)
UPDATE stations SET latitude = 14.8653, longitude = 120.8115, region = 'Region III', contact_number = '(045) 123-5001' WHERE name LIKE '%Olongapo%' LIMIT 1;
UPDATE stations SET latitude = 15.4817, longitude = 120.7119, region = 'Region III', contact_number = '(045) 123-5002' WHERE name LIKE '%Angeles%' LIMIT 1;
UPDATE stations SET latitude = 15.4757, longitude = 120.5959, region = 'Region III', contact_number = '(045) 123-5003' WHERE name LIKE '%San Fernando%' AND region = 'Region III' LIMIT 1;
UPDATE stations SET latitude = 14.8578, longitude = 120.8433, region = 'Region III', contact_number = '(047) 123-5004' WHERE name LIKE '%Balanga%' LIMIT 1;
UPDATE stations SET latitude = 15.0349, longitude = 120.6780, region = 'Region III', contact_number = '(044) 123-5005' WHERE name LIKE '%Gapan%' LIMIT 1;

-- Ilocos Region (Region I)
UPDATE stations SET latitude = 16.4023, longitude = 120.5960, region = 'Region I', contact_number = '(074) 123-6001' WHERE name LIKE '%Baguio%' LIMIT 1;
UPDATE stations SET latitude = 17.6132, longitude = 120.5763, region = 'Region I', contact_number = '(077) 123-6002' WHERE name LIKE '%Laoag%' LIMIT 1;
UPDATE stations SET latitude = 16.0480, longitude = 120.3340, region = 'Region I', contact_number = '(075) 123-6003' WHERE name LIKE '%Dagupan%' LIMIT 1;
UPDATE stations SET latitude = 15.9754, longitude = 120.5739, region = 'Region I', contact_number = '(072) 123-6004' WHERE name LIKE '%Urdaneta%' LIMIT 1;

-- Cagayan Valley (Region II)
UPDATE stations SET latitude = 17.6129, longitude = 121.7270, region = 'Region II', contact_number = '(078) 123-7001' WHERE name LIKE '%Tuguegarao%' LIMIT 1;
UPDATE stations SET latitude = 16.9754, longitude = 121.8107, region = 'Region II', contact_number = '(078) 123-7002' WHERE name LIKE '%Cauayan%' LIMIT 1;
UPDATE stations SET latitude = 17.4354, longitude = 121.8656, region = 'Region II', contact_number = '(078) 123-7003' WHERE name LIKE '%Ilagan%' LIMIT 1;

-- Bicol Region (Region V)
UPDATE stations SET latitude = 13.4214, longitude = 123.4136, region = 'Region V', contact_number = '(054) 123-8001' WHERE name LIKE '%Naga%' LIMIT 1;
UPDATE stations SET latitude = 13.1391, longitude = 123.7437, region = 'Region V', contact_number = '(052) 123-8002' WHERE name LIKE '%Legazpi%' LIMIT 1;
UPDATE stations SET latitude = 13.8341, longitude = 123.4956, region = 'Region V', contact_number = '(054) 123-8003' WHERE name LIKE '%Daet%' LIMIT 1;

-- Western Visayas (Region VI)
UPDATE stations SET latitude = 10.7202, longitude = 122.5621, region = 'Region VI', contact_number = '(033) 123-9001' WHERE name LIKE '%Iloilo%' LIMIT 1;
UPDATE stations SET latitude = 11.0050, longitude = 122.5378, region = 'Region VI', contact_number = '(033) 123-9002' WHERE name LIKE '%Bacolod%' LIMIT 1;
UPDATE stations SET latitude = 10.3157, longitude = 123.8854, region = 'Region VI', contact_number = '(036) 123-9003' WHERE name LIKE '%Kalibo%' LIMIT 1;
UPDATE stations SET latitude = 11.1143, longitude = 122.9560, region = 'Region VI', contact_number = '(034) 123-9004' WHERE name LIKE '%Silay%' LIMIT 1;

-- Central Visayas (Region VII)
UPDATE stations SET latitude = 10.3157, longitude = 123.8854, region = 'Region VII', contact_number = '(032) 123-1001' WHERE name LIKE '%Cebu%' LIMIT 1;
UPDATE stations SET latitude = 10.2981, longitude = 123.8941, region = 'Region VII', contact_number = '(032) 123-1002' WHERE name LIKE '%Mandaue%' LIMIT 1;
UPDATE stations SET latitude = 10.3509, longitude = 123.9321, region = 'Region VII', contact_number = '(032) 123-1003' WHERE name LIKE '%Lapu-Lapu%' LIMIT 1;
UPDATE stations SET latitude = 9.8349, longitude = 124.3897, region = 'Region VII', contact_number = '(038) 123-1004' WHERE name LIKE '%Tagbilaran%' LIMIT 1;
UPDATE stations SET latitude = 10.0889, longitude = 123.4425, region = 'Region VII', contact_number = '(032) 123-1005' WHERE name LIKE '%Dumaguete%' LIMIT 1;

-- Eastern Visayas (Region VIII)
UPDATE stations SET latitude = 11.2503, longitude = 125.0039, region = 'Region VIII', contact_number = '(053) 123-1101' WHERE name LIKE '%Tacloban%' LIMIT 1;
UPDATE stations SET latitude = 11.7759, longitude = 125.0314, region = 'Region VIII', contact_number = '(055) 123-1102' WHERE name LIKE '%Catbalogan%' LIMIT 1;
UPDATE stations SET latitude = 12.5050, longitude = 124.5994, region = 'Region VIII', contact_number = '(055) 123-1103' WHERE name LIKE '%Calbayog%' LIMIT 1;

-- Zamboanga Peninsula (Region IX)
UPDATE stations SET latitude = 6.9104, longitude = 122.0790, region = 'Region IX', contact_number = '(062) 123-1201' WHERE name LIKE '%Zamboanga%' LIMIT 1;
UPDATE stations SET latitude = 8.5089, longitude = 123.6368, region = 'Region IX', contact_number = '(062) 123-1202' WHERE name LIKE '%Dipolog%' LIMIT 1;
UPDATE stations SET latitude = 8.1479, longitude = 123.8522, region = 'Region IX', contact_number = '(065) 123-1203' WHERE name LIKE '%Dapitan%' LIMIT 1;

-- Northern Mindanao (Region X)
UPDATE stations SET latitude = 8.4829, longitude = 124.6496, region = 'Region X', contact_number = '(088) 123-1301' WHERE name LIKE '%Cagayan de Oro%' LIMIT 1;
UPDATE stations SET latitude = 8.2280, longitude = 124.2452, region = 'Region X', contact_number = '(088) 123-1302' WHERE name LIKE '%Iligan%' LIMIT 1;
UPDATE stations SET latitude = 8.1478, longitude = 125.1287, region = 'Region X', contact_number = '(088) 123-1303' WHERE name LIKE '%Gingoog%' LIMIT 1;

-- Davao Region (Region XI)
UPDATE stations SET latitude = 7.0731, longitude = 125.6128, region = 'Region XI', contact_number = '(082) 123-1401' WHERE name LIKE '%Davao%' LIMIT 1;
UPDATE stations SET latitude = 7.3391, longitude = 125.8080, region = 'Region XI', contact_number = '(084) 123-1402' WHERE name LIKE '%Tagum%' LIMIT 1;
UPDATE stations SET latitude = 6.7538, longitude = 125.2305, region = 'Region XI', contact_number = '(082) 123-1403' WHERE name LIKE '%Digos%' LIMIT 1;

-- SOCCSKSARGEN (Region XII)
UPDATE stations SET latitude = 6.1164, longitude = 125.1716, region = 'Region XII', contact_number = '(083) 123-1501' WHERE name LIKE '%General Santos%' LIMIT 1;
UPDATE stations SET latitude = 6.2545, longitude = 124.6926, region = 'Region XII', contact_number = '(083) 123-1502' WHERE name LIKE '%Koronadal%' LIMIT 1;
UPDATE stations SET latitude = 7.1644, longitude = 124.2090, region = 'Region XII', contact_number = '(064) 123-1503' WHERE name LIKE '%Cotabato%' LIMIT 1;

-- Caraga (Region XIII)
UPDATE stations SET latitude = 9.3371, longitude = 125.5272, region = 'Region XIII', contact_number = '(085) 123-1601' WHERE name LIKE '%Butuan%' LIMIT 1;
UPDATE stations SET latitude = 8.9517, longitude = 125.5407, region = 'Region XIII', contact_number = '(086) 123-1602' WHERE name LIKE '%Surigao%' LIMIT 1;
UPDATE stations SET latitude = 9.0753, longitude = 125.6084, region = 'Region XIII', contact_number = '(085) 123-1603' WHERE name LIKE '%Cabadbaran%' LIMIT 1;

-- If your station names don't match, update stations without coordinates
-- This will assign default coordinates based on region
UPDATE stations s
LEFT JOIN (
    SELECT id FROM stations WHERE latitude IS NOT NULL
) assigned ON s.id = assigned.id
SET 
    s.latitude = CASE 
        WHEN s.region = 'NCR' THEN 14.5995
        WHEN s.region = 'Region I' THEN 16.0834
        WHEN s.region = 'Region II' THEN 17.3292
        WHEN s.region = 'Region III' THEN 15.4817
        WHEN s.region = 'Region IV-A' THEN 14.1008
        WHEN s.region = 'Region IV-B' THEN 13.4132
        WHEN s.region = 'Region V' THEN 13.4214
        WHEN s.region = 'Region VI' THEN 10.7202
        WHEN s.region = 'Region VII' THEN 10.3157
        WHEN s.region = 'Region VIII' THEN 11.2517
        WHEN s.region = 'Region IX' THEN 8.4542
        WHEN s.region = 'Region X' THEN 8.4542
        WHEN s.region = 'Region XI' THEN 7.0731
        WHEN s.region = 'Region XII' THEN 6.1164
        WHEN s.region = 'Region XIII' THEN 8.9517
        WHEN s.region = 'CAR' THEN 16.4023
        WHEN s.region = 'BARMM' THEN 7.2257
        ELSE 14.5995
    END,
    s.longitude = CASE 
        WHEN s.region = 'NCR' THEN 120.9842
        WHEN s.region = 'Region I' THEN 120.3336
        WHEN s.region = 'Region II' THEN 121.8127
        WHEN s.region = 'Region III' THEN 120.7119
        WHEN s.region = 'Region IV-A' THEN 121.0794
        WHEN s.region = 'Region IV-B' THEN 121.6014
        WHEN s.region = 'Region V' THEN 123.4136
        WHEN s.region = 'Region VI' THEN 122.5621
        WHEN s.region = 'Region VII' THEN 123.8854
        WHEN s.region = 'Region VIII' THEN 125.0035
        WHEN s.region = 'Region IX' THEN 124.6319
        WHEN s.region = 'Region X' THEN 124.6319
        WHEN s.region = 'Region XI' THEN 125.6128
        WHEN s.region = 'Region XII' THEN 125.1716
        WHEN s.region = 'Region XIII' THEN 125.5407
        WHEN s.region = 'CAR' THEN 120.5960
        WHEN s.region = 'BARMM' THEN 124.2452
        ELSE 120.9842
    END,
    s.contact_number = COALESCE(s.contact_number, '(02) 8888-8888')
WHERE assigned.id IS NULL AND s.region IS NOT NULL;

-- Set default NCR for stations without region
UPDATE stations 
SET region = 'NCR', latitude = 14.5995, longitude = 120.9842 
WHERE region IS NULL OR region = '';
