<?php

namespace App\Notifications\Core\Jobs;

use App\Models\NotificationLog;
use App\Notifications\Core\ChannelRegistry;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Enums\NotificationStatus;
use App\Notifications\Core\Exceptions\TransientChannelException;
use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;
use App\Notifications\Messages\MetaFailureNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker que entrega una notificación a un canal específico.
 *
 * Reintentos exponenciales declarados en `$backoff`. La última excepción no
 * capturada marca el log como `exhausted` desde `failed()` y emite la meta-alerta
 * configurada en `notifications.meta_failure_notification`.
 */
class SendNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $timeout = 30;

    public function __construct(
        public int   $logId,
        public array $payload,
        public array $recipient,
    ) {
        $this->tries = (int) config('notifications.retry.max_attempts', 5);
    }

    /**
     * Backoff exponencial (en segundos). Laravel toma el array y aplica un valor
     * por cada reintento.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $configured = config('notifications.retry.backoff_seconds', [10, 30, 90, 270, 600]);
        return array_map('intval', is_array($configured) ? $configured : [60]);
    }

    public function handle(ChannelRegistry $registry): void
    {
        $log = NotificationLog::find($this->logId);
        if (!$log) {
            Log::warning('SendNotificationJob: NotificationLog no encontrado, job descartado.', [
                'log_id' => $this->logId,
            ]);
            return;
        }

        $message   = NotificationMessage::fromArray($this->payload);
        $recipient = ChannelRecipient::fromArray($this->recipient);

        $log->incrementAttempts();

        try {
            $channel = $registry->get($recipient->channelKey);
        } catch (\App\Notifications\Core\Exceptions\ChannelNotConfiguredException $e) {
            $log->markFailed(
                "channel '{$recipient->channelKey}' no está registrado en config/notifications.php: " . $e->getMessage()
            );
            return;
        }

        if (!$channel->isEnabled()) {
            $log->markFailed(
                "channel '{$recipient->channelKey}' está deshabilitado o le faltan credenciales en BD "
                . '(notification_channel_configs)'
            );
            return;
        }

        if (!$channel->supports($message)) {
            $log->markFailed("channel '{$recipient->channelKey}' does not support this message");
            return;
        }

        $result = $channel->send($message, $recipient);

        if ($result->success) {
            $log->markSent($result->externalId);
            return;
        }

        if ($result->shouldRetry && $this->attempts() < $this->tries) {
            // Conservar el último error para diagnóstico en el log
            $log->forceFill(['last_error' => $result->error])->save();
            throw new TransientChannelException(
                "Channel [{$channel->key()}] reported transient failure: {$result->error}"
            );
        }

        $log->markFailed($result->error ?? 'unknown failure');
    }

    /**
     * Invocado por Laravel cuando el job agota todos los reintentos.
     */
    public function failed(?Throwable $exception): void
    {
        $log = NotificationLog::find($this->logId);
        $error = $exception?->getMessage() ?? 'unknown failure';

        if ($log && $log->status !== NotificationStatus::FAILED->value) {
            $log->markExhausted($error);
        }

        if (!(bool) config('notifications.meta_failure_notification', true)) {
            return;
        }

        // Emitir meta-alerta sobre el fallo persistente. Si la propia meta-alerta
        // falla, queda solo en el log de Laravel — no se reintenta para evitar
        // recursión infinita.
        try {
            $meta = MetaFailureNotification::build(
                category:  $this->payload['category'] ?? 'unknown',
                channel:   $this->recipient['channel'] ?? 'unknown',
                recipient: $this->recipient['address'] ?? 'unknown',
                title:     $this->payload['title'] ?? '',
                error:     $error,
            );

            // Llamada directa al dispatcher para evitar dependencia circular en construcción.
            app(\App\Notifications\Core\NotificationDispatcher::class)->dispatch($meta);
        } catch (Throwable $metaEx) {
            Log::error('SendNotificationJob: meta-alerta también falló — solo registro local.', [
                'log_id'           => $this->logId,
                'original_error'   => $error,
                'meta_error'       => $metaEx->getMessage(),
            ]);
        }
    }
}
