import re

with open('public/staff_transactions_hub.php', 'r', encoding='utf-8') as f:
    content = f.read()

print("--- occurrences of 'fuel_tab_default' ---")
for i, line in enumerate(content.split('\n'), 1):
    if 'fuel_tab_default' in line.lower():
        print(f"{i}: {line.strip()}")
