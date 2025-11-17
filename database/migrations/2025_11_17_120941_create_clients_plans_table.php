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
        Schema::create('clients_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_billing_date');
            $table->decimal('current_price', 8, 2);
            $table->string('billing_cycle')->default('monthly');
            $table->string('ip_address')->nullable();
            $table->string('mikrotik_queue_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('next_billing_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients_plans');
    }
};
