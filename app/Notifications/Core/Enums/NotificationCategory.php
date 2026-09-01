<?php

namespace App\Notifications\Core\Enums;

/**
 * Categorías de notificaciones soportadas por el módulo.
 *
 * Cada categoría tiene una severidad por defecto, usada cuando el productor no
 * especifica una. Las severidades por defecto pueden sobreescribirse al construir
 * el NotificationMessage.
 */
enum NotificationCategory: string
{
    case MIKROTIK_CONNECTIVITY = 'mikrotik_connectivity';
    case MIKROTIK_RECOVERY     = 'mikrotik_recovery';
    case DEVICE_PROVISIONED    = 'device_provisioned';
    case DEVICE_PROVISION_FAILED = 'device_provision_failed';
    case PROVISIONING_AGENT_OFFLINE = 'provisioning_agent_offline';
    case WORKER_SUMMARY        = 'worker_summary';
    case WORKER_FAILURE        = 'worker_failure';
    case SSL_EXPIRATION        = 'ssl_expiration';
    case RESOURCE_USAGE        = 'resource_usage';
    case DB_SYNC_FAILURE       = 'db_sync_failure';
    case SERVICE_HEALTH        = 'service_health';
    case INFO_TASK_COMPLETED   = 'info_task_completed';
    case META_FAILURE          = 'meta_failure';

    public function defaultSeverity(): NotificationSeverity
    {
        return match ($this) {
            self::MIKROTIK_CONNECTIVITY,
            self::WORKER_FAILURE,
            self::DB_SYNC_FAILURE,
            self::META_FAILURE,
            // Un alta fallida deja un equipo sin dar servicio y puede haber
            // requerido limpieza manual; un agente caído congela todas las
            // altas. Las dos exigen atención inmediata.
            self::DEVICE_PROVISION_FAILED,
            self::PROVISIONING_AGENT_OFFLINE => NotificationSeverity::CRITICAL,

            self::WORKER_SUMMARY,
            self::RESOURCE_USAGE,
            self::SERVICE_HEALTH     => NotificationSeverity::SUMMARY,

            self::MIKROTIK_RECOVERY,
            self::SSL_EXPIRATION,
            self::DEVICE_PROVISIONED,
            self::INFO_TASK_COMPLETED => NotificationSeverity::INFO,
        };
    }
}
