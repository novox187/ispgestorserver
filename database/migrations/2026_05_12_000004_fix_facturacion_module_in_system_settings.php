<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Keys that belong to the 'facturacion' module with their correct group
    private const FACTURACION_KEYS = [
        'issuer_name'               => 'issuer',
        'issuer_nit'                => 'issuer',
        'issuer_address'            => 'issuer',
        'issuer_city'               => 'issuer',
        'issuer_country'            => 'issuer',
        'issuer_email'              => 'issuer',
        'issuer_phone'              => 'issuer',
        'tax_rate'                  => 'tax',
        'tax_name'                  => 'tax',
        'tax_id_label'              => 'tax',
        'currency_code'             => 'currency',
        'currency_symbol'           => 'currency',
        'invoice_resolution_number' => 'legal',
        'invoice_resolution_date'   => 'legal',
        'grace_period_days'         => 'billing',
        'auto_payment_retry_days'   => 'billing',
        'invoice_due_days'          => 'billing',
    ];

    public function up(): void
    {
        foreach (self::FACTURACION_KEYS as $key => $group) {
            DB::table('system_settings')
                ->where('key', $key)
                ->where(function ($q) {
                    $q->where('module', 'general')
                      ->orWhereNull('module');
                })
                ->update(['module' => 'facturacion', 'group' => $group]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::FACTURACION_KEYS) as $key) {
            DB::table('system_settings')
                ->where('key', $key)
                ->where('module', 'facturacion')
                ->update(['module' => 'general']);
        }
    }
};
