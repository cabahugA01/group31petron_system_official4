import re

with open('public/staff_transactions_hub.php', 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

matches = []
for i, line in enumerate(content.split('\n'), 1):
    if 'section=' in line.lower() or '$_get[\'section\']' in line.lower():
        matches.append(f"{i}: {line.strip()}")

with open('scratch/search_section_out.txt', 'w', encoding='utf-8') as out:
    out.write("\n".join(matches))

print(f"Found {len(matches)} matches, wrote to scratch/search_section_out.txt")
