<?php

namespace Database\Factories;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClienteFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente al factory.
     * @var string
     */
    protected $model = Cliente::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->firstName();
        $apellido = $this->faker->lastName();
        
        return [
            'nombre_completo' => "{$nombre} {$apellido}",
            'documento_id' => $this->faker->unique()->numerify('##########'), // 10 dígitos únicos
            'telefono_contacto' => $this->faker->unique()->numerify('########'), // 8 dígitos
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'direccion_instalacion' => $this->faker->address(),
            'coordenadas_gps' => $this->faker->latitude() . ',' . $this->faker->longitude(),
            'fecha_contratacion' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'estatus_servicio' => $this->faker->randomElement(['ACTIVO', 'ACTIVO', 'ACTIVO', 'LIMITADO', 'SUSPENDIDO']),
            'observaciones' => $this->faker->optional(0.2)->sentence(), // 20% de probabilidad de tener observaciones
            'remember_token' => Str::random(10),
        ];
    }
}