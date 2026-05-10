<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Ticket;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canal: private-ticket.{ticketId}
|--------------------------------------------------------------------------
| Permite el acceso si el usuario autenticado es:
|   - El cliente dueño del ticket
|   - Cualquier empleado (puede gestionar cualquier ticket)
*/
Broadcast::channel('ticket.{ticketId}', function ($user, int $ticketId) {
    $ticket = Ticket::find($ticketId);
    if (!$ticket) {
        return false;
    }

    if ($user instanceof Client) {
        return $ticket->client_id === $user->id
            ? ['type' => 'client', 'id' => $user->id, 'name' => $user->full_name]
            : false;
    }

    if ($user instanceof Employee) {
        return ['type' => 'employee', 'id' => $user->id, 'name' => $user->nombre];
    }

    return false;
});

/*
|--------------------------------------------------------------------------
| Canal: private-employees
|--------------------------------------------------------------------------
| Exclusivo para empleados. Reciben notificaciones globales:
| nuevos tickets, mensajes de clientes sin ticket asignado, etc.
*/
Broadcast::channel('employees', function ($user) {
    return $user instanceof Employee
        ? ['id' => $user->id, 'name' => $user->nombre]
        : false;
});

/*
|--------------------------------------------------------------------------
| Canal: private-client.{clientId}
|--------------------------------------------------------------------------
| Canal personal del cliente para notificaciones fuera del ticket activo.
*/
Broadcast::channel('client.{clientId}', function ($user, int $clientId) {
    if ($user instanceof Client) {
        return $user->id === $clientId ? ['id' => $user->id, 'name' => $user->full_name] : false;
    }
    if ($user instanceof Employee) {
        return ['id' => $user->id, 'name' => $user->nombre];
    }
    return false;
});
