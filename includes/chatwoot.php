<?php
/**
 * Registra un mensaje saliente en Chatwoot.
 * Crea el contacto y la conversacion si no existen, para que las plantillas
 * enviadas por la API de Meta queden visibles para el equipo.
 *
 * @param string $celular  numero del socio (10 digitos o con indicativo)
 * @param string $mensaje  texto a registrar
 * @param string $nombre   opcional, para crear el contacto con nombre
 */
function enviar_whatsapp_chatwoot($celular, $mensaje, $nombre = '') {
    $env = [];
    foreach (file('/app/.env') as $l) {
        if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; }
    }
    $token = $env['CHATWOOT_API_TOKEN'] ?? '';
    if (empty($token)) return ['ok'=>false,'error'=>'CHATWOOT_API_TOKEN no configurado'];

    $base    = 'https://n8n-chatwoot.spqqf7.easypanel.host/api/v1/accounts/3';
    $inboxId = 10;
    $log     = '/app/iclock/chatwoot_whatsapp.log';

    $cel = preg_replace('/\D/','',(string)$celular);
    if (strlen($cel) === 10) $cel = '57'.$cel;
    $e164 = '+'.$cel;

    $call = function($url, $method = 'GET', $body = null) use ($token) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $h = ["api_access_token: $token"];
        if ($body !== null) {
            $h[] = "Content-Type: application/json";
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        if ($method !== 'GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, json_decode($r, true), $r];
    };

    // 1. buscar contacto
    [$c1, $r1] = $call("$base/contacts/search?q=".urlencode($cel));
    $contactId = $r1['payload'][0]['id'] ?? null;

    // 2. crear si no existe
    if (!$contactId) {
        [$c2, $r2, $raw2] = $call("$base/contacts", 'POST', [
            'inbox_id'     => $inboxId,
            'name'         => $nombre !== '' ? $nombre : $cel,
            'phone_number' => $e164,
        ]);
        $contactId = $r2['payload']['contact']['id'] ?? ($r2['payload']['id'] ?? null);
        @file_put_contents($log, date('Y-m-d H:i:s')." CREA-CONTACTO cel=$cel http=$c2 id=".var_export($contactId,true)." ".substr((string)$raw2,0,200)."\n", FILE_APPEND);
        if (!$contactId) return ['ok'=>false,'error'=>'No pude crear el contacto'];
    }

    // 3. buscar conversacion
    [$c3, $r3] = $call("$base/contacts/$contactId/conversations");
    $convId = $r3['payload'][0]['id'] ?? null;

    // 4. crear si no hay
    if (!$convId) {
        // source_id es el identificador del contacto en el inbox
        [$cS, $rS] = $call("$base/contacts/$contactId/contactable_inboxes");
        $sourceId = null;
        foreach (($rS['payload'] ?? []) as $ci) {
            if (($ci['inbox']['id'] ?? null) == $inboxId) { $sourceId = $ci['source_id'] ?? null; break; }
        }
        $body = ['inbox_id'=>$inboxId, 'contact_id'=>$contactId, 'status'=>'open'];
        if ($sourceId) $body['source_id'] = $sourceId;
        [$c4, $r4, $raw4] = $call("$base/conversations", 'POST', $body);
        $convId = $r4['id'] ?? ($r4['payload']['id'] ?? null);
        @file_put_contents($log, date('Y-m-d H:i:s')." CREA-CONV contact=$contactId http=$c4 id=".var_export($convId,true)." ".substr((string)$raw4,0,200)."\n", FILE_APPEND);
        if (!$convId) return ['ok'=>false,'error'=>'No pude crear la conversacion'];
    }

    // 5. registrar el mensaje
    [$c5, $r5, $raw5] = $call("$base/conversations/$convId/messages", 'POST', [
        'content'      => $mensaje,
        'message_type' => 'outgoing',
        'private'      => false,
    ]);
    @file_put_contents($log, date('Y-m-d H:i:s')." MSG conv=$convId http=$c5 ".substr((string)$raw5,0,200)."\n", FILE_APPEND);
    return ($c5 >= 200 && $c5 < 300)
        ? ['ok'=>true,'conversation_id'=>$convId,'contact_id'=>$contactId]
        : ['ok'=>false,'error'=>"HTTP $c5"];
}
