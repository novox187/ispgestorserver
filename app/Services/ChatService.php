<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;

class ChatService
{
    protected function uploadAttachment(UploadedFile $file): array
    {
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
        $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
            'folder'        => 'chat_attachments',
            'resource_type' => 'auto',
        ]);

        return [
            'cloudinary_public_id' => $result['public_id'],
            'file_url'             => $result['secure_url'],
            'original_name'        => $file->getClientOriginalName(),
            'type'                 => $file->getMimeType(),
            'size'                 => $file->getSize(),
        ];
    }

    /**
     * Busca el ticket abierto del cliente o crea uno nuevo.
     * Crea el mensaje, sube adjuntos y emite el evento WebSocket.
     */
    public function createMessage(array $data): Message
    {
        return DB::transaction(function () use ($data) {
            $clientId = $data['client_id'];

            $ticket = Ticket::where('client_id', $clientId)
                ->where('status', '!=', 'closed')
                ->latest()
                ->first();

            if (!$ticket) {
                $ticket = Ticket::create([
                    'client_id'       => $clientId,
                    'subject'         => substr($data['message'] ?? 'Nuevo Ticket', 0, 100),
                    'status'          => 'open',
                    'last_message_at' => now(),
                    'employee_id'     => null,
                ]);
            }

            $message = $ticket->messages()->create([
                'message'    => $data['message'] ?? '',
                'client_id'  => $clientId,
                'employee_id' => null,
            ]);

            if (!empty($data['attachments'])) {
                $attachmentsData = collect($data['attachments'])
                    ->map(fn (UploadedFile $file) => $this->uploadAttachment($file))
                    ->all();
                $message->attachments()->createMany($attachmentsData);
            }

            if ($ticket->status === 'closed') {
                $ticket->status = 'open';
            }
            $ticket->last_message_at = now();
            $ticket->save();

            $message->load('attachments');

            broadcast(new MessageSent($message, 'user'))->toOthers();

            return $message;
        });
    }

    /**
     * El empleado responde en un ticket existente.
     */
    public function replyAsEmployee(Ticket $ticket, int $employeeId, string $messageText, array $attachments = []): Message
    {
        return DB::transaction(function () use ($ticket, $employeeId, $messageText, $attachments) {
            $message = $ticket->messages()->create([
                'message'     => $messageText,
                'employee_id' => $employeeId,
                'client_id'   => null,
            ]);

            if (!empty($attachments)) {
                $attachmentsData = collect($attachments)
                    ->map(fn (UploadedFile $file) => $this->uploadAttachment($file))
                    ->all();
                $message->attachments()->createMany($attachmentsData);
            }

            if ($ticket->employee_id === null) {
                $ticket->employee_id = $employeeId;
            }
            $ticket->last_message_at = now();
            $ticket->save();

            $message->load(['attachments', 'employee']);

            broadcast(new MessageSent($message, 'agent'));

            return $message;
        });
    }

    /**
     * Crea un evento del sistema (p. ej. wallet_funded) desacoplado del sistema de tickets.
     * Persiste en client_events y transmite por private-client.{clientId}.
     */
    public function createSystemEvent(int $clientId, string $eventType, string $displayText, array $metadata = []): ?\App\Models\ClientEvent
    {
        try {
            $event = \App\Models\ClientEvent::create([
                'client_id'  => $clientId,
                'event_type' => $eventType,
                'data'       => array_merge($metadata, ['text' => $displayText]),
            ]);

            broadcast(new \App\Events\ClientEventBroadcast($event));

            return $event;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("ChatService::createSystemEvent failed: {$e->getMessage()}", [
                'client_id'  => $clientId,
                'event_type' => $eventType,
            ]);
            return null;
        }
    }
}
