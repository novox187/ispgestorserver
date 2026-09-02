<?php

use App\Models\ProvisioningAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Instalador desatendido.
 *
 * Lo que se protege aquí es que la comodidad no se coma la seguridad: la URL
 * tiene que exigir firma, caducar, y no llevar nunca el token en claro.
 */
function urlInstalador(ProvisioningAgent $agent, ?int $minutos = 30): string
{
    return URL::temporarySignedRoute('agent.installer', now()->addMinutes($minutos), ['id' => $agent->id]);
}

function agenteDePrueba(string $role = 'provisioner'): ProvisioningAgent
{
    return ProvisioningAgent::create([
        'name'      => 'Agente ' . $role,
        'role'      => $role,
        'is_active' => true,
    ]);
}

it('entrega un script ejecutable con el token ya incrustado', function () {
    $agent = agenteDePrueba();

    $res = $this->get(urlInstalador($agent));

    $res->assertOk()
        ->assertHeader('Content-Type', 'text/x-shellscript; charset=utf-8');

    $script = $res->getContent();

    expect($script)->toStartWith('#!/usr/bin/env bash')
        ->and($script)->toContain('ROLE="provisioner"')
        // El token en claro solo existe dentro del script.
        ->and($script)->not->toContain('{{TOKEN}}')
        ->and($script)->not->toContain('{{PAYLOAD}}');

    // Y el token incrustado es el que de verdad sirve para enrolar.
    preg_match('/ENROLLMENT_TOKEN="([^"]+)"/', $script, $m);
    expect($m[1] ?? '')->not->toBeEmpty();
    expect(ProvisioningAgent::findByEnrollmentToken($m[1])?->id)->toBe($agent->id);
});

it('incrusta el agente entero, no una referencia a otra descarga', function () {
    $script = $this->get(urlInstalador(agenteDePrueba()))->getContent();

    preg_match("/<<'PAYLOAD_B64'[^\\n]*\\n(.*?)\\nPAYLOAD_B64/s", $script, $m);
    $zip = base64_decode(preg_replace('/\s+/', '', $m[1] ?? ''), true);

    expect($zip)->not->toBeFalse()
        // Cabecera de un ZIP: el paquete viaja de verdad, no es un marcador.
        ->and(substr((string) $zip, 0, 2))->toBe('PK');

    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, $zip);
    $archivo = new ZipArchive();
    $archivo->open($tmp);

    $nombres = [];
    for ($i = 0; $i < $archivo->numFiles; $i++) {
        $nombres[] = $archivo->getNameIndex($i);
    }
    $archivo->close();
    @unlink($tmp);

    expect($nombres)->toContain('install.sh')
        ->toContain('ispgestor-agent.service')
        ->toContain('ispgestor_agent/__main__.py');
});

it('el script generado es bash válido', function () {
    // La plantilla se edita a mano y un paréntesis de más solo se vería al
    // ejecutarla en la máquina del cliente, que es el peor sitio para enterarse.
    $script = $this->get(urlInstalador(agenteDePrueba('vpn_host')))->getContent();

    $ruta = tempnam(sys_get_temp_dir(), 'instalador') . '.sh';
    file_put_contents($ruta, $script);

    $salida = [];
    $codigo = 0;
    exec('bash -n ' . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);
    @unlink($ruta);

    expect($codigo)->toBe(0, 'bash -n falló: ' . implode("\n", $salida));
})->skip(fn () => !file_exists('/bin/bash'), 'Sin bash en este entorno.');

it('rechaza la descarga sin firma', function () {
    $agent = agenteDePrueba();

    $this->get("/api/agent/installer/{$agent->id}")->assertForbidden();
});

it('rechaza una firma manipulada', function () {
    $agent = agenteDePrueba();
    $url   = urlInstalador($agent);

    // Cambiar el agente destino invalida la firma: sin esto, cualquiera con un
    // enlace de un agente podría pedir el instalador de otro.
    $otro = agenteDePrueba('vpn_host');
    $this->get(str_replace("/{$agent->id}?", "/{$otro->id}?", $url))->assertForbidden();
});

it('rechaza la descarga caducada', function () {
    $url = urlInstalador(agenteDePrueba(), 30);

    $this->travel(31)->minutes();

    $this->get($url)->assertForbidden();
});

it('cada descarga invalida el token de la anterior', function () {
    $agent = agenteDePrueba();

    $primero = $this->get(urlInstalador($agent))->getContent();
    $segundo = $this->get(urlInstalador($agent))->getContent();

    preg_match('/ENROLLMENT_TOKEN="([^"]+)"/', $primero, $a);
    preg_match('/ENROLLMENT_TOKEN="([^"]+)"/', $segundo, $b);

    expect($a[1])->not->toBe($b[1])
        ->and(ProvisioningAgent::findByEnrollmentToken($a[1]))->toBeNull()
        ->and(ProvisioningAgent::findByEnrollmentToken($b[1])?->id)->toBe($agent->id);
});

it('el token nunca viaja en la URL', function () {
    $agent = agenteDePrueba();
    $url   = urlInstalador($agent);

    $script = $this->get($url)->getContent();
    preg_match('/ENROLLMENT_TOKEN="([^"]+)"/', $script, $m);

    expect($url)->not->toContain($m[1]);
});

it('el panel entrega la orden de instalación al crear un agente', function () {
    Sanctum::actingAs(makeSuperAdminEmployee(), ['*']);

    $res = $this->postJson('/api/admin/provisioning/agents', [
        'name' => 'Oficina',
        'role' => 'provisioner',
    ]);

    $res->assertCreated();
    $orden = $res->json('data.installer_command');

    expect($orden)->toContain('curl -fsSL')
        ->toContain('/api/agent/installer/')
        ->toContain('| sudo bash')
        // La firma es lo que autoriza la descarga.
        ->toContain('signature=');
});
