with open(r'c:\xampp\htdocs\group31petron_system_official4\public\admin_transactions_oversight.php', 'r', encoding='utf-8-sig') as f:
    lines = f.readlines()
for idx, line in enumerate(lines):
    if 'atoOpenRejectModal' in line or 'atoOpenAdjustModal' in line:
        print(f"Line {idx+1}: {line.strip()}")
