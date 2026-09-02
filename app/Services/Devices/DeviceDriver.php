<?php

namespace App\Services\Devices;

use App\Models\NetworkDevice;
use App\Services\Devices\Dto\DeviceTelemetry;
use App\Services\Devices\Dto\NeighborLink;
use App\Services\Devices\Dto\ProbeResult;

/**
 * Traduce «pregúntale su estado a este equipo» al protocolo de cada fabricante.
 *
 * Misma idea que `VpnDriver` y por las mismas razones: el parque del cliente
 * mezcla routers MikroTik (API binaria de RouterOS) con antenas Ubiquiti airMAX
 * (HTTP contra `status.cgi`), y mañana puede entrar un tercero. El monitoreo, el
 * inventario y el mapa operan contra esta interfaz y no contra `librouteros`, así
 * que añadir un fabricante no obliga a tocarlos.
 *
 * ## Reglas que todo driver debe cumplir
 *
 * 1. **Ningún método lanza.** Los drivers hablan con hardware ajeno por redes que
 *    se caen; una excepción sin capturar tumbaría el ciclo de monitoreo entero y
 *    dejaría sin sondear a los equipos que vinieran detrás. Los fallos se
 *    devuelven como `ProbeResult::down()` o `DeviceTelemetry::unreachable()`.
 * 2. **«No sé leerlo» no es «está caído».** Si el equipo responde pero el driver
 *    no entiende su firmware, la respuesta es `DeviceTelemetry::unparsed()`, que
 *    deja constancia sin disparar una alerta de enlace caído sobre un enlace que
 *    funciona.
 * 3. **No se escribe nada en el equipo.** El alcance actual es de solo lectura.
 */
interface DeviceDriver
{
    /** Fabricante que cubre este driver; casa con `network_devices.vendor`. */
    public function vendor(): string;

    /** Identificador que se persiste en `network_devices.driver`. */
    public function name(): string;

    public function supports(DeviceCapability $capability): bool;

    /**
     * ¿Responde el equipo? Es el sondeo barato que alimenta el monitor de
     * conectividad, y de paso devuelve lo que el equipo cuente de sí mismo.
     */
    public function probe(NetworkDevice $device, ?int $timeoutSeconds = null): ProbeResult;

    /**
     * Estado completo del equipo, ya normalizado.
     *
     * Un driver que no declare `DeviceCapability::TELEMETRY` puede devolver
     * `DeviceTelemetry::unparsed()`; nunca lanzar.
     */
    public function telemetry(NetworkDevice $device, ?int $timeoutSeconds = null): DeviceTelemetry;

    /**
     * Vecinos que el equipo dice tener, para construir la topología.
     *
     * Un driver que no declare `DeviceCapability::NEIGHBORS` devuelve lista
     * vacía. Como el resto de la interfaz: nunca lanza.
     *
     * @return list<NeighborLink>
     */
    public function neighbors(NetworkDevice $device, ?int $timeoutSeconds = null): array;

    /**
     * Traduce una respuesta cruda del equipo al vocabulario común.
     *
     * Existe para que el parseo viva en el SERVIDOR y no en el agente. Las
     * antenas solo son alcanzables desde la LAN del cliente, así que el agente
     * es quien habla con ellas —pero se limita a reenviar lo que le contestan.
     * La diferencia importa el día que aparezca un firmware que no sabemos leer:
     * darle soporte es un despliegue del servidor, no ir a la oficina del
     * cliente a actualizar a mano un demonio de Python.
     *
     * Como el resto de la interfaz: nunca lanza. Lo que no se entienda vuelve
     * como `DeviceTelemetry::unparsed()`, que deja el payload guardado para
     * poder soportarlo después sin disparar una alerta de enlace caído.
     *
     * @param array<string, mixed> $raw
     */
    public function normalize(array $raw): DeviceTelemetry;
}
