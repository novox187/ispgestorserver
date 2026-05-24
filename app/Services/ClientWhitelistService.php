<?php

namespace App\Services;

use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientWhitelist;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class ClientWhitelistService
{
    /**
     * Comprueba si el cliente está protegido por la lista blanca.
     *
     * Esta es la única vía recomendada para consultar la regla de protección
     * antes de cualquier proceso de suspensión automática o manual.
     */
    public function isProtected(int $clientId): bool
    {
        return ClientWhitelist::query()
            ->where('client_id', $clientId)
            ->active()
            ->exists();
    }

    /**
     * Recupera la inclusión vigente del cliente (o null si no está protegido).
     */
    public function activeEntryFor(int $clientId): ?ClientWhitelist
    {
        return ClientWhitelist::query()
            ->with('authorizedBy:id,nombre,email')
            ->where('client_id', $clientId)
            ->active()
            ->latest('added_at')
            ->first();
    }

    /**
     * Agrega un cliente a la lista blanca.
     *
     * @param  Client            $client       Cliente a proteger.
     * @param  Employee          $authorizedBy Empleado super_admin que autoriza.
     * @param  string            $reason       Motivo de la inclusión.
     * @param  Carbon|null       $expiresAt    Fecha de vencimiento opcional.
     * @param  string|null       $ipAddress    IP del request para auditoría.
     * @return ClientWhitelist                 Registro creado.
     *
     * @throws \DomainException Si ya existe una inclusión vigente para el cliente.
     */
    public function addClient(
        Client $client,
        Employee $authorizedBy,
        string $reason,
        ?CarbonInterface $expiresAt = null,
        ?string $ipAddress = null,
    ): ClientWhitelist {
        return DB::transaction(function () use ($client, $authorizedBy, $reason, $expiresAt, $ipAddress) {
            $existing = ClientWhitelist::where('client_id', $client->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new \DomainException(
                    "El cliente {$client->id} ya está en la lista blanca (registro #{$existing->id})."
                );
            }

            $entry = ClientWhitelist::create([
                'client_id'     => $client->id,
                'added_at'      => now(),
                'authorized_by' => $authorizedBy->id,
                'reason'        => $reason,
                'expires_at'    => $expiresAt,
                'active'        => true,
            ]);

            $this->logAudit(
                operation: 'WHITELIST_ADD',
                entry: $entry,
                old: null,
                authorizedBy: $authorizedBy,
                ipAddress: $ipAddress,
            );

            return $entry->fresh(['authorizedBy', 'client']);
        });
    }

    /**
     * Retira un cliente de la lista blanca (baja lógica para preservar historial).
     *
     * @throws \DomainException Si no hay una inclusión vigente para el cliente.
     */
    public function removeClient(
        Client $client,
        Employee $authorizedBy,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): ClientWhitelist {
        return DB::transaction(function () use ($client, $authorizedBy, $reason, $ipAddress) {
            $entry = ClientWhitelist::where('client_id', $client->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if (!$entry) {
                throw new \DomainException(
                    "El cliente {$client->id} no tiene una inclusión vigente en la lista blanca."
                );
            }

            $old = $entry->only(['active', 'reason', 'expires_at']);

            $entry->update([
                'active' => false,
                'reason' => $reason
                    ? $entry->reason . " | Retirado: {$reason}"
                    : $entry->reason,
            ]);

            $this->logAudit(
                operation: 'WHITELIST_REMOVE',
                entry: $entry,
                old: $old,
                authorizedBy: $authorizedBy,
                ipAddress: $ipAddress,
            );

            return $entry->fresh(['authorizedBy', 'client']);
        });
    }

    /**
     * Actualiza el motivo o la fecha de vencimiento de una inclusión vigente.
     */
    public function updateEntry(
        ClientWhitelist $entry,
        array $changes,
        Employee $authorizedBy,
        ?string $ipAddress = null,
    ): ClientWhitelist {
        return DB::transaction(function () use ($entry, $changes, $authorizedBy, $ipAddress) {
            $allowed = collect($changes)->only(['reason', 'expires_at'])->toArray();
            $old = $entry->only(array_keys($allowed));

            $entry->update($allowed);

            $this->logAudit(
                operation: 'WHITELIST_UPDATE',
                entry: $entry,
                old: $old,
                authorizedBy: $authorizedBy,
                ipAddress: $ipAddress,
            );

            return $entry->fresh(['authorizedBy', 'client']);
        });
    }

    /**
     * Registra en la tabla `audits` la operación realizada sobre la lista blanca.
     */
    private function logAudit(
        string $operation,
        ClientWhitelist $entry,
        ?array $old,
        Employee $authorizedBy,
        ?string $ipAddress,
    ): void {
        Audit::create([
            'table_name' => 'client_whitelists',
            'operation'  => $operation,
            'record_id'  => (string) $entry->id,
            'old_values' => $old,
            'new_values' => [
                'id'            => $entry->id,
                'client_id'     => $entry->client_id,
                'authorized_by' => $entry->authorized_by,
                'reason'        => $entry->reason,
                'expires_at'    => optional($entry->expires_at)->toIso8601String(),
                'active'        => $entry->active,
                'added_at'      => optional($entry->added_at)->toIso8601String(),
                'timestamp'     => now()->toIso8601String(),
            ],
            'user_id'    => $authorizedBy->id,
            'user_type'  => Employee::class,
            'ip_address' => $ipAddress,
        ]);
    }
}
