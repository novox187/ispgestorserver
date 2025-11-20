<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'wallet_id',
        'type',
        'amount',
        'description',
        'reference',
        'status',
        'metadata'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Tipos de transacción permitidos
     */
    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAWAL = 'withdrawal';
    const TYPE_PAYMENT = 'payment';
    const TYPE_REFUND = 'refund';

    /**
     * Estados permitidos
     */
    const STATUS_PENDING = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Relación con la billetera
     */
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Relación con el Cliente a través de la billetera
     */
    public function client()
    {
        return $this->hasOneThrough(Client::class, Wallet::class, 'id', 'id', 'wallet_id', 'client_id');
    }

    /**
     * Scope para transacciones completadas
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope para depósitos
     */
    public function scopeDeposits($query)
    {
        return $query->where('type', self::TYPE_DEPOSIT);
    }

    /**
     * Scope para pagos
     */
    public function scopePayments($query)
    {
        return $query->where('type', self::TYPE_PAYMENT);
    }

    /**
     * Scope para transacciones recientes
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Marcar transacción como completada
     */
    public function markAsCompleted()
    {
        $this->update(['status' => self::STATUS_COMPLETED]);
        return $this;
    }

    /**
     * Marcar transacción como fallida
     */
    public function markAsFailed($reason = null)
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'metadata' => array_merge($this->metadata ?? [], ['failure_reason' => $reason])
        ]);
        return $this;
    }

    /**
     * Verificar si la transacción es exitosa
     */
    public function isSuccessful()
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Obtener el icono según el tipo de transacción
     */
    public function getIconAttribute()
    {
        return match($this->type) {
            self::TYPE_DEPOSIT => '⬆️',
            self::TYPE_WITHDRAWAL => '⬇️',
            self::TYPE_PAYMENT => '💳',
            self::TYPE_REFUND => '↩️',
            default => '🔹'
        };
    }

    /**
     * Obtener el color según el tipo de transacción
     */
    public function getColorAttribute()
    {
        return match($this->type) {
            self::TYPE_DEPOSIT, self::TYPE_REFUND => 'success',
            self::TYPE_WITHDRAWAL, self::TYPE_PAYMENT => 'danger',
            default => 'secondary'
        };
    }
}