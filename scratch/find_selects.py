with open("public/staff_transactions_hub.php", "r", encoding="utf-8") as f:
    content = f.read()

import re
matches = re.finditer(r"SELECT\s+.*?\s+FROM\s+merchandise_transactions\s+mt", content, re.IGNORECASE | re.DOTALL)
for m in matches:
    print("Match found:")
    print(content[m.start():m.start()+400])
    print("-" * 50)
