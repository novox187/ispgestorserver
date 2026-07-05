<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ventana de corte del servicio de un cliente.
 *
 * Periodo [suspended_at, reactivated_at) durante el cual el cliente no consume
 * el servicio (suspensión por impago, baja, etc.). `reactivated_at` NULL indica
 * que el corte sigue vigente. La facturación consulta estas ventanas como fecha
 * límite: ninguna factura debe emitirse con fecha dentro de una ventana.
 */
class ClientServiceInterruption extends Model
{
    use Auditable;

    public const TYPE_SUSPENSION   = 'suspension';
    public const TYPE_CANCELLATION = 'cancellation';

    protected $fillable = [
        'client_id',
        'type',
        'suspended_at',
        'reactivated_at',
        'suspension_reason',
        'reactivation_reason',
        'suspended_by',
        'reactivated_by',
        'invoice_id',
        'source',
    ];

    protected $casts = [
        'suspended_at'   => 'datetime',
        'reactivated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Factura que originó el corte (si aplica).
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Ventanas vigentes (servicio aún cortado).
     */
    public function scopeOpen($query)
    {
        return $query->whereNull('reactivated_at');
    }

    /**
     * Indica si el corte cubre la fecha dada.
     *
     * Regla de negocio con granularidad de DÍA: el corte rige desde el día de
     * la suspensión (inclusive) hasta el día de la reactivación (exclusive).
     * El día de la reactivación ya es facturable; el día del corte no lo es.
     */
    public function covers(\DateTimeInterface $date): bool
    {
        $moment = Carbon::instance($date);

        return $this->suspended_at->copy()->startOfDay()->lte($moment)
            && ($this->reactivated_at === null || $this->reactivated_at->copy()->startOfDay()->gt($moment));
    }
}
