<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class Message extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'ticket_id',
        'client_id',
        'employee_id',
        'message',
        'event_type',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at'  => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function getSenderAttribute(): Model
    {
        return $this->client ?? $this->employee;
    }

    public function getSenderTypeAttribute(): string
    {
        if ($this->event_type) {
            return 'system';
        }
        return $this->client_id ? 'client' : 'employee';
    }

    /** Indica si el mensaje es un evento del sistema (no un mensaje de texto normal). */
    public function isSystemEvent(): bool
    {
        return !empty($this->event_type);
    }
}
