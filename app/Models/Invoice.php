<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'client_plan_id', 'invoice_number', 'issue_date', 'due_date', 'amount', 'tax_amount', 'total_amount', 'status', 'payment_method', 'paid_at', 'payment_reference', 'description', 'metadata'];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Estados de factura
     */
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Métodos de pago
     */
    const PAYMENT_WALLET = 'wallet';
    const PAYMENT_MANUAL = 'manual';
    const PAYMENT_CARD = 'card';
    const PAYMENT_TRANSFER = 'transfer';

    /**
     * Relación con el cliente
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Relación con el plan del cliente
     */
    public function clientPlan()
    {
        return $this->belongsTo(ClientPlan::class);
    }

    /**
     * Relación con las transacciones
     */
    public function transaction()
    {
        return $this->hasOne(Transaction::class, 'reference', 'payment_reference');
    }

    /**
     * Scope para facturas pendientes o fallidas
     */
    public function scopePendingOrFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FAILED]);
    }

    /**
     * Scope para facturas pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope para facturas fallidas
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope para facturas pagadas
     */
    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * Scope para facturas vencidas
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_PENDING)->where('due_date', '<', now()->toDateString());
    }

    /**
     * Generar número de factura único
     */
    public static function generateInvoiceNumber(): string
    {
        $year = now()->year;
        $month = now()->format('m');

        do {
            $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $invoiceNumber = "FACT-{$year}{$month}-{$sequence}";
        } while (self::where('invoice_number', $invoiceNumber)->exists());

        return $invoiceNumber;
    }

    /**
     * Marcar como pagada
     */
    public function markAsPaid($paymentMethod = null, $reference = null)
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'payment_method' => $paymentMethod,
            'payment_reference' => $reference,
            'paid_at' => now(),
        ]);

        return $this;
    }

    /**
     * Verificar si está vencida
     */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->due_date->lt(now());
    }

    /**
     * Verificar si se puede pagar automáticamente
     */
    public function canAutoPay(): bool
    {
        return $this->status === self::STATUS_PENDING &&
            $this->due_date->gte(now()->subDays(5)) && // Hasta 5 días después del vencimiento
            $this->client->hasSufficientBalance($this->total_amount);
    }

    /**
     * Obtener el color según el estado
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'success',
            self::STATUS_PENDING => $this->isOverdue() ? 'danger' : 'warning',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'secondary',
            default => 'secondary',
        };
    }

    /**
     * Obtener texto del estado
     */
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PAID => 'Pagada',
            self::STATUS_PENDING => $this->isOverdue() ? 'Vencida' : 'Pendiente',
            self::STATUS_FAILED => 'Fallida',
            self::STATUS_CANCELLED => 'Cancelada',
            default => 'Borrador',
        };
    }
}
