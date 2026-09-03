<?php

use App\Models\ProvisioningAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Reconfirmación de contraseña en las acciones que rompen un agente.
 *
 * Lo que se protege aquí es que el freno esté en el SERVIDOR. Un modal en el
 * panel evita el clic accidental, pero deja la API abierta: con una sesión
 * robada —o un portátil sin bloquear— revocar un agente sería una sola llamada
 * HTTP. Y revocar significa volver a instalarlo en la máquina donde vive, que
 * puede estar en la oficina de un cliente.
 */
const CLAVE = 'clave-de-prueba-123';

beforeEach(function () {
    $this->admin = makeSuperAdminEmployee(['password' => CLAVE]);
    Sanctum::actingAs($this->admin, ['*']);

    $this->agent = ProvisioningAgent::create([
        'name' => 'Monitor (hosting)', 'role' => 'monitor', 'is_active' => true,
    ]);

    RateLimiter::clear('confirmar-clave:' . $this->admin->id);
});

// ── Eliminar ─────────────────────────────────────────────────────────────────

it('no elimina un agente sin la contraseña', function () {
    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_REQUIRED');

    expect(ProvisioningAgent::find($this->agent->id))->not->toBeNull();
});

it('no elimina un agente con la contraseña equivocada', function () {
    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => 'otra-cosa'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_INVALID');

    expect(ProvisioningAgent::find($this->agent->id))->not->toBeNull();
});

it('elimina el agente con la contraseña correcta', function () {
    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => CLAVE])
        ->assertStatus(204);

    expect(ProvisioningAgent::find($this->agent->id))->toBeNull();
});

// ── Regenerar credenciales ───────────────────────────────────────────────────

it('no regenera las credenciales sin la contraseña', function () {
    $this->postJson("/api/admin/provisioning/agents/{$this->agent->id}/regenerate-token")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_REQUIRED');
});

it('regenera las credenciales con la contraseña correcta', function () {
    $this->postJson("/api/admin/provisioning/agents/{$this->agent->id}/regenerate-token", ['password' => CLAVE])
        ->assertOk()
        ->assertJsonStructure(['data' => ['enrollment_token']]);
});

// ── Desactivar ───────────────────────────────────────────────────────────────

it('no desactiva un agente sin la contraseña', function () {
    $this->putJson("/api/admin/provisioning/agents/{$this->agent->id}", ['is_active' => false])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_REQUIRED');

    expect($this->agent->fresh()->is_active)->toBeTrue();
});

it('desactiva con la contraseña correcta', function () {
    $this->putJson("/api/admin/provisioning/agents/{$this->agent->id}", [
        'is_active' => false, 'password' => CLAVE,
    ])->assertOk();

    expect($this->agent->fresh()->is_active)->toBeFalse();
});

it('renombrar no pide contraseña: no rompe nada', function () {
    // Exigirla para cada cambio inofensivo enseña a teclearla sin leer, que es
    // justo lo que hace inútil pedirla donde importa.
    $this->putJson("/api/admin/provisioning/agents/{$this->agent->id}", ['name' => 'Monitor renombrado'])
        ->assertOk();

    expect($this->agent->fresh()->name)->toBe('Monitor renombrado');
});

it('reactivar tampoco la pide: devuelve el servicio, no lo quita', function () {
    $this->agent->update(['is_active' => false]);

    $this->putJson("/api/admin/provisioning/agents/{$this->agent->id}", ['is_active' => true])
        ->assertOk();

    expect($this->agent->fresh()->is_active)->toBeTrue();
});

// ── Límite de intentos ───────────────────────────────────────────────────────

it('bloquea tras varios intentos fallidos', function () {
    // Sin límite esto es un oráculo de contraseñas: con la sesión ya abierta se
    // podría probar una lista y averiguar la clave del operador.
    foreach (range(1, 5) as $i) {
        $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => "fallo-{$i}"])
            ->assertStatus(422);
    }

    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => CLAVE])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_LOCKED');

    // Y el bloqueo protege de verdad: ni con la contraseña buena pasa.
    expect(ProvisioningAgent::find($this->agent->id))->not->toBeNull();
});

it('pedir la confirmación y no completarla no acerca al bloqueo', function () {
    // Abrir el modal y cancelar es lo más normal del mundo; no puede gastar
    // intentos ni acabar bloqueando a quien no se equivocó en nada.
    foreach (range(1, 8) as $i) {
        $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_CONFIRMATION_REQUIRED');
    }

    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => CLAVE])
        ->assertStatus(204);
});

it('un acierto limpia los intentos fallidos previos', function () {
    $this->deleteJson("/api/admin/provisioning/agents/{$this->agent->id}", ['password' => 'mal'])
        ->assertStatus(422);

    $this->postJson("/api/admin/provisioning/agents/{$this->agent->id}/regenerate-token", ['password' => CLAVE])
        ->assertOk();

    expect(RateLimiter::attempts('confirmar-clave:' . $this->admin->id))->toBe(0);
});
