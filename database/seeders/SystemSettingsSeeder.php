<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Eliminar claves Colombia que ya no aplican en Ecuador
        Setting::whereIn('key', [
            'issuer_nit',
            'invoice_resolution_number',
            'invoice_resolution_date',
        ])->delete();

        $settings = [
            // ── module: facturacion / group: issuer ──────────────────────────
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_name',    'value' => 'Iron Link S.A.',            'data_type' => 'string',  'description' => 'Razón social del emisor',                     'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_ruc',     'value' => '',                          'data_type' => 'string',  'description' => 'RUC del emisor (13 dígitos)',                  'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_address', 'value' => '',                          'data_type' => 'string',  'description' => 'Dirección fiscal del emisor',                 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_city',    'value' => '',                          'data_type' => 'string',  'description' => 'Ciudad del emisor',                           'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_country', 'value' => 'Ecuador',                   'data_type' => 'string',  'description' => 'País del emisor',                             'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_email',   'value' => 'facturacion@ironlink.com',  'data_type' => 'string',  'description' => 'Correo electrónico del emisor',               'is_public' => true],
            ['module' => 'facturacion', 'group' => 'issuer', 'key' => 'issuer_phone',   'value' => '+593 ',                     'data_type' => 'string',  'description' => 'Teléfono de contacto del emisor',             'is_public' => true],

            // ── module: facturacion / group: tax ────────────────────────────
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_rate',      'value' => '0.15', 'data_type' => 'float',  'description' => 'Tasa IVA activa — Ecuador 15% (Decreto 470/2024)', 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_name',      'value' => 'IVA',  'data_type' => 'string', 'description' => 'Nombre del impuesto',                               'is_public' => true],
            ['module' => 'facturacion', 'group' => 'tax', 'key' => 'tax_id_label',  'value' => 'RUC',  'data_type' => 'string', 'description' => 'Etiqueta del identificador fiscal del cliente',     'is_public' => true],

            // ── module: facturacion / group: currency ────────────────────────
            ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_code',   'value' => 'USD', 'data_type' => 'string', 'description' => 'Código ISO de la moneda (Ecuador dolarizado)', 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'currency', 'key' => 'currency_symbol', 'value' => '$',   'data_type' => 'string', 'description' => 'Símbolo de la moneda',                         'is_public' => true],

            // ── module: facturacion / group: legal (SRI Ecuador) ─────────────
            ['module' => 'facturacion', 'group' => 'legal', 'key' => 'sri_establishment_code', 'value' => '001', 'data_type' => 'string', 'description' => 'Código de establecimiento SRI (3 dígitos)', 'is_public' => true],
            ['module' => 'facturacion', 'group' => 'legal', 'key' => 'sri_emission_point',     'value' => '001', 'data_type' => 'string', 'description' => 'Código del punto de emisión SRI (3 dígitos)', 'is_public' => true],

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
