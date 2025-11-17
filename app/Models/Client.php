<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; 

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Get the client plans for the client.
     */
    public function clientPlans(): HasMany
    {
        return $this->hasMany(ClientPlan::class);
    }

    /**
     * Get the servicios for the client.
     */
    public function servicios(): HasMany
    {
        return $this->hasMany(Servicio::class);
    }

    /**
     * Get the soportes for the client.
     */
    public function soportes(): HasMany
    {
        return $this->hasMany(Support::class);
    }

    /**
     * Scope a query to only include active clients.
     */
    public function scopeActive($query)
    {
        return $query->where('service_status', 'ACTIVO');
    }

    /**
     * Scope a query to only include inactive clients.
     */
    public function scopeInactive($query)
    {
        return $query->where('service_status', 'INACTIVO');
    }

    /**
     * Scope a query to only include suspended clients.
     */
    public function scopeSuspended($query)
    {
        return $query->where('service_status', 'SUSPENDIDO');
    }

    /**
     * Scope a query to only include limited clients.
     */
    public function scopeLimited($query)
    {
        return $query->where('service_status', 'LIMITADO');
    }

    /**
     * Scope a query to only include clients with IP assigned.
     */
    public function scopeWithIp($query)
    {
        return $query->whereNotNull('ip');
    }

    /**
     * Check if the client is active.
     */
    public function isActive(): bool
    {
        return $this->service_status === 'ACTIVO';
    }

    /**
     * Check if the client has IP assigned.
     */
    public function hasIpAddress(): bool
    {
        return !empty($this->ip);
    }

    /**
     * Get the contract duration in days.
     */
    public function getContractDurationAttribute(): int
    {
        return $this->contract_date->diffInDays(now());
    }
}