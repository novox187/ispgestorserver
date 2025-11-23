<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->onDelete('cascade')->comment('Billetera relacionada');
            $table->enum('type', ['deposit', 'transfer', 'withdrawal', 'payment', 'refund'])->comment('Tipo de transacción');
            $table->decimal('amount', 10, 2)->comment('Monto de la transacción');
            $table->string('description', 500)->nullable()->comment('Descripción de la transacción');
            $table->string('reference')->unique()->comment('Referencia única de la transacción');
            $table
                ->enum('status', ['pending', 'completed', 'failed', 'cancelled'])
                ->default('pending')
                ->comment('Estado de la transacción');
            $table->string('image_public_id')->nullable()->comment('ID único en Cloudinary para gestionar la imagen');
            $table->string('image_url')->nullable()->comment('URL para mostrar la imagen');
            $table->json('metadata')->nullable()->comment('Datos adicionales de la transacción');
            $table->timestamps();

            // Índices
            $table->index('type');
            $table->index('status');
            $table->index('reference');
            $table->index('created_at');
            $table->index(['wallet_id', 'created_at']);
            $table->index(['type', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
