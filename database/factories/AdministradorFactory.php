<?php

namespace Database\Factories;

use App\Models\Administrador;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdministradorFactory extends Factory
{
    protected $model = Administrador::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'telefono' => $this->faker->numerify('########'),
            'remember_token' => Str::random(10),
            'fk_rol_id' => Rol::factory(),
        ];
    }
}
