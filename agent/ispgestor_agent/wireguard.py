"""Operaciones WireGuard en el sistema operativo del hosting.

Mismo criterio que en el lado del router: lista blanca de operaciones tipadas,
nunca comandos crudos. Este agente corre con privilegios sobre la interfaz del
túnel, así que la superficie de lo que puede hacer tiene que estar acotada de
forma explícita.

La clave privada del servidor no se lee, ni se escribe, ni se transmite: solo
se manipulan peers, identificados por su clave pública.
"""

from __future__ import annotations

import re
import subprocess
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

# Marcador con el que se delimitan los peers gestionados por ISP Gestor dentro
# del fichero de configuración. Permite añadirlos y quitarlos sin tocar lo que
# el administrador tenga puesto a mano.
BLOCK_BEGIN = "# >>> ispgestor:{label} >>>"
BLOCK_END = "# <<< ispgestor:{label} <<<"


class WireGuardError(RuntimeError):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"[{code}] {message}")


class UnknownOperation(WireGuardError):
    def __init__(self, operation: str):
        super().__init__(
            "OPERATION_NOT_ALLOWED",
            f"La operación '{operation}' no está en la lista blanca del agente.",
        )


@dataclass
class OperationOutcome:
    values: dict[str, Any] = field(default_factory=dict)
    logs: list[str] = field(default_factory=list)

    def log(self, message: str) -> None:
        self.logs.append(message)


class WireGuardHost:
    def __init__(self, interface: str, config_path: str):
        self.interface = interface
        self.config_path = Path(config_path)

    # ── Ejecución de operaciones ────────────────────────────────────────────

    def run(self, operations: list[dict]) -> OperationOutcome:
        outcome = OperationOutcome()

        for operation in operations:
            name = operation.get("op")
            handler = HANDLERS.get(name)

            if handler is None:
                raise UnknownOperation(str(name))

            handler(self, operation, outcome)

        return outcome

    # ── Utilidades ──────────────────────────────────────────────────────────

    def _wg(self, *args: str) -> str:
        return self._run(["wg", *args])

    @staticmethod
    def _run(command: list[str], timeout: float = 20.0) -> str:
        try:
            result = subprocess.run(
                command, capture_output=True, text=True, timeout=timeout, check=False
            )
        except FileNotFoundError as exc:
            raise WireGuardError(
                "WG_NOT_INSTALLED",
                f"No se encontró '{command[0]}'. Instala wireguard-tools en el hosting.",
            ) from exc
        except subprocess.SubprocessError as exc:
            raise WireGuardError("WG_COMMAND_FAILED", f"Fallo ejecutando {command[0]}: {exc}") from exc

        if result.returncode != 0:
            raise WireGuardError(
                "WG_COMMAND_FAILED",
                f"'{' '.join(command)}' devolvió {result.returncode}: {result.stderr.strip()}",
            )

        return result.stdout

    def server_public_key(self) -> str | None:
        try:
            return self._wg("show", self.interface, "public-key").strip() or None
        except WireGuardError:
            return None

    def interface_exists(self) -> bool:
        try:
            self._wg("show", self.interface)
            return True
        except WireGuardError:
            return False

    def peers(self) -> dict[str, dict]:
        """Peers actuales indexados por clave pública."""
        output = self._wg("show", self.interface, "dump")
        peers: dict[str, dict] = {}

        # La primera línea del dump describe la interfaz; el resto, un peer cada.
        for line in output.strip().splitlines()[1:]:
            fields = line.split("\t")
            if len(fields) < 5:
                continue

            peers[fields[0]] = {
                "public_key": fields[0],
                "endpoint": fields[2],
                "allowed_ips": fields[3],
                "latest_handshake": int(fields[4]) if fields[4].isdigit() else 0,
            }

        return peers


# ── Manejadores de la lista blanca ──────────────────────────────────────────


