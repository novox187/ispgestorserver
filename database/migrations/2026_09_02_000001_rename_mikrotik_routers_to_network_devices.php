<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abre el inventario a más de un fabricante.
 *
 * El cliente gestiona la red con MikroTik (los routers grandes: colas, firewall,
 * NAT) pero levanta los enlaces con antenas Ubiquiti airMAX. Hasta ahora las
 * antenas eran invisibles para la plataforma, así que un enlace degradado no se
 * detectaba hasta que llamaban los clientes.
 *
 * La tabla pasa a llamarse `network_devices` en vez de crearse una tabla nueva
 * para Ubiquiti: dos inventarios paralelos obligarían a que cada funcionalidad
 * posterior —mapa, monitoreo, alertas, dashboard— hiciera UNION de ambos y
 * duplicara su lógica. El coste del renombrado se paga una vez; el de los dos
 * inventarios se pagaría en cada funcionalidad futura.
 *
 * MySQL actualiza por sí solo las claves foráneas que apuntan a la tabla al
 * ejecutar RENAME TABLE, de modo que `firewall_filter_rules.router_id`,
 * `firewall_nat_rules.router_id`, `firewall_apply_logs.router_id`,
 * `router_vpn_profiles.router_id` y `device_provisioning_sessions.router_id`
 * siguen siendo válidas sin tocarlas.
 *
 * Las columnas propias del plano de control (`network_cidr`, `gateway`,
 * `is_primary`, `routeros_version`) se quedan donde están y quedan vacías para
 * las antenas. No es un descuido: describen el papel de router de gestión, que
 * una antena de enlace no desempeña.
 *
 * ## Al desplegar: esto exige ventana de mantenimiento
 *
 * El DDL de MySQL/MariaDB provoca commit implícito, así que esta migración NO es
 * atómica: si falla a mitad no hay rollback y el esquema queda a medias. Además,
 * entre el `rename` y el arranque del contenedor nuevo, el viejo sigue sirviendo
 * tráfico con `MikrotikRouter::$table = 'mikrotik_routers'`, que ya no existe;
 * los cuatro workers y el scheduler del supervisor seguirían disparando trabajos
 * contra ella (`MonitorMikrotikConnectivityJob` corre con `tries = 1`).
 *
 * Procedimiento: parar la aplicación, restaurar un dump en un entorno aparte y
 * ensayar la migración ahí, comprobar que las cinco claves foráneas entrantes
 * siguen apuntando a la tabla, y solo entonces migrar y arrancar el contenedor
 * nuevo.
 *
 *   SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME
 *   FROM information_schema.KEY_COLUMN_USAGE
 *   WHERE REFERENCED_TABLE_NAME IN ('mikrotik_routers', 'network_devices');
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('mikrotik_routers', 'network_devices');

        /*
         * RENAME TABLE no toca el nombre de los índices, que siguen llamándose
         * `mikrotik_routers_*`. Es deuda silenciosa con fecha de explosión
         * lejana: `Blueprint::createIndexName()` deriva el nombre de la tabla del
         * blueprint, así que dentro de unos meses un `dropUnique(['mac_address'])`
         * sobre `network_devices` buscará `network_devices_mac_address_unique`,
         * no lo encontrará y la migración reventará sin que nadie entienda por
         * qué. Se renombran aquí, mientras el motivo está a la vista.
         */
        Schema::table('network_devices', function (Blueprint $table) {
            $table->renameIndex('mikrotik_routers_mac_address_unique', 'network_devices_mac_address_unique');
            $table->renameIndex('mikrotik_routers_serial_number_unique', 'network_devices_serial_number_unique');
        });

        Schema::table('network_devices', function (Blueprint $table) {
            /*
             * Se añaden nullable y sin valor por defecto a propósito. Un
             * `default('mikrotik')` rellenaría solo las filas existentes —que es
             * lo que se quiere— pero dejaría armado un cepo permanente: cualquier
             * alta futura que olvidara fijar el fabricante crearía en silencio un
             * MikroTik. El relleno se hace explícito abajo y después la columna
             * pasa a NOT NULL.
             */
            $table->string('vendor', 20)->nullable()->after('name')
                ->comment('mikrotik | ubiquiti — fabricante del equipo');
            $table->string('role', 30)->nullable()->after('vendor')
                ->comment('core_router | edge_router | backhaul_ap | backhaul_station | sector_ap | cpe');
            $table->string('driver', 30)->nullable()->after('role')
                ->comment('routeros | airos — implementación de DeviceDriver que gobierna este equipo');

            /*
             * Equivalentes neutrales de `board_name` y `routeros_version`. Los
             * originales NO se borran: el código de MikroTik los usa y el checker
             * de compatibilidad razona sobre versiones de RouterOS en concreto.
             * Estas dos son las que consultan el inventario y el mapa, que no
             * pueden saber de qué fabricante es cada fila.
             */
            $table->string('model', 60)->nullable()->after('board_name')
                ->comment('Modelo del equipo, sea cual sea el fabricante');
            $table->string('firmware_version', 40)->nullable()->after('routeros_version')
                ->comment('Versión de firmware, sea cual sea el fabricante');

            // Geo tipada. `clients.gps_coordinates` guarda un string libre de 50
            // caracteres sin parsear; aquí no se repite ese error.
            $table->decimal('latitude', 10, 7)->nullable()->after('gateway')
                ->comment('Latitud en grados decimales');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude')
                ->comment('Longitud en grados decimales');

            /*
             * Última telemetría, desnormalizada sobre la propia fila. El listado
             * de equipos y el mapa necesitan el dato más reciente de cada uno;
             * sacarlo de la tabla de muestras exigiría una subconsulta por fila o
             * un LATERAL, y ambos son innecesarios para un valor que se
             * sobrescribe en cada sondeo.
             */
            $table->smallInteger('last_signal_dbm')->nullable()->after('consecutive_failures')
                ->comment('Señal en dBm de la última muestra (negativa)');
            $table->unsignedTinyInteger('last_ccq_percent')->nullable()->after('last_signal_dbm')
                ->comment('CCQ 0-100 de la última muestra');
            $table->timestamp('last_telemetry_at')->nullable()->after('last_ccq_percent')
                ->comment('Cuándo se recibió la última muestra — envejece si el agente cae');

            // El scope global de MikrotikRouter filtra por fabricante en cada
            // consulta del módulo MikroTik, que es casi todo el sistema.
            $table->index('vendor');
            $table->index(['vendor', 'role']);
        });

        /*
         * Todo lo que había en la tabla antes de esta migración es, por
         * definición, un router MikroTik de gestión dado de alta contra la API de
         * RouterOS.
         */
        DB::table('network_devices')->update([
            'vendor' => 'mikrotik',
            'role'   => 'core_router',
            'driver' => 'routeros',
        ]);

        // Los equivalentes neutrales se siembran desde las columnas específicas
        // en dos sentencias separadas: una fila puede tener modelo sin versión.
        DB::statement('UPDATE network_devices SET model = board_name WHERE board_name IS NOT NULL');
        DB::statement('UPDATE network_devices SET firmware_version = routeros_version WHERE routeros_version IS NOT NULL');

        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('vendor', 20)->nullable(false)->change();
            $table->string('role', 30)->nullable(false)->change();
            $table->string('driver', 30)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        /*
         * Al revertir se pierden las filas de equipos que no sean MikroTik: la
         * tabla vuelve a un esquema que no sabe representarlos. Se borran ANTES
         * de eliminar la columna que los distingue —de ahí el orden— para no
         * dejar en `mikrotik_routers` equipos que no lo son, con credenciales
         * que el código de RouterOS intentaría usar contra la API binaria.
         */
        DB::table('network_devices')->where('driver', '!=', 'routeros')->delete();

        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropIndex(['vendor', 'role']);
            $table->dropIndex(['vendor']);
            $table->dropColumn([
                'vendor',
                'role',
                'driver',
                'model',
                'firmware_version',
                'latitude',
                'longitude',
                'last_signal_dbm',
                'last_ccq_percent',
                'last_telemetry_at',
            ]);
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->renameIndex('network_devices_mac_address_unique', 'mikrotik_routers_mac_address_unique');
            $table->renameIndex('network_devices_serial_number_unique', 'mikrotik_routers_serial_number_unique');
        });

        Schema::rename('network_devices', 'mikrotik_routers');
    }
};
