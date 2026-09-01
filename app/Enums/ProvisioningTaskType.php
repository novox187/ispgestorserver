<?php

namespace App\Enums;

/**
 * Tipos de tarea que el servidor encola para que un agente los reclame.
 *
 * Cada tipo `apply_*` declara su compensación: cuando la saga falla después de
 * haberlo aplicado, se encola la tarea de reversión correspondiente. Los pasos
 * `identify_*` y `verify_*` son de solo lectura y no compensan nada.
 */
enum ProvisioningTaskType: string
{
    case IDENTIFY_DEVICE      = 'identify_device';
    case APPLY_ROUTER_VPN     = 'apply_router_vpn';
    case APPLY_HOST_PEER      = 'apply_host_peer';
    case VERIFY_ROUTER_VPN    = 'verify_router_vpn';
    case VERIFY_HOST_PEER     = 'verify_host_peer';
    case HARDEN_ROUTER        = 'harden_router';
    case ROLLBACK_ROUTER_VPN  = 'rollback_router_vpn';
    case ROLLBACK_HOST_PEER   = 'rollback_host_peer';

    public function label(): string
    {
        return match ($this) {
            self::IDENTIFY_DEVICE     => 'Identificar dispositivo',
            self::APPLY_ROUTER_VPN    => 'Configurar VPN en el router',
            self::APPLY_HOST_PEER     => 'Registrar peer en el hosting',
            self::VERIFY_ROUTER_VPN   => 'Verificar túnel desde el router',
            self::VERIFY_HOST_PEER    => 'Verificar túnel desde el hosting',
            self::HARDEN_ROUTER       => 'Rotar credenciales y cerrar la API',
            self::ROLLBACK_ROUTER_VPN => 'Revertir VPN del router',
            self::ROLLBACK_HOST_PEER  => 'Revertir peer del hosting',
        };
    }

    /**
     * Tarea que deshace lo aplicado por esta, o null si no aplicó nada.
     *
     * `HARDEN_ROUTER` no declara compensación propia a propósito: la reversión
     * del router (`ROLLBACK_ROUTER_VPN`) ya borra el usuario dedicado y las
     * reglas de firewall además de la interfaz, y es idempotente. Apilar dos
     * compensaciones para el mismo extremo solo abriría la puerta a revertir a
     * medias.
     */
    public function compensation(): ?self
    {
        return match ($this) {
            self::APPLY_ROUTER_VPN => self::ROLLBACK_ROUTER_VPN,
            self::APPLY_HOST_PEER  => self::ROLLBACK_HOST_PEER,
            default                => null,
        };
    }

    public function isRollback(): bool
    {
        return in_array($this, [self::ROLLBACK_ROUTER_VPN, self::ROLLBACK_HOST_PEER], true);
    }

    /**
     * Presupuesto de tiempo antes de dar la tarea por vencida. Aplicar tarda
     * más que leer, y el rollback debe ser generoso para no dejar residuos.
     */
    public function defaultTimeoutSeconds(): int
    {
        return match ($this) {
            self::IDENTIFY_DEVICE                            => 120,
            self::APPLY_ROUTER_VPN, self::APPLY_HOST_PEER    => 180,
            // La verificación espera a que haya handshake, que con keepalive de
            // 25 s puede tardar; por eso no es el paso más corto.
            self::VERIFY_ROUTER_VPN, self::VERIFY_HOST_PEER  => 120,
            self::HARDEN_ROUTER                              => 120,
            self::ROLLBACK_ROUTER_VPN, self::ROLLBACK_HOST_PEER => 180,
        };
    }
}
