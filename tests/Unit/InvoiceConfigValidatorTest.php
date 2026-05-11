<?php

use App\Models\Setting;
use App\Services\InvoiceConfigValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

function seedAllRequired(): void
{
    $rows = [
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_name',                'value' => 'Iron Link S.A.S.',        'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_nit',                 'value' => '900.123.456-7',            'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_address',             'value' => 'Calle 10 # 20-30',         'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_city',                'value' => 'Bogotá',                   'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_country',             'value' => 'Colombia',                 'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'issuer',   'key' => 'issuer_email',               'value' => 'test@ironlink.com',        'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_rate',                   'value' => '0.15',                     'data_type' => 'float',   'is_public' => true],
        ['module' => 'facturacion', 'group' => 'tax',      'key' => 'tax_name',                   'value' => 'IVA',                      'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',              'value' => 'COP',                      'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol',            'value' => '$',                        'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'invoice_resolution_number',  'value' => '18764000001',              'data_type' => 'string',  'is_public' => true],
        ['module' => 'facturacion', 'group' => 'legal',    'key' => 'invoice_resolution_date',    'value' => '2024-01-01',               'data_type' => 'string',  'is_public' => true],
    ];

    foreach ($rows as $row) {
        Setting::create(array_merge($row, ['description' => '']));
    }
}

// ── Escenario 1: todas las configuraciones completas ─────────────────────────

it('valida correctamente cuando todas las configuraciones están completas', function () {
    seedAllRequired();

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeTrue();
    expect($result['missing'])->toBeEmpty();
    expect($result['invalid'])->toBeEmpty();
    expect($result['messages'])->toBeEmpty();
});

it('assertValid no lanza excepción cuando la configuración está completa', function () {
    seedAllRequired();

    expect(fn () => (new InvoiceConfigValidator())->assertValid())->not->toThrow(\RuntimeException::class);
});

// ── Escenario 2: configuraciones parcialmente faltantes ──────────────────────

it('detecta configuraciones faltantes y bloquea con mensaje específico', function () {
    // Solo insertar issuer parcial — omitir tax, currency y legal
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_name',    'value' => 'Iron Link', 'data_type' => 'string', 'is_public' => true, 'description' => '']);
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_nit',     'value' => '900.1',     'data_type' => 'string', 'is_public' => true, 'description' => '']);
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_address', 'value' => 'Calle 1',   'data_type' => 'string', 'is_public' => true, 'description' => '']);
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_city',    'value' => 'Bogotá',    'data_type' => 'string', 'is_public' => true, 'description' => '']);
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_country', 'value' => 'Colombia',  'data_type' => 'string', 'is_public' => true, 'description' => '']);
    Setting::create(['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_email',   'value' => 'a@b.com',   'data_type' => 'string', 'is_public' => true, 'description' => '']);
    // tax, currency, legal missing

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['missing'])->toHaveKey('tax');
    expect($result['missing'])->toHaveKey('currency');
    expect($result['missing'])->toHaveKey('legal');
    expect($result['missing']['tax'])->toHaveKey('tax_rate');
    expect($result['missing']['tax'])->toHaveKey('tax_name');
    expect($result['messages'])->not->toBeEmpty();
});

it('detecta una sola clave faltante dentro de un grupo', function () {
    seedAllRequired();

    // Remove just issuer_email
    Setting::where('key', 'issuer_email')->delete();

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['missing'])->toHaveKey('issuer');
    expect($result['missing']['issuer'])->toHaveKey('issuer_email');
    expect($result['missing']['issuer'])->not->toHaveKey('issuer_name');
});

it('detecta clave con valor vacío como faltante', function () {
    seedAllRequired();

    Setting::where('key', 'tax_rate')->update(['value' => '   ']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['missing'])->toHaveKey('tax');
    expect($result['missing']['tax'])->toHaveKey('tax_rate');
});

it('assertValid lanza RuntimeException cuando hay claves faltantes', function () {
    // DB vacía — todas faltan

    expect(fn () => (new InvoiceConfigValidator())->assertValid())
        ->toThrow(\RuntimeException::class);
});

it('el payload de RuntimeException contiene type y missing', function () {
    try {
        (new InvoiceConfigValidator())->assertValid();
    } catch (\RuntimeException $e) {
        $payload = json_decode($e->getMessage(), true);
        expect($payload['type'])->toBe('invoice_config_invalid');
        expect($payload['missing'])->not->toBeEmpty();
        return;
    }
    $this->fail('Se esperaba RuntimeException');
});

// ── Escenario 3: configuraciones inválidas o expiradas ───────────────────────

it('detecta tax_rate fuera de rango (>= 1)', function () {
    seedAllRequired();
    Setting::where('key', 'tax_rate')->update(['value' => '1.5']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid'])->toHaveKey('tax');
    expect($result['invalid']['tax'])->toHaveKey('tax_rate');
    expect($result['messages'][0])->toContain('tax_rate');
});

it('detecta tax_rate negativo como inválido', function () {
    seedAllRequired();
    Setting::where('key', 'tax_rate')->update(['value' => '-0.05']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid']['tax'])->toHaveKey('tax_rate');
});

it('detecta tax_rate no numérico como inválido', function () {
    seedAllRequired();
    Setting::where('key', 'tax_rate')->update(['value' => 'quince']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid']['tax'])->toHaveKey('tax_rate');
});

it('detecta fecha de resolución en el futuro como inválida', function () {
    seedAllRequired();
    Setting::where('key', 'invoice_resolution_date')->update(['value' => '2099-12-31']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid'])->toHaveKey('legal');
    expect($result['invalid']['legal'])->toHaveKey('invoice_resolution_date');
    expect($result['messages'][0])->toContain('invoice_resolution_date');
});

it('detecta formato de fecha de resolución inválido', function () {
    seedAllRequired();
    Setting::where('key', 'invoice_resolution_date')->update(['value' => '31-12-2024']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid']['legal'])->toHaveKey('invoice_resolution_date');
});

it('detecta email del emisor inválido', function () {
    seedAllRequired();
    Setting::where('key', 'issuer_email')->update(['value' => 'no-es-un-email']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeFalse();
    expect($result['invalid']['issuer'])->toHaveKey('issuer_email');
});

it('acepta tax_rate exactamente en 0 (sin impuesto)', function () {
    seedAllRequired();
    Setting::where('key', 'tax_rate')->update(['value' => '0']);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeTrue();
});

it('acepta fecha de resolución de hoy como válida', function () {
    seedAllRequired();
    Setting::where('key', 'invoice_resolution_date')->update(['value' => now()->toDateString()]);

    $result = (new InvoiceConfigValidator())->validate();

    expect($result['valid'])->toBeTrue();
});
