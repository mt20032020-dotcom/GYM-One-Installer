<?php
/**
 * Librería de Planes Futuros - Adrenaline Gym
 * Maneja la cola de planes y su activación automática
 */

/**
 * Verifica si un usuario tiene plan activo vigente
 * Retorna el plan activo o null
 */
function get_active_plan($db, $userid) {
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM current_tickets 
        WHERE userid = ? AND expiredate >= ? 
        AND (opportunities IS NULL OR opportunities > 0)
        ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("is", $userid, $today);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result ?: null;
}

/**
 * Agrega un plan: si no hay activo lo activa de una vez,
 * si hay activo lo mete a la cola de futuros.
 * $desired_start: fecha deseada de inicio (null = hoy o automático)
 * Retorna: ['type' => 'active'|'future', 'start_date' => ..., 'end_date' => ...]
 */
function add_plan($db, $userid, $ticketname, $expire_days, $opportunities, $desired_start = null) {
    $active = get_active_plan($db, $userid);

    if (!$active && ($desired_start === null || $desired_start <= date('Y-m-d'))) {
        // Sin plan activo y sin fecha futura: activar ya
        $start = date('Y-m-d');
        $end = date('Y-m-d', strtotime("+{$expire_days} days", strtotime($start)));
        $stmt = $db->prepare("INSERT INTO current_tickets (userid, ticketname, buydate, expiredate, opportunities) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isssi", $userid, $ticketname, $start, $end, $opportunities);
        $stmt->execute();
        return ['type' => 'active', 'start_date' => $start, 'end_date' => $end];
    }

    if (!$active && $desired_start > date('Y-m-d')) {
        // Sin plan activo pero con fecha futura elegida: va a la cola con fecha
        $order = get_next_queue_order($db, $userid);
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare("INSERT INTO future_tickets (userid, ticketname, expire_days, opportunities, purchase_date, desired_start_date, queue_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isiissi", $userid, $ticketname, $expire_days, $opportunities, $now, $desired_start, $order);
        $stmt->execute();
        $est_end = date('Y-m-d', strtotime("+{$expire_days} days", strtotime($desired_start)));
        return ['type' => 'future', 'start_date' => $desired_start, 'end_date' => $est_end];
    }

    // Con plan activo: a la cola
    $order = get_next_queue_order($db, $userid);
    $now = date('Y-m-d H:i:s');
    $stmt = $db->prepare("INSERT INTO future_tickets (userid, ticketname, expire_days, opportunities, purchase_date, desired_start_date, queue_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isiissi", $userid, $ticketname, $expire_days, $opportunities, $now, $desired_start, $order);
    $stmt->execute();

    // Fecha estimada: cuando venza el último de la cadena
    $est_start = estimate_queue_start($db, $userid, $order);
    $est_end = $est_start ? date('Y-m-d', strtotime("+{$expire_days} days", strtotime($est_start))) : null;
    return ['type' => 'future', 'start_date' => $est_start, 'end_date' => $est_end];
}

/**
 * Siguiente orden en la cola del usuario
 */
function get_next_queue_order($db, $userid) {
    $stmt = $db->prepare("SELECT COALESCE(MAX(queue_order), 0) + 1 FROM future_tickets WHERE userid = ? AND activated = 0");
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    return $stmt->get_result()->fetch_row()[0];
}

/**
 * Estima cuándo iniciaría un plan en la cola
 */
function estimate_queue_start($db, $userid, $up_to_order) {
    $active = get_active_plan($db, $userid);
    $date = $active ? $active['expiredate'] : date('Y-m-d');

    $stmt = $db->prepare("SELECT * FROM future_tickets WHERE userid = ? AND activated = 0 AND queue_order < ? ORDER BY queue_order ASC");
    $stmt->bind_param("ii", $userid, $up_to_order);
    $stmt->execute();
    $queue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($queue as $item) {
        if ($item['desired_start_date'] && $item['desired_start_date'] > $date) {
            $date = $item['desired_start_date'];
        }
        $date = date('Y-m-d', strtotime("+{$item['expire_days']} days", strtotime($date)));
    }
    return $date;
}

/**
 * ACTIVADOR: revisa si el plan actual del usuario murió y activa el siguiente de la cola.
 * Se llama desde: check-in, cron diario, dashboard, admin.
 */
function activate_next_plan($db, $userid) {
    $active = get_active_plan($db, $userid);
    if ($active) return false; // Aún tiene plan vigente

    // Buscar el siguiente en la cola
    $today = date('Y-m-d');
    $stmt = $db->prepare("SELECT * FROM future_tickets 
        WHERE userid = ? AND activated = 0 
        AND (desired_start_date IS NULL OR desired_start_date <= ?)
        ORDER BY queue_order ASC LIMIT 1");
    $stmt->bind_param("is", $userid, $today);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();

    if (!$next) return false; // No hay planes en cola listos

    // Activarlo
    $start = date('Y-m-d');
    $end = date('Y-m-d', strtotime("+{$next['expire_days']} days"));
    $stmt2 = $db->prepare("INSERT INTO current_tickets (userid, ticketname, buydate, expiredate, opportunities) VALUES (?, ?, ?, ?, ?)");
    $stmt2->bind_param("isssi", $userid, $next['ticketname'], $start, $end, $next['opportunities']);
    $stmt2->execute();

    // Marcarlo como activado
    $stmt3 = $db->prepare("UPDATE future_tickets SET activated = 1 WHERE id = ?");
    $stmt3->bind_param("i", $next['id']);
    $stmt3->execute();

    // Log
    $db->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ($userid, 'Plan futuro activado: {$next['ticketname']}', 'success', NOW())");

    return $next;
}

/**
 * Obtiene la cola de planes futuros de un usuario con fechas estimadas
 */
function get_future_plans($db, $userid) {
    $stmt = $db->prepare("SELECT * FROM future_tickets WHERE userid = ? AND activated = 0 ORDER BY queue_order ASC");
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    $plans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($plans as $i => $plan) {
        $est_start = estimate_queue_start($db, $userid, $plan['queue_order']);
        if ($plan['desired_start_date'] && $plan['desired_start_date'] > $est_start) {
            $est_start = $plan['desired_start_date'];
        }
        $plans[$i]['estimated_start'] = $est_start;
        $plans[$i]['estimated_end'] = date('Y-m-d', strtotime("+{$plan['expire_days']} days", strtotime($est_start)));
    }
    return $plans;
}

/**
 * Elimina un plan futuro de la cola
 */
function remove_future_plan($db, $future_id, $userid) {
    $stmt = $db->prepare("DELETE FROM future_tickets WHERE id = ? AND userid = ? AND activated = 0");
    $stmt->bind_param("ii", $future_id, $userid);
    return $stmt->execute();
}
