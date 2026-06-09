import subprocess, sys
sys.stdout.reconfigure(encoding='utf-8')

mysql = r"C:\xampp\mysql\bin\mysql.exe"
DB = "petron_pos_db_secure"

def q(sql):
    r = subprocess.run([mysql, "-u", "root", DB], input=sql.encode('utf-8'), capture_output=True)
    out = r.stdout.decode('utf-8', errors='replace').strip()
    err = r.stderr.decode('utf-8', errors='replace').strip()
    if r.returncode != 0:
        print(f"  ERR: {err[:200]}")
    return out

# Mapping: old_id -> new_id
# 17=superadmin→1, 21=staff→2, 22=manager→3, 23=admin→4
id_map = {17: 1, 21: 2, 22: 3, 23: 4}

# All tables with FK to users.id (from earlier query)
ref_tables = [
    ("activity_logs",                   "user_id"),
    ("audit_log",                       "user_id"),
    ("audit_logs",                      "user_id"),
    ("audit_trail",                     "manager_id"),
    ("calendar_events",                 "created_by"),
    ("calendar_events",                 "manager_assigned"),
    ("calendar_events",                 "staff_assigned"),
    ("calendar_events",                 "updated_by"),
    ("calendar_event_conflicts",        "resolved_by"),
    ("calendar_event_history",          "changed_by"),
    ("calendar_event_notifications",    "user_id"),
    ("calibration_logs",                "encoded_by"),
    ("customers",                       "mgr_reviewed_by"),
    ("customer_credit_transactions",    "created_by"),
    ("customer_statements",             "generated_by"),
    ("customer_update_requests",        "requested_by"),
    ("customer_update_requests",        "reviewed_by"),
    ("daily_reconciliation",            "verified_by"),
    ("deliveries_oversight",            "encoded_by"),
    ("deliveries_oversight",            "admin_id"),
    ("error_events",                    "User_ID"),
    ("error_events",                    "Assigned_To"),
    ("error_events",                    "Resolved_By"),
    ("export_logs",                     "user_id"),
    ("fuel_adjustments",                "approved_by"),
    ("fuel_adjustments",                "user_id"),
    ("fuel_calibration_records",        "staff_id"),
    ("fuel_daily_readings",             "user_id"),
    ("fuel_deliveries",                 "received_by"),
    ("fuel_deliveries",                 "verified_by"),
    ("fuel_pumps",                      "calibration_updated_by"),
    ("fuel_purchase_orders",            "created_by"),
    ("fuel_purchase_orders",            "delivered_by"),
    ("fuel_reconciliation",             "verified_by"),
    ("fuel_sales_summary",              "staff_id"),
    ("fuel_stock_requests",             "manager_id"),
    ("fuel_stock_requests",             "staff_id"),
    ("fuel_transactions",               "staff_id"),
    ("fuel_transaction_audit",          "staff_id"),
    ("fuel_variance_reports",           "resolved_by"),
    ("integration_api_endpoints",       "created_by"),
    ("integration_audit",               "user_id"),
    ("integration_pos_parsers",         "created_by"),
    ("integration_sync_rules",          "created_by"),
    ("inventory_logs",                  "user_id"),
    ("job_orders",                      "assigned_by"),
    ("job_orders",                      "approved_by"),
    ("job_orders",                      "reviewed_by"),
    ("job_orders",                      "user_id"),
    ("job_order_audit",                 "performed_by"),
    ("job_order_receipts",              "created_by"),
    ("job_order_service_types",         "reviewed_by"),
    ("job_order_service_types",         "submitted_by"),
    ("labor_sessions",                  "user_id"),
    ("login_attempts",                  "user_id"),
    ("low_stock_alerts",                "resolved_by"),
    ("manager_color_config",            "user_id"),
    ("manual_service_types",            "created_by"),
    ("merchandise_deliveries",          "manager_id"),
    ("merchandise_transactions",        "validated_by"),
    ("merchandise_transactions",        "staff_id"),
    ("merchandise_transaction_audit",   "staff_id"),
    ("module_config_audit",             "changed_by"),
    ("notifications",                   "user_id"),
    ("password_reset_tokens",           "user_id"),
    ("pending_merchandise_transactions","staff_id"),
    ("pending_merchandise_transactions","validated_by"),
    ("procurement_audit",               "user_id"),
    ("purchase_orders",                 "created_by"),
    ("purchase_orders",                 "approved_by"),
    ("received_items",                  "received_by"),
    ("receiving_batches",               "received_by"),
    ("sales",                           "user_id"),
    ("service_entries",                 "assigned_staff_id"),
    ("simple_stock_requests",           "user_id"),
    ("staff_audit_log",                 "user_id"),
    ("staff_calendar_events",           "staff_encoder_id"),
    ("staff_calendar_events",           "manager_assigned_id"),
    ("staff_calendar_event_history",    "changed_by"),
    ("staff_color_config",              "user_id"),
    ("staff_performance_log",           "user_id"),
    ("staff_schedules",                 "user_id"),
    ("staff_tasks",                     "user_id"),
    ("station_items",                   "created_by"),
    ("stock_requests",                  "manager_id"),
    ("stock_requests",                  "staff_id"),
    ("stock_request_audit",             "performed_by"),
    ("superadmin_notification_preferences", "user_id"),
    ("superadmin_search_history",       "user_id"),
    ("supplier_confirmations",          "confirmed_by"),
    ("system_activity_logs",            "user_id"),
    ("system_alerts",                   "resolved_by"),
    ("system_backups",                  "created_by"),
    ("system_error_logs",               "assigned_to"),
    ("system_error_logs",               "resolved_by"),
    ("system_error_logs",               "user_id"),
    ("system_maintenance_log",          "performed_by"),
    ("system_settings",                 "updated_by"),
    ("system_settings_audit",           "changed_by"),
    ("system_versions",                 "applied_by"),
    ("user_notifications",              "user_id"),
    ("user_preferences",                "user_id"),
    ("user_sessions",                   "user_id"),
    ("validation_actions_log",          "manager_id"),
    ("validation_actions_log",          "staff_id"),
    ("variance_alerts",                 "user_id"),
    ("vehicle_types",                   "reviewed_by"),
    ("vehicle_types",                   "submitted_by"),
]

