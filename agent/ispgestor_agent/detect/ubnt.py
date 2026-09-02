"""Descubrimiento de equipos Ubiquiti por su protocolo propio.

Los equipos airOS escuchan en el puerto UDP 10001 y responden a una sonda de
cuatro bytes con un paquete de TLVs que dice quiénes son. Es el mismo mecanismo
que usa la herramienta oficial de Ubiquiti.

## Unicast, no broadcast

La forma «natural» sería un broadcast a la red local, y así lo hace la
herramienta de Ubiquiti. Aquí no sirve: **el broadcast no cruza el dominio de
capa 2 del agente**, y las antenas están en torres, en otras redes. Se barre
dirección por dirección en unicast, que el protocolo también admite.

## El agente decide qué puede barrer

`sweep()` recibe el rango, pero quien lo llama debe haberlo validado contra la
lista blanca de la configuración LOCAL del agente. Es el mismo principio que ya
aplica `VpnDriver` con su lista blanca de operaciones: ni un servidor
comprometido debe poder convertir al agente en un escáner de red dirigido.

Solo biblioteca estándar, como el resto del agente.
"""

from __future__ import annotations

import ipaddress
import logging
import socket
import struct
import time
from dataclasses import dataclass

log = logging.getLogger("ispgestor.ubnt")

#: Puerto en el que escuchan los equipos Ubiquiti.
UBNT_PORT = 10001

#: Sonda de la versión 1 del protocolo. Los equipos responden con sus TLVs.
PROBE = b"\x01\x00\x00\x00"

# Tipos de TLV observados en airOS. Los que no se reconocen se ignoran sin
# romper: un firmware nuevo puede añadir campos y eso no debe invalidar el resto.
TLV_MAC = 0x01
TLV_MAC_AND_IP = 0x02
TLV_FIRMWARE = 0x03
TLV_UPTIME = 0x0A
TLV_HOSTNAME = 0x0B
TLV_PLATFORM = 0x0C
TLV_ESSID = 0x0D
TLV_WMODE = 0x0E
TLV_MODEL = 0x14


@dataclass
class UbntDevice:
    """Lo que un equipo cuenta de sí mismo al responder a la sonda."""

    ip_address: str | None = None
    mac_address: str | None = None
    firmware: str | None = None
    hostname: str | None = None
    platform: str | None = None
    model: str | None = None
    essid: str | None = None
    uptime_seconds: int | None = None

    def to_finding(self) -> dict:
        return {
            "ip_address": self.ip_address,
            "mac_address": self.mac_address,
            "firmware": self.firmware,
            "hostname": self.hostname,
            # `model` es el nombre comercial (NanoStation M5) y `platform` el
            # interno; se prefiere el primero porque es el que reconoce quien
            # está mirando la lista.
            "model": self.model or self.platform,
            "essid": self.essid,
        }


