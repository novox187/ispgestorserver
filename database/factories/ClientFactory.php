<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientFactory extends Factory
{
    /**
     * El nombre del modelo correspondiente al factory.
     * @var string
     */
    protected $model = Client::class;

    /**
     * Define el estado predeterminado del modelo.
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nombre = $this->faker->firstName();
        $apellido = $this->faker->lastName();
        
        return [
            'full_name' => "{$nombre} {$apellido}",
            'document_id' => $this->faker->unique()->numerify('##########'), // 10 dígitos únicos
            'contact_phone' => $this->faker->unique()->numerify('########'), // 8 dígitos
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('perro'),
            'installation_address' => $this->faker->address(),
            'gps_coordinates' => $this->faker->latitude() . ',' . $this->faker->longitude(),
            'contract_date' => $this->faker->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'service_status' => $this->faker->randomElement(['ACTIVE', 'LIMITED', 'SUSPENDED', 'CANCELLED']),
            'ip' => $this->faker->ipv4(),
            'observations' => $this->faker->optional(0.2)->sentence(), // 20% de probabilidad de tener observaciones
            'remember_token' => Str::random(10),
        ];
    } // ← QUITÉ EL } EXTRA QUE ESTABA AQUÍ

    /**
     * Cliente activo
     */
    public function active(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'service_status' => 'ACTIVE', // Cambié 'activo' por 'ACTIVO' para coincidir con el definition
                'ip' => $this->faker->ipv4(), // Activos siempre tienen IP
            ];
        });
    }

    /**
     * Cliente inactivo
     */
    public function inactive(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'service_status' => 'INACTIVE', // Agregué INACTIVO
                'ip' => null, // Inactivos no tienen IP
            ];
        });
    }

    /**
     * Cliente suspendido
     */
    public function suspended(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'service_status' => 'SUSPENDED',
                'ip' => $this->faker->optional(0.5)->ipv4(), // 50% tienen IP
            ];
        });
    }

    /**
     * Cliente limitado
     */
    public function limited(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'service_status' => 'LIMITED',
                'ip' => $this->faker->ipv4(), // Limitados tienen IP
            ];
        });
    }

    /**
     * Cliente con IP asignada
     */
    public function withIp(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'ip' => $this->faker->ipv4(),
            ];
        });
    }

    /**
     * Cliente sin IP
     */
    public function withoutIp(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'ip' => null,
            ];
        });
    }

    /**
     * Cliente residencial
     */
    public function residential(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'full_name' => $this->faker->name(),
                'installation_address' => $this->faker->streetAddress(),
            ];
        });
    }

    /**
     * Cliente empresarial
     */
    public function business(): Factory
    {
        return $this->state(function (array $attributes) {
            $company = $this->faker->company();
            
            return [
                'full_name' => $company,
                'email' => $this->faker->companyEmail(),
                'installation_address' => $this->faker->streetAddress() . ', ' . $this->faker->city(),
            ];
        });
    }

    /**
     * Cliente con email específico
     */
    public function withEmail(string $email): Factory
    {
        return $this->state(function (array $attributes) use ($email) {
            return [
                'email' => $email,
            ];
        });
    }

    /**
     * Cliente con nombre específico
     */
    public function withName(string $name): Factory
    {
        return $this->state(function (array $attributes) use ($name) {
            return [
                'full_name' => $name,
            ];
        });
    }

    /**
     * Cliente reciente (contratado en los últimos 30 días)
     */
    public function recent(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'contract_date' => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
                'service_status' => 'ACTIVE',
            ];
        });
    }

    /**
     * Cliente antiguo (contratado hace más de 1 año)
     */
    public function longTerm(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'contract_date' => $this->faker->dateTimeBetween('-2 years', '-1 year')->format('Y-m-d'),
                'service_status' => 'ACTIVE',
            ];
        });
    }
} 