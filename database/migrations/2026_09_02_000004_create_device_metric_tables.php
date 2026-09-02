<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serie temporal de métricas de los equipos, en dos resoluciones.
 *
 * ## Por qué dos tablas
 *
 * El alcance incluye las antenas de cliente: unos 400 equipos sondeados cada 5
 * minutos son ~100.000 filas al día, ~37 millones al año. Guardar todo eso al
 * detalle durante meses hace inviables los backups de la VPS sin que nadie llegue
 * a consultar jamás el dato de las 3:47 de hace ocho meses.
 *
 * `device_metric_samples` guarda el detalle con retención corta (lo que sirve
 * para diagnosticar una incidencia en curso) y `device_metric_hourly` guarda
 * mínimos, medias y máximos por hora con retención larga (lo que sirve para ver
 * la tendencia de un enlace que se degrada poco a poco). La segunda ocupa ~60
 * veces menos.
 *
 * ## Por qué no hay columna `raw`
 *
 * Guardar el JSON crudo de cada respuesta multiplicaría por tres o cuatro el
 * tamaño de la tabla para conservar datos que nadie consulta. Solo se guarda el
 * payload cuando el driver NO supo interpretarlo (`unparsed_payload`), que es
 * justo el caso en que hace falta para depurar un firmware nuevo.
 *
 * ## Por qué `dateTime` y no `timestamp`
 *
 * Las columnas TIMESTAMP de MySQL/MariaDB se convierten según la zona horaria de
 * la sesión al escribir y al leer. Un `mysqldump` restaurado con otra
 * `time_zone` desplazaría la serie entera, y encima el tipo se acaba en 2038.
 * El agente envía epoch en segundos y aquí se guarda como UTC sin conversiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_metric_samples', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                // Cascade sí: las métricas de un equipo borrado no le sirven a
                // nadie y dejarlas huérfanas solo estorbaría a la poda.
                ->cascadeOnDelete();

            $table->dateTime('sampled_at')
                ->comment('Momento de la lectura EN UTC, tal como lo declaró el agente');

            // Estado general — lo tiene cualquier equipo.
            $table->unsignedInteger('uptime_seconds')->nullable();
            $table->decimal('cpu_load_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('memory_free_bytes')->nullable();
            $table->unsignedBigInteger('memory_total_bytes')->nullable();

            /*
             * Métricas de radio. Nulas en un router de núcleo, y eso NO es un
             * enlace degradado: es una métrica que no le aplica. Confundir
             * ambas cosas es la forma más rápida de llenar el panel de alarmas
             * falsas.
             */
            $table->smallInteger('signal_dbm')->nullable()->comment('Negativa, p. ej. -67');
            $table->smallInteger('noise_floor_dbm')->nullable();
            $table->smallInteger('snr_db')->nullable();
            $table->unsignedTinyInteger('ccq_percent')->nullable();
            $table->decimal('tx_rate_mbps', 8, 2)->nullable();
            $table->decimal('rx_rate_mbps', 8, 2)->nullable();
            $table->smallInteger('tx_power_dbm')->nullable();
            $table->unsignedSmallInteger('frequency_mhz')->nullable();
            $table->unsignedSmallInteger('channel_width_mhz')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->unsignedSmallInteger('station_count')->nullable();

            $table->text('unparsed_payload')->nullable()
                ->comment('Solo cuando el driver no supo leer la respuesta: material para soportar ese firmware');

            /*
             * El nonce del HMAC protege contra reenviar la MISMA petición, no
             * contra un reintento —que genera nonce nuevo—. Si se pierde la
             * respuesta, el agente reenvía el lote y duplicaría. Este índice lo
             * absorbe con `insertOrIgnore`, y de paso es el índice con el que se
             * consultan las series, así que no cuesta nada aparte.
             */
            $table->unique(['device_id', 'sampled_at']);

            // La poda borra por antigüedad sin filtrar por equipo.
            $table->index('sampled_at');
        });

        Schema::create('device_metric_hourly', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained('network_devices')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->dateTime('bucket_hour')->comment('Inicio de la hora agregada, en UTC');

            $table->unsignedSmallInteger('sample_count')->default(0);

            /*
             * Mínimo, media y máximo. La media sola miente en lo que más
             * importa: un enlace que se cae treinta segundos cada hora tiene
             * una media excelente, y es justo el que hay que ir a mirar.
             */
            $table->smallInteger('signal_min_dbm')->nullable();
            $table->decimal('signal_avg_dbm', 6, 2)->nullable();
            $table->smallInteger('signal_max_dbm')->nullable();

            $table->unsignedTinyInteger('ccq_min_percent')->nullable();
            $table->decimal('ccq_avg_percent', 5, 2)->nullable();

            $table->smallInteger('snr_min_db')->nullable();
            $table->decimal('snr_avg_db', 6, 2)->nullable();

            $table->decimal('cpu_avg_percent', 5, 2)->nullable();
            $table->decimal('cpu_max_percent', 5, 2)->nullable();

            $table->timestamps();

            $table->unique(['device_id', 'bucket_hour']);
            $table->index('bucket_hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_metric_hourly');
        Schema::dropIfExists('device_metric_samples');
    }
};
