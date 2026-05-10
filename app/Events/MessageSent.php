<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;

    public function __construct(Message $message, string $senderType = 'user')
    {
        $message->loadMissing(['attachments', 'employee', 'client']);

        $this->payload = [
            'id'                 => $message->id,
            'ticket_id'          => $message->ticket_id,
            'text'               => $message->message,
            'sender'             => $senderType,
            'event_type'         => $message->event_type,
            'metadata'           => $message->metadata,
            'employee_name'      => $message->employee?->nombre,
            'timestamp'          => $message->created_at->toIso8601String(),
            'formatted_datetime' => $message->created_at->format('Y-m-d H:i:s'),
            'attachments'        => $message->attachments->map(fn ($a) => [
                'cloudinary_public_id' => $a->cloudinary_public_id,
                'file_url'             => $a->file_url,
                'original_name'        => $a->original_name,
                'type'                 => $a->type,
                'size'                 => $a->size,
            ])->toArray(),
        ];
    }

    /**
     * Canal privado del ticket para que cliente y empleado lo reciban.
     * También se emite al canal global de empleados para actualizaciones de lista.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('ticket.' . $this->payload['ticket_id']),
            new PrivateChannel('employees'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
