<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallApplyLog extends Model
{
    protected $fillable = [
        'router_id',
        'employee_id',
        'reason',
        'snapshot_before',
        'snapshot_applied',
        'status',
        'error_message',
        'applied_at',
    ];

    protected $casts = [
        'snapshot_before'  => 'array',
        'snapshot_applied' => 'array',
        'applied_at'       => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
