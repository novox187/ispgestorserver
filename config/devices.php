<?php

/*
|--------------------------------------------------------------------------
| Parque de dispositivos y monitoreo
|--------------------------------------------------------------------------
|
| Igual que en `provisioning.php`, aquí vive solo lo ESTRUCTURAL. Lo operativo
| —cadencia de sondeo, umbrales de alerta, retención— se administra desde el
| panel en Configuraciones → Workers, respaldado por `automation_settings`, para
| que ajustar un parámetro no exija un redespliegue en Coolify.
|
| Aquí no hay ningún secreto: las credenciales de los equipos viven cifradas en
| `network_devices` y en `device_credentials`.
|
*/

return [

    'monitoring' => [
        /*
         * Cadencia que se le sugiere al agente. Cinco minutos es el equilibrio
         * entre detectar una caída pronto y no martillear el httpd de las
         * antenas, que es monohilo y admite pocas sesiones.
         */
        'poll_interval_seconds' => (int) env('DEVICE_POLL_INTERVAL', 300),

        /*
         * Peticiones por minuto que se le permiten al canal de monitoreo, por
         * agente.
         *
         * Va en un cubo propio y llaveado por agente porque el `throttle:180,1`
         * del canal de aprovisionamiento se llavea por IP: todos los agentes
         * detrás del mismo NAT de oficina comparten cubo, y el monitoreo los
         * dejaría sin cuota al resto.
         */
        'rate_limit_per_minute' => (int) env('DEVICE_MONITORING_RATE_LIMIT', 60),
    ],

    'retention' => [
        /*
         * Días de detalle antes de podar. Con las antenas de cliente dentro del
         * alcance son unas 100.000 filas al día, así que la retención corta no
         * es tacañería: es lo que mantiene la tabla y los backups manejables.
         * Lo que sobrevive son los agregados horarios.
         */
        'samples_days' => 14,

        /* Meses de agregados horarios. Trece para poder comparar con el año pasado. */
        'hourly_months' => 13,

        /*
         * Filas por iteración al podar. Un DELETE de millones de filas de un
         * tirón bloquea la tabla y llena el redo log; se borra en tandas.
         */
        'prune_chunk' => 5000,
    ],

];
