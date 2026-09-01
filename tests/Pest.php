<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Crea un Employee con el rol `super_admin`, el cual bypassa
 * todas las comprobaciones del middleware `permission:*`.
 *
 * Reutilizable en todos los tests de Feature que necesitan
 * autenticarse contra endpoints administrativos.
 */
function makeSuperAdminEmployee(array $attributes = []): \App\Models\Employee
{
    $role = \App\Models\Role::firstOrCreate(
        ['slug' => 'super_admin'],
        ['nombre' => 'Super Admin', 'descripcion' => '']
    );

    return \App\Models\Employee::factory()->create(array_merge(
        ['role_id' => $role->id],
        $attributes,
    ));
}

/**
 * Inserta la configuración mínima válida del módulo de facturación,
 * tal y como la espera `App\Services\InvoiceConfigValidator`.
 *
 * Necesario para cualquier test que dispare la generación de facturas
 * (manual o automática) o que llegue a `configCheck`, ya que el
 * controlador rechaza con 422 cuando la configuración no es válida.
 */
function seedValidInvoiceConfig(): void
{
    $rows = [
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_name',            'value' => 'Iron Link S.A.S.', 'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_ruc',             'value' => '1790012345001',    'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_address',         'value' => 'Calle 1',          'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_city',            'value' => 'Quito',            'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_country',         'value' => 'Ecuador',          'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_email',           'value' => 'a@ironlink.com',   'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_rate',               'value' => '0.15',             'data_type' => 'float',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_name',               'value' => 'IVA',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',          'value' => 'USD',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol',        'value' => '$',                'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_establishment_code', 'value' => '001',              'data_type' => 'string', 'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'sri_emission_point',     'value' => '001',              'data_type' => 'string', 'is_public' => true],
    ];

    foreach ($rows as $row) {
        \App\Models\Setting::create(array_merge($row, ['description' => '']));
    }
}

/**
 * Crea un agente de aprovisionamiento YA ENROLADO y devuelve sus credenciales
 * en claro, que es la única forma de poder firmar peticiones desde un test.
 *
 * Para el rol `vpn_host` se rellenan unas capabilities plausibles: sin ellas la
 * saga no sabría qué endpoint ni qué clave pública meterle al router.
 *
 * @return array{agent: \App\Models\ProvisioningAgent, token: string, secret: string}
 */
function makeProvisioningAgent(
    string $role = 'provisioner',
    array $capabilities = [],
    array $attributes = [],
): array {
    $defaults = $role === 'vpn_host'
        ? [
            'server_public_key' => 'c2VydmVyLXB1YmxpYy1rZXktZm9yLXRlc3RpbmctMDAwMD0=',
            'endpoint_host'     => 'vpn.ironlink.uk',
            'endpoint_port'     => 51820,
            'interface'         => 'wg-ispgestor',
            'subnet'            => '10.77.0.0/24',
        ]
        : ['provisioning_interfaces' => ['eth1']];

    $agent = \App\Models\ProvisioningAgent::create(array_merge([
        'name'      => "Agente {$role} de prueba",
        'role'      => $role,
        'is_active' => true,
    ], $attributes));

    $credentials = $agent->completeEnrollment();

    $agent->forceFill([
        'capabilities' => array_merge($defaults, $capabilities),
        'last_seen_at' => now(),
        'agent_version' => '1.0.0-test',
    ])->save();

    return [
        'agent'  => $agent->fresh(),
        'token'  => $credentials['token'],
        'secret' => $credentials['secret'],
    ];
}

/**
 * Cabeceras HMAC válidas para una petición de agente.
 *
 * El cuerpo se serializa con `json_encode($body)` porque es exactamente lo que
 * hace `postJson()` de Laravel: la firma tiene que cubrir los mismos bytes que
 * viajan por el cable o no cuadraría nunca.
 *
 * @param array{agent: \App\Models\ProvisioningAgent, token: string, secret: string} $enrolled
 * @param array<string,mixed> $overrides Permite romper una cabecera a propósito
 *        para ejercitar los rechazos del middleware.
 * @return array<string,string>
 */
function signedAgentHeaders(
    array $enrolled,
    string $method,
    string $uri,
    array $body = [],
    array $overrides = [],
): array {
    $timestamp = (string) ($overrides['timestamp'] ?? now()->getTimestamp());
    $nonce     = (string) ($overrides['nonce'] ?? \Illuminate\Support\Str::uuid());
    $content   = $body === [] ? '' : json_encode($body);

    $signature = $overrides['signature'] ?? \App\Services\Provisioning\AgentSignature::sign(
        secret:    $overrides['secret'] ?? $enrolled['secret'],
        method:    $method,
        path:      parse_url($uri, PHP_URL_PATH) ?: $uri,
        timestamp: $timestamp,
        nonce:     $nonce,
        body:      $content,
    );

    return [
        \App\Services\Provisioning\AgentSignature::HEADER_AGENT     => $overrides['token'] ?? $enrolled['token'],
        \App\Services\Provisioning\AgentSignature::HEADER_TIMESTAMP => $timestamp,
        \App\Services\Provisioning\AgentSignature::HEADER_NONCE     => $nonce,
        \App\Services\Provisioning\AgentSignature::HEADER_SIGNATURE => $signature,
    ];
}

/**
 * Resultado plausible que devolvería un agente real para cada tipo de tarea.
 *
 * @return array<string,mixed>
 */
