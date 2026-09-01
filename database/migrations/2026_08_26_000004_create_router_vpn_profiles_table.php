<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil VPN vigente de cada router (1:1). Además de documentar el túnel, la
 * unicidad de `assigned_ip` es la última red de seguridad del asignador de
 * direcciones: aunque dos sesiones concurrentes escapasen al `lockForUpdate`,
 * la base de datos rechazaría la segunda.
 *
 * Las filas con `status = revoked` se conservan para que la auditoría pueda
 * explicar quién ocupó una IP antes de reciclarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_vpn_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('router_id')
                ->constrained('mikrotik_routers')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('session_id')->nullable()
                ->constrained('device_provisioning_sessions')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Sesión de aprovisionamiento que lo creó');

            $table->string('driver', 20)->default('wireguard')
                ->comment('Tecnología del túnel — hoy solo wireguard');

            $table->string('interface_name', 30)
                ->comment('Nombre de la interfaz en el router (ej: wg-ispgestor)');

            // Nullable a propósito: MySQL admite varios NULL en un índice único,
            // así que revocar un perfil (assigned_ip → null) libera la dirección
            // para el pool sin borrar la fila ni ensuciar el valor.
            $table->string('assigned_ip', 45)->nullable()->unique()
                ->comment('IP del router dentro de la subred VPN — única mientras el perfil la retiene');
            $table->string('released_ip', 45)->nullable()
                ->comment('Dirección que ocupó este perfil una vez revocado — deja constancia del reciclaje');

            $table->text('router_public_key')
                ->comment('Clave pública del router; la privada se queda en el equipo');
            $table->text('server_public_key')
                ->comment('Clave pública del servidor WireGuard del hosting');

            $table->string('endpoint_host', 255)
                ->comment('Host público al que el router marca (el VPS)');
            $table->unsignedSmallInteger('endpoint_port')->default(51820);
            $table->string('allowed_ips', 100)
                ->comment('Redes que el router enruta por el túnel');
            $table->unsignedSmallInteger('keepalive')->default(25)
                ->comment('persistent-keepalive en segundos — imprescindible tras NAT');

            $table->string('status', 20)->default('pending')
                ->comment('pending | active | failed | revoked');

            $table->timestamp('last_handshake_at')->nullable()
                ->comment('Último handshake observado en cualquiera de los dos extremos');

            $table->timestamps();

            $table->index(['router_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_vpn_profiles');
    }
};
