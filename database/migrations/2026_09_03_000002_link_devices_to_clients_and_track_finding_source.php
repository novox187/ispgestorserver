<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula equipos con clientes y registra de dónde salió cada hallazgo.
 *
 * ## Por qué `client_id` va en el equipo y no al revés
 *
 * Un abonado puede tener más de un equipo —la antena y, cada vez más a menudo,
 * un router detrás—. Con el campo en `clients` solo cabría uno. Al revés
 * cualquier número de equipos apunta al mismo cliente, y `clients.ip` se queda
 * como está: la usa la sincronización de colas para facturar y no se toca.
 *
 * ## Por qué hace falta saber el origen de un hallazgo
 *
 * El descubrimiento tiene dos fuentes que ven cosas distintas, y ninguna
 * sustituye a la otra: el barrido UDP solo lo contestan los equipos airOS, y la
 * tabla de vecinos del router solo recoge lo que sea vecino de capa 2 suyo. En
 * un parque real de 145 equipos, la primera vio 25 y la segunda 146, con nueve
 * que solo aparecían en una y 130 que solo aparecían en la otra. Quien mira la
 * lista necesita saber de cuál vino cada fila para interpretar lo que falta.
 *
 * `discovered_via_device_id` es además lo que permite dibujar el mapa sin
 * preguntarle nada al operador: si el hallazgo salió de la tabla de vecinos de
 * un router, ese router es el otro extremo del enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('site_id')
                ->constrained('clients')
                ->cascadeOnUpdate()
                // El equipo sobrevive a la baja del cliente: sigue montado en el
                // tejado y hay que poder verlo para ir a retirarlo.
                ->nullOnDelete()
                ->comment('Abonado al que pertenece el equipo; null en infraestructura');
        });

        Schema::table('network_scan_findings', function (Blueprint $table) {
            $table->string('source', 12)->default('sweep')->after('scan_id')
                ->comment('sweep = barrido UDP, neighbor = tabla de vecinos, both = ambas');

            $table->foreignId('discovered_via_device_id')->nullable()->after('matched_device_id')
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Equipo cuya tabla de vecinos reportó este hallazgo');

            $table->string('remote_interface', 60)->nullable()->after('discovered_via_device_id')
                ->comment('Interfaz del equipo que lo reportó: el extremo del enlace');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::table('network_scan_findings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discovered_via_device_id');
            $table->dropColumn(['source', 'remote_interface']);
        });
    }
};
