<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chatService) {}

    /**
     * GET /api/admin/chat/conversations
     * Lista todos los tickets con su último mensaje y datos del cliente.
     */
    public function conversations(Request $request): JsonResponse
    {
        $tickets = Ticket::with(['client', 'assignedEmployee', 'messages' => function ($q) {
                $q->latest()->limit(1)->with('attachments');
            }])
            ->orderByDesc('last_message_at')
            ->paginate(30);

        $data = $tickets->map(function (Ticket $ticket) {
            $lastMsg = $ticket->messages->first();
            return [
                'ticket_id'       => $ticket->id,
                'subject'         => $ticket->subject,
                'status'          => $ticket->status,
                'last_message_at' => $ticket->last_message_at?->toIso8601String(),
                'client' => [
                    'id'     => $ticket->client->id,
                    'name'   => $ticket->client->full_name,
                    'email'  => $ticket->client->email,
                ],
                'assigned_employee' => $ticket->assignedEmployee ? [
                    'id'   => $ticket->assignedEmployee->id,
                    'name' => $ticket->assignedEmployee->nombre,
                ] : null,
                'last_message' => $lastMsg ? [
                    'id'         => $lastMsg->id,
                    'text'       => $lastMsg->message,
                    'sender'     => $lastMsg->employee_id ? 'agent' : ($lastMsg->event_type ? 'system' : 'user'),
                    'event_type' => $lastMsg->event_type,
                    'timestamp'  => $lastMsg->created_at->toIso8601String(),
                ] : null,
                'unread_count' => $ticket->messages()
                    ->whereNull('read_at')
                    ->whereNull('employee_id')
                    ->whereNull('event_type')
                    ->count(),
            ];
        });

        return response()->json([
            'data'       => $data,
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page'    => $tickets->lastPage(),
                'total'        => $tickets->total(),
                'has_more'     => $tickets->hasMorePages(),
            ],
        ]);
    }

    /**
     * GET /api/admin/chat/{ticketId}/messages
     * Mensajes paginados de un ticket específico.
     */
    public function messages(Request $request, int $ticketId): JsonResponse
    {
        $ticket = Ticket::findOrFail($ticketId);
        $perPage = $request->get('per_page', 20);

        $messages = Message::where('ticket_id', $ticketId)
            ->with(['attachments', 'employee', 'client'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $formatted = $messages->map(fn (Message $m) => $this->formatMessage($m));

        $clientEvents = \App\Models\ClientEvent::where('client_id', $ticket->client_id)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($e) {
                return [
                    'id'                 => 'evt-' . $e->id,
                    'event_type'         => $e->event_type,
                    'text'               => $e->data['text'] ?? '',
                    'metadata'           => $e->data,
                    'sender'             => 'system',
                    'timestamp'          => $e->created_at->toIso8601String(),
                    'formatted_datetime' => $e->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'ticket'     => [
                'id'      => $ticket->id,
                'subject' => $ticket->subject,
                'status'  => $ticket->status,
                'client'  => [
                    'id'    => $ticket->client->id,
                    'name'  => $ticket->client->full_name,
                    'email' => $ticket->client->email,
                ],
                'assigned_employee' => $ticket->assignedEmployee ? [
                    'id'   => $ticket->assignedEmployee->id,
                    'name' => $ticket->assignedEmployee->nombre,
                ] : null,
            ],
            'messages'   => $formatted,
            'events'     => $clientEvents,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
                'total'        => $messages->total(),
                'has_more'     => $messages->hasMorePages(),
            ],
        ]);
    }

    /**
     * POST /api/admin/chat/{ticketId}/messages
     * El empleado envía un mensaje en el ticket.
     */
    public function store(Request $request, int $ticketId): JsonResponse
    {
        $request->validate([
            'message'       => 'required_without:attachments|nullable|string|max:5000',
            'attachments'   => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $ticket = Ticket::findOrFail($ticketId);
        $employee = Auth::user();

        $message = $this->chatService->replyAsEmployee(
            $ticket,
            $employee->id,
            $request->input('message', ''),
            $request->file('attachments', []),
        );

        // Marcar mensajes del cliente como leídos al responder
        Message::where('ticket_id', $ticketId)
            ->whereNull('employee_id')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($this->formatMessage($message), 201);
    }

    /**
     * PUT /api/admin/chat/{ticketId}/assign
     * Asigna el ticket al empleado autenticado.
     */
    public function assign(int $ticketId): JsonResponse
    {
        $ticket = Ticket::findOrFail($ticketId);
        $employee = Auth::user();

        $ticket->employee_id = $employee->id;
        $ticket->save();

        return response()->json(['message' => 'Ticket asignado.', 'ticket_id' => $ticket->id]);
    }

    /**
     * PUT /api/admin/chat/{ticketId}/status
     * Actualiza el estado del ticket (open / pending / closed).
     */
    public function updateStatus(Request $request, int $ticketId): JsonResponse
    {
        $request->validate(['status' => 'required|in:open,pending,closed']);
        $ticket = Ticket::findOrFail($ticketId);
        $ticket->status = $request->input('status');
        $ticket->save();

        return response()->json(['message' => 'Estado actualizado.', 'status' => $ticket->status]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function formatMessage(Message $m): array
    {
        return [
            'id'                 => $m->id,
            'ticket_id'          => $m->ticket_id,
            'text'               => $m->message,
            'sender'             => $m->employee_id ? 'agent' : ($m->event_type ? 'system' : 'user'),
            'event_type'         => $m->event_type,
            'metadata'           => $m->metadata,
            'employee_name'      => $m->employee?->nombre,
            'client_name'        => $m->client?->full_name,
            'timestamp'          => $m->created_at->toIso8601String(),
            'formatted_datetime' => $m->created_at->format('Y-m-d H:i:s'),
            'read_at'            => $m->read_at?->toIso8601String(),
            'attachments'        => $m->attachments->map(fn ($a) => [
                'cloudinary_public_id' => $a->cloudinary_public_id,
                'file_url'             => $a->file_url,
                'original_name'        => $a->original_name,
                'type'                 => $a->type,
                'size'                 => $a->size,
            ]),
        ];
    }
}
