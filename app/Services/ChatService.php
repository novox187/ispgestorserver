<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cloudinary\Cloudinary;

class ChatService
{
    /**
     * Upload attachment to Cloudinary and return metadata
     */
    protected function uploadAttachment(UploadedFile $file): array
    {
        Log::info('Uploading attachment', ['file' => $file->getClientOriginalName()]);

        try {
            // Upload to Cloudinary
            $cloudinary = new Cloudinary(env('CLOUDINARY_URL'));
            $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                'folder' => 'chat_attachments',
                'resource_type' => 'auto'
            ]);

            Log::info('Attachment uploaded successfully', ['public_id' => $result['public_id']]);

            return [
                'cloudinary_public_id' => $result['public_id'],
                'file_url' => $result['secure_url'],
                'original_name' => $file->getClientOriginalName(),
                'type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to upload attachment to Cloudinary', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Busca el ticket abierto del cliente o crea uno nuevo.
     * Crea el mensaje y actualiza el estado del ticket.
     */
    public function createMessage(array $data): Message
    {
        Log::info('Starting createMessage', ['data' => $data]);

        return DB::transaction(function () use ($data) {

            $clientId = $data['client_id'];
            
            // 1. BUSCAR UN TICKET ABIERTO (estados: 'new' o 'open')
            $ticket = Ticket::where('client_id', $clientId)
                ->whereNotIn('status', ['closed', 'resolved']) // Excluye estados finales
                ->latest()
                ->first();
                
            // 2. CREAR UN NUEVO TICKET si no se encontró uno abierto
            if (!$ticket) {
                $ticket = Ticket::create([
                    'client_id' => $clientId,
                    'subject' => substr($data['message'] ?? 'Nuevo Ticket', 0, 100), 
                    'status' => 'new',
                    'last_message_at' => now(),
                    'employee_id' => null, // Sin asignar inicialmente
                ]);
            }
            
            // 3. Crear el Mensaje y asignarlo al ticket
            $message = $ticket->messages()->create([
                'message' => $data['message'],
                'client_id' => $clientId,
                'employee_id' => null,
                'created_at' => now()
            ]);

            // 4. Procesar y adjuntar archivos
            if (isset($data['attachments'])) {
                $attachmentsData = collect($data['attachments'])
                    ->map(fn(UploadedFile $file) => $this->uploadAttachment($file))
                    ->all();
                $message->attachments()->createMany($attachmentsData);
            }

            // 5. Actualizar Ticket y estado
            if (in_array($ticket->status, ['closed', 'resolved'])) {
                $ticket->status = 'reopened'; // Reabrir si el cliente responde a un ticket cerrado
            } elseif ($ticket->status === 'new') {
                $ticket->status = 'open'; // Mover de nuevo a 'open'
            }
            $ticket->save();

            // 6. Retornar el mensaje completo
            return $message->load('attachments'); 
        });
    }
}