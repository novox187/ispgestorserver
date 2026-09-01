<?php

namespace App\Notifications\Messages;

use App\Enums\AgentRole;
use App\Models\ProvisioningAgent;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: un agente de aprovisionamiento dejó de reportar.
 *
 * Es crítico porque las consecuencias son distintas y ambas silenciosas: sin el
 * agente de la oficina no se detecta ningún equipo que se enchufe, y sin el del
 * hosting no se puede crear ningún peer. En los dos casos el sistema no falla —
 * simplemente deja de hacer altas sin decir nada.
 */
class ProvisioningAgentOfflineNotification
{
    public static function build(ProvisioningAgent $agent): NotificationMessage
    {
        $lastSeen = $agent->last_seen_at?->diffForHumans() ?? 'nunca';

        $consequence = match ($agent->role) {
            AgentRole::PROVISIONER => 'No se detectará ningún equipo que se conecte por cable.',
            AgentRole::VPN_HOST    => 'No se podrá dar de alta ningún equipo: ninguna sesión '
                . 'llegará a crear su peer en el hosting.',
        };

        $body = "*Agente:* `{$agent->name}` (#{$agent->id})\n"
            . "*Rol:* {$agent->role->label()}\n"
            . "*Último contacto:* {$lastSeen}"
                . ($agent->last_seen_at ? " (" . $agent->last_seen_at->toIso8601String() . ")" : '') . "\n"
            . "*Última IP:* `" . ($agent->last_ip ?? 'desconocida') . "`\n"
            . "*Versión:* " . ($agent->agent_version ?? 'desconocida') . "\n\n"
            . "*Consecuencia:* {$consequence}\n\n"
            . "*Pasos de diagnóstico sugeridos:*\n"
            . "1. En la máquina del agente: `systemctl status ispgestor-agent`.\n"
            . "2. Revisar su traza: `journalctl -u ispgestor-agent -n 100`.\n"
            . "3. Comprobar que alcanza la API: debe poder salir por HTTPS a este servidor.\n"
            . "4. Verificar que el agente no fue revocado desde MikroTik → Agentes.";

        return new NotificationMessage(
            category: NotificationCategory::PROVISIONING_AGENT_OFFLINE,
            severity: NotificationSeverity::CRITICAL,
            title:    "Agente de aprovisionamiento {$agent->name} sin conexión",
            body:     $body,
            context:  [
                'agent_id'      => $agent->id,
                'name'          => $agent->name,
                'role'          => $agent->role->value,
                'last_seen_at'  => $agent->last_seen_at?->toIso8601String(),
                'last_ip'       => $agent->last_ip,
                'agent_version' => $agent->agent_version,
                'detected_at'   => now()->toIso8601String(),
            ],
            dedupeKey: "provisioning:agent-offline:{$agent->id}",
        );
    }
}
