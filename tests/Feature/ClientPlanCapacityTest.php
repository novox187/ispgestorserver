<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\Employee;
use App\Models\Plan;
use App\Services\MikroTikQueueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Payload base para crear un cliente. Se sobreescribe por test.
 */
function clientePayloadBase(array $overrides = []): array
{
    return array_merge([
        'full_name'            => 'Cliente Prueba',
        'document_id'          => '12345678',
        'contact_phone'        => '0999999999',
        'email'                => 'cliente@example.com',
        'password'             => 'secret123',
        'installation_address' => 'Calle Principal 123',
        'contract_date'        => now()->toDateString(),
        'ip'                   => '192.168.1.100',
    ], $overrides);
}

/**
 * Rellena el plan con N clientes activos para simular saturación.
 */
function llenarPlanConClientes(Plan $plan, int $cantidad): void
{
    for ($i = 0; $i < $cantidad; $i++) {
        $client = Client::factory()->create();
        ClientPlan::factory()->create([
            'client_id'     => $client->id,
            'plan_id'       => $plan->id,
            'status'        => 'active',
            'current_price' => $plan->monthly_price,
        ]);
    }
}

// ─── Tests de la validación de capacidad por plan ────────────────────────────

it('permite crear un cliente en un plan con capacidad aunque no existan conexiones ISP físicas', function () {
    // Sin conexiones ISP → ancho de banda físico global = 0.
    // El código anterior bloqueaba cualquier creación de cliente en este escenario;
    // la corrección permite crearlos mientras el plan destino tenga capacidad propia.
    $plan = Plan::factory()->create([
        'name'           => 'Plan Test',
        'slug'           => 'plan-test-cap',
        'download_speed' => 10,
        'upload_speed'   => 5,
        'ratio'          => '1:10',
    ]);

    $mock = Mockery::mock(MikroTikQueueSyncService::class);
    $mock->shouldReceive('createClientAndQueue')->once()->andReturn([
        'action'     => 'created',
        'queue_name' => 'cliente_prueba_12345678',
    ]);
    app()->instance(MikroTikQueueSyncService::class, $mock);

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/clientes/crear', clientePayloadBase([
            'plan_id' => $plan->id,
        ]))
        ->assertStatus(201);

    $this->assertDatabaseHas('clients', ['document_id' => '12345678']);
});

it('rechaza crear un cliente cuando el plan ha alcanzado su capacidad máxima (ratio 1:2)', function () {
    $plan = Plan::factory()->create([
        'name'           => 'Plan Pequeño',
        'slug'           => 'plan-pequeno-cap',
        'download_speed' => 10,
        'upload_speed'   => 5,
        'ratio'          => '1:2', // máximo 2 clientes
    ]);

    llenarPlanConClientes($plan, 2); // plan lleno

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/clientes/crear', clientePayloadBase([
            'document_id' => '99999999',
            'email'       => 'tercero@example.com',
            'plan_id'     => $plan->id,
            'ip'          => '192.168.1.103',
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'PLAN_CAPACITY_EXHAUSTED');

    $this->assertDatabaseMissing('clients', ['document_id' => '99999999']);
});

it('permite crear cliente en plan B con capacidad aunque plan A (mismo ISP) esté lleno', function () {
    // Plan A lleno con ratio 1:2 (2 clientes)
    $planA = Plan::factory()->create([
        'name'           => 'Plan A',
        'slug'           => 'plan-a-cap',
        'download_speed' => 10,
        'upload_speed'   => 5,
        'ratio'          => '1:2',
    ]);
    llenarPlanConClientes($planA, 2);

    // Plan B vacío con ratio 1:2 (tiene capacidad)
    $planB = Plan::factory()->create([
        'name'           => 'Plan B',
        'slug'           => 'plan-b-cap',
        'download_speed' => 10,
        'upload_speed'   => 5,
        'ratio'          => '1:2',
    ]);

    $mock = Mockery::mock(MikroTikQueueSyncService::class);
    $mock->shouldReceive('createClientAndQueue')->once()->andReturn([
        'action'     => 'created',
        'queue_name' => 'cliente_planb_11111111',
    ]);
    app()->instance(MikroTikQueueSyncService::class, $mock);

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/clientes/crear', clientePayloadBase([
            'document_id' => '11111111',
            'email'       => 'planb@example.com',
            'plan_id'     => $planB->id,
            'ip'          => '192.168.1.200',
        ]))
        ->assertStatus(201);

    $this->assertDatabaseHas('clients', ['document_id' => '11111111']);
});

it('permite crear el último cliente disponible en un plan (slot exacto)', function () {
    $plan = Plan::factory()->create([
        'name'           => 'Plan Justo',
        'slug'           => 'plan-justo-cap',
        'download_speed' => 10,
        'upload_speed'   => 5,
        'ratio'          => '1:3', // máximo 3 clientes
    ]);

    llenarPlanConClientes($plan, 2); // 2 de 3 ocupados

    $mock = Mockery::mock(MikroTikQueueSyncService::class);
    $mock->shouldReceive('createClientAndQueue')->once()->andReturn([
        'action'     => 'created',
        'queue_name' => 'cliente_justo_55555555',
    ]);
    app()->instance(MikroTikQueueSyncService::class, $mock);

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/clientes/crear', clientePayloadBase([
            'document_id' => '55555555',
            'email'       => 'justo@example.com',
            'plan_id'     => $plan->id,
            'ip'          => '192.168.1.50',
        ]))
        ->assertStatus(201);

    $this->assertDatabaseHas('clients', ['document_id' => '55555555']);
});
