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
    Schema::create('plan_features', function (Blueprint $table) {
        $table->id();
        $table->foreignId('plan_id')->constrained()->onDelete('cascade');
        $table->string('feature');
        $table->string('icon')->nullable();
        $table->integer('order')->default(0);
        $table->boolean('highlighted')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};