def _set_peer(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    interface = op.get("interface") or host.interface

    # `wg set` es idempotente: reconfigura el peer si ya existe.
    host._wg(
        "set",
        interface,
        "peer",
        op["public_key"],
        "allowed-ips",
        op["allowed_ips"],
        "persistent-keepalive",
        str(op.get("keepalive", 25)),
    )

    outcome.log(f"Peer registrado en {interface} con allowed-ips {op['allowed_ips']}.")


def _persist_peer(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    """Escribe el peer en el fichero de configuración.

    Sin esto el peer desaparecería al reiniciar el host y el router quedaría
    incomunicado sin que nada lo delatase hasta el siguiente chequeo de salud.
    """
    label = op["label"]
    block = "\n".join(
        [
            BLOCK_BEGIN.format(label=label),
            "[Peer]",
            f"PublicKey = {op['public_key']}",
            f"AllowedIPs = {op['allowed_ips']}",
            f"PersistentKeepalive = {op.get('keepalive', 25)}",
            BLOCK_END.format(label=label),
            "",
        ]
    )

    if not host.config_path.exists():
        raise WireGuardError(
            "WG_CONFIG_MISSING",
            f"No existe {host.config_path}. El túnel del hosting debe estar creado antes "
            f"de que el agente pueda añadirle peers.",
        )

    content = host.config_path.read_text(encoding="utf-8")
    content = _strip_block(content, label)

    if not content.endswith("\n"):
        content += "\n"

    host.config_path.write_text(content + block, encoding="utf-8")
    outcome.log(f"Peer persistido en {host.config_path} bajo la etiqueta {label}.")


def _remove_peer(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    interface = op.get("interface") or host.interface

    if op["public_key"] not in host.peers():
        outcome.log("El peer ya no estaba en la interfaz.")
        return

    host._wg("set", interface, "peer", op["public_key"], "remove")
    outcome.log(f"Peer retirado de {interface}.")


def _unpersist_peer(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    if not host.config_path.exists():
        outcome.log("No hay fichero de configuración del que retirar el peer.")
        return

    label = op["label"]
    content = host.config_path.read_text(encoding="utf-8")
    stripped = _strip_block(content, label)

    if stripped == content:
        outcome.log(f"No había ningún bloque etiquetado {label}.")
        return

    host.config_path.write_text(stripped, encoding="utf-8")
    outcome.log(f"Bloque {label} retirado de {host.config_path}.")


def _check_handshake(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    max_age = int(op.get("max_age_seconds", 180))
    deadline = time.monotonic() + min(max_age, 90)
    public_key = op["public_key"]

    while True:
        peer = host.peers().get(public_key)

        if peer and peer["latest_handshake"]:
            age = int(time.time()) - peer["latest_handshake"]
            if age <= max_age:
                outcome.values["handshake_age_seconds"] = age
                outcome.log(f"Handshake con el equipo hace {age} s.")
                return

        if time.monotonic() >= deadline:
            raise WireGuardError(
                "NO_HANDSHAKE",
                f"El hosting no ha visto handshake del equipo en {int(min(max_age, 90))} s. "
                f"Comprueba que el puerto UDP del servidor es alcanzable desde la red "
                f"donde está el router.",
            )

        time.sleep(3)


def _ping(host: WireGuardHost, op: dict, outcome: OperationOutcome) -> None:
    address = op["address"]
    count = int(op.get("count", 4))

    try:
        output = WireGuardHost._run(
            ["ping", "-c", str(count), "-W", "2", address], timeout=count * 3 + 5
        )
    except WireGuardError as exc:
        raise WireGuardError(
            "PING_FAILED",
            f"El hosting no alcanza {address} por el túnel pese a tener handshake. "
            f"Revisa allowed-ips y el enrutado. Detalle: {exc.message}",
        ) from exc

    match = re.search(r"(\d+) received", output)
    received = int(match.group(1)) if match else 0

    outcome.values.setdefault("ping", {})[address] = {"sent": count, "received": received}
    outcome.log(f"Ping a {address}: {received}/{count} respuestas.")


def _strip_block(content: str, label: str) -> str:
    """Elimina el bloque marcado, dejando intacto el resto del fichero."""
    begin = BLOCK_BEGIN.format(label=label)
    end = BLOCK_END.format(label=label)

    pattern = re.compile(
        rf"{re.escape(begin)}.*?{re.escape(end)}\n?",
        re.DOTALL,
    )

    return pattern.sub("", content)


HANDLERS: dict[str, Callable[[WireGuardHost, dict, OperationOutcome], None]] = {
    "wg.set_peer": _set_peer,
    "wg.persist_peer": _persist_peer,
    "wg.remove_peer": _remove_peer,
    "wg.unpersist_peer": _unpersist_peer,
    "wg.check_handshake": _check_handshake,
    "ping": _ping,
}