print("=== Step 1: Disable FK checks ===")
q("SET FOREIGN_KEY_CHECKS=0;")

print("=== Step 2: Delete all users NOT in our 4 ===")
out = q("DELETE FROM users WHERE id NOT IN (17, 21, 22, 23);")
print(f"  Deleted extra users: done")

print("=== Step 3: Remap user_id in all referencing tables ===")
# Process in reverse order to avoid conflicts (high->low then low->high)
# Use temp negative IDs to avoid collision during remap
# Old → Temp negative → New
for old_id, new_id in id_map.items():
    tmp_id = -(old_id)  # e.g. 17 → -17
    for table, col in ref_tables:
        q(f"UPDATE `{table}` SET `{col}` = {tmp_id} WHERE `{col}` = {old_id};")

# Now apply temp → new
for old_id, new_id in id_map.items():
    tmp_id = -(old_id)
    for table, col in ref_tables:
        q(f"UPDATE `{table}` SET `{col}` = {new_id} WHERE `{col}` = {tmp_id};")

print("=== Step 4: Update users.id using temp IDs ===")
for old_id, new_id in id_map.items():
    q(f"UPDATE users SET id = -{old_id} WHERE id = {old_id};")
for old_id, new_id in id_map.items():
    q(f"UPDATE users SET id = {new_id} WHERE id = -{old_id};")

print("=== Step 5: Reset AUTO_INCREMENT to 5 ===")
q("ALTER TABLE users AUTO_INCREMENT = 5;")

print("=== Step 6: Re-enable FK checks ===")
q("SET FOREIGN_KEY_CHECKS=1;")

print("\n=== Final Verification ===")
out = subprocess.run([mysql, "-u", "root", "-e",
    "SELECT id, first_name, last_name, role, station_id FROM petron_pos_db_secure.users ORDER BY id;"],
    capture_output=True)
print(out.stdout.decode('utf-8', errors='replace'))
print("✅ ID reassignment complete!")
