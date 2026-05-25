<?php

namespace App\Notifications\Messages;

use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Factory: alerta de meta-fallo emitida cuando un envío agota todos los reintentos.
 *
 * Usa categoría dedicada META_FAILURE para no enmascarar el conteo de fallos
 * reales del worker original y para permitir que un router de canal alternativo
 * la trate diferente si se desea.
 */
class MetaFailureNotification
{
    public static function build(string $category, string $channel, string $recipient, string $title, string $error): NotificationMessage
    {
        $body = "*Falló el envío persistente de una notificación.*\n\n"
            . "*Categoría original:* `{$category}`\n"
            . "*Canal:* `{$channel}`\n"
            . "*Destinatario:* `{$recipient}`\n"
            . "*Título original:* `" . substr($title, 0, 120) . "`\n"
            . "*Último error:* `" . substr($error, 0, 240) . "`\n\n"
            . "Revisar credenciales, conectividad y permisos del canal.";

        return new NotificationMessage(
            category:   NotificationCategory::META_FAILURE,
            severity:   NotificationSeverity::CRITICAL,
            title:      "🛑 Notificación no entregable",
            body:       $body,
            context:    [
                'original_category'  => $category,
                'channel'            => $channel,
                'recipient'          => $recipient,
                'original_title'     => $title,
                'last_error'         => $error,
            ],
            dedupeKey:  "meta:failure:{$channel}:{$recipient}",
        );
    }
}
