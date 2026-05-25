<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('connectivity_status', 20)->default('unknown')->after('is_active')
                ->comment('connected | disconnected | unknown — calculado por el monitor');
            $table->timestamp('last_health_check_at')->nullable()->after('connectivity_status')
                ->comment('Último intento de chequeo de salud (exitoso o no)');
            $table->timestamp('last_connected_at')->nullable()->after('last_health_check_at')
                ->comment('Último chequeo exitoso registrado');
            $table->timestamp('last_disconnected_at')->nullable()->after('last_connected_at')
                ->comment('Momento en que pasó a estado disconnected');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('last_disconnected_at')
                ->comment('Fallos consecutivos acumulados — el umbral disparará la alerta');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn([
                'connectivity_status',
                'last_health_check_at',
                'last_connected_at',
                'last_disconnected_at',
                'consecutive_failures',
            ]);
        });
    }
};