function fakeAgentTaskResult(string $type, array $overrides = []): array
{
    $canned = match ($type) {
        'identify_device' => [
            'identity'            => 'MikroTik-Bench',
            'board_name'          => 'hEX S',
            'routeros_version'    => '7.15.3',
            'serial_number'       => 'HEX7S0012345',
            'lan_ip'              => '192.168.88.1',
            'wireguard_available' => true,
            'wan_reachable'       => true,
            'credentials'         => ['username' => 'admin', 'password' => ''],
            'logs'                => ['Conectado a 192.168.88.1:8728 con admin', 'RouterOS 7.15.3 (hEX S)'],
        ],
        'apply_router_vpn' => [
            'router_public_key' => 'cm91dGVyLXB1YmxpYy1rZXktZ2VuZXJhZGEtcG9yLVJPUw==',
            'logs'              => ['Interfaz wg-ispgestor creada', 'Peer del servidor añadido'],
        ],
        'apply_host_peer'    => ['ok' => true, 'logs' => ['Peer añadido a wg-ispgestor']],
        'verify_router_vpn'  => ['handshake_age_seconds' => 12, 'ping_ok' => true],
        'verify_host_peer'   => ['handshake_age_seconds' => 9,  'ping_ok' => true],
        'harden_router'      => ['ok' => true, 'logs' => ['Usuario ispgestor-api creado', 'API restringida']],
        default              => ['ok' => true],
    };

    return array_merge($canned, $overrides);
}

/**
 * Conduce un alta completa simulando a los dos agentes.
 *
 * Reclama y reporta por HTTP contra los endpoints reales, así que el flujo pasa
 * por la firma HMAC, el middleware, los controladores y el orquestador — no
 * solo por la capa de servicios. Con `QUEUE_CONNECTION=sync` cada reporte
 * dispara el avance de la saga en el acto, de modo que el bucle ve la siguiente
 * tarea inmediatamente.
 *
 * @param array $provisioner Resultado de makeProvisioningAgent('provisioner')
 * @param array $vpnHost     Resultado de makeProvisioningAgent('vpn_host')
 * @param array<string,array{code:string,message:string}> $failAt
 *        Tipos de tarea que deben reportarse como fallidos, para ejercitar la
 *        compensación.
 * @return list<string> Tipos de tarea ejecutados, en orden.
 */
function driveProvisioningFlow(
    \Tests\TestCase $test,
    array $provisioner,
    array $vpnHost,
    array $failAt = [],
    int $maxSteps = 20,
): array {
    $executed = [];

    for ($step = 0; $step < $maxSteps; $step++) {
        $claimed = null;

        foreach ([$provisioner, $vpnHost] as $agent) {
            $body    = ['max' => 1];
            $headers = signedAgentHeaders($agent, 'POST', '/api/agent/tasks/claim', $body);

            $response = $test->postJson('/api/agent/tasks/claim', $body, $headers);
            $tasks    = $response->json('data.tasks') ?? [];

            if ($tasks !== []) {
                $claimed = ['agent' => $agent, 'task' => $tasks[0]];
                break;
            }
        }

        // Sin tareas pendientes en ningún agente: la saga llegó a un estado
        // terminal o espera una acción humana.
        if ($claimed === null) {
            return $executed;
        }

        $task       = $claimed['task'];
        $type       = $task['type'];
        $executed[] = $type;

        $uri = "/api/agent/tasks/{$task['id']}/report";

        $body = isset($failAt[$type])
            ? [
                'status'        => 'failed',
                'error_code'    => $failAt[$type]['code'],
                'error_message' => $failAt[$type]['message'],
                'result'        => [],
            ]
            : [
                'status' => 'succeeded',
                'result' => fakeAgentTaskResult($type),
            ];

        $test->postJson($uri, $body, signedAgentHeaders($claimed['agent'], 'POST', $uri, $body))
            ->assertOk();
    }

    return $executed;
}

/**
 * Inserta la configuración del canal Telegram directamente en la BD para los
 * tests del módulo de notificaciones. El módulo ya no lee credenciales/chat IDs
 * de variables de entorno: estos valores deben venir de
 * `notification_channel_configs`.
 *
 * @param array<string,string|null> $routes  Mapa categoría → chat_id que crea
 *        filas en `notification_event_routes`. Pasar `null` como valor para
 *        que la ruta caiga al `default_address` del canal.
 */
function seedTelegramChannel(
    string $botToken = 'fake-token',
    ?string $defaultAddress = 'chat-default',
    string $parseMode = 'MarkdownV2',
    array $routes = [],
    bool $enabled = true,
): void {
    $settings = ['parse_mode' => $parseMode];
    if ($defaultAddress !== null) {
        $settings['default_address'] = $defaultAddress;
    }

    \App\Models\NotificationChannelConfig::updateOrCreate(
        ['channel_key' => 'telegram'],
        [
            'enabled'     => $enabled,
            'credentials' => $botToken !== '' ? ['bot_token' => $botToken] : [],
            'settings'    => $settings,
        ]
    );

    foreach ($routes as $category => $addressOverride) {
        \App\Models\NotificationEventRoute::updateOrCreate(
            ['category' => $category, 'channel_key' => 'telegram'],
            [
                'enabled'          => true,
                'address_override' => $addressOverride,
            ]
        );
    }
}
