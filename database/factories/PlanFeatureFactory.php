<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Características generales que pueden aplicarse a cualquier plan
        $generalFeatures = [
            ['feature' => 'Instalación gratuita', 'icon' => 'tool', 'highlighted' => true],
            ['feature' => 'Soporte técnico 24/7', 'icon' => 'headphones', 'highlighted' => true],
            ['feature' => 'Router WiFi incluido', 'icon' => 'wifi', 'highlighted' => false],
            ['feature' => 'Garantía de servicio', 'icon' => 'shield', 'highlighted' => false],
            ['feature' => 'App de control', 'icon' => 'smartphone', 'highlighted' => false],
            ['feature' => 'Sin límite de datos', 'icon' => 'bar-chart', 'highlighted' => true],
            ['feature' => 'Múltiples dispositivos', 'icon' => 'monitor', 'highlighted' => false],
            ['feature' => 'Conexión estable', 'icon' => 'zap', 'highlighted' => false],
            ['feature' => 'Actualizaciones gratuitas', 'icon' => 'refresh-cw', 'highlighted' => false],
            ['feature' => 'Panel de control online', 'icon' => 'settings', 'highlighted' => false],
        ];

        // Combinar características según la categoría del plan
        $plan = Plan::inRandomOrder()->first() ?? Plan::factory()->create();
        
        $availableFeatures = $generalFeatures;

        $selectedFeature = $this->faker->randomElement($availableFeatures);

        return [
            'plan_id' => $plan->id,
            'feature' => $selectedFeature['feature'],
            'icon' => $selectedFeature['icon'],
            'order' => $this->faker->numberBetween(0, 20),
            'highlighted' => $selectedFeature['highlighted'],
        ];
    }

    /**
     * Estados adicionales para testing
     */

    /**
     * Característica destacada
     */
    public function highlighted(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'highlighted' => true,
            ];
        });
    }

    /**
     * Característica normal (no destacada)
     */
    public function normal(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'highlighted' => false,
            ];
        }); // ← QUITÉ EL PUNTO Y COMA EXTRA AQUÍ
    }

    /**
     * Para plan residencial específico
     */
    public function forResidential(): Factory
    {
        return $this->state(function (array $attributes) {
            $residentialPlan = Plan::where('category', 'residential')->inRandomOrder()->first();
            $residentialFeatures = [
                ['feature' => 'Streaming en HD', 'icon' => 'tv', 'highlighted' => true],
                ['feature' => 'Videollamadas sin cortes', 'icon' => 'video', 'highlighted' => false],
                ['feature' => 'Ideal para teletrabajo', 'icon' => 'home', 'highlighted' => true],
                ['feature' => 'Juegos online', 'icon' => 'gamepad', 'highlighted' => false],
            ];

            $selected = $this->faker->randomElement($residentialFeatures);

            return [
                'plan_id' => $residentialPlan?->id ?? Plan::factory()->residential()->create()->id,
                'feature' => $selected['feature'],
                'icon' => $selected['icon'],
                'highlighted' => $selected['highlighted'],
            ];
        });
    }

    /**
     * Para plan premium específico
     */
    public function forPremium(): Factory
    {
        return $this->state(function (array $attributes) {
            $premiumPlan = Plan::where('category', 'premium')->inRandomOrder()->first();
            $premiumFeatures = [
                ['feature' => 'Streaming 4K Ultra HD', 'icon' => 'tv', 'highlighted' => true],
                ['feature' => 'Gaming profesional', 'icon' => 'gamepad', 'highlighted' => true],
                ['feature' => 'Prioridad en la red', 'icon' => 'star', 'highlighted' => true],
                ['feature' => 'Router WiFi 6', 'icon' => 'wifi', 'highlighted' => true],
            ];

            $selected = $this->faker->randomElement($premiumFeatures);

            return [
                'plan_id' => $premiumPlan?->id ?? Plan::factory()->premium()->create()->id,
                'feature' => $selected['feature'],
                'icon' => $selected['icon'],
                'highlighted' => $selected['highlighted'],
            ];
        });
    }

    /**
     * Para plan empresarial específico
     */
    public function forBusiness(): Factory
    {
        return $this->state(function (array $attributes) {
            $businessPlan = Plan::where('category', 'business')->inRandomOrder()->first();
            $businessFeatures = [
                ['feature' => 'IP estática incluida', 'icon' => 'server', 'highlighted' => true],
                ['feature' => 'SLA 99.9%', 'icon' => 'activity', 'highlighted' => true],
                ['feature' => 'Soporte empresarial', 'icon' => 'briefcase', 'highlighted' => true],
                ['feature' => 'Conexión dedicada', 'icon' => 'link', 'highlighted' => true],
            ];

            $selected = $this->faker->randomElement($businessFeatures);

            return [
                'plan_id' => $businessPlan?->id ?? Plan::factory()->business()->create()->id,
                'feature' => $selected['feature'],
                'icon' => $selected['icon'],
                'highlighted' => $selected['highlighted'],
            ];
        });
    }

    /**
     * Con orden específico
     */
    public function withOrder(int $order): Factory
    {
        return $this->state(function (array $attributes) use ($order) {
            return [
                'order' => $order,
            ];
        });
    }

    /**
     * Con icono específico
     */
    public function withIcon(string $icon): Factory
    {
        return $this->state(function (array $attributes) use ($icon) {
            return [
                'icon' => $icon,
            ];
        });
    }

    /**
     * Para un plan específico
     */
    public function forPlan(Plan $plan): Factory
    {
        return $this->state(function (array $attributes) use ($plan) {
            return [
                'plan_id' => $plan->id,
            ];
        });
    }
}