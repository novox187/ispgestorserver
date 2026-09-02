<?php

namespace App\Services;

use App\Models\NetworkDevice;
use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;

/**
 * Verifica conectividad con un equipo RouterOS y lee sus recursos de sistema.
 *
 * Aislado en una clase propia para poder ser sustituido por un mock en pruebas
 * (no depende del binding de Client del provider, sino que crea uno fresco con
 * las credenciales de la fila — habilitando soporte multi-equipo).
 *
 * La firma acepta `NetworkDevice` y no `MikrotikRouter` para que el monitoreo
 * genérico pueda pasarle cualquier fila del inventario. Sigue siendo
 * responsabilidad del llamante —hoy, `RouterOsDriver`— no traerle una antena:
 * esta clase habla la API binaria de RouterOS y nada más.
 */
class MikrotikHealthChecker
{
    public function __construct(
        private readonly int $timeoutSeconds = 3,
    ) {
    }

    /**
     * @param int|null $timeoutOverride  Permite al caller (job, comando) imponer
     *        un timeout configurado en runtime sin reconstruir el servicio.
     *        Si es null se usa el valor del constructor.
     * @return array{ok: bool, error: ?string}
     */
    public function check(NetworkDevice $router, ?int $timeoutOverride = null): array
    {
        try {
            $this->resources($router, $timeoutOverride);

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

    /**
     * Devuelve `/system/resource/print` en crudo. **Lanza** si no se puede
     * hablar con el equipo.
     *
     * Es el mismo trabajo que hace `check()` —de hecho `check()` lo usa— pero
     * conservando la respuesta en vez de tirarla. El sondeo ya obliga a abrir la
     * sesión y a esperar la respuesta; descartar los datos y volver a pedirlos
     * después sería pagar dos veces el viaje.
     *
     * @return array<string, mixed>
     */
    public function resources(NetworkDevice $router, ?int $timeoutOverride = null): array
    {
        $rows = $this->query($router, '/system/resource/print', $timeoutOverride);

        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /**
     * Ejecuta una consulta de solo lectura y devuelve sus filas. **Lanza** si no
     * se puede hablar con el equipo.
     *
     * Existe para que el descubrimiento de topología pueda leer
     * `/ip/neighbor/print` sin duplicar la construcción del cliente. Es
     * deliberadamente genérico y deliberadamente de solo lectura: quien la llame
     * pasa el comando, y todos los que hay hoy son `print`.
     *
     * @return list<mixed>
     */
    public function query(NetworkDevice $router, string $command, ?int $timeoutOverride = null): array
    {
        $timeout = $timeoutOverride !== null ? max(1, $timeoutOverride) : $this->timeoutSeconds;

        $config = new Config([
            'host'     => (string) $router->host,
            'user'     => (string) $router->username,
            'pass'     => (string) $router->password,
            'port'     => (int) ($router->port ?: 8728),
            'timeout'  => $timeout,
            'attempts' => 1,
        ]);

        return (new Client($config))->query(new Query($command))->read();
    }
}
