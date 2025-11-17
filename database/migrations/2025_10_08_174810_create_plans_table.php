<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up()
{
    Schema::create('plans', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->text('description')->nullable();
        $table->integer('download_speed');
        $table->integer('upload_speed');
        $table->boolean('symmetric')->default(false);
        $table->decimal('monthly_price', 8, 2);
        $table->decimal('setup_price', 8, 2)->default(0);
        $table->string('billing_cycle')->default('monthly');
        $table->string('category')->default('residential');
        $table->integer('priority')->default(1);
        $table->boolean('is_featured')->default(false);
        $table->boolean('is_active')->default(true);
        $table->string('mikrotik_queue_name')->nullable();
        $table->string('download_limit')->nullable();
        $table->string('upload_limit')->nullable();
        $table->string('burst_limit')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};