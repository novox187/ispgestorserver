<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class ClientPlan extends Model
{
    use HasFactory, Auditable;

    public $incrementing = true;
    protected $table = 'clients_plans';

    protected $fillable = [
        'client_id',
        'plan_id',
        'status',
        'start_date',
        'end_date',
        'next_billing_date',
        'current_price',
        'billing_cycle',
        'ip_address',
        'mikrotik_queue_id',
        'payment_method',
        'payment_reference',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_billing_date' => 'date',
        'current_price' => 'decimal:2',
    ];

    /**
     * Get the client that owns the client plan.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the plan that owns the client plan.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include suspended plans.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Scope a query to only include cancelled plans.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Scope a query to only include pending plans.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include plans with IP assigned.
     */
    public function scopeWithIp($query)
    {
        return $query->whereNotNull('ip_address');
    }

    /**
     * Scope a query to only include plans expiring soon.
     */
    public function scopeExpiringSoon($query)
    {
        return $query->where('next_billing_date', '<=', now()->addDays(7))
                    ->where('status', 'active');
    }

    /**
     * Check if the plan is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the plan is expired.
     */
    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Get the formatted current price.
     */
    public function getFormattedCurrentPriceAttribute(): string
    {
        return '€' . number_format($this->current_price, 2);
    }

    /**
     * Get days until next billing.
     */
    public function getDaysUntilBillingAttribute(): int
    {
        return now()->diffInDays($this->next_billing_date, false);
    }

    /**
     * Check if billing is due soon (within 7 days).
     */
    public function getIsBillingDueSoonAttribute(): bool
    {
        return $this->days_until_billing <= 7 && $this->days_until_billing >= 0;
    }
}