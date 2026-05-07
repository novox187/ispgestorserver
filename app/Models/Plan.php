<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\Auditable;

class Plan extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'slug', // Asegúrate de que slug esté aquí
        'description',
        'download_speed',
        'upload_speed',
        'symmetric',
        'ratio',
        'monthly_price',
        'setup_price',
        'billing_cycle',
        'category',
        'priority',
        'is_featured',
        'is_active',
        'mikrotik_queue_name',
        'download_limit',
        'upload_limit',
        'burst_limit',
    ];

    protected $casts = [
        'download_speed' => 'integer',
        'upload_speed' => 'integer',
        'symmetric' => 'boolean',
        'monthly_price' => 'decimal:2',
        'setup_price' => 'decimal:2',
        'priority' => 'integer',
        'is_featured' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class)->orderBy('order');
    }

    public function userPlans(): HasMany
    {
        return $this->hasMany(UserPlan::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority')->orderBy('monthly_price');
    }

    public function getFormattedMonthlyPriceAttribute(): string
    {
        return '€' . number_format($this->monthly_price, 2);
    }

    public function getFormattedSetupPriceAttribute(): string
    {
        return $this->setup_price > 0 
            ? '€' . number_format($this->setup_price, 2)
            : 'Gratis';
    }

    public function getSpeedDisplayAttribute(): string
    {
        return $this->symmetric 
            ? "{$this->download_speed} Mbps Simétrico"
            : "{$this->download_speed} Mbps / {$this->upload_speed} Mbps";
    }

    public function getDownloadSpeedDisplayAttribute(): string
    {
        return $this->download_speed >= 1000 
            ? ($this->download_speed / 1000) . ' Gbps'
            : $this->download_speed . ' Mbps';
    }

    public function getUploadSpeedDisplayAttribute(): string
    {
        return $this->upload_speed >= 1000 
            ? ($this->upload_speed / 1000) . ' Gbps'
            : $this->upload_speed . ' Mbps';
    }

    public function getHasFreeSetupAttribute(): bool
    {
        return $this->setup_price == 0;
    }

    public function getFirstPaymentAttribute(): float
    {
        return $this->monthly_price + $this->setup_price;
    }

    public function getFormattedFirstPaymentAttribute(): string
    {
        return '€' . number_format($this->first_payment, 2);
    }

    public function getHighlightedFeaturesAttribute()
    {
        return $this->features->where('highlighted', true);
    }

    public function getRegularFeaturesAttribute()
    {
        return $this->features->where('highlighted', false);
    }

    // NO incluir el método boot() - los slugs se manejan explícitamente
}