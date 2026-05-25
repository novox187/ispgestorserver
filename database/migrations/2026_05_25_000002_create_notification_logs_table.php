<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            $table->uuid('notification_id')
                ->comment('Agrupa todos los envíos derivados del mismo NotificationMessage');
            $table->string('category', 50)
                ->comment('Categoría de la notificación (mikrotik_connectivity, worker_summary, ...)');
            $table->string('severity', 20)
                ->comment('Nivel: critical | summary | info');
            $table->string('channel', 30)
                ->comment('Canal de entrega (telegram, email, slack, ...)');
            $table->string('recipient', 255)
                ->comment('Dirección destino (chat_id, email, etc.)');

            $table->string('title', 255);
            $table->text('body');
            $table->json('context')->nullable()
                ->comment('Payload estructurado original');
            $table->json('attachments')->nullable()
                ->comment('Archivos/imágenes adjuntas si el canal lo soporta');

            $table->string('status', 20)->default('pending')
                ->comment('pending | sent | failed | duplicated | exhausted');
            $table->string('dedupe_key')->nullable()
                ->comment('Clave de deduplicación usada para suprimir esta notificación');
            $table->unsignedTinyInteger('attempts')->default(0)
                ->comment('Cantidad de intentos de envío realizados');
            $table->string('external_id')->nullable()
                ->comment('Identificador devuelto por el canal externo (ej: telegram message_id)');
            $table->text('last_error')->nullable()
                ->comment('Detalle del último error si status != sent');

            $table->timestamp('sent_at')->nullable()
                ->comment('Momento del envío exitoso');
            $table->timestamps();

            $table->index('notification_id');
            $table->index('category');
            $table->index('severity');
            $table->index('status');
            $table->index('dedupe_key');
            $table->index(['created_at', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
