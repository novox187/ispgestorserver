<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportHistory extends Model
{
    protected $fillable = [
        'employee_id',
        'table_name',
        'file_name',
        'status',
        'summary',
        'errors',
        'created_ids',
    ];

    protected $casts = [
        'summary' => 'array',
        'errors' => 'array',
        'created_ids' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
