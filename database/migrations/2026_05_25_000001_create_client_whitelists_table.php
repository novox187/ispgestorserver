<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_whitelists', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('client_id')
                ->comment('Cliente protegido de la suspensión automática');

            $table->timestamp('added_at')->useCurrent()
                ->comment('Fecha y hora de incorporación a la lista blanca');

            $table->unsignedBigInteger('authorized_by')->nullable()
                ->comment('Empleado (super_admin) que autorizó la inclusión');

            $table->text('reason')
                ->comment('Motivo descriptivo de la incorporación');

            $table->timestamp('expires_at')->nullable()
                ->comment('Fecha de vencimiento de la permanencia. NULL = permanente');

            $table->boolean('active')->default(true)
                ->comment('Soft-toggle: permite mantener historial al retirar al cliente');

            $table->timestamps();

            $table->foreign('client_id')
                ->references('id')->on('clients')
                ->cascadeOnDelete();

            $table->foreign('authorized_by')
                ->references('id')->on('employees')
                ->nullOnDelete();

            $table->index('client_id', 'idx_whitelist_client');
            $table->index('active', 'idx_whitelist_active');
            $table->index('expires_at', 'idx_whitelist_expires');
            $table->index(['client_id', 'active'], 'idx_whitelist_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_whitelists');
    }
};
