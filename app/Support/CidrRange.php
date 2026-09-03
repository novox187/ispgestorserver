<?php

namespace App\Support;

/**
 * Un rango IPv4 con la única pregunta que hace falta responder: ¿está esta
 * dirección dentro?
 *
 * Existe porque el descubrimiento cruza dos fuentes que ven redes distintas: la
 * tabla de vecinos del router de borde alcanza todo el ISP —en el parque real,
 * seis redes y 146 equipos—, y hay que quedarse solo con lo que cae dentro del
 * rango que el operador pidió barrer. Sin el filtro, pedir la red de gestión
 * devolvería además los cien y pico CPE de abonado de las otras.
 *
 * Solo IPv4 a propósito: es lo que usan las redes de gestión de este parque, y
 * una implementación a medias de IPv6 sería peor que no tenerla.
 */
final readonly class CidrRange
{
    private function __construct(
        private int $red,
        private int $mascara,
    ) {
    }

    /** Devuelve null en vez de lanzar: el CIDR llega de fuera y puede ser basura. */
    public static function tryParse(string $cidr): ?self
    {
        [$ip, $prefijo] = array_pad(explode('/', trim($cidr), 2), 2, null);

        if ($prefijo === null || !ctype_digit($prefijo)) {
            return null;
        }

        $prefijo = (int) $prefijo;

        if ($prefijo < 0 || $prefijo > 32) {
            return null;
        }

        $largo = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($largo === false) {
            return null;
        }

        // Un /0 desplazado 32 bits es comportamiento indefinido en C y en PHP
        // devuelve el operando sin tocar, así que se resuelve aparte.
        $mascara = $prefijo === 0 ? 0 : (-1 << (32 - $prefijo)) & 0xFFFFFFFF;

        return new self(ip2long($largo) & $mascara, $mascara);
    }

    public function contains(string $ip): bool
    {
        $valida = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if ($valida === false) {
            return false;
        }

        return (ip2long($valida) & $this->mascara) === $this->red;
    }
}
