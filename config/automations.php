<?php

/*
|--------------------------------------------------------------------------
| Política de los workers automáticos
|--------------------------------------------------------------------------
|
| La tabla `automation_settings` guarda QUÉ está configurado. Este archivo
| declara QUÉ SE PUEDE configurar: con qué cadencias tiene sentido correr cada
| worker, cuánto daño hace equivocarse y qué deja de pasar si se apaga.
|
| Vivía todo en la interfaz: el panel ofrecía las ocho cadencias a los diez
| workers por igual, así que la Generación Mensual de Facturas se podía poner
| «cada cinco minutos» y el corte de servicio «cada hora». El servidor las
| aceptaba porque solo validaba el formato, no el sentido.
|
| El catálogo vive aquí y no en la base de datos a propósito: no es
| configuración del operador, es una restricción del sistema. Cambiarla es un
| despliegue, no un clic.
|
| `risk` decide cuánta fricción se interpone:
|   critical — toca el servicio del abonado o su dinero. Cambios y ejecución
|              manual piden confirmación explicando la consecuencia.
|   standard — toca la infraestructura. Sin confirmación, pero agrupado aparte.
|   passive  — solo lee y agrega. Sin fricción.
|
*/

return [

    /*
    | Cadencias válidas por worker. Un tipo ausente de esta lista se rechaza en
    | el servidor, no solo se oculta en el panel.
    |
    | `cron` no aparece en ninguna: era texto libre validado por una expresión
    | regular que solo contaba cinco campos, así que `* * * * *` —cada minuto—
    | pasaba, y `a b c d e` también (fallaba luego en silencio dentro del
    | try/catch del scheduler). Si algún worker acaba necesitando una cadencia
    | rara, se añade aquí con nombre propio.
    */
    'policies' => [

        'client_suspension' => [
            'risk'      => 'critical',
            'schedules' => ['daily'],
            'summary'   => 'Corta el servicio de quien acumula facturas vencidas.',
            'if_disabled' => 'Nadie se suspende, por mucho que deba. La cartera vencida sigue navegando.',
            'why_schedule' => 'Cortar es una decisión de una vez al día: con una cadencia menor se dejaría a un abonado sin conexión a media tarde.',
        ],

        'auto_reactivation' => [
            'risk'      => 'critical',
            'schedules' => ['daily', 'hourly'],
            'summary'   => 'Restablece el servicio de quien ya no debe nada vencido.',
            'if_disabled' => 'Un cliente que salda su deuda por una vía distinta a la recarga se queda cortado hasta que alguien lo reactive a mano.',
            'why_schedule' => 'Más frecuente es mejor para el abonado: solo restablece servicio, nunca lo quita.',
        ],

        'auto_billing' => [
            'risk'      => 'critical',
            'schedules' => ['daily'],
            'summary'   => 'Cobra de la billetera las facturas por vencer y reintenta las fallidas.',
            'if_disabled' => 'No se cobra nada automáticamente. El corte seguirá funcionando, pero cortará sin haber intentado cobrar antes.',
            'why_schedule' => 'Cobrar dos veces el mismo día no tiene sentido y multiplica el riesgo de cargo duplicado.',
        ],

        'monthly_invoices' => [
            'risk'      => 'critical',
            'schedules' => ['monthly'],
            'summary'   => 'Emite las facturas recurrentes del periodo.',
            'if_disabled' => 'No se emite ninguna factura nueva: nadie recibe cargo y no hay nada que cobrar ni que vencer.',
            'why_schedule' => 'El ciclo de facturación es mensual por definición; cualquier otra cadencia emite fuera de periodo.',
        ],

        'billing_integrity' => [
            'risk'      => 'standard',
            'schedules' => ['daily', 'hourly'],
            'summary'   => 'Concilia en solo lectura la base de datos contra el router y la facturación.',
            'if_disabled' => 'Las desalineaciones dejan de detectarse: un cliente cortado que sigue navegando ya no se reporta.',
            'why_schedule' => 'Solo lee, así que la frecuencia es un compromiso de coste, no de riesgo.',
        ],

        'mikrotik_sync' => [
            'risk'      => 'standard',
            'schedules' => ['every_fifteen_minutes', 'every_thirty_minutes', 'hourly', 'daily'],
            'summary'   => 'Mantiene las colas del router alineadas con los planes contratados.',
            'if_disabled' => 'Un cambio de plan deja de reflejarse en la velocidad real que recibe el abonado.',
            'why_schedule' => 'Cada corrida recorre el router entero: por debajo de quince minutos se le carga sin ganar nada.',
        ],

        'device_auto_provisioning' => [
            'risk'      => 'standard',
            'schedules' => ['every_five_minutes', 'every_ten_minutes', 'every_fifteen_minutes', 'every_thirty_minutes'],
            'summary'   => 'Vigila las altas de equipos y rescata las que se quedaron a medias.',
            'if_disabled' => 'Un alta cuyo agente muera a mitad se queda colgada, sin revertir ni completar.',
            'why_schedule' => 'Es un vigilante: cuanto más tarde en pasar, más tiempo queda colgada una sesión rota.',
        ],

        'device_connectivity_monitor' => [
            'risk'      => 'passive',
            'schedules' => ['every_five_minutes', 'every_ten_minutes', 'every_fifteen_minutes', 'every_thirty_minutes', 'hourly'],
            'summary'   => 'Comprueba que cada equipo del inventario siga respondiendo.',
            'if_disabled' => 'Un equipo caído deja de avisar: se descubre cuando llama el cliente.',
            'why_schedule' => 'Marca el retardo con el que se detecta una caída.',
        ],

        'provisioning_agent_monitor' => [
            'risk'      => 'passive',
            'schedules' => ['every_five_minutes', 'every_ten_minutes', 'every_fifteen_minutes', 'every_thirty_minutes'],
            'summary'   => 'Vigila que los agentes de aprovisionamiento sigan reportando.',
            'if_disabled' => 'Un agente caído no se detecta; simplemente dejan de entrar altas.',
            'why_schedule' => 'Debe ser bastante más frecuente que el umbral de «minutos sin reportar».',
        ],

        'topology_discovery' => [
            'risk'      => 'passive',
            'schedules' => ['hourly', 'daily'],
            'summary'   => 'Deduce los enlaces entre equipos a partir de sus tablas de vecinos.',
            'if_disabled' => 'El mapa de red deja de actualizarse: los equipos nuevos no aparecen enlazados.',
            'why_schedule' => 'La topología cambia con las instalaciones, no de un minuto a otro.',
        ],

        'device_metrics_rollup' => [
            'risk'      => 'passive',
            'schedules' => ['hourly', 'daily'],
            'summary'   => 'Resume la telemetría por hora y poda el detalle vencido.',
            'if_disabled' => 'La tabla de muestras crece unas 100.000 filas al día hasta hacer inviables los backups.',
            'why_schedule' => 'Agrega por hora: correrlo más a menudo no adelanta trabajo, y menos deja crecer la tabla.',
        ],
    ],

    /*
    | Política por defecto para un worker que aún no esté catalogado (se añade
    | uno nuevo y se olvida este archivo). Deliberadamente restrictiva: es
    | preferible que una cadencia legítima haya que habilitarla aquí a que un
    | worker nuevo herede libertad total.
    */
    'default_policy' => [
        'risk'         => 'standard',
        'schedules'    => ['hourly', 'daily'],
        'summary'      => null,
        'if_disabled'  => 'Este worker deja de ejecutarse.',
        'why_schedule' => null,
    ],

    /*
    | Etiquetas de los niveles de riesgo, usadas por el panel para agrupar.
    */
    'risk_levels' => [
        'critical' => [
            'label'   => 'Afectan al servicio y al dinero del abonado',
            'caption' => 'Un error aquí corta conexiones o genera cargos. Los cambios piden confirmación.',
        ],
        'standard' => [
            'label'   => 'Mantienen la red y los datos alineados',
            'caption' => 'No cobran ni cortan, pero de ellos depende que lo que se cobra y se corta sea correcto.',
        ],
        'passive' => [
            'label'   => 'Observan y ordenan',
            'caption' => 'Solo leen, agregan y avisan. Apagarlos no rompe nada de inmediato: deja de haber vigilancia.',
        ],
    ],
];
