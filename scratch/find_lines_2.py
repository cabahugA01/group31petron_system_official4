with open("public/staff_transactions_hub.php", "r", encoding="utf-8") as f:
    lines = f.readlines()

for idx, line in enumerate(lines):
    if "credit_customer_id" in line:
        print(f"Line {idx+1}: {line.strip()}")
