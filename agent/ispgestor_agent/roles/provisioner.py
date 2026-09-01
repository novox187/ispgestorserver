"""Rol `provisioner`: el agente que vive donde se enchufan los routers.

Hace dos cosas: vigilar el puerto de aprovisionamiento para detectar un equipo
en cuanto se conecta el cable, y ejecutar contra él las operaciones que la saga
le vaya mandando.

La detección combina tres señales porque ninguna basta por sí sola:

  · MNDP es la más rica —trae MAC, modelo, versión e identidad sin necesitar
    credenciales— pero RouterOS solo anuncia cada 60 segundos.
  · El carrier de la NIC reacciona en el acto, pero no dice qué hay al otro lado.
  · La sonda directa confirma que lo conectado es un RouterOS y sirve de red de
    seguridad para los equipos que traen el descubrimiento deshabilitado.
"""

from __future__ import annotations

import logging
import time

from ..client import ApiClient, ApiError, TransportError
from ..config import AgentConfig
from ..detect import link, mndp
from ..routeros import RouterOsError, RouterOsSession

log = logging.getLogger("ispgestor.provisioner")

# Un mismo equipo no se vuelve a reportar dentro de esta ventana. MNDP repite
# cada 60 s y el servidor ya deduplica, pero no tiene sentido gastar una
# petición por anuncio.
REPORT_COOLDOWN_SECONDS = 45


