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
        Schema::create('clientes', function (Blueprint $table) {
            $table->id('id_cliente'); // Clave Primaria (PK)
            $table->string('nombre_completo', 255);
            $table->string('documento_id', 50)->unique(); // ID legal único
            $table->string('telefono_contacto', 20);
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->text('direccion_instalacion');
            $table->string('coordenadas_gps', 50)->nullable();
            $table->date('fecha_contratacion');
            $table->enum('estatus_servicio', ['ACTIVO', 'LIMITADO', 'SUSPENDIDO', 'CANCELADO'])->default('ACTIVO');
            $table->text('observaciones')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};