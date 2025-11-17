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
        Schema::create('supports', function (Blueprint $table) {
            $table->id('id_ticket'); // Clave Primaria (PK)
            
            // Clave Foránea
            $table->foreignId('fk_id_cliente')->references('id')->on('clients')->cascadeOnDelete();

            $table->string('subject', 255);
            $table->text('description');
            $table->timestamp('open_date')->useCurrent(); // Usa la fecha y hora actual por defecto
            $table->enum('priority', ['HIGH', 'MEDIUM', 'LOW'])->default('MEDIUM');
            $table->enum('status', ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'])->default('OPEN');
            $table->string('assigned_technician', 100)->nullable(); /* Técnico asignado */
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supports');
    }
};