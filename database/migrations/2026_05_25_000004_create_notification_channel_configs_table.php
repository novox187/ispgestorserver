<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channel_configs', function (Blueprint $table) {
            $table->id();
            $table->string('channel_key', 30)->unique()
                ->comment('Identificador del canal (telegram, email, slack, ...)');
            $table->boolean('enabled')->default(false)
                ->comment('Habilita o deshabilita el canal de forma global');
            $table->text('credentials')->nullable()
                ->comment('JSON cifrado con tokens/passwords del canal');
            $table->json('settings')->nullable()
                ->comment('Configuración no sensible: chat_id default, parse_mode, base_url, etc.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channel_configs');
    }
};
