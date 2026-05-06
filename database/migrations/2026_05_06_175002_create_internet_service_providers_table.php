<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internet_service_providers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')
                ->comment('Nombre comercial o legal de la empresa ISP');
            $table->string('technical_support_contact')->nullable()
                ->comment('Nombre del contacto técnico o ejecutivo de cuenta');
            $table->string('support_phone')->nullable()
                ->comment('Teléfono directo del NOC o soporte de emergencias');
            $table->string('support_email')->nullable()
                ->comment('Correo para reportes técnicos o apertura de tickets');
            $table->string('address')->nullable()
                ->comment('Dirección física de la oficina del proveedor');
            $table->string('payment_method')->nullable()
                ->comment('Método de pago (Transferencia, Débito, Efectivo)');
            $table->string('account_number')->nullable()
                ->comment('Número de cuenta bancaria para el pago del servicio');
            $table->boolean('is_active')->default(true)
                ->comment('Indica si el proveedor está operativo en nuestro sistema');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internet_service_providers');
    }
};