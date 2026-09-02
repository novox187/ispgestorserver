"""Operaciones sobre un RouterOS, acotadas por lista blanca.

Aquí está el límite de confianza del agente. El servidor nunca manda comandos
crudos: manda operaciones tipadas (`wireguard.create_interface`,
`ip.add_address`, ...) y este módulo es quien decide qué significa cada una y
qué se ejecuta de verdad en el equipo. Una operación que no esté en `HANDLERS`
se rechaza, de modo que ni siquiera un servidor comprometido puede hacer que un
agente ejecute algo arbitrario en la red del cliente.

Todas las operaciones de reversión son idempotentes: el rollback puede caer
sobre un equipo en el que solo se aplicó parte de la secuencia, así que borrar
algo que no existe no puede ser un error.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from typing import Any, Callable

try:
    from librouteros import connect as _ros_connect
    from librouteros.exceptions import TrapError, LibRouterosError
except ImportError as exc:  # pragma: no cover - depende del entorno de instalación
    raise SystemExit(
        "Falta la dependencia 'librouteros'. Instálala con:\n"
        "  pip install -r requirements.txt"
    ) from exc


class RouterOsError(RuntimeError):
    """Fallo al hablar con el equipo."""

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"[{code}] {message}")


class UnknownOperation(RouterOsError):
    def __init__(self, operation: str):
        super().__init__(
            "OPERATION_NOT_ALLOWED",
            f"La operación '{operation}' no está en la lista blanca del agente.",
        )


@dataclass
class OperationOutcome:
    """Resultado acumulado de ejecutar una secuencia de operaciones."""

    values: dict[str, Any] = field(default_factory=dict)
    logs: list[str] = field(default_factory=list)

    def log(self, message: str) -> None:
        self.logs.append(message)


class RouterOsSession:
    """Sesión con la API binaria de RouterOS."""

    def __init__(self, host: str, port: int, username: str, password: str, timeout: float = 15.0):
        self.host = host
        self.port = port
        self.username = username
        self.password = password
        self.timeout = timeout
        self._api = None

    def __enter__(self) -> "RouterOsSession":
        self.connect()
        return self

    def __exit__(self, *_exc) -> None:
        self.close()

    def connect(self) -> None:
        try:
            self._api = _ros_connect(
                host=self.host,
                port=self.port,
                username=self.username,
                password=self.password,
                timeout=self.timeout,
            )
        except TrapError as exc:
            raise RouterOsError("ROUTER_AUTH_FAILED", f"Credenciales rechazadas: {exc}") from exc
        except (LibRouterosError, OSError) as exc:
            raise RouterOsError(
                "ROUTER_UNREACHABLE",
                f"No se pudo conectar a {self.host}:{self.port}: {exc}",
            ) from exc

    def close(self) -> None:
        if self._api is not None:
            try:
                self._api.close()
            except Exception:
                pass
            self._api = None

    # ── Acceso de bajo nivel ────────────────────────────────────────────────

    def path(self, path: str):
        if self._api is None:
            raise RouterOsError("ROUTER_NOT_CONNECTED", "La sesión no está abierta.")
        return self._api.path(path)

    def query(self, command: str, **kwargs) -> list[dict]:
        if self._api is None:
            raise RouterOsError("ROUTER_NOT_CONNECTED", "La sesión no está abierta.")
        return list(self._api(command, **kwargs))

    def rows(self, path: str, **filters) -> list[dict]:
        entries = list(self.path(path))

        if not filters:
            return entries

        return [
            row
            for row in entries
            if all(str(row.get(key)) == str(value) for key, value in filters.items())
        ]

    def resource(self) -> dict:
        """`/system/resource` en crudo: uptime, CPU, memoria, modelo y versión.

        Extraído a método propio porque lo usan dos caminos con propósitos
        distintos: `identify()` durante el alta, y el rol de monitoreo en cada
        vuelta. Tenerlo en un solo sitio evita que se separen.
        """
        return (self.rows("/system/resource") or [{}])[0]

    # ── Identificación ──────────────────────────────────────────────────────

    def identify(self) -> dict:
        """Lee la identidad del equipo y qué es capaz de hacer.

        `wireguard_available` se resuelve preguntándole al propio equipo en vez
        de deducirlo del modelo: los SMIPS de poca memoria corren RouterOS 7
        con un juego de paquetes recortado en el que WireGuard puede faltar, y
        mantener una lista de modelos envejecería mal.
        """
        resource = self.resource()
        identity = (self.rows("/system/identity") or [{}])[0]

        try:
            routerboard = (self.rows("/system/routerboard") or [{}])[0]
        except RouterOsError:
            routerboard = {}

        return {
            "identity": identity.get("name"),
            "board_name": resource.get("board-name") or routerboard.get("model"),
            "routeros_version": resource.get("version"),
            "serial_number": routerboard.get("serial-number"),
            "architecture": resource.get("architecture-name"),
            "wireguard_available": self.supports_wireguard(),
        }

    def supports_wireguard(self) -> bool:
        try:
            list(self.path("/interface/wireguard"))
            return True
        except (TrapError, LibRouterosError, RouterOsError):
            return False

    def management_mac(self) -> str | None:
        """MAC de la primera interfaz Ethernet, usada para deduplicar equipos."""
        try:
            for row in self.rows("/interface/ethernet"):
                mac = row.get("mac-address")
                if mac:
                    return str(mac).upper()
        except RouterOsError:
            pass

        return None

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


# ── Manejadores de la lista blanca ──────────────────────────────────────────
#
# Cada uno implementa UNA operación. Añadir una capacidad nueva al agente exige
# escribir aquí su manejador, que es exactamente la fricción que se busca: la
# superficie de lo que un agente puede hacer en la red no crece por accidente.


def _create_wireguard_interface(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    name = op["name"]
    existing = session.rows("/interface/wireguard", name=name)

    if existing:
        # Reejecución de un paso que ya se aplicó: se reutiliza la interfaz en
        # vez de fallar, y se devuelve su clave pública igualmente.
        outcome.log(f"La interfaz {name} ya existía; se reutiliza.")
        outcome.values["router_public_key"] = existing[0].get("public-key")
        return

    session.path("/interface/wireguard").add(
        **{
            "name": name,
            "listen-port": op.get("listen_port", 13231),
            "mtu": op.get("mtu", 1420),
            "comment": op.get("comment", ""),
        }
    )

    created = session.rows("/interface/wireguard", name=name)
    if not created:
        raise RouterOsError("WG_INTERFACE_NOT_CREATED", f"No se pudo crear la interfaz {name}.")

    public_key = created[0].get("public-key")
    if not public_key:
        raise RouterOsError(
            "WG_PUBLIC_KEY_MISSING",
            f"La interfaz {name} se creó pero RouterOS no devolvió su clave pública.",
        )

    # RouterOS genera la privada y no sale nunca del equipo: solo se lee esta.
    outcome.values["router_public_key"] = public_key
    outcome.log(f"Interfaz {name} creada; clave pública obtenida.")


def _add_wireguard_peer(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    interface = op["interface"]
    public_key = op["public_key"]

    if session.rows("/interface/wireguard/peers", interface=interface, **{"public-key": public_key}):
        outcome.log("El peer del servidor ya estaba configurado.")
        return

    session.path("/interface/wireguard/peers").add(
        **{
            "interface": interface,
            "public-key": public_key,
            "endpoint-address": op["endpoint_address"],
            "endpoint-port": op["endpoint_port"],
            "allowed-address": op["allowed_address"],
            "persistent-keepalive": f"{op.get('keepalive', 25)}s",
            "comment": op.get("comment", ""),
        }
    )
    outcome.log(f"Peer del servidor añadido a {interface}.")


def _add_ip_address(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    interface = op["interface"]
    address = op["address"]

    if session.rows("/ip/address", interface=interface, address=address):
        outcome.log(f"La dirección {address} ya estaba asignada a {interface}.")
        return

    session.path("/ip/address").add(
        **{"address": address, "interface": interface, "comment": op.get("comment", "")}
    )
    outcome.log(f"Dirección {address} asignada a {interface}.")


def _allow_input(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    interface = op["interface"]
    ports = ",".join(str(port) for port in op.get("ports", []))
    comment = op.get("comment", "ISP Gestor")

    if session.rows("/ip/firewall/filter", comment=comment):
        outcome.log("La regla de firewall ya existía.")
        return

    # `place-before=0` la coloca al principio de la cadena: una regla de
    # aceptación colocada después de un drop genérico no serviría de nada.
    session.path("/ip/firewall/filter").add(
        **{
            "chain": "input",
            "action": "accept",
            "protocol": op.get("protocol", "tcp"),
            "in-interface": interface,
            "dst-port": ports,
            "comment": comment,
            "place-before": "0",
        }
    )
    outcome.log(f"Regla de entrada creada para {interface} en los puertos {ports}.")


def _enable_api(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    _set_api_service(session, outcome, disabled="no", port=op.get("port"), allowed=None)


def _restrict_api(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    _set_api_service(
        session, outcome, disabled="no", port=op.get("port"), allowed=op["allowed_address"]
    )
    outcome.log(f"API restringida a {op['allowed_address']}.")


def _unrestrict_api(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    # Se reabre antes de revertir el resto: si el endurecimiento llegó a
    # aplicarse, sin esto el agente perdería el acceso por la LAN a mitad de la
    # reversión y dejaría residuo en el equipo.
    _set_api_service(session, outcome, disabled="no", port=op.get("port"), allowed="")
    outcome.log("Restricción de origen de la API retirada.")


def _set_api_service(
    session: RouterOsSession,
    outcome: OperationOutcome,
    disabled: str,
    port: int | None,
    allowed: str | None,
) -> None:
    services = session.rows("/ip/service", name="api")

    if not services:
        raise RouterOsError("API_SERVICE_MISSING", "El equipo no expone el servicio 'api'.")

    changes: dict[str, Any] = {".id": services[0][".id"], "disabled": disabled}

    if port is not None:
        changes["port"] = port
    if allowed is not None:
        changes["address"] = allowed

    session.path("/ip/service").update(**changes)
    outcome.log(f"Servicio API actualizado (disabled={disabled}).")


def _create_api_user(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    username = op["username"]
    existing = session.rows("/user", name=username)

    payload = {
        "name": username,
        "password": op["password"],
        "group": op.get("group", "full"),
        "address": op.get("allowed_address", ""),
        "comment": op.get("comment", ""),
    }

    if existing:
        # Reaprovisionamiento: se reescribe la contraseña en vez de fallar, para
        # que el usuario dedicado quede siempre con la credencial vigente.
        session.path("/user").update(**{".id": existing[0][".id"], **payload})
        outcome.log(f"Usuario {username} ya existía; credenciales actualizadas.")
        return

    session.path("/user").add(**payload)
    outcome.log(f"Usuario dedicado {username} creado.")


def _verify_login(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    """Comprueba que el usuario recién creado sirve para entrar.

    Se hace ANTES de cerrar la API a la subred del túnel: si algo salió mal, el
    agente todavía puede revertir con las credenciales de fábrica. Verificarlo
    después sería verificarlo cuando ya no hay vuelta atrás.
    """
    probe = RouterOsSession(
        host=session.host,
        port=op.get("port", session.port),
        username=op["username"],
        password=op["password"],
        timeout=10.0,
    )

    try:
        probe.connect()
        probe.rows("/system/identity")
        outcome.log(f"Login verificado con el usuario {op['username']}.")
    except RouterOsError as exc:
        raise RouterOsError(
            "ROTATED_CREDENTIALS_INVALID",
            f"El usuario {op['username']} se creó pero no permite entrar: {exc.message}",
        ) from exc
    finally:
        probe.close()


def _remove_api_user(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    for row in session.rows("/user", name=op["username"]):
        session.path("/user").remove(row[".id"])
        outcome.log(f"Usuario {op['username']} eliminado.")


def _remove_input_rules(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    interface = op["interface"]

    for row in session.rows("/ip/firewall/filter"):
        if row.get("in-interface") == interface or "ISP Gestor" in str(row.get("comment", "")):
            session.path("/ip/firewall/filter").remove(row[".id"])
            outcome.log(f"Regla de firewall {row['.id']} eliminada.")


def _remove_ip_address(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    for row in session.rows("/ip/address", interface=op["interface"]):
        session.path("/ip/address").remove(row[".id"])
        outcome.log(f"Dirección {row.get('address')} retirada de {op['interface']}.")


def _remove_wireguard_peers(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    for row in session.rows("/interface/wireguard/peers", interface=op["interface"]):
        session.path("/interface/wireguard/peers").remove(row[".id"])
        outcome.log(f"Peer {row['.id']} eliminado de {op['interface']}.")


def _remove_wireguard_interface(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    for row in session.rows("/interface/wireguard", name=op["name"]):
        session.path("/interface/wireguard").remove(row[".id"])
        outcome.log(f"Interfaz {op['name']} eliminada.")


def _check_interface(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    rows = session.rows("/interface/wireguard", name=op["name"])

    if not rows:
        raise RouterOsError("WG_INTERFACE_MISSING", f"La interfaz {op['name']} no existe.")

    if str(rows[0].get("disabled", "false")).lower() == "true":
        raise RouterOsError("WG_INTERFACE_DISABLED", f"La interfaz {op['name']} está deshabilitada.")

    outcome.log(f"Interfaz {op['name']} presente y habilitada.")


def _check_peer_handshake(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    """Espera a que haya handshake reciente con el servidor.

    Se sondea con espera activa porque el primer handshake no es inmediato: el
    router tiene que resolver el endpoint, atravesar el NAT de la oficina y
    completar el intercambio. Fallar al primer intento daría falsos negativos.
    """
    max_age = int(op.get("max_age_seconds", 180))
    deadline = time.monotonic() + min(max_age, 90)

    while True:
        for row in session.rows("/interface/wireguard/peers", interface=op["interface"]):
            handshake = row.get("last-handshake")
            if handshake:
                age = _duration_seconds(str(handshake))
                if age is not None and age <= max_age:
                    outcome.values["handshake_age_seconds"] = age
                    outcome.log(f"Handshake con el servidor hace {age} s.")
                    return

        if time.monotonic() >= deadline:
            raise RouterOsError(
                "NO_HANDSHAKE",
                f"El túnel no completó el handshake en {int(min(max_age, 90))} s. "
                f"Comprueba que el equipo tiene salida a internet y que el endpoint "
                f"es alcanzable desde su red.",
            )

        time.sleep(3)


def _ping(session: RouterOsSession, op: dict, outcome: OperationOutcome) -> None:
    """Ping desde el propio equipo: la prueba de que el túnel además enruta.

    Que haya handshake solo demuestra que los dos extremos se reconocen; sin
    esto, un túnel con las redes mal declaradas pasaría por bueno.
    """
    address = op["address"]
    count = int(op.get("count", 4))

    replies = session.query("/ping", address=address, count=count)
    received = sum(1 for row in replies if row.get("status") is None and row.get("time"))

    outcome.values.setdefault("ping", {})[address] = {"sent": count, "received": received}

    if received == 0:
        raise RouterOsError(
            "PING_FAILED",
            f"El equipo no alcanza {address} por el túnel pese a tener handshake. "
            f"Revisa las redes declaradas en el peer (allowed-address).",
        )

    outcome.log(f"Ping a {address}: {received}/{count} respuestas.")


def _duration_seconds(value: str) -> int | None:
    """Traduce una duración de RouterOS ('1m30s', '5s', '2h3m') a segundos."""
    units = {"d": 86400, "h": 3600, "m": 60, "s": 1}
    total = 0
    number = ""
    matched = False

    for char in value:
        if char.isdigit():
            number += char
        elif char in units and number:
            total += int(number) * units[char]
            number = ""
            matched = True
        else:
            number = ""

    return total if matched else None


HANDLERS: dict[str, Callable[[RouterOsSession, dict, OperationOutcome], None]] = {
    "wireguard.create_interface": _create_wireguard_interface,
    "wireguard.add_peer": _add_wireguard_peer,
    "wireguard.remove_peers": _remove_wireguard_peers,
    "wireguard.remove_interface": _remove_wireguard_interface,
    "wireguard.check_interface": _check_interface,
    "wireguard.check_peer_handshake": _check_peer_handshake,
    "ip.add_address": _add_ip_address,
    "ip.remove_address": _remove_ip_address,
    "firewall.allow_input": _allow_input,
    "firewall.remove_input_rules": _remove_input_rules,
    "service.enable_api": _enable_api,
    "service.restrict_api": _restrict_api,
    "service.unrestrict_api": _unrestrict_api,
    "user.create_api_user": _create_api_user,
    "user.verify_login": _verify_login,
    "user.remove_api_user": _remove_api_user,
    "ping": _ping,
}
