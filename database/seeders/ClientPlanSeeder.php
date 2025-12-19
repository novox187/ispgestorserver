<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class ClientPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clients = Client::all();
        $plans = Plan::all();

        if ($clients->isEmpty() || $plans->isEmpty()) {
            $this->command->warn('No hay clientes o planes para generar suscripciones.');
            return;
        }

        foreach ($clients as $client) {
            // 80% de probabilidad de tener un plan ACTIVO
            $hasActivePlan = rand(1, 100) <= 80;

            if ($hasActivePlan) {
                // Crear UN plan activo
                ClientPlan::factory()
                    ->forClient($client)
                    ->forPlan($plans->random())
                    ->active()
                    ->withIp()
                    ->create();
            } else {
                // Si no tiene activo, tiene uno suspendido o cancelado (estado actual)
                $status = rand(1, 100) <= 50 ? 'suspended' : 'cancelled';
                if ($status === 'suspended') {
                    ClientPlan::factory()->forClient($client)->forPlan($plans->random())->suspended()->create();
                } else {
                    ClientPlan::factory()->forClient($client)->forPlan($plans->random())->cancelled()->create();
                }
            }

            // 30% de probabilidad de tener historial (planes antiguos cancelados)
            if (rand(1, 100) <= 30) {
                ClientPlan::factory()
                    ->forClient($client)
                    ->forPlan($plans->random())
                    ->cancelled() // Usar estado cancelled explícito para fechas pasadas
                    ->create();
            }
        }

        $this->command->info('ClientPlans creados correctamente. Se garantizó un máximo de 1 plan activo por cliente.');
    }
}