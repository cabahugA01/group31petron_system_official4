import subprocess

files = [
    'public/login_new.php',
    'public/login.php',
    'public/forgot_password.php',
    'public/forgot_password_reset.php',
    'public/forgot_password_clean.php',
    'public/verify_login_otp.php',
    'public/verify_otp.php',
    'public/verify_otp_clean.php',
    'public/register.php',
    'public/transaction_details.php',
    'public/print_po_new.php',
    'public/staff_fuel_inventory_estate.php'
]

has_error = False
for f in files:
    res = subprocess.run([r'C:\xampp\php\php.exe', '-l', f], capture_output=True, text=True)
    if res.returncode != 0:
        print(f"Error in {f}: {res.stderr}")
        has_error = True

if not has_error:
    print("All files passed syntax check successfully!")
