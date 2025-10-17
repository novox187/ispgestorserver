<?php

namespace Database\Factories;

use App\Models\Soporte;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

class SoporteFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente al factory.
     * @var string
     */
    protected $model = Soporte::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Clave Foránea
            'fk_id_cliente' => Cliente::factory(),

            'asunto' => $this->faker->randomElement([
                'Baja velocidad en la noche',
                'No hay servicio',
                'Problema con el router Wi-Fi',
                'Quiero cambiar mi plan',
                'Falla intermitente de conexión',
            ]),
            'descripcion' => $this->faker->paragraph(),
            'fecha_apertura' => $this->faker->dateTimeBetween('-6 months', 'now'),
            'prioridad' => $this->faker->randomElement(['ALTA', 'MEDIA', 'BAJA']),
            'estatus_ticket' => $this->faker->randomElement(['ABIERTO', 'EN_PROCESO', 'RESUELTO', 'RESUELTO', 'CERRADO']),
            'tecnico_asignado' => $this->faker->optional(0.7)->randomElement(['Juan Pérez', 'Ana Gómez', 'Carlos Ríos']),
        ];
    }
}