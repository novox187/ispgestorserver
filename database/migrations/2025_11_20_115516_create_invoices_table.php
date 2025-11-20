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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                  ->constrained('clients')
                  ->onDelete('cascade')
                  ->comment('Cliente dueño de la factura');
            $table->foreignId('client_plan_id')
                   ->references('id')
                   ->on('clients_plans')
                   ->constrained()
                   ->onDelete('cascade')
                   ->comment('Plan del cliente facturado');
            $table->string('invoice_number')->unique()->comment('Número único de factura');
            $table->date('issue_date')->comment('Fecha de emisión');
            $table->date('due_date')->comment('Fecha de vencimiento');
            $table->decimal('amount', 10, 2)->comment('Monto total');
            $table->decimal('tax_amount', 10, 2)->default(0)->comment('Impuestos');
            $table->decimal('total_amount', 10, 2)->comment('Monto total con impuestos');
            $table->enum('status', ['draft', 'pending', 'paid', 'failed', 'cancelled'])->default('draft');
            $table->enum('payment_method', ['wallet', 'cash', 'card', 'transfer'])->nullable();
            $table->timestamp('paid_at')->nullable()->comment('Fecha de pago');
            $table->string('payment_reference')->nullable()->comment('Referencia de pago');
            $table->text('description')->nullable()->comment('Descripción de la factura');
            $table->json('metadata')->nullable()->comment('Datos adicionales');
            $table->timestamps();

            // Índices
            $table->index('client_id');
            $table->index('invoice_number');
            $table->index('status');
            $table->index('due_date');
            $table->index(['client_id', 'status']);
            $table->index(['due_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};