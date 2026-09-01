<?php

namespace App\Services\Provisioning;

/**
 * Decide si un equipo detectado puede entrar por el flujo automático.
 *
 * Se comprueba ANTES de tocar nada: un equipo incompatible se rechaza sin que
 * se le haya escrito una sola línea de configuración, que es la diferencia
 * entre un rechazo limpio y dejar un router a medio configurar.
 *
 * Dos criterios, y el orden importa:
 *
 *  1. La versión de RouterOS. WireGuard es nativo desde la 7.1; por debajo no
 *     existe el menú y no hay nada que negociar.
 *  2. Si el agente pudo confirmar que el equipo tiene realmente el paquete.
 *     Esto no es redundante con lo anterior: los equipos SMIPS de poca memoria
 *     (hAP lite, RB941) corren RouterOS 7 con un juego de paquetes recortado en
 *     el que WireGuard puede no estar. Preguntarle al equipo es más fiable que
 *     mantener una lista de modelos.
 */
class DeviceCompatibilityChecker
{
    public const CODE_VERSION_UNSUPPORTED  = 'ROUTEROS_VERSION_UNSUPPORTED';
    public const CODE_VERSION_UNKNOWN      = 'ROUTEROS_VERSION_UNKNOWN';
    public const CODE_WIREGUARD_UNAVAILABLE = 'WIREGUARD_UNAVAILABLE';

    public function __construct(private readonly ProvisioningSettings $settings)
    {
    }

    /**
     * @param bool|null $wireguardAvailable Lo que el agente pudo confirmar en el
     *        equipo. `null` cuando aún no se ha podido comprobar (p. ej. en la
     *        detección inicial, antes de identificar).
     *
     * @return array{compatible: bool, code: ?string, reason: ?string, normalized_version: ?string}
     */
    public function check(?string $version, ?string $boardName = null, ?bool $wireguardAvailable = null): array
    {
        $minimum    = $this->settings->minimumRouterOsVersion();
        $normalized = $this->normalizeVersion($version);

        if ($normalized === null) {
            return $this->fail(
                self::CODE_VERSION_UNKNOWN,
                'No se pudo determinar la versión de RouterOS del equipo'
                    . ($boardName !== null ? " ({$boardName})" : '') . '.',
                null,
            );
        }

        if (version_compare($normalized, $minimum, '<')) {
            return $this->fail(
                self::CODE_VERSION_UNSUPPORTED,
                "El equipo reporta RouterOS {$normalized} y WireGuard requiere {$minimum} o superior."
                    . ($boardName !== null ? " Modelo: {$boardName}." : ''),
                $normalized,
            );
        }

        if ($wireguardAvailable === false) {
            return $this->fail(
                self::CODE_WIREGUARD_UNAVAILABLE,
                "El equipo corre RouterOS {$normalized} pero no expone WireGuard."
                    . ($boardName !== null ? " Modelo: {$boardName}." : '')
                    . ' Es habitual en equipos SMIPS con el juego de paquetes recortado.',
                $normalized,
            );
        }

        return [
            'compatible'         => true,
            'code'               => null,
            'reason'             => null,
            'normalized_version' => $normalized,
        ];
    }

    /**
     * Extrae la parte comparable de la cadena de versión.
     *
     * RouterOS devuelve cosas como `7.15.3`, `7.16beta2`, `7.12rc1` o
     * `6.48.6 (long-term)`. Solo interesan los números: un `7.16beta2` es, a
     * efectos de WireGuard, un 7.16.
     */
    public function normalizeVersion(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }

        if (!preg_match('/(\d+(?:\.\d+)*)/', trim($version), $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array{compatible: bool, code: ?string, reason: ?string, normalized_version: ?string}
     */
    private function fail(string $code, string $reason, ?string $normalized): array
    {
        return [
            'compatible'         => false,
            'code'               => $code,
            'reason'             => $reason,
            'normalized_version' => $normalized,
        ];
    }
}
