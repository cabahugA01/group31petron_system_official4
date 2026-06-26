with open('public/staff_transactions_hub.php', 'r', encoding='utf-8') as f:
    content = f.read()

import re
matches = re.findall(r"['\"]action['\"]\s*:\s*['\"]([^'\"]+)['\"]", content)
print("Actions found:", set(matches))
