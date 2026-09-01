<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cola de trabajo que los agentes reclaman por polling saliente. Es una cola
 * aparte de `jobs` a propósito: aquí el consumidor vive fuera del contenedor,
 * cada tarea tiene un destinatario concreto (`agent_id`) y necesita vencimiento
 * propio para poder disparar el rollback de la saga.
 *
 * `payload` va cifrado porque puede transportar credenciales del router.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('session_id')
                ->constrained('device_provisioning_sessions')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('agent_id')
                ->constrained('provisioning_agents')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Único agente autorizado a reclamar esta tarea');

            $table->string('type', 40)
                ->comment('identify_device|apply_router_vpn|apply_host_peer|verify_router_vpn|'
                    . 'verify_host_peer|rollback_router_vpn|rollback_host_peer');

            $table->text('payload')->nullable()
                ->comment('Instrucción estructurada y cifrada; nunca comandos crudos');

            $table->string('status', 20)->default('pending')
                ->comment('pending | claimed | succeeded | failed | expired');

            $table->unsignedSmallInteger('attempts')->default(0)
                ->comment('Veces que se reclamó — la saga gobierna los reintentos, no la cola');

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()
                ->comment('Vencida sin reporte ⇒ se marca expired y la saga revierte');

            $table->json('result')->nullable()
                ->comment('Lo que devolvió el agente, incluidas sus líneas de log');

            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // El claim filtra por (agent_id, status) y ordena por id.
            $table->index(['agent_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_tasks');
    }
};
