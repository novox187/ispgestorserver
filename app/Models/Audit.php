<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Audit extends Model
{
    // Desactivar updated_at ya que los registros de auditoría son inmutables
    const UPDATED_AT = null;

    protected $fillable = [
        'table_name',
        'operation',
        'record_id',
        'old_values',
        'new_values',
        'user_id',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Relación con el usuario que realizó la acción.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
