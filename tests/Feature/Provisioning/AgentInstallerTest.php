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
function urlInstalador(ProvisioningAgent $agent, ?int $minutos = 30, ?string $plataforma = null): string
{
    $params = ['id' => $agent->id];

    if ($plataforma !== null) {
        $params['platform'] = $plataforma;
    }

    return URL::temporarySignedRoute('agent.installer', now()->addMinutes($minutos), $params);
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
        // `install.sh` copia la unidad plantilla sin condiciones. Si no viaja en
        // el paquete, la instalación aborta en el `cp` y el fallo solo aparece
        // en la máquina del cliente.
        ->toContain('ispgestor-agent@.service')
        ->toContain('ispgestor_agent/__main__.py');
});

// ── Plataformas ──────────────────────────────────────────────────────────────

it('entrega un script de PowerShell cuando se pide Windows', function () {
    // El provisioner tiene que estar donde se enchufan los routers, y eso suele
    // ser un PC de oficina con Windows. Entregarle bash ahí no sirve de nada.
    $res = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'windows'));
    $script = $res->getContent();

    $res->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="instalar-agente-provisioner.ps1"');

    expect($script)->toContain('$ErrorActionPreference')
        ->toContain('Register-ScheduledTask')
        // Y NO debe llevar nada de bash ni de systemd.
        ->not->toContain('#!/usr/bin/env bash')
        ->not->toContain('systemctl');
});

it('el instalador de Windows exige permisos de administrador', function () {
    // Crea servicios y escribe en Program Files: sin esto fallaría a mitad,
    // dejando el agente instalado pero sin arrancar.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'windows'))->getContent();

    expect($script)->toContain('WindowsBuiltInRole]::Administrator');
});

it('el instalador de Windows protege el secreto con SID y no con nombres', function () {
    // En un Windows en español el grupo se llama «Administradores»: una ACL
    // escrita contra el nombre inglés falla justo en la máquina del cliente.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'windows'))->getContent();

    expect($script)->toContain('/inheritance:r')
        ->toContain('S-1-5-32-544')
        ->toContain('S-1-5-18');
});

it('el script de Windows está estructuralmente entero', function () {
    // El equivalente del `bash -n` de la plantilla de Unix, pero más flojo: sin
    // PowerShell no se puede analizar de verdad. Aun así atrapa lo que suele
    // romperse al editar la plantilla a mano —una llave sin cerrar, el
    // here-string mal terminado, un marcador sin sustituir— y eso solo se vería
    // al ejecutarla en la máquina del cliente.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'windows'))->getContent();

    // Ningún marcador se quedó sin sustituir.
    expect($script)->not->toMatch('/\{\{[A-Z_]+\}\}/');

    // El here-string que envuelve el paquete abre y cierra. Si no, PowerShell
    // se come el resto del script como si fuera texto.
    expect(substr_count($script, "@'\n"))->toBe(1)
        ->and(substr_count($script, "\n'@"))->toBe(1);

    // Llaves y paréntesis equilibrados fuera del bloque del paquete, que es
    // base64 y no contiene ninguno de los dos.
    $codigo = preg_replace("/@'\n.*?\n'@/s", "''", $script);

    expect(substr_count($codigo, '{'))->toBe(substr_count($codigo, '}'))
        ->and(substr_count($codigo, '('))->toBe(substr_count($codigo, ')'));
});

it('PowerShell analiza el script sin errores', function () {
    // La comprobación de verdad, cuando hay con qué hacerla.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'windows'))->getContent();

    $ruta = tempnam(sys_get_temp_dir(), 'instalador') . '.ps1';
    file_put_contents($ruta, $script);

    $guion = '$e = $null; '
        . '[void][System.Management.Automation.Language.Parser]::ParseFile('
        . escapeshellarg($ruta) . ', [ref]$null, [ref]$e); '
        . 'if ($e.Count -gt 0) { $e | ForEach-Object { $_.Message }; exit 1 }';

    $salida = [];
    $codigo = 0;
    exec('pwsh -NoProfile -Command ' . escapeshellarg($guion) . ' 2>&1', $salida, $codigo);
    @unlink($ruta);

    expect($codigo)->toBe(0, "PowerShell rechazó el script:\n" . implode("\n", $salida));
})->skip(fn () => trim((string) shell_exec('command -v pwsh')) === '', 'Sin PowerShell en este entorno.');

it('sin plataforma sigue entregando el script de Unix', function () {
    // Compatibilidad: los enlaces ya generados no llevan el parámetro.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner')))->getContent();

    expect($script)->toStartWith('#!/usr/bin/env bash');
});

it('una plataforma inventada degrada a Unix en vez de fallar', function () {
    $res = $this->get(urlInstalador(agenteDePrueba('provisioner'), 30, 'atari'));

    $res->assertOk();
    expect($res->getContent())->toStartWith('#!/usr/bin/env bash');
});

it('el script de Unix sirve para Linux y para macOS', function () {
    // Un solo script: comparten rutas, permisos y venv. Lo único que difiere
    // —systemd frente a launchd— lo resuelve el paquete.
    $script = $this->get(urlInstalador(agenteDePrueba('provisioner')))->getContent();

    expect($script)->toContain('Darwin')
        ->toContain('ispgestor-agent-service')
        // Ya no debe invocar systemd directamente en ningún sitio.
        ->not->toContain('systemctl enable');
});

