"""Rol `monitor`: sondea el parque y empuja lo leído.

Es un rol aparte del `provisioner` y no una capacidad más suya. Aquel corre un
único bucle de tres segundos que atiende MNDP, el carrier de la NIC y la cola de
tareas; sondear cientos de equipos son minutos por vuelta, y meterlo ahí
degradaría el alta automática de routers como efecto colateral del monitoreo.
Separarlo permite además desplegarlo en una torre sin darle capacidad de tocar
la configuración de ningún equipo: el servidor solo le acepta este canal.

En esta fase sondea equipos RouterOS, que es el fabricante ya soportado. El
driver de airOS entra después; la forma del bucle no cambia.
"""

from __future__ import annotations

import logging
import time

from ..airos import AirOsError, AirOsSession
from ..client import ApiClient, ApiError, TransportError
from ..config import AgentConfig
from ..detect import ubnt
from ..parsing import number, uptime_seconds
from ..routeros import RouterOsError, RouterOsSession

log = logging.getLogger("ispgestor.monitor")

#: Tope de muestras por envío. Debe ir por debajo del límite del servidor.
BATCH_SIZE = 100

#: Espera inicial tras un fallo, que se dobla hasta el techo.
BACKOFF_START = 5.0
BACKOFF_MAX = 300.0

#: Timeout por equipo. Corto a propósito: un equipo que no responde no puede
#: retrasar el sondeo de los que sí lo hacen.
DEVICE_TIMEOUT = 8.0


