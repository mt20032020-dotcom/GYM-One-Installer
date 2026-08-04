<?php
/**
 * Envia una plantilla de WhatsApp a traves de Chatwoot.
 * Crea contacto y conversacion si no existen, para que el equipo
 * vea la conversacion completa cuando el socio responda.
 */
function enviar_plantilla_chatwoot($celular, $nombre, $plantilla, $params = [], $btnParam = null, $textoVisible = '') {
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }
    $token = $env['CHATWOOT_API_TOKEN'] ?? '';
    if (empty($token)) return ['ok'=>false,'error'=>'sin token'];

    $base  = 'https://n8n-chatwoot.spqqf7.easypanel.host/api/v1/accounts/3';
    $inbox = 10;
    $log   = '/app/iclock/chatwoot_whatsapp.log';
    $cel = preg_replace('/\D/','',(string)$celular);
    if (strlen($cel) === 10) $cel = '57'.$cel;

    $call = function($url, $method='GET', $body=null) use ($token) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $h = ["api_access_token: $token"];
        if ($body !== null) { $h[]="Content-Type: application/json"; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        if ($method!=='GET') curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$c, json_decode($r,true), $r];
    };

    [$c1,$r1] = $call("$base/contacts/search?q=".urlencode($cel));
    $cid = $r1['payload'][0]['id'] ?? null;
    if (!$cid) {
        [$c2,$r2] = $call("$base/contacts", 'POST',
            ['inbox_id'=>$inbox, 'name'=>($nombre !== '' ? $nombre : $cel), 'phone_number'=>'+'.$cel]);
        $cid = $r2['payload']['contact']['id'] ?? ($r2['payload']['id'] ?? null);
    }
    if (!$cid) return ['ok'=>false,'error'=>'sin contacto'];

    [$c3,$r3] = $call("$base/contacts/$cid/conversations");
    $conv = $r3['payload'][0]['id'] ?? null;
    if (!$conv) {
        [$cS,$rS] = $call("$base/contacts/$cid/contactable_inboxes");
        $src = null;
        foreach (($rS['payload'] ?? []) as $ci)
            if (($ci['inbox']['id'] ?? null) == $inbox) { $src = $ci['source_id'] ?? null; break; }
        $b = ['inbox_id'=>$inbox,'contact_id'=>$cid,'status'=>'open'];
        if ($src) $b['source_id'] = $src;
        [$c4,$r4] = $call("$base/conversations", 'POST', $b);
        $conv = $r4['id'] ?? ($r4['payload']['id'] ?? null);
    }
    if (!$conv) return ['ok'=>false,'error'=>'sin conversacion'];

    /* Chatwoot espera el cuerpo y los botones en claves separadas.
       Mandarlos todos juntos hace que Meta rechace por numero de parametros. */
    $body = [];
    foreach ($params as $i => $p) $body[(string)($i+1)] = (string)$p;
    $proc = ['body' => $body];
    if ($btnParam !== null) {
        $proc['buttons'] = [['type' => 'url', 'parameter' => (string)$btnParam]];
    }

    [$c5,$r5,$raw5] = $call("$base/conversations/$conv/messages", 'POST', [
        'content' => $textoVisible !== '' ? $textoVisible : implode(' ', $params),
        'message_type' => 'outgoing',
        'template_params' => [
            'name'=>$plantilla, 'category'=>'UTILITY', 'language'=>'es_CO',
            'processed_params'=>$proc
        ]
    ]);
    @file_put_contents($log, date('Y-m-d H:i:s')." PLANTILLA=$plantilla cel=$cel conv=$conv http=$c5 ".substr((string)$raw5,0,180)."\n", FILE_APPEND);
    return ($c5>=200 && $c5<300) ? ['ok'=>true,'conversation_id'=>$conv] : ['ok'=>false,'error'=>"HTTP $c5"];
}
