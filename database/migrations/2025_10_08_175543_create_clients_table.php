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
        Schema::create('clients', function (Blueprint $table) {
            $table->id('id'); // Clave Primaria (PK)
            $table->string('full_name', 255);
            $table->string('document_id', 50)->unique(); // ID legal único
            $table->string('contact_phone', 20);
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->text('installation_address');
            $table->string('gps_coordinates', 50)->nullable();
            $table->date('contract_date');
            $table->enum('service_status', ['ACTIVE', 'LIMITED', 'SUSPENDED', 'CANCELLED'])->default('ACTIVE');
            $table->string('ip', 45)->default('0.0.0.0'); 
            $table->text('observations')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};