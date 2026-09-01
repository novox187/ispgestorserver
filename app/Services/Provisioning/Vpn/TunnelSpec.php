<?php

namespace App\Services\Provisioning\Vpn;

/**
 * Todo lo que define un túnel concreto entre un router y el hosting.
 *
 * Es inmutable y se construye una sola vez por sesión, antes de tocar ningún
 * extremo. Que los dos lados se configuren a partir del mismo objeto es lo que
 * garantiza que no se contradigan: una IP en el router y otra en el peer del
 * host produce un túnel que levanta y no enruta.
 *
 * Obsérvese qué NO hay aquí: ninguna clave privada. La del router la genera el
 * propio RouterOS al crear la interfaz y solo se lee su pública; la del
 * servidor no sale nunca del sistema operativo del hosting.
 */
final class TunnelSpec
{
    public function __construct(
        public readonly string $interfaceName,
        public readonly string $assignedIp,
        public readonly int $prefixLength,
        public readonly string $serverIp,
        public readonly string $serverPublicKey,
        public readonly string $endpointHost,
        public readonly int $endpointPort,
        public readonly string $subnet,
        public readonly int $keepalive,
    ) {
    }

    /** Dirección con máscara tal y como se escribe en el router. */
    public function addressWithPrefix(): string
    {
        return "{$this->assignedIp}/{$this->prefixLength}";
    }

    /**
     * Redes que el router enruta por el túnel. Se limita a la subred VPN: el
     * router no debe mandar por aquí su tráfico de internet, solo lo que va al
     * sistema de gestión.
     */
    public function routerAllowedAddress(): string
    {
        return $this->subnet;
    }

    /**
     * Lo que el hosting acepta de este peer: exactamente su /32 y nada más.
     * Una máscara más ancha permitiría a un router suplantar a otro.
     */
    public function peerAllowedIps(): string
    {
        return "{$this->assignedIp}/32";
    }

    public function endpoint(): string
    {
        return "{$this->endpointHost}:{$this->endpointPort}";
    }

    public function toArray(): array
    {
        return [
            'interface_name'    => $this->interfaceName,
            'assigned_ip'       => $this->assignedIp,
            'prefix_length'     => $this->prefixLength,
            'server_ip'         => $this->serverIp,
            'server_public_key' => $this->serverPublicKey,
            'endpoint_host'     => $this->endpointHost,
            'endpoint_port'     => $this->endpointPort,
            'subnet'            => $this->subnet,
            'keepalive'         => $this->keepalive,
        ];
    }
}
