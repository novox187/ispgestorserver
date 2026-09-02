<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Barridos de descubrimiento y lo que encuentran.
 *
 * ## Por qué no reutiliza `provisioning_tasks`
 *
 * Era tentador: un barrido es puntual, igual que una tarea. Pero aquella tabla
 * modela una SAGA —cada tipo declara su compensación y su vencimiento, y
 * `session_id` es NOT NULL porque toda tarea pertenece al alta de un equipo
 * concreto—. Un escaneo no compensa nada, no pertenece a ningún alta, y devuelve
 * N hallazgos en vez de un resultado. Aflojar aquel `NOT NULL` para que cupiera
 * habría corrompido una abstracción que hoy está limpia, a cambio de ahorrarse
 * dos tablas.
 *
 * ## Los hallazgos no son dispositivos
 *
 * Se guardan aparte y el operador los confirma. Un barrido ve impresoras,
 * portátiles y el equipo del vecino; volcarlos al inventario llenaría el mapa de
 * ruido que después habría que limpiar a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agent_id')
                ->constrained('provisioning_agents')
                ->cascadeOnUpdate()
                ->cascadeOnDelete()
                ->comment('Agente que ejecuta el barrido: solo él ve esa red');

            $table->string('cidr', 43)
                ->comment('Rango a barrer. El agente lo valida contra SU PROPIA lista blanca');

            $table->string('status', 20)->default('pending')
                ->comment('pending | running | completed | failed | cancelled');

            $table->foreignId('requested_by')->nullable()
                ->constrained('employees')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Quién lo pidió — un barrido es una acción con consecuencias visibles en la red');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedSmallInteger('found_count')->default(0);

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // El agente pregunta por los suyos pendientes en cada vuelta.
            $table->index(['agent_id', 'status']);
        });

        Schema::create('network_scan_findings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('scan_id')
                ->constrained('network_scans')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('mac_address', 17)->nullable();
            $table->string('ip_address', 45);
            $table->string('vendor', 20)->nullable()
                ->comment('Deducido de la OUI; null si no es de un fabricante conocido');
            $table->string('model', 60)->nullable();
            $table->string('firmware', 40)->nullable();
            $table->string('hostname', 100)->nullable();
            $table->string('essid', 64)->nullable();

            $table->foreignId('matched_device_id')->nullable()
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Ya estaba en el inventario: se muestra pero no se ofrece dar de alta');

            $table->timestamp('created_at')->nullable();

            // Un mismo equipo puede responder a varias sondas del mismo barrido.
            $table->unique(['scan_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_scan_findings');
        Schema::dropIfExists('network_scans');
    }
};
