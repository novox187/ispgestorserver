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
    case MONITOR     = 'monitor';

    public function label(): string
    {
        return match ($this) {
            self::PROVISIONER => 'Agente de aprovisionamiento (oficina)',
            self::VPN_HOST    => 'Agente de VPN (hosting)',
            self::MONITOR     => 'Agente de monitoreo (sondea el parque)',
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
            /*
             * El monitor no reclama ninguna tarea de la saga: su trabajo es leer
             * el estado del parque y empujar muestras, en un carril propio.
             *
             * Es un rol aparte y no una capacidad más del `provisioner` porque
             * ese corre un único bucle de 3 segundos que atiende MNDP, el carrier
             * de la NIC y la cola de tareas. Sondear cientos de antenas por HTTPS
             * son minutos por vuelta, y meterlo ahí degradaría el alta automática
             * de routers como efecto colateral del monitoreo. Separarlo permite
             * además desplegarlo en una torre sin darle capacidad de tocar la
             * configuración de ningún equipo.
             */
            self::MONITOR => [],
        };
    }

    public function allows(ProvisioningTaskType $type): bool
    {
        return in_array($type, $this->allowedTaskTypes(), true);
    }
}
