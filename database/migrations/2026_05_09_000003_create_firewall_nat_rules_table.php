<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_nat_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('router_id')
                ->constrained('mikrotik_routers')->onDelete('cascade')
                ->comment('Router al que pertenece esta regla');
            $table->string('uuid')->unique()
                ->comment('UUID generado por el frontend para identificación cross-system');
            $table->string('external_id')->nullable()
                ->comment('ID asignado por RouterOS al aplicar la regla al equipo');

            // Estado y orden
            $table->boolean('enabled')->default(true)
                ->comment('Si la regla está activa en el NAT');
            $table->unsignedSmallInteger('priority')->default(0)
                ->comment('Orden de ejecución (menor = mayor prioridad)');

            // Acción
            $table->enum('chain', ['srcnat', 'dstnat'])
                ->comment('Cadena NAT: srcnat para salida, dstnat para entrada');
            $table->enum('action', ['masquerade', 'src-nat', 'dst-nat', 'redirect'])
                ->comment('Tipo de traducción a aplicar');

            // Condiciones de coincidencia
            $table->enum('protocol', ['any', 'tcp', 'udp', 'icmp'])->default('any')
                ->comment('Protocolo de transporte a filtrar');
            $table->string('src_address')->nullable()
                ->comment('Dirección o subred de origen (IPv4 o CIDR)');
            $table->string('src_address_list')->nullable()
                ->comment('Nombre de address-list de origen en RouterOS');
            $table->string('dst_address')->nullable()
                ->comment('Dirección o subred de destino (IPv4 o CIDR)');
            $table->string('src_port')->nullable()
                ->comment('Puerto o rango de origen (solo TCP/UDP)');
            $table->string('dst_port')->nullable()
                ->comment('Puerto o rango de destino (solo TCP/UDP)');
            $table->string('out_interface')->nullable()
                ->comment('Interfaz de salida del paquete (ej: WAN)');

            // Traducción de destino
            $table->string('to_addresses')->nullable()
                ->comment('IP destino traducida (no aplica en masquerade)');
            $table->string('to_ports')->nullable()
                ->comment('Puerto destino traducido (no aplica en masquerade)');

            // Logging y descripción
            $table->string('comment')->nullable()
                ->comment('Descripción legible de la regla');
            $table->boolean('log')->default(false)
                ->comment('Si se registran en el log de RouterOS los paquetes que coincidan');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firewall_nat_rules');
    }
};
