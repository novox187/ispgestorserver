<?php

use App\Jobs\DiscoverTopologyLinksJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta el worker que descubre enlaces por la tabla de vecinos.
 *
 * Cadencia holgada a propósito: la topología física cambia cuando alguien sube a
 * una torre, no cada cinco minutos. Consultarla más a menudo solo añadiría
 * tráfico contra los routers sin descubrir nada nuevo.
 */
return new class extends Migration
{
    private const KEY = 'topology_discovery';

    public function up(): void
    {
        if (DB::table('automation_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('automation_settings')->insert([
            'key'             => self::KEY,
            'name'            => 'Descubrimiento de Topología',
            'description'     => 'Pregunta a cada equipo alcanzable por sus vecinos (MNDP/CDP/LLDP) y registra los '
                . 'enlaces que encuentra. Los enlaces nacen como «descubiertos» y un operador los confirma.',
            'job_class'       => DiscoverTopologyLinksJob::class,
            'queue'           => 'default',
            'enabled'         => true,
            'schedule_type'   => 'hourly',
            'schedule_config' => json_encode([]),
            'params'          => json_encode(['query_timeout_seconds' => 5]),
            'params_schema'   => json_encode([
                'query_timeout_seconds' => [
                    'type' => 'integer', 'label' => 'Timeout por equipo (segundos)',
                    'description' => 'Tiempo máximo de espera al preguntarle a un equipo por sus vecinos.',
                    'min' => 1, 'max' => 30, 'required' => true,
                ],
            ]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('automation_settings')->where('key', self::KEY)->delete();
    }
};
