<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M-01 (Anatomía del Corte, D-01/D-02): registra si el corte fue
     * efectivamente aplicado en la red o solo en base de datos.
     *
     *   enforced       — la IP está en la lista y una regla de firewall activa
     *                     la referencia: el corte es real.
     *   no_filter_rule — la IP está en la lista pero no hay regla que la use:
     *                     el cliente sigue navegando.
     *   entry_missing  — MikroTik no aceptó ni conserva la entrada.
     *   unverifiable   — el router no respondió a la comprobación.
     *   no_ip          — el cliente no tiene IP asignada, no hay nada que cortar.
     *   not_applicable — corte de tipo 'cancellation' u otro que no pasa por
     *                     MikroTik en este punto.
     *
     * NULL en filas antiguas (backfill, cortes previos a esta migración):
     * significa "no verificado", no "no efectivo".
     *
     * M-12 (D-18): además de la columna, esta migración cierra el hueco de
     * integridad más señalado del módulo — nada en el esquema impedía dos
     * ventanas abiertas simultáneas para el mismo cliente; solo lo evitaba
     * una comprobación en PHP (ClientServiceStatusObserver::openInterruption)
     * que dos escrituras concurrentes pueden esquivar. MySQL/MariaDB no
     * soportan un índice único parcial nativo, así que se usa el patrón
     * estándar: una columna generada que solo toma un valor real cuando la
     * ventana está abierta (reactivated_at IS NULL) y NULL en el resto — los
     * NULL no colisionan entre sí en un índice único, así que el índice solo
     * actúa quien de verdad importa: dos filas abiertas del mismo cliente.
     */
    public function up(): void
    {
        Schema::table('client_service_interruptions', function (Blueprint $table) {
            $table->string('enforcement_state', 30)->nullable()->after('source');
        });

        $this->closeDuplicateOpenWindows();

        DB::statement(
            'ALTER TABLE client_service_interruptions '
            . 'ADD COLUMN open_marker TINYINT GENERATED ALWAYS AS (IF(reactivated_at IS NULL, 1, NULL)) STORED'
        );

        DB::statement(
            'ALTER TABLE client_service_interruptions '
            . 'ADD UNIQUE INDEX client_service_interruptions_one_open_per_client (client_id, open_marker)'
        );
    }

    /**
     * El índice único de abajo falla si los datos ya incumplen el invariante,
     * y esta migración corre en producción DESPUÉS de que el contenedor nuevo
     * empiece a servir tráfico: un ALTER fallido dejaría código nuevo sobre
     * esquema viejo. Así que primero se sanea lo que haya.
     *
     * De cada grupo duplicado sobrevive abierta la ventana MÁS ANTIGUA, que es
     * la que la facturación usa como fecha límite (la más conservadora para el
     * cliente). Las sobrantes se cierran con `reactivated_at = suspended_at`:
     * una ventana de duración cero no cubre ningún día (`covers()` exige
     * `reactivated_at > fecha`), así que no altera lo ya facturado. No se borra
     * ninguna fila — el historial se conserva y el saneamiento queda explicado
     * en `reactivation_reason`.
     */
    private function closeDuplicateOpenWindows(): void
    {
        $duplicados = DB::table('client_service_interruptions')
            ->select('client_id')
            ->whereNull('reactivated_at')
            ->groupBy('client_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('client_id');

        foreach ($duplicados as $clientId) {
            $conservar = DB::table('client_service_interruptions')
                ->where('client_id', $clientId)
                ->whereNull('reactivated_at')
                ->orderBy('suspended_at')
                ->orderBy('id')
                ->value('id');

            DB::table('client_service_interruptions')
                ->where('client_id', $clientId)
                ->whereNull('reactivated_at')
                ->where('id', '!=', $conservar)
                ->update([
                    'reactivated_at'      => DB::raw('suspended_at'),
                    'reactivation_reason' => 'Ventana duplicada cerrada al aplicar el invariante de corte único (migración 2026_09_08_000001)',
                    'reactivated_by'      => 'system_migration',
                ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_service_interruptions DROP INDEX client_service_interruptions_one_open_per_client');
        DB::statement('ALTER TABLE client_service_interruptions DROP COLUMN open_marker');

        Schema::table('client_service_interruptions', function (Blueprint $table) {
            $table->dropColumn('enforcement_state');
        });
    }
};
