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
