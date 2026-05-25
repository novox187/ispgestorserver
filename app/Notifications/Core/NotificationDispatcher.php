<?php

namespace App\Notifications\Core;

use App\Models\NotificationLog;
use App\Notifications\Core\Enums\NotificationStatus;
use App\Notifications\Core\Jobs\SendNotificationJob;
use App\Notifications\Core\Messages\NotificationMessage;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta el flujo completo de una notificación:
 *
 *   1. Deduplica vía Deduplicator (registra fila status=duplicated si aplica).
 *   2. Resuelve destinatarios vía NotificationRouter.
 *   3. Inserta una fila NotificationLog (status=pending) por cada destinatario.
 *   4. Encola un SendNotificationJob por cada fila.
 *
 * Esta clase es el único punto de entrada al módulo desde el resto de la app.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly NotificationRouter $router,
        private readonly Deduplicator       $deduplicator,
    ) {
    }

    /**
     * Despacha una notificación. Retorna la colección de logs creados para que
     * el caller pueda inspeccionar el resultado en tests.
     *
     * @return array<int, NotificationLog>
     */
    public function dispatch(NotificationMessage $message): array
    {
        $dedupeKey = $message->effectiveDedupeKey();

        if (!$this->deduplicator->tryAcquire($dedupeKey, $message->category)) {
            $log = NotificationLog::create([
                'notification_id' => $message->id,
                'category'        => $message->category->value,
                'severity'        => $message->severity->value,
                'channel'         => 'n/a',
                'recipient'       => 'n/a',
                'title'           => $message->title,
                'body'            => $message->body,
                'context'         => $message->context,
                'attachments'     => $message->attachments,
                'status'          => NotificationStatus::DUPLICATED->value,
                'dedupe_key'      => $dedupeKey,
                'last_error'      => 'suppressed by deduplicator',
            ]);

            Log::info('NotificationDispatcher: mensaje deduplicado.', [
                'category'   => $message->category->value,
                'dedupe_key' => $dedupeKey,
            ]);

            return [$log];
        }

        $recipients = $this->router->resolve($message);

        if (empty($recipients)) {
            $log = NotificationLog::create([
                'notification_id' => $message->id,
                'category'        => $message->category->value,
                'severity'        => $message->severity->value,
                'channel'         => 'n/a',
                'recipient'       => 'n/a',
                'title'           => $message->title,
                'body'            => $message->body,
                'context'         => $message->context,
                'attachments'     => $message->attachments,
                'status'          => NotificationStatus::FAILED->value,
                'dedupe_key'      => $dedupeKey,
                'last_error'      => "sin destinatarios para categoría '{$message->category->value}': "
                                     . 'configure rutas en notification_event_routes con address_override '
                                     . 'o un default_address en notification_channel_configs',
            ]);

            Log::warning('NotificationDispatcher: sin destinatarios resueltos.', [
                'category' => $message->category->value,
                'severity' => $message->severity->value,
            ]);

            return [$log];
        }

        $logs = [];
        $connection = config('notifications.queue.connection');
        $queueName  = config('notifications.queue.name', 'notifications');

        foreach ($recipients as $recipient) {
            $log = NotificationLog::create([
                'notification_id' => $message->id,
                'category'        => $message->category->value,
                'severity'        => $message->severity->value,
                'channel'         => $recipient->channelKey,
                'recipient'       => $recipient->address,
                'title'           => $message->title,
                'body'            => $message->body,
                'context'         => $message->context,
                'attachments'     => $message->attachments,
                'status'          => NotificationStatus::PENDING->value,
                'dedupe_key'      => $dedupeKey,
            ]);

            $job = (new SendNotificationJob(
                logId:      $log->id,
                payload:    $message->toArray(),
                recipient:  $recipient->toArray(),
            ))->onQueue($queueName);

            if ($connection) {
                $job->onConnection($connection);
            }

            dispatch($job);
            $logs[] = $log;
        }

        return $logs;
    }
}
