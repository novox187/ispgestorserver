<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M-04 — Separa la ventana horaria del corte de la del cobro.
     *
     * `auto_billing` y `client_suspension` corrían ambos a las 02:00, en colas
     * distintas atendidas por workers distintos, recorriendo el mismo conjunto
     * de facturas y llamando al mismo processInvoicePayment(). Además del riesgo
     * de doble cobro (ya cerrado con bloqueo pesimista), el orden importa: el
     * corte debe mirar una cartera ya estabilizada por los cobros de esa noche.
     *
     * M-13 — Registra `billing:reactivate` como automatización gobernable.
     *
     * La reactivación diaria vivía suelta en bootstrap/app.php: no se podía
     * desactivar ni reprogramar desde el panel, no respetaba el interruptor
     * general y no emitía resumen. Era la única pieza del ciclo invisible para
     * el operador.
     */
    public function up(): void
    {
        DB::table('automation_settings')
            ->where('key', 'client_suspension')
            ->update([
                'schedule_config' => json_encode(['time' => '04:00']),
                'updated_at'      => now(),
            ]);

        DB::table('automation_settings')->updateOrInsert(
            ['key' => 'auto_reactivation'],
            [
                'name'        => 'Reactivación Automática de Clientes',
                'description' => 'Revisa los clientes suspendidos y reactiva a los que ya no tienen deuda vencida (por recarga de billetera o pago registrado). Complementa a la reactivación inmediata que dispara cada recarga.',
                'job_class'   => \App\Jobs\ProcessAutoReactivationSweep::class,
                'queue'       => 'reactivations',
                'enabled'     => true,
                'schedule_type'   => 'daily',
                'schedule_config' => json_encode(['time' => '10:00']),
                'params'          => json_encode([]),
                'params_schema'   => json_encode([]),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('automation_settings')
            ->where('key', 'client_suspension')
            ->update([
                'schedule_config' => json_encode(['time' => '02:00']),
                'updated_at'      => now(),
            ]);

        DB::table('automation_settings')->where('key', 'auto_reactivation')->delete();
    }
};
