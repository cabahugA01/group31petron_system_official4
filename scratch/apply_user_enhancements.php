<?php
/**
 * Patcher script to apply User Management updates to public/users.php with line endings normalization
 */

$file = __DIR__ . '/../public/users.php';
$content = file_get_contents($file);

// Normalize line endings to LF
$content = str_replace("\r\n", "\n", $content);

// Helper function to normalize and replace
function safe_replace(&$subject, $search, $replace, $stepName) {
    $normalizedSearch = str_replace("\r\n", "\n", $search);
    $normalizedReplace = str_replace("\r\n", "\n", $replace);
    
    if (strpos($subject, $normalizedSearch) === false) {
        die("Error: Could not locate substring for step: $stepName\n");
    }
    
    $subject = str_replace($normalizedSearch, $normalizedReplace, $subject);
    echo "Successfully completed: $stepName\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Insert generateEmployeeID helper function
// ─────────────────────────────────────────────────────────────────────────────
$search1 = <<<'CODE'
function user_status_label(?string $status): string {
    $normalized = normalize_user_status_for_db((string)$status);
    return $normalized === 'Disabled' ? 'Disabled' : $normalized;
}
CODE;

$replace1 = <<<'CODE'
function user_status_label(?string $status): string {
    $normalized = normalize_user_status_for_db((string)$status);
    return $normalized === 'Disabled' ? 'Disabled' : $normalized;
}

function generateEmployeeID($pdo, $role) {
    $role = strtolower(trim($role));
    $prefix = 'STF';
    if ($role === 'superadmin') $prefix = 'SA';
    elseif ($role === 'admin') $prefix = 'ADM';
    elseif ($role === 'manager') $prefix = 'MGR';
    
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE employee_id LIKE ? ORDER BY employee_id DESC LIMIT 1");
    $stmt->execute([$prefix . '-%']);
    $last_id = $stmt->fetchColumn();
    
    $num = 1;
    if ($last_id) {
        $parts = explode('-', $last_id);
        $last_num = (int)end($parts);
        $num = $last_num + 1;
    }
    
    return $prefix . '-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}
CODE;

safe_replace($content, $search1, $replace1, "Injected helper function generateEmployeeID");

// ─────────────────────────────────────────────────────────────────────────────
// 2. Action Handler: Generate Employee ID & remove duplication
// ─────────────────────────────────────────────────────────────────────────────
$search2 = <<<'CODE'
        // 1. Add User
        if ($action === 'add_user') {
            $first_name_input  = trim($_POST['first_name']      ?? '');
            $last_name_input   = trim($_POST['last_name']       ?? '');
            $employee_id_input = trim($_POST['employee_id']     ?? '');
            $contact_input     = trim($_POST['contact_number']  ?? '');
            $email_input       = trim($_POST['email']           ?? '');
            $username_input    = trim($_POST['username']        ?? '');
            $assigned_shift    = trim($_POST['assigned_shift']  ?? '');
            $status_input      = trim($_POST['status']          ?? 'Active');
            $role_key_input    = $_POST['role']                 ?? '';
            $role              = role_key($role_key_input);
            $raw_password      = trim($_POST['new_password']    ?? '');
            $confirm_password  = trim($_POST['confirm_password']?? '');
CODE;

$replace2 = <<<'CODE'
        // 1. Add User
        if ($action === 'add_user') {
            $first_name_input  = trim($_POST['first_name']      ?? '');
            $last_name_input   = trim($_POST['last_name']       ?? '');
            $role_key_input    = $_POST['role']                 ?? '';
            $role              = role_key($role_key_input);
            $employee_id_input = generateEmployeeID($pdo, $role);
            $contact_input     = trim($_POST['contact_number']  ?? '');
            $email_input       = trim($_POST['email']           ?? '');
            $username_input    = trim($_POST['username']        ?? '');
            $assigned_shift    = trim($_POST['assigned_shift']  ?? '');
            $status_input      = trim($_POST['status']          ?? 'Active');
            $raw_password      = trim($_POST['new_password']    ?? '');
            $confirm_password  = trim($_POST['confirm_password']?? '');
CODE;

safe_replace($content, $search2, $replace2, "Updated action handler inputs (Auto Employee ID)");

// ─────────────────────────────────────────────────────────────────────────────
// 3. User List Columns selection
// ─────────────────────────────────────────────────────────────────────────────
$search3 = <<<'CODE'
$user_list_columns = "
    u.id,
    u.first_name,
    u.last_name,
    u.username,
    u.role,
    u.email,
    u.station_id,
    u.status,
    u.created_at,
    u.updated_at,
    u.profile_picture,
    u.name,
    s.name AS station_name
";
CODE;

$replace3 = <<<'CODE'
$user_list_columns = "
    u.id,
    u.employee_id,
    u.first_name,
    u.last_name,
    u.username,
    u.role,
    u.email,
    u.station_id,
    u.assigned_shift,
    u.status,
    u.created_at,
    u.updated_at,
    u.profile_picture,
    u.name,
    s.name AS station_name
";
CODE;

safe_replace($content, $search3, $replace3, "Updated query selection columns");

// ─────────────────────────────────────────────────────────────────────────────
// 4. Table UI Columns (Employee ID, Name, Username, Role, Shift, Status, Actions)
// ─────────────────────────────────────────────────────────────────────────────
$search4 = <<<'CODE'
            <thead>
                <tr>
                    <th>Name / Username</th>
                    <th>Role</th>
                    <th>Contact Info</th>
                    <?php if($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): 
                    $isActive = is_user_active_status($u['status'] ?? '');
                    $statusClass = $isActive ? 'success' : 'danger';
                    $statusLabel = user_status_label($u['status'] ?? '');
                    $roleKey = role_key($u['role'] ?? 'staff');
                    $roleLabel = normalize_role($u['role'] ?? $roleKey);
                    if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                    $roleClass = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                ?>
                <tr>
                    <td>
                        <div style="font-weight:bold;"><?php echo htmlspecialchars(isset($u['name']) ? $u['name'] : ($u['username'] ?? 'Unknown')); ?></div>
                        <div class="muted" style="font-size:0.85em;">@<?php echo htmlspecialchars($u['username'] ?? 'N/A'); ?></div>
                    </td>
                    <td><span class="badge bg-<?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                    <td>
                        <div><i class="fas fa-envelope fa-xs"></i> <?php echo htmlspecialchars($u['email'] ?? 'N/A'); ?></div>
                    </td>
                    <?php if($my_role === 'superadmin'): ?>
                        <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
CODE;

$replace4 = <<<'CODE'
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Assigned Shift</th>
                    <?php if($my_role === 'superadmin'): ?><th>Station</th><?php endif; ?>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): 
                    $isActive = is_user_active_status($u['status'] ?? '');
                    $statusClass = $isActive ? 'success' : 'danger';
                    $statusLabel = user_status_label($u['status'] ?? '');
                    $roleKey = role_key($u['role'] ?? 'staff');
                    $roleLabel = normalize_role($u['role'] ?? $roleKey);
                    if ($roleLabel === '') { $roleLabel = ucfirst($roleKey); }
                    $roleClass = in_array($roleKey, ['manager','admin','superadmin'], true) ? 'primary' : 'secondary';
                ?>
                <tr>
                    <td style="font-family: monospace; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($u['employee_id'] ?? '—'); ?></td>
                    <td>
                        <div style="font-weight:bold;"><?php echo htmlspecialchars(isset($u['name']) ? $u['name'] : ($u['username'] ?? 'Unknown')); ?></div>
                        <div class="muted" style="font-size:0.8em;"><?php echo htmlspecialchars($u['email'] ?? ''); ?></div>
                    </td>
                    <td style="font-weight: 500; color: #475569;">@<?php echo htmlspecialchars($u['username'] ?? '—'); ?></td>
                    <td><span class="badge bg-<?php echo $roleClass; ?>"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                    <td>
                        <?php if ($roleKey === 'staff'): ?>
                            <span class="badge" style="background-color: #f1f5f9; color: #1e293b; border: 1px solid #e2e8f0; font-weight: 600;">
                                <?php echo htmlspecialchars($u['assigned_shift'] ?? 'Unassigned'); ?>
                            </span>
                        <?php else: ?>
                            <span class="muted" style="font-size: 0.85em; color: #94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <?php if($my_role === 'superadmin'): ?>
                        <td><?php echo htmlspecialchars($u['station_name'] ?? 'Unassigned'); ?></td>
                    <?php endif; ?>
                    <td><span class="badge bg-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
CODE;

safe_replace($content, $search4, $replace4, "Updated table headers and rows");

// Update colspan for empty list
$searchColspan = 'colspan="<?php echo $my_role === \'superadmin\' ? 6 : 5; ?>"';
$replaceColspan = 'colspan="<?php echo $my_role === \'superadmin\' ? 8 : 7; ?>"';
safe_replace($content, $searchColspan, $replaceColspan, "Updated empty row colspan");

// ─────────────────────────────────────────────────────────────────────────────
// 5. Remove Employee ID from Add User Modal form & Update shift list
// ─────────────────────────────────────────────────────────────────────────────
$search5 = <<<'CODE'
                <div class="grid-2 gap-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label class="lbl">Employee ID <span class="muted">(optional)</span></label>
                        <input type="text" name="employee_id" class="inp full" placeholder="e.g. EMP-1092">
                    </div>
                    <div class="form-group">
                        <label class="lbl">Contact Number <span class="muted">(optional)</span></label>
                        <input type="text" name="contact_number" class="inp full" placeholder="e.g. 0917xxxxxxx">
                    </div>
                </div>
CODE;

$replace5 = <<<'CODE'
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="lbl">Contact Number <span class="muted">(optional)</span></label>
                    <input type="text" name="contact_number" class="inp full" placeholder="e.g. 0917xxxxxxx">
                </div>
CODE;

safe_replace($content, $search5, $replace5, "Removed Employee ID field from addModal form");

// ─────────────────────────────────────────────────────────────────────────────
// 6. Update Shift dropdown list in addModal
// ─────────────────────────────────────────────────────────────────────────────
$search6 = <<<'CODE'
                        <select name="assigned_shift" id="add_assigned_shift" class="inp full">
                            <option value="">Select shift</option>
                            <option value="Shift 1">Shift 1 (6:00 AM – 2:00 PM)</option>
                            <option value="Shift 2">Shift 2 (2:00 PM – 10:00 PM)</option>
                            <option value="Shift 3">Shift 3 (10:00 PM – 6:00 AM)</option>
                            <option value="All Shifts">All Shifts (Rotating)</option>
                        </select>
CODE;

$replace6 = <<<'CODE'
                        <select name="assigned_shift" id="add_assigned_shift" class="inp full">
                            <option value="">Select shift</option>
                            <option value="Shift 1">Shift 1 (6:00 AM – 2:00 PM)</option>
                            <option value="Shift 2">Shift 2 (2:00 PM – 12:00 AM)</option>
                        </select>
CODE;

safe_replace($content, $search6, $replace6, "Updated shifts options in addModal");

// ─────────────────────────────────────────────────────────────────────────────
// 7. View Profile modal grid additions (Employee ID, Assigned Shift, Contact)
// ─────────────────────────────────────────────────────────────────────────────
$search7 = <<<'CODE'
            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Email</div>
                    <div id="view_email" style="font-size:13px;color:#374151;word-break:break-all;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Status</div>
                    <div id="view_status"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Role</div>
                    <div id="view_role_text" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
            </div>
CODE;

$replace7 = <<<'CODE'
            <!-- Details grid -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Employee ID</div>
                    <div id="view_employee_id" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Assigned Shift</div>
                    <div id="view_assigned_shift" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Email</div>
                    <div id="view_email" style="font-size:13px;color:#374151;word-break:break-all;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Contact Number</div>
                    <div id="view_contact_number" style="font-size:13px;color:#374151;"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Status</div>
                    <div id="view_status"></div>
                </div>
                <div>
                    <div style="font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">Role</div>
                    <div id="view_role_text" style="font-size:13px;color:#374151;font-weight:600;"></div>
                </div>
            </div>
CODE;

safe_replace($content, $search7, $replace7, "Updated viewModal grid fields");

// ─────────────────────────────────────────────────────────────────────────────
// 8. JS openViewModal mapping
// ─────────────────────────────────────────────────────────────────────────────
$search8 = <<<'CODE'
    document.getElementById('view_modal_title').textContent = isManager ? 'Manager Profile' : 'Staff Profile';
    document.getElementById('view_name').textContent = user.name || '—';
    document.getElementById('view_username').textContent = '@' + (user.username || user.email || '—');
    document.getElementById('view_email').textContent = user.email || 'N/A';
    document.getElementById('view_role_text').textContent = isManager ? 'Manager' : 'Staff';
CODE;

$replace8 = <<<'CODE'
    document.getElementById('view_modal_title').textContent = isManager ? 'Manager Profile' : 'Staff Profile';
    document.getElementById('view_name').textContent = user.name || '—';
    document.getElementById('view_username').textContent = '@' + (user.username || user.email || '—');
    document.getElementById('view_email').textContent = user.email || 'N/A';
    document.getElementById('view_role_text').textContent = isManager ? 'Manager' : 'Staff';
    document.getElementById('view_employee_id').textContent = user.employee_id || '—';
    document.getElementById('view_assigned_shift').textContent = user.assigned_shift || '—';
    document.getElementById('view_contact_number').textContent = user.phone_number || '—';
CODE;

safe_replace($content, $search8, $replace8, "Updated JS openViewModal assignments");

// Save content
file_put_contents($file, $content);
echo "All done successfully!\n";
CODE;
echo "Enhancement patch script created.\n";
