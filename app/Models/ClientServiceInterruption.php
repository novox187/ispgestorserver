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

    /**
     * Resultado de la comprobación de que el corte es efectivo en la red.
     * Solo ENFORCED significa que el abonado dejó de navegar; los demás
     * estados describen cortes aplicados en la base de datos pero no (o no
     * verificadamente) en el router.
     */
    public const ENFORCEMENT_ENFORCED       = 'enforced';
    public const ENFORCEMENT_NO_FILTER_RULE = 'no_filter_rule';
    public const ENFORCEMENT_ENTRY_MISSING  = 'entry_missing';
    public const ENFORCEMENT_UNVERIFIABLE   = 'unverifiable';
    public const ENFORCEMENT_NO_IP          = 'no_ip';
    public const ENFORCEMENT_IP_MISMATCH    = 'ip_mismatch';
    public const ENFORCEMENT_NOT_APPLICABLE = 'not_applicable';

    /** Estados en los que el cliente sigue navegando pese a estar cortado en BD. */
    public const ENFORCEMENT_FAILED_STATES = [
        self::ENFORCEMENT_NO_FILTER_RULE,
        self::ENFORCEMENT_ENTRY_MISSING,
        self::ENFORCEMENT_UNVERIFIABLE,
        self::ENFORCEMENT_NO_IP,
        self::ENFORCEMENT_IP_MISMATCH,
    ];

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
        'enforcement_state',
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
     * Cortes registrados en BD que NO se pudieron confirmar en la red: el
     * cliente deja de facturarse pero puede seguir navegando. Es la consulta
     * que alimenta la alerta operativa y la métrica de cortes efectivos.
     */
    public function scopeNotEnforced($query)
    {
        return $query->whereIn('enforcement_state', self::ENFORCEMENT_FAILED_STATES);
    }

    public function isEnforced(): bool
    {
        return $this->enforcement_state === self::ENFORCEMENT_ENFORCED;
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
