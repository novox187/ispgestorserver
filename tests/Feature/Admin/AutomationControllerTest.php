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

/**
 * Varias automatizaciones las siembran las migraciones, así que en los tests se
 * actualizan en vez de crearse: `create()` chocaba contra la clave única.
 */
function upsertAutomation(string $key, array $attrs): AutomationSetting
{
    return AutomationSetting::updateOrCreate(['key' => $key], $attrs);
}

describe('GET /admin/automations', function () {

    it('lista todas las automatizaciones con su política', function () {
        seedSuspensionAutomation();

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations');

        $res->assertStatus(200);
        $keys = collect($res->json('data'))->pluck('key')->all();
        expect($keys)->toContain('client_suspension');

        // El panel necesita los límites, no solo el estado: sin ellos tendría
        // que volver a codificarlos en el navegador.
        $corte = collect($res->json('data'))->firstWhere('key', 'client_suspension');
        expect($corte['policy']['risk'])->toBe('critical');
        expect($corte['policy']['allowed_schedules'])->toBe(['daily']);
        expect($corte['policy']['if_disabled'])->not->toBeEmpty();
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

/* -------------------------------------------------------------------------- */
/*  Límites de configuración                                                  */
/* -------------------------------------------------------------------------- */

describe('Límites por worker', function () {

    it('rechaza una cadencia que no tiene sentido para el worker', function () {
        upsertAutomation('monthly_invoices', [
            'key'         => 'monthly_invoices',
            'name'            => 'Generación Mensual de Facturas',
            'job_class'       => \App\Jobs\GenerateMonthlyInvoices::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'monthly',
            'schedule_config' => ['day' => 1, 'time' => '01:00'],
            'params'          => [],
            'params_schema'   => [],
        ]);

        // El panel ofrecía las ocho cadencias a todos los workers por igual:
        // se podía facturar «cada cinco minutos».
        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/monthly_invoices', [
                'schedule_type'   => 'every_five_minutes',
                'schedule_config' => [],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['schedule_type']]);

        expect(AutomationSetting::where('key', 'monthly_invoices')->value('schedule_type'))
            ->toBe('monthly');
    });

    it('acepta una cadencia permitida para el worker', function () {
        seedSuspensionAutomation();

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'schedule_type'   => 'daily',
                'schedule_config' => ['time' => '05:30'],
            ])
            ->assertStatus(200);

        expect(AutomationSetting::where('key', 'client_suspension')->value('schedule_config'))
            ->toBe(['time' => '05:30']);
    });

    it('el bloqueo vale también fuera del panel', function () {
        seedSuspensionAutomation();

        // Ocultar la opción en el desplegable no basta: la API se llama igual
        // desde curl.
        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->putJson('/api/admin/automations/client_suspension', [
                'schedule_type'   => 'cron',
                'schedule_config' => ['expression' => '* * * * *'],
            ])
            ->assertStatus(422);
    });
});

/* -------------------------------------------------------------------------- */
/*  Coherencia entre workers                                                  */
/* -------------------------------------------------------------------------- */

