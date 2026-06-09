import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

def q(sql):
    r = subprocess.run([mysql, "-u", "root", DB], input=sql.encode('utf-8'), capture_output=True)
    out = r.stdout.decode('utf-8', errors='replace').strip()
    err = r.stderr.decode('utf-8', errors='replace').strip()
    if r.returncode != 0:
        print(f"  ERR executing SQL:\n{sql}\nError: {err}")
    return out

print("=== Disable Foreign Key Checks ===")
q("SET FOREIGN_KEY_CHECKS=0;")

print("\n=== 1. Clean up/Update `fuel_types` table ===")
# Ensure only the 5 main types exist with correct IDs
q("UPDATE fuel_types SET name = 'XCS Plus', description = 'Petron Fuel: XCS Plus' WHERE id = 12;")
q("UPDATE fuel_types SET name = 'XTRA UNL', description = 'Petron Fuel: XTRA UNL' WHERE id = 13;")
# Delete Petron E10 (15) and Petron Blaze 100 (16)
q("DELETE FROM fuel_types WHERE id IN (15, 16);")
# Print current fuel types to verify
print(q("SELECT * FROM fuel_types;"))

print("\n=== 2. Clean up `fuel_inventory` ===")
# Delete E10 and Blaze 100 from fuel_inventory
q("DELETE FROM fuel_inventory WHERE fuel_type_id IN (15, 16) OR fuel_type IN ('Petron E10', 'Petron Blaze 100');")
# Update fuel_type names in fuel_inventory
q("UPDATE fuel_inventory SET fuel_type = 'XCS Plus' WHERE fuel_type = 'XCS';")
q("UPDATE fuel_inventory SET fuel_type = 'XTRA UNL' WHERE fuel_type = 'Xtra Advance';")
q("UPDATE fuel_inventory SET fuel_type = 'Diesel' WHERE fuel_type = 'Diesel Max';")
# Verify fuel_inventory
print(q("SELECT * FROM fuel_inventory;"))

print("\n=== 3. Clean up `fuel_pumps` and map to valid IDs ===")
# Map station 1253 pumps to correct IDs
# Pump 1 (XCS Plus -> 12)
q("UPDATE fuel_pumps SET fuel_type_id = 12 WHERE station_id = 1253 AND pump_number = '1';")
# Pump 2 (Turbo Diesel -> 11)
q("UPDATE fuel_pumps SET fuel_type_id = 11 WHERE station_id = 1253 AND pump_number = '2';")
# Pump 3 (Diesel -> 10)
q("UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1253 AND pump_number = '3';")
# Pump 4 (Kerosene -> 14)
q("UPDATE fuel_pumps SET fuel_type_id = 14 WHERE station_id = 1253 AND pump_number = '4';")
# Pump 5 (XTRA UNL -> 13)
q("UPDATE fuel_pumps SET fuel_type_id = 13 WHERE station_id = 1253 AND pump_number = '5';")

# Map station 1250 pumps to correct IDs
q("UPDATE fuel_pumps SET fuel_type_id = 11 WHERE station_id = 1250 AND pump_number = 'Pump 1';") # Turbo Diesel
q("UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1250 AND pump_number = 'Pump 2';") # Diesel
q("UPDATE fuel_pumps SET fuel_type_id = 12 WHERE station_id = 1250 AND pump_number = 'Pump 3';") # XCS Plus

# Map station 1268 pumps to correct IDs
q("UPDATE fuel_pumps SET fuel_type_id = 10 WHERE station_id = 1268;") # All Diesel

# Verify fuel_pumps mapping
print(q("SELECT fp.id, fp.station_id, fp.pump_number, fp.fuel_type_id, ft.name FROM fuel_pumps fp LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id;"))

print("\n=== 4. Update fuel_type names in other tables ===")
tables_to_update = [
    "accounts_receivable",
    "calibration_logs",
    "fuel_adjustments",
    "fuel_calibration_defaults",
    "fuel_calibration_records",
    "fuel_daily_readings",
    "fuel_deliveries",
    "fuel_readings",
    "fuel_reconciliation",
    "fuel_sales_summary",
    "fuel_stock_requests",
    "fuel_transactions",
    "fuel_transaction_audit",
    "fuel_variance_reports",
    "low_stock_alerts",
    "pump_configuration",
    "station_pump_assignment"
]

for table in tables_to_update:
    q(f"UPDATE `{table}` SET `fuel_type` = 'XCS Plus' WHERE `fuel_type` = 'XCS';")
    q(f"UPDATE `{table}` SET `fuel_type` = 'XTRA UNL' WHERE `fuel_type` = 'Xtra Advance';")
    q(f"UPDATE `{table}` SET `fuel_type` = 'Diesel' WHERE `fuel_type` = 'Diesel Max';")
    # Clean up any leftover deleted fuel types in transaction tables to avoid orphan reports
    q(f"DELETE FROM `{table}` WHERE `fuel_type` IN ('Petron E10', 'Petron Blaze 100');")

# Also update fuel_type_id references in other tables
q("UPDATE fuel_adjustments SET fuel_type_id = 13 WHERE fuel_type_id = 13;") # XTRA UNL
q("UPDATE fuel_adjustments SET fuel_type_id = 12 WHERE fuel_type_id = 12;") # XCS Plus
q("DELETE FROM fuel_adjustments WHERE fuel_type_id NOT IN (10, 11, 12, 13, 14);")
q("DELETE FROM low_stock_alerts WHERE fuel_type_id NOT IN (10, 11, 12, 13, 14);")

print("\n=== Re-enable Foreign Key Checks ===")
q("SET FOREIGN_KEY_CHECKS=1;")

print("\n=== Verification ===")
print("Current fuel types in DB:")
print(q("SELECT id, name FROM fuel_types;"))
print("\nUnique fuel types in transactions:")
print(q("SELECT DISTINCT fuel_type FROM fuel_transactions;"))
print("\nUnique fuel types in inventory:")
print(q("SELECT DISTINCT fuel_type FROM fuel_inventory;"))
