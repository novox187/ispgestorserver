<?php

namespace App\Services\Devices\Dto;

/**
 * Un vecino que un equipo dice tener al otro lado de una interfaz.
 *
 * Es la materia prima del mapa. La MAC es lo que importa: es lo único que
 * permite cruzar «este router ve algo en ether3» con «ese algo es la antena que
 * tengo en el inventario». El resto son pistas para que el operador reconozca lo
 * que está confirmando.
 */
final readonly class NeighborLink
{
    public function __construct(
        public ?string $remoteMac = null,
        public ?string $remoteIdentity = null,
        public ?string $remoteIp = null,
        public ?string $localInterface = null,
        public ?string $platform = null,
    ) {
    }

    /** Sin MAC no se puede cruzar con el inventario, y entonces no sirve. */
    public function isUsable(): bool
    {
        return $this->remoteMac !== null;
    }
}
