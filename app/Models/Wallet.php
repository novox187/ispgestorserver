<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'balance',
        'currency',
        'status'
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Relación con el cliente
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Relación con las transacciones
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Scope para billeteras activas
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Depositar dinero en la billetera
     */
    public function deposit($amount, $description = "Recarga de saldo")
    {
        $this->balance += $amount;
        $this->save();

        // Crear transacción
        $this->transactions()->create([
            'type' => 'deposit',
            'amount' => $amount,
            'description' => $description,
            'reference' => 'DEP_' . uniqid(),
            'status' => 'completed'
        ]);

        return $this;
    }

    /**
     * Retirar dinero de la billetera
     */
    public function withdraw($amount, $description = "Retiro de saldo")
    {
        if ($this->balance < $amount) {
            throw new \Exception('Saldo insuficiente');
        }

        $this->balance -= $amount;
        $this->save();

        // Crear transacción
        $this->transactions()->create([
            'type' => 'withdrawal',
            'amount' => $amount,
            'description' => $description,
            'reference' => 'WITH_' . uniqid(),
            'status' => 'completed'
        ]);

        return $this;
    }

    /**
     * Realizar un pago
     */
    public function makePayment($amount, $service, $description = "")
    {
        if ($this->balance < $amount) {
            throw new \Exception('Saldo insuficiente para realizar el pago');
        }

        $this->balance -= $amount;
        $this->save();

        // Crear transacción de pago
        $this->transactions()->create([
            'type' => 'payment',
            'amount' => $amount,
            'description' => $description ?: "Pago para: " . $service,
            'reference' => 'PAY_' . uniqid(),
            'status' => 'completed',
            'metadata' => ['service' => $service]
        ]);

        return $this;
    }

    /**
     * Obtener transacciones recientes
     */
    public function recentTransactions($limit = 10)
    {
        return $this->transactions()
                    ->orderBy('created_at', 'desc')
                    ->limit($limit)
                    ->get();
    }

    /**
     * Verificar si la billetera está activa
     */
    public function isActive()
    {
        return $this->status === 'active';
    }
}
