<?php

namespace App\Notifications\Messages;

use App\Models\MikrotikRouter;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: construye un NotificationMessage para la alerta de pérdida de
 * conectividad con un router MikroTik monitoreado.
 *
 * El dedupeKey se basa en el id del router para evitar repetir la alerta
 * mientras dura el incidente (TTL configurado en notifications.deduplication).
 */
class MikrotikDisconnectedNotification
{
    public static function build(MikrotikRouter $router, string $errorDetail, ?\DateTimeInterface $lastConnectedAt = null): NotificationMessage
    {
        $now = now();

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
            title:      "MikroTik {$router->name} desconectado",
            body:       $body,
            context:    [
                'router_id'   => $router->id,
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
