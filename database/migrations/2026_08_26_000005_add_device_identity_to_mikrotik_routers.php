<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasta ahora la plataforma guardaba de cada equipo solo host/puerto/credenciales
 * y descartaba todo lo que `MikroTikService::getSystemInfo()` ya devolvía. Sin
 * modelo ni serial no se puede decidir compatibilidad ni reconocer un equipo que
 * vuelve al banco de aprovisionamiento.
 *
 * `serial_number` y `mac_address` son las claves de deduplicación: un router
 * reconectado se re-aprovisiona sobre su misma fila en vez de duplicarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->string('mac_address', 17)->nullable()->unique()->after('host')
                ->comment('MAC del puerto de gestión — clave de deduplicación');
            $table->string('serial_number', 60)->nullable()->unique()->after('mac_address')
                ->comment('Serial de RouterBOARD — clave de deduplicación preferente');
            $table->string('board_name', 100)->nullable()->after('serial_number')
                ->comment('Modelo del equipo reportado por RouterOS');
            $table->string('routeros_version', 30)->nullable()->after('board_name')
                ->comment('Versión de RouterOS detectada en el último aprovisionamiento');

            $table->string('provisioning_source', 20)->default('manual')->after('is_primary')
                ->comment('manual | auto — cómo llegó este equipo al sistema');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_source')
                ->comment('Momento en que el alta automática se completó y verificó');
        });

        /*
         * `password` era VARCHAR(255) y se queda corta: el cast `encrypted` del
         * modelo produce un sobre JSON en base64 que ronda los 280 caracteres
         * incluso para una contraseña modesta. Era un fallo latente del esquema
         * —el alta manual valida `max:255` sobre el texto plano, así que una
         * contraseña larga tecleada a mano ya reventaba— que solo salía a la luz
         * ahora, porque el alta automática genera contraseñas de 32 caracteres.
         */
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->text('password')
                ->comment('Contraseña cifrada de la API RouterOS (el sobre del cast excede 255)')
                ->change();
        });
    }

    public function down(): void
    {
        // No se revierte `password` a VARCHAR(255): truncaría las credenciales
        // cifradas de cualquier router dado de alta con este esquema y las
        // dejaría irrecuperables. Una columna más ancha de la cuenta no molesta
        // a nadie.
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropUnique(['mac_address']);
            $table->dropUnique(['serial_number']);
            $table->dropColumn([
                'mac_address',
                'serial_number',
                'board_name',
                'routeros_version',
                'provisioning_source',
                'provisioned_at',
            ]);
        });
    }
};
