<?php
/**
 * Librería de Congelamiento de Planes - Adrenaline Gym
 * Reglas:
 * - Solo admin congela
 * - Sin justificación médica: máximo 7 días
 * - Con incapacidad médica: sin límite
 * - 1 congelamiento por vigencia del plan
 * - Durante el congelamiento el torniquete rechaza
 * - Efecto: extiende expiredate del plan por los días congelados
 */

/**
 * Plan activo del usuario (cualquier tipo)
 */
function freeze_get_active_plan($db, $userid) {
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM current_tickets 
        WHERE userid = ? AND expiredate >= ?
        AND (opportunities IS NULL OR opportunities > 0)
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("is", $userid, $today);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * ¿Ya usó su congelamiento en este plan?
 */
function has_frozen_this_plan($db, $ticket_id) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM plan_freezes WHERE current_ticket_id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_row()[0] > 0;
}

/**
 * ¿Está congelado HOY el usuario?
 * Retorna el freeze activo o null
 */
function is_frozen_today($db, $userid) {
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM plan_freezes 
        WHERE userid = ? AND freeze_start <= ? AND freeze_end >= ?
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("iss", $userid, $today, $today);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Congelar el plan de un usuario.
 * Retorna true o mensaje de error.
 */
function freeze_plan($db, $userid, $freeze_start, $freeze_end, $reason, $has_medical, $admin_userid = null) {
    // Validaciones básicas
    if (empty($freeze_start) || empty($freeze_end)) return "Debes indicar fecha de inicio y fin.";
    if ($freeze_end < $freeze_start) return "La fecha fin no puede ser anterior a la de inicio.";
    if (empty(trim($reason))) return "Debes indicar el motivo del congelamiento.";
    
    $plan = freeze_get_active_plan($db, $userid);
    if (!$plan) return "El usuario no tiene un plan activo para congelar.";
    
    // 1 congelamiento por vigencia
    if (has_frozen_this_plan($db, $plan['id'])) {
        return "Este plan ya fue congelado una vez. Solo se permite 1 congelamiento por vigencia.";
    }
    
    // Calcular días (inclusive: del 1 al 5 = 5 días)
    $days = (strtotime($freeze_end) - strtotime($freeze_start)) / 86400 + 1;
    
    // Límite de 7 días sin justificación médica
    if (!$has_medical && $days > 7) {
        return "Sin justificación médica el máximo es 7 días. Solicitaste $days días.";
    }
    
    // El congelamiento no puede empezar después del vencimiento del plan
    if ($freeze_start > $plan['expiredate']) {
        return "El congelamiento no puede iniciar después del vencimiento del plan (" . $plan['expiredate'] . ").";
    }
    
    // Registrar el congelamiento
    $stmt = $db->prepare("INSERT INTO plan_freezes (userid, current_ticket_id, freeze_start, freeze_end, days_frozen, reason, has_medical, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $med = $has_medical ? 1 : 0;
    $stmt->bind_param("iississi", $userid, $plan['id'], $freeze_start, $freeze_end, $days, $reason, $med, $admin_userid);
    if (!$stmt->execute()) return "Error al registrar el congelamiento.";
    
    // Extender el vencimiento del plan
    $new_expire = date('Y-m-d', strtotime($plan['expiredate'] . " +$days days"));
    $stmt2 = $db->prepare("UPDATE current_tickets SET expiredate = ? WHERE id = ?");
    $stmt2->bind_param("si", $new_expire, $plan['id']);
    $stmt2->execute();
    
    // Log
    $db->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ($userid, 'Plan congelado del $freeze_start al $freeze_end ($days dias). Nuevo vencimiento: $new_expire', 'warning', NOW())");
    
    // Si el congelamiento incluye HOY, vetar de inmediato en el SpeedFace
    $today = date('Y-m-d');
    if ($freeze_start <= $today && $freeze_end >= $today) {
        require_once '/app/iclock/lib/endtime.php';
        @sincronizar_acceso_speedface($userid);
    }
    
    return true;
}

/**
 * Cancelar un congelamiento (revierte la extensión)
 * Solo si aún no terminó
 */
function unfreeze_plan($db, $freeze_id) {
    $stmt = $db->prepare("SELECT * FROM plan_freezes WHERE id = ?");
    $stmt->bind_param("i", $freeze_id);
    $stmt->execute();
    $freeze = $stmt->get_result()->fetch_assoc();
    if (!$freeze) return "Congelamiento no encontrado.";
    
    $today = date('Y-m-d');
    
    // Calcular días a revertir
    if ($freeze['freeze_end'] < $today) {
        return "Este congelamiento ya terminó, no se puede cancelar.";
    }
    
    // Si ya empezó, solo revertir los días que faltaban
    $effective_start = max($freeze['freeze_start'], $today);
    $days_to_revert = (strtotime($freeze['freeze_end']) - strtotime($effective_start)) / 86400 + 1;
    if ($freeze['freeze_start'] > $today) {
        // No ha empezado: revertir todo
        $days_to_revert = $freeze['days_frozen'];
    }
    
    // Acortar el vencimiento
    $stmt2 = $db->prepare("UPDATE current_tickets SET expiredate = DATE_SUB(expiredate, INTERVAL ? DAY) WHERE id = ?");
    $stmt2->bind_param("ii", $days_to_revert, $freeze['current_ticket_id']);
    $stmt2->execute();
    
    // Ajustar o eliminar el registro
    if ($freeze['freeze_start'] > $today) {
        // No empezó: eliminar completo
        $db->query("DELETE FROM plan_freezes WHERE id = " . intval($freeze_id));
    } else {
        // Ya empezó: cortar el fin a ayer
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $new_days = (strtotime($yesterday) - strtotime($freeze['freeze_start'])) / 86400 + 1;
        $stmt3 = $db->prepare("UPDATE plan_freezes SET freeze_end = ?, days_frozen = ? WHERE id = ?");
        $stmt3->bind_param("sii", $yesterday, $new_days, $freeze_id);
        $stmt3->execute();
    }
    
    return true;
}

/**
 * Historial de congelamientos de un usuario
 */
function get_freezes($db, $userid) {
    $stmt = $db->prepare("SELECT * FROM plan_freezes WHERE userid = ? ORDER BY id DESC");
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
