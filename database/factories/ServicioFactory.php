<?php

namespace Database\Factories;

use App\Models\Servicio;
use App\Models\Cliente;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServicioFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente al factory.
     * @var string
     */
    protected $model = Servicio::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 1. Definir fechas de pago y vencimiento
        $dia_corte = $this->faker->numberBetween(1, 28);
        $fecha_vencimiento = $this->faker->dateTimeBetween('-1 month', '+15 days');
        $fecha_ultimo_pago = (clone $fecha_vencimiento)->modify('-30 days');


        // 2. Generar IP Estática (simulación)
        // Las IPs deben ser únicas, así que usamos el método unique() de Faker
        $ip_segments = [
            $this->faker->numberBetween(192, 192), // Primer octeto
            $this->faker->numberBetween(168, 168), // Segundo octeto
            $this->faker->numberBetween(1, 254),  // Tercer octeto
            $this->faker->unique()->numberBetween(1, 254), // Cuarto octeto único
        ];
        $ip = implode('.', $ip_segments);

        return [
            // Claves Foráneas
            // fk_id_cliente lo asigna automáticamente has(Servicio::factory()) en el seeder
            'fk_id_plan' => function () {
                $existing = Plan::query()->inRandomOrder()->value('id_plan');
                return $existing ?: Plan::factory();
            },

            // Datos de red y automatización
            'metodo_autenticacion' => 'IP_STATICA',
            'ip_estatica_cliente' => $ip,
            'mac_address_cliente' => $this->faker->macAddress(),

            // Datos de Facturación
            'dia_corte_fijo' => $dia_corte,
            'fecha_proximo_vencimiento' => $fecha_vencimiento->format('Y-m-d'),
            'fecha_ultimo_pago' => $fecha_ultimo_pago->format('Y-m-d'),
        ];
    }
}