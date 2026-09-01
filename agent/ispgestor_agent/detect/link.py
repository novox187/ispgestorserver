"""Detección por estado del cable y sonda directa.

MNDP es la señal más rica pero RouterOS solo anuncia cada 60 segundos, y un
minuto de espera con el equipo delante se nota. Esta pieza cubre ese hueco:
vigila el carrier de la NIC autorizada y, en cuanto sube, sondea el segmento
en busca de un RouterOS.

Es además la red de seguridad para los equipos que traen el descubrimiento
deshabilitado, en los que MNDP no llegaría nunca.
"""

from __future__ import annotations

import socket
import subprocess
from dataclasses import dataclass
from pathlib import Path

SYS_CLASS_NET = Path("/sys/class/net")

# Puertos con los que se reconoce un RouterOS al otro lado del cable.
ROUTEROS_PORTS = (8728, 8729, 22, 80)


@dataclass
class LinkState:
    interface: str
    carrier: bool
    operstate: str


def read_link(interface: str) -> LinkState | None:
    """Estado del enlace de una NIC. None si la interfaz no existe."""
    base = SYS_CLASS_NET / interface

    if not base.exists():
        return None

    try:
        # `carrier` da EINVAL cuando la interfaz está administrativamente caída;
        # se trata como "sin cable" en vez de propagar el error.
        carrier_raw = (base / "carrier").read_text(encoding="utf-8").strip()
        carrier = carrier_raw == "1"
    except OSError:
        carrier = False

    try:
        operstate = (base / "operstate").read_text(encoding="utf-8").strip()
    except OSError:
        operstate = "unknown"

    return LinkState(interface=interface, carrier=carrier, operstate=operstate)


class CarrierWatcher:
    """Detecta la transición «sin cable» → «con cable» en las NIC vigiladas.

    Se queda solo con el flanco de subida: mientras el cable siga puesto no
    vuelve a avisar, que es lo que evita reabrir un alta cada segundo.
    """

    def __init__(self, interfaces: list[str]):
        self.interfaces = interfaces
        self._previous: dict[str, bool] = {}

    def poll(self) -> list[str]:
        """Interfaces en las que acaba de conectarse un cable."""
        risen = []

        for interface in self.interfaces:
            state = read_link(interface)
            if state is None:
                continue

            was_up = self._previous.get(interface, False)
            if state.carrier and not was_up:
                risen.append(interface)

            self._previous[interface] = state.carrier

        return risen

    def prime(self) -> None:
        """Toma el estado actual como referencia sin emitir transiciones.

        Se llama al arrancar: un cable que ya estaba puesto antes de iniciar el
        agente no es una conexión nueva y no debe disparar un alta.
        """
        for interface in self.interfaces:
            state = read_link(interface)
            self._previous[interface] = bool(state and state.carrier)


def probe(address: str, timeout: float = 1.0) -> dict | None:
    """Comprueba si en una dirección responde algo que parece un RouterOS.

    Devuelve el hallazgo o None. No identifica el equipo —eso es cosa del paso
    de identificación, que sí entra con credenciales—; aquí solo se decide si
    merece la pena abrir una sesión.
    """
    open_ports = [port for port in ROUTEROS_PORTS if _port_is_open(address, port, timeout)]

    if not open_ports:
        return None

    return {
        "detection_method": "link_probe",
        "lan_ip": address,
        "mac_address": arp_lookup(address),
        "open_ports": open_ports,
    }


def _port_is_open(address: str, port: int, timeout: float) -> bool:
    try:
        with socket.create_connection((address, port), timeout=timeout):
            return True
    except OSError:
        return False


def arp_lookup(address: str) -> str | None:
    """MAC asociada a una IP según la tabla de vecinos del núcleo.

    Se usa `ip neigh` en vez de leer /proc/net/arp porque este último solo
    refleja IPv4 y se queda obsoleto antes.
    """
    try:
        result = subprocess.run(
            ["ip", "neigh", "show", address],
            capture_output=True,
            text=True,
            timeout=5,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    for token, following in zip(result.stdout.split(), result.stdout.split()[1:]):
        if token == "lladdr":
            return following.upper()

    return None


def warm_arp(address: str, timeout: float = 1.0) -> None:
    """Fuerza una entrada ARP tocando el puerto.

    Sin esto, `ip neigh` puede no tener aún la MAC del equipo recién conectado
    y la detección llegaría sin el dato que sirve para deduplicar.
    """
    _port_is_open(address, ROUTEROS_PORTS[0], timeout)
