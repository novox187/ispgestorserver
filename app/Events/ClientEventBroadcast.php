<?php
namespace App\Events;

use App\Models\ClientEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientEventBroadcast implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public array $payload;

    public function __construct(ClientEvent $event)
    {
        $this->payload = [
            'id'                 => $event->id,
            'client_id'          => $event->client_id,
            'event_type'         => $event->event_type,
            'text'               => $event->data['text'] ?? '',
            'metadata'           => $event->data,
            'sender'             => 'system',
            'timestamp'          => $event->created_at->toIso8601String(),
            'formatted_datetime' => $event->created_at->format('Y-m-d H:i:s'),
        ];
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('client.' . $this->payload['client_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client.event';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
