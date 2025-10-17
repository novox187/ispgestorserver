<?php

namespace Database\Factories;

use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RolFactory extends Factory
{
    protected $model = Rol::class;

    public function definition(): array
    {
        $nombre = $this->faker->unique()->randomElement(['super_admin', 'facturacion', 'tecnico', 'gerente', 'tecnico_avanzado']);
        return [
            'nombre' => ucfirst(str_replace('_', ' ', $nombre)),
            'slug' => Str::slug($nombre),
            'descripcion' => $this->faker->optional()->sentence(),
        ];
    }
}
