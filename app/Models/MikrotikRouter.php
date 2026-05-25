<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MikrotikRouter extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'description',
        'is_active',
        'last_loaded_at',
        'last_applied_at',
        'connectivity_status',
        'last_health_check_at',
        'last_connected_at',
        'last_disconnected_at',
        'consecutive_failures',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active'            => 'boolean',
        'port'                 => 'integer',
        'last_loaded_at'       => 'datetime',
        'last_applied_at'      => 'datetime',
        'last_health_check_at' => 'datetime',
        'last_connected_at'    => 'datetime',
        'last_disconnected_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'password'             => 'encrypted',
    ];

    public function filterRules(): HasMany
    {
        return $this->hasMany(FirewallFilterRule::class, 'router_id');
    }

    public function natRules(): HasMany
    {
        return $this->hasMany(FirewallNatRule::class, 'router_id');
    }

    public function applyLogs(): HasMany
    {
        return $this->hasMany(FirewallApplyLog::class, 'router_id');
    }
}
