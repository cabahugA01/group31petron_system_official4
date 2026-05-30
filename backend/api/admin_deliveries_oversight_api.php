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
        default:
            echo json_encode(['success'=>false,'message'=>'Unknown action: '.htmlspecialchars($action)]);
    }
} catch (Exception $e) {
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
}
