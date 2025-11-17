<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Valores por defecto - estos serán sobrescritos por el sequence()
        return [
            'name' => 'Plan Default',
            'slug' => 'plan-default',
            'description' => 'Descripción del plan por defecto.',
            'download_speed' => 100,
            'upload_speed' => 20,
            'symmetric' => false,
            'monthly_price' => 50.00,
            'setup_price' => 0,
            'billing_cycle' => 'monthly',
            'category' => 'residential',
            'priority' => 5,
            'is_featured' => false,
            'is_active' => true,
            'mikrotik_queue_name' => 'Plan_Default',
            'download_limit' => '100M',
            'upload_limit' => '20M',
            'burst_limit' => '150M/30M',
        ];
    }

    /**
     * Estados adicionales para testing - solo usar cuando NO se usa sequence
     */
    public function symmetric(): Factory
    {
        return $this->state(function (array $attributes) {
            $speed = $this->faker->randomElement([100, 200, 300, 500, 1000]);
            return [
                'download_speed' => $speed,
                'upload_speed' => $speed,
                'symmetric' => true,
            ];
        });
    }

    public function business(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'category' => 'business',
                'setup_price' => $this->faker->randomElement([50.00, 75.00, 100.00]),
                'is_featured' => false,
            ];
        });
    }

    public function residential(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'category' => 'residential',
                'setup_price' => 0,
                'is_featured' => $this->faker->boolean(40),
            ];
        });
    }

    public function premium(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'category' => 'premium',
                'setup_price' => $this->faker->randomElement([0, 25.00]),
                'is_featured' => true,
            ];
        });
    }

    public function inactive(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => false,
            ];
        });
    }
}