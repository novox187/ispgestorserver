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
        Schema::create('servicios', function (Blueprint $table) {
            $table->id('id_servicio'); // Clave Primaria (PK)
            
            // Claves Foráneas
            $table->foreignId('fk_id_cliente')->references('id_cliente')->on('clientes')->cascadeOnDelete();
            $table->foreignId('fk_id_plan')->references('id_plan')->on('planes');

            // Datos de red y automatización (IP Estática)
            $table->string('metodo_autenticacion', 50)->default('IP_STATICA');
            $table->ipAddress('ip_estatica_cliente')->unique(); // La clave para el control Mikrotik
            $table->string('mac_address_cliente', 17)->nullable(); 

            // Datos de Facturación
            $table->integer('dia_corte_fijo'); // Día fijo del mes (1 a 31)
            $table->date('fecha_proximo_vencimiento'); // Clave para el script de corte
            $table->date('fecha_ultimo_pago')->nullable(); 

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servicios');
    }
};