class MonitorRole:
    def __init__(self, config: AgentConfig, client: ApiClient):
        self.config = config
        self.client = client
        #: Sesiones airOS vivas, por id de equipo.
        #:
        #: El httpd de airOS es monohilo y admite pocas sesiones: autenticarse
        #: contra cientos de antenas en cada vuelta puede colgarle el httpd a un
        #: equipo que funciona perfectamente y dejarlo sin gestión. Se conservan
        #: entre vueltas y solo se renuevan cuando el equipo las rechaza.
        self._airos: dict[int, AirOsSession] = {}

    def run(self, version: str) -> None:
        log.info("Agente de monitoreo en marcha.")

        backoff = BACKOFF_START
        interval = 300.0

        try:
            while True:
                try:
                    payload = self.client.monitoring_targets()
                    interval = float(payload.get("poll_interval_seconds") or interval)
                    targets = payload.get("targets") or []

                    log.info("Sondeando %d equipos.", len(targets))
                    self._sweep(targets)

                    # Los barridos se atienden después del sondeo: son puntuales
                    # y no pueden retrasar la telemetría, que es lo que sostiene
                    # las alertas.
                    self._run_pending_scans()

                    backoff = BACKOFF_START
                    time.sleep(interval)

                except ApiError as exc:
                    # El servidor limita este canal por agente; si dice cuánto
                    # esperar, se le hace caso en vez de insistir.
                    wait = exc.retry_after if exc.is_rate_limited and exc.retry_after else backoff
                    log.warning("La API rechazó la petición (%s). Reintento en %.0f s.", exc.code, wait)
                    time.sleep(wait)
                    backoff = min(backoff * 2, BACKOFF_MAX)

                except TransportError as exc:
                    # Sin backoff, una caída de la API se convierte en un
                    # martilleo cada pocos segundos justo cuando peor le viene.
                    log.warning("No se pudo alcanzar la API (%s). Reintento en %.0f s.", exc, backoff)
                    time.sleep(backoff)
                    backoff = min(backoff * 2, BACKOFF_MAX)

        except KeyboardInterrupt:
            log.info("Detenido por el operador.")

    def _sweep(self, targets: list[dict]) -> None:
        self._forget_stale_sessions(targets)

        batch: list[dict] = []

        for target in targets:
            batch.append(self._probe(target))

            if len(batch) >= BATCH_SIZE:
                self._push(batch)
                batch = []

        if batch:
            self._push(batch)

    def _probe(self, target: dict) -> dict:
        """Lee un equipo y devuelve la muestra, pase lo que pase.

        Nunca propaga una excepción: un equipo inalcanzable es un dato —y de los
        importantes—, no un error que deba abortar la vuelta y dejar sin sondear
        a los que vienen detrás.
        """
        sample = {
            "device_id": target["device_id"],
            "sampled_at": int(time.time()),
            "reachable": False,
        }

        driver = target.get("driver")

        if driver == "airos":
            return self._probe_airos(target, sample)

        if driver != "routeros":
            # Todavía sin driver para este equipo: se reporta como no leído en
            # vez de inventarse un estado.
            sample["error"] = f"driver no soportado por este agente: {driver}"
            return sample

        try:
            with RouterOsSession(
                host=target["host"],
                port=int(target.get("port") or 8728),
                username=target.get("username") or "",
                password=target.get("password") or "",
                timeout=DEVICE_TIMEOUT,
            ) as session:
                resource = session.resource()

            sample.update(
                {
                    "reachable": True,
                    "uptime_seconds": uptime_seconds(resource.get("uptime")),
                    "cpu_load_percent": number(resource.get("cpu-load")),
                    "memory_free_bytes": number(resource.get("free-memory")),
                    "memory_total_bytes": number(resource.get("total-memory")),
                }
            )
        except RouterOsError as exc:
            sample["error"] = f"{exc.code}: {exc.message}"[:500]
        except Exception as exc:  # noqa: BLE001 — ver el docstring
            sample["error"] = str(exc)[:500]

        return sample

    def _probe_airos(self, target: dict, sample: dict) -> dict:
        """Trae `status.cgi` en crudo y lo manda tal cual.

        El agente NO interpreta la respuesta a propósito: normalizarla es trabajo
        del servidor. Así, el día que una antena traiga un firmware que no
        sabemos leer, darle soporte es un despliegue y no una visita a la oficina
        del cliente para actualizar este demonio.
        """
        device_id = target["device_id"]

        try:
            session = self._airos.get(device_id)

            if session is None:
                session = AirOsSession(
                    host=target["host"],
                    port=int(target.get("port") or 443),
                    username=target.get("username") or "",
                    password=target.get("password") or "",
                    timeout=DEVICE_TIMEOUT,
                )
                self._airos[device_id] = session

            sample["raw"] = session.status()
            sample["reachable"] = True

        except AirOsError as exc:
            # Se tira la sesión: si el fallo fue de credenciales o el equipo se
            # reinició, conservarla haría fallar también la vuelta siguiente.
            self._airos.pop(device_id, None)
            sample["error"] = f"{exc.code}: {exc.message}"[:500]
        except Exception as exc:  # noqa: BLE001 — un equipo no puede tumbar la vuelta
            self._airos.pop(device_id, None)
            sample["error"] = str(exc)[:500]

        return sample

    def _forget_stale_sessions(self, targets: list[dict]) -> None:
        """Olvida las sesiones de equipos que ya no están asignados.

        Sin esto, el diccionario crecería indefinidamente cada vez que un equipo
        se reasigna o se da de baja, en un proceso que corre durante meses.
        """
        vigentes = {t["device_id"] for t in targets}

        for device_id in list(self._airos):
            if device_id not in vigentes:
                del self._airos[device_id]

    # ── Barridos de descubrimiento ──────────────────────────────────────────

    def _run_pending_scans(self) -> None:
        try:
            scans = self.client.pending_scans()
        except (ApiError, TransportError) as exc:
            log.debug("No se pudieron consultar los barridos pendientes: %s", exc)
            return

        for scan in scans:
            self._run_scan(scan)

    def _run_scan(self, scan: dict) -> None:
        scan_id = scan["id"]
        cidr = scan["cidr"]

        # LA comprobación que importa: el rango se valida contra la lista blanca
        # de ESTE agente, no contra lo que diga el servidor. Sin esto, quien
        # controlara el servidor podría usar al agente para barrer cualquier red
        # a la que alcance.
        if not ubnt.cidr_is_allowed(cidr, self.config.scannable_cidrs):
            log.warning("Barrido de %s rechazado: fuera de la lista blanca local.", cidr)
            self._report(
                scan_id,
                "failed",
                error_code="CIDR_NOT_ALLOWED",
                error_message=(
                    f"El rango {cidr} no está en los rangos que este agente tiene "
                    f"permitido barrer. Se configuran en la máquina del agente."
                ),
            )
            return

        log.info("Barriendo %s…", cidr)

        try:
            encontrados = ubnt.sweep(cidr)
        except ValueError as exc:
            self._report(scan_id, "failed", error_code="CIDR_TOO_LARGE", error_message=str(exc))
            return
        except Exception as exc:  # noqa: BLE001 — un barrido no puede tumbar el bucle
            self._report(scan_id, "failed", error_code="SCAN_FAILED", error_message=str(exc)[:500])
            return

        log.info("Barrido de %s: %d equipos.", cidr, len(encontrados))
        self._report(scan_id, "completed", findings=[d.to_finding() for d in encontrados])

    def _report(self, scan_id: int, status: str, **kwargs) -> None:
        try:
            self.client.report_scan(scan_id, status, **kwargs)
        except (ApiError, TransportError) as exc:
            # Si el reporte no llega, el barrido queda en «ejecutándose» y el
            # servidor lo vencerá por tiempo. Reintentarlo aquí solo alargaría
            # la vuelta.
            log.warning("No se pudo reportar el barrido %s: %s", scan_id, exc)

    def _push(self, batch: list[dict]) -> None:
        try:
            result = self.client.push_samples(batch)
            log.info("Enviadas %d muestras, guardadas %s.", len(batch), result.get("stored"))
        except (ApiError, TransportError) as exc:
            # Se pierde el lote a propósito: reencolarlo en memoria haría crecer
            # el proceso sin límite durante una caída larga de la API, y una
            # muestra vieja vale mucho menos que la siguiente, que llega en
            # minutos.
            log.warning("No se pudo enviar el lote de %d muestras: %s", len(batch), exc)
