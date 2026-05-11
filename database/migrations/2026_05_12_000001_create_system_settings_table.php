<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 64)->default('general')->index();
            $table->string('key', 128)->unique();
            $table->longText('value')->nullable();
            $table->enum('data_type', ['string', 'integer', 'float', 'boolean', 'json', 'text'])
                  ->default('string');
            $table->text('description')->nullable();
            // Determines whether this setting can be exposed to the public API / frontend.
            // A false value here means the setting is for internal/admin use only.
            $table->boolean('is_public')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
