<?php

namespace App\Notifications\Core\Enums;

/**
 * Nivel de severidad de una notificación.
 *
 * - CRITICAL: requiere intervención inmediata (MikroTik desconectado, worker caído).
 * - SUMMARY:  resumen de operaciones automatizadas o métricas periódicas.
 * - INFO:     avisos informativos rutinarios.
 */
enum NotificationSeverity: string
{
    case CRITICAL = 'critical';
    case SUMMARY  = 'summary';
    case INFO     = 'info';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Crítica',
            self::SUMMARY  => 'Resumen',
            self::INFO     => 'Informativa',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CRITICAL => '🔴',
            self::SUMMARY  => '📊',
            self::INFO     => 'ℹ️',
        };
    }
}
