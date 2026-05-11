<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ── module: facturacion / group: issuer ──────────────────────────
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_name',    'value' => 'Iron Link S.A.S.',         'data_type' => 'string',  'description' => 'Razón social del emisor',               'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_nit',     'value' => '900.123.456-7',             'data_type' => 'string',  'description' => 'NIT o identificación fiscal',           'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_address', 'value' => 'Calle 10 # 20-30',          'data_type' => 'string',  'description' => 'Dirección fiscal del emisor',           'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_city',    'value' => 'Bogotá',                    'data_type' => 'string',  'description' => 'Ciudad del emisor',                     'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_country', 'value' => 'Colombia',                  'data_type' => 'string',  'description' => 'País del emisor',                       'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_email',   'value' => 'facturacion@ironlink.com',  'data_type' => 'string',  'description' => 'Correo electrónico del emisor',         'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_phone',   'value' => '+57 1 234 5678',            'data_type' => 'string',  'description' => 'Teléfono de contacto del emisor',       'is_public' => true],

            // ── module: facturacion / group: tax ────────────────────────────
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_rate',      'value' => '0.15', 'data_type' => 'float',  'description' => 'Tasa de impuesto activa (ej: 0.15 = 15%)', 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_name',      'value' => 'IVA',  'data_type' => 'string', 'description' => 'Nombre del impuesto',                       'is_public' => true],
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_id_label',  'value' => 'NIT',  'data_type' => 'string', 'description' => 'Etiqueta del identificador fiscal',         'is_public' => true],

            // ── module: facturacion / group: currency ────────────────────────
            ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',   'value' => 'COP', 'data_type' => 'string', 'description' => 'Código ISO de la moneda',  'is_public' => true],
            ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol', 'value' => '$',   'data_type' => 'string', 'description' => 'Símbolo de la moneda',     'is_public' => true],

            // ── module: facturacion / group: legal ───────────────────────────
            ['module' => 'facturacion', 'group' => 'legal', 'key' => 'invoice_resolution_number', 'value' => '18764000001', 'data_type' => 'string', 'description' => 'Número de resolución de facturación', 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'legal', 'key' => 'invoice_resolution_date',   'value' => '2024-01-01',  'data_type' => 'string', 'description' => 'Fecha de la resolución',               'is_public' => true],

            // ── module: facturacion / group: billing (internos) ──────────────
            ['module' => 'facturacion', 'group' => 'billing', 'key' => 'grace_period_days',        'value' => '3',  'data_type' => 'integer', 'description' => 'Días de gracia antes de suspensión',    'is_public' => false],
            ['module' => 'facturacion', 'group' => 'billing', 'key' => 'auto_payment_retry_days',  'value' => '5',  'data_type' => 'integer', 'description' => 'Días de reintento de pago automático',   'is_public' => false],
            ['module' => 'facturacion', 'group' => 'billing', 'key' => 'invoice_due_days',         'value' => '15', 'data_type' => 'integer', 'description' => 'Días de vencimiento desde la emisión',   'is_public' => false],
        ];

        foreach ($settings as $data) {
            Setting::updateOrCreate(['key' => $data['key']], $data);
        }

        Setting::flushCache();
    }
}