it('el paquete lleva la capa de servicio y el plist de launchd', function () {
    // `install.sh` los copia sin condiciones: si faltan, la instalación aborta
    // en el `cp` y el fallo solo se ve en la máquina del cliente.
    $script = $this->get(urlInstalador(agenteDePrueba()))->getContent();

    preg_match("/<<'PAYLOAD_B64'[^\\n]*\\n(.*?)\\nPAYLOAD_B64/s", $script, $m);
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($tmp, base64_decode(preg_replace('/\s+/', '', $m[1] ?? ''), true));

    $zip = new ZipArchive();
    $zip->open($tmp);
    $nombres = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nombres[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($tmp);

    expect($nombres)->toContain('ispgestor-agent-service')
        ->toContain('uk.ironlink.ispgestor-agent.plist');
});

it('las unidades no exigen rutas que solo existen en el hosting', function () {
    // `ReadWritePaths` sin el prefijo `-` obliga a que la ruta exista: si falta,
    // systemd se niega a montar el espacio de nombres y mata el proceso antes de
    // arrancarlo (status=226/NAMESPACE).
    //
    // Pasó de verdad: `/etc/wireguard` solo existe en el `vpn_host`, así que en
    // la máquina de una oficina —un `provisioner`— el agente entraba en bucle de
    // reinicio nada más instalarse. No se vio en el hosting porque allí sí
    // existe, y es donde se había probado.
    $unidades = [
        base_path('agent/ispgestor-agent.service'),
        base_path('agent/ispgestor-agent@.service'),
    ];

    foreach ($unidades as $unidad) {
        preg_match('/^ReadWritePaths=(.+)$/m', (string) file_get_contents($unidad), $m);

        expect($m[1] ?? '')->not->toBeEmpty("«{$unidad}» no declara ReadWritePaths");

        foreach (preg_split('/\s+/', trim($m[1])) as $ruta) {
            // La única que el instalador garantiza es la suya, porque la crea él.
            if ($ruta === '/etc/ispgestor-agent') {
                continue;
            }

            expect($ruta)->toStartWith(
                '-',
                "«{$ruta}» en " . basename($unidad) . ' tiene que llevar el prefijo «-»: '
                . 'si no existe en la máquina destino, el servicio no arranca.'
            );
        }
    }
});

it('no ofrece Windows ni macOS para el rol vpn_host', function () {
    // Administra el WireGuard del hosting, que es Linux por definición.
    // Ofrecerlo sería ofrecer algo que falla al ejecutarse.
    $admin = makeSuperAdminEmployee();
    Sanctum::actingAs($admin, ['*']);

    $res = $this->postJson('/api/admin/provisioning/agents', [
        'name' => 'VPN Host', 'role' => 'vpn_host',
    ])->assertStatus(201);

    $ordenes = $res->json('data.installer_commands');

    expect($ordenes)->toHaveKey('linux')
        ->and($ordenes)->not->toHaveKey('windows')
        ->and($ordenes)->not->toHaveKey('macos');
});

it('el panel entrega una orden por plataforma para el provisioner', function () {
    $admin = makeSuperAdminEmployee();
    Sanctum::actingAs($admin, ['*']);

    $res = $this->postJson('/api/admin/provisioning/agents', [
        'name' => 'Oficina', 'role' => 'provisioner',
    ])->assertStatus(201);

    $ordenes = $res->json('data.installer_commands');

    expect($ordenes['linux'])->toContain('curl -fsSL')
        ->and($ordenes['macos'])->toContain('curl -fsSL')
        // En Windows se guarda y se ejecuta aparte: `irm | iex` no puede leer
        // del teclado, y el instalador pregunta qué tarjeta vigilar.
        ->and($ordenes['windows'])->toContain('irm ')
        ->and($ordenes['windows'])->toContain('platform=windows')
        ->and($ordenes['windows'])->not->toContain('| iex');
});

it('el instalador de un monitor pregunta qué rangos podrá barrer', function () {
    // Sin `--scannable` la lista queda vacía, que significa «no barrer nada»:
    // el agente se instalaría bien y rechazaría todos los barridos.
    $script = $this->get(urlInstalador(agenteDePrueba('monitor')))->getContent();

    expect($script)->toContain('--scannable')
        ->toContain('ROLE" == "monitor"');
});

it('el instalador no pisa a un agente de otro rol en la misma máquina', function () {
    // El hosting es a la vez vpn_host y el mejor sitio para el monitor. Con una
    // sola unidad, el segundo se llevaba por delante las credenciales del
    // primero y lo dejaba fuera en silencio.
    $script = $this->get(urlInstalador(agenteDePrueba('monitor')))->getContent();

    expect($script)->toContain('INSTANCIA="$ROLE"')
        ->toContain('${CONFIG_DIR}/${ROLE}.conf')
        // Y el enrolamiento tiene que apuntar a ESE fichero, no al de siempre.
        ->toContain('"$BIN" --config "$CONFIG" enroll');
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

it('acepta la URL firmada cuando la petición llega por un proxy https', function () {
    /*
     * La regresión que esto cubre solo se manifiesta en producción: el proxy
     * habla `http` con el contenedor y anuncia el esquema original en una
     * cabecera. Si Laravel no confía en ella reconstruye la URL como `http`,
     * que no es sobre la que se firmó, y rechaza con 403 toda URL firmada.
     *
     * Por eso se firma en `https` y después se devuelve el generador a `http`:
     * si no, el cliente de pruebas construiría la petición ya como `https` y el
     * caso pasaría igual sin el arreglo, que es justo lo que no queremos.
     */
    $agent = agenteDePrueba('vpn_host');

    URL::forceRootUrl('https://proxy.test');
    URL::forceScheme('https');
    $url = URL::temporarySignedRoute('agent.installer', now()->addMinutes(30), ['id' => $agent->id]);
    expect($url)->toStartWith('https://');

    URL::forceRootUrl('http://proxy.test');
    URL::forceScheme('http');
    $ruta = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    $this->get($ruta, ['X-Forwarded-Proto' => 'https'])->assertOk();
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
