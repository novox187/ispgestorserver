<?php

namespace App\Providers;

use App\Services\Provisioning\ProvisioningAuditor;
use App\Services\Provisioning\ProvisioningSettings;
use App\Services\Provisioning\Vpn\VpnDriver;
use App\Services\Provisioning\Vpn\WireGuardDriver;
use Illuminate\Support\ServiceProvider;

/**
 * Enlaza las piezas del aprovisionamiento automático de dispositivos.
 *
 * El binding que importa es el de `VpnDriver`: toda la saga opera contra esa
 * interfaz y no contra `wg`, así que añadir mañana un `L2tpIpsecDriver` para
 * los equipos que se queden en RouterOS 6 es cambiar esta línea, no reescribir
 * el orquestador.
 */
class ProvisioningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VpnDriver::class, function () {
            return match (config('provisioning.vpn.driver', 'wireguard')) {
                default => new WireGuardDriver(),
            };
        });

        // Sin estado propio y consultados desde varios sitios en la misma
        // petición: singleton evita releer la configuración una y otra vez.
        $this->app->singleton(ProvisioningSettings::class);
        $this->app->singleton(ProvisioningAuditor::class);
    }
}
