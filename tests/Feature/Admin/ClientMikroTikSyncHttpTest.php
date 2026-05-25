<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Employee;
use App\Models\InternetServiceProvider;
use App\Models\IspConnection;
use App\Models\Plan;
use App\Services\MikroTikQueueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Garantiza que existan conexiones ISP con capacidad disponible
 * para que la validación `ISP_CAPACITY_EXHAUSTED` no dispare en
 * tests que no buscan ejercitarla.
 */
function seedAmpleIspCapacity(): void
{
    $isp = InternetServiceProvider::query()->create([
        'company_name' => 'ISP Test Mikrotik',
        'is_active'    => true,
    ]);

    IspConnection::query()->create([
        'isp_id'         => $isp->id,
        'bandwidth_down' => 1000,
        'bandwidth_up'   => 1000,
        'ratio'          => '1:1',
        'contract_date'  => now()->toDateString(),
        'billing_day'    => 1,
        'billing_cycle'  => 'monthly',
        'monthly_price'  => 0,
        'interface_name' => null,
        'status'         => 'active',
    ]);
}

it('exige ip válida al crear cliente si se asigna un plan', function () {
    $employee = makeSuperAdminEmployee();
    $plan = Plan::factory()->create();

    $payload = [
        'full_name' => 'Nombre Cliente',
        'document_id' => '555555',
        'contact_phone' => '0999999999',
        'email' => 'cliente@example.com',
        'password' => 'secret123',
        'installation_address' => 'Av. Siempre Viva 123',
        'contract_date' => now()->toDateString(),
        'plan_id' => $plan->id,
    ];

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/clientes/crear', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.ip.0', fn ($v) => is_string($v) && $v !== '');
});

it('revierte cambios en DB si falla la sincronización con MikroTik al editar', function () {
    seedAmpleIspCapacity();

    $employee = makeSuperAdminEmployee();
    $plan = Plan::factory()->create();
    $client = Client::factory()->create([
        'full_name' => 'Cliente Original',
        'document_id' => '111',
        'email' => 'orig@example.com',
        'ip' => '192.168.20.50',
    ]);

    ClientPlan::factory()->create([
        'client_id' => $client->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'ip_address' => $client->ip,
    ]);

    $mock = Mockery::mock(MikroTikQueueSyncService::class);
    $mock->shouldReceive('syncClientQueueForPlan')->andThrow(new Exception('mk fail'));
    app()->instance(MikroTikQueueSyncService::class, $mock);

    $payload = [
        'full_name' => 'Cliente Modificado',
        'document_id' => '222',
        'email' => 'orig@example.com',
        'ip' => '192.168.20.51',
        'plan_id' => $plan->id,
        'reason' => 'Cambio de datos',
    ];

    $this->actingAs($employee, 'sanctum')
        ->putJson('/api/admin/clientes/' . $client->id, $payload)
        ->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('sync_failed', true);

    $this->assertDatabaseHas('clients', [
        'id' => $client->id,
        'full_name' => 'Cliente Original',
        'document_id' => '111',
        'ip' => '192.168.20.50',
    ]);
});

