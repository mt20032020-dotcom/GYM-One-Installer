<?php
session_start();
date_default_timezone_set('America/Bogota');
if (!isset($_SESSION['adminuser'])) { header("Location: ../../../dashboard"); exit(); }
$adminid = $_SESSION['adminuser'];

$QUICK_TICKET_ID = 10;   // Sesion
$CF_USERID       = 222222222222;

function read_env_file($p){ $d=[]; foreach(preg_split("/\r\n|\n|\r/", (string)@file_get_contents($p)) as $l){ if(trim($l)===''||strpos(ltrim($l),'#')===0) continue; $x=explode('=',$l,2); if(count($x)==2) $d[trim($x[0])]=trim($x[1]); } return $d; }
$env = read_env_file('/app/.env');
$conn = new mysqli($env['DB_SERVER'],$env['DB_USERNAME'],$env['DB_PASSWORD'],$env['DB_NAME']);
if ($conn->connect_error) { die("Error de conexion"); }
$conn->set_charset('utf8mb4');

$lang = $env['LANG_CODE'] ?? 'ES';
$translations = json_decode(@file_get_contents("/app/assets/lang/{$lang}.json"), true) ?: [];
$currency = $env['CURRENCY'] ?? 'COP';
$business_name = $env['BUSINESS_NAME'] ?? '';

$t = $conn->query("SELECT id,name,price,expire_days,occasions FROM tickets WHERE id=".(int)$QUICK_TICKET_ID);
$ticket = $t ? $t->fetch_assoc() : null;
if (!$ticket) { die("No existe el ticket id {$QUICK_TICKET_ID}"); }

