<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\ClientServiceInterruption;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Registra automáticamente las ventanas de corte del servicio.
 *
 * Cuando un cliente pasa a un estado no facturable (suspendido/cancelado) se
 * abre una interrupción con su fecha de corte; cuando sale de esos estados se
 * cierran las ventanas abiertas con la fecha de reactivación. Al vivir en
 * eventos de Eloquent, CUALQUIER vía que cambie service_status mediante save()
 * (servicios, controladores, cambios manuales en tinker) queda registrada, y
 * la facturación puede usar estas fechas como límite de emisión.
 *
 * Los flujos de negocio pueden enriquecer el registro (razón, ejecutor,
 * factura origen) seteando Client::$serviceStatusChangeContext antes de save().
 *
 * Limitación conocida: los mass-updates (Client::where()->update()) no disparan
 * eventos de Eloquent; los flujos del sistema siempre usan save() sobre el modelo.
 */
class ClientServiceStatusObserver
{
    private const CUT_STATUSES = ['SUSPENDED', 'SUSPENDIDO', 'CANCELLED', 'CANCELADO'];

    public function created(Client $client): void
    {
        if ($this->isCutStatus($client->service_status)) {
            $this->openInterruption($client);
        }
    }

    public function updated(Client $client): void
    {
        if (!$client->wasChanged('service_status')) {
            return;
        }

        $wasCut = $this->isCutStatus($client->getOriginal('service_status'));
        $isCut  = $this->isCutStatus($client->service_status);

        if (!$wasCut && $isCut) {
            $this->openInterruption($client);
        } elseif ($wasCut && !$isCut) {
            $this->closeOpenInterruptions($client);
        } elseif ($wasCut && $isCut) {
            // p. ej. suspendido → cancelado: el corte sigue vigente; solo se
            // actualiza el tipo de la ventana abierta.
            $type = $this->typeFor($client->service_status);
            $client->serviceInterruptions()->open()->get()
                ->each(fn (ClientServiceInterruption $i) => $i->update(['type' => $type]));
            $client->serviceStatusChangeContext = null;
        }
    }

    private function openInterruption(Client $client): void
    {
        $context = $client->serviceStatusChangeContext ?? [];
        $client->serviceStatusChangeContext = null;

        // Idempotencia: un corte vigente no se duplica.
        if ($client->serviceInterruptions()->open()->exists()) {
            return;
        }

        $datos = [
            'client_id'         => $client->id,
            'type'              => $this->typeFor($client->service_status),
            'suspended_at'      => now(),
            'suspension_reason' => $context['reason'] ?? 'Cambio de estado del servicio',
            'suspended_by'      => $context['executor'] ?? $this->defaultExecutor(),
            'invoice_id'        => $context['invoice_id'] ?? null,
            'source'            => $context['source'] ?? 'status_change',
        ];

        // Solo los flujos que hablan con MikroTik saben si el corte llegó a
        // aplicarse. Un cambio de estado por otra vía lo deja en NULL, que
        // significa "sin verificar", no "sin efecto".
        //
        // El despliegue arranca el contenedor nuevo ANTES de migrar, así que
        // hay unos segundos de código nuevo sobre esquema viejo: sin esta
        // comprobación, cualquier corte en esa ventana moría con "Unknown
        // column". Es un puente de despliegue — se puede quitar cuando la
        // migración 2026_09_08_000001 esté aplicada en todos los entornos.
        if (self::supportsEnforcementState()) {
            $datos['enforcement_state'] = $context['enforcement_state'] ?? null;
        }

        ClientServiceInterruption::create($datos);
    }

    /**
     * Se resuelve una vez por proceso: `hasColumn` consulta information_schema
     * y esto corre en cada corte.
     */
    private static ?bool $enforcementStateSupported = null;

    private static function supportsEnforcementState(): bool
    {
        if (self::$enforcementStateSupported === null) {
            try {
                self::$enforcementStateSupported = Schema::hasColumn('client_service_interruptions', 'enforcement_state');
            } catch (\Throwable $e) {
                self::$enforcementStateSupported = false;
            }
        }

        return self::$enforcementStateSupported;
    }

    private function closeOpenInterruptions(Client $client): void
    {
        $context = $client->serviceStatusChangeContext ?? [];
        $client->serviceStatusChangeContext = null;

        // update() por modelo (no mass-update) para que Auditable registre el cierre.
        $client->serviceInterruptions()->open()->get()->each(
            fn (ClientServiceInterruption $i) => $i->update([
                'reactivated_at'      => now(),
                'reactivation_reason' => $context['reason'] ?? 'Cambio de estado del servicio',
                'reactivated_by'      => $context['executor'] ?? $this->defaultExecutor(),
            ])
        );
    }

    private function defaultExecutor(): string
    {
        $user = Auth::user();

        return $user ? class_basename($user) . ':' . $user->id : 'system';
    }

    private function isCutStatus(?string $status): bool
    {
        return in_array(strtoupper((string) $status), self::CUT_STATUSES, true);
    }

    private function typeFor(?string $status): string
    {
        return in_array(strtoupper((string) $status), ['CANCELLED', 'CANCELADO'], true)
            ? ClientServiceInterruption::TYPE_CANCELLATION
            : ClientServiceInterruption::TYPE_SUSPENSION;
    }
}
