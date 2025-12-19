<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use App\Traits\Auditable;

class Message extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'ticket_id',
        'client_id',
        'employee_id',
        'message', // Puede ser NULL si solo se envían adjuntos
    ];

    /**
     * El mensaje pertenece a un ticket.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Los archivos adjuntos de este mensaje.
     * Relación: Uno a Muchos (Un mensaje puede tener múltiples adjuntos).
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // --- ACCESORIOS PARA EL REMITENTE ---

    /**
     * El remitente del mensaje (Cliente).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * El remitente del mensaje (Empleado/Sistema/Bot).
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
    
    /**
     * ACCESSOR (Método mágico de acceso): Obtiene el modelo del remitente.
     * Devuelve el objeto Client O Employee, simplificando la lógica de la aplicación.
     */
    public function getSenderAttribute(): Model
    {
        // Se valida a nivel de la aplicación que solo uno de los dos sea NO nulo
        return $this->client ?? $this->employee;
    }

    /**
     * ACCESSOR: Determina el tipo de remitente ('client' o 'employee').
     */
    public function getSenderTypeAttribute(): string
    {
        return $this->client_id ? 'client' : 'employee';
    }
}