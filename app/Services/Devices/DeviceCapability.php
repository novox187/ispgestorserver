<?php

namespace App\Services\Devices;

/**
 * Lo que un driver sabe hacer con un equipo.
 *
 * Existe para que el resto del sistema pregunte por capacidades en vez de por
 * fabricante. «¿Es Ubiquiti?» es la pregunta equivocada: un EdgeRouter no tiene
 * radio y un MikroTik SXT sí. Preguntar `supports(RADIO)` sobrevive a que mañana
 * entre un fabricante nuevo o a que uno existente cubra más casos.
 */
enum DeviceCapability: string
{
    /** Comprobar si el equipo responde. Todo driver debe soportarla. */
    case PROBE = 'probe';

    /** Leer estado general: uptime, CPU, memoria, firmware. */
    case TELEMETRY = 'telemetry';

    /** Leer métricas de radio: señal, ruido, SNR, CCQ, tasas. */
    case RADIO = 'radio';

    /** Enumerar vecinos descubiertos por el propio equipo (LLDP/CDP/MNDP). */
    case NEIGHBORS = 'neighbors';

    /** Enumerar las estaciones asociadas, si el equipo hace de punto de acceso. */
    case STATIONS = 'stations';

    public function label(): string
    {
        return match ($this) {
            self::PROBE     => 'Sondeo de alcance',
            self::TELEMETRY => 'Estado general',
            self::RADIO     => 'Métricas de radio',
            self::NEIGHBORS => 'Vecinos descubiertos',
            self::STATIONS  => 'Estaciones asociadas',
        };
    }
}
