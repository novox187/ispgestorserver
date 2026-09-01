"""Escucha del MikroTik Neighbor Discovery Protocol (MNDP).

Es la señal de detección más rica que existe para este caso: todo RouterOS
emite por difusión en el puerto UDP 5678 un paquete que lleva su MAC, su
identidad, la versión, el modelo y el nombre de la interfaz — y lo hace sin
que el equipo tenga que tener una IP alcanzable ni credenciales conocidas.

Formato del paquete: dos bytes de cabecera, dos de número de secuencia y a
continuación una sucesión de TLV (tipo y longitud en 16 bits big-endian,
seguidos del valor).

La pega es la cadencia: RouterOS anuncia cada 60 segundos por defecto, así que
por sí sola esta señal puede tardar un minuto en reaccionar. Por eso el agente
la combina con la vigilancia del carrier de la NIC, que reacciona en el acto.
"""

from __future__ import annotations

import socket
import struct
from dataclasses import dataclass

MNDP_PORT = 5678

# Tipos TLV documentados por MikroTik. Los que no aparecen aquí se ignoran en
# silencio: el protocolo crece entre versiones y un tipo desconocido no es un
# error.
TLV_MAC_ADDRESS = 1
TLV_IDENTITY = 5
TLV_VERSION = 7
TLV_PLATFORM = 8
TLV_UPTIME = 10
TLV_SOFTWARE_ID = 11
TLV_BOARD = 12
TLV_UNPACK = 14
TLV_IPV6_ADDRESS = 15
TLV_INTERFACE_NAME = 16
TLV_IPV4_ADDRESS = 17


@dataclass
class Neighbor:
    """Un equipo anunciándose en el segmento."""

    mac_address: str | None = None
    identity: str | None = None
    version: str | None = None
    platform: str | None = None
    board: str | None = None
    software_id: str | None = None
    interface_name: str | None = None
    ipv4_address: str | None = None
    source_ip: str | None = None

    def is_mikrotik(self) -> bool:
        """El anuncio dice ser de un equipo MikroTik.

        MNDP es propietario, así que en la práctica solo lo emiten ellos; aun
        así se comprueba antes de dar por bueno el hallazgo.
        """
        return (self.platform or "").lower().startswith("mikrotik") or self.board is not None

    def to_detection(self, link_interface: str | None = None) -> dict:
        return {
            "detection_method": "mndp",
            "mac_address": self.mac_address,
            "identity": self.identity,
            "board_name": self.board,
            "routeros_version": self.version,
            "link_interface": link_interface or self.interface_name,
            "lan_ip": self.ipv4_address or self.source_ip,
        }


def parse(payload: bytes) -> Neighbor | None:
    """Interpreta un paquete MNDP. Devuelve None si no lo parece."""
    # 2 de cabecera + 2 de secuencia; por debajo de eso no hay nada que leer.
    if len(payload) < 4:
        return None

    neighbor = Neighbor()
    offset = 4

    while offset + 4 <= len(payload):
        tlv_type, length = struct.unpack_from(">HH", payload, offset)
        offset += 4

        # Longitud que se sale del paquete: trama corrupta o truncada. Se
        # devuelve lo leído hasta aquí en vez de descartarlo todo.
        if offset + length > len(payload):
            break

        value = payload[offset : offset + length]
        offset += length

        if tlv_type == TLV_MAC_ADDRESS and length == 6:
            neighbor.mac_address = ":".join(f"{byte:02X}" for byte in value)
        elif tlv_type == TLV_IDENTITY:
            neighbor.identity = _text(value)
        elif tlv_type == TLV_VERSION:
            neighbor.version = _text(value)
        elif tlv_type == TLV_PLATFORM:
            neighbor.platform = _text(value)
        elif tlv_type == TLV_BOARD:
            neighbor.board = _text(value)
        elif tlv_type == TLV_SOFTWARE_ID:
            neighbor.software_id = _text(value)
        elif tlv_type == TLV_INTERFACE_NAME:
            neighbor.interface_name = _text(value)
        elif tlv_type == TLV_IPV4_ADDRESS and length == 4:
            neighbor.ipv4_address = ".".join(str(byte) for byte in value)

    if neighbor.mac_address is None and neighbor.identity is None:
        return None

    return neighbor


def _text(value: bytes) -> str | None:
    text = value.decode("utf-8", errors="replace").strip("\x00").strip()
    return text or None


class MndpListener:
    """Socket UDP en escucha de anuncios MNDP.

    No filtra por interfaz porque el socket es de difusión y no siempre se
    puede saber por dónde entró un paquete; ese filtrado lo hace el rol
    `provisioner` cruzando la IP de origen con las NIC autorizadas.
    """

    def __init__(self, timeout: float = 1.0):
        self.timeout = timeout
        self._socket: socket.socket | None = None

    def open(self) -> None:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        sock.settimeout(self.timeout)
        sock.bind(("", MNDP_PORT))
        self._socket = sock

    def close(self) -> None:
        if self._socket is not None:
            self._socket.close()
            self._socket = None

    def poll(self) -> Neighbor | None:
        """Lee un anuncio si lo hay. None cuando expira el tiempo de espera."""
        if self._socket is None:
            raise RuntimeError("El listener MNDP no está abierto.")

        try:
            payload, address = self._socket.recvfrom(4096)
        except socket.timeout:
            return None

        neighbor = parse(payload)
        if neighbor is not None:
            neighbor.source_ip = address[0]

        return neighbor

    def __enter__(self) -> "MndpListener":
        self.open()
        return self

    def __exit__(self, *_exc) -> None:
        self.close()
