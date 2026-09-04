<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía lo que se guarda de cada lectura para que la pantalla de monitoreo
 * pueda enseñar lo mismo que la interfaz del propio equipo.
 *
 * El driver de airOS ya leía más de lo que se guardaba: la respuesta de
 * `status.cgi` trae SSID, modo, cifrado, calidad y capacidad airMAX y el tráfico
 * instantáneo, y todo eso se descartaba al escribir la muestra. El operador
 * terminaba abriendo la web de la antena para ver justo lo que el sistema ya
 * había leído y tirado.
 *
 * ## Por qué unas columnas van a la serie y otras a la ficha
 *
 * Van a `device_metric_samples` las que cambian a cada lectura y cuya historia
 * dice algo: la calidad y la capacidad airMAX describen la degradación de un
 * enlace, y el tráfico dice si un cliente está saturando su plan. Sin serie, un
 * enlace que se degrada solo se detecta cuando ya está caído.
 *
 * Van a `network_devices` las que describen *cómo está configurado* el equipo:
 * SSID, modo y cifrado no cambian entre una lectura y la siguiente, y guardar
 * cien mil filas diarias repitiendo la misma cadena de texto sería pagar el
 * coste de una serie temporal para un dato que no varía. Aquí se sobrescriben,
 * igual que ya se hacía con la señal y el CCQ.
 *
 * `last_remote_mac` no duplica a `network_links`: la tabla de enlaces solo
 * conoce al otro extremo cuando **también** está en el inventario. La MAC del AP
 * la sabe la antena siempre, y en un CPE de abonado cuyo sector aún no está dado
 * de alta es la única pista de a qué se está asociando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_metric_samples', function (Blueprint $table) {
            /*
             * Los dos indicadores propios de airMAX. No son lo mismo que el CCQ:
             * la calidad mide cuánto se degrada el enlace por interferencia o
             * por tramas perdidas, y la capacidad cuánto del caudal teórico se
             * está pudiendo usar. Un enlace con señal excelente y capacidad al
             * 11 % —el del caso que motivó esto— parece sano en cualquier panel
             * que solo mire dBm.
             */
            $table->unsignedTinyInteger('airmax_quality_percent')->nullable()->after('ccq_percent');
            $table->unsignedTinyInteger('airmax_capacity_percent')->nullable()->after('airmax_quality_percent');

            /*
             * Tráfico instantáneo en kbps, que es como lo publica airOS. En kbps
             * y no en Mbps porque el dato llega ya en esa unidad y convertirlo al
             * guardar solo introduce redondeo en la parte baja, que es
             * precisamente donde vive el tráfico de una antena en reposo.
             */
            $table->unsignedInteger('tx_throughput_kbps')->nullable()->after('rx_rate_mbps');
            $table->unsignedInteger('rx_throughput_kbps')->nullable()->after('tx_throughput_kbps');
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->string('last_ssid', 64)->nullable()->after('last_ccq_percent')
                ->comment('SSID leído en la última muestra');
            $table->string('last_wireless_mode', 24)->nullable()->after('last_ssid')
                ->comment('Modo inalámbrico crudo del equipo: sta, ap, sta-wds…');
            $table->string('last_security', 24)->nullable()->after('last_wireless_mode')
                ->comment('Cifrado en uso, p. ej. WPA2-AES');
            $table->string('last_remote_mac', 17)->nullable()->after('last_security')
                ->comment('MAC del equipo al otro lado: el AP cuando somos estación');
        });
    }

    public function down(): void
    {
        Schema::table('device_metric_samples', function (Blueprint $table) {
            $table->dropColumn([
                'airmax_quality_percent',
                'airmax_capacity_percent',
                'tx_throughput_kbps',
                'rx_throughput_kbps',
            ]);
        });

        Schema::table('network_devices', function (Blueprint $table) {
            $table->dropColumn([
                'last_ssid',
                'last_wireless_mode',
                'last_security',
                'last_remote_mac',
            ]);
        });
    }
};
