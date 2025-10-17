<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Faker\Generator as Faker;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $faker = $this->faker;

        // Elegimos un plan base al azar (usado cuando NO se aplica sequence desde el seeder)
        $planes = [
            ['down' => 50, 'up' => 10, 'tarifa' => 30.50],
            ['down' => 100, 'up' => 20, 'tarifa' => 55.90],
            ['down' => 200, 'up' => 40, 'tarifa' => 75.00],
            ['down' => 500, 'up' => 100, 'tarifa' => 99.99],
            ['down' => 1000, 'up' => 200, 'tarifa' => 150.00],
        ];

        $picked = $faker->randomElement($planes);
        $down = $picked['down'];
        $up = $picked['up'];
        $tarifa = $picked['tarifa'];

        return [
            'velocidad_descarga_mbps' => $down,
            'velocidad_subida_mbps' => $up,
            'tarifa_mensual' => $tarifa,
            'nombre_plan' => "Fibra Óptica {$down} / {$up} Mbps",
            'mikrotik_queue_name' => "Q-{$down}M-{$up}M",
            'activo' => true,
        ];
    }

    /**
     * Recalcula campos derivados en base a los atributos finales (incluyendo overrides por sequence).
     */
    public function configure()
    {
        return $this->afterMaking(function (Plan $plan) {
            $down = $plan->velocidad_descarga_mbps;
            $up = $plan->velocidad_subida_mbps;
            $plan->nombre_plan = "Fibra Óptica {$down} / {$up} Mbps";
            $plan->mikrotik_queue_name = "Q-{$down}M-{$up}M";
        });
    }
}