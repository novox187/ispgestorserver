<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topología de la red: dónde está cada cosa y qué está conectado con qué.
 *
 * Hasta ahora el sistema no tenía ninguna noción de esto —ni sitio, ni torre, ni
 * enlace— y la única coordenada era `clients.gps_coordinates`, una cadena libre
 * de 50 caracteres sin parsear. Un mapa no se puede construir sobre eso.
 *
 * ## Sitios y equipos son cosas distintas
 *
 * Un sitio es un lugar físico —una torre, una azotea, el POP— y aloja varios
 * equipos. Separarlos permite mover una antena de torre sin reescribir sus
 * coordenadas, y que el mapa agrupe media docena de equipos en un solo punto en
 * vez de apilar marcadores en la misma posición.
 *
 * ## Los enlaces se normalizan por orden de id
 *
 * Un enlace entre A y B es el mismo que entre B y A. Sin normalizar, el
 * autodescubrimiento crearía dos filas para el mismo enlace —una por cada
 * extremo que lo reporte— y el mapa dibujaría dos líneas superpuestas. Se guarda
 * siempre con el id menor en `a_device_id`, y el índice único hace el resto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_sites', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            $table->string('type', 20)->default('tower')
                ->comment('tower | pole | rooftop | pop | office | customer');

            $table->string('address', 255)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->smallInteger('elevation_m')->nullable()
                ->comment('Altura sobre el nivel del mar — decide qué enlaces tienen línea de vista');

            /*
             * Jerarquía opcional: una azotea puede colgar del POP que la
             * alimenta. Sirve para plegar el mapa por zonas sin inventar otra
             * entidad.
             */
            $table->foreignId('parent_site_id')->nullable()
                ->constrained('network_sites')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('longitude')
                ->constrained('network_sites')
                ->cascadeOnUpdate()
                // Borrar un sitio no puede llevarse por delante los equipos que
                // había en él: quedan sin ubicar, a la vista, para recolocarlos.
                ->nullOnDelete();

            $table->index('site_id');
        });

        Schema::create('network_links', function (Blueprint $table) {
            $table->id();

            // Siempre el id menor en `a_device_id`; ver la nota de arriba.
            $table->foreignId('a_device_id')
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('b_device_id')
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->string('a_interface', 40)->nullable();
            $table->string('b_interface', 40)->nullable();

            $table->string('type', 20)->default('wireless_ptp')
                ->comment('wireless_ptp | wireless_ptmp | fiber | utp | vpn');

            /*
             * Los enlaces descubiertos nacen `discovered` y el operador los
             * confirma. Sin ese paso, el mapa se llenaría de líneas hacia el
             * switch de la oficina y el portátil del técnico, que también hablan
             * LLDP.
             */
            $table->string('status', 20)->default('discovered')
                ->comment('discovered | confirmed | archived');

            $table->string('discovery_source', 20)->default('manual')
                ->comment('manual | neighbor | airos_station');

            $table->timestamp('last_seen_at')->nullable()
                ->comment('Última vez que el descubrimiento volvió a verlo — un enlace que deja de verse no se borra solo');

            $table->unsignedSmallInteger('expected_capacity_mbps')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['a_device_id', 'b_device_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropIndex(['site_id']);
            $table->dropColumn('site_id');
        });

        Schema::dropIfExists('network_links');
        Schema::dropIfExists('network_sites');
    }
};
