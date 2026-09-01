<?php

namespace App\Enums;

/**
 * Estados de una sesión de aprovisionamiento.
 *
 * Avance normal:
 *   detected → identifying → [awaiting_approval] → provisioning_router
 *            → provisioning_host → verifying → hardening → completed
 *
 * El endurecimiento va DESPUÉS de verificar y no antes: cierra la API del
 * router a la subred del túnel, y hacerlo mientras aún se verifica dejaría al
 * agente de la oficina sin poder comprobar nada desde la LAN.
 *
 * Desde cualquier estado activo se puede caer a `failed`; si ya se había
 * aplicado algo en algún extremo, la saga compensa y termina en `rolled_back`.
 */
enum ProvisioningStatus: string
{
    case DETECTED            = 'detected';
    case IDENTIFYING         = 'identifying';
    case AWAITING_APPROVAL   = 'awaiting_approval';
    case PROVISIONING_ROUTER = 'provisioning_router';
    case PROVISIONING_HOST   = 'provisioning_host';
    case VERIFYING           = 'verifying';
    case HARDENING           = 'hardening';
    case COMPLETED           = 'completed';
    case FAILED              = 'failed';
    case ROLLED_BACK         = 'rolled_back';
    case CANCELLED           = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DETECTED            => 'Dispositivo detectado',
            self::IDENTIFYING         => 'Identificando equipo',
            self::AWAITING_APPROVAL   => 'Esperando aprobación',
            self::PROVISIONING_ROUTER => 'Configurando VPN en el router',
            self::PROVISIONING_HOST   => 'Configurando VPN en el hosting',
            self::VERIFYING           => 'Verificando enlace',
            self::HARDENING           => 'Rotando credenciales del equipo',
            self::COMPLETED           => 'Completado',
            self::FAILED              => 'Fallido',
            self::ROLLED_BACK         => 'Revertido',
            self::CANCELLED           => 'Cancelado',
        };
    }

    /**
     * Estados terminales: la saga ya no los toca y no cuentan como sesión viva
     * al deduplicar una nueva detección del mismo equipo.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::ROLLED_BACK,
            self::CANCELLED,
        ], true);
    }

    public function isActive(): bool
    {
        return !$this->isTerminal();
    }

    /**
     * Posición en el stepper del panel (0-based). Los estados terminales
     * reutilizan el índice del paso en el que murieron.
     */
    public function stepIndex(): int
    {
        return match ($this) {
            self::DETECTED                              => 0,
            self::IDENTIFYING, self::AWAITING_APPROVAL  => 1,
            self::PROVISIONING_ROUTER                   => 2,
            self::PROVISIONING_HOST                     => 3,
            self::VERIFYING                             => 4,
            self::HARDENING                             => 5,
            self::COMPLETED                             => 6,
            self::FAILED, self::ROLLED_BACK, self::CANCELLED => 6,
        };
    }
}
