<?php

namespace App\Jobs;

use App\Models\NetworkScan;
use App\Services\Devices\NeighborScanEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completa un barrido recién cerrado con las tablas de vecinos de los routers.
 *
 * Va en cola y no dentro de la petición del agente por dos motivos: consultar
 * varios routers por el túnel puede tardar segundos, y el agente está esperando
 * la respuesta para seguir con su vuelta. Bloquearlo ahí retrasaría el sondeo
 * del parque entero para completar un barrido puntual.
 *
 * Se dispara también cuando el barrido FALLA. Parece contraintuitivo, pero el
 * caso típico es que el agente rechace el rango por no estar en su lista blanca:
 * entonces el barrido UDP no dio nada y la tabla de vecinos es lo único que le
 * queda al operador. Un fallo del agente no tiene por qué dejarle sin datos.
 */
class EnrichScanWithNeighborsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $scanId)
    {
    }

    public function handle(NeighborScanEnricher $enricher): void
    {
        $scan = NetworkScan::find($this->scanId);

        if ($scan === null) {
            return;
        }

        try {
            $anadidos = $enricher->enrich($scan);

            Log::info('EnrichScanWithNeighborsJob: barrido completado con vecinos.', [
                'scan_id'   => $scan->id,
                'cidr'      => $scan->cidr,
                'hallazgos' => $anadidos,
            ]);
        } catch (Throwable $e) {
            // El barrido ya está cerrado y sus hallazgos guardados. Que el
            // enriquecido falle no puede invalidar lo que sí se obtuvo, así que
            // se registra y se deja pasar en vez de reintentar en bucle.
            Log::warning('EnrichScanWithNeighborsJob: no se pudo completar.', [
                'scan_id' => $this->scanId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
