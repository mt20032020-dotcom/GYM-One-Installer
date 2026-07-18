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

$manana = date('Y-m-d', strtotime('+1 day'));
$stmtR = $connR->prepare("SELECT u.userid, u.firstname, u.email, c.ticketname, c.expiredate, c.opportunities
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
        '¡Hola, ' . htmlspecialchars($rowR['firstname']) . '!',
        'Te recordamos que tu plan vence mañana. ¡No pares tu entrenamiento!',
        $filasR,
        $extraR
    );
    @send_mail($envR, $rowR['email'], 'Tu plan vence mañana — Adrenaline Gym', $bodyR, $envR['BUSINESS_NAME'] ?? 'Adrenaline Gym', true);
    $enviadosR++;
    usleep(500000); // 0.5s entre correos para no saturar Gmail
}
$connR->close();
@file_put_contents('/app/iclock/recordatorios.log', date('Y-m-d H:i:s') . " Recordatorios enviados: $enviadosR\n", FILE_APPEND);
