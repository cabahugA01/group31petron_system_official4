import re

with open('public/manager_set_prices.php', 'r', encoding='utf-8', errors='ignore') as f:
    c = f.read()

# Find unit selects
for m in re.finditer(r'<select[^>]*name=["\'](?:unit|edit_unit|add_unit)["\'][^>]*>(.*?)</select>', c, re.DOTALL | re.I):
    print(m.group(0))

for m in re.finditer(r'id=["\'](?:unit|prodUnit|editProdUnit)["\'][^>]*>(.*?)</select>', c, re.DOTALL | re.I):
    print(m.group(0))
