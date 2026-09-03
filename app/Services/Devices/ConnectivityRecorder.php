<?php

namespace App\Services\Devices;

use App\Models\NetworkDevice;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\MikrotikDisconnectedNotification;
use App\Notifications\Messages\MikrotikRecoveredNotification;
use App\Services\Devices\Dto\ProbeResult;

/**
 * Anota en el inventario lo que un sondeo averiguó del equipo.
 *
 * Vive aparte del trabajo periódico porque hay **dos** cosas que sondean: ese
 * trabajo y el botón de «probar credenciales» del panel. Cuando solo el primero
 * escribía el resultado, el botón hablaba con la antena, la veía responder y
 * tiraba esa evidencia: la ficha seguía en rojo hasta el siguiente ciclo, con el
 * operador mirando un «Desconectado» sobre un equipo que le acababa de
 * contestar.
 *
 * La política de alertado —el umbral de fallos seguidos— sigue siendo del
 * trabajo periódico. Aquí solo se registra.
 */
class ConnectivityRecorder
{
    /**
     * El equipo respondió.
     *
     * Se aprovecha para corregir el inventario: modelo y firmware los dice el
     * propio equipo y cambian sin que nadie los teclee.
     */
    public function recordUp(NetworkDevice $device, ProbeResult $result): void
    {
        $now                = now();
        $wasDisconnected    = $device->connectivity_status === 'disconnected';
        $lastDisconnectedAt = $device->last_disconnected_at;

        $device->forceFill(array_merge([
            'connectivity_status'  => 'connected',
            'last_health_check_at' => $now,
            'last_connected_at'    => $now,
            'consecutive_failures' => 0,
        ], $result->inventoryUpdates()))->save();

        // Una avería que termina es un hecho que merece quedar registrado,
        // aunque quien la haya terminado esté delante de la pantalla: si no, el
        // corte se cerraría sin dejar rastro de cuándo ni cómo.
        if ($wasDisconnected) {
            Notify::dispatch(MikrotikRecoveredNotification::build($device, $lastDisconnectedAt));
        }
    }

    /**
     * El equipo no respondió.
     *
     * Un fallo suelto no es una avería: se cuenta, y solo al llegar al umbral se
     * marca el equipo como caído y se alerta. Es lo que evita que un timeout
     * puntual despierte a alguien de madrugada.
     */
    public function recordDown(NetworkDevice $device, ProbeResult $result, int $threshold): void
    {
        $now         = now();
        $newFailures = ((int) $device->consecutive_failures) + 1;

        $device->forceFill([
            'last_health_check_at' => $now,
            'consecutive_failures' => $newFailures,
        ])->save();

        if ($newFailures < $threshold) {
            return;
        }

        if ($device->connectivity_status !== 'disconnected') {
            $device->forceFill([
                'connectivity_status'  => 'disconnected',
                'last_disconnected_at' => $now,
            ])->save();
        }

        Notify::dispatch(MikrotikDisconnectedNotification::build(
            router:          $device->refresh(),
            errorDetail:     (string) ($result->error ?? 'unknown'),
            lastConnectedAt: $device->last_connected_at,
        ));
    }
}
