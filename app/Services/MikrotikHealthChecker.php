<?php

namespace App\Services;

use App\Models\MikrotikRouter;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;

/**
 * Verifica conectividad con un MikroTik dado.
 *
 * Aislado en una clase propia para poder ser sustituido por un mock en pruebas
 * (no depende del singleton de Client del provider, sino que crea uno fresco
 * con las credenciales de la fila — habilitando soporte multi-router).
 */
class MikrotikHealthChecker
{
    public function __construct(
        private readonly int $timeoutSeconds = 3,
    ) {
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function check(MikrotikRouter $router): array
    {
        try {
            $config = new Config([
                'host'     => $router->host,
                'user'     => $router->username,
                'pass'     => $router->password,
                'port'     => (int) ($router->port ?: 8728),
                'timeout'  => $this->timeoutSeconds,
                'attempts' => 1,
            ]);
            $client = new Client($config);
            $client->query(new Query('/system/resource/print'))->read();
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            Log::debug('MikrotikHealthChecker: fallo de conectividad.', [
                'router_id' => $router->id,
                'host'      => $router->host,
                'error'     => $e->getMessage(),
            ]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
