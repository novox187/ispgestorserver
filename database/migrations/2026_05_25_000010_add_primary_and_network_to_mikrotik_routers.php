<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            // Router principal: usado por defecto por todos los servicios cuando
            // no especifican router_id (MikroTikServiceProvider, sync, firewall).
            // El boot() del modelo garantiza que solo uno esté en true.
            $table->boolean('is_primary')->default(false)->after('is_active')
                ->comment('Router usado por defecto para todas las funcionalidades del sistema');

            // Red CIDR de los clientes detrás de este router. Ej: 192.168.20.0/24.
            // Permite que servicios de asignación de IP/firewall sepan qué subred
            // les corresponde sin tener que parsear el gateway.
            $table->string('network_cidr', 45)->nullable()->after('is_primary')
                ->comment('Subred de clientes en CIDR (ej: 192.168.20.0/24)');

            // Gateway que verán los clientes. Suele ser la IP LAN del router en
            // la subred declarada arriba. Ej: 192.168.20.1.
            $table->string('gateway', 45)->nullable()->after('network_cidr')
                ->comment('Gateway de la subred de clientes (ej: 192.168.20.1)');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn(['is_primary', 'network_cidr', 'gateway']);
        });
    }
};
