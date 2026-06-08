<?php

use App\Models\Client;
use App\Models\ClientPlan;
use App\Models\ClientWhitelist;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Role;
use App\Services\ClientSuspensionService;
use App\Services\ClientWhitelistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function unitMakeAdmin(): Employee
{
    $role = Role::firstOrCreate(
        ['slug' => 'super_admin'],
        ['nombre' => 'Super Admin', 'descripcion' => '']
    );
    return Employee::factory()->create(['role_id' => $role->id]);
}

function unitMakeSuspendedClientWithPlan(): Client
{
    $client = Client::factory()->create(['service_status' => 'SUSPENDED']);

    $suffix = uniqid();
    $plan   = Plan::factory()->create(['name' => "Plan {$suffix}", 'slug' => "plan-{$suffix}"]);

    ClientPlan::create([
        'client_id'         => $client->id,
        'plan_id'           => $plan->id,
        'start_date'        => now()->subMonths(2)->toDateString(),
        'billing_cycle'     => 'monthly',
        'status'            => 'suspended',
        'next_billing_date' => now()->addMonth()->toDateString(),
        'current_price'     => 30.00,
    ]);

    return $client;
}

describe('ClientWhitelistService::isProtected', function () {

    it('devuelve false cuando no hay inclusión', function () {
        $client = Client::factory()->active()->create();
        expect(app(ClientWhitelistService::class)->isProtected($client->id))->toBeFalse();
    });

    it('devuelve true cuando hay inclusión activa sin vencimiento', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient($client, $admin, 'protegido permanente');

        expect(app(ClientWhitelistService::class)->isProtected($client->id))->toBeTrue();
    });

    it('devuelve true cuando la inclusión vence en el futuro', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient(
            $client, $admin, 'protegido temporalmente',
            Carbon::now()->addDays(10),
        );

        expect(app(ClientWhitelistService::class)->isProtected($client->id))->toBeTrue();
    });

    it('devuelve false cuando la inclusión ha vencido', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        // Insertar directamente para forzar fecha pasada (la API valida `after:now`)
        ClientWhitelist::create([
            'client_id'     => $client->id,
            'added_at'      => Carbon::now()->subDays(30),
            'authorized_by' => $admin->id,
            'reason'        => 'expirada',
            'expires_at'    => Carbon::now()->subDay(),
            'active'        => true,
        ]);

        expect(app(ClientWhitelistService::class)->isProtected($client->id))->toBeFalse();
    });

    it('devuelve false cuando la inclusión está desactivada (baja lógica)', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient($client, $admin, 'temporal');
        app(ClientWhitelistService::class)->removeClient($client, $admin);

        expect(app(ClientWhitelistService::class)->isProtected($client->id))->toBeFalse();
    });
});

describe('ClientWhitelistService::addClient', function () {

    it('lanza DomainException si el cliente ya está protegido', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient($client, $admin, 'primero');

        expect(fn () => app(ClientWhitelistService::class)->addClient($client, $admin, 'segundo'))
            ->toThrow(\DomainException::class);
    });

    it('permite re-incluir tras una baja lógica', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        $first = app(ClientWhitelistService::class)->addClient($client, $admin, 'primero');
        app(ClientWhitelistService::class)->removeClient($client, $admin);
        $second = app(ClientWhitelistService::class)->addClient($client, $admin, 'segundo');

        expect($second->id)->not->toBe($first->id);
        expect($client->fresh()->isWhitelisted())->toBeTrue();
    });
});

describe('ClientWhitelistService::addClient — reactivación automática', function () {

    it('reactiva el servicio y los planes de un cliente suspendido al incluirlo', function () {
        $admin  = unitMakeAdmin();
        $client = unitMakeSuspendedClientWithPlan();

        app(ClientWhitelistService::class)->addClient($client, $admin, 'protegido y reactivado');

        // service_status es un ENUM en mayúsculas; isActive() abstrae el casing.
        expect($client->fresh()->isActive())->toBeTrue();
        expect(ClientPlan::where('client_id', $client->id)->where('status', 'active')->count())->toBe(1);
        expect(ClientPlan::where('client_id', $client->id)->where('status', 'suspended')->count())->toBe(0);
    });

    it('no altera el estado de un cliente que ya estaba activo', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        app(ClientWhitelistService::class)->addClient($client, $admin, 'protegido activo');

        expect($client->fresh()->isActive())->toBeTrue();
    });

    it('mantiene la inclusión aunque la reactivación falle (best-effort)', function () {
        $admin  = unitMakeAdmin();
        $client = unitMakeSuspendedClientWithPlan();

        // La reactivación es un efecto secundario: si lanza, no debe revertir la
        // inclusión en la lista blanca (que ya está persistida).
        $this->mock(ClientSuspensionService::class, function (MockInterface $m) {
            $m->shouldReceive('reactivateClient')->once()->andThrow(new \RuntimeException('MikroTik caído'));
        });

        $entry = app(ClientWhitelistService::class)->addClient($client, $admin, 'protegido pese al fallo');

        expect($entry->active)->toBeTrue();
        expect($client->fresh()->isWhitelisted())->toBeTrue();
    });
});

describe('ClientWhitelistService::updateEntry', function () {

    it('actualiza motivo y fecha de vencimiento', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        $entry = app(ClientWhitelistService::class)->addClient($client, $admin, 'inicial');

        $updated = app(ClientWhitelistService::class)->updateEntry(
            entry: $entry,
            changes: [
                'reason'     => 'motivo actualizado',
                'expires_at' => Carbon::now()->addMonth(),
            ],
            authorizedBy: $admin,
        );

        expect($updated->reason)->toBe('motivo actualizado');
        expect($updated->expires_at)->not->toBeNull();
    });

    it('ignora campos no permitidos', function () {
        $admin  = unitMakeAdmin();
        $client = Client::factory()->active()->create();

        $entry = app(ClientWhitelistService::class)->addClient($client, $admin, 'inicial');
        $originalClientId = $entry->client_id;

        app(ClientWhitelistService::class)->updateEntry(
            entry: $entry,
            changes: ['client_id' => 99999, 'active' => false],
            authorizedBy: $admin,
        );

        $entry->refresh();
        expect($entry->client_id)->toBe($originalClientId);
        expect($entry->active)->toBeTrue();
    });
});
