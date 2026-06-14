import os

files = [
    r'c:\xampp\htdocs\group31petron_system_official4\public\login_new.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\login.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\forgot_password.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\forgot_password_reset.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\forgot_password_clean.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\verify_login_otp.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\verify_otp.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\verify_otp_clean.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\register.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\transaction_details.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\print_po_new.php',
    r'c:\xampp\htdocs\group31petron_system_official4\public\staff_fuel_inventory_estate.php'
]

logo_replacement_php = """<?php echo '../' . get_system_logo_url(isset($station_id) ? (int)$station_id : (isset($user['station_id']) ? (int)$user['station_id'] : 0)); ?>"""

for path in files:
    if not os.path.exists(path):
        continue
        
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
        
    # We want to replace standard hardcoded images:
    # 1. ../assets/img/Petron Logo.png?v=2
    # 2. ../assets/img/Petron Logo.png
    # 3. /group31petron_system_official4/assets/img/Petron Logo.png
    
    modified = False
    
    # Simple check and replacement
    targets = [
        '../assets/img/Petron Logo.png?v=2',
        '../assets/img/Petron Logo.png',
        '/group31petron_system_official4/assets/img/Petron Logo.png'
    ]
    
    for t in targets:
        if t in content:
            if t == '/group31petron_system_official4/assets/img/Petron Logo.png':
                # Special root path replacement
                r_path = "<?php echo '/group31petron_system_official4/' . get_system_logo_url(isset($station_id) ? (int)$station_id : 0); ?>"
                content = content.replace(t, r_path)
            else:
                content = content.replace(t, logo_replacement_php)
            modified = True
            
    if modified:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated logo in: {os.path.basename(path)}")
