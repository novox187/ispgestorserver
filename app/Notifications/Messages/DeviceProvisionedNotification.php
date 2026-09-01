<?php

namespace App\Notifications\Messages;

use App\Models\DeviceProvisioningSession;
use App\Models\MikrotikRouter;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: un dispositivo conectado por cable quedó enlazado por VPN,
 * verificado y registrado.
 *
 * El dedupeKey va por sesión y no por router: re-aprovisionar un equipo es un
 * evento distinto del alta original y debe avisarse igualmente.
 */
class DeviceProvisionedNotification
{
    public static function build(
        DeviceProvisioningSession $session,
        MikrotikRouter $router,
    ): NotificationMessage {
        $elapsed = $session->started_at
            ? $session->started_at->diffForHumans(now(), ['syntax' => true, 'parts' => 2])
            : 'desconocido';

        $body = "*Equipo:* `{$router->name}` (#{$router->id})\n"
            . "*Modelo:* " . ($session->board_name ?? 'desconocido')
                . " · RouterOS " . ($session->routeros_version ?? '?') . "\n"
            . ($session->serial_number ? "*Serie:* `{$session->serial_number}`\n" : '')
            . ($session->mac_address ? "*MAC:* `{$session->mac_address}`\n" : '')
            . "*Detectado por:* " . ($session->agent?->name ?? 'agente desconocido')
                . " (`{$session->detection_method}`)\n"
            . "*Túnel:* `{$session->vpn_assigned_ip}` vía `{$session->vpn_endpoint}`\n"
            . "*Acceso:* `{$router->username}@{$router->host}:{$router->port}` "
                . "(credenciales de fábrica rotadas)\n"
            . "*Duración del alta:* {$elapsed}\n\n"
            . ($router->is_primary
                ? "⚠️ Este equipo ha quedado como *router principal* del sistema.\n\n"
                : '')
            . "*Queda pendiente de configurar a mano:* la subred de clientes "
            . "(`network_cidr`) y el gateway, que el alta automática no puede deducir.";

        return new NotificationMessage(
            category: NotificationCategory::DEVICE_PROVISIONED,
            severity: NotificationSeverity::INFO,
            title:    "Dispositivo {$router->name} dado de alta",
            body:     $body,
            context:  [
                'session_id'       => $session->id,
                'router_id'        => $router->id,
                'name'             => $router->name,
                'host'             => $router->host,
                'board_name'       => $session->board_name,
                'routeros_version' => $session->routeros_version,
                'serial_number'    => $session->serial_number,
                'mac_address'      => $session->mac_address,
                'vpn_assigned_ip'  => $session->vpn_assigned_ip,
                'vpn_endpoint'     => $session->vpn_endpoint,
                'is_primary'       => $router->is_primary,
                'detected_at'      => $session->started_at?->toIso8601String(),
                'completed_at'     => $session->completed_at?->toIso8601String(),
            ],
            dedupeKey: "provisioning:completed:{$session->id}",
        );
    }
}
