"""Detección por estado del cable y sonda directa.

MNDP es la señal más rica pero RouterOS solo anuncia cada 60 segundos, y un
minuto de espera con el equipo delante se nota. Esta pieza cubre ese hueco:
vigila el carrier de la NIC autorizada y, en cuanto sube, sondea el segmento
en busca de un RouterOS.

Es además la red de seguridad para los equipos que traen el descubrimiento
deshabilitado, en los que MNDP no llegaría nunca.
"""

from __future__ import annotations

import re
import socket
import subprocess
import sys
from dataclasses import dataclass
from pathlib import Path

SYS_CLASS_NET = Path("/sys/class/net")

IS_WINDOWS = sys.platform == "win32"
IS_MACOS = sys.platform == "darwin"

# Puertos con los que se reconoce un RouterOS al otro lado del cable.
ROUTEROS_PORTS = (8728, 8729, 22, 80)

#: MAC en cualquiera de las dos notaciones: `aa:bb:cc:dd:ee:ff` en Unix y
#: `aa-bb-cc-dd-ee-ff` en Windows.
_MAC = re.compile(r"\b([0-9a-fA-F]{2}([:-])(?:[0-9a-fA-F]{2}\2){4}[0-9a-fA-F]{2})\b")


@dataclass
class LinkState:
    interface: str
    carrier: bool
    operstate: str


def read_link(interface: str) -> LinkState | None:
    """Estado del enlace de una NIC. None si la interfaz no existe.

    Cada sistema expone esto de una forma distinta y ninguna se parece:

    - **Linux** lo publica como ficheros en `/sys/class/net`. Es lo más barato
      que hay: dos lecturas sin lanzar procesos.
    - **macOS** no tiene `/sys`; se pregunta a `ifconfig`.
    - **Windows** tampoco, y ahí se usa PowerShell leyendo los campos
      NUMÉRICOS del adaptador. Los textuales están traducidos y compararlos
      contra «Up» fallaría en un Windows en español, que es exactamente el que
      va a tener el cliente.
    """
    if IS_WINDOWS:
        return _read_link_windows(interface)

    if IS_MACOS:
        return _read_link_macos(interface)

    return _read_link_linux(interface)


def _read_link_linux(interface: str) -> LinkState | None:
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


def _read_link_macos(interface: str) -> LinkState | None:
    """Lee el estado con `ifconfig`.

    La línea que interesa es `status: active` / `status: inactive`. Esa palabra
    la imprime el propio `ifconfig` y no está traducida, así que se puede
    comparar con seguridad.
    """
    salida = _ejecutar(["ifconfig", interface])

    if salida is None:
        return None

    # `ifconfig` de una interfaz inexistente devuelve error y salida vacía.
    if not salida.strip():
        return None

    estado = "unknown"

    for linea in salida.splitlines():
        if "status:" in linea:
            estado = linea.split("status:", 1)[1].strip()
            break

    return LinkState(interface=interface, carrier=estado == "active", operstate=estado)


def _read_link_windows(interface: str) -> LinkState | None:
    """Lee el estado del adaptador con PowerShell, por valores numéricos.

    `MediaConnectionState` vale 1 cuando hay cable e `ifOperStatus` vale 1
    cuando la interfaz está operativa. Se leen los enteros del enum (`.value__`)
    y no su representación en texto, que viene traducida por el sistema.
    """
    guion = (
        f"$a = Get-NetAdapter -Name '{_escapar_ps(interface)}' -ErrorAction SilentlyContinue; "
        "if ($null -eq $a) { 'NOEXISTE' } "
        "else { \"$($a.MediaConnectionState.value__);$($a.ifOperStatus.value__)\" }"
    )

    salida = _ejecutar(["powershell", "-NoProfile", "-NonInteractive", "-Command", guion])

    if salida is None:
        return None

    salida = salida.strip()

    if salida == "NOEXISTE" or not salida:
        return None

    partes = salida.split(";")

    try:
        conexion = int(partes[0])
        operativa = int(partes[1]) if len(partes) > 1 else 0
    except (ValueError, IndexError):
        return None

    return LinkState(
        interface=interface,
        # 1 = Connected. Cualquier otro valor (2 desconectado, 0 desconocido) no
        # es un cable puesto.
        carrier=conexion == 1,
        operstate="up" if operativa == 1 else "down",
    )


def _escapar_ps(valor: str) -> str:
    """Escapa una cadena para meterla entre comillas simples en PowerShell."""
    return valor.replace("'", "''")


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
    """MAC asociada a una IP según la tabla de vecinos del sistema.

    Tres órdenes distintas para lo mismo: `ip neigh` en Linux, `arp -n` en macOS
    y `arp -a` en Windows. La de Windows además imprime las cabeceras y el tipo
    de entrada traducidos, así que no se puede parsear por posición ni buscando
    palabras: se extrae la MAC por su forma, que es igual en cualquier idioma.
    """
    if IS_WINDOWS:
        orden = ["arp", "-a", address]
    elif IS_MACOS:
        orden = ["arp", "-n", address]
    else:
        # `ip neigh` en vez de /proc/net/arp: este último solo refleja IPv4 y se
        # queda obsoleto antes.
        orden = ["ip", "neigh", "show", address]

    salida = _ejecutar(orden)

    if salida is None:
        return None

    return _mac_de_la_linea_de(address, salida)


def _mac_de_la_linea_de(address: str, salida: str) -> str | None:
    """Extrae la MAC de la línea que menciona esa dirección.

    Se exige que la dirección aparezca en la misma línea porque `arp -a` de
    Windows ignora el filtro cuando la entrada no está en caché y devuelve la
    tabla entera: sin esta comprobación se devolvería la MAC de otro equipo,
    que es peor que no devolver ninguna —el alta se deduplicaría contra el
    equipo equivocado—.

    Y la dirección se busca delimitada, no como subcadena: `10.9.0.5` aparece
    dentro de `10.9.0.55`, y emparejar así devolvía la MAC del vecino de al
    lado. Lo encontró la prueba, no la revisión.
    """
    exacta = re.compile(rf"(?<![\d.]){re.escape(address)}(?![\d.])")

    for linea in salida.splitlines():
        if not exacta.search(linea):
            continue

        encontrada = _MAC.search(linea)

        if encontrada:
            return encontrada.group(1).replace("-", ":").upper()

    return None


def _ejecutar(orden: list[str]) -> str | None:
    """Lanza una orden del sistema y devuelve su salida, o None si no se pudo.

    Devuelve None y no lanza porque estas consultas corren en el bucle del
    agente: que falte una herramienta o que la orden tarde no puede tumbar la
    detección, solo dejarla sin ese dato en esa vuelta.
    """
    try:
        return subprocess.run(
            orden,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        ).stdout
    except (OSError, subprocess.SubprocessError):
        return None


def warm_arp(address: str, timeout: float = 1.0) -> None:
    """Fuerza una entrada ARP tocando el puerto.

    Sin esto, `ip neigh` puede no tener aún la MAC del equipo recién conectado
    y la detección llegaría sin el dato que sirve para deduplicar.
    """
    _port_is_open(address, ROUTEROS_PORTS[0], timeout)
