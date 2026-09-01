<?php

namespace App\Enums;

/**
 * Estados de una tarea encolada para un agente.
 *
 * `claimed` es el estado en el que vive una tarea mientras el agente la
 * ejecuta. Si vence sin reporte pasa a `expired`, lo que la saga trata igual
 * que un fallo (un agente que murió a mitad de aplicar deja el mismo residuo
 * que uno que devolvió error).
 */
enum ProvisioningTaskStatus: string
{
    case PENDING   = 'pending';
    case CLAIMED   = 'claimed';
    case SUCCEEDED = 'succeeded';
    case FAILED    = 'failed';
    case EXPIRED   = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::SUCCEEDED, self::FAILED, self::EXPIRED], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::FAILED, self::EXPIRED], true);
    }
}
