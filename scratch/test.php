<?php if(true): ?>
        </thead>
        <tbody>
            <?php if (count($rows) > 0): ?>
                <?php 
                // Group tracking for action buttons
                $prev_customer = null;
                $prev_date = null;
                $group_index = 0;
                
                foreach ($rows as $idx => $r): 
                    $pay_st = vt_pay_status($r); 
                    
                    // Check if this is a new group (different customer or different date)
                    $current_customer = $r['customer'];
                    $current_date = date('Y-m-d', strtotime($r['txn_date']));
                    $is_new_group = ($current_customer !== $prev_customer || $current_date !== $prev_date);
                    $show_actions = true; // Every transaction row exposes its own actions
                    
                    if ($is_new_group) {
                        $group_index++;
                        $prev_customer = $current_customer;
                        $prev_date = $current_date;
                    }
                    
                    // Build items list for this row
                    $rc_row_items = [];
                    if ($r['_source'] === 'merchandise_transactions') {
                        $mt_id = (int)$r['row_id'];
                        if ($mt_id && !empty($mgr_items_map[$mt_id])) {
                            $rc_row_items = $mgr_items_map[$mt_id];
                        } elseif (!empty($r['items_service']) && $r['items_service'] !== 'N/A') {
                            $parts = explode(',', $r['items_service']);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if ($p === '') continue;
                                $qty = 1;
                                if (preg_match('/\(x(\d+(?:\.\d+)?)\)/i', $p, $qmatch)) {
                                    $qty = (float)$qmatch[1];
                                }
                                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                                $rc_row_items[] = [
                                    'item_type'    => 'unknown',
                                    'product_name' => $clean_name,
                                    'quantity'     => $qty,
                                    'unit_price'   => 0,
                                    'subtotal'     => 0,
                                    'category'     => '',
                                    'size_variant' => '',
                                ];
                            }
                        }
                    } else {
                        // Job order: use items_service
                        if (!empty($r['items_service'])) {
                            $parts = explode(',', $r['items_service']);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if ($p === '') continue;
                                $qty = 1;
                                if (preg_match('/\(x(\d+(?:\.\d+)?)\)/i', $p, $qmatch)) {
                                    $qty = (float)$qmatch[1];
                                }
                                $clean_name = trim(preg_replace('/\s*\(x\d+(?:\.\d+)?\)$/i', '', $p));
                                $rc_row_items[] = [
                                    'item_type'    => 'service',
                                    'product_name' => $clean_name,
                                    'quantity'     => $qty,
                                    'unit_price'   => 0,
                                    'subtotal'     => 0,
                                    'category'     => 'Job Order',
                                    'size_variant' => $r['vehicle_plate'] ?? '',
                                ];
                            }
                        }
                    }
                    $expand_id = 'mgre_' . ($r['_source'] === 'job_orders' ? 'jo' : 'mt') . '_' . (int)$r['row_id'];
                    // Separate merchandise vs service items using keyword heuristics
                    $svc_keywords = ['cleaning','service','repair','check','lube','lubrication','alignment','rotation','flush','replacement','inspection','wash','polish','detailing','tune','oil change','brake','adjust'];
                    $is_svc_fn = function(array $i) use ($svc_keywords): bool {
                        if (strtolower(trim($i['item_type'] ?? '')) === 'service') return true;
                        $nl = strtolower($i['product_name'] ?? '');
                        foreach ($svc_keywords as $kw) {
                            if (strpos($nl, $kw) !== false) return true;
                        }
                        return false;
                    };
                    $col_svc   = array_values(array_filter($rc_row_items, fn($i) => $is_svc_fn($i)));
                    $col_merch = array_values(array_filter($rc_row_items, fn($i) => !$is_svc_fn($i)));
                    if (empty($col_svc) && !empty(trim($r['job_order_service'] ?? ''))) {
                        $col_svc = [['product_name' => trim($r['job_order_service'])]];
                    }
                    
                    // Smart unit label helper
                    $ri_unit_fn = function(string $name, float $qty): string {
                        $nl = strtolower($name);
                        $pl = $qty > 1;
                        if (strpos($nl,'refrigerant')!==false||strpos($nl,'r134a')!==false) return $pl?'Cans':'Can';
                        if (strpos($nl,'oil')!==false||strpos($nl,'coolant')!==false||strpos($nl,'fluid')!==false||strpos($nl,'lubricant')!==false) return $pl?'Bottles':'Bottle';
                        if (strpos($nl,'liter')!==false||strpos($nl,'litre')!==false) return $pl?'Liters':'Liter';
                        if (strpos($nl,'tire')!==false||strpos($nl,'tyre')!==false) return $pl?'pcs':'pc';
                        return $pl?'pcs':'pc';
                    };
                ?>
                <?php
                    $t = strtolower($r['entry_type'] ?? $r['transaction_type'] ?? '');
                    $has_items   = !empty(trim($r['items'] ?? ''));
                    $has_service = !empty(trim($r['service_type'] ?? $r['job_order_service'] ?? ''));

                    if ($has_items && $has_service) {
                        $tLabel = 'JO + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
                    } elseif ($t === 'combined') {
                        $tLabel = 'JO + Merchandise'; $tIcon = 'fa-wrench'; $tBadge = 'badge-purple';
                    } elseif ($t === 'job_order' || $t === 'job order' || $has_service) {
                        $tLabel = 'Job Order'; $tIcon = 'fa-wrench'; $tBadge = 'badge-orange';
                    } else {
                        $tLabel = 'Merchandise'; $tIcon = 'fa-shopping-cart'; $tBadge = 'badge-blue';
                    }

                    // Generate OR No. from transaction date + numeric DB id
                    $or_year = date('Y', strtotime($r['txn_date']));
                    $or_no   = ($r['_source'] === 'merchandise_transactions')
                        ? 'OR-' . $or_year . '-' . str_pad((int)$r['row_id'], 6, '0', STR_PAD_LEFT)
                        : 'JO-'  . $or_year . '-' . str_pad((int)$r['row_id'], 6, '0', STR_PAD_LEFT);
                ?>
                <tr>
                    <td style="white-space:nowrap;font-weight:700;font-size:10.5px;color:#0f172a;">
                        <?php echo htmlspecialchars($or_no); ; ?>
                    </td>
                    <td style="white-space:nowrap;font-size:9.5px;font-family:monospace;color:#64748b;overflow:hidden;text-overflow:ellipsis;" title="<?php echo htmlspecialchars($r['txn_id']); ; ?>">
                        <?php echo htmlspecialchars($r['txn_id']); ; ?>
                    </td>
                    <td style="font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#1e293b;" title="<?php echo htmlspecialchars($r['customer']); ; ?>">
                        <?php echo htmlspecialchars($r['customer']); ; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <span class="badge <?php echo $tBadge; ?>"><i class="fas <?php echo $tIcon; ?>"></i> <?php echo htmlspecialchars($tLabel); ?></span>
                    </td>
                    <!-- Products column -->
                    <td style="font-size:10px;line-height:1.2;vertical-align:middle;word-break:break-word;">
                        <?= format_transaction_items($r['items'] ?? '') ?>
                    </td>
                    <!-- Service Type column -->
                    <td style="font-size:10px;color:#475569;line-height:1.2;vertical-align:middle;word-break:break-word;" title="<?php echo htmlspecialchars(trim($r['service_type'] ?? $r['job_order_service'] ?? '')); ?>">
                        <?php echo htmlspecialchars(!empty(trim($r['service_type'] ?? $r['job_order_service'] ?? '')) ? trim($r['service_type'] ?? $r['job_order_service'] ?? '') : '—'); ?>
                    </td>
                    <!-- Service Fee column -->
                    <td style="font-size:10.5px;font-weight:700;color:#2563eb;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $s_cost = (float)($r['service_fee'] ?? 0);
                        echo $s_cost > 0 ? '₱' . number_format($s_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">—</span>';
                        ?>
                    </td>
                    <!-- Labor Fee column -->
                    <td style="font-size:10.5px;font-weight:700;color:#16a34a;vertical-align:middle;text-align:right;white-space:nowrap;">
                        <?php
                        $l_cost = (float)($r['labor_fee'] ?? 0);
                        echo $l_cost > 0 ? '₱' . number_format($l_cost, 2) : '<span style="color:#cbd5e1;font-weight:400;">—</span>';
                        ?>
                    </td>
                    <!-- Vehicle column -->
                    <td style="font-size:10.5px;text-align:center;white-space:nowrap;color:#475569;">
                      <?php
                        $veh = trim($r['vehicle_plate'] ?? '');
                        if ($veh === '' || $veh === '—' || $veh === 'N/A') {
                            echo '<span style="color:#cbd5e1;">N/A</span>';
                        } else {
                            echo htmlspecialchars($veh);
                        }
                      ?>
                    </td>
                    <!-- Total Amount column -->
                    <td style="font-weight:700;font-size:10.5px;text-align:right;white-space:nowrap;color:#0f172a;">
                        ₱<?php echo number_format((float)$r['amount'], 2); ; ?>
                    </td>
                    <!-- Payment Method column -->
                    <td style="font-size:10px;white-space:nowrap;color:#334155;">
                        <div><?php echo htmlspecialchars($r['payment_method']); ; ?></div>
                        <?php
                        $p_st_val = vt_pay_status($r);
                        $p_st_col = match(strtolower($p_st_val)) {
                            'paid' => '#16a34a',
                            'partial' => '#d97706',
                            'account receivable', 'credit', 'ar' => '#7c3aed',
                            default => '#dc2626'
                        };
                        ?>
                        <div style="font-size:9px;font-weight:700;color:<?php echo $p_st_col; ?>;"><?php echo htmlspecialchars($p_st_val); ?></div>
                    </td>
                    <td style="font-size:10px;white-space:nowrap;color:#475569;">
                        <?php 
                        $s_val = strtolower(trim($r['shift'] ?? ''));
                        $shift_time_label = match($s_val) {
                            'first', 'shift 1', '1' => 'Shift 1',
                            'second', 'shift 2', '2' => 'Shift 2',
                            default => htmlspecialchars($r['shift'] ?: 'N/A')
                        };
                        echo $shift_time_label;
                        ?>
                    </td>
                    <!-- Staff Encoder column -->
                    <td style="font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569;" title="<?php echo htmlspecialchars($r['staff_name']); ; ?>"><?php echo htmlspecialchars($r['staff_name']); ; ?></td>
                    <!-- Status column with badge -->
                    <td style="text-align:center;white-space:nowrap;">
                        <?php
                        $src_key = $r['_source'] . '_' . $r['row_id'];
                        $txn_key = $r['_source'] . '_' . $r['txn_id'];
                        $pending_req = $pending_txn_requests[$src_key] ?? ($pending_txn_requests[$txn_key] ?? null);
                        
                        $vst = strtolower(trim($r['validation_status'] ?? 'completed'));
                        $wst = strtolower(trim($r['workflow_status'] ?? ''));
                        
                        $has_adj_req  = ($pending_req && ($pending_req['request_type'] ?? '') === 'Adjustment');
                        $has_void_req = ($pending_req && ($pending_req['request_type'] ?? '') === 'Void');
                        
                        if ($has_void_req) {
                            echo '<span class="badge badge-red" style="white-space:nowrap;"><i class="fas fa-clock"></i> Void Requested</span>';
                        } elseif ($has_adj_req) {
                            echo '<span class="badge badge-orange" style="white-space:nowrap;"><i class="fas fa-clock"></i> Adjustment Requested</span>';
                        } elseif ($vst === 'voided') {
                            echo '<span class="badge badge-red" style="white-space:nowrap;"><i class="fas fa-ban"></i> Voided</span>';
                        } elseif ($vst === 'adjusted') {
                            echo '<span class="badge badge-purple" style="white-space:nowrap;"><i class="fas fa-check-circle"></i> Adjusted</span>';
                        } elseif ($wst === 'in_progress' || $wst === 'in progress') {
                            echo '<span class="badge badge-blue" style="white-space:nowrap;"><i class="fas fa-spinner"></i> In Progress</span>';
                        } elseif ($wst === 'released') {
                            echo '<span class="badge badge-green" style="white-space:nowrap;"><i class="fas fa-check"></i> Released</span>';
                        } else {
                            echo '<span class="badge badge-green" style="white-space:nowrap;"><i class="fas fa-check-circle"></i> Completed</span>';
                        }
                        ?>
                    </td>
                    <!-- Date & Time column -->
                    <td style="white-space:nowrap;line-height:1.2;">
                        <div style="font-size:10px;font-weight:600;color:#334155;white-space:nowrap;"><?php echo date('M d, Y', strtotime($r['txn_date'])); ?></div>
                        <div style="font-size:9.5px;color:#64748b;white-space:nowrap;"><?php echo date('h:i A', strtotime($r['txn_date'])); ?></div>
                    </td>
                    <!-- Actions column (Manager: View Details always, Adjust/Void ONLY when requested) -->
                    <td style="text-align:center;padding:4px 2px;vertical-align:middle;white-space:nowrap;">
                        <?php if ($show_actions): %>
                        <div style="display:flex;flex-direction:column;gap:3px;align-items:stretch;">
                            <!-- 1. View Details (Always available for Manager) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#0284c7;border:1.5px solid #0284c7;background:#ffffff !important;cursor:pointer;font-weight:700;"
                                    onclick="viewTransactionDetails('<?php echo htmlspecialchars($r['_source']); ?>', <?php echo (int)$r['row_id']; ?>)"
                                    title="View Details">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            
                            <?php if ($has_adj_req): %>
                            <!-- 2. Adjust Button (Only when Staff requested Adjustment) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#b45309;border:1.5px solid #f59e0b;background:#ffffff !important;cursor:pointer;font-weight:700;"
                                    onclick="openReviewRequestModal(<?php echo (int)$pending_req['id']; ?>, 'Adjustment', <?php echo (int)$r['row_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['txn_id'])); ?>', '<?php echo htmlspecialchars(addslashes($r['customer'])); ?>', '<?php echo htmlspecialchars(addslashes($r['entry_type'])); ?>', '<?php echo htmlspecialchars(addslashes($r['txn_date'])); ?>', '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>', '<?php echo htmlspecialchars(addslashes($pending_req['request_reason'])); ?>', <?php echo (float)($pending_req['new_amount'] ?? 0); ?>, '<?php echo htmlspecialchars(addslashes($r['_source'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_method'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')); ?>')"
                                    title="Review & Adjust">
                                <i class="fas fa-sliders-h"></i> Adjust
                            </button>
                            <?php elseif ($has_void_req): %>
                            <!-- 3. Void Button (Only when Staff requested Void) -->
                            <button type="button" class="vt-btn-act-sm"
                                    style="color:#dc2626;border:1.5px solid #dc2626;background:#ffffff !important;cursor:pointer;font-weight:700;"
                                    onclick="openReviewRequestModal(<?php echo (int)$pending_req['id']; ?>, 'Void', <?php echo (int)$r['row_id']; ?>, '<?php echo htmlspecialchars(addslashes($r['txn_id'])); ?>', '<?php echo htmlspecialchars(addslashes($r['customer'])); ?>', '<?php echo htmlspecialchars(addslashes($r['entry_type'])); ?>', '<?php echo htmlspecialchars(addslashes($r['txn_date'])); ?>', '<?php echo htmlspecialchars(addslashes($r['staff_name'])); ?>', '<?php echo htmlspecialchars(addslashes($pending_req['request_reason'])); ?>', 0, '<?php echo htmlspecialchars(addslashes($r['_source'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_method'])); ?>', '<?php echo htmlspecialchars(addslashes($r['payment_status'] ?? 'Paid')); ?>')"
                                    title="Review & Void">
                                <i class="fas fa-ban"></i> Void
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
<?php endif; ?>