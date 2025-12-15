<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'nombre' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'telefono' => $this->faker->numerify('########'),
            'remember_token' => Str::random(10),
            'role_id' => null,
        ];
    }
}
