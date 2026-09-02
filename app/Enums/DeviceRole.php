<?php

namespace App\Enums;

/**
 * Papel que desempeña un equipo dentro de la red.
 *
 * El rol —no el fabricante— es lo que decide qué se le pide a cada equipo. Un
 * router de núcleo se sondea por su carga y sus colas; una antena, por su señal
 * y su CCQ. Separarlo del fabricante evita el error de suponer que «Ubiquiti»
 * implica «radio»: un EdgeRouter no lo es, y un MikroTik SXT sí.
 */
enum DeviceRole: string
{
    case CORE_ROUTER      = 'core_router';
    case EDGE_ROUTER      = 'edge_router';
    case BACKHAUL_AP      = 'backhaul_ap';
    case BACKHAUL_STATION = 'backhaul_station';
    case SECTOR_AP        = 'sector_ap';
    case CPE              = 'cpe';

    public function label(): string
    {
        return match ($this) {
            self::CORE_ROUTER      => 'Router de núcleo',
            self::EDGE_ROUTER      => 'Router de borde',
            self::BACKHAUL_AP      => 'Enlace troncal (AP)',
            self::BACKHAUL_STATION => 'Enlace troncal (estación)',
            self::SECTOR_AP        => 'Sector de acceso',
            self::CPE              => 'Antena de cliente',
        };
    }

    /**
     * ¿Este equipo tiene radio, y por tanto telemetría de señal, ruido y CCQ?
     *
     * Gobierna qué columnas tienen sentido en la ficha y qué se pinta en el
     * mapa. Un router de núcleo sin radio con `signal = null` no es un enlace
     * degradado, es un equipo al que esa métrica no le aplica.
     */
    public function hasRadio(): bool
    {
        return match ($this) {
            self::CORE_ROUTER, self::EDGE_ROUTER => false,
            default                              => true,
        };
    }

    /**
     * ¿Forma parte de la infraestructura propia del ISP?
     *
     * La distinción manda en las alertas: cuando cae un enlace troncal o un
     * sector se queda sin servicio mucha gente a la vez y hay que avisar de
     * inmediato; cuando cae la antena de un cliente, casi siempre es que la ha
     * desenchufado. Sin esta separación, el ruido de las CPE enterraría las
     * incidencias que de verdad importan.
     */
    public function isInfrastructure(): bool
    {
        return $this !== self::CPE;
    }

    /** Roles que el mapa dibuja como nodo propio de la topología. */
    public static function infrastructureCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $role->isInfrastructure(),
        ));
    }
}
