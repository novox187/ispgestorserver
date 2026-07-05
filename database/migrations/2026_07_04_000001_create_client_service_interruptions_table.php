<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro estructurado de las ventanas de corte del servicio.
     *
     * Cada fila representa un periodo [suspended_at, reactivated_at) durante el
     * cual el cliente NO consume el servicio. La facturación usa estas fechas
     * como límite: no se emiten facturas cuya fecha de emisión caiga dentro de
     * una ventana de corte. `reactivated_at` NULL significa corte vigente.
     */
    public function up(): void
    {
        Schema::create('client_service_interruptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('type', 20)->default('suspension'); // suspension | cancellation
            $table->dateTime('suspended_at');                  // fecha de corte (límite de facturación)
            $table->dateTime('reactivated_at')->nullable();    // NULL = corte vigente
            $table->string('suspension_reason', 500)->nullable();
            $table->string('reactivation_reason', 500)->nullable();
            $table->string('suspended_by', 120)->nullable();   // executor: system_auto | employee:{id} | ...
            $table->string('reactivated_by', 120)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('source', 30)->default('status_change'); // auto | manual | status_change | backfill
            $table->timestamps();

            $table->index(['client_id', 'reactivated_at']);
            $table->index(['client_id', 'suspended_at']);
        });

        $this->backfillOpenInterruptions();
    }

    /**
     * Los clientes que YA están suspendidos/cancelados al desplegar esta tabla
     * necesitan una ventana abierta para que la validación por fecha funcione
     * desde el primer día. La fecha de corte se recupera de la auditoría
     * (operaciones de corte conocidas); si no hay rastro, se usa updated_at.
     */
    private function backfillOpenInterruptions(): void
    {
        $cutClients = DB::table('clients')
            ->whereIn(DB::raw('UPPER(service_status)'), ['SUSPENDED', 'SUSPENDIDO', 'CANCELLED', 'CANCELADO'])
            ->get(['id', 'service_status', 'updated_at']);

        foreach ($cutClients as $client) {
            $lastCutAudit = DB::table('audits')
                ->where('table_name', 'clients')
                ->where('record_id', (string) $client->id)
                ->whereIn('operation', ['SUSPEND_AUTO_OP', 'SUSPEND_TECH_OP', 'CANCEL_OP'])
                ->orderByDesc('created_at')
                ->first(['created_at']);

            $isCancelled = in_array(strtoupper($client->service_status), ['CANCELLED', 'CANCELADO'], true);

            DB::table('client_service_interruptions')->insert([
                'client_id'         => $client->id,
                'type'              => $isCancelled ? 'cancellation' : 'suspension',
                'suspended_at'      => $lastCutAudit->created_at ?? $client->updated_at ?? now(),
                'reactivated_at'    => null,
                'suspension_reason' => 'Backfill: corte vigente al crear el registro de interrupciones',
                'suspended_by'      => 'system_backfill',
                'source'            => 'backfill',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_service_interruptions');
    }
};
