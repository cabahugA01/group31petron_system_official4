path = "public/staff_transactions_hub.php"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()

target = """                    $mt_date_col   AS transaction_date,
                    mt.item_sku,
                    mt.quantity,
                    mt.unit_price
             FROM merchandise_transactions mt"""

replacement = """                    $mt_date_col   AS transaction_date,
                    mt.item_sku,
                    mt.quantity,
                    mt.unit_price,
                    $mt_valstat_col AS validation_status
             FROM merchandise_transactions mt"""

if target in content:
    content = content.replace(target, replacement)
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Success")
else:
    print("Target not found exactly, trying search...")
    # Try with flexible whitespace
    import re
    pat = re.escape(target).replace(r"\ ", r"\s*")
    match = re.search(pat, content)
    if match:
        content = content[:match.start()] + replacement + content[match.end():]
        with open(path, "w", encoding="utf-8") as f:
            f.write(content)
        print("Success regex")
    else:
        print("Target not found with regex either")
