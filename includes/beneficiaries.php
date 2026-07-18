<?php
/**
 * Librería de Beneficiarios de Tiqueteras - Adrenaline Gym
 * Reglas:
 * - Solo titulares con tiquetera activa (plan con ocasiones) pueden tener beneficiarios
 * - Máximo 2 beneficiarios
 * - 1 cambio (reemplazo) por vigencia del plan
 * - 1 tiket máximo por persona por día
 */

/**
 * Obtiene la tiquetera activa del titular (plan con ocasiones > 0 y vigente)
 */
function get_active_tiquetera($db, $userid) {
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM current_tickets 
        WHERE userid = ? AND expiredate >= ? 
        AND opportunities IS NOT NULL AND opportunities > 0
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("is", $userid, $today);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Lista los beneficiarios de un titular con sus datos
 */
function get_beneficiaries($db, $titular_userid) {
    $stmt = $db->prepare("SELECT tb.*, u.firstname, u.lastname, u.cedula 
        FROM ticket_beneficiaries tb
        JOIN users u ON u.userid = tb.beneficiary_userid
        WHERE tb.titular_userid = ?
        ORDER BY tb.created_at ASC");
    $stmt->bind_param("i", $titular_userid);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Verifica de quién es beneficiario un usuario (retorna datos del titular o null)
 */
function get_titular_of($db, $beneficiary_userid) {
    $stmt = $db->prepare("SELECT tb.titular_userid, u.firstname, u.lastname 
        FROM ticket_beneficiaries tb
        JOIN users u ON u.userid = tb.titular_userid
        WHERE tb.beneficiary_userid = ? LIMIT 1");
    $stmt->bind_param("i", $beneficiary_userid);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}

/**
 * Verifica si el titular puede hacer un cambio (reemplazo) en su vigencia actual
 */
function can_change_beneficiary($db, $titular_userid) {
    $tiquetera = get_active_tiquetera($db, $titular_userid);
    if (!$tiquetera) return false;
    
    $stmt = $db->prepare("SELECT COUNT(*) FROM beneficiary_changes 
        WHERE titular_userid = ? AND current_ticket_id = ? AND action = 'replace'");
    $stmt->bind_param("ii", $titular_userid, $tiquetera['id']);
    $stmt->execute();
    $changes = $stmt->get_result()->fetch_row()[0];
    return $changes < 1;
}

/**
 * Agrega un beneficiario. Retorna true o mensaje de error.
 * $is_replacement: true si está reemplazando (cuenta contra el límite)
 */
function add_beneficiary($db, $titular_userid, $beneficiary_userid, $is_replacement = false) {
    // Validar tiquetera activa
    $tiquetera = get_active_tiquetera($db, $titular_userid);
    if (!$tiquetera) return "El titular no tiene una tiquetera activa.";
    
    // No puede ser beneficiario de sí mismo
    if ($titular_userid == $beneficiary_userid) return "No puedes agregarte a ti mismo.";
    
    // Verificar que el beneficiario exista
    $stmt = $db->prepare("SELECT userid FROM users WHERE userid = ?");
    $stmt->bind_param("i", $beneficiary_userid);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) return "El usuario beneficiario no existe.";
    
    // Máximo 2
    $current = get_beneficiaries($db, $titular_userid);
    if (count($current) >= 2) return "Ya tienes el máximo de 2 beneficiarios. Elimina uno primero.";
    
    // El beneficiario no puede ser beneficiario de otro titular
    if (get_titular_of($db, $beneficiary_userid)) return "Esta persona ya es beneficiaria de otra tiquetera.";
    
    // Si es reemplazo, verificar límite
    if ($is_replacement && !can_change_beneficiary($db, $titular_userid)) {
        return "Ya usaste tu cambio de beneficiario para esta tiquetera. Podrás cambiar cuando renueves.";
    }
    
    // Insertar
    $stmt = $db->prepare("INSERT INTO ticket_beneficiaries (titular_userid, beneficiary_userid) VALUES (?, ?)");
    $stmt->bind_param("ii", $titular_userid, $beneficiary_userid);
    if (!$stmt->execute()) return "Error al agregar beneficiario.";
    
    // Registrar el cambio si es reemplazo
    if ($is_replacement) {
        $stmt = $db->prepare("INSERT INTO beneficiary_changes (titular_userid, current_ticket_id, action, beneficiary_userid) VALUES (?, ?, 'replace', ?)");
        $stmt->bind_param("iii", $titular_userid, $tiquetera['id'], $beneficiary_userid);
        $stmt->execute();
    }
    
    return true;
}

/**
 * Elimina un beneficiario
 */
function remove_beneficiary($db, $titular_userid, $beneficiary_userid) {
    $stmt = $db->prepare("DELETE FROM ticket_beneficiaries WHERE titular_userid = ? AND beneficiary_userid = ?");
    $stmt->bind_param("ii", $titular_userid, $beneficiary_userid);
    return $stmt->execute();
}

/**
 * Verifica si un usuario ya consumió su tiket HOY (titular o beneficiario)
 */
function already_used_today($db, $userid) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM access_log 
        WHERE userid = ? AND DATE(entry_time) = CURDATE()");
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    return $stmt->get_result()->fetch_row()[0] > 0;
}

/**
 * LÓGICA CENTRAL DE ACCESO:
 * Dado un userid que marca en el torniquete, determina:
 * - Si es titular con plan propio -> usa su plan
 * - Si es beneficiario -> descuenta de la tiquetera del titular
 * - Aplica regla de 1 tiket por día
 * Retorna: ['allowed' => bool, 'source' => 'own'|'beneficiary', 'titular_userid' => ..., 'message' => ...]
 */
function resolve_access($db, $userid) {
    $today = date('Y-m-d');
    
    // 1. ¿Tiene plan propio vigente?
    $stmt = $db->prepare("SELECT * FROM current_tickets 
        WHERE userid = ? AND expiredate >= ? 
        AND (opportunities IS NULL OR opportunities > 0)
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("is", $userid, $today);
    $stmt->execute();
    $own = $stmt->get_result()->fetch_assoc();
    
    if ($own) {
        // Si es tiquetera (con ocasiones), aplicar 1 por día
        if ($own['opportunities'] !== null && already_used_today($db, $userid)) {
            return ['allowed' => true, 'source' => 'own', 'ticket_id' => $own['id'], 'deduct' => false, 'message' => 'Ya consumió su tiket hoy - acceso sin descuento'];
        }
        return ['allowed' => true, 'source' => 'own', 'ticket_id' => $own['id'], 'deduct' => ($own['opportunities'] !== null), 'message' => 'Plan propio'];
    }
    
    // 2. ¿Es beneficiario de alguien?
    $titular = get_titular_of($db, $userid);
    if ($titular) {
        $tiquetera = get_active_tiquetera($db, $titular['titular_userid']);
        if ($tiquetera) {
            // Regla 1 por día para el beneficiario
            if (already_used_today($db, $userid)) {
                return ['allowed' => true, 'source' => 'beneficiary', 'ticket_id' => $tiquetera['id'], 'titular_userid' => $titular['titular_userid'], 'deduct' => false, 'message' => 'Beneficiario ya consumió hoy'];
            }
            return ['allowed' => true, 'source' => 'beneficiary', 'ticket_id' => $tiquetera['id'], 'titular_userid' => $titular['titular_userid'], 'deduct' => true, 'message' => 'Beneficiario de ' . $titular['firstname'] . ' ' . $titular['lastname']];
        }
    }
    
    return ['allowed' => false, 'message' => 'Sin plan vigente'];
}