def parse(payload: bytes, source_ip: str | None = None) -> UbntDevice | None:
    """Interpreta una respuesta del protocolo. Devuelve None si no lo parece.

    Tolera tramas truncadas y tipos desconocidos a propósito: se descubre contra
    equipos ajenos, de firmwares variados, y una trama a medias no puede tumbar
    el barrido entero.
    """
    # 1 de versión + 1 de comando + 2 de longitud.
    if len(payload) < 4:
        return None

    version = payload[0]

    # Solo se conocen las versiones 1 y 2 de la cabecera. Otra cosa en el 10001
    # no es un equipo Ubiquiti.
    if version not in (0x01, 0x02):
        return None

    device = UbntDevice(ip_address=source_ip)
    offset = 4

    while offset + 3 <= len(payload):
        tlv_type = payload[offset]
        (length,) = struct.unpack_from(">H", payload, offset + 1)
        offset += 3

        # Longitud que se sale del paquete: trama corrupta o cortada. Se
        # devuelve lo leído hasta aquí en vez de descartarlo todo.
        if offset + length > len(payload):
            break

        value = payload[offset : offset + length]
        offset += length

        if tlv_type == TLV_MAC and length == 6:
            device.mac_address = _mac(value)
        elif tlv_type == TLV_MAC_AND_IP and length == 10:
            # Seis bytes de MAC y cuatro de IP. Esta IP es la que el equipo cree
            # tener, que puede diferir de la de origen del paquete si hay NAT por
            # medio; se prefiere la del propio equipo.
            device.mac_address = _mac(value[:6])
            device.ip_address = ".".join(str(b) for b in value[6:10])
        elif tlv_type == TLV_FIRMWARE:
            device.firmware = _text(value)
        elif tlv_type == TLV_HOSTNAME:
            device.hostname = _text(value)
        elif tlv_type == TLV_PLATFORM:
            device.platform = _text(value)
        elif tlv_type == TLV_MODEL:
            device.model = _text(value)
        elif tlv_type == TLV_ESSID:
            device.essid = _text(value)
        elif tlv_type == TLV_UPTIME and length == 4:
            (device.uptime_seconds,) = struct.unpack(">I", value)

    # Sin MAC ni nombre no hay nada que ofrecerle al operador.
    if device.mac_address is None and device.hostname is None:
        return None

    return device


def sweep(cidr: str, timeout: float = 2.0, max_hosts: int = 1024) -> list[UbntDevice]:
    """Barre un rango en unicast y devuelve lo que responda.

    El rango DEBE haber sido validado antes contra la lista blanca local del
    agente. `max_hosts` es un tope de seguridad para que un `/8` mal escrito no
    convierta al agente en un escáner de horas.
    """
    red = ipaddress.ip_network(cidr, strict=False)
    hosts = list(red.hosts()) if red.num_addresses > 2 else [red.network_address]

    if len(hosts) > max_hosts:
        raise ValueError(
            f"El rango {cidr} tiene {len(hosts)} direcciones y el tope es {max_hosts}."
        )

    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setblocking(False)

    encontrados: dict[str, UbntDevice] = {}

    try:
        # Se envían todas las sondas primero y se escucha después. Enviar y
        # esperar dirección por dirección multiplicaría el timeout por el número
        # de direcciones: un /24 tardaría ocho minutos en vez de dos segundos.
        for host in hosts:
            try:
                sock.sendto(PROBE, (str(host), UBNT_PORT))
            except OSError as exc:
                log.debug("No se pudo sondear %s: %s", host, exc)

        limite = time.monotonic() + timeout

        while time.monotonic() < limite:
            try:
                payload, (origen, _puerto) = sock.recvfrom(4096)
            except BlockingIOError:
                time.sleep(0.02)
                continue
            except OSError as exc:
                log.debug("Error recibiendo respuesta: %s", exc)
                continue

            device = parse(payload, source_ip=origen)

            if device is not None:
                # Se indexa por la IP de origen: un mismo equipo puede responder
                # más de una vez y no debe aparecer duplicado.
                encontrados[origen] = device
    finally:
        sock.close()

    return list(encontrados.values())


def cidr_is_allowed(cidr: str, allowed: list[str]) -> bool:
    """¿Está este rango dentro de lo que el agente tiene permitido barrer?

    Con la lista vacía NO se permite nada. Es lo contrario del criterio de las
    MAC admitidas —donde vacío significa «cualquiera», útil en un banco de
    pruebas— porque aquí el riesgo es distinto: una lista sin rellenar por
    descuido no puede dejar al agente barriendo lo que le manden.
    """
    if not allowed:
        return False

    try:
        objetivo = ipaddress.ip_network(cidr, strict=False)
    except ValueError:
        return False

    for permitido in allowed:
        try:
            if objetivo.subnet_of(ipaddress.ip_network(permitido, strict=False)):
                return True
        except (ValueError, TypeError):
            continue

    return False


def _mac(value: bytes) -> str:
    return ":".join(f"{byte:02X}" for byte in value)


def _text(value: bytes) -> str | None:
    text = value.decode("utf-8", errors="replace").strip("\x00").strip()
    return text or None
