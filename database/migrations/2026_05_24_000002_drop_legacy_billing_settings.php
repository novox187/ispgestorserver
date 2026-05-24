<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retira los settings legacy `grace_period_days` y `auto_payment_retry_days`
 * de `system_settings`. Su lógica migró al módulo de Workers Automáticos:
 *   - grace_period_days        → automation_settings.client_suspension.params.grace_days
 *   - auto_payment_retry_days  → automation_settings.auto_billing.params.retry_window_days
 *
 * No restaurar en `down()`: re-introducir estas claves crearía nuevamente la
 * fuente de verdad duplicada que esta migración resuelve.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->whereIn('key', ['grace_period_days', 'auto_payment_retry_days'])
            ->delete();
    }

    public function down(): void
    {
        // Intencionalmente vacío. Ver nota en cabecera.
    }
};
