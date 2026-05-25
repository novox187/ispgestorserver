<?php

namespace App\Models;

use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use App\Notifications\Core\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro histórico de cada envío realizado por el módulo de notificaciones.
 *
 * Cada fila representa un intento de entrega a un destinatario concreto en un canal.
 * Múltiples filas pueden compartir `notification_id` cuando un mismo NotificationMessage
 * se enrutó a varios destinatarios.
 *
 * @property int                       $id
 * @property string                    $notification_id
 * @property string                    $category
 * @property string                    $severity
 * @property string                    $channel
 * @property string                    $recipient
 * @property string                    $title
 * @property string                    $body
 * @property array|null                $context
 * @property array|null                $attachments
 * @property string                    $status
 * @property string|null               $dedupe_key
 * @property int                       $attempts
 * @property string|null               $external_id
 * @property string|null               $last_error
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class NotificationLog extends Model
{
    protected $fillable = [
        'notification_id',
        'category',
        'severity',
        'channel',
        'recipient',
        'title',
        'body',
        'context',
        'attachments',
        'status',
        'dedupe_key',
        'attempts',
        'external_id',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'context'     => 'array',
        'attachments' => 'array',
        'attempts'    => 'integer',
        'sent_at'     => 'datetime',
    ];

    public function categoryEnum(): NotificationCategory
    {
        return NotificationCategory::from($this->category);
    }

    public function severityEnum(): NotificationSeverity
    {
        return NotificationSeverity::from($this->severity);
    }

    public function statusEnum(): NotificationStatus
    {
        return NotificationStatus::from($this->status);
    }

    public function markSent(?string $externalId = null): void
    {
        $this->forceFill([
            'status'      => NotificationStatus::SENT->value,
            'external_id' => $externalId,
            'sent_at'     => now(),
            'last_error'  => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status'     => NotificationStatus::FAILED->value,
            'last_error' => $error,
        ])->save();
    }

    public function markExhausted(string $error): void
    {
        $this->forceFill([
            'status'     => NotificationStatus::EXHAUSTED->value,
            'last_error' => $error,
        ])->save();
    }

    public function incrementAttempts(): void
    {
        $this->forceFill(['attempts' => $this->attempts + 1])->save();
    }
}
