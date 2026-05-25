<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mapeo de (categoría, canal) configurable desde el panel.
     *
     * - Si una categoría tiene al menos una fila habilitada → reemplaza el ruteo
     *   por severidad para esa categoría.
     * - Si no tiene filas habilitadas → el dispatcher cae al ruteo por severidad
     *   declarado en config/notifications.php.
     */
    public function up(): void
    {
        Schema::create('notification_event_routes', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)
                ->comment('Categoría de la notificación (mikrotik_connectivity, worker_summary, ...)');
            $table->string('channel_key', 30)
                ->comment('Canal de entrega');
            $table->boolean('enabled')->default(true)
                ->comment('Si false, la categoría no se envía por este canal');
            $table->string('address_override')->nullable()
                ->comment('Destinatario alternativo (chat_id, email). Si null, usa el default del canal');
            $table->json('extra')->nullable()
                ->comment('Metadatos del canal: thread_id (Telegram topics), labels, etc.');
            $table->timestamps();

            $table->unique(['category', 'channel_key']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_event_routes');
    }
};
