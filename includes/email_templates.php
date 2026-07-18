<?php
/**
 * Templates de correo con estética Adrenaline Gym
 * Uso: adrenaline_email($titulo, $subtitulo, $filas, $extra_html)
 */

function adrenaline_email($badge, $titulo, $subtitulo, $filas = [], $extra_html = '') {
    $rows_html = '';
    foreach ($filas as $label => $valor) {
        $rows_html .= '<tr style="border-bottom:1px solid #E5E7EB;">
            <td style="color:#6B7280;font-weight:600;padding:12px 0;width:40%;">' . htmlspecialchars($label) . '</td>
            <td style="color:#222;font-weight:600;padding:12px 0;">' . $valor . '</td>
        </tr>';
    }
    $tabla = $filas ? '<div style="background:#f8f9fa;border:1px solid #E5E7EB;padding:24px;border-radius:8px;margin:24px 0;">
        <table style="width:100%;border-collapse:collapse;">' . $rows_html . '</table></div>' : '';

    return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%"><tr><td align="center" style="padding:24px 12px;">
<div style="max-width:600px;width:100%;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
    <div style="background:#111111;padding:32px 30px;text-align:center;border-bottom:4px solid #e53935;">
        <img src="cid:gymlogo" alt="Adrenaline Gym" style="max-width:180px;height:auto;">
    </div>
    <div style="padding:32px 30px;">
        <div style="text-align:center;">
            <span style="background:#FEF2F2;color:#DC2626;padding:8px 16px;border-radius:20px;font-size:12px;font-weight:600;display:inline-block;margin-bottom:20px;">' . $badge . '</span>
        </div>
        <h1 style="color:#222;font-size:24px;font-weight:700;margin:0 0 16px;">' . $titulo . '</h1>
        <p style="color:#6B7280;font-size:16px;margin:0 0 8px;">' . $subtitulo . '</p>
        ' . $tabla . '
        ' . $extra_html . '
    </div>
    <div style="background:#f8f9fa;padding:24px 30px;text-align:center;color:#6B7280;font-size:12px;">
        <p style="margin:0;">Adrenaline Gym — Pasto, Nariño</p>
        <p style="margin:4px 0 0;font-size:11px;color:#9CA3AF;">Este es un correo automático, no es necesario responder.</p>
    </div>
</div>
</td></tr></table>
</body></html>';
}
