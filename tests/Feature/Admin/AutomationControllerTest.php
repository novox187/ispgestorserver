<?php

use App\Models\AutomationSetting;
use App\Models\Employee;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeAutomationEmployee(): Employee
{
    $role = Role::firstOrCreate(
        ['slug' => 'super_admin'],
        ['nombre' => 'Super Admin', 'descripcion' => '']
    );
    return Employee::factory()->create(['role_id' => $role->id]);
}

function seedSuspensionAutomation(): AutomationSetting
{
    return AutomationSetting::create([
        'key'             => 'client_suspension',
        'name'            => 'Suspensión Automática',
        'description'     => 'Suspende clientes vencidos',
        'job_class'       => \App\Jobs\ProcessClientSuspension::class,
        'queue'           => 'suspensions',
        'enabled'         => true,
        'schedule_type'   => 'daily',
        'schedule_config' => ['time' => '02:00'],
        'params'          => ['grace_days' => 3],
        'params_schema'   => [
            'grace_days' => [
                'type'     => 'integer',
                'label'    => 'Días de gracia',
                'min'      => 0,
                'max'      => 30,
                'required' => true,
            ],
        ],
    ]);
}

describe('GET /admin/automations', function () {

    it('lista todas las automatizaciones', function () {
        seedSuspensionAutomation();

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations');

        $res->assertStatus(200);
        expect($res->json())->toHaveCount(1);
        expect($res->json('0.key'))->toBe('client_suspension');
    });

    it('requiere autenticación', function () {
        $this->getJson('/api/admin/automations')->assertStatus(401);
    });
});

describe('GET /admin/automations/{key}', function () {

    it('devuelve una automatización específica', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations/client_suspension')
            ->assertStatus(200)
            ->assertJsonPath('key', 'client_suspension')
            ->assertJsonPath('enabled', true);
    });

    it('devuelve 404 si la key no existe', function () {
        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations/inexistente')
            ->assertStatus(404);
    });
});

describe('PUT /admin/automations/{key}', function () {

    it('actualiza enabled y schedule_config válidos', function () {
        seedSuspensionAutomation();

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'enabled'         => false,
                'schedule_type'   => 'daily',
                'schedule_config' => ['time' => '03:30'],
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('schedule_config.time', '03:30');
    });

    it('actualiza params válidos', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'params' => ['grace_days' => 7],
            ])
            ->assertStatus(200)
            ->assertJsonPath('params.grace_days', 7);
    });

    it('rechaza params fuera de rango', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'params' => ['grace_days' => 100],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['grace_days']]);
    });

    it('rechaza schedule_type inválido', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'schedule_type'   => 'invalid_type',
                'schedule_config' => [],
            ])
            ->assertStatus(422);
    });

    it('rechaza hora con formato inválido', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'schedule_type'   => 'daily',
                'schedule_config' => ['time' => '25:99'],
            ])
            ->assertStatus(422);
    });

    it('genera entrada de auditoría al modificar', function () {
        $automation = seedSuspensionAutomation();
        $auditCountBefore = \App\Models\Audit::where('table_name', 'automation_settings')->count();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'params' => ['grace_days' => 10],
            ])
            ->assertStatus(200);

        $auditCountAfter = \App\Models\Audit::where('table_name', 'automation_settings')->count();
        expect($auditCountAfter)->toBeGreaterThan($auditCountBefore);
    });
});

describe('GET /admin/automations/{key}/audits', function () {

    it('devuelve el historial de cambios de una automatización', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'params' => ['grace_days' => 5],
            ])->assertStatus(200);

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations/client_suspension/audits');

        $res->assertStatus(200);
        expect($res->json())->not->toBeEmpty();
        expect($res->json('0'))->toHaveKeys(['operation', 'old_values', 'new_values', 'created_at']);
    });
});

describe('POST /admin/automations/{key}/run-now', function () {

    it('despacha el job inmediatamente cuando está habilitado', function () {
        \Illuminate\Support\Facades\Bus::fake();
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->postJson('/api/admin/automations/client_suspension/run-now')
            ->assertStatus(200)
            ->assertJsonStructure(['message']);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\ProcessClientSuspension::class);
    });

    it('rechaza ejecución cuando la automatización está deshabilitada', function () {
        $a = seedSuspensionAutomation();
        $a->update(['enabled' => false]);

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->postJson('/api/admin/automations/client_suspension/run-now')
            ->assertStatus(400);
    });
});

describe('AutomationSettingsService', function () {

    it('valida params correctamente para integer con rango', function () {
        $automation = seedSuspensionAutomation();
        $service = app(\App\Services\AutomationSettingsService::class);

        expect($service->validateParams($automation, ['grace_days' => 5]))->toBeEmpty();
        expect($service->validateParams($automation, ['grace_days' => -1]))->not->toBeEmpty();
        expect($service->validateParams($automation, ['grace_days' => 31]))->not->toBeEmpty();
        expect($service->validateParams($automation, ['grace_days' => 'abc']))->not->toBeEmpty();
    });

    it('valida schedules daily/monthly/cron', function () {
        $service = app(\App\Services\AutomationSettingsService::class);

        expect($service->validateSchedule('daily', ['time' => '14:30']))->toBeEmpty();
        expect($service->validateSchedule('daily', ['time' => '25:00']))->not->toBeEmpty();
        expect($service->validateSchedule('monthly', ['day' => 15, 'time' => '01:00']))->toBeEmpty();
        expect($service->validateSchedule('monthly', ['day' => 30, 'time' => '01:00']))->not->toBeEmpty();
        expect($service->validateSchedule('cron', ['expression' => '0 2 * * *']))->toBeEmpty();
        expect($service->validateSchedule('cron', ['expression' => 'invalid']))->not->toBeEmpty();
    });
});
