<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientWhitelist extends Model
{
    use HasFactory;

    protected $table = 'client_whitelists';

    protected $fillable = [
        'client_id',
        'added_at',
        'authorized_by',
        'reason',
        'expires_at',
        'active',
    ];

    protected $casts = [
        'added_at'   => 'datetime',
        'expires_at' => 'datetime',
        'active'     => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'authorized_by');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function isCurrentlyValid(): bool
    {
        if (!$this->active) {
            return false;
        }
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
