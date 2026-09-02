<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales compartidas por perfil, en vez de una copia por equipo.
 *
 * Un WISP usa la misma clave de administración en todas sus antenas. Guardar una
 * copia cifrada en cada fila de `network_devices` haría que rotarla fuese editar
 * trescientos registros —y que bastara olvidar uno para dejar un equipo fuera
 * del monitoreo sin que nadie lo note.
 *
 * Los routers MikroTik existentes NO se tocan: cada uno tiene su contraseña
 * propia, generada por el alta automática, y eso es deseable. El perfil es para
 * el parque que se administra en bloque. La resolución vive en
 * `NetworkDevice::resolvedCredentials()`: manda lo que tenga el equipo, y solo
 * si no tiene nada se recurre al perfil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();

            $table->string('name', 80)
                ->comment('Nombre operativo del perfil: «Antenas torre norte», «airOS por defecto»');

            $table->string('vendor', 20)
                ->comment('Fabricante al que aplica — evita ofrecer credenciales de airOS a un RouterOS');

            $table->string('username', 60);

            $table->text('password')
                ->comment('Cifrada por el cast del modelo; el sobre excede los 255 caracteres');

            $table->text('description')->nullable();

            $table->timestamp('rotated_at')->nullable()
                ->comment('Última vez que se cambió la contraseña — sin esto no hay forma de saber si una credencial lleva años sin tocarse');

            $table->timestamps();

            $table->index('vendor');
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->foreignId('credential_profile_id')->nullable()->after('password')
                ->constrained('device_credentials')
                ->cascadeOnUpdate()
                // Restrict y no cascade: borrar un perfil no puede llevarse por
                // delante los equipos que lo usaban. Quien lo borre tiene que
                // reasignarlos antes, a la vista.
                ->restrictOnDelete()
                ->comment('Perfil de credenciales, si este equipo no tiene las suyas propias');

            /*
             * Qué agente sondea este equipo.
             *
             * Hoy basta con uno en la oficina, que alcanza todas las torres. La
             * columna existe igual porque sin ella `GET /agent/monitoring/targets`
             * tendría que devolverle a cualquier agente las credenciales de TODAS
             * las antenas del ISP — una regresión frente al modelo actual, donde
             * el rol acota a qué secretos llega cada agente. Con la columna, añadir
             * un agente en una torre es enrolarlo y asignarle sus equipos.
             */
            $table->foreignId('agent_id')->nullable()->after('credential_profile_id')
                ->constrained('provisioning_agents')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Agente responsable de sondear este equipo; null = ninguno lo sondea');

            $table->boolean('is_monitored')->default(true)->after('agent_id')
                ->comment('Permite excluir del sondeo una CPE tras NAT sin borrarla del inventario');

            $table->index(['agent_id', 'is_monitored']);
        });
    }

    public function down(): void
    {
        /*
         * Las claves foráneas se sueltan ANTES que el índice, y en su propia
         * sentencia. MySQL exige que toda FK tenga un índice que la respalde y
         * aquí el índice compuesto `(agent_id, is_monitored)` es el único que
         * cubre `agent_id`: intentar borrarlo con la FK todavía puesta falla con
         * un «Cannot drop index: needed in a foreign key constraint».
         */
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropForeign(['credential_profile_id']);
            $table->dropForeign(['agent_id']);
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'is_monitored']);
            $table->dropColumn(['credential_profile_id', 'agent_id', 'is_monitored']);
        });

        Schema::dropIfExists('device_credentials');
    }
};
