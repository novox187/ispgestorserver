<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensancha las columnas de firmware: 40 caracteres no alcanzan para airOS.
 *
 * El límite se eligió mirando versiones de RouterOS ("7.20.6"), que son cortas.
 * Las de airOS llevan plataforma, chipset, versión, número de compilación y
 * fecha, y se pasan:
 *
 *   XW.ar934x.v6.1.7-licensed.32555.180523.1625   (43)
 *   WA.ar934x.v8.7.22.48486.260227.1959           (35)
 *
 * Se descubrió con el primer barrido real contra el parque: el agente encontró
 * las antenas, pero el servidor rechazó el informe entero con un 422 por cuatro
 * equipos con firmware «-licensed», y **se perdió el barrido completo** —el
 * agente no reencola, y el registro quedó colgado en «ejecutándose».
 *
 * 80 y no 43: el margen es gratis en un `varchar` y evita repetir esto con el
 * siguiente esquema de nombres que se invente un fabricante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_scan_findings', function (Blueprint $table) {
            $table->string('firmware', 80)->nullable()->change();
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('firmware_version', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Volver a 40 truncaría datos ya guardados, así que se recorta primero.
        // Sin esto, `migrate:rollback` falla contra una base con antenas dentro.
        DB::table('network_scan_findings')->update([
            'firmware' => DB::raw('LEFT(firmware, 40)'),
        ]);

        DB::table('network_devices')->update([
            'firmware_version' => DB::raw('LEFT(firmware_version, 40)'),
        ]);

        Schema::table('network_scan_findings', function (Blueprint $table) {
            $table->string('firmware', 40)->nullable()->change();
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('firmware_version', 40)->nullable()->change();
        });
    }
};
