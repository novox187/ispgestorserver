<?php

namespace App\Enums;

/**
 * Fabricantes que el inventario sabe representar.
 *
 * El fabricante no es una etiqueta descriptiva: decide qué implementación de
 * `DeviceDriver` gobierna el equipo y, por tanto, por qué protocolo se le habla.
 * Añadir un fabricante es añadir un caso aquí y un driver que lo implemente.
 */
enum DeviceVendor: string
{
    case MIKROTIK = 'mikrotik';
    case UBIQUITI = 'ubiquiti';

    public function label(): string
    {
        return match ($this) {
            self::MIKROTIK => 'MikroTik',
            self::UBIQUITI => 'Ubiquiti',
        };
    }

    /** Driver por defecto para un equipo de este fabricante. */
    public function defaultDriver(): string
    {
        return match ($this) {
            self::MIKROTIK => 'routeros',
            self::UBIQUITI => 'airos',
        };
    }

    /**
     * Puerto del plano de gestión de este fabricante.
     *
     * No es un detalle cosmético: es la diferencia entre hablar con el equipo y
     * no hablar con él. RouterOS escucha su API binaria en 8728 y airOS publica
     * su interfaz web en 443, y no hay nada en común entre ambos.
     *
     * Vive aquí, junto a `defaultDriver()`, porque es la misma decisión: el
     * fabricante determina por qué protocolo y por qué puerta se le habla. La
     * columna `port` de la tabla lleva un `default(8728)` heredado de cuando el
     * inventario solo tenía routers MikroTik; cualquier alta que no fije el
     * puerto explícitamente se lo lleva puesto, y una antena Ubiquiti con 8728
     * falla al primer intento con un error de conexión que no explica nada.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::MIKROTIK => 8728,
            // 443 y no 80: airOS trae la web por TLS de fábrica, con
            // certificado autofirmado. El driver interpreta el 80 como HTTP
            // plano, así que este valor también elige el esquema.
            self::UBIQUITI => 443,
        };
    }

    /**
     * Prefijos OUI registrados a nombre del fabricante.
     *
     * Sirven para dos cosas distintas: decidir si un equipo detectado en el
     * banco es candidato a un alta, y proponer el driver correcto cuando un
     * barrido de subred encuentra algo. Por eso viven asociados al fabricante y
     * no en una lista plana de MAC admitidas.
     *
     * @return list<string>
     */
    public function macPrefixes(): array
    {
        return match ($this) {
            // MikroTikls SIA
            self::MIKROTIK => [
                // 00:0C:42 es el OUI clásico de RouterBOARD y 08:55:31 el de los
                // CCR recientes: faltaban los dos, y entre ambos cubren buena
                // parte de un parque real.
                '00:0C:42', '08:55:31', '18:FD:74', '2C:C8:1B', '48:8F:5A',
                '4C:5E:0C', '64:D1:54', '6C:3B:6B', '74:4D:28', '78:9A:18',
                '7C:2F:80', 'B8:69:F4', 'CC:2D:E0', 'D4:CA:6D', 'DC:2C:6E',
                'E4:8D:8C', 'F4:1E:57',
            ],
            // Ubiquiti Inc. / Ubiquiti Networks
            self::UBIQUITI => [
                '00:15:6D', '00:27:22', '04:18:D6', '18:E8:29', '24:A4:3C',
                '44:D9:E7', '60:22:32', '68:72:51', '70:A7:41', '74:83:C2',
                // 74:AC:B9 (LiteBeam M5) y F4:92:BF (PowerBeam, NanoStation M2)
                // se llevaban 11 de los 25 equipos del primer barrido real.
                '74:AC:B9', '78:8A:20', '80:2A:A8', '94:2A:6F', 'AC:8B:A9',
                'B4:FB:E4', 'DC:9F:DB', 'E0:63:DA', 'F0:9F:C2', 'F4:92:BF',
                'FC:EC:DA',
            ],
        };
    }

    /**
     * Fabricante al que pertenece una MAC, o null si no es de ninguno conocido.
     */
    public static function fromMacAddress(?string $mac): ?self
    {
        if ($mac === null) {
            return null;
        }

        $normalized = strtoupper(str_replace('-', ':', $mac));

        foreach (self::cases() as $vendor) {
            foreach ($vendor->macPrefixes() as $prefix) {
                if (str_starts_with($normalized, $prefix)) {
                    return $vendor;
                }
            }
        }

        return null;
    }
}
