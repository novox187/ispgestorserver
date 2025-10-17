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
        Schema::create('soporte', function (Blueprint $table) {
            $table->id('id_ticket'); // Clave Primaria (PK)
            
            // Clave Foránea
            $table->foreignId('fk_id_cliente')->references('id_cliente')->on('clientes')->cascadeOnDelete();

            $table->string('asunto', 255);
            $table->text('descripcion');
            $table->timestamp('fecha_apertura')->useCurrent(); // Usa la fecha y hora actual por defecto
            $table->enum('prioridad', ['ALTA', 'MEDIA', 'BAJA'])->default('MEDIA');
            $table->enum('estatus_ticket', ['ABIERTO', 'EN_PROCESO', 'RESUELTO', 'CERRADO'])->default('ABIERTO');
            $table->string('tecnico_asignado', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('soporte');
    }
};