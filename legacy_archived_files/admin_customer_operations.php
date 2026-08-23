<?php
/**
 * ADMIN CUSTOMER OVERSIGHT — OPERATIONS API
 * View-only. No add, edit, verify, or delete.
 */
ob_start();
require_once __DIR__ . '/../backend/lib.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../backend/customer_module_helpers.php';
ob_end_clean();

require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

$me         = current_user();
$role       = role_key($me['role'] ?? '');
$station_id = (int)user_station_id();

customer_ensure_optional_columns($pdo);

if (!in_array($role, ['admin','superadmin','developer'])) {
    echo json_encode(['success'=>false,'error'=>'Unauthorized']); exit;
}

if (!customer_can_view_all_stations($role) && $station_id <= 0) {
    echo json_encode(['success'=>false,'error'=>'Your account is not assigned to a station.']); exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':               listCustomers();        break;
        case 'view':               viewCustomer();         break;
        case 'transaction_history':txnHistory();           break;
        case 'analytics':          getAnalytics();         break;
        case 'staff_list':         getStaffList();         break;
        case 'manager_list':       getManagerList();       break;
        default: echo json_encode(['success'=>false,'error'=>'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}

/* ─── helpers ─────────────────────────────────────────────────────── */
function sid() { global $station_id, $role; return ($role==='admin') ? $station_id : 0; }

function emptyStats() {
    return ['total'=>0,'new_today'=>0,'registered'=>0,'active'=>0,'inactive'=>0,
            'verified'=>0,'pending_v'=>0,'outstanding_count'=>0,'outstanding_total'=>0];
}

/* ─── LIST ────────────────────────────────────────────────────────── */
function listCustomers() {
    global $pdo, $station_id, $role;

    $search    = trim($_GET['search']      ?? '');
    $cid       = trim($_GET['customer_id'] ?? '');
    $cname     = trim($_GET['cname']       ?? '');
    $contact   = trim($_GET['contact']     ?? '');
    $ctype     = trim($_GET['ctype']       ?? '');
    $status    = trim($_GET['status']      ?? '');
    $verif     = trim($_GET['verif']       ?? '');
    $regBy     = trim($_GET['reg_by']      ?? '');
    $verBy     = trim($_GET['ver_by']      ?? '');
    $payStatus = trim($_GET['pay_status']  ?? '');
    $regFrom   = trim($_GET['reg_from']    ?? '');
    $regTo     = trim($_GET['reg_to']      ?? '');
    $txFrom    = trim($_GET['tx_from']     ?? '');
    $txTo      = trim($_GET['tx_to']       ?? '');

    $where = []; $params = [];
    customer_apply_station_scope($where, $params, 'c', $role, $station_id);

    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $balanceExpr = customer_balance_expr($pdo, 'c');
    $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $verificationExpr = customer_verification_status_expr($pdo, 'c');

    if ($search !== '') {
        $where[] = "(CAST(c.id AS CHAR) LIKE ? OR $customerIdExpr LIKE ? OR $displayNameExpr LIKE ? OR $contactExpr LIKE ?)";
        $s = "%$search%"; array_push($params,$s,$s,$s,$s);
    }
    if ($cid  !== '') { $where[] = "$customerIdExpr LIKE ?"; $params[] = "%$cid%"; }
    if ($cname!== '') { $where[] = "$displayNameExpr LIKE ?"; $params[] = "%$cname%"; }
    if ($contact!=='') { $where[] = "$contactExpr LIKE ?"; $params[] = "%$contact%"; }
    if ($ctype !== '' && $ctype !== 'registered') { $ctype = ''; }
    if ($status!=='') { $where[] = "$statusExpr = ?"; $params[] = $status; }
    if ($verif!== '') { $where[] = "$verificationExpr = ?"; $params[] = $verif; }
    if ($regBy!== '') { $where[] = "c.registered_by = ?"; $params[] = (int)$regBy; }
    if ($verBy!== '') { $where[] = "c.verified_by = ?"; $params[] = (int)$verBy; }
    if ($regFrom!=='') { $where[] = "DATE($registeredExpr) >= ?"; $params[] = $regFrom; }
    if ($regTo  !=='') { $where[] = "DATE($registeredExpr) <= ?"; $params[] = $regTo; }

    $wc = $where ? implode(' AND ',$where) : '1=1';

    $stmt = $pdo->prepare("
        SELECT c.id,
               c.station_id,
               $customerIdExpr AS customer_id_display,
               $displayNameExpr AS name,
               $contactExpr AS contact_number,
               $typeExpr AS ctype,
               $statusExpr AS status,
               $verificationExpr AS verification_status,
               $balanceExpr AS outstanding_balance,
               $creditLimitExpr AS credit_limit,
               $registeredExpr AS reg_date,
               TRIM(CONCAT(COALESCE(rb.first_name,''),' ',COALESCE(rb.last_name,''))) AS registered_by_name,
               TRIM(CONCAT(COALESCE(vb.first_name,''),' ',COALESCE(vb.last_name,''))) AS verified_by_name,
               c.verified_at
        FROM customers c
        LEFT JOIN users rb ON c.registered_by = rb.id
        LEFT JOIN users vb ON c.verified_by   = vb.id
        WHERE $wc
        ORDER BY $registeredExpr DESC, c.id DESC
    ");
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Per-customer last tx + payment status + date filter
    $result = [];
    foreach ($raw as $c) {
        $lastTx = null; $totalSpent = 0.0;
        $txStation = customer_can_view_all_stations($role) ? (int)($c['station_id'] ?? 0) : $station_id;
        foreach ([
            ["SELECT MAX(transaction_date),COALESCE(SUM(total_amount),0) FROM fuel_transactions WHERE customer_id=? AND station_id=?", $txStation],
            ["SELECT MAX(transaction_date),COALESCE(SUM(total_amount),0) FROM merchandise_transactions WHERE customer_id=? AND station_id=?", $txStation],
            ["SELECT MAX(created_at),COALESCE(SUM(total_cost),0) FROM job_orders WHERE customer_id=? AND station_id=?", $txStation],
        ] as [$sql, $sid]) {
            try {
                $q = $pdo->prepare($sql);
                $q->execute([$c['id'], $sid]);
                [$ld, $tot] = $q->fetch(PDO::FETCH_NUM) ?: [null,0];
                if ($ld) $lastTx = $lastTx ? max($lastTx,$ld) : $ld;
                $totalSpent += (float)$tot;
            } catch (Exception $e) {}
        }

        // Last tx date filter
        if ($txFrom !== '' || $txTo !== '') {
            if (!$lastTx) continue;
            $d = date('Y-m-d', strtotime($lastTx));
            if ($txFrom !== '' && $d < $txFrom) continue;
            if ($txTo   !== '' && $d > $txTo)   continue;
        }

        // Payment status
        $ob = (float)$c['outstanding_balance'];
        $ps = $ob <= 0 ? 'paid' : ($totalSpent > 0 && $ob < $totalSpent ? 'partial' : 'unpaid');
        if ($payStatus !== '' && $ps !== $payStatus) continue;

        $c['last_transaction'] = $lastTx;
        $c['total_spent']      = $totalSpent;
        $c['payment_status']   = $ps;
        $result[] = $c;
    }

    // Stats (full station, unfiltered)
    $statsWhere = [];
    $sp = [];
    customer_apply_station_scope($statsWhere, $sp, 'c', $role, $station_id);
    $sw = $statsWhere ? 'WHERE ' . implode(' AND ', $statsWhere) : 'WHERE 1=1';
    $st = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN DATE($registeredExpr)=CURDATE() THEN 1 ELSE 0 END) AS new_today,
               COUNT(*) AS registered,
               SUM(CASE WHEN $statusExpr='active'   THEN 1 ELSE 0 END) AS active,
               SUM(CASE WHEN $statusExpr!='active'  THEN 1 ELSE 0 END) AS inactive,
               SUM(CASE WHEN $verificationExpr='verified' THEN 1 ELSE 0 END) AS verified,
               SUM(CASE WHEN $verificationExpr='pending'  THEN 1 ELSE 0 END) AS pending_v,
               SUM(CASE WHEN $balanceExpr>0 THEN 1 ELSE 0 END) AS outstanding_count,
               COALESCE(SUM($balanceExpr),0) AS outstanding_total
        FROM customers c $sw
    ");
    $st->execute($sp);
    $stats = $st->fetch(PDO::FETCH_ASSOC) ?: emptyStats();

    echo json_encode(['success'=>true,'customers'=>$result,'stats'=>$stats,'count'=>count($result)]);
}

/* ─── VIEW ────────────────────────────────────────────────────────── */
function viewCustomer() {
    global $pdo, $station_id, $role;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) throw new Exception('ID required');

    $customerIdExpr = customer_id_expr($pdo, 'c');
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $contactExpr = customer_contact_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $statusExpr = customer_status_expr($pdo, 'c');
    $registeredExpr = customer_registered_at_expr($pdo, 'c');
    $balanceExpr = customer_balance_expr($pdo, 'c');
    $creditLimitExpr = customer_credit_limit_expr($pdo, 'c');
    $verificationExpr = customer_verification_status_expr($pdo, 'c');
    $govIdTypeExpr = customer_gov_id_type_expr($pdo, 'c');

    $where = ['c.id=?'];
    $p = [$id];
    customer_apply_station_scope($where, $p, 'c', $role, $station_id);
    $w = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT c.*,
               $customerIdExpr AS customer_id_display,
               $displayNameExpr AS name,
               $contactExpr AS contact_number,
               $typeExpr AS customer_type,
               $statusExpr AS status,
               $registeredExpr AS registered_at,
               $balanceExpr AS outstanding_balance,
               $creditLimitExpr AS credit_limit,
               $verificationExpr AS verification_status,
               $govIdTypeExpr AS gov_id_type,
               " . customer_company_expr($pdo, 'company_name', 'c') . " AS company_name,
               " . customer_company_expr($pdo, 'company_address', 'c') . " AS company_address,
               " . customer_company_expr($pdo, 'company_contact_person', 'c') . " AS company_contact_person,
               " . customer_company_expr($pdo, 'company_contact_number', 'c') . " AS company_contact_number,
               " . customer_verification_remarks_expr($pdo, 'c') . " AS verification_remarks,
               " . customer_user_name_expr('rb') . " AS registered_by_name,
               " . customer_user_name_expr('vb') . " AS verified_by_name
        FROM customers c
        LEFT JOIN users rb ON c.registered_by = rb.id
        LEFT JOIN users vb ON c.verified_by   = vb.id
        WHERE $w
    ");
    $stmt->execute($p);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception('Customer not found');
    unset($c['password']);
    $customerStation = customer_can_view_all_stations($role) ? (int)($c['station_id'] ?? 0) : $station_id;

    // Tx summary
    $mCnt=0; $mAmt=0.0; $jCnt=0; $jAmt=0.0; $fCnt=0; $fAmt=0.0; $last=null;
    foreach ([
        ['merchandise_transactions','transaction_date','total_amount',&$mCnt,&$mAmt],
        ['job_orders','created_at','total_cost',&$jCnt,&$jAmt],
        ['fuel_transactions','transaction_date','total_amount',&$fCnt,&$fAmt],
    ] as [$tbl,$dt,$amt,&$cnt,&$sum]) {
        try {
            $q=$pdo->prepare("SELECT COUNT(*),COALESCE(SUM($amt),0),MAX($dt) FROM $tbl WHERE customer_id=? AND station_id=?");
            $q->execute([$id,$customerStation]);
            [$n,$s,$ld]=$q->fetch(PDO::FETCH_NUM);
            $cnt=(int)$n; $sum=(float)$s;
            if ($ld) $last=$last?max($last,$ld):$ld;
        } catch (Exception $e) {}
    }

    $totalSpent=$mAmt+$jAmt+$fAmt;
    $ob=(float)$c['outstanding_balance'];
    $payStatus=$ob<=0?'Paid':($totalSpent>0&&$ob<$totalSpent?'Partial':'Unpaid');
    $availCredit=max(0,(float)$c['credit_limit']-$ob);
    $avg=$mCnt+$jCnt+$fCnt>0 ? round($totalSpent/($mCnt+$jCnt+$fCnt),2) : 0;

    echo json_encode([
        'success'  => true,
        'customer' => $c,
        'summary'  => [
            'merch_count'      => $mCnt, 'merch_amount'   => $mAmt,
            'jo_count'         => $jCnt, 'jo_amount'      => $jAmt,
            'fuel_count'       => $fCnt, 'fuel_amount'    => $fAmt,
            'total_count'      => $mCnt+$jCnt+$fCnt,
            'total_spent'      => $totalSpent,
            'last_transaction' => $last,
            'payment_status'   => $payStatus,
            'available_credit' => $availCredit,
            'avg_transaction'  => $avg,
        ]
    ]);
}

/* ─── TRANSACTION HISTORY ─────────────────────────────────────────── */
function txnHistory() {
    global $pdo, $station_id, $role;
    $id      = (int)($_GET['id']       ?? 0); if (!$id) throw new Exception('ID required');
    $search  = trim($_GET['search']    ?? '');
    $module  = trim($_GET['module']    ?? '');
    $payS    = trim($_GET['pay_status']?? '');
    $txnS    = trim($_GET['txn_status']?? '');
    $dFrom   = trim($_GET['date_from'] ?? '');
    $dTo     = trim($_GET['date_to']   ?? '');
    $limit   = max(10,min(100,(int)($_GET['limit']??10)));
    $page    = max(1,(int)($_GET['page']??1));
    $offset  = ($page-1)*$limit;

    $scopeWhere = ['c.id = ?'];
    $scopeParams = [$id];
    customer_apply_station_scope($scopeWhere, $scopeParams, 'c', $role, $station_id);
    $scopeStmt = $pdo->prepare("SELECT c.station_id FROM customers c WHERE " . implode(' AND ', $scopeWhere));
    $scopeStmt->execute($scopeParams);
    $customerStation = (int)$scopeStmt->fetchColumn();
    if ($customerStation <= 0) {
        throw new Exception('Customer not found');
    }

    $all = [];

    if ($module===''||$module==='Fuel') {
        $w=['ft.customer_id=?','ft.station_id=?']; $p=[$id,$customerStation];
        if ($search!=='') { $w[]="ft.transaction_id LIKE ?"; $p[]="%$search%"; }
        if ($txnS  !=='') { $w[]="ft.status=?"; $p[]=$txnS; }
        if ($dFrom !=='') { $w[]="DATE(ft.transaction_date)>=?"; $p[]=$dFrom; }
        if ($dTo   !=='') { $w[]="DATE(ft.transaction_date)<=?"; $p[]=$dTo; }
        try {
            $q=$pdo->prepare("SELECT ft.transaction_date AS txn_date, ft.transaction_id AS reference_no,
                'Fuel' AS module, CONCAT(ft.fuel_type,' — ',ft.liters_sold,'L') AS description,
                ft.total_amount AS amount, 'N/A' AS pay_status,
                COALESCE(ft.status,'Completed') AS txn_status,
                COALESCE(u.name,CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),'System') AS processed_by
                FROM fuel_transactions ft LEFT JOIN users u ON ft.staff_id=u.id
                WHERE ".implode(' AND ',$w));
            $q->execute($p);
            $all=array_merge($all,$q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    if ($module===''||$module==='Merchandise') {
        $w=['mt.customer_id=?','mt.station_id=?']; $p=[$id,$customerStation];
        if ($search!=='') { $w[]="mt.transaction_id LIKE ?"; $p[]="%$search%"; }
        if ($txnS  !=='') { $w[]="mt.validation_status=?"; $p[]=$txnS; }
        if ($dFrom !=='') { $w[]="DATE(mt.transaction_date)>=?"; $p[]=$dFrom; }
        if ($dTo   !=='') { $w[]="DATE(mt.transaction_date)<=?"; $p[]=$dTo; }
        try {
            $q=$pdo->prepare("SELECT mt.transaction_date AS txn_date, mt.transaction_id AS reference_no,
                'Merchandise' AS module, CONCAT('Merchandise Sale') AS description,
                mt.total_amount AS amount, COALESCE(mt.payment_status,'N/A') AS pay_status,
                COALESCE(mt.validation_status,'Completed') AS txn_status,
                COALESCE(u.name,CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),'System') AS processed_by
                FROM merchandise_transactions mt LEFT JOIN users u ON mt.staff_id=u.id
                WHERE ".implode(' AND ',$w));
            $q->execute($p);
            $all=array_merge($all,$q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    if ($module===''||$module==='Job Order') {
        $w=['jo.customer_id=?','jo.station_id=?']; $p=[$id,$customerStation];
        if ($search!=='') { $w[]="(COALESCE(jo.job_order_id,'') LIKE ? OR COALESCE(jo.job_order_number,'') LIKE ?)"; $p[]="%$search%"; $p[]="%$search%"; }
        if ($txnS  !=='') { $w[]="jo.status=?"; $p[]=$txnS; }
        if ($dFrom !=='') { $w[]="DATE(jo.created_at)>=?"; $p[]=$dFrom; }
        if ($dTo   !=='') { $w[]="DATE(jo.created_at)<=?"; $p[]=$dTo; }
        try {
            $q=$pdo->prepare("SELECT jo.created_at AS txn_date,
                COALESCE(jo.job_order_id,jo.job_order_number,CONCAT('JO-',jo.id)) AS reference_no,
                'Job Order' AS module, COALESCE(jo.service_type,'Auto Service') AS description,
                COALESCE(jo.total_cost,0) AS amount, COALESCE(jo.payment_status,'N/A') AS pay_status,
                COALESCE(jo.status,'Pending') AS txn_status,
                COALESCE(u.name,CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')),'System') AS processed_by
                FROM job_orders jo LEFT JOIN users u ON jo.created_by=u.id
                WHERE ".implode(' AND ',$w));
            $q->execute($p);
            $all=array_merge($all,$q->fetchAll(PDO::FETCH_ASSOC));
        } catch (Exception $e) {}
    }

    // Pay status filter
    if ($payS!=='') $all=array_filter($all,fn($t)=>strtolower((string)$t['pay_status']) === strtolower($payS));

    usort($all,fn($a,$b)=>strtotime($b['txn_date'])-strtotime($a['txn_date']));
    $total=count($all);
    $pages=max(1,(int)ceil($total/$limit));

    echo json_encode([
        'success'=>true,'history'=>array_values(array_slice($all,$offset,$limit)),
        'total'=>$total,'pages'=>$pages,'page'=>$page,'limit'=>$limit
    ]);
}

/* ─── ANALYTICS ───────────────────────────────────────────────────── */
function getAnalytics() {
    global $pdo, $station_id, $role;
    $scopeWhere = [];
    $sp = [];
    customer_apply_station_scope($scopeWhere, $sp, 'c', $role, $station_id);
    $sw = $scopeWhere ? 'AND ' . implode(' AND ', $scopeWhere) : '';
    $displayNameExpr = customer_display_name_expr($pdo, 'c');
    $typeExpr = customer_type_expr($pdo, 'c');
    $txnUnion = "
        SELECT customer_id, station_id, transaction_date AS txn_date, total_amount AS amount
        FROM merchandise_transactions
        UNION ALL
        SELECT customer_id, station_id, created_at AS txn_date, total_cost AS amount
        FROM job_orders
        UNION ALL
        SELECT customer_id, station_id, transaction_date AS txn_date, total_amount AS amount
        FROM fuel_transactions
    ";

    // Monthly registrations (last 12 months)
    $monthly = [];
    try {
        $q=$pdo->prepare("SELECT DATE_FORMAT(COALESCE(c.registered_at,c.created_at),'%Y-%m') AS mo,
            COUNT(*) AS cnt FROM customers c WHERE COALESCE(c.registered_at,c.created_at)>=DATE_SUB(NOW(),INTERVAL 12 MONTH) $sw
            GROUP BY mo ORDER BY mo ASC");
        $q->execute($sp); $monthly=$q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Type distribution
    $typeDist = [];
    try {
        $q=$pdo->prepare("SELECT $typeExpr AS ctype, COUNT(*) AS cnt
            FROM customers c WHERE 1=1 $sw GROUP BY ctype");
        $q->execute($sp); $typeDist=$q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Top 10 spenders
    $topSpenders = [];
    try {
        $q=$pdo->prepare("SELECT c.id, $displayNameExpr AS name,
            COALESCE(SUM(tx.amount),0) AS total_spent
            FROM customers c
            LEFT JOIN ($txnUnion) tx ON tx.customer_id = c.id AND tx.station_id = c.station_id
            WHERE 1=1 $sw
            GROUP BY c.id, name ORDER BY total_spent DESC LIMIT 10");
        $q->execute($sp); $topSpenders=$q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // Monthly spending (last 6 months)
    $monthlySpend = [];
    try {
        $q=$pdo->prepare("SELECT DATE_FORMAT(tx.txn_date,'%Y-%m') AS mo,
            COALESCE(SUM(tx.amount),0) AS tot
            FROM ($txnUnion) tx
            JOIN customers c ON c.id = tx.customer_id AND c.station_id = tx.station_id
            WHERE tx.txn_date>=DATE_SUB(NOW(),INTERVAL 6 MONTH) $sw
            GROUP BY mo ORDER BY mo ASC");
        $q->execute($sp); $monthlySpend=$q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $registeredSpend = 0.0;
    try {
        $q=$pdo->prepare("SELECT COALESCE(SUM(tx.amount),0) AS total
            FROM customers c
            JOIN ($txnUnion) tx ON tx.customer_id = c.id AND tx.station_id = c.station_id
            WHERE 1=1 $sw");
        $q->execute($sp);
        $registeredSpend = (float)$q->fetchColumn();
    } catch (Exception $e) {}

    // Summary KPIs
    $kpis = ['new_this_month'=>0,'inactive'=>0,'total_revenue'=>0,'avg_spend'=>0,'registered_spend'=>0];
    try {
        $q=$pdo->prepare("SELECT
            SUM(CASE WHEN DATE_FORMAT(COALESCE(c.registered_at,c.created_at),'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m') THEN 1 ELSE 0 END) AS new_month,
            SUM(CASE WHEN " . customer_status_expr($pdo, 'c') . "!='active' THEN 1 ELSE 0 END) AS inactive
            FROM customers c WHERE 1=1 $sw");
        $q->execute($sp); $r=$q->fetch(PDO::FETCH_ASSOC);
        $kpis['new_this_month']=(int)($r['new_month']??0);
        $kpis['inactive']=(int)($r['inactive']??0);
    } catch (Exception $e) {}
    $allSpend = array_sum(array_map(fn($row) => (float)($row['tot'] ?? 0), $monthlySpend));
    $kpis['total_revenue'] = $allSpend;
    $kpis['avg_spend'] = count($topSpenders) ? round(array_sum(array_map(fn($row) => (float)($row['total_spent'] ?? 0), $topSpenders)) / count($topSpenders), 2) : 0;
    $kpis['registered_spend'] = $registeredSpend;

    echo json_encode(['success'=>true,'monthly'=>$monthly,'type_dist'=>$typeDist,
        'top_spenders'=>$topSpenders,'monthly_spend'=>$monthlySpend,'kpis'=>$kpis]);
}

/* ─── STAFF LIST ──────────────────────────────────────────────────── */
function getStaffList() {
    global $pdo, $station_id, $role;
    $where = [];
    $sp = [];
    customer_apply_station_scope($where, $sp, 'c', $role, $station_id);
    $sw = $where ? 'AND ' . implode(' AND ', $where) : '';
    try {
        $q=$pdo->prepare("SELECT DISTINCT u.id,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS name
            FROM customers c JOIN users u ON c.registered_by=u.id WHERE 1=1 $sw ORDER BY name");
        $q->execute($sp);
        echo json_encode(['success'=>true,'list'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['success'=>true,'list'=>[]]); }
}

/* ─── MANAGER LIST ────────────────────────────────────────────────── */
function getManagerList() {
    global $pdo, $station_id, $role;
    $where = [];
    $sp = [];
    customer_apply_station_scope($where, $sp, 'c', $role, $station_id);
    $sw = $where ? 'AND ' . implode(' AND ', $where) : '';
    try {
        $q=$pdo->prepare("SELECT DISTINCT u.id,
            TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) AS name
            FROM customers c JOIN users u ON c.verified_by=u.id WHERE c.verified_by IS NOT NULL $sw ORDER BY name");
        $q->execute($sp);
        echo json_encode(['success'=>true,'list'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) { echo json_encode(['success'=>true,'list'=>[]]); }
}
