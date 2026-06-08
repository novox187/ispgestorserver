<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Plan;
use App\Services\ClientSuspensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeClientForDeactivation(string $serviceStatus, string $planStatus = 'suspended'): Client
{
    $client = Client::factory()->create(['service_status' => $serviceStatus]);

    $suffix = uniqid();
    $plan   = Plan::factory()->create(['name' => "Plan {$suffix}", 'slug' => "plan-{$suffix}"]);

    ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => $planStatus,
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 30.00,
    ]);

    return $client;
}

describe('ClientSuspensionService::cancelClient() — baja de cliente', function () {

    it('da de baja a un cliente suspendido: cliente y planes quedan cancelados', function () {
        $client = makeClientForDeactivation('SUSPENDED', 'suspended');

        $result = app(ClientSuspensionService::class)->cancelClient($client, 'Cliente se mudó');

        expect($result['success'])->toBeTrue();
        expect(strtoupper($client->fresh()->service_status))->toBe('CANCELLED');
        expect(ClientPlan::where('client_id', $client->id)->where('status', '!=', 'cancelled')->count())->toBe(0);

        $plan = ClientPlan::where('client_id', $client->id)->first();
        expect($plan->status)->toBe('cancelled');
        expect($plan->end_date)->not->toBeNull();
    });

    it('rechaza dar de baja a un cliente ACTIVO (debe estar suspendido primero)', function () {
        $client = makeClientForDeactivation('ACTIVE', 'active');

        $result = app(ClientSuspensionService::class)->cancelClient($client, 'intento inválido');

        expect($result['success'])->toBeFalse();
        expect($result['code'])->toBe('NOT_SUSPENDED');
        expect(strtoupper($client->fresh()->service_status))->toBe('ACTIVE');
        // El plan activo no se toca.
        expect(ClientPlan::where('client_id', $client->id)->where('status', 'active')->count())->toBe(1);
    });

    it('es idempotente: dar de baja a un cliente ya cancelado no falla', function () {
        $client = makeClientForDeactivation('CANCELLED', 'cancelled');

        $result = app(ClientSuspensionService::class)->cancelClient($client, 'repetido');

        expect($result['success'])->toBeTrue();
        expect($result['already_cancelled'] ?? false)->toBeTrue();
    });

    it('aplica la baja en BD aunque MikroTik no esté disponible (best-effort)', function () {
        // En el entorno de pruebas no hay router primary, por lo que la liberación
        // de colas falla; la baja debe completarse igualmente en la base de datos.
        $client = makeClientForDeactivation('SUSPENDED', 'suspended');

        $result = app(ClientSuspensionService::class)->cancelClient($client, 'baja con MikroTik caído');

        expect($result['success'])->toBeTrue();
        expect(strtoupper($client->fresh()->service_status))->toBe('CANCELLED');
        expect($result['mikrotik'])->toBeArray();
        // Al menos un intento de liberación de cola se registró como fallido.
        expect(collect($result['mikrotik'])->contains(fn ($r) => ($r['success'] ?? true) === false))->toBeTrue();
    });
});
