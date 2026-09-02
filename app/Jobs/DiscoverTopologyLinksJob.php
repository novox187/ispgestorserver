<?php

namespace App\Jobs;

use App\Jobs\Concerns\NotifiesWorkerSummary;
use App\Models\AutomationSetting;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriverRegistry;
use App\Services\Devices\Dto\NeighborLink;
use App\Services\Devices\TopologyRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descubre enlaces preguntándole a cada equipo por sus vecinos.
 *
 * Cubre la mitad MikroTik del mapa. La otra mitad —los enlaces inalámbricos
 * entre antenas— se descubre en la ingesta de telemetría, porque esa
 * información solo llega ahí: las antenas no son alcanzables desde el servidor.
 *
 * Esta mitad **sí** se resuelve desde el servidor, sin agente: la tabla de
 * vecinos de RouterOS es alcanzable por el túnel WireGuard, y en ella aparecen
 * las antenas Ubiquiti porque hablan LLDP. Es la fuente de topología más barata
 * que hay, y por eso corre aquí y no en el agente.
 */
class DiscoverTopologyLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, NotifiesWorkerSummary;

    public const SETTING_KEY = 'topology_discovery';

    public int $tries = 1;
    public int $timeout = 600;

    public function handle(DeviceDriverRegistry $registry, TopologyRecorder $recorder): void
    {
        $setting = AutomationSetting::getCached(self::SETTING_KEY);
        if ($setting && !$setting->enabled) {
            return;
        }

        $timeout = max(1, (int) AutomationSetting::getParam(self::SETTING_KEY, 'query_timeout_seconds', 5));

        $consultados = 0;
        $enlaces     = 0;

        NetworkDevice::query()
            ->active()
            // Solo los que el servidor alcanza por su cuenta: los que sondea un
            // agente no son consultables desde aquí.
            ->whereNull('agent_id')
            ->each(function (NetworkDevice $device) use ($registry, $recorder, $timeout, &$consultados, &$enlaces) {
                $driver = $registry->for($device);

                if ($driver === null || !$driver->supports(DeviceCapability::NEIGHBORS)) {
                    return;
                }

                try {
                    $vecinos = $driver->neighbors($device, $timeout);
                    $consultados++;

                    $macs = array_values(array_filter(array_map(
                        fn (NeighborLink $n) => $n->isUsable() ? $n->remoteMac : null,
                        $vecinos,
                    )));

                    // El vecino de un router llega por cable salvo prueba en
                    // contrario; los enlaces inalámbricos los aporta la otra vía.
                    $enlaces += $recorder->recordPeers($device, $macs, 'neighbor', 'utp');
                } catch (Throwable $e) {
                    Log::warning('DiscoverTopologyLinksJob: fallo consultando vecinos.', [
                        'device_id' => $device->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            });

        Log::info('DiscoverTopologyLinksJob: descubrimiento completado.', [
            'equipos_consultados' => $consultados,
            'enlaces'             => $enlaces,
        ]);

        $this->notifyWorkerSummary(
            workerName: 'DiscoverTopologyLinksJob',
            result:     ['equipos_consultados' => $consultados, 'enlaces_registrados' => $enlaces],
            objective:  'Descubrir enlaces por la tabla de vecinos de cada equipo',
        );
    }

    public function failed(Throwable $exception): void
    {
        $this->notifyWorkerFailure(
            workerName: 'DiscoverTopologyLinksJob',
            exception:  $exception,
            objective:  'Descubrir enlaces por la tabla de vecinos de cada equipo',
        );
    }
}
