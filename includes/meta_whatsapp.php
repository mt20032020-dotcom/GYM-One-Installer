<?php
/**
 * Envia el codigo de verificacion via plantilla de Autenticacion de Meta Cloud API.
 * A diferencia del envio via Chatwoot, esto funciona con CUALQUIER numero,
 * incluso si esa persona nunca ha escrito al bot (contacto en frio, ej. desde un QR).
 */
function enviar_codigo_whatsapp_meta($celular, $codigo) {
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }

    $token = $env['WHATSAPP_ACCESS_TOKEN'] ?? '';
    $phoneId = $env['WHATSAPP_PHONE_NUMBER_ID'] ?? '';
    if (empty($token) || $token === 'PEGA_AQUI_TU_TOKEN' || empty($phoneId)) {
        return ['ok' => false, 'error' => 'WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_NUMBER_ID no configurados'];
    }

    $celularClean = preg_replace('/\D/', '', (string) $celular);
    if (strlen($celularClean) === 10) { $celularClean = '57' . $celularClean; }

    $logFile = '/app/iclock/meta_whatsapp.log';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $celularClean,
        'type' => 'template',
        'template' => [
            'name' => 'codigo_verificacion',
            'language' => ['code' => 'es_CO'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $codigo]
                    ]
                ],
                [
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => '0',
                    'parameters' => [
                        ['type' => 'text', 'text' => $codigo]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init("https://graph.facebook.com/v21.0/{$phoneId}/messages");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    @file_put_contents($logFile, date('Y-m-d H:i:s') . " cel=$celularClean http=$httpCode curlErr=$curlError payload=" . json_encode($payload) . " resp=" . $response . "\n\n", FILE_APPEND);

    $result = json_decode($response, true);
    if ($httpCode === 200 && isset($result['messages'])) {
        return ['ok' => true];
    }
    $errorMsg = $result['error']['message'] ?? ($curlError ?: "HTTP $httpCode sin detalle");
    return ['ok' => false, 'error' => $errorMsg];
}

/**
 * Envia CUALQUIER plantilla aprobada de WhatsApp via Meta Cloud API directo.
 * $bodyParams: array simple en el orden de las variables {{1}}, {{2}}, etc.
 * No depende de Chatwoot - funciona con cualquier numero, aunque nunca haya escrito al bot.
 */
function enviar_plantilla_whatsapp_meta($celular, $templateName, $languageCode, $bodyParams = [], $headerParams = []) {
    $env = [];
    foreach (file('/app/.env') as $l) { if (strpos($l,'=')!==false) { [$k,$v]=explode('=',trim($l),2); $env[$k]=$v; } }

    $token = $env['WHATSAPP_ACCESS_TOKEN'] ?? '';
    $phoneId = $env['WHATSAPP_PHONE_NUMBER_ID'] ?? '';
    if (empty($token) || empty($phoneId)) {
        return ['ok' => false, 'error' => 'WHATSAPP_ACCESS_TOKEN o WHATSAPP_PHONE_NUMBER_ID no configurados'];
    }

    $celularClean = preg_replace('/\D/', '', (string) $celular);
    if (strlen($celularClean) === 10) { $celularClean = '57' . $celularClean; }

    $components = [];
    if (!empty($headerParams)) {
        $components[] = [
            'type' => 'header',
            'parameters' => array_map(function($p) { return ['type' => 'text', 'text' => (string) $p]; }, $headerParams)
        ];
    }
    if (!empty($bodyParams)) {
        $components[] = [
            'type' => 'body',
            'parameters' => array_map(function($p) { return ['type' => 'text', 'text' => (string) $p]; }, $bodyParams)
        ];
    }

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $celularClean,
        'type' => 'template',
        'template' => [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
            'components' => $components
        ]
    ];

    $logFile = '/app/iclock/meta_whatsapp.log';
    $ch = curl_init("https://graph.facebook.com/v21.0/{$phoneId}/messages");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    @file_put_contents($logFile, date('Y-m-d H:i:s') . " PLANTILLA=$templateName cel=$celularClean http=$httpCode curlErr=$curlError payload=" . json_encode($payload) . " resp=" . $response . "\n\n", FILE_APPEND);

    $result = json_decode($response, true);
    if ($httpCode === 200 && isset($result['messages'])) {
        return ['ok' => true];
    }
    $errorMsg = $result['error']['message'] ?? ($curlError ?: "HTTP $httpCode sin detalle");
    return ['ok' => false, 'error' => $errorMsg];
}
