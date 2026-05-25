<?php

namespace App\Notifications\Core\Messages;

use App\Notifications\Core\Enums\FormatHint;
use App\Notifications\Core\Enums\NotificationCategory;
use App\Notifications\Core\Enums\NotificationSeverity;
use Illuminate\Support\Str;

/**
 * Value object inmutable que representa una notificación a despachar.
 *
 * Las propiedades son readonly para garantizar que el mismo mensaje pueda
 * encolarse y reintentarse sin que mutaciones accidentales corrompan el
 * payload almacenado en `notification_logs.context`.
 */
final class NotificationMessage
{
    public readonly string $id;

    /**
     * @param array<string,mixed> $context     Payload estructurado con datos del evento.
     * @param array<int,array{type:string,url:string,caption?:string}> $attachments
     */
    public function __construct(
        public readonly NotificationCategory $category,
        public readonly NotificationSeverity $severity,
        public readonly string               $title,
        public readonly string               $body,
        public readonly array                $context = [],
        public readonly FormatHint           $formatHint = FormatHint::MARKDOWN,
        public readonly array                $attachments = [],
        public readonly ?string              $dedupeKey = null,
        ?string                              $id = null,
    ) {
        if ($this->title === '') {
            throw new \InvalidArgumentException('NotificationMessage title cannot be empty.');
        }
        if ($this->body === '') {
            throw new \InvalidArgumentException('NotificationMessage body cannot be empty.');
        }

        $this->id = $id ?? (string) Str::uuid();
    }

    /**
     * Calcula la clave de deduplicación, usando la explícita si fue provista o
     * construyéndola desde categoría + hash determinístico del contexto.
     */
    public function effectiveDedupeKey(): string
    {
        if ($this->dedupeKey !== null && $this->dedupeKey !== '') {
            return $this->dedupeKey;
        }

        $hash = substr(md5(json_encode($this->context) . '|' . $this->title), 0, 16);
        return $this->category->value . ':' . $hash;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'category'     => $this->category->value,
            'severity'     => $this->severity->value,
            'title'        => $this->title,
            'body'         => $this->body,
            'context'      => $this->context,
            'format_hint'  => $this->formatHint->value,
            'attachments'  => $this->attachments,
            'dedupe_key'   => $this->effectiveDedupeKey(),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            category:    NotificationCategory::from($data['category']),
            severity:    NotificationSeverity::from($data['severity']),
            title:       $data['title'],
            body:        $data['body'],
            context:     $data['context'] ?? [],
            formatHint:  FormatHint::from($data['format_hint'] ?? 'markdown'),
            attachments: $data['attachments'] ?? [],
            dedupeKey:   $data['dedupe_key'] ?? null,
            id:          $data['id'] ?? null,
        );
    }
}
