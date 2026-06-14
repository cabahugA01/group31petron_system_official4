path = r'c:\xampp\htdocs\group31petron_system_official4\partials\header.php'

with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, line in enumerate(lines):
    if ':root' in line or 'system_settings' in line or 'system_logo' in line:
        print(f"Line {i+1}: {line.strip()}")
