<?php
/**
 * Recordatorios de vencimiento - se ejecuta desde el barrido nocturno
 * Avisa a los usuarios cuyo plan vence MAÑANA
 */
require_once '/app/includes/mailer.php';
require_once '/app/includes/email_templates.php';

$envR = [];
foreach (file('/app/.env') as $lR) {
    if (strpos($lR, '=') !== false) { [$kR, $vR] = explode('=', trim($lR), 2); $envR[$kR] = $vR; }
}
if (empty($envR['MAIL_HOST'])) return;

$connR = @new mysqli($envR['DB_SERVER'], $envR['DB_USERNAME'], $envR['DB_PASSWORD'], $envR['DB_NAME']);
if ($connR->connect_error) return;

require_once '/app/includes/chatwoot_plantilla.php';
$manana = date('Y-m-d', strtotime('+1 day'));
$stmtR = $connR->prepare("SELECT u.userid, u.firstname, u.lastname, u.email, u.celular, c.ticketname, c.expiredate, c.opportunities
    FROM current_tickets c
    JOIN users u ON u.userid = c.userid
    WHERE c.expiredate = ?");
$stmtR->bind_param("s", $manana);
$stmtR->execute();
$resR = $stmtR->get_result();

$enviadosR = 0;
while ($rowR = $resR->fetch_assoc()) {
    if (empty($rowR['email']) || strpos($rowR['email'], '@') === false || strpos($rowR['email'], 'sincorreo.local') !== false) continue;
    
    $filasR = [
        'Plan' => htmlspecialchars($rowR['ticketname']),
        'Vence' => date('d/m/Y', strtotime($rowR['expiredate'])),
    ];
    if ($rowR['opportunities'] !== null) $filasR['Tikets restantes'] = $rowR['opportunities'];
    
    $extraR = '<div style="text-align:center;margin-top:24px;">
        <span style="color:#6B7280;font-size:14px;">Renueva en recepción o desde nuestra web para no perder tu ritmo. 💪</span>
    </div>';
    
    $bodyR = adrenaline_email(
        '⏰ TU PLAN VENCE MAÑANA',
        '¡Hola, ' . htmlspecialchars($rowR['lastname']) . '!',
        'Te recordamos que tu plan vence mañana. ¡No pares tu entrenamiento!',
        $filasR,
        $extraR
    );
    @send_mail($envR, $rowR['email'], 'Tu plan vence mañana — Adrenaline Gym', $bodyR, $envR['BUSINESS_NAME'] ?? 'Adrenaline Gym', true);
    /* RECORDATORIO_WA: ademas del correo, avisar por WhatsApp via Chatwoot
       para que el equipo vea la conversacion si el socio responde. */
    $celR = preg_replace('/\D/','', (string)($rowR['celular'] ?? ''));
    if (preg_match('/^3\d{9}$/', $celR)) {
        $nomR   = trim($rowR['lastname']);
        $planR  = $rowR['ticketname'];
        $vencR  = date('d/m/Y', strtotime($rowR['expiredate']));
        $msgR   = "Hola $nomR, tu plan $planR vence el $vencR.";
        /* el boton lleva al checkout del mismo plan que vence */
        $tidR = null;
        $stT = $connR->prepare("SELECT id FROM tickets WHERE name = ? LIMIT 1");
        $stT->bind_param('s', $planR);
        $stT->execute();
        $rowT = $stT->get_result()->fetch_assoc();
        $stT->close();
        $tidR = $rowT['id'] ?? null;
        $btnR = (string)($tidR ?: 13);
        @enviar_plantilla_chatwoot($celR, trim($rowR['lastname'].' '.$rowR['firstname']), 'recordatorio_vencimiento',
            [$nomR, $planR, $vencR], $btnR, $msgR);
    }
    $enviadosR++;
    usleep(500000); // 0.5s entre correos para no saturar Gmail
}
$connR->close();
@file_put_contents('/app/iclock/recordatorios.log', date('Y-m-d H:i:s') . " Recordatorios enviados: $enviadosR\n", FILE_APPEND);
