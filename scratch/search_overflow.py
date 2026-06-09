with open('public/staff_transactions_hub.php', 'r', encoding='utf-8', errors='ignore') as f:
    content = f.read()

matches = []
for i, line in enumerate(content.split('\n'), 1):
    if 'todayentriescard' in line.lower() or 'meter reading history' in line.lower() or 'overflow' in line.lower() or 'txn-content' in line.lower():
        matches.append(f"{i}: {line.strip()}")

with open('scratch/search_overflow.txt', 'w', encoding='utf-8') as out:
    out.write('\n'.join(matches))

print(f"Found {len(matches)} matches.")
