<?php
function enviar_whatsapp_chatwoot($celular, $mensaje) {
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }

    $token = $env['CHATWOOT_API_TOKEN'] ?? '';
    if (empty($token) || $token === 'PEGA_AQUI_TU_TOKEN') {
        return ['ok' => false, 'error' => 'CHATWOOT_API_TOKEN no configurado en .env'];
    }

    $celularClean = preg_replace('/\D/', '', (string) $celular);
    if (strlen($celularClean) === 10) { $celularClean = '57' . $celularClean; }

    $base = 'https://n8n-chatwoot.spqqf7.easypanel.host/api/v1/accounts/3';
    $logFile = '/app/iclock/chatwoot_whatsapp.log';

    $ch = curl_init("$base/contacts/search?q=" . urlencode($celularClean));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["api_access_token: $token"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $searchRaw = curl_exec($ch);
    curl_close($ch);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " SEARCH cel=$celularClean resp=" . substr((string)$searchRaw,0,300) . "\n", FILE_APPEND);
    $searchResult = json_decode($searchRaw, true);
    $contactId = $searchResult['payload'][0]['id'] ?? null;

    if (!$contactId) {
        return ['ok' => false, 'error' => 'Contacto no encontrado en Chatwoot (nunca ha escrito al bot)'];
    }

    $ch2 = curl_init("$base/contacts/$contactId/conversations");
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["api_access_token: $token"]);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 8);
    $convRaw = curl_exec($ch2);
    curl_close($ch2);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " CONV contact=$contactId resp=" . substr((string)$convRaw,0,300) . "\n", FILE_APPEND);
    $convResult = json_decode($convRaw, true);
    $conversationId = $convResult['payload'][0]['id'] ?? ($convResult[0]['id'] ?? null);

    if (!$conversationId) {
        return ['ok' => false, 'error' => 'Sin conversacion existente para ese contacto'];
    }

    $ch3 = curl_init("$base/conversations/$conversationId/messages");
    curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch3, CURLOPT_POST, true);
    curl_setopt($ch3, CURLOPT_HTTPHEADER, ["api_access_token: $token", "Content-Type: application/json"]);
    curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode(['content' => $mensaje, 'message_type' => 'outgoing']));
    curl_setopt($ch3, CURLOPT_TIMEOUT, 8);
    $sendRaw = curl_exec($ch3);
    curl_close($ch3);
    @file_put_contents($logFile, date('Y-m-d H:i:s') . " ENVIADO conv=$conversationId resp=" . substr((string)$sendRaw,0,300) . "\n", FILE_APPEND);

    return ['ok' => true];
}
