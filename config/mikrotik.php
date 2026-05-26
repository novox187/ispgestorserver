<?php

/*
|--------------------------------------------------------------------------
| Configuración del módulo MikroTik
|--------------------------------------------------------------------------
|
| Las credenciales y datos de conexión de cada router viven en la tabla
| `mikrotik_routers` y se administran desde el panel (MikroTik → Dispositivos).
|
| El sistema usa el router marcado como `is_primary=true` para todas las
| operaciones por defecto. No hay variables de entorno `MIKROTIK_*` para
| credenciales — se eliminaron a favor de la BD.
|
| Aquí solo queda el flag global para deshabilitar el módulo en entornos
| donde no se quiera levantar conexiones RouterOS (p. ej. tests, CI).
|
*/

return [
    'enabled' => true,
];
