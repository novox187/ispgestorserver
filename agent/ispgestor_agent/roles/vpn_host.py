"""Rol `vpn_host`: el agente que corre en el sistema operativo del hosting.

Existe por una razón concreta: la aplicación vive aislada en su contenedor de
Coolify y no puede tocar la interfaz WireGuard del host. En vez de intentar
salir del contenedor —abriendo puertos, montando sockets o metiendo claves SSH
dentro de la imagen—, se invierte la dirección: este demonio sale a buscar
trabajo por HTTPS.

Solo administra peers. No lee, no escribe y no transmite la clave privada del
servidor; lo único que publica es su contraparte pública, para que la saga sepa
qué meterle al router.
"""

from __future__ import annotations

import logging
import time

from ..client import ApiClient, ApiError, TransportError
from ..config import AgentConfig
from ..wireguard import WireGuardError, WireGuardHost

log = logging.getLogger("ispgestor.vpn_host")


class VpnHostRole:
    def __init__(self, config: AgentConfig, client: ApiClient):
        self.config = config
        self.client = client
        self.host = WireGuardHost(config.wg_interface, config.wg_config_path)

    def run(self, version: str) -> None:
        self._preflight()

        last_heartbeat = 0.0

        try:
            while True:
                now = time.monotonic()

                if now - last_heartbeat >= self.config.heartbeat_interval:
                    self._heartbeat(version)
                    last_heartbeat = now

                self._work()
                time.sleep(self.config.poll_interval)
        except KeyboardInterrupt:
            log.info("Detenido por el operador.")

    def _preflight(self) -> None:
        """Comprueba al arrancar lo que haría fallar cualquier alta.

        Es mejor gritar aquí que dejar que la primera alta se caiga a mitad y
        haya que revertir un router ya configurado.
        """
        if not self.host.interface_exists():
            log.error(
                "La interfaz '%s' no existe en este host. El túnel del servidor "
                "debe estar levantado antes de que el agente pueda añadirle peers.",
                self.config.wg_interface,
            )
            return

        actual = self.host.server_public_key()

        if actual and self.config.server_public_key and actual != self.config.server_public_key:
            # Si no coincide, los routers se configurarían con una clave que no
            # es la del servidor y el handshake nunca se completaría.
            log.error(
                "La clave pública configurada no coincide con la de '%s'. "
                "Corrige server_public_key en la configuración del agente: %s",
                self.config.wg_interface,
                actual,
            )
        elif actual and not self.config.server_public_key:
            log.warning(
                "No hay server_public_key configurada; se tomará la de la interfaz: %s",
                actual,
            )
            self.config.server_public_key = actual

        log.info(
            "Listo. Interfaz %s con %d peers, endpoint %s:%d.",
            self.config.wg_interface,
            len(self.host.peers()),
            self.config.endpoint_host,
            self.config.endpoint_port,
        )

    def _heartbeat(self, version: str) -> None:
        try:
            self.client.heartbeat(
                version,
                self.config.capabilities(),
                health={
                    "interface_up": self.host.interface_exists(),
                    "peer_count": len(self.host.peers()) if self.host.interface_exists() else 0,
                },
            )
        except (ApiError, TransportError) as exc:
            log.error("Latido fallido: %s", exc)

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
        log.info("Ejecutando tarea #%s (%s).", task_id, task["type"])

        try:
            outcome = self.host.run((task.get("payload") or {}).get("operations") or [])
            self.client.report_task(task_id, "succeeded", result=outcome.values, logs=outcome.logs)
            log.info("Tarea #%s completada.", task_id)

        except WireGuardError as exc:
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
