<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../public/db_connect.php';
$me     = current_user();
$role   = role_key($me['role'] ?? '');
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// ── Schema migration: ensure all needed columns exist ────────────────────────
$_migrations = [
    "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS remarks TEXT DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS dr_number VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS source_ref VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS finalized_at DATETIME DEFAULT NULL",
    "ALTER TABLE deliveries_oversight ADD COLUMN IF NOT EXISTS finalized_by INT(11) DEFAULT NULL",
];
foreach($_migrations as $_sql){ try{ $pdo->exec($_sql); }catch(Exception $_e){} }
// Ensure status column is VARCHAR not ENUM
try{
    $ct=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='deliveries_oversight' AND COLUMN_NAME='status' LIMIT 1")->fetchColumn();
    if($ct && stripos($ct,'enum')!==false)
        $pdo->exec("ALTER TABLE deliveries_oversight MODIFY COLUMN status VARCHAR(60) NOT NULL DEFAULT 'Pending Manager Approval'");
}catch(Exception $e){}
// Fix blank statuses
try{ $pdo->exec("UPDATE deliveries_oversight SET status='Pending Manager Approval' WHERE status='' OR status IS NULL"); }catch(Exception $e){}
if ($action === 'export_excel' || $action === 'export_pdf') {
    if (!$me || !in_array($role, ['admin','superadmin'])) { http_response_code(403); echo 'Access denied'; exit; }
    $station_id = (int)user_station_id();
    $start=$_GET['start']??date('Y-m-d',strtotime('-30 days')); $end=$_GET['end']??date('Y-m-d'); $sf=$_GET['status']??'';
    $w='WHERE do2.station_id=? AND do2.delivery_date BETWEEN ? AND ?'; $p=[$station_id,$start,$end];
    if($sf!==''){
        if($sf==='expected'){
            $w.=" AND do2.status='Expected Delivery'";
        }elseif($sf==='pending'){
            // Admin 'pending' = records awaiting Admin oversight (already passed Manager)
            $w.=" AND do2.status IN ('Pending Admin Oversight','Pending Manager Confirmation','Pending Validation')";
        }elseif($sf==='approved'){
            $w.=" AND do2.status IN ('Confirmed','Validated')";
        }elseif($sf==='flagged'){
            $w.=" AND do2.status IN ('Discrepancy','Flagged')";
        }else{
            $w.=' AND do2.status=?';$p[]=$sf;
        }
    } else {
        // Default export: exclude raw Manager-queue records from Admin exports
        $w.=" AND do2.status NOT IN ('Pending Manager Approval')";
    }
    $st=$pdo->prepare("SELECT do2.*,u_enc.name AS encoded_by_name,u_adm.name AS admin_name,u_mgr.name AS manager_name FROM deliveries_oversight do2 LEFT JOIN users u_enc ON do2.encoded_by=u_enc.id LEFT JOIN users u_adm ON do2.admin_id=u_adm.id LEFT JOIN users u_mgr ON do2.manager_id=u_mgr.id $w ORDER BY do2.delivery_date DESC");
    $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if($action==='export_excel'){
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="deliveries_'.date('Y-m-d').'.xls"');
        $o=fopen('php://output','w'); fputcsv($o,['#','Ref','Type','DR','Supplier','Product','Qty','Unit','Date','Encoded By','Status','Notes']);
        foreach($rows as $r) fputcsv($o,[$r['id'],$r['delivery_ref'],ucfirst($r['delivery_type']),$r['dr_number']??'',$r['supplier'],$r['product'],$r['quantity'],$r['unit'],$r['delivery_date'],$r['encoded_by_name']??'',$r['status'],$r['admin_notes']??'']);
        fclose($o); exit;
    }
    $sn='Station'; try{$s=$pdo->prepare("SELECT name FROM stations WHERE id=? LIMIT 1");$s->execute([$station_id]);$sn=$s->fetchColumn()?:$sn;}catch(Exception $e){}
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Deliveries</title><style>body{font-family:Arial,sans-serif;font-size:11px}table{width:100%;border-collapse:collapse}th{background:#002F6C;color:#fff;padding:5px;font-size:10px;text-align:left}td{padding:4px 5px;border-bottom:1px solid #eee;font-size:10px}</style></head><body>';
    echo '<h2 style="color:#002F6C">Deliveries Oversight — '.htmlspecialchars($sn).'</h2><p>'.htmlspecialchars($start).' to '.htmlspecialchars($end).'</p>';
    echo '<table><thead><tr><th>#</th><th>Ref</th><th>Type</th><th>Supplier</th><th>Product</th><th>Qty</th><th>Date</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.(int)$r['id'].'</td><td>'.htmlspecialchars($r['delivery_ref']).'</td><td>'.htmlspecialchars(ucfirst($r['delivery_type'])).'</td><td>'.htmlspecialchars($r['supplier']).'</td><td>'.htmlspecialchars($r['product']).'</td><td>'.number_format((float)$r['quantity'],2).'</td><td>'.htmlspecialchars($r['delivery_date']).'</td><td>'.htmlspecialchars($r['status']).'</td><td>'.htmlspecialchars($r['admin_notes']??'').'</td></tr>';
    echo '</tbody></table><script>window.onload=function(){window.print();}</script></body></html>'; exit;
}
header('Content-Type: application/json');
if (!$me || !in_array($role, ['admin','superadmin'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Admin access required.']); exit; }
$station_id=(int)user_station_id();
try{$ct=$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='deliveries_oversight' AND COLUMN_NAME='status' LIMIT 1")->fetchColumn();if($ct&&stripos($ct,'enum')!==false)$pdo->exec("ALTER TABLE deliveries_oversight MODIFY COLUMN status VARCHAR(60) NOT NULL DEFAULT 'Pending Validation'");}catch(Exception $e){}
try{$pdo->exec("UPDATE deliveries_oversight SET status='Pending Manager Approval' WHERE status='' OR status IS NULL");}catch(Exception $e){}
foreach(["ALTER TABLE deliveries_oversight ADD COLUMN remarks TEXT DEFAULT NULL","ALTER TABLE deliveries_oversight ADD COLUMN dr_number VARCHAR(100) DEFAULT NULL","ALTER TABLE deliveries_oversight ADD COLUMN source_ref VARCHAR(100) DEFAULT NULL","ALTER TABLE deliveries_oversight ADD COLUMN manager_id INT DEFAULT NULL","ALTER TABLE deliveries_oversight ADD COLUMN manager_action_at DATETIME DEFAULT NULL","ALTER TABLE deliveries_oversight ADD COLUMN manager_notes TEXT DEFAULT NULL"] as $sql){try{$pdo->exec($sql);}catch(Exception $e){}}
try {
    switch ($action) {
        case 'get_po_price':
            // Fetch unit price from PO based on source_ref
            $source_ref = trim($_GET['source_ref'] ?? '');
            if (!$source_ref) {
                echo json_encode(['success' => false, 'message' => 'Source ref required']);
                break;
            }
            
            try {
                // Try purchase_orders table first
                $stmt = $pdo->prepare("SELECT unit_price FROM purchase_orders WHERE po_number = ? LIMIT 1");
                $stmt->execute([$source_ref]);
                $price = $stmt->fetchColumn();
                
                if ($price && $price > 0) {
                    echo json_encode(['success' => true, 'unit_price' => (float)$price, 'source' => 'purchase_orders']);
                    break;
                }
                
                // Try fuel_purchase_orders table
                $stmt = $pdo->prepare("SELECT unit_price FROM fuel_purchase_orders WHERE po_number = ? LIMIT 1");
                $stmt->execute([$source_ref]);
                $price = $stmt->fetchColumn();
                
                if ($price && $price > 0) {
                    echo json_encode(['success' => true, 'unit_price' => (float)$price, 'source' => 'fuel_purchase_orders']);
                    break;
                }
                
                // No price found
                echo json_encode(['success' => false, 'message' => 'PO price not found', 'unit_price' => 0]);
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error fetching PO price: ' . $e->getMessage(), 'unit_price' => 0]);
            }
            break;
            
        case 'process_delivery':
            $inp=json_decode(file_get_contents('php://input'),true)??[];
            $id=(int)($inp['id']??0);
            $expected_qty=(float)($inp['expected_quantity']??0);
            $actual_qty=(float)($inp['actual_quantity']??0);
            $damaged_qty=(float)($inp['damaged_quantity']??0);
            $unit_price=(float)($inp['unit_price']??0);
            $expected_amt=(float)($inp['expected_amount']??0);
            $payable_amt=(float)($inp['payable_amount']??0);
            $disc_type=trim($inp['discrepancy_type']??'');
            $remarks=trim($inp['remarks']??'');
            
            if(!$id){echo json_encode(['success'=>false,'message'=>'ID required']);break;}
            if($actual_qty<=0||$unit_price<=0){echo json_encode(['success'=>false,'message'=>'Invalid quantity or price']);break;}
            if(!$remarks){echo json_encode(['success'=>false,'message'=>'Remarks are required']);break;}
            
            $st=$pdo->prepare("SELECT * FROM deliveries_oversight WHERE id=? AND station_id=?");
            $st->execute([$id,$station_id]);
            $rec=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rec){echo json_encode(['success'=>false,'message'=>'Delivery not found']);break;}
            
            $pdo->beginTransaction();
            
            // Determine final status based on discrepancy
            $final_status='Validated';
            if($disc_type==='Partial'){
                $final_status='Partial Delivery';
            }elseif($disc_type==='Damaged'){
                $final_status='Damaged Items';
            }elseif($disc_type==='Rejected'){
                $final_status='Rejected Delivery';
            }elseif($disc_type==='Mixed'){
                $final_status='Partial Delivery'; // Mixed shows as Partial with notes
            }
            
            // Update delivery with payment computation
            $pdo->prepare("
                UPDATE deliveries_oversight 
                SET expected_quantity=?, actual_quantity=?, damaged_quantity=?, 
                    unit_price=?, expected_amount=?, payable_amount=?,
                    discrepancy_type=?, status=?, admin_id=?, admin_action_at=NOW(),
                    admin_notes=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $expected_qty, $actual_qty, $damaged_qty,
                $unit_price, $expected_amt, $payable_amt,
                $disc_type, $final_status, $me['id'],
                $remarks, $id
            ]);
            
            // Audit trail
            try{
                $pdo->prepare("
                    INSERT INTO audit_trail (transaction_id,manager_id,action_type,old_value,new_value,station_id,entity_type)
                    VALUES (?,?,'Process Delivery & Compute Payment',?,?,?,'delivery')
                ")->execute([$id,$me['id'],$rec['status'],$final_status.' | Payable: ₱'.number_format($payable_amt,2),$station_id]);
            }catch(Exception $e){}
            
            $pdo->commit();
            
            $msg='Delivery processed successfully. Payable amount: ₱'.number_format($payable_amt,2);
            if($disc_type){
                $msg.=' | Discrepancy: '.$disc_type;
            }
            
            echo json_encode(['success'=>true,'message'=>$msg]);
            break;
            
        case 'print_payment_report':
            if(!$me||!in_array($role,['admin','superadmin'])){http_response_code(403);echo'Access denied';exit;}
            $id=(int)($_GET['id']??0);
            if(!$id){echo'Invalid delivery ID';exit;}
            
            $st=$pdo->prepare("
                SELECT do2.*,u_enc.name AS encoded_by_name,u_adm.name AS admin_name,s.name AS station_name
                FROM deliveries_oversight do2
                LEFT JOIN users u_enc ON do2.encoded_by=u_enc.id
                LEFT JOIN users u_adm ON do2.admin_id=u_adm.id
                LEFT JOIN stations s ON do2.station_id=s.id
                WHERE do2.id=? AND do2.station_id=?
            ");
            $st->execute([$id,$station_id]);
            $rec=$st->fetch(PDO::FETCH_ASSOC);
            
            if(!$rec){echo'Delivery not found';exit;}
            
            // Generate printable payment report
            header('Content-Type: text/html; charset=utf-8');
            ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delivery Payment Report - <?php echo htmlspecialchars($rec['delivery_ref']); ?></title>
    <style>
        body{font-family:Arial,sans-serif;font-size:13px;margin:20px;color:#333;}
        .header{text-align:center;margin-bottom:30px;border-bottom:3px solid #002F70;padding-bottom:15px;}
        .header h1{margin:0;color:#002F70;font-size:24px;}
        .header p{margin:5px 0;color:#666;}
        .section{margin:20px 0;padding:15px;background:#f8f9fa;border-radius:8px;}
        .section h3{margin:0 0 12px;color:#002F70;font-size:16px;border-bottom:2px solid #dee2e6;padding-bottom:8px;}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:15px;}
        .info-item{padding:8px;background:#fff;border-radius:4px;}
        .info-label{font-weight:600;color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;}
        .info-value{font-size:14px;color:#222;margin-top:3px;}
        .payment-table{width:100%;border-collapse:collapse;margin:15px 0;}
        .payment-table th{background:#002F70;color:#fff;padding:10px;text-align:left;font-size:12px;}
        .payment-table td{padding:10px;border-bottom:1px solid #dee2e6;}
        .payment-table tr:last-child td{border-bottom:none;}
        .payment-total{background:#f0fdf4;padding:15px;border-radius:6px;margin:15px 0;border:2px solid #28a745;}
        .payment-total .label{font-size:16px;font-weight:700;color:#002F70;}
        .payment-total .amount{font-size:22px;font-weight:700;color:#28a745;float:right;}
        .discrepancy-box{background:#fff3cd;border:2px solid #ffc107;padding:12px;border-radius:6px;margin:15px 0;}
        .discrepancy-box strong{color:#856404;}
        .footer{margin-top:40px;padding-top:15px;border-top:2px solid #dee2e6;font-size:11px;color:#6c757d;text-align:center;}
        .signatures{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin:40px 0 20px;}
        .signature{text-align:center;}
        .signature .line{border-top:1px solid #000;margin:40px 0 5px;padding-top:5px;}
        .badge{display:inline-block;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700;}
        .badge-partial{background:#fff3cd;color:#f59e0b;border:1px solid #fbbf24;}
        .badge-damaged{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
        .badge-validated{background:#d1fae5;color:#065f46;}
        @media print{body{margin:0;}.header{page-break-after:avoid;}}
    </style>
</head>
<body>
    <div class="header">
        <h1>DELIVERY PAYMENT REPORT</h1>
        <p><strong>Petron Station Management System</strong></p>
        <p><?php echo htmlspecialchars($rec['station_name']??'Station'); ?></p>
    </div>

    <div class="section">
        <h3>Delivery Information</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Delivery Reference</div>
                <div class="info-value"><strong><?php echo htmlspecialchars($rec['delivery_ref']); ?></strong></div>
            </div>
            <div class="info-item">
                <div class="info-label">DR Number</div>
                <div class="info-value"><?php echo htmlspecialchars($rec['dr_number']?:'N/A'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Supplier</div>
                <div class="info-value"><?php echo htmlspecialchars($rec['supplier']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Delivery Date</div>
                <div class="info-value"><?php echo date('F d, Y',strtotime($rec['delivery_date'])); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Product</div>
                <div class="info-value"><?php echo htmlspecialchars($rec['product']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <?php 
                    $status_class='validated';
                    if(stripos($rec['status'],'Partial')!==false)$status_class='partial';
                    if(stripos($rec['status'],'Damaged')!==false)$status_class='damaged';
                    ?>
                    <span class="badge badge-<?php echo $status_class; ?>"><?php echo htmlspecialchars($rec['status']); ?></span>
                </div>
            </div>
        </div>
    </div>

    <?php if($rec['discrepancy_type']&&$rec['discrepancy_type']!==''): ?>
    <div class="discrepancy-box">
        <strong>⚠ DISCREPANCY DETECTED: <?php echo htmlspecialchars($rec['discrepancy_type']); ?></strong><br>
        <div style="margin-top:8px;font-size:12px;"><?php echo nl2br(htmlspecialchars($rec['admin_notes']??'')); ?></div>
    </div>
    <?php endif; ?>

    <div class="section">
        <h3>Quantity & Payment Computation</h3>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Expected Quantity (PO/Staff Encoded)</strong></td>
                    <td style="text-align:right;"><?php echo number_format($rec['expected_quantity']?:$rec['quantity'],2); ?> <?php echo htmlspecialchars($rec['unit']); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$rec['unit_price'],2); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$rec['expected_amount'],2); ?></td>
                </tr>
                <tr style="background:#f0fdf4;">
                    <td><strong>Actual Received Quantity</strong></td>
                    <td style="text-align:right;"><strong><?php echo number_format((float)$rec['actual_quantity'],2); ?> <?php echo htmlspecialchars($rec['unit']); ?></strong></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$rec['unit_price'],2); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$rec['actual_quantity']*(float)$rec['unit_price'],2); ?></td>
                </tr>
                <?php if((float)$rec['damaged_quantity']>0): ?>
                <tr style="background:#fff5f5;">
                    <td><strong>Less: Damaged/Defective Items</strong></td>
                    <td style="text-align:right;color:#dc2626;"><strong><?php echo number_format((float)$rec['damaged_quantity'],2); ?> <?php echo htmlspecialchars($rec['unit']); ?></strong></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$rec['unit_price'],2); ?></td>
                    <td style="text-align:right;color:#dc2626;">-₱<?php echo number_format((float)$rec['damaged_quantity']*(float)$rec['unit_price'],2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="payment-total">
            <span class="label">TOTAL PAYABLE AMOUNT:</span>
            <span class="amount">₱<?php echo number_format((float)$rec['payable_amount'],2); ?></span>
            <div style="clear:both;"></div>
        </div>
    </div>

    <div class="section">
        <h3>Admin Notes & Remarks</h3>
        <p style="margin:0;white-space:pre-wrap;"><?php echo htmlspecialchars($rec['admin_notes']?:'No additional remarks.'); ?></p>
    </div>

    <div class="section">
        <h3>Processing Details</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Encoded By (Staff)</div>
                <div class="info-value"><?php echo htmlspecialchars($rec['encoded_by_name']??'N/A'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Processed By (Admin)</div>
                <div class="info-value"><?php echo htmlspecialchars($rec['admin_name']??'N/A'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Processing Date</div>
                <div class="info-value"><?php echo $rec['admin_action_at']?date('F d, Y g:i A',strtotime($rec['admin_action_at'])):'N/A'; ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Report Generated</div>
                <div class="info-value"><?php echo date('F d, Y g:i A'); ?></div>
            </div>
        </div>
    </div>

    <div class="signatures">
        <div class="signature">
            <div class="line"><?php echo htmlspecialchars($rec['supplier']); ?></div>
            <div style="font-size:11px;color:#6c757d;">Supplier Representative</div>
        </div>
        <div class="signature">
            <div class="line"><?php echo htmlspecialchars($rec['admin_name']??''); ?></div>
            <div style="font-size:11px;color:#6c757d;">Station Admin</div>
        </div>
        <div class="signature">
            <div class="line">__________________________</div>
            <div style="font-size:11px;color:#6c757d;">Finance Officer</div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Important Note:</strong> This report serves as the official basis for supplier payment. Suppliers without system accounts should contact station admin/finance via phone or in-person for payment arrangements.</p>
        <p>Generated by Petron Station Management System | <?php echo date('F d, Y g:i A'); ?></p>
    </div>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
            <?php
            exit;
            
        case 'pending_count':
            // Admin pending count = records that have passed Manager validation
            // and are now awaiting Admin oversight. 'Pending Manager Approval' records
            // are still in the Manager queue — Admin should not see those as their pending items.
            $st=$pdo->prepare("SELECT COUNT(*) FROM deliveries_oversight WHERE station_id=? AND status IN ('Pending Admin Oversight','Pending Validation','Pending Manager Confirmation')");
            $st->execute([$station_id]); echo json_encode(['success'=>true,'count'=>(int)$st->fetchColumn()]); break;
        case 'list':
            $sf=trim($_GET['status']??''); $tf=trim($_GET['type']??''); $sup=trim($_GET['supplier']??'');
            $start=trim($_GET['start']??date('Y-m-d',strtotime('-30 days'))); $end=trim($_GET['end']??date('Y-m-d'));
            $w='WHERE do2.station_id=? AND do2.delivery_date BETWEEN ? AND ?'; $p=[$station_id,$start,$end];
            if($sf!==''){
                if($sf==='expected'){
                    $w.=" AND do2.status='Expected Delivery'";
                }elseif($sf==='pending'){
                    // Admin 'pending' = records awaiting Admin oversight (already passed Manager)
                    $w.=" AND do2.status IN ('Pending Admin Oversight','Pending Manager Confirmation','Pending Validation')";
                }elseif($sf==='approved'){
                    $w.=" AND do2.status IN ('Confirmed','Validated')";
                }elseif($sf==='flagged'){
                    $w.=" AND do2.status IN ('Discrepancy','Flagged')";
                }else{
                    $w.=' AND do2.status=?';$p[]=$sf;
                }
            }
            if($tf!==''){$w.=' AND do2.delivery_type=?';$p[]=$tf;}
            if($sup!==''){$w.=' AND do2.supplier LIKE ?';$p[]='%'.$sup.'%';}
            // When no status filter is set, Admin sees all records EXCEPT those still in the
            // Manager queue ('Pending Manager Approval'). Those belong to Manager, not Admin.
            if($sf===''){
                $w.=" AND do2.status NOT IN ('Pending Manager Approval')";
            }
            $st=$pdo->prepare("SELECT do2.*,u_enc.name AS encoded_by_name,u_adm.name AS admin_name,u_mgr.name AS manager_name FROM deliveries_oversight do2 LEFT JOIN users u_enc ON do2.encoded_by=u_enc.id LEFT JOIN users u_adm ON do2.admin_id=u_adm.id LEFT JOIN users u_mgr ON do2.manager_id=u_mgr.id {$w} ORDER BY FIELD(do2.status,'Discrepancy','Flagged','Pending Admin Oversight','Pending Manager Confirmation','Pending Validation','Expected Delivery','Confirmed','Validated'),do2.delivery_date DESC");
            $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
            $counts=['Expected'=>0,'Pending Validation'=>0,'Validated'=>0,'Flagged'=>0];
            foreach($rows as $r){
                $s=$r['status'];
                if($s==='Expected Delivery')$counts['Expected']++;
                elseif(in_array($s,['Pending Admin Oversight','Pending Manager Confirmation','Pending Validation']))$counts['Pending Validation']++;
                elseif(in_array($s,['Confirmed','Validated']))$counts['Validated']++;
                elseif(in_array($s,['Discrepancy','Flagged']))$counts['Flagged']++;
            }
            echo json_encode(['success'=>true,'data'=>$rows,'counts'=>$counts]); break;
        case 'detail':
            $id=(int)($_GET['id']??0); if(!$id){echo json_encode(['success'=>false,'message'=>'ID required']);break;}
            $st=$pdo->prepare("SELECT do2.*,u_enc.name AS encoded_by_name,u_adm.name AS admin_name,u_mgr.name AS manager_name FROM deliveries_oversight do2 LEFT JOIN users u_enc ON do2.encoded_by=u_enc.id LEFT JOIN users u_adm ON do2.admin_id=u_adm.id LEFT JOIN users u_mgr ON do2.manager_id=u_mgr.id WHERE do2.id=? AND do2.station_id=?");
            $st->execute([$id,$station_id]); $rec=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rec){echo json_encode(['success'=>false,'message'=>'Not found']);break;}
            try{$st2=$pdo->prepare("SELECT at.*,u.name AS actor_name FROM audit_trail at LEFT JOIN users u ON at.manager_id=u.id WHERE at.transaction_id=? AND at.entity_type='delivery' ORDER BY at.timestamp DESC");$st2->execute([$id]);$rec['audit']=$st2->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){$rec['audit']=[];}
            echo json_encode(['success'=>true,'data'=>$rec]); break;
        case 'validate':
            $inp=json_decode(file_get_contents('php://input'),true)??[]; $id=(int)($inp['id']??0); $notes=trim($inp['notes']??'');
            if(!$id){echo json_encode(['success'=>false,'message'=>'ID required']);break;}
            $st=$pdo->prepare("SELECT * FROM deliveries_oversight WHERE id=? AND station_id=?"); $st->execute([$id,$station_id]); $rec=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rec){echo json_encode(['success'=>false,'message'=>'Not found']);break;}
            // Guard: Admin cannot validate a delivery still in the Manager queue
            if($rec['status']==='Pending Manager Approval'){echo json_encode(['success'=>false,'message'=>'This delivery is still pending Manager approval. Admin cannot validate it until the Manager has reviewed it first.']);break;}
            if(in_array($rec['status'],['Validated','Confirmed'])){echo json_encode(['success'=>false,'message'=>'Already validated']);break;}
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE deliveries_oversight SET status='Validated',admin_id=?,admin_action_at=NOW(),admin_notes=?,updated_at=NOW() WHERE id=?")->execute([$me['id'],$notes?:null,$id]);
            try{$pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,old_value,new_value,station_id,entity_type) VALUES (?,?,'Validate',?,?,?,'delivery')")->execute([$id,$me['id'],$rec['status'],'Validated',$station_id]);}catch(Exception $e){}
            $pdo->commit(); echo json_encode(['success'=>true,'message'=>'Delivery validated successfully.']); break;
        case 'flag':
            $inp=json_decode(file_get_contents('php://input'),true)??[]; $id=(int)($inp['id']??0); $reason=trim($inp['reason']??'');
            if(!$id){echo json_encode(['success'=>false,'message'=>'ID required']);break;}
            if(!$reason){echo json_encode(['success'=>false,'message'=>'Reason is required']);break;}
            $st=$pdo->prepare("SELECT * FROM deliveries_oversight WHERE id=? AND station_id=?"); $st->execute([$id,$station_id]); $rec=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rec){echo json_encode(['success'=>false,'message'=>'Not found']);break;}
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE deliveries_oversight SET status='Flagged',admin_id=?,admin_action_at=NOW(),admin_notes=?,updated_at=NOW() WHERE id=?")->execute([$me['id'],$reason,$id]);
            try{$pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,old_value,new_value,station_id,entity_type) VALUES (?,?,'Flag',?,?,?,'delivery')")->execute([$id,$me['id'],$rec['status'],'Flagged: '.$reason,$station_id]);}catch(Exception $e){}
            $pdo->commit(); echo json_encode(['success'=>true,'message'=>'Delivery flagged.']); break;
        
        case 'process_delivery':
            $inp=json_decode(file_get_contents('php://input'),true)??[];
            $id=(int)($inp['id']??0);
            $expected_qty=(float)($inp['expected_quantity']??0);
            $actual_qty=(float)($inp['actual_quantity']??0);
            $damaged_qty=(float)($inp['damaged_quantity']??0);
            $unit_price=(float)($inp['unit_price']??0);
            $expected_amt=(float)($inp['expected_amount']??0);
            $payable_amt=(float)($inp['payable_amount']??0);
            $disc_type=trim($inp['discrepancy_type']??'');
            $remarks=trim($inp['remarks']??'');
            
            if(!$id||$actual_qty<=0||$unit_price<=0){echo json_encode(['success'=>false,'message'=>'Invalid data: ID, actual quantity, and unit price are required']);break;}
            if(!$remarks){echo json_encode(['success'=>false,'message'=>'Remarks are required']);break;}
            
            $st=$pdo->prepare("SELECT * FROM deliveries_oversight WHERE id=? AND station_id=?");
            $st->execute([$id,$station_id]);
            $rec=$st->fetch(PDO::FETCH_ASSOC);
            if(!$rec){echo json_encode(['success'=>false,'message'=>'Delivery not found']);break;}
            
            // Determine final status based on discrepancy
            $final_status='Validated';
            if($disc_type==='Partial')$final_status='Partial Delivery';
            elseif($disc_type==='Damaged')$final_status='Damaged Items';
            elseif($disc_type==='Rejected')$final_status='Rejected Delivery';
            elseif($disc_type==='Mixed')$final_status='Partial Delivery';
            
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE deliveries_oversight 
                SET expected_quantity=?, actual_quantity=?, damaged_quantity=?,
                    unit_price=?, expected_amount=?, payable_amount=?,
                    discrepancy_type=?, status=?, admin_id=?, admin_action_at=NOW(),
                    admin_notes=?, updated_at=NOW()
                WHERE id=?
            ")->execute([
                $expected_qty,$actual_qty,$damaged_qty,
                $unit_price,$expected_amt,$payable_amt,
                $disc_type,$final_status,$me['id'],$remarks,$id
            ]);
            
            // Log audit trail
            try{
                $pdo->prepare("INSERT INTO audit_trail (transaction_id,manager_id,action_type,old_value,new_value,station_id,entity_type) VALUES (?,?,'Process Delivery',?,?,?,'delivery')")->execute([$id,$me['id'],$rec['status'],$final_status.' | Payable: ₱'.number_format($payable_amt,2),$station_id]);
            }catch(Exception $e){}
            
            $pdo->commit();
            
            $msg='Delivery processed successfully. Payable amount: ₱'.number_format($payable_amt,2);
            if($disc_type)$msg.=' (Discrepancy type: '.$disc_type.')';
            
            echo json_encode(['success'=>true,'message'=>$msg]);
            break;
        
        case 'print_payment_report':
            $id=(int)($_GET['id']??0);
            if(!$id){http_response_code(400);echo 'Invalid delivery ID';exit;}
            
            $st=$pdo->prepare("SELECT do2.*,u_enc.name AS encoded_by_name,u_adm.name AS admin_name,st.name AS station_name FROM deliveries_oversight do2 LEFT JOIN users u_enc ON do2.encoded_by=u_enc.id LEFT JOIN users u_adm ON do2.admin_id=u_adm.id LEFT JOIN stations st ON do2.station_id=st.id WHERE do2.id=? AND do2.station_id=?");
            $st->execute([$id,$station_id]);
            $del=$st->fetch(PDO::FETCH_ASSOC);
            if(!$del){http_response_code(404);echo 'Delivery not found';exit;}
            
            // Generate printable payment report
            header('Content-Type: text/html; charset=utf-8');
            ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delivery Payment Report - <?php echo htmlspecialchars($del['delivery_ref']); ?></title>
    <style>
        body{font-family:Arial,sans-serif;padding:20px;max-width:800px;margin:0 auto;line-height:1.6;}
        .header{text-align:center;border-bottom:3px solid #002F70;padding-bottom:15px;margin-bottom:20px;}
        .header h1{color:#002F70;margin:0;font-size:24px;}
        .header p{margin:5px 0;color:#666;font-size:13px;}
        .section{margin:20px 0;padding:15px;background:#f8f9fa;border-radius:6px;}
        .section-title{font-weight:bold;color:#002F70;margin-bottom:10px;font-size:16px;border-bottom:2px solid #002F70;padding-bottom:5px;}
        .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:10px 0;}
        .info-item{padding:8px;background:#fff;border-radius:4px;border-left:3px solid #002F70;}
        .info-label{font-size:11px;color:#666;font-weight:600;text-transform:uppercase;}
        .info-value{font-size:14px;color:#222;font-weight:500;}
        .payment-table{width:100%;border-collapse:collapse;margin:15px 0;}
        .payment-table th{background:#002F70;color:#fff;padding:10px;text-align:left;font-size:12px;}
        .payment-table td{padding:10px;border-bottom:1px solid #ddd;font-size:13px;}
        .payment-table tr:last-child td{border-bottom:none;}
        .total-row{background:#f0fdf4;font-weight:bold;font-size:16px;}
        .total-row td{border-top:2px solid #28a745;color:#28a745;}
        .notes{background:#fff3cd;padding:12px;border-radius:6px;border-left:4px solid #ffc107;margin:15px 0;}
        .notes-title{font-weight:bold;color:#856404;margin-bottom:5px;}
        .footer{text-align:center;margin-top:30px;padding-top:15px;border-top:1px solid #ddd;font-size:11px;color:#666;}
        .discrepancy-badge{display:inline-block;padding:4px 10px;border-radius:12px;font-size:11px;font-weight:bold;margin-left:10px;}
        .badge-partial{background:#fff3cd;color:#f59e0b;}
        .badge-damaged{background:#fee2e2;color:#dc2626;}
        .badge-rejected{background:#f3f4f6;color:#6b7280;}
        @media print{body{padding:10px;}.no-print{display:none;}}
    </style>
</head>
<body>
    <div class="header">
        <h1>DELIVERY PAYMENT REPORT</h1>
        <p><strong>Petron Station Management System</strong></p>
        <p><?php echo htmlspecialchars($del['station_name']??'Station'); ?> | Generated: <?php echo date('F d, Y h:i A'); ?></p>
    </div>

    <div class="section">
        <div class="section-title">Delivery Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Delivery Reference</div>
                <div class="info-value"><?php echo htmlspecialchars($del['delivery_ref']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">DR Number</div>
                <div class="info-value"><?php echo htmlspecialchars($del['dr_number']?:'N/A'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Supplier</div>
                <div class="info-value"><?php echo htmlspecialchars($del['supplier']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Delivery Date</div>
                <div class="info-value"><?php echo date('F d, Y',strtotime($del['delivery_date'])); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Product</div>
                <div class="info-value"><?php echo htmlspecialchars($del['product']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Encoded By</div>
                <div class="info-value"><?php echo htmlspecialchars($del['encoded_by_name']?:'Staff'); ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">
            Payment Computation
            <?php if($del['discrepancy_type']): ?>
                <span class="discrepancy-badge badge-<?php echo strtolower($del['discrepancy_type']); ?>">
                    <?php echo htmlspecialchars($del['discrepancy_type']); ?> Delivery
                </span>
            <?php endif; ?>
        </div>
        <table class="payment-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="text-align:right;">Quantity</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Expected Quantity (from PO/Staff)</td>
                    <td style="text-align:right;"><?php echo number_format((float)$del['expected_quantity'],2); ?> <?php echo htmlspecialchars($del['unit']); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$del['unit_price'],2); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$del['expected_amount'],2); ?></td>
                </tr>
                <tr>
                    <td><strong>Actual Received Quantity</strong></td>
                    <td style="text-align:right;"><strong><?php echo number_format((float)$del['actual_quantity'],2); ?> <?php echo htmlspecialchars($del['unit']); ?></strong></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$del['unit_price'],2); ?></td>
                    <td style="text-align:right;"><strong>₱<?php echo number_format((float)$del['actual_quantity']*(float)$del['unit_price'],2); ?></strong></td>
                </tr>
                <?php if((float)$del['damaged_quantity']>0): ?>
                <tr style="color:#dc2626;">
                    <td>Less: Damaged/Defective Items</td>
                    <td style="text-align:right;">-<?php echo number_format((float)$del['damaged_quantity'],2); ?> <?php echo htmlspecialchars($del['unit']); ?></td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$del['unit_price'],2); ?></td>
                    <td style="text-align:right;">-₱<?php echo number_format((float)$del['damaged_quantity']*(float)$del['unit_price'],2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td colspan="3" style="text-align:right;">TOTAL PAYABLE AMOUNT:</td>
                    <td style="text-align:right;">₱<?php echo number_format((float)$del['payable_amount'],2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php if($del['admin_notes']): ?>
    <div class="notes">
        <div class="notes-title">Admin Remarks:</div>
        <div><?php echo nl2br(htmlspecialchars($del['admin_notes'])); ?></div>
    </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Authorization</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Processed By (Admin)</div>
                <div class="info-value"><?php echo htmlspecialchars($del['admin_name']?:'N/A'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Processing Date</div>
                <div class="info-value"><?php echo $del['admin_action_at']?date('F d, Y h:i A',strtotime($del['admin_action_at'])):'N/A'; ?></div>
            </div>
        </div>
    </div>

    <div class="footer">
        <p><strong>Note:</strong> This is an official payment report for supplier communication. Payable amount reflects actual delivered and usable items only.</p>
        <p>For inquiries, contact Admin or Finance Department.</p>
    </div>

    <div class="no-print" style="text-align:center;margin-top:20px;">
        <button onclick="window.print()" style="background:#002F70;color:#fff;padding:10px 20px;border:none;border-radius:6px;font-weight:600;cursor:pointer;">
            Print Report
        </button>
        <button onclick="window.close()" style="background:#6c757d;color:#fff;padding:10px 20px;border:none;border-radius:6px;font-weight:600;cursor:pointer;margin-left:10px;">
            Close
        </button>
    </div>

    <script>
        // Auto-print on load
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>
            <?php
            exit;
        
        default:
            echo json_encode(['success'=>false,'message'=>'Unknown action: '.htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
}
