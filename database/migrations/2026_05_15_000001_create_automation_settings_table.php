<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('job_class', 255);
            $table->string('queue', 64)->default('default');
            $table->boolean('enabled')->default(true)->index();
            $table->string('schedule_type', 32)->default('daily');
            $table->json('schedule_config')->nullable();
            $table->json('params')->nullable();
            $table->json('params_schema')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_settings');
    }
};
