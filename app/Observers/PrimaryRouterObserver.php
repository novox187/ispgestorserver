<?php

namespace App\Observers;

use App\Enums\DeviceVendor;
use App\Models\Audit;
use App\Models\MikrotikRouter;
use App\Models\NetworkDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Sostiene el invariante del router primary.
 *
 * Exactamente un MikroTik lleva `is_primary=true`, y siempre hay uno mientras
 * quede algún router. De ese registro salen las credenciales con las que se
 * conecta medio sistema —colas, firewall, suspensiones, monitoreo—, así que
 * quedarse sin primary hace que `EnsurePrimaryRouter` devuelva 423 y el módulo
 * entero deje de responder.
 *
 * ## Por qué es un Observer y no hooks en `booted()`
 *
 * `MikrotikRouter` y `NetworkDevice` son dos vistas de la misma tabla, y Eloquent
 * despacha los eventos de modelo bajo el nombre de la clase concreta
 * (`"eloquent.{$event}: " . static::class`). Con la lógica en el `booted()` de
 * `MikrotikRouter`, crear o borrar una fila a través de `NetworkDevice` no
 * dispararía nada: se podría borrar el primary sin que nadie promoviera a otro y
 * el sistema se caería entero sin una sola línea en los logs.
 *
 * Registrando este observer para AMBAS clases el invariante se sostenga se entre
 * por donde se entre. Es la razón por la que la lógica salió del modelo.
 */
class PrimaryRouterObserver
{
    /**
     * Demociones hechas en `saving` a la espera de auditarse en `saved`.
     *
     * Se indexan por identidad del objeto en vez de guardarse como propiedad
     * pública del modelo, que era la solución anterior: así Eloquent no puede
     * confundirlas con una columna y el modelo no expone estado interno del
     * invariante.
     *
     * **Estático a propósito.** El despachador de eventos resuelve el observer
     * del contenedor cada vez que salta un evento, así que `saving` y `saved`
     * ocurrirían sobre instancias distintas y lo anotado en la primera se
     * perdería antes de llegar a la segunda. Podría arreglarse bindeando el
     * observer como singleton, pero entonces el correcto funcionamiento del
     * invariante dependería de una línea en un provider que nadie relacionaría
     * con esto al leerla. Estático es correcto pase lo que pase.
     *
     * @var array<int, list<int>>
     */
    private static array $pendingDemotions = [];

    /**
     * El primer router creado pasa automáticamente a ser primary y activo, para
     * que el sistema quede operativo en cuanto se da de alta el primero.
     */
    public function creating(Model $device): void
    {
        if (!$this->governs($device)) {
            return;
        }

        if (!$this->routers()->exists()) {
            $device->is_primary = true;

            if ($device->is_active === null) {
                $device->is_active = true;
            }
        }
    }

    /**
     * Solo un router puede ser primary a la vez: al marcar uno, el anterior se
     * desmarca dentro de la misma transacción.
     */
    public function saving(Model $device): void
    {
        if (!$this->governs($device) || !$device->is_primary || !$device->isDirty('is_primary')) {
            return;
        }

        $demoted = $this->routers()
            ->where('id', '!=', $device->id ?? 0)
            ->where('is_primary', true)
            ->pluck('id')
            ->all();

        if ($demoted === []) {
            return;
        }

        $this->routers()->whereIn('id', $demoted)->update(['is_primary' => false]);

        // La auditoría se aplaza a `saved`: en un alta, aquí el router que
        // promueve todavía no tiene id y el registro saldría sin decir quién
        // ocupó su lugar.
        self::$pendingDemotions[spl_object_id($device)] = $demoted;
    }

    public function saved(Model $device): void
    {
        $key = spl_object_id($device);

        foreach (self::$pendingDemotions[$key] ?? [] as $demotedId) {
            $this->auditDemotion($demotedId, $device);
        }

        unset(self::$pendingDemotions[$key]);
    }

    /**
     * Si se elimina el primary, promover otro para que el sistema no quede sin
     * router por defecto. Se prefiere uno activo.
     */
    public function deleted(Model $device): void
    {
        if (!$this->governs($device) || !$device->is_primary) {
            return;
        }

        $replacement = $this->routers()
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($replacement) {
            $replacement->is_primary = true;
            // Silencioso: promover un sustituto no debe volver a entrar en
            // `saving` y desencadenar otra ronda de demociones.
            $replacement->saveQuietly();
        }
    }

    /**
     * ¿Gobierna este observer a este equipo?
     *
     * Una antena Ubiquiti comparte tabla con los routers pero no tiene plano de
     * control, así que `is_primary` no significa nada para ella.
     *
     * La comprobación por instancia va primero y no es redundante: en un alta
     * todavía puede no haberse estampado el atributo `vendor` cuando se dispara
     * `creating`, y depender del orden en que se registraron los hooks sería
     * frágil. Un `MikrotikRouter` es un MikroTik por construcción.
     */
    private function governs(Model $device): bool
    {
        if ($device instanceof MikrotikRouter) {
            return true;
        }

        /*
         * El fabricante llega como enum o como cadena según por qué modelo entre
         * la operación: `NetworkDevice` castea la columna a `DeviceVendor` y
         * `MikrotikRouter` la deja en crudo. Comparar sin normalizar hacía que
         * este método devolviera siempre false para las filas leídas por
         * `NetworkDevice` —justo la puerta trasera que el observer existe para
         * tapar— y el invariante quedaba sin vigilar precisamente por donde más
         * falta hacía.
         */
        $vendor = $device->vendor ?? null;

        return ($vendor instanceof DeviceVendor ? $vendor->value : $vendor) === MikrotikRouter::VENDOR;
    }

    /**
     * Consulta sobre los routers MikroTik, independiente de por qué modelo haya
     * entrado la operación. Se filtra a mano en vez de apoyarse en el scope
     * global de `MikrotikRouter` para que el resultado no dependa de la clase
     * que disparó el evento.
     */
    private function routers(): Builder
    {
        return NetworkDevice::query()->where('vendor', MikrotikRouter::VENDOR);
    }

    /**
     * Deja constancia de una despromoción que el `update()` masivo de `saving`
     * no puede registrar por sí solo. Igual que en el trait `Auditable`, un
     * fallo al auditar nunca rompe la operación de negocio que lo originó.
     */
    private function auditDemotion(int $demotedId, Model $promoted): void
    {
        try {
            Audit::create([
                'table_name' => 'network_devices',
                'operation'  => 'PRIMARY_DEMOTED',
                'record_id'  => (string) $demotedId,
                'old_values' => ['is_primary' => true],
                'new_values' => [
                    'is_primary'         => false,
                    'promoted_router_id' => $promoted->id,
                    'promoted_router'    => $promoted->name,
                    'timestamp'          => now()->toIso8601String(),
                ],
                'user_id'    => Auth::id(),
                'user_type'  => Auth::user() ? get_class(Auth::user()) : null,
                'ip_address' => Request::ip() ?? '127.0.0.1',
            ]);
        } catch (\Throwable $e) {
            Log::error('PrimaryRouterObserver: fallo al auditar la despromoción de primary.', [
                'demoted_id' => $demotedId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