describe('Coherencia del conjunto', function () {

    it('avisa cuando el corte corre antes que el cobro', function () {
        seedSuspensionAutomation(); // corte a las 02:00

        upsertAutomation('auto_billing', [
            'key'         => 'auto_billing',
            'name'            => 'Cobros Automáticos',
            'job_class'       => \App\Jobs\ProcessAutoBilling::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'daily',
            'schedule_config' => ['time' => '03:00'], // cobra DESPUÉS de cortar
            'params'          => [],
            'params_schema'   => [],
        ]);

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations');

        $titulos = collect($res->json('warnings'))->pluck('title')->all();
        expect($titulos)->toContain('El corte corre antes que el cobro');
    });

    it('avisa cuando se corta pero no se reactiva', function () {
        seedSuspensionAutomation();

        upsertAutomation('auto_reactivation', [
            'key'         => 'auto_reactivation',
            'name'            => 'Reactivación Automática',
            'job_class'       => \App\Jobs\ProcessAutoReactivationSweep::class,
            'queue'           => 'reactivations',
            'enabled'         => false,
            'schedule_type'   => 'daily',
            'schedule_config' => ['time' => '10:00'],
            'params'          => [],
            'params_schema'   => [],
        ]);

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations');

        expect(collect($res->json('warnings'))->pluck('title')->all())
            ->toContain('Se corta pero no se restablece');
    });

    it('no inventa avisos cuando la configuración es coherente', function () {
        seedSuspensionAutomation();
        AutomationSetting::where('key', 'client_suspension')
            ->update(['schedule_config' => ['time' => '04:00']]);

        upsertAutomation('auto_billing', [
            'key'         => 'auto_billing', 'name' => 'Cobros', 'job_class' => \App\Jobs\ProcessAutoBilling::class,
            'queue' => 'default', 'enabled' => true, 'schedule_type' => 'daily',
            'schedule_config' => ['time' => '02:00'], 'params' => [], 'params_schema' => [],
        ]);
        upsertAutomation('auto_reactivation', [
            'key'         => 'auto_reactivation', 'name' => 'Reactivación', 'job_class' => \App\Jobs\ProcessAutoReactivationSweep::class,
            'queue' => 'reactivations', 'enabled' => true, 'schedule_type' => 'daily',
            'schedule_config' => ['time' => '10:00'], 'params' => [], 'params_schema' => [],
        ]);

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->getJson('/api/admin/automations');

        $titulos = collect($res->json('warnings'))->pluck('title')->all();
        expect($titulos)->not->toContain('El corte corre antes que el cobro');
        expect($titulos)->not->toContain('Se corta pero no se restablece');
    });
});

/* -------------------------------------------------------------------------- */
/*  Previsualización de impacto                                               */
/* -------------------------------------------------------------------------- */

describe('POST /admin/automations/{key}/impact', function () {

    it('cuenta a cuántos clientes afectaría bajar los días de gracia', function () {
        seedSuspensionAutomation();

        $cliente = \App\Models\Client::factory()->active()->create();
        $plan    = \App\Models\Plan::factory()->create();
        $cp = \App\Models\ClientPlan::create([
            'client_id' => $cliente->id, 'plan_id' => $plan->id,
            'start_date' => now()->subMonths(2)->toDateString(), 'billing_cycle' => 'monthly',
            'status' => 'active', 'next_billing_date' => now()->addMonth()->toDateString(),
            'current_price' => 25.00,
        ]);
        // Vencida hace 2 días: fuera con 3 días de gracia, dentro con 1.
        \App\Models\Invoice::create([
            'client_id' => $cliente->id, 'client_plan_id' => $cp->id,
            'invoice_number' => 'INV-IMP-' . uniqid(),
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->subDays(2)->toDateString(),
            'amount' => 25.00, 'tax_amount' => 0, 'total_amount' => 25.00,
            'status' => \App\Models\Invoice::STATUS_PENDING,
        ]);

        $res = $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->postJson('/api/admin/automations/client_suspension/impact', [
                'params' => ['grace_days' => 1],
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('supported', true)
            ->assertJsonPath('count', 1)        // con 1 día de gracia, entra
            ->assertJsonPath('current_count', 0); // con los 3 actuales, no
    });

    it('responde que no aplica en workers sin impacto medible', function () {
        upsertAutomation('device_metrics_rollup', [
            'key'         => 'device_metrics_rollup', 'name' => 'Métricas',
            'job_class' => \App\Jobs\RollUpDeviceMetricsJob::class, 'queue' => 'default',
            'enabled' => true, 'schedule_type' => 'hourly', 'schedule_config' => [],
            'params' => [], 'params_schema' => [],
        ]);

        $this->actingAs(makeAutomationEmployee(), 'sanctum')
            ->postJson('/api/admin/automations/device_metrics_rollup/impact', ['params' => []])
            ->assertStatus(200)
            ->assertJsonPath('supported', false);
    });
});
