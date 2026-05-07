<?php

use App\Models\Employee;
use App\Models\InternetServiceProvider;
use App\Models\IspConnection;
use App\Models\Plan;
use App\Services\MikroTikQueueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedIspCapacityForPlansTests(float $down, float $up): void
{
    $isp = InternetServiceProvider::query()->create([
        'company_name' => 'ISP Test',
        'is_active' => true,
    ]);

    IspConnection::query()->create([
        'isp_id' => $isp->id,
        'bandwidth_down' => $down,
        'bandwidth_up' => $up,
        'ratio' => '1:1',
        'contract_date' => now()->toDateString(),
        'billing_day' => 1,
        'billing_cycle' => 'monthly',
        'monthly_price' => 0,
        'interface_name' => null,
        'status' => 'active',
    ]);
}

function mockPlanQueueSyncOk(): void
{
    $mock = Mockery::mock(MikroTikQueueSyncService::class);
    $mock->shouldReceive('ensurePlanQueue')->andReturn([
        'action' => 'created',
        'name' => 'plan_queue_test',
    ]);
    app()->instance(MikroTikQueueSyncService::class, $mock);
}

it('permite crear un plan dentro de la capacidad disponible por planes', function () {
    seedIspCapacityForPlansTests(200, 200);
    mockPlanQueueSyncOk();

    Plan::factory()->create([
        'name' => 'Plan 100',
        'slug' => 'plan-100',
        'download_speed' => 100,
        'upload_speed' => 100,
    ]);

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/planes', [
            'name' => 'Plan 50',
            'download_speed' => 50,
            'upload_speed' => 50,
            'ratio' => '1:1',
            'monthly_price' => 10,
            'is_active' => true,
        ])
        ->assertStatus(201);

    $this->assertDatabaseHas('plans', [
        'name' => 'Plan 50',
        'download_speed' => 50,
        'upload_speed' => 50,
    ]);
});

it('rechaza crear un plan que excede la capacidad disponible por planes', function () {
    seedIspCapacityForPlansTests(200, 200);
    mockPlanQueueSyncOk();

    Plan::factory()->create([
        'name' => 'Plan 100',
        'slug' => 'plan-100',
        'download_speed' => 100,
        'upload_speed' => 100,
    ]);

    $employee = Employee::factory()->create();

    $this->actingAs($employee, 'sanctum')
        ->postJson('/api/admin/planes', [
            'name' => 'Plan 200',
            'download_speed' => 200,
            'upload_speed' => 200,
            'ratio' => '1:1',
            'monthly_price' => 10,
            'is_active' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ISP_CAPACITY_EXHAUSTED')
        ->assertJsonPath('message', 'Capacidad insuficiente: tiene 100 megas disponibles de 200 totales');
});

it('calcula correctamente capacidad con múltiples planes existentes', function () {
    seedIspCapacityForPlansTests(200, 200);
    mockPlanQueueSyncOk();

    Plan::factory()->create([
        'name' => 'Plan 50',
        'slug' => 'plan-50',
        'download_speed' => 50,
        'upload_speed' => 50,
    ]);
    Plan::factory()->create([
        'name' => 'Plan 70',
        'slug' => 'plan-70',
        'download_speed' => 70,
        'upload_speed' => 70,
    ]);

    $employee = Employee::factory()->create();

    $res = $this->actingAs($employee, 'sanctum')
        ->getJson('/api/admin/planes/summary')
        ->assertStatus(200);

    expect((float) $res->json('capacity.plans_assigned_down_mbps'))->toBe(120.0);
    expect((float) $res->json('capacity.plans_remaining_down_mbps'))->toBe(80.0);
});

