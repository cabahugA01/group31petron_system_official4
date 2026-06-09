import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

def q(sql):
    r = subprocess.run([mysql, "-u", "root", DB], input=sql.encode('utf-8'), capture_output=True)
    out = r.stdout.decode('utf-8', errors='replace').strip()
    return out

print(q("SHOW CREATE TABLE fuel_pumps;"))
