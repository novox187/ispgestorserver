<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── tickets: agregar last_message_at ──────────────────────────────────
        if (!Schema::hasColumn('tickets', 'last_message_at')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->timestamp('last_message_at')->nullable()->after('status');
            });
        }

        // ── tickets: ampliar el enum de status vía SQL nativo (SQLite-safe) ──
        // SQLite no permite ALTER COLUMN, pero al no tener constraint real de CHECK
        // en producción MySQL/PgSQL se manejará con una migración adicional.
        // Para SQLite dejamos el valor como TEXT sin restricción:
        DB::statement("UPDATE tickets SET status = 'open' WHERE status NOT IN ('new','open','pending','closed','reopened')");

        // ── messages: agregar columnas de eventos y lectura ───────────────────
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'event_type')) {
                $table->string('event_type', 50)->nullable()->after('message')
                      ->comment('Tipo de evento del sistema: wallet_funded, ticket_assigned, etc.');
            }
            if (!Schema::hasColumn('messages', 'metadata')) {
                $table->json('metadata')->nullable()->after('event_type')
                      ->comment('Datos adicionales del evento (amount, receipt_url, actor, etc.)');
            }
            if (!Schema::hasColumn('messages', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('metadata')
                      ->comment('Timestamp en que el destinatario leyó el mensaje');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['event_type', 'metadata', 'read_at']);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('last_message_at');
        });
    }
};
