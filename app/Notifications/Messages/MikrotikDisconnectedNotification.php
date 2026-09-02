<?php

namespace App\Notifications\Messages;

use App\Models\NetworkDevice;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: construye un NotificationMessage para la alerta de pérdida de
 * conectividad con un equipo monitoreado.
 *
 * Acepta cualquier `NetworkDevice` —router MikroTik o antena Ubiquiti— porque el
 * monitor dejó de ser específico de un fabricante. La categoría de notificación
 * conserva su nombre histórico (`MIKROTIK_CONNECTIVITY`) a propósito: su valor
 * está persistido en las filas de `notification_event_routes` que el cliente ya
 * tiene configuradas, y renombrarlo le rompería el enrutado de sus alertas a
 * cambio de nada.
 *
 * El dedupeKey se basa en el id del equipo para evitar repetir la alerta
 * mientras dura el incidente (TTL configurado en notifications.deduplication).
 */
class MikrotikDisconnectedNotification
{
    public static function build(NetworkDevice $router, string $errorDetail, ?\DateTimeInterface $lastConnectedAt = null): NotificationMessage
    {
        $now    = now();
        $vendor = $router->vendor?->label() ?? 'Dispositivo';

        $body = "*Dispositivo:* `{$router->name}` (#{$router->id})\n"
            . "*Host:* `{$router->host}:{$router->port}`\n"
            . (filled($router->description) ? "*Descripción:* {$router->description}\n" : '')
            . "*Detectado:* " . $now->toIso8601String() . "\n"
            . "*Último contacto exitoso:* " . ($lastConnectedAt?->format('c') ?? 'desconocido') . "\n"
            . "*Error técnico:* `" . substr($errorDetail, 0, 240) . "`\n\n"
            . "*Pasos de diagnóstico sugeridos:*\n"
            . "1. Verificar alcance por ICMP al host.\n"
            . "2. Confirmar credenciales y que el servicio API esté habilitado.\n"
            . "3. Revisar firewall del servidor (puerto destino) y del router.\n"
            . "4. Si el router responde por consola, revisar carga de CPU y memoria.";

        return new NotificationMessage(
            category:   NotificationCategory::MIKROTIK_CONNECTIVITY,
            severity:   NotificationSeverity::CRITICAL,
            title:      "{$vendor} {$router->name} desconectado",
            body:       $body,
            context:    [
                'router_id'   => $router->id,
                'vendor'      => $router->vendor?->value,
                'name'        => $router->name,
                'host'        => $router->host,
                'port'        => $router->port,
                'description' => $router->description,
                'detected_at' => $now->toIso8601String(),
                'error'       => $errorDetail,
                'last_connected_at' => $lastConnectedAt?->format('c'),
            ],
            dedupeKey:  "mikrotik:disconnected:{$router->id}",
        );
    }
}
