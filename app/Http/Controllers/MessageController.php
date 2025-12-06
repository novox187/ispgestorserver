<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMessageRequest;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Models\Message;

class MessageController extends Controller
{
    protected ChatService $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $perPage = $request->get('per_page', 20);
        $page = $request->get('page', 1);

        // Get all messages from all tickets for this client, ordered by newest first
        $messages = Message::whereHas('ticket', function ($query) use ($user) {
                $query->where('client_id', $user->id);
            })
            ->with(['attachments', 'ticket', 'employee'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $formattedMessages = $messages->map(function ($message) {
            return [
                'id' => $message->id,
                'text' => $message->message,
                'sender' => $message->employee_id ? 'agent' : 'user',
                'timestamp' => $message->created_at,
                'formatted_datetime' => $message->created_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i:s'),
                'ticket_id' => $message->ticket_id,
                'employee_name' => $message->employee?->nombre,
                'attachments' => $message->attachments->map(fn($att) => [
                    'cloudinary_public_id' => $att->cloudinary_public_id,
                    'file_url' => $att->file_url,
                    'original_name' => $att->original_name,
                    'type' => $att->type,
                    'size' => $att->size,
                ]),
            ];
        });

        return response()->json([
            'messages' => $formattedMessages,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
                'has_more' => $messages->hasMorePages(),
            ]
        ]);
    }

    public function store(StoreMessageRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['client_id'] = Auth::id();

        Log::info('Store message request', ['data' => $data]);

        // Los datos validados ahora incluyen el client_id
        $message = $this->chatService->createMessage($data);

        Log::info('Message created successfully', ['message_id' => $message->id]);

        // Devolver la respuesta en el formato esperado por Svelte
        return response()->json([
            'id' => $message->id,
            'text' => $message->message,
            'sender' => 'user',
            'timestamp' => $message->created_at,
            'formatted_datetime' => $message->created_at->format('Y-m-d H:i:s'),
            'ticket_id' => $message->ticket_id,
            'attachments' => $message->attachments->map(fn($att) => [
                'cloudinary_public_id' => $att->cloudinary_public_id,
                'file_url' => $att->file_url,
                'original_name' => $att->original_name,
                'type' => $att->type,
                'size' => $att->size,
            ]),
        ], 201);
    }
}