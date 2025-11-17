<?php

namespace Database\Factories;

use App\Models\Support;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente al factory.
     * @var string
     */
    protected $model = Support::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Clave Foránea
            'fk_id_cliente' => Client::factory(),

            'subject' => $this->faker->randomElement([
                'Baja velocidad en la noche',
                'No hay servicio',
                'Problema con el router Wi-Fi',
                'Quiero cambiar mi plan',
                'Falla intermitente de conexión',
            ]),
            'description' => $this->faker->paragraph(),
            'open_date' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'priority' => $this->faker->randomElement(['HIGH', 'MEDIUM', 'LOW']),
            'status' => $this->faker->randomElement(['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED']),
            'assigned_technician' => $this->faker->optional(0.7)->randomElement(['Juan Pérez', 'Ana Gómez', 'Carlos Ríos']),
        ];
    }
}