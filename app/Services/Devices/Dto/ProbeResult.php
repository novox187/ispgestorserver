<?php

namespace App\Services\Devices\Dto;

/**
 * Resultado de preguntarle a un equipo si está vivo.
 *
 * Inmutable y con constructores nombrados, al estilo de `TunnelSpec`: el
 * llamante no puede construir un resultado incoherente —un `ok` con mensaje de
 * error, o un fallo con datos de identidad— porque la clase no se lo permite.
 *
 * El sondeo aprovecha para devolver lo que el equipo cuente de sí mismo. Sale
 * gratis (ya hubo que hablar con él) y permite que el inventario se corrija solo
 * cuando alguien actualiza el firmware o sustituye una antena sin avisar.
 */
final readonly class ProbeResult
{
    private function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $identity = null,
        public ?string $model = null,
        public ?string $firmware = null,
        public ?int $uptimeSeconds = null,
    ) {
    }

    public static function up(
        ?string $identity = null,
        ?string $model = null,
        ?string $firmware = null,
        ?int $uptimeSeconds = null,
    ): self {
        return new self(
            ok: true,
            identity: $identity,
            model: $model,
            firmware: $firmware,
            uptimeSeconds: $uptimeSeconds,
        );
    }

    public static function down(string $error): self
    {
        return new self(ok: false, error: $error);
    }

    /**
     * Campos del inventario que este sondeo permite refrescar, ya filtrados de
     * nulos para poder pasarlos tal cual a un `fill()`.
     *
     * @return array<string, string>
     */
    public function inventoryUpdates(): array
    {
        return array_filter([
            'model'            => $this->model,
            'firmware_version' => $this->firmware,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
