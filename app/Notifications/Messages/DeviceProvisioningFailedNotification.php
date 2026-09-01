<?php

namespace App\Notifications\Messages;

use App\Models\DeviceProvisioningSession;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: un alta falló.
 *
 * El dato que más importa aquí no es el error sino si la reversión dejó
 * residuo. Una compensación que no se pudo ejecutar deja una interfaz WireGuard
 * huérfana en el router o un peer fantasma en el hosting, y eso exige que
 * alguien vaya a limpiarlo a mano. El mensaje lo dice explícitamente y con los
 * comandos concretos, en vez de dejarlo enterrado en el log.
 */
class DeviceProvisioningFailedNotification
{
    public static function build(
        DeviceProvisioningSession $session,
        string $errorCode,
        string $errorMessage,
        bool $rolledBack,
    ): NotificationMessage {
        $device = $session->identity
            ?: $session->board_name
            ?: ($session->mac_address ?? "sesión #{$session->id}");

        $residue = self::pendingCleanup($session);

        $body = "*Equipo:* `{$device}`\n"
            . ($session->board_name ? "*Modelo:* {$session->board_name}"
                . " · RouterOS " . ($session->routeros_version ?? '?') . "\n" : '')
            . ($session->mac_address ? "*MAC:* `{$session->mac_address}`\n" : '')
            . "*Detectado por:* " . ($session->agent?->name ?? 'agente desconocido') . "\n"
            . "*Sesión:* #{$session->id}\n"
            . "*Código:* `{$errorCode}`\n"
            . "*Detalle:* " . substr($errorMessage, 0, 400) . "\n\n"
            . ($rolledBack
                ? "*Reversión:* ejecutada.\n"
                : "*Reversión:* no hizo falta — el fallo ocurrió antes de modificar nada.\n");

        if ($residue !== []) {
            $body .= "\n🚨 *Quedó configuración sin revertir.* Hay que limpiarla a mano:\n";
            foreach ($residue as $line) {
                $body .= "• {$line}\n";
            }
        }

        $body .= "\n*Diagnóstico:*\n"
            . "1. Revisa la traza completa: `storage/logs/provisioning-*.log`, sesión {$session->id}.\n"
            . "2. Historial del alta en Auditoría, tabla `device_provisioning`, registro {$session->id}.\n"
            . "3. Comprueba que ambos agentes siguen reportando en MikroTik → Agentes.";

        return new NotificationMessage(
            category: NotificationCategory::DEVICE_PROVISION_FAILED,
            severity: NotificationSeverity::CRITICAL,
            title:    "Alta fallida: {$device}",
            body:     $body,
            context:  [
                'session_id'       => $session->id,
                'error_code'       => $errorCode,
                'error_message'    => $errorMessage,
                'rolled_back'      => $rolledBack,
                'manual_cleanup'   => $residue,
                'device'           => $device,
                'board_name'       => $session->board_name,
                'routeros_version' => $session->routeros_version,
                'mac_address'      => $session->mac_address,
                'vpn_assigned_ip'  => $session->vpn_assigned_ip,
                'failed_at'        => now()->toIso8601String(),
            ],
            dedupeKey: "provisioning:failed:{$session->id}",
        );
    }

    /**
     * Compensaciones que quedaron en la pila sin ejecutarse. Se traducen a la
     * acción manual concreta que hay que hacer en cada extremo.
     *
     * @return list<string>
     */
    private static function pendingCleanup(DeviceProvisioningSession $session): array
    {
        $lines = [];

        foreach ($session->compensations ?? [] as $entry) {
            $iface = $entry['payload']['spec']['interface_name'] ?? 'wg-ispgestor';

            $lines[] = match ($entry['type'] ?? '') {
                'rollback_router_vpn' => "En el router: eliminar la interfaz `{$iface}`, "
                    . "su dirección IP y el usuario `ispgestor-api`.",
                'rollback_host_peer'  => "En el hosting: `wg set {$iface} peer "
                    . "<clave> remove` y quitar el bloque del fichero de configuración "
                    . "(IP `" . ($session->vpn_assigned_ip ?? '?') . "`).",
                default => "Compensación pendiente sin identificar: "
                    . ($entry['type'] ?? 'desconocida') . '.',
            };
        }

        return $lines;
    }
}
