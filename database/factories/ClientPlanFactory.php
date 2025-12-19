<?php

namespace Database\Factories;

use App\Models\ClientPlan;
use App\Models\Client;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class ClientPlanFactory extends Factory
{
    protected $model = ClientPlan::class;

    public function definition(): array
    {
        $client = Client::inRandomOrder()->first() ?? Client::factory()->create();
        $plan = Plan::active()->inRandomOrder()->first() ?? Plan::factory()->create();

        // Asegurar que la fecha de inicio sea posterior a la fecha de contrato del cliente
        $minDate = $client->contract_date ? new \DateTime($client->contract_date) : new \DateTime('-1 year');
        $startDate = $this->faker->dateTimeBetween($minDate, 'now');
        
        $billingCycle = $this->faker->randomElement(['monthly', 'quarterly', 'yearly']);
        $status = $this->faker->randomElement(['active', 'active', 'active', 'suspended', 'cancelled']);
        
        $endDate = null;
        if ($status === 'cancelled' || $status === 'suspended') {
            $endDate = $this->faker->dateTimeBetween($startDate, 'now');
        }

        $nextBillingDate = $this->calculateNextBillingDate($status === 'active' ? new \DateTime() : $startDate, $billingCycle);

        return [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'next_billing_date' => $nextBillingDate,
            'current_price' => $plan->monthly_price,
            'billing_cycle' => $billingCycle,
            'ip_address' => $this->faker->optional(0.8)->ipv4(),
            'mikrotik_queue_id' => $this->faker->optional(0.7)->regexify('\*[0-9]{1,3}'),
            'payment_method' => $this->faker->randomElement(['stripe', 'paypal', 'transfer', 'cash', null]),
            'payment_reference' => $this->faker->optional(0.6)->regexify('[A-Z0-9]{10,20}'),
            'notes' => $this->faker->optional(0.2)->text(100),
        ];
    }

    /**
     * Calcular la próxima fecha de facturación basada en el ciclo
     */
    private function calculateNextBillingDate(\DateTime $startDate, string $billingCycle): \DateTime
    {
        $nextDate = clone $startDate;

        switch ($billingCycle) {
            case 'monthly':
                $nextDate->modify('+1 month');
                break;
            case 'quarterly':
                $nextDate->modify('+3 months');
                break;
            case 'yearly':
                $nextDate->modify('+1 year');
                break;
        }

        return $nextDate;
    }

    /**
     * Estados adicionales para testing
     */

    /**
     * Plan activo
     */
    public function active(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-6 months', 'now');
            $billingCycle = $this->faker->randomElement(['monthly', 'quarterly', 'yearly']);
            $nextBillingDate = $this->calculateNextBillingDate($startDate, $billingCycle);

            return [
                'status' => 'active',
                'start_date' => $startDate,
                'end_date' => null,
                'next_billing_date' => $nextBillingDate,
                'billing_cycle' => $billingCycle,
            ];
        });
    }

    /**
     * Plan suspendido
     */
    public function suspended(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-1 year', '-1 month');
            $endDate = $this->faker->dateTimeBetween($startDate, 'now');

            return [
                'status' => 'suspended',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_billing_date' => $this->faker->dateTimeBetween($endDate, '+1 month'),
            ];
        });
    }

    /**
     * Plan cancelado
     */
    public function cancelled(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-2 years', '-3 months');
            $endDate = $this->faker->dateTimeBetween($startDate, '-1 month');

            return [
                'status' => 'cancelled',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_billing_date' => $endDate,
            ];
        });
    }

    /**
     * Plan pendiente (nuevo)
     */
    public function pending(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('+1 day', '+1 week');

            return [
                'status' => 'pending',
                'start_date' => $startDate,
                'end_date' => null,
                'next_billing_date' => $startDate,
            ];
        });
    }

    /**
     * Con ciclo de facturación mensual
     */
    public function monthly(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = new \DateTime($attributes['start_date'] ?? 'now');
            $nextBillingDate = $this->calculateNextBillingDate($startDate, 'monthly');

            return [
                'billing_cycle' => 'monthly',
                'next_billing_date' => $nextBillingDate,
            ];
        });
    }

    /**
     * Con ciclo de facturación trimestral
     */
    public function quarterly(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = new \DateTime($attributes['start_date'] ?? 'now');
            $nextBillingDate = $this->calculateNextBillingDate($startDate, 'quarterly');

            return [
                'billing_cycle' => 'quarterly',
                'next_billing_date' => $nextBillingDate,
            ];
        });
    }

    /**
     * Con ciclo de facturación anual
     */
    public function yearly(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = new \DateTime($attributes['start_date'] ?? 'now');
            $nextBillingDate = $this->calculateNextBillingDate($startDate, 'yearly');

            return [
                'billing_cycle' => 'yearly',
                'next_billing_date' => $nextBillingDate,
            ];
        });
    }

    /**
     * Con IP asignada
     */
    public function withIp(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'ip_address' => $this->faker->ipv4(),
                'mikrotik_queue_id' => $this->faker->regexify('\*[0-9]{1,3}'),
            ];
        });
    }

    /**
     * Sin IP asignada
     */
    public function withoutIp(): Factory
    {
        return $this->state(function (array $attributes) {
            return [
                'ip_address' => null,
                'mikrotik_queue_id' => null,
            ];
        });
    }

    /**
     * Con método de pago específico
     */
    public function withPaymentMethod(string $method): Factory
    {
        return $this->state(function (array $attributes) use ($method) {
            return [
                'payment_method' => $method,
                'payment_reference' => $this->faker->regexify('[A-Z0-9]{10,20}'),
            ];
        });
    }

    /**
     * Para un cliente específico
     */
    public function forClient(Client $client): Factory
    {
        return $this->state(function (array $attributes) use ($client) {
            return [
                'client_id' => $client->id,
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
                'current_price' => $plan->monthly_price,
            ];
        });
    }

    /**
     * Con precio personalizado (útil para promociones)
     */
    public function withCustomPrice(float $price): Factory
    {
        return $this->state(function (array $attributes) use ($price) {
            return [
                'current_price' => $price,
            ];
        });
    }

    /**
     * Plan que vence pronto (en los próximos 7 días)
     */
    public function expiringSoon(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-11 months', '-10 months');
            $nextBillingDate = $this->faker->dateTimeBetween('+1 day', '+7 days');

            return [
                'start_date' => $startDate,
                'next_billing_date' => $nextBillingDate,
                'status' => 'active',
            ];
        });
    }

    /**
     * Plan recién creado (próximo pago en más de 20 días)
     */
    public function recentlyCreated(): Factory
    {
        return $this->state(function (array $attributes) {
            $startDate = $this->faker->dateTimeBetween('-10 days', 'now');
            $nextBillingDate = $this->faker->dateTimeBetween('+21 days', '+30 days');

            return [
                'start_date' => $startDate,
                'next_billing_date' => $nextBillingDate,
                'status' => 'active',
            ];
        });
    }
}