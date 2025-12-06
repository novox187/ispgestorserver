<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Message;
use App\Models\Ticket;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    /**
     * Get all tickets for the authenticated user (client or employee).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user instanceof Client) {
            $tickets = Ticket::where('client_id', $user->id)
                ->with(['messages.attachments', 'assignedEmployee'])
                ->orderBy('created_at', 'desc')
                ->get();
        } elseif ($user instanceof Employee) {
            $tickets = Ticket::with(['messages.attachments', 'client', 'assignedEmployee'])
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return response()->json($tickets);
    }

    /**
     * Get messages for a specific ticket.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $ticket = Ticket::with(['messages.attachments', 'messages.client', 'messages.employee', 'client', 'assignedEmployee'])->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        if ($user instanceof Client && $ticket->client_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Employees can view all tickets

        return response()->json($ticket);
    }

    /**
     * Send a message. Clients create ticket if none open. Employees send to assigned tickets.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'message' => 'nullable|string|max:1000',
            'ticket_id' => 'nullable|integer|exists:tickets,id',
            'files' => 'nullable|array',
            'files.*' => 'file|mimes:jpeg,png,jpg,gif,pdf,doc,docx,xls,xlsx|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $user) {
            $ticket = null;

            if ($user instanceof Client) {
                // Client: find or create open ticket
                $ticket = Ticket::where('client_id', $user->id)
                    ->where('status', 'open')
                    ->first();

                if (!$ticket) {
                    $ticket = Ticket::create([
                        'client_id' => $user->id,
                        'subject' => $request->message ? substr($request->message, 0, 100) : 'Nuevo ticket',
                        'status' => 'open',
                    ]);
                }
            } elseif ($user instanceof Employee) {
                // Employee: must specify ticket_id
                if (!$request->ticket_id) {
                    throw new \Exception('Ticket ID required for employees');
                }

                $ticket = Ticket::find($request->ticket_id);
                if (!$ticket) {
                    throw new \Exception('Ticket not found');
                }

                // Assign employee if not assigned
                if (!$ticket->employee_id) {
                    $ticket->update(['employee_id' => $user->id]);
                }
            } else {
                throw new \Exception('Unauthorized');
            }

            // Create message
            $message = Message::create([
                'ticket_id' => $ticket->id,
                'client_id' => $user instanceof Client ? $user->id : null,
                'employee_id' => $user instanceof Employee ? $user->id : null,
                'message' => $request->message,
            ]);

            // Handle file uploads
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $uploadedFile = Cloudinary::upload($file->getRealPath(), [
                        'folder' => 'tickets/' . $ticket->id . '/',
                        'public_id' => uniqid(),
                    ]);

                    Attachment::create([
                        'message_id' => $message->id,
                        'cloudinary_public_id' => $uploadedFile->getPublicId(),
                        'file_url' => $uploadedFile->getSecurePath(),
                        'original_name' => $file->getClientOriginalName(),
                        'type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Message sent successfully'], 201);
    }

    /**
     * Close a ticket (employees only).
     */
    public function close(Request $request, $id)
    {
        $user = $request->user();

        if (!$user instanceof Employee) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        $ticket->update(['status' => 'closed']);

        return response()->json(['message' => 'Ticket closed']);
    }

    /**
     * Assign ticket to employee.
     */
    public function assign(Request $request, $id)
    {
        $user = $request->user();

        if (!$user instanceof Employee) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $ticket = Ticket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket not found'], 404);
        }

        $ticket->update(['employee_id' => $user->id]);

        return response()->json(['message' => 'Ticket assigned']);
    }
}