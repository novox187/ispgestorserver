<?php

namespace App\Services\Devices\Dto;

/**
 * Una muestra de estado de un equipo, ya normalizada.
 *
 * Es la frontera entre «lo que dice cada fabricante» y «lo que guarda y pinta el
 * sistema». Los drivers traducen a esto; de aquí en adelante nadie necesita
 * saber si el equipo era un RouterOS o un airOS.
 *
 * `radio` es null en los equipos sin radio —un router de núcleo— y eso NO es un
 * enlace degradado, es una métrica que no aplica.
 */
final readonly class DeviceTelemetry
{
    public function __construct(
        public bool $reachable,
        public ?string $error = null,
        public ?int $uptimeSeconds = null,
        public ?float $cpuLoadPercent = null,
        public ?int $memoryFreeBytes = null,
        public ?int $memoryTotalBytes = null,
        public ?string $model = null,
        public ?string $firmware = null,
        public ?RadioTelemetry $radio = null,
    ) {
    }

    public static function unreachable(string $error): self
    {
        return new self(reachable: false, error: $error);
    }

    /**
     * El equipo respondió pero el driver no supo interpretar lo que devolvió.
     *
     * Es un estado propio y no un fallo a propósito: un firmware que aún no
     * sabemos leer no puede disparar una alerta de caída sobre un enlace que
     * está funcionando perfectamente.
     */
    public static function unparsed(string $error, ?string $firmware = null): self
    {
        return new self(reachable: true, error: $error, firmware: $firmware);
    }

    public function hasRadioMetrics(): bool
    {
        return $this->radio !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'reachable'          => $this->reachable,
            'error'              => $this->error,
            'uptime_seconds'     => $this->uptimeSeconds,
            'cpu_load_percent'   => $this->cpuLoadPercent,
            'memory_free_bytes'  => $this->memoryFreeBytes,
            'memory_total_bytes' => $this->memoryTotalBytes,
            'model'              => $this->model,
            'firmware'           => $this->firmware,
        ], $this->radio?->toArray() ?? []);
    }
}
