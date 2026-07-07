<?php
require_once __DIR__ . '/lib.php';
require_login();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$staff = read_json('staff.json', []);
$sessions = read_json('labor_sessions.json', []); // active
$logs = read_json('labor_logs.json', []); // history

function now_iso(){ return date('c'); }
function time_hm($ts){ return date('H:i', strtotime($ts)); }
function date_ymd($ts){ return date('Y-m-d', strtotime($ts)); }

if($method === 'GET'){
  $view = $_GET['view'] ?? 'all';
  if($view === 'summary'){
    $today = today_key();
    $todayLogs = array_values(array_filter($logs, fn($l)=>($l['date']??'') === $today));
    $hours = 0.0; $charges = 0.0;
    foreach($todayLogs as $l){ $hours += (float)($l['hours'] ?? 0); $charges += (float)($l['charge'] ?? 0); }
    json_response(['ok'=>true, 'data'=>[
      'active_now'=>count($sessions),
      'hours_today'=>round($hours,1),
      'logs_today'=>count($todayLogs),
      'charges_today'=>$charges,
      'sessions'=>$sessions,
      'logs'=>$logs,
      'staff'=>$staff
    ]]);
  }
  json_response(['ok'=>true, 'data'=>['sessions'=>$sessions,'logs'=>$logs,'staff'=>$staff]]);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if(!is_array($body)) $body = [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

if($method === 'POST'){
  if($action === 'staff_save'){
    $id = $body['id'] ?? '';
    $item = [
      'id' => $id ?: uniqid('EMP_'),
      'emp_id' => trim((string)($body['emp_id'] ?? '')),
      'name' => trim((string)($body['name'] ?? '')),
      'role' => strtolower(trim((string)($body['role'] ?? 'staff'))),
      'rate' => (float)($body['rate'] ?? 0),
      'phone' => trim((string)($body['phone'] ?? '')),
      'email' => trim((string)($body['email'] ?? '')),
      'status' => ($body['status'] ?? 'active') ?: 'active',
    ];
    if($item['emp_id'] === '' || $item['name'] === ''){
      json_response(['ok'=>false,'error'=>'Employee ID and name are required'], 400);
    }
    // normalize role
    $allowed = ['cashier','mechanic','attendant','manager'];
    if(!in_array($item['role'], $allowed)) $item['role'] = 'staff';

    $found=false;
    foreach($staff as $i=>$s){
      if(($s['id']??'') === $item['id']){
        $staff[$i] = array_merge($s, $item);
        $found=true;
        break;
      }
    }
    if(!$found) $staff[] = $item;
    write_json('staff.json', $staff);
    json_response(['ok'=>true, 'data'=>['staff'=>$item]]);
  }

  if($action === 'staff_delete'){
    $id = $body['id'] ?? '';
    if(!$id) json_response(['ok'=>false,'error'=>'Missing id'], 400);
    $staff = array_values(array_filter($staff, fn($s)=>($s['id']??'') !== $id));
    // also end any active sessions for that staff
    $sessions = array_values(array_filter($sessions, fn($ss)=>($ss['staff_id']??'') !== $id));
    write_json('staff.json', $staff);
    write_json('labor_sessions.json', $sessions);
    json_response(['ok'=>true]);
  }

  if($action === 'clock_in'){
    $staff_id = $body['staff_id'] ?? '';
    if(!$staff_id) json_response(['ok'=>false,'error'=>'Select a staff member'], 400);
    // prevent duplicate active session
    foreach($sessions as $ss){
      if(($ss['staff_id']??'') === $staff_id){
        json_response(['ok'=>false,'error'=>'Staff already has an active session'], 400);
      }
    }
    $staffRec = null;
    foreach($staff as $s){ if(($s['id']??'') === $staff_id){ $staffRec=$s; break; } }
    if(!$staffRec) json_response(['ok'=>false,'error'=>'Staff not found'], 404);

    $session = [
      'id' => uniqid('S_'),
      'staff_id' => $staff_id,
      'start' => now_iso(),
    ];
    $sessions[] = $session;
    write_json('labor_sessions.json', $sessions);
    json_response(['ok'=>true, 'data'=>['session'=>$session]]);
  }

  if($action === 'clock_out'){
    $session_id = $body['session_id'] ?? '';
    if(!$session_id) json_response(['ok'=>false,'error'=>'Missing session'], 400);

    $session = null;
    $newSessions = [];
    foreach($sessions as $ss){
      if(($ss['id']??'') === $session_id) $session = $ss;
      else $newSessions[] = $ss;
    }
    if(!$session) json_response(['ok'=>false,'error'=>'Session not found'], 404);

    $end = now_iso();
    $startTs = strtotime($session['start']);
    $endTs = strtotime($end);
    $hours = max(0, ($endTs - $startTs) / 3600.0);

    $staffRec = null;
    foreach($staff as $s){ if(($s['id']??'') === ($session['staff_id']??'')){ $staffRec=$s; break; } }
    $rate = (float)($staffRec['rate'] ?? 0);
    $charge = $hours * $rate;

    $log = [
      'id' => uniqid('L_'),
      'staff_id' => $session['staff_id'],
      'staff_name' => $staffRec['name'] ?? 'Staff',
      'date' => date_ymd($session['start']),
      'time_in' => time_hm($session['start']),
      'time_out' => time_hm($end),
      'hours' => round($hours,1),
      'charge' => round($charge,2),
      'status' => 'completed'
    ];
    $logs[] = $log;

    $sessions = $newSessions;
    write_json('labor_sessions.json', $sessions);
    write_json('labor_logs.json', $logs);
    json_response(['ok'=>true, 'data'=>['log'=>$log]]);
  }

  json_response(['ok'=>false,'error'=>'Unknown action'], 400);
}

json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
?>