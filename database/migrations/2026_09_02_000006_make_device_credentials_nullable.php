<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite que un equipo no tenga credenciales propias.
 *
 * `username` y `password` eran obligatorias porque la tabla nació para routers
 * MikroTik, y a un router siempre hay que decirle con qué usuario entrar. Desde
 * que existen los perfiles compartidos de `device_credentials` esa premisa ya no
 * se sostiene: una antena que usa el perfil «airOS por defecto» no tiene por qué
 * llevar una copia de la misma clave en su fila —que era justamente lo que los
 * perfiles vinieron a evitar.
 *
 * La resolución vive en `NetworkDevice::resolvedCredentials()`: manda lo del
 * equipo y, a falta de ello, lo del perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Antes de volver a NOT NULL hay que rellenar lo que quedó vacío, o la
         * migración falla sobre cualquier equipo que use un perfil. Se pone
         * cadena vacía y no una credencial inventada: vacío es honesto y no
         * abre sesión en ningún sitio.
         */
        \Illuminate\Support\Facades\DB::table('network_devices')
            ->whereNull('username')->update(['username' => '']);
        \Illuminate\Support\Facades\DB::table('network_devices')
            ->whereNull('password')->update(['password' => '']);

        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
            $table->text('password')->nullable(false)->change();
        });
    }
};
