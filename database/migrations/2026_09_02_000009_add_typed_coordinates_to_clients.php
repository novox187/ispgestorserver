<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenadas de cliente tipadas, a partir del texto libre que ya había.
 *
 * `clients.gps_coordinates` es un `string(50)` donde cada quien escribió lo que
 * quiso: «-0.1807, -78.4678», «-0.1807,-78.4678», a veces con grados o con una
 * URL de Google Maps pegada. Sirve para que un técnico lo copie y lo pegue, pero
 * no para dibujar un mapa ni para calcular distancias.
 *
 * **La columna original NO se borra.** Es dato que introdujo el usuario y puede
 * contener matices que el parseo pierde —una referencia, una nota— así que se
 * conserva como estaba. Estas dos columnas son las que consulta el mapa.
 *
 * Lo que no se pueda interpretar se deja nulo y se registra en el log: es
 * preferible un cliente sin punto en el mapa a un punto en mitad del océano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('gps_coordinates');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        $parseadas = 0;
        $fallidas  = [];

        DB::table('clients')
            ->whereNotNull('gps_coordinates')
            ->where('gps_coordinates', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($clientes) use (&$parseadas, &$fallidas) {
                foreach ($clientes as $cliente) {
                    $coords = $this->parse((string) $cliente->gps_coordinates);

                    if ($coords === null) {
                        $fallidas[] = $cliente->id;
                        continue;
                    }

                    DB::table('clients')->where('id', $cliente->id)->update($coords);
                    $parseadas++;
                }
            });

        Log::info('Backfill de coordenadas de clientes completado.', [
            'parseadas'      => $parseadas,
            'sin_interpretar' => count($fallidas),
            // Se listan para que alguien pueda revisarlas a mano; el texto
            // original sigue en su columna.
            'ids_pendientes' => array_slice($fallidas, 0, 50),
        ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }

    /**
     * Extrae un par de coordenadas de un texto libre.
     *
     * Se buscan los dos primeros números con signo del texto, lo que absorbe las
     * variantes reales («lat, lng», «lat lng», una URL de Maps pegada) sin
     * intentar adivinar formatos exóticos. Se validan los rangos: un par fuera
     * de [-90,90] y [-180,180] no es una coordenada, sea lo que sea.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function parse(string $raw): ?array
    {
        if (!preg_match_all('/-?\d+(?:\.\d+)?/', $raw, $m) || count($m[0]) < 2) {
            return null;
        }

        $lat = (float) $m[0][0];
        $lng = (float) $m[0][1];

        // Un par de enteros pequeños suele ser otra cosa (un identificador, una
        // fecha partida) más que una coordenada real.
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng];
    }
};
