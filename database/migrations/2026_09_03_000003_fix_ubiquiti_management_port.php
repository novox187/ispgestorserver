<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige el puerto de las antenas Ubiquiti dadas de alta con el puerto de
 * MikroTik.
 *
 * La columna `port` nació con `default(8728)` —la API binaria de RouterOS—
 * cuando la tabla solo guardaba routers MikroTik. Al abrirla a Ubiquiti, las
 * altas que no fijaban el puerto se llevaban ese valor puesto, y el driver de
 * airOS acababa pidiendo `https://<ip>:8728/login.cgi`: la antena rechaza la
 * conexión y la prueba de credenciales falla con un error de red que parece un
 * problema de conectividad cuando en realidad es un puerto equivocado.
 *
 * El criterio para tocar una fila es estrecho a propósito: solo Ubiquiti, y
 * solo si el puerto es exactamente uno de los dos de la API de RouterOS. Nadie
 * configura airOS ahí, así que esos valores solo pueden venir del `default`.
 * Cualquier otro puerto —un 8080 tras un NAT, un 443 ya correcto— se respeta,
 * porque puede ser una decisión deliberada del operador.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('network_devices')
            ->where('vendor', 'ubiquiti')
            ->whereIn('port', [8728, 8729])
            ->update(['port' => 443]);
    }

    public function down(): void
    {
        // Sin vuelta atrás, y no por descuido: una vez corregidas, no hay forma
        // de distinguir las filas que arrastraban el `default` de las que se
        // pusieron a 443 a mano. Devolver todas las antenas a 8728 rompería
        // también las segundas, y el estado anterior era el estropeado.
    }
};
