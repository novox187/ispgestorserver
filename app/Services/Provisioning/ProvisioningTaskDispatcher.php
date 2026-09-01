<?php

namespace App\Services\Provisioning;

use App\Enums\AgentRole;
use App\Enums\ProvisioningTaskStatus;
use App\Enums\ProvisioningTaskType;
use App\Models\DeviceProvisioningSession;
use App\Models\ProvisioningAgent;
use App\Models\ProvisioningTask;
use RuntimeException;

/**
 * Encola una tarea para el agente que corresponde.
 *
 * Elegir destinatario es una decisión de seguridad, no solo de enrutado: es lo
 * que garantiza que las credenciales de un router solo lleguen al agente de la
 * oficina y que las claves del servidor WireGuard solo lleguen al del hosting.
 * Por eso la comprobación de rol se hace aquí, en el momento de crear la tarea,
 * y se repite en el `claim` — un payload mal dirigido no debe llegar siquiera a
 * escribirse en la base de datos.
 */
class ProvisioningTaskDispatcher
{
    /**
     * Encola una tarea para el agente `provisioner` que abrió la sesión.
     *
     * Se usa siempre el mismo agente que detectó el equipo: es el único que
     * tiene el router al alcance por cable.
     */
    public function toProvisioner(
        DeviceProvisioningSession $session,
        ProvisioningTaskType $type,
        array $payload,
    ): ProvisioningTask {
        $agent = $session->agent;

        if ($agent === null || !$agent->is_active) {
            throw new RuntimeException(
                'El agente de aprovisionamiento que detectó el equipo ya no está disponible.'
            );
        }

        return $this->create($session, $agent, $type, $payload);
    }

    /**
     * Encola una tarea para el agente `vpn_host` del hosting.
     */
    public function toVpnHost(
        DeviceProvisioningSession $session,
        ProvisioningTaskType $type,
        array $payload,
    ): ProvisioningTask {
        $agent = ProvisioningAgent::activeVpnHost();

        if ($agent === null) {
            throw new RuntimeException(
                'No hay ningún agente de VPN activo en el hosting: no se puede configurar el túnel.'
            );
        }

        return $this->create($session, $agent, $type, $payload);
    }

    public function forRole(
        DeviceProvisioningSession $session,
        AgentRole $role,
        ProvisioningTaskType $type,
        array $payload,
    ): ProvisioningTask {
        return $role === AgentRole::VPN_HOST
            ? $this->toVpnHost($session, $type, $payload)
            : $this->toProvisioner($session, $type, $payload);
    }

    /**
     * Rol que debe ejecutar un tipo de tarea. Lo usa el rollback para saber a
     * quién mandar cada compensación sin tener que recordarlo en la pila.
     */
    public function roleFor(ProvisioningTaskType $type): AgentRole
    {
        return AgentRole::VPN_HOST->allows($type)
            ? AgentRole::VPN_HOST
            : AgentRole::PROVISIONER;
    }

    private function create(
        DeviceProvisioningSession $session,
        ProvisioningAgent $agent,
        ProvisioningTaskType $type,
        array $payload,
    ): ProvisioningTask {
        if (!$agent->canExecute($type)) {
            throw new RuntimeException(
                "El agente '{$agent->name}' (rol {$agent->role->value}) no puede ejecutar "
                . "tareas de tipo '{$type->value}'."
            );
        }

        return ProvisioningTask::create([
            'session_id' => $session->id,
            'agent_id'   => $agent->id,
            'type'       => $type,
            'payload'    => $payload,
            'status'     => ProvisioningTaskStatus::PENDING,
            'expires_at' => now()->addSeconds($type->defaultTimeoutSeconds() * 2),
        ]);
    }
}
