import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

def q(sql):
    r = subprocess.run([mysql, "-u", "root", DB], input=sql.encode('utf-8'), capture_output=True)
    out = r.stdout.decode('utf-8', errors='replace').strip()
    err = r.stderr.decode('utf-8', errors='replace').strip()
    if r.returncode != 0:
        print(f"  ERR: {err[:200]}")
    return out

print("=== Current Fuel Types in Table `fuel_types` ===")
print(q("SELECT id, name, description, price_per_liter FROM fuel_types;"))

print("\n=== Show active fuel pumps ===")
print(q("SELECT fp.id, fp.pump_number, fp.fuel_type_id, ft.name AS fuel_type_name FROM fuel_pumps fp LEFT JOIN fuel_types ft ON fp.fuel_type_id = ft.id;"))

print("\n=== Show active fuel inventory ===")
print(q("SELECT fi.id, fi.fuel_type, fi.fuel_type_id, ft.name AS fuel_type_name, fi.price_per_liter FROM fuel_inventory fi LEFT JOIN fuel_types ft ON fi.fuel_type_id = ft.id;"))

print("\n=== Tables containing column fuel_type (varchar) ===")
find_cols_sql = """
SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'petron_pos_db_secure' 
  AND COLUMN_NAME IN ('fuel_type', 'fuel_type_name')
  AND DATA_TYPE IN ('varchar', 'char', 'text');
"""
print(q(find_cols_sql))
