<?php

namespace App\Enums;

/**
 * Roles de un agente de aprovisionamiento.
 *
 * El rol es la unidad de autorización del canal M2M: determina qué tipos de
 * tarea puede reclamar un agente y, por tanto, a qué secretos llega a ver.
 * Un `provisioner` jamás recibe claves del servidor WireGuard y un `vpn_host`
 * jamás recibe credenciales de un router.
 */
enum AgentRole: string
{
    case PROVISIONER = 'provisioner';
    case VPN_HOST    = 'vpn_host';

    public function label(): string
    {
        return match ($this) {
            self::PROVISIONER => 'Agente de aprovisionamiento (oficina)',
            self::VPN_HOST    => 'Agente de VPN (hosting)',
        };
    }

    /**
     * Tipos de tarea que este rol puede reclamar y ejecutar.
     *
     * @return list<ProvisioningTaskType>
     */
    public function allowedTaskTypes(): array
    {
        return match ($this) {
            self::PROVISIONER => [
                ProvisioningTaskType::IDENTIFY_DEVICE,
                ProvisioningTaskType::APPLY_ROUTER_VPN,
                ProvisioningTaskType::VERIFY_ROUTER_VPN,
                ProvisioningTaskType::HARDEN_ROUTER,
                ProvisioningTaskType::ROLLBACK_ROUTER_VPN,
            ],
            self::VPN_HOST => [
                ProvisioningTaskType::APPLY_HOST_PEER,
                ProvisioningTaskType::VERIFY_HOST_PEER,
                ProvisioningTaskType::ROLLBACK_HOST_PEER,
            ],
        };
    }

    public function allows(ProvisioningTaskType $type): bool
    {
        return in_array($type, $this->allowedTaskTypes(), true);
    }
}
