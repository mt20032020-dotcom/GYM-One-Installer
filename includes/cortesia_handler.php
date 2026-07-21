<?php
// Otorgar cortesia (plan gratis). Requiere: $conn, $useridgymuser, $translations ya definidos.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cortesia_ticket_id'])) {
    require_once '/app/includes/future_plans.php';
    $tid = (int) $_POST['cortesia_ticket_id'];
    $uidP = (int) $useridgymuser;
    $tk = $conn->query("SELECT name, expire_days, occasions FROM tickets WHERE id = " . $tid);
    $trow = $tk ? $tk->fetch_assoc() : null;

    if ($trow) {
        // Datos reales del cliente (consultados directo, sin depender del scope externo)
        $rU = $conn->query("SELECT firstname, lastname, email, cedula FROM users WHERE userid = " . $uidP);
        $uRow = $rU ? $rU->fetch_assoc() : [];
        $fullname = trim(($uRow['firstname'] ?? '') . ' ' . ($uRow['lastname'] ?? ''));
        $cedulaShow = $uRow['cedula'] ?? $uidP;

        // FIX bug 1: preservar NULL (plan por tiempo, sin limite de ingresos) en vez de convertirlo a 0
        $occ = ($trow['occasions'] === null) ? null : (int)$trow['occasions'];
        $res = add_plan($conn, $uidP, $trow['name'], (int)$trow['expire_days'], $occ, null);

        $seqR = $conn->query("SELECT COALESCE(MAX(id),0)+1 AS n FROM invoices")->fetch_assoc();
        $invNo = 'CORT-' . date('Y') . '-' . str_pad($seqR['n'], 5, '0', STR_PAD_LEFT);
        $descFact = 'CORTESIA - ' . $trow['name'];

        // ===== PDF real (mismo molde de las facturas pagadas) =====
        $pdfName = null;
        try {
            require_once '/app/vendor/autoload.php';
            require_once '/app/admin/boss/sell/payment/_invoice.php';

            $envI = [];
            foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $envI[$k]=$v; } }

            $items = "
                <table class='inv-table'>
                    <thead><tr>
                        <th class='inv-th' style='width:14%'>ID</th>
                        <th class='inv-th'>" . htmlspecialchars($translations['invoicedescription'] ?? 'Descripcion') . "</th>
                        <th class='inv-th inv-r' style='width:30%'>" . htmlspecialchars($translations['unitprice'] ?? 'Valor') . "</th>
                    </tr></thead>
                    <tbody>
                        <tr><td>" . (int)$tid . "</td><td>" . htmlspecialchars($descFact) . "</td><td class='inv-r'>0 COP</td></tr>
                        <tr class='inv-total-row'><td colspan='2' class='inv-r'>" . htmlspecialchars($translations['invoiceamount'] ?? 'Total') . "</td><td class='inv-r'>0 COP</td></tr>
                    </tbody>
                </table>";

            $html = gymone_invoice_shell([
                't' => $translations,
                'title' => $translations['invoice'] ?? 'Factura',
                'logoPath' => '/app/assets/img/brand/logo.png',
                'partnerLogoPath' => '/app/assets/img/logo.png',
                'year' => date('Y'),
                'businessName' => $envI['BUSINESS_NAME'] ?? '',
                'businessEmail' => $envI['MAIL_USERNAME'] ?? '',
                'businessPhone' => $envI['PHONE_NO'] ?? '',
                'date' => date('Y-m-d'),
                'invoiceNumber' => $invNo,
                'userid' => $cedulaShow,
                'clientName' => $fullname,
                'clientCity' => '',
                'clientAddress' => '',
                'clientEmail' => $uRow['email'] ?? '',
                'workerName' => 'Cortesia administrativa',
                'paymentType' => 'Cortesia (sin costo)',
            ], $items);

            $mpdf = new \Mpdf\Mpdf();
            $mpdf->WriteHTML($html);
            $pdfName = "{$uidP}-{$invNo}.pdf";
            $mpdf->Output("/app/assets/docs/invoices/{$pdfName}", \Mpdf\Output\Destination::FILE);
        } catch (\Throwable $ex) {
            @file_put_contents('/app/iclock/cortesia_pdf_error.log', date('Y-m-d H:i:s') . ' ' . $ex->getMessage() . "\n", FILE_APPEND);
            $pdfName = null;
        }

        // FIX bug 2: 'name' = nombre del cliente (igual que las facturas reales), NO la descripcion del plan
        $rutaF = $pdfName ?: ($invNo . '.txt');
        $st = $conn->prepare("INSERT INTO invoices (userid, name, price, status, route, created_at) VALUES (?, ?, 0, 'paid', ?, NOW())");
        $st->bind_param("iss", $uidP, $fullname, $rutaF);
        $st->execute(); $st->close();

        $accS = ($res['type'] === 'active') ? 'activada ahora' : ('encolada, inicia ' . ($res['start_date'] ?? '?'));
        $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES (" . $uidP . ", 'Cortesia otorgada: " . $conn->real_escape_string($trow['name']) . " (" . $accS . ")', 'success', NOW())");
        if ($res['type'] === 'active') {
            require_once '/app/iclock/lib/endtime.php';
            @sincronizar_acceso_speedface($uidP);
        }
        $GLOBALS['cortesia_ok'] = 'Cortesia "' . htmlspecialchars($trow['name']) . '" otorgada (' . $accS . ').' . ($pdfName ? '' : ' Aviso: la factura PDF no se genero, revisa cortesia_pdf_error.log');
    } else {
        $GLOBALS['cortesia_ok'] = 'ERROR: plan no encontrado.';
    }
}
$cortesia_tickets = [];
$__ct = $conn->query("SELECT id, name, expire_days, occasions FROM tickets WHERE visible = 1 ORDER BY id ASC");
if ($__ct) { while ($__r = $__ct->fetch_assoc()) { $cortesia_tickets[] = $__r; } }
