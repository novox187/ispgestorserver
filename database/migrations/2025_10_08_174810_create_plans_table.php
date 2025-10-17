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
        Schema::create('planes', function (Blueprint $table) {
            $table->id('id_plan'); // Clave Primaria (PK)
            $table->string('nombre_plan', 100)->unique(); // Nombre comercial único
            $table->integer('velocidad_descarga_mbps'); // Velocidad de descarga
            $table->integer('velocidad_subida_mbps'); // Velocidad de subida
            $table->decimal('tarifa_mensual', 10, 2); // Costo
            $table->string('mikrotik_queue_name', 100)->unique(); // Nombre de la Simple Queue en Mikrotik
            $table->boolean('activo')->default(true); // Indica si el plan está disponible
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};