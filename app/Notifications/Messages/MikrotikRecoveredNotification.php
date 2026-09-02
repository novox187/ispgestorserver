<?php

namespace App\Notifications\Messages;

use App\Models\NetworkDevice;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: construye un NotificationMessage informativo cuando un equipo que
 * estaba marcado como `disconnected` vuelve a responder.
 *
 * Como su gemela de desconexión, acepta cualquier `NetworkDevice` y conserva el
 * nombre histórico de la categoría para no romper el enrutado ya configurado.
 */
class MikrotikRecoveredNotification
{
    public static function build(NetworkDevice $router, ?\DateTimeInterface $lastDisconnectedAt): NotificationMessage
    {
        $now      = now();
        $vendor   = $router->vendor?->label() ?? 'Dispositivo';
        $downtime = $lastDisconnectedAt
            ? $now->diffForHumans($lastDisconnectedAt, ['parts' => 2, 'short' => true])
            : 'desconocido';

        $body = "*Dispositivo:* `{$router->name}` (#{$router->id})\n"
            . "*Host:* `{$router->host}:{$router->port}`\n"
            . "*Recuperado:* " . $now->toIso8601String() . "\n"
            . "*Tiempo offline:* {$downtime}";

        return new NotificationMessage(
            category:   NotificationCategory::MIKROTIK_RECOVERY,
            severity:   NotificationSeverity::INFO,
            title:      "{$vendor} {$router->name} recuperó conectividad",
            body:       $body,
            context:    [
                'router_id'             => $router->id,
                'vendor'                => $router->vendor?->value,
                'name'                  => $router->name,
                'host'                  => $router->host,
                'recovered_at'          => $now->toIso8601String(),
                'last_disconnected_at'  => $lastDisconnectedAt?->format('c'),
            ],
            dedupeKey:  "mikrotik:recovered:{$router->id}",
        );
    }
}
