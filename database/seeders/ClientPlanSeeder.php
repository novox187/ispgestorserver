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

        foreach ($clients as $client) {
            ClientPlan::factory()
                ->forClient($client)
                ->forPlan($plans->random())
                ->active()
                ->withIp()
                ->create();
        }

        // Crear algunos planes suspendidos
        ClientPlan::factory()
            ->count(5)
            ->suspended()
            ->create();

        // Crear planes que vencen pronto
        ClientPlan::factory()
            ->count(3)
            ->expiringSoon()
            ->create();

        // Plan con precio personalizado (promoción)
        ClientPlan::factory()
            ->forClient($clients->first())
            ->forPlan($plans->first())
            ->withCustomPrice(19.99)
            ->active()
            ->create();

        $this->command->info('ClientPlans creados: ' . count(ClientPlan::all()));
    }
}