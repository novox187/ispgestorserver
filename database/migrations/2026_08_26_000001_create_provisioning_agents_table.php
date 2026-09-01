<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agentes de aprovisionamiento: demonios que corren FUERA del contenedor Docker
 * y se conectan hacia la API (nunca al revés). Dos roles:
 *
 *  - `provisioner`: vive en la oficina, detecta routers conectados por Ethernet
 *    y les aplica la configuración WireGuard vía API de RouterOS.
 *  - `vpn_host`:    vive en el sistema operativo del hosting, administra los
 *    peers de la interfaz WireGuard del servidor.
 *
 * La autenticación es HMAC-SHA256 sobre `secret`; `token_hash` identifica al
 * agente sin guardar el token en claro (mismo criterio que Sanctum).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_agents', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100)
                ->comment('Nombre descriptivo del agente (ej: "Bench oficina Quito")');
            $table->string('role', 20)
                ->comment('provisioner | vpn_host — determina qué tareas puede reclamar');

            $table->string('token_hash', 64)->nullable()->unique()
                ->comment('SHA-256 del token de acceso; el token en claro solo existe en el agente');
            $table->text('secret')->nullable()
                ->comment('Secreto HMAC cifrado con el que el agente firma cada petición');

            $table->string('enrollment_token_hash', 64)->nullable()->unique()
                ->comment('SHA-256 del token de enrolamiento de un solo uso');
            $table->timestamp('enrollment_expires_at')->nullable()
                ->comment('Caducidad del token de enrolamiento (TTL corto)');
            $table->timestamp('enrolled_at')->nullable()
                ->comment('Momento en que el agente canjeó el token y recibió sus credenciales');

            $table->boolean('is_active')->default(true)
                ->comment('Un agente revocado deja de poder autenticarse inmediatamente');

            $table->timestamp('last_seen_at')->nullable()
                ->comment('Último heartbeat o petición autenticada recibida');
            $table->string('last_ip', 45)->nullable()
                ->comment('IP de origen de la última petición autenticada');
            $table->string('agent_version', 30)->nullable()
                ->comment('Versión del binario del agente, reportada en el heartbeat');

            $table->json('capabilities')->nullable()
                ->comment('Datos que el agente publica: para vpn_host la clave pública del '
                    . 'servidor, endpoint, interfaz y subred; para provisioner las NIC vigiladas');

            $table->timestamps();

            $table->index(['role', 'is_active']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_agents');
    }
};
