<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isp_connections', function (Blueprint $table) {
            $table->id();
            
            // Relación con el ISP
            $table->foreignId('isp_id')
                ->constrained('internet_service_providers')
                ->onDelete('cascade')
                ->comment('Relación con la tabla de proveedores de internet');
            
            // Recursos de Red
            $table->decimal('bandwidth_down', 10, 2)
                ->comment('Ancho de banda real de bajada en Mbps');
            $table->decimal('bandwidth_up', 10, 2)
                ->comment('Ancho de banda real de subida en Mbps');
            $table->string('ratio')->default('1:1')
                ->comment('Relación de ancho de banda (ej: 1:1 dedicado, 10:1 residencial)');
            
            // Gestión de Contrato y Pagos
            $table->date('contract_date')
                ->comment('Fecha en la que se inició el contrato del servicio');
            $table->integer('billing_day')
                ->comment('Día del mes establecido para la fecha límite de pago');
            $table->string('billing_cycle')->default('monthly')
                ->comment('Ciclo de facturación (mensual, anual, bimestral)');
            $table->decimal('monthly_price', 12, 2)
                ->comment('Costo total mensual del servicio contratado');
            
            // Datos Técnicos y Estado
            $table->string('interface_name')->nullable()
                ->comment('Identificador de la interfaz en el router/MikroTik');
            $table->enum('status', ['active', 'maintenance', 'suspended', 'canceled'])
                ->default('active')
                ->comment('Estado operativo actual de la conexión');
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isp_connections');
    }
};