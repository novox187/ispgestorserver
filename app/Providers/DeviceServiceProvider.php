<?php

namespace App\Providers;

use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Drivers\AirOsDriver;
use App\Services\Devices\Drivers\RouterOsDriver;
use App\Services\Provisioning\AgentSignature;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Registra los drivers de dispositivo disponibles.
 *
 * Es el equivalente, para el parque de equipos, de lo que
 * `ProvisioningServiceProvider` hace con `VpnDriver`. Dar de alta un fabricante
 * nuevo es implementar `DeviceDriver` y añadir una línea aquí.
 *
 * Singleton porque el registro no tiene estado por petición y los drivers son
 * objetos baratos y sin estado propio: construirlos en cada resolución sería
 * trabajo sin contrapartida.
 */
class DeviceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DeviceDriverRegistry::class, function ($app) {
            return new DeviceDriverRegistry([
                $app->make(RouterOsDriver::class),
                $app->make(AirOsDriver::class),
            ]);
        });
    }

    public function boot(): void
    {
        /*
         * Cubo propio para el canal de monitoreo, llaveado por AGENTE.
         *
         * El limitador por defecto de Laravel se llavea por usuario autenticado
         * y, a falta de él, por IP. En este canal no hay usuario de Laravel, así
         * que todos los agentes detrás del mismo NAT de oficina compartirían
         * cuota: el monitoreo, que empuja lotes, dejaría sin margen al
         * aprovisionamiento y viceversa.
         *
         * Se llavea con la cabecera del token del agente, que es su
         * identificador público —no el secreto—, y se cae a la IP si falta,
         * para que una petición sin cabeceras siga estando limitada.
         */
        RateLimiter::for('agent-monitoring', function (Request $request) {
            $token = (string) $request->header(AgentSignature::HEADER_AGENT, '');

            return Limit::perMinute((int) config('devices.monitoring.rate_limit_per_minute', 60))
                ->by($token !== '' ? 'agent:' . sha1($token) : 'ip:' . $request->ip());
        });
    }
}
