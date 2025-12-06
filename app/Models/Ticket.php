<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    /**
     * Define los campos que pueden ser asignados masivamente.
     */
    protected $fillable = [
        'client_id',
        'employee_id', // El empleado asignado al ticket
        'subject',
        'status',
        'last_message_at',
    ];

    /**
     * Los mensajes pertenecen a este ticket.
     * Relación: Uno a Muchos (Un ticket tiene muchos mensajes).
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * El cliente que creó el ticket.
     * Relación: Muchos a Uno.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * El empleado actualmente asignado a este ticket (puede ser nulo).
     * Relación: Muchos a Uno.
     */
    public function assignedEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}