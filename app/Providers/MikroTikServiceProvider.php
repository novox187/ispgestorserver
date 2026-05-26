<?php

namespace App\Providers;

use App\Models\MikrotikRouter;
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
        //
    }

    private function buildClient(): ?Client
    {
        // En arranques tempranos (migrate, comandos sobre BD vacía) la tabla
        // puede aún no existir. Devolver null permite que el provider no rompa
        // el bootstrap de la app.
        try {
            if (!Schema::hasTable('mikrotik_routers')) {
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