class ProvisionerRole:
    def __init__(self, config: AgentConfig, client: ApiClient):
        self.config = config
        self.client = client
        self.watcher = link.CarrierWatcher(config.provisioning_interfaces)
        self._recent: dict[str, float] = {}

    # ── Bucle principal ─────────────────────────────────────────────────────

    def run(self, version: str) -> None:
        if not self.config.provisioning_interfaces:
            log.warning(
                "No hay interfaces de aprovisionamiento configuradas: solo se "
                "atenderán tareas, no se detectará ningún equipo."
            )

        # Un cable que ya estaba puesto antes de arrancar no es una conexión
        # nueva y no debe disparar un alta.
        self.watcher.prime()

        listener = mndp.MndpListener(timeout=1.0)
        try:
            listener.open()
        except OSError as exc:
            log.error(
                "No se pudo escuchar MNDP en el puerto %d (%s). La detección "
                "seguirá funcionando por carrier y sonda, pero más despacio.",
                mndp.MNDP_PORT,
                exc,
            )
            listener = None

        last_heartbeat = 0.0

        try:
            while True:
                now = time.monotonic()

                if now - last_heartbeat >= self.config.heartbeat_interval:
                    self._heartbeat(version)
                    last_heartbeat = now

                self._detect_by_carrier()

                if listener is not None:
                    self._detect_by_mndp(listener)

                self._work()

                time.sleep(self.config.poll_interval)
        except KeyboardInterrupt:
            log.info("Detenido por el operador.")
        finally:
            if listener is not None:
                listener.close()

    def _heartbeat(self, version: str) -> None:
        links = {
            interface: bool(state and state.carrier)
            for interface in self.config.provisioning_interfaces
            for state in [link.read_link(interface)]
        }

        try:
            self.client.heartbeat(version, self.config.capabilities(), health={"links": links})
        except (ApiError, TransportError) as exc:
            log.error("Latido fallido: %s", exc)

    # ── Detección ───────────────────────────────────────────────────────────

    def _detect_by_carrier(self) -> None:
        for interface in self.watcher.poll():
            log.info("Cable conectado en %s; sondeando el segmento.", interface)

            for address in self.config.probe_addresses:
                # Se fuerza una entrada ARP: sin esto la sonda llegaría sin la
                # MAC, que es el dato con el que el servidor deduplica.
                link.warm_arp(address)
                found = link.probe(address)

                if found is None:
                    continue

                log.info("Detectado un equipo en %s (puertos %s).", address, found["open_ports"])
                found["link_interface"] = interface
                found.pop("open_ports", None)
                self._report(found)
                break

    def _detect_by_mndp(self, listener: mndp.MndpListener) -> None:
        # Se vacía lo que haya llegado desde la última vuelta; el socket tiene
        # tiempo de espera corto, así que esto no bloquea el bucle.
        for _ in range(10):
            neighbor = listener.poll()

            if neighbor is None:
                return

            if not neighbor.is_mikrotik():
                continue

            log.info(
                "Anuncio MNDP de %s (%s, RouterOS %s).",
                neighbor.identity or "sin identidad",
                neighbor.board or "modelo desconocido",
                neighbor.version or "?",
            )

            self._report(neighbor.to_detection(self._interface_for(neighbor)))

    def _interface_for(self, neighbor: mndp.Neighbor) -> str | None:
        """NIC vigilada por la que probablemente entró el anuncio.

        El socket MNDP es de difusión y no siempre dice por dónde llegó el
        paquete, así que con una sola interfaz configurada se asume esa.
        """
        if len(self.config.provisioning_interfaces) == 1:
            return self.config.provisioning_interfaces[0]

        return None

    def _report(self, payload: dict) -> None:
        key = payload.get("mac_address") or payload.get("lan_ip") or "desconocido"
        now = time.monotonic()

        if now - self._recent.get(key, 0.0) < REPORT_COOLDOWN_SECONDS:
            return

        self._recent[key] = now

        try:
            result = self.client.report_detection(payload)
        except (ApiError, TransportError) as exc:
            log.error("No se pudo reportar la detección: %s", exc)
            # Se olvida para reintentar en el siguiente anuncio en vez de
            # esperar a que expire el silencio.
            self._recent.pop(key, None)
            return

        if result.get("ignored"):
            log.info("El servidor descartó el equipo: %s", result.get("message"))
        elif result.get("created"):
            log.info("Alta iniciada, sesión #%s.", result.get("session_id"))

    # ── Ejecución de tareas ─────────────────────────────────────────────────

    def _work(self) -> None:
        try:
            tasks = self.client.claim_tasks(1)
        except (ApiError, TransportError) as exc:
            log.error("No se pudieron reclamar tareas: %s", exc)
            return

        for task in tasks:
            self._execute(task)

    def _execute(self, task: dict) -> None:
        task_id = task["id"]
        task_type = task["type"]
        payload = task.get("payload") or {}

        log.info("Ejecutando tarea #%s (%s).", task_id, task_type)

        try:
            if task_type == "identify_device":
                result, logs = self._identify(payload)
            else:
                result, logs = self._apply(payload)

            self.client.report_task(task_id, "succeeded", result=result, logs=logs)
            log.info("Tarea #%s completada.", task_id)

        except RouterOsError as exc:
            log.error("Tarea #%s fallida: %s", task_id, exc)
            self._report_failure(task_id, exc.code, exc.message)

        except Exception as exc:  # noqa: BLE001 - cualquier fallo debe reportarse
            log.exception("Tarea #%s con error inesperado.", task_id)
            self._report_failure(task_id, "AGENT_UNEXPECTED_ERROR", str(exc))

    def _report_failure(self, task_id: int, code: str, message: str) -> None:
        try:
            self.client.report_task(task_id, "failed", error_code=code, error_message=message)
        except (ApiError, TransportError) as exc:
            # Si el reporte no llega, el vigilante del servidor vencerá la tarea
            # y disparará la reversión igualmente.
            log.error("No se pudo reportar el fallo de la tarea #%s: %s", task_id, exc)

    def _identify(self, payload: dict) -> tuple[dict, list[str]]:
        """Entra al equipo probando las credenciales de fábrica candidatas."""
        host = payload.get("lan_ip")
        port = int(payload.get("api_port", 8728))
        candidates = payload.get("credentials") or [{"username": "admin", "password": ""}]
        logs: list[str] = []

        if not host:
            raise RouterOsError(
                "DEVICE_ADDRESS_UNKNOWN",
                "No se conoce la IP del equipo. Comprueba que la NIC de "
                "aprovisionamiento recibe dirección del router.",
            )

        last_error: RouterOsError | None = None

        for candidate in candidates:
            session = RouterOsSession(
                host=host,
                port=port,
                username=candidate.get("username", "admin"),
                password=candidate.get("password", ""),
            )

            try:
                session.connect()
            except RouterOsError as exc:
                last_error = exc
                logs.append(f"Credencial '{candidate.get('username')}' rechazada.")
                continue

            try:
                identity = session.identify()
                logs.append(
                    f"Conectado a {host}:{port} como '{candidate.get('username')}'. "
                    f"{identity.get('board_name')} / RouterOS {identity.get('routeros_version')}."
                )

                identity["mac_address"] = session.management_mac()
                identity["lan_ip"] = host
                identity["credentials"] = candidate

                if payload.get("checks", {}).get("wan_reachability"):
                    identity["wan_reachable"] = self._has_internet(session, logs)

                return identity, logs
            finally:
                session.close()

        raise last_error or RouterOsError(
            "NO_VALID_CREDENTIALS",
            "Ninguna de las credenciales de fábrica configuradas sirvió para entrar al equipo.",
        )

    def _has_internet(self, session: RouterOsSession, logs: list[str]) -> bool:
        """El equipo alcanza internet.

        Se comprueba aquí y no más adelante porque sin salida el router jamás
        podría alcanzar el endpoint del túnel, y el fallo se manifestaría tres
        pasos después como un handshake que no llega — mucho más difícil de
        diagnosticar que «falta el cable de WAN».
        """
        for target in ("1.1.1.1", "8.8.8.8"):
            try:
                replies = session.query("/ping", address=target, count=2)
            except RouterOsError:
                continue

            if any(row.get("time") for row in replies):
                logs.append(f"Salida a internet confirmada ({target} responde).")
                return True

        logs.append("El equipo no alcanza internet: revisa el cable de WAN en ether1.")
        return False

    def _apply(self, payload: dict) -> tuple[dict, list[str]]:
        connection = payload.get("connection") or {}
        operations = payload.get("operations") or []

        session = RouterOsSession(
            host=connection.get("host"),
            port=int(connection.get("port", 8728)),
            username=connection.get("username", "admin"),
            password=connection.get("password", ""),
        )

        with session:
            outcome = session.run(operations)

        missing = [key for key in payload.get("expect", []) if key not in outcome.values]
        if missing:
            raise RouterOsError(
                "EXPECTED_VALUES_MISSING",
                f"El equipo no devolvió: {', '.join(missing)}.",
            )

        return outcome.values, outcome.logs
