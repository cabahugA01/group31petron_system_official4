path = "public/staff_transactions_hub.php"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

# Let's search for the block
target_lines = [
    "$mt_date_col   AS transaction_date,",
    "mt.item_sku,",
    "mt.quantity,",
    "mt.unit_price"
]

# We will normalize line endings and spaces
lines = content.splitlines()
found_idx = -1
for i in range(len(lines) - 4):
    if (target_lines[0] in lines[i] and 
        target_lines[1] in lines[i+1] and 
        target_lines[2] in lines[i+2] and 
        target_lines[3] in lines[i+3]):
        found_idx = i
        break

if found_idx != -1:
    print(f"Found target starting at line {found_idx+1}")
    # Replace unit_price line to include validation_status
    # Check if validation_status is already there
    if "validation_status" not in lines[found_idx+4] and "validation_status" not in lines[found_idx+3]:
        lines[found_idx+3] = lines[found_idx+3] + ",\n                    $mt_valstat_col AS validation_status"
        with open(path, "w", encoding="utf-8") as f:
            f.write("\n".join(lines) + "\n")
        print("Successfully updated file")
    else:
        print("Already updated")
else:
    print("Target lines not found")
