<?php

/*
|--------------------------------------------------------------------------
| Aprovisionamiento automático de dispositivos
|--------------------------------------------------------------------------
|
| Aquí vive únicamente lo ESTRUCTURAL: nombres, versiones mínimas y valores
| por defecto que rara vez cambian.
|
| Todo lo operativo —subred de la VPN, endpoint, aprobación automática,
| prefijos MAC admitidos, tiempos de espera— se administra desde el panel en
| Configuraciones → Workers, respaldado por `automation_settings`. Ese fue el
| criterio que ya siguieron los módulos de MikroTik y de notificaciones:
| cambiar un parámetro operativo no debe exigir un redespliegue en Coolify.
|
| Las credenciales de los routers siguen viviendo cifradas en la tabla
| `mikrotik_routers`, y los secretos de los agentes en `provisioning_agents`.
| Aquí no hay ningún secreto.
|
*/

return [

    /*
     * Interruptor global. Con esto en false los agentes siguen autenticándose
     * pero no se abre ninguna sesión nueva: útil para congelar el alta durante
     * una ventana de mantenimiento sin revocar credenciales.
     */
    'enabled' => env('PROVISIONING_ENABLED', true),

    'agent' => [
        // Cadencia sugerida de polling que se devuelve al agente. Él la respeta;
        // no es un límite impuesto (para eso está el throttle de la ruta).
        'poll_interval_seconds' => (int) env('PROVISIONING_POLL_INTERVAL', 3),

        // Sin heartbeat en este tiempo, el monitor considera caído al agente.
        'offline_after_minutes' => (int) env('PROVISIONING_AGENT_OFFLINE_MINUTES', 5),

        // Tareas que un agente puede llevarse en un mismo claim.
        'max_tasks_per_claim' => 1,
    ],

    'vpn' => [
        'driver' => 'wireguard',

        // Nombre de la interfaz que se crea en el router. Se mantiene fijo para
        // que el rollback sepa qué borrar aunque la sesión se haya perdido.
        'interface_name' => env('PROVISIONING_VPN_INTERFACE', 'wg-ispgestor'),

        // WireGuard es nativo en RouterOS a partir de la 7.1. Por debajo de esa
        // versión el alta se rechaza limpiamente, sin tocar el equipo.
        'minimum_routeros_version' => '7.1',

        'keepalive_seconds' => 25,
    ],

    'router' => [
        /*
         * Usuario dedicado que se crea en el router al terminar el alta. El
         * flujo automático no puede dejar el equipo con las credenciales de
         * fábrica: se crea este usuario con contraseña generada y se guarda
         * cifrada en `mikrotik_routers.password`.
         */
        'api_username' => env('PROVISIONING_ROUTER_API_USER', 'ispgestor-api'),
        'api_port'     => 8728,

        // Longitud de la contraseña generada para ese usuario.
        'generated_password_length' => 32,
    ],

    /*
     * Valores por defecto de los parámetros operativos. Son la red de
     * seguridad si `automation_settings` todavía no tiene la fila sembrada:
     * el código lee siempre AutomationSetting::getParam(..., default) usando
     * estos valores como default.
     */
    'defaults' => [
        'auto_approve'         => true,
        'vpn_subnet'           => '10.77.0.0/24',
        'vpn_server_ip'        => '10.77.0.1',
        'endpoint_port'        => 51820,
        'allowed_mac_prefixes' => [
            // OUI registradas de MikroTikls SIA. Acotan qué equipos son
            // candidatos: enchufar otra cosa en el puerto no dispara un alta.
            '18:FD:74', '2C:C8:1B', '48:8F:5A', '4C:5E:0C', '64:D1:54',
            '6C:3B:6B', '74:4D:28', '78:9A:18', '7C:2F:80', 'B8:69:F4',
            'CC:2D:E0', 'D4:CA:6D', 'DC:2C:6E', 'E4:8D:8C', 'F4:1E:57',
        ],
        // Credenciales de fábrica que el agente prueba al identificar. Vacío el
        // password es el caso por defecto de RouterOS.
        'factory_credentials' => [
            ['username' => 'admin', 'password' => ''],
        ],
    ],

];
