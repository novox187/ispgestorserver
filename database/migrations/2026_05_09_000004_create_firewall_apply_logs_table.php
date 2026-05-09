<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_apply_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('router_id')
                ->constrained('mikrotik_routers')->onDelete('cascade')
                ->comment('Router sobre el que se ejecutó la operación');
            $table->foreignId('employee_id')->nullable()
                ->constrained('employees')->onDelete('set null')
                ->comment('Empleado que ejecutó el apply');

            $table->string('reason')->nullable()
                ->comment('Razón del cambio ingresada por el usuario');
            $table->json('snapshot_before')->nullable()
                ->comment('Estado completo del firewall antes del apply');
            $table->json('snapshot_applied')
                ->comment('Snapshot enviado que fue aplicado al router');

            $table->enum('status', ['success', 'failed', 'rolled_back'])->default('success')
                ->comment('Resultado de la operación de apply');
            $table->text('error_message')->nullable()
                ->comment('Detalle del error si status = failed');
            $table->timestamp('applied_at')
                ->comment('Momento en que se ejecutó el apply');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_apply_logs');
    }
};
