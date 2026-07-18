<?php
/**
 * Lazy cron: ejecuta la activación de planes futuros máximo 1 vez por hora
 * Se incluye desde páginas de alto tráfico (login, dashboard, cdata)
 */
function run_lazy_future_activation($conn) {
    $lock_file = '/tmp/future_plans_last_run.txt';
    $now = time();
    
    // Verificar si ya corrió en la última hora
    if (file_exists($lock_file)) {
        $last_run = (int) @file_get_contents($lock_file);
        if (($now - $last_run) < 3600) return; // Menos de 1 hora
    }
    
    @file_put_contents($lock_file, $now);
    
    require_once '/app/includes/future_plans.php';
    
    // Activar planes pendientes de todos los usuarios
    $result = @$conn->query("SELECT DISTINCT userid FROM future_tickets WHERE activated = 0");
    if (!$result) return;
    
    while ($row = $result->fetch_assoc()) {
        $activated = @activate_next_plan($conn, $row['userid']);
        if ($activated) {
            @require_once '/app/iclock/lib/endtime.php';
            if (function_exists('sincronizar_acceso_speedface')) {
                @sincronizar_acceso_speedface($row['userid']);
            }
        }
    }
}
