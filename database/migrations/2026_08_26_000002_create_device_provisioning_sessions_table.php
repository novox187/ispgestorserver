<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una fila por intento de dar de alta un dispositivo. Es el agregado que
 * conduce la saga: guarda lo detectado, lo aplicado en cada extremo y la pila
 * de compensaciones necesaria para revertir un alta a medio camino.
 *
 * La fila de `mikrotik_routers` NO se crea hasta que la sesión llega a
 * `completed`: crearla antes haría que la regla "el primer router es primary"
 * (MikrotikRouter::booted) dejase al sistema devolviendo 423 si el alta falla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_provisioning_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agent_id')
                ->constrained('provisioning_agents')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Agente provisioner que detectó el dispositivo');

            $table->foreignId('router_id')->nullable()
                ->constrained('mikrotik_routers')
                ->cascadeOnUpdate()
                ->nullOnDelete()
                ->comment('Router resultante; null hasta que la sesión se completa');

            $table->string('status', 30)->default('detected')
                ->comment('detected|identifying|awaiting_approval|provisioning_router|'
                    . 'provisioning_host|verifying|completed|failed|rolled_back|cancelled');

            $table->string('detection_method', 20)
                ->comment('mndp | link_probe | arp | manual');

            // ── Identidad del dispositivo ────────────────────────────────────
            $table->string('mac_address', 17)->nullable()
                ->comment('MAC del puerto por el que se detectó (formato AA:BB:CC:DD:EE:FF)');
            $table->string('identity', 100)->nullable()
                ->comment('/system/identity del RouterOS');
            $table->string('board_name', 100)->nullable()
                ->comment('Modelo declarado por el equipo (ej: "hEX S", "RB750Gr3")');
            $table->string('routeros_version', 30)->nullable()
                ->comment('Versión de RouterOS — WireGuard exige >= 7.1');
            $table->string('serial_number', 60)->nullable()
                ->comment('Serial de RouterBOARD; clave de deduplicación preferente');
            $table->string('link_interface', 30)->nullable()
                ->comment('NIC del agente por la que se vio el equipo');
            $table->string('lan_ip', 45)->nullable()
                ->comment('IP de fábrica alcanzable en el segmento de bench (ej: 192.168.88.1)');

            // ── Resultado del túnel ──────────────────────────────────────────
            $table->string('vpn_interface', 30)->nullable()
                ->comment('Nombre de la interfaz WireGuard creada en el router');
            $table->string('vpn_assigned_ip', 45)->nullable()
                ->comment('IP asignada al router dentro de la subred VPN');
            $table->text('vpn_router_public_key')->nullable()
                ->comment('Clave pública generada por el propio router; la privada nunca sale de él');
            $table->string('vpn_endpoint', 255)->nullable()
                ->comment('host:puerto del servidor WireGuard usado por el router');

            // ── Saga ─────────────────────────────────────────────────────────
            $table->json('compensations')->nullable()
                ->comment('Pila LIFO de acciones de reversión pendientes por cada paso aplicado');
            // `text` y no `json` porque va cifrada: transporta la contraseña
            // generada para el usuario de gestión del router mientras dura el
            // alta, y el texto cifrado no es JSON válido.
            $table->text('context')->nullable()
                ->comment('Datos volátiles del flujo, cifrados (credenciales generadas, hallazgos)');

            $table->string('error_code', 60)->nullable()
                ->comment('Código estable del fallo (ej: ROUTEROS_VERSION_UNSUPPORTED)');
            $table->text('error_message')->nullable()
                ->comment('Detalle técnico del fallo para diagnóstico');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('mac_address');
            $table->index('serial_number');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_provisioning_sessions');
    }
};
