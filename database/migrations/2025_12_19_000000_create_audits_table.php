<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('table_name')->comment('Nombre de la tabla afectada');
            $table->string('operation')->comment('Tipo de operación: INSERT, UPDATE, DELETE');
            $table->string('record_id')->comment('ID del registro afectado (flexible para int o uuid)');
            $table->longText('old_values')->nullable()->comment('Valores anteriores al cambio (JSON)');
            $table->longText('new_values')->nullable()->comment('Valores nuevos después del cambio (JSON)');
            $table->unsignedBigInteger('user_id')->nullable()->comment('ID del usuario que realizó la acción');
            $table->string('ip_address', 45)->nullable()->comment('Dirección IP de origen');
            $table->timestamp('created_at')->useCurrent()->comment('Fecha y hora del evento (inmutable)');

            // Índices para optimizar búsquedas
            $table->index(['table_name', 'record_id']);
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
