<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
$env=[];foreach(preg_split("/\r\n|\n|\r/",(string)@file_get_contents('/app/.env')) as $l){ if(trim($l)===''||strpos(ltrim($l),'#')===0) continue; $p=explode('=',$l,2); if(count($p)===2) $env[trim($p[0])]=trim($p[1]); }
$conn=new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
if($conn->connect_error){ echo json_encode(['ok'=>false,'error'=>'Error de conexion']); exit; }
$conn->set_charset('utf8mb4');
if(empty($_SESSION['userid'])){ echo json_encode(['ok'=>false,'error'=>'Sesion expirada']); exit; }
$uid=(int)($_POST['userid'] ?? 0);
$n=max(1,min(20,(int)($_POST['extras'] ?? 0)));
if($uid<=0){ echo json_encode(['ok'=>false,'error'=>'Usuario invalido']); exit; }
$st=$conn->prepare("SELECT firstname,lastname FROM users WHERE userid=?");
$st->bind_param('i',$uid); $st->execute(); $u=$st->get_result()->fetch_assoc(); $st->close();
if(!$u){ echo json_encode(['ok'=>false,'error'=>'Usuario no encontrado']); exit; }
$st=$conn->prepare("SELECT id,opportunities FROM current_tickets WHERE userid=? AND opportunities IS NOT NULL AND opportunities>0 AND expiredate>=CURDATE() ORDER BY expiredate ASC LIMIT 1");
$st->bind_param('i',$uid); $st->execute(); $t=$st->get_result()->fetch_assoc(); $st->close();
if(!$t){ echo json_encode(['ok'=>false,'error'=>'No tiene tiquetera vigente con saldo']); exit; }
if((int)$t['opportunities'] < $n){ echo json_encode(['ok'=>false,'error'=>'Saldo insuficiente: quedan '.(int)$t['opportunities']]); exit; }
$conn->begin_transaction();
try{
  $nuevo=(int)$t['opportunities']-$n;
  $st=$conn->prepare("UPDATE current_tickets SET opportunities=? WHERE id=?");
  $st->bind_param('ii',$nuevo,$t['id']); $st->execute(); $st->close();
  $nom=trim($u['lastname'].' '.$u['firstname']);
  for($i=1;$i<=$n;$i++){
    $cn='Invitado de '.$u['lastname'].' ('.$i.')';
    $st=$conn->prepare("INSERT INTO access_log (userid, display_name, is_companion) VALUES (?, ?, 1)");
    $st->bind_param('is',$uid,$cn); $st->execute(); $st->close();
  }
  $conn->query("INSERT INTO logs (userid,action,actioncolor,time) VALUES ($uid,'Ingreso extra: $n invitado(s) descontados de la tiquetera','info',NOW())");
  $conn->commit();
  if($nuevo<=0){ require_once '/app/iclock/lib/endtime.php'; @sincronizar_acceso_speedface($uid); }
  echo json_encode(['ok'=>true,'restantes'=>$nuevo,'nombre'=>$nom,'extras'=>$n]);
}catch(Exception $e){ $conn->rollback(); echo json_encode(['ok'=>false,'error'=>'Error al registrar']); }
