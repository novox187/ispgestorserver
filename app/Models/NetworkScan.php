<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un barrido de descubrimiento sobre un rango de la red de gestión.
 *
 * Se audita: pedir un barrido lanza tráfico contra decenas o cientos de
 * direcciones de la red del cliente, y eso tiene que dejar constancia de quién
 * lo pidió y sobre qué rango.
 */
class NetworkScan extends Model
{
    use Auditable;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'agent_id',
        'cidr',
        'status',
        'requested_by',
        'started_at',
        'finished_at',
        'found_count',
        'error_code',
        'error_message',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'found_count' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(ProvisioningAgent::class, 'agent_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(NetworkScanFinding::class, 'scan_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }
}
