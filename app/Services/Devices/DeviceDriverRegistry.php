<?php

namespace App\Services\Devices;

use App\Models\NetworkDevice;
use RuntimeException;

/**
 * Resuelve qué driver gobierna cada equipo, a partir de `network_devices.driver`.
 *
 * Se resuelve por la columna y no por el fabricante porque un mismo fabricante
 * puede necesitar más de un transporte —airOS 5.x y airOS 8.x acabarán siendo
 * dos— y porque así cambiar el driver de un equipo concreto es editar una fila,
 * no desplegar código.
 */
class DeviceDriverRegistry
{
    /** @var array<string, DeviceDriver> */
    private array $drivers = [];

    /** @param iterable<DeviceDriver> $drivers */
    public function __construct(iterable $drivers = [])
    {
        foreach ($drivers as $driver) {
            $this->register($driver);
        }
    }

    public function register(DeviceDriver $driver): void
    {
        $this->drivers[$driver->name()] = $driver;
    }

    public function has(?string $name): bool
    {
        return $name !== null && isset($this->drivers[$name]);
    }

    /**
     * Driver de un equipo, o null si su columna `driver` no corresponde a
     * ninguno registrado.
     *
     * Devuelve null en vez de lanzar porque el caso tiene que poder ocurrir sin
     * romper nada: una fila puede quedar apuntando a un driver que todavía no
     * está implementado —o que se retiró— y el monitoreo debe saltársela y
     * seguir con los demás equipos, no abortar el ciclo.
     */
    public function for(NetworkDevice $device): ?DeviceDriver
    {
        return $this->drivers[$device->driver] ?? null;
    }

    /** @throws RuntimeException si no hay driver para el equipo. */
    public function forOrFail(NetworkDevice $device): DeviceDriver
    {
        return $this->for($device) ?? throw new RuntimeException(
            "No hay driver registrado para «{$device->driver}» (dispositivo #{$device->id})."
        );
    }

    /** @return array<string, DeviceDriver> */
    public function all(): array
    {
        return $this->drivers;
    }
}
