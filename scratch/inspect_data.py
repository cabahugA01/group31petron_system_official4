import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

def q(sql):
    r = subprocess.run([mysql, "-u", "root", DB], input=sql.encode('utf-8'), capture_output=True)
    out = r.stdout.decode('utf-8', errors='replace').strip()
    return out

tables_with_id = [
    ("fuel_adjustments", "fuel_type_id"),
    ("fuel_inventory", "fuel_type_id"),
    ("fuel_pricing", "fuel_type_id"),
    ("fuel_pumps", "fuel_type_id"),
    ("fuel_purchase_orders", "fuel_type_id"),
    ("fuel_reconciliation", "fuel_type_id"),
    ("fuel_variance_reports", "fuel_type_id"),
    ("low_stock_alerts", "fuel_type_id"),
    ("products", "fuel_type_id")
]

tables_with_name = [
    "accounts_receivable",
    "calibration_logs",
    "fuel_adjustments",
    "fuel_calibration_defaults",
    "fuel_calibration_records",
    "fuel_daily_readings",
    "fuel_deliveries",
    "fuel_inventory",
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

print("=== Record counts by fuel_type_id ===")
for table, col in tables_with_id:
    print(f"\nTable: {table}")
    sql = f"SELECT `{col}`, COUNT(*) FROM `{table}` GROUP BY `{col}`"
    print(q(sql))

print("\n=== Record counts by fuel_type name in tables ===")
for table in tables_with_name:
    print(f"\nTable: {table}")
    sql = f"SELECT `fuel_type`, COUNT(*) FROM `{table}` GROUP BY `fuel_type`"
    print(q(sql))
