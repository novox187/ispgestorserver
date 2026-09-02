<?php

namespace App\Providers;

use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use App\Observers\PrimaryRouterObserver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RouterOS\Client;
use RouterOS\Config;

/**
 * Resuelve el `RouterOS\Client` consultando la BD (no env).
 *
 * El cliente se construye con las credenciales del router marcado como primary
 * (`mikrotik_routers.is_primary = true`). Si no hay primary configurado el
 * binding devuelve `null` y los servicios que dependen de él (MikroTikService,
 * sync, firewall, suspensiones) operan en modo "no-op": no rompen pero quedan
 * a la espera de que el admin cree el primer router desde el panel.
 *
 * Bindeamos como `scoped` (no `singleton`) para que el cliente se reconstruya
 * por request — si el admin cambia credenciales del primary en caliente, el
 * siguiente request usa los valores nuevos sin reiniciar la app.
 */
class MikroTikServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Client::class, function () {
            return $this->buildClient();
        });
    }

    public function boot(): void
    {
        /*
         * El invariante del router primary se registra para las DOS clases que
         * ven la tabla `network_devices`. Eloquent despacha los eventos de
         * modelo bajo el nombre de la clase concreta, así que registrarlo solo
         * en una dejaría la otra como puerta trasera por la que borrar el
         * primary sin que nadie promueva un sustituto — y el sistema entero
         * respondería 423 sin un solo error en los logs.
         */
        MikrotikRouter::observe(PrimaryRouterObserver::class);
        NetworkDevice::observe(PrimaryRouterObserver::class);
    }

    private function buildClient(): ?Client
    {
        /*
         * En arranques tempranos (migrate, comandos sobre BD vacía) la tabla
         * puede aún no existir. Devolver null permite que el provider no rompa
         * el bootstrap de la app.
         *
         * El nombre tiene que seguir al de la tabla: si esta comprobación se
         * queda mirando una tabla que ya no existe, devuelve null en cada
         * request, el cliente de RouterOS nunca se construye y TODO el módulo
         * MikroTik pasa a modo no-op —colas, firewall, suspensiones— sin lanzar
         * un solo error. Es el fallo más silencioso que puede tener este
         * archivo.
         */
        try {
            if (!Schema::hasTable('network_devices')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $router = MikrotikRouter::primaryRouter();

        if (!$router || !$router->is_active) {
            return null;
        }

        try {
            $config = new Config([
                'host'     => (string) $router->host,
                'user'     => (string) $router->username,
                'pass'     => (string) $router->password,
                'port'     => (int) ($router->port ?: 8728),
                'timeout'  => 10,
                'attempts' => 1,
                'delay'    => 0,
            ]);

            return new Client($config);
        } catch (\Throwable $e) {
            Log::error('MikroTikServiceProvider: no se pudo construir el Client del router primary.', [
                'router_id' => $router->id,
                'host'      => $router->host,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }
}
