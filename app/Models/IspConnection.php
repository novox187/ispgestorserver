<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IspConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'isp_id',
        'bandwidth_down',
        'bandwidth_up',
        'ratio',
        'contract_date',
        'billing_day',
        'billing_cycle',
        'monthly_price',
        'interface_name',
        'status',
    ];

    /**
     * Relación inversa: esta conexión pertenece a un Proveedor de Internet.
     */
    public function isp(): BelongsTo
    {
        return $this->belongsTo(InternetServiceProvider::class, 'isp_id');
    }

    /**
     * Atributo dinámico: Calcula el costo por megabit de bajada.
     * Se accede como: $connection->price_per_mb
     */
    public function getPricePerMbAttribute(): float
    {
        if ($this->bandwidth_down > 0) {
            return $this->monthly_price / $this->bandwidth_down;
        }
        return 0;
    }

    /**
     * Scope para filtrar solo conexiones activas.
     * Uso: IspConnection::active()->get();
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}