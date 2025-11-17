<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanFeature extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_id',
        'feature',
        'icon',
        'order',
        'highlighted',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'order' => 'integer',
        'highlighted' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Get the plan that owns the feature.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Scope a query to only include highlighted features.
     */
    public function scopeHighlighted($query)
    {
        return $query->where('highlighted', true);
    }

    /**
     * Scope a query to only include regular features.
     */
    public function scopeRegular($query)
    {
        return $query->where('highlighted', false);
    }

    /**
     * Scope a query to order features by order field.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('id');
    }

    /**
     * Scope a query for features with specific icons.
     */
    public function scopeWithIcon($query, string $icon)
    {
        return $query->where('icon', $icon);
    }

    /**
     * Get the icon class for display.
     * Assuming you're using Lucide icons or similar
     */
    public function getIconClassAttribute(): string
    {
        return $this->icon ? 'icon-' . $this->icon : 'icon-default';
    }

    /**
     * Check if the feature has an icon.
     */
    public function getHasIconAttribute(): bool
    {
        return !empty($this->icon);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Set default order if not provided
        static::creating(function ($feature) {
            if (is_null($feature->order)) {
                $maxOrder = PlanFeature::where('plan_id', $feature->plan_id)
                    ->max('order');
                $feature->order = $maxOrder ? $maxOrder + 1 : 0;
            }
        });
    }
}