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
         Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                  ->constrained()
                  ->onDelete('cascade')
                  ->comment('Usuario dueño de la billetera');
            $table->decimal('balance', 10, 2)
                  ->default(0.00)
                  ->comment('Saldo actual de la billetera');
            $table->string('currency', 3)
                  ->default('USD')
                  ->comment('Moneda de la billetera (USD, EUR, etc.)');
            $table->enum('status', ['active', 'suspended', 'inactive'])
                  ->default('active')
                  ->comment('Estado de la billetera');
            $table->timestamps();

            // Índices
            $table->unique('client_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
