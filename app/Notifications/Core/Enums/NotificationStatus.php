<?php

namespace App\Notifications\Core\Enums;

/**
 * Estado de una fila de notification_logs en su ciclo de vida.
 *
 * pending    → encolada, aún no enviada.
 * sent       → enviada con éxito al canal externo.
 * failed     → error no reintentable (chat_id inválido, etc.); no se vuelve a intentar.
 * duplicated → suprimida por el deduplicador (registrada como rastro pero no enviada).
 * exhausted  → agotó los reintentos exponenciales sin éxito.
 */
enum NotificationStatus: string
{
    case PENDING    = 'pending';
    case SENT       = 'sent';
    case FAILED     = 'failed';
    case DUPLICATED = 'duplicated';
    case EXHAUSTED  = 'exhausted';
}
