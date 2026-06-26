import re

with open('public/staff_transactions_hub.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Let's find occurrences of section in php code
lines = content.split('\n')
for idx, line in enumerate(lines):
    if 'section' in line.lower() and ('if' in line or 'switch' in line or '==' in line):
        print(f"{idx+1}: {line.strip()}")
