<?php

namespace App\Jobs;

/**
 * @deprecated Usa `MonitorDeviceConnectivityJob`, que vigila el parque entero y
 *             no solo los MikroTik.
 *
 * Se conserva únicamente como red de seguridad del despliegue. El nombre de esta
 * clase está persistido en `automation_settings.job_class`, y el código llega al
 * servidor antes de que corra la migración que actualiza esa fila. Si la clase
 * desapareciera en ese intervalo, `AutomationSettingsService` la descartaría con
 * un simple `Log::warning` y **el monitoreo quedaría apagado sin que nadie se
 * entere** hasta que cayera un equipo y no llegara la alerta.
 *
 * Puede borrarse cuando se confirme que ninguna fila de `automation_settings`
 * la referencia.
 */
class MonitorMikrotikConnectivityJob extends MonitorDeviceConnectivityJob
{
}
