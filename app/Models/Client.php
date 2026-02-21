<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; 
use App\Traits\Auditable;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, Auditable;

    protected $table = 'clients';

    protected $fillable = [
        'full_name',
        'document_id',
        'contact_phone',
        'email',
        'password',
        'installation_address',
        'gps_coordinates',
        'contract_date',
        'service_status',
        'ip',
        'observations',
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'contract_date' => 'date',
    ];

    protected $appends = ['name', 'dni'];

    public function getNameAttribute()
    {
        return $this->full_name;
    }

    public function getDniAttribute()
    {
        return $this->document_id;
    }

    /**
     * Obtén los planes del cliente para el cliente.
     */
    public function clientPlans(): HasMany
    {
        return $this->hasMany(ClientPlan::class);
    }

    /**
     * Obtén las facturas del cliente.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Obtén los servicios del cliente para el cliente.
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }

    /**
     * Obtén los soportes del cliente para el cliente.
     */
    public function soportes(): HasMany
    {
        // supports.fk_id_cliente -> clients.id
        return $this->hasMany(Support::class, 'fk_id_cliente', 'id');
    }

    /**
     * Filtra los clientes activos.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('service_status', ['ACTIVO', 'ACTIVE']);
    }

    /**
     * Filtra los clientes inactivos.
     */
    public function scopeInactive($query)
    {
        return $query->whereIn('service_status', ['INACTIVO', 'INACTIVE']);
    }

    /**
     * Filtra los clientes suspendidos.
     */
    public function scopeSuspended($query)
    {
        return $query->whereIn('service_status', ['SUSPENDIDO', 'SUSPENDED']);
    }

    /**
     * Filtra los clientes limitados.
     */
    public function scopeLimited($query)
    {
        return $query->whereIn('service_status', ['LIMITADO', 'LIMITED']);
    }

    /**
     * Filtra los clientes con IP asignada.
     */
    public function scopeWithIp($query)
    {
        return $query->whereNotNull('ip');
    }

    /**
     * Verifica si el cliente está activo.
     */
    public function isActive(): bool
    {
        return in_array($this->service_status, ['ACTIVO', 'ACTIVE']);
    }

    /**
     * Verifica si el cliente tiene IP asignada.
     */
    public function hasIpAddress(): bool
    {
        return !empty($this->ip);
    }

    /**
     * Obtiene la duración del contrato en días.
     */
    public function getContractDurationAttribute(): int
    {
        return $this->contract_date->diffInDays(now());
    }

    /**
     * Relación con la billetera del cliente
     */
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Relación con las transacciones a través de la billetera
     */
    public function transactions()
    {
        return $this->hasManyThrough(Transaction::class, Wallet::class);
    }

    /**
     * Obtener el saldo actual del cliente
     */
    public function getBalanceAttribute()
    {
        return $this->wallet ? $this->wallet->balance : 0;
    }

    /**
     * Verificar si el cliente tiene saldo suficiente
     */
    public function hasSufficientBalance($amount)
    {
        return $this->balance >= $amount;
    }
}