$msg = ''; $ok = false; $doneInvoice = ''; $donePdf = ''; $doorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pm'])) {
    $pm = $_POST['pm'];
    $valid = ['cash','card','transfer','mixed'];
    $price = (float)$ticket['price'];
    $mxCash = max(0,(float)($_POST['mix_cash'] ?? 0));
    $mxCard = max(0,(float)($_POST['mix_card'] ?? 0));
    $mxTr   = max(0,(float)($_POST['mix_transfer'] ?? 0));
    if (!in_array($pm, $valid, true)) { $msg = 'Metodo de pago invalido'; }
    elseif ($pm === 'mixed' && abs(($mxCash+$mxCard+$mxTr) - $price) > 0.01) { $msg = 'El pago mixto no cuadra con el total'; }
    else {
        if ($pm === 'mixed') { $addCash=$mxCash; $addCard=$mxCard; $addTr=$mxTr; }
        else {
            $addCash = ($pm==='cash')?$price:0;
            $addCard = ($pm==='card')?$price:0;
            $addTr   = ($pm==='transfer')?$price:0;
        }
        $conn->begin_transaction();
        try {
            $r = $conn->query("SELECT id FROM revenu_stats WHERE date = CURDATE()");
            if ($r && $r->num_rows) {
                $row = $r->fetch_assoc();
                $s = $conn->prepare("UPDATE revenu_stats SET cash = cash + ?, bank_card = bank_card + ?, transfer = transfer + ? WHERE id = ?");
                $s->bind_param("dddi", $addCash, $addCard, $addTr, $row['id']); $s->execute(); $s->close();
            } else {
                $s = $conn->prepare("INSERT INTO revenu_stats (date,bank_card,cash,transfer,web) VALUES (CURDATE(),?,?,?,0)");
                $s->bind_param("ddd", $addCard, $addCash, $addTr); $s->execute(); $s->close();
            }

            $seq = $conn->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM invoices")->fetch_assoc();
            $invoiceNumber = 'ADR-'.date('Y').'-'.str_pad($seq['n'],5,'0',STR_PAD_LEFT);

            require_once '/app/vendor/autoload.php';
            require_once __DIR__ . '/../payment/_invoice.php';
            $wn = 'Administrador';
            $wq = $conn->query("SELECT firstname,lastname FROM workers WHERE userid=".(int)$adminid);
            if ($wq && $wq->num_rows) { $w=$wq->fetch_assoc(); $wn=trim($w['firstname'].' '.$w['lastname']); }
            else { $wq=$conn->query("SELECT firstname,lastname FROM users WHERE userid=".(int)$adminid); if($wq && $wq->num_rows){$w=$wq->fetch_assoc(); $wn=trim($w['firstname'].' '.$w['lastname']);} }
            if ($pm === 'mixed') { $pmLabel = 'Pago mixto (Efectivo $'.number_format($addCash,0,',','.').' + Tarjeta $'.number_format($addCard,0,',','.').' + Transf. $'.number_format($addTr,0,',','.').')'; }
            else { $pmLabel = ($pm=='cash') ? ($translations['cash'] ?? 'Efectivo') : (($pm=='transfer') ? 'Transferencia' : ($translations['card'] ?? 'Tarjeta')); }

            $inv_items = "<table class='inv-table'><thead><tr>
                <th class='inv-th' style='width:14%'>ID</th>
                <th class='inv-th'>".htmlspecialchars($translations['invoicedescription'] ?? 'Descripcion')."</th>
                <th class='inv-th inv-r' style='width:30%'>".htmlspecialchars($translations['unitprice'] ?? 'Valor')."</th>
                </tr></thead><tbody><tr>
                <td>".(int)$ticket['id']."</td><td>".htmlspecialchars($ticket['name'])."</td>
                <td class='inv-r'>".number_format($price,0,',','.')." ".$currency."</td></tr>
                <tr class='inv-total-row'><td colspan='2' class='inv-r'>".htmlspecialchars($translations['invoiceamount'] ?? 'Total')."</td>
                <td class='inv-r'>".number_format($price,0,',','.')." ".$currency."</td></tr></tbody></table>";

            $invoiceHtml = gymone_invoice_shell([
                't'=>$translations,'title'=>$translations['invoice'] ?? 'Factura',
                'logoPath'=>'/app/assets/img/brand/logo.png','partnerLogoPath'=>'/app/assets/img/logo.png',
                'year'=>date('Y'),'businessName'=>$business_name,'businessEmail'=>$env['MAIL_USERNAME'] ?? '',
                'businessPhone'=>$env['PHONE_NO'] ?? '','date'=>date('Y-m-d'),'invoiceNumber'=>$invoiceNumber,
                'userid'=>'222222222222','clientName'=>'Consumidor Final','clientCity'=>'','clientAddress'=>'',
                'clientEmail'=>'','workerName'=>$wn,'paymentType'=>$pmLabel,
            ], $inv_items);

            $mpdf = new \Mpdf\Mpdf();
            $mpdf->WriteHTML($invoiceHtml);
            $pdfName = "{$CF_USERID}-{$invoiceNumber}.pdf";
            $mpdf->Output("/app/assets/docs/invoices/{$pdfName}", \Mpdf\Output\Destination::FILE);

            $nm='Consumidor Final'; $st='paid';
            $s=$conn->prepare("INSERT INTO invoices (userid,name,price,status,route,created_at) VALUES (?,?,?,?,?,NOW())");
            $s->bind_param("isdss",$CF_USERID,$nm,$price,$st,$pdfName); $s->execute(); $s->close();

            $s=$conn->prepare("INSERT INTO access_log (userid,display_name,is_companion,entry_time) VALUES (?,?,0,NOW())");
            $s->bind_param("is",$CF_USERID,$nm); $s->execute(); $s->close();

            $act="Venta rapida sesion - {$pmLabel} - {$pdfName}"; $cl='success';
            $s=$conn->prepare("INSERT INTO logs (userid,action,actioncolor,time) VALUES (?,?,?,NOW())");
            $s->bind_param("iss",$adminid,$act,$cl); $s->execute(); $s->close();

            $conn->commit();
            $ok=true; $doneInvoice=$invoiceNumber; $donePdf=$pdfName;

            $cmdFile='/app/iclock/cmd_queue.txt'; $id=(string)random_int(100000,999999);
            $fh=@fopen($cmdFile,'a');
            if($fh){ flock($fh,LOCK_EX); fwrite($fh,"C:{$id}:CONTROL DEVICE 01010105\n"); flock($fh,LOCK_UN); fclose($fh); @chmod($cmdFile,0666);
                @file_put_contents('/app/iclock/puerta_log.txt',date('Y-m-d H:i:s')." venta rapida sesion admin={$adminid} {$invoiceNumber}\n",FILE_APPEND);
                $doorMsg='Torniquete abierto';
            } else { $doorMsg='VENTA OK pero no se pudo abrir el torniquete - abrelo manualmente'; }
        } catch (Throwable $e) {
            $conn->rollback();
            $msg = 'Error: '.$e->getMessage().' (no se registro nada, no se abrio la puerta)';
        }
    }
}
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>$ok,'invoice'=>$doneInvoice,'pdf'=>$donePdf,'door'=>$doorMsg,'msg'=>$msg]);
    exit();
}
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Venta rapida - Sesion</title>
<style>
body{font-family:system-ui,sans-serif;background:#f4f5f7;margin:0;padding:24px;}
.card{max-width:520px;margin:0 auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);}
h1{margin:0 0 4px;font-size:22px;color:#222}
.sub{color:#666;font-size:14px;margin-bottom:20px}
.price{font-size:34px;font-weight:700;color:#e53935;margin:10px 0 24px}
button{width:100%;padding:16px;font-size:17px;font-weight:600;border:0;border-radius:10px;color:#fff;margin-bottom:12px;cursor:pointer}
.cash{background:#2e7d32}.card2{background:#1565c0}.tr{background:#6a1b9a}
.ok{background:#e8f5e9;border-left:4px solid #2e7d32;padding:14px;border-radius:8px;margin-bottom:18px}
.err{background:#ffebee;border-left:4px solid #c62828;padding:14px;border-radius:8px;margin-bottom:18px}
a.back{display:block;text-align:center;margin-top:18px;color:#555;font-size:14px}
</style></head><body><div class="card">
<?php if($ok): ?>
  <div class="ok"><b>Venta registrada</b><br>Factura <?= htmlspecialchars($doneInvoice) ?><br><?= htmlspecialchars($doorMsg) ?><br>
  <a href="/assets/docs/invoices/<?= urlencode($donePdf) ?>" target="_blank">Ver factura PDF</a></div>
<?php elseif($msg): ?>
  <div class="err"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<h1>Venta rapida</h1>
<div class="sub"><?= htmlspecialchars($ticket['name']) ?> &middot; Consumidor Final &middot; abre torniquete</div>
<div class="price"><?= number_format((float)$ticket['price'],0,',','.') ?> <?= htmlspecialchars($currency) ?></div>
<form method="post" onsubmit="var f=this;setTimeout(function(){f.querySelectorAll('button').forEach(function(b){b.disabled=true;});},10);">
  <button class="cash" name="pm" value="cash">Efectivo</button>
  <button class="card2" name="pm" value="card">Tarjeta</button>
  <button class="tr" name="pm" value="transfer">Transferencia / QR</button>
</form>
<a class="back" href="/admin/boss/sell/">&larr; Volver a Venta</a>
</div></body></html>
