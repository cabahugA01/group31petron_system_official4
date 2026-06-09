import re

with open('public/staff_transactions_hub.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Find updateFuelCalc function
start = content.find('function updateFuelCalc')
if start != -1:
    print(content[start+600:start+1800])
else:
    print("Function updateFuelCalc not found")
