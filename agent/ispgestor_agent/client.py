"""Cliente HTTP firmado contra la API de ISP Gestor.

Toda la comunicación es SALIENTE. El agente nunca escucha en un puerto: es él
quien abre la conexión para reclamar trabajo. Eso es lo que permite que la
aplicación, aislada en su contenedor de Coolify, gobierne máquinas que están
detrás de un NAT sin abrir nada en ninguno de los dos extremos.

Cada petición va firmada con HMAC-SHA256 sobre método, ruta, marca de tiempo,
nonce y hash del cuerpo. El secreto no viaja: viaja la firma.

Se usa `urllib` de la biblioteca estándar a propósito, para que instalar el
agente en un servidor ajeno no arrastre dependencias que no hagan falta.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import ssl
import time
import urllib.error
import urllib.request
import uuid
from typing import Any

HEADER_AGENT = "X-ISPG-Agent"
HEADER_TIMESTAMP = "X-ISPG-Timestamp"
HEADER_NONCE = "X-ISPG-Nonce"
HEADER_SIGNATURE = "X-ISPG-Signature"

USER_AGENT = "ispgestor-agent/1.0"


class ApiError(RuntimeError):
    """La API respondió con un error."""

    def __init__(self, status: int, code: str, message: str, retry_after: int | None = None):
        self.status = status
        self.code = code
        self.message = message
        #: Segundos que el servidor pide esperar (cabecera `Retry-After`).
        self.retry_after = retry_after
        super().__init__(f"[{status} {code}] {message}")

    @property
    def is_rate_limited(self) -> bool:
        return self.status == 429


class TransportError(RuntimeError):
    """No se pudo alcanzar la API."""


def canonical_string(method: str, path: str, timestamp: str, nonce: str, body: str) -> str:
    return "\n".join(
        [
            method.upper(),
            path,
            timestamp,
            nonce,
            hashlib.sha256(body.encode("utf-8")).hexdigest(),
        ]
    )


def sign(secret: str, method: str, path: str, timestamp: str, nonce: str, body: str) -> str:
    return hmac.new(
        secret.encode("utf-8"),
        canonical_string(method, path, timestamp, nonce, body).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()


class ApiClient:
    def __init__(
        self,
        base_url: str,
        token: str = "",
        secret: str = "",
        timeout: float = 30.0,
        verify_tls: bool = True,
    ):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.secret = secret
        self.timeout = timeout
        self._ssl_context = None if verify_tls else ssl._create_unverified_context()

    # ── Endpoints ───────────────────────────────────────────────────────────

    def enroll(self, enrollment_token: str, hostname: str, version: str, capabilities: dict) -> dict:
        """Canjea el token de un solo uso por las credenciales permanentes.

        Es la única llamada sin firmar: el secreto con el que se firmaría es
        justo lo que se está pidiendo.
        """
        return self._request(
            "POST",
            "/api/agent/enroll",
            {
                "enrollment_token": enrollment_token,
                "hostname": hostname,
                "agent_version": version,
                "capabilities": capabilities,
            },
            signed=False,
        )["data"]

    def heartbeat(self, version: str, capabilities: dict, health: dict | None = None) -> dict:
        return self._request(
            "POST",
            "/api/agent/heartbeat",
            {"agent_version": version, "capabilities": capabilities, "health": health or {}},
        )["data"]

    def report_detection(self, payload: dict) -> dict:
        return self._request("POST", "/api/agent/devices/detected", payload)["data"]

    def claim_tasks(self, maximum: int = 1) -> list[dict]:
        return self._request("POST", "/api/agent/tasks/claim", {"max": maximum})["data"]["tasks"]

    def report_task(
        self,
        task_id: int,
        status: str,
        result: dict | None = None,
        error_code: str | None = None,
        error_message: str | None = None,
        logs: list[str] | None = None,
    ) -> None:
        self._request(
            "POST",
            f"/api/agent/tasks/{task_id}/report",
            {
                "status": status,
                "result": result or {},
                "error_code": error_code,
                "error_message": error_message,
                "logs": (logs or [])[:200],
            },
        )

    # ── Monitoreo ───────────────────────────────────────────────────────────

    def monitoring_targets(self) -> dict:
        """Equipos que este agente debe sondear, con credenciales resueltas."""
        return self._request("GET", "/api/agent/monitoring/targets").get("data", {})

    def push_samples(self, samples: list[dict]) -> dict:
        """Envía un lote de lecturas.

        En lote y no de una en una: con cientos de equipos, una petición por
        muestra agotaría el límite del canal en segundos y multiplicaría por
        cien el coste de firmar cada envío.
        """
        return self._request(
            "POST", "/api/agent/monitoring/samples", {"samples": samples}
        ).get("data", {})

    # ── Barridos de descubrimiento ──────────────────────────────────────────

    def pending_scans(self) -> list[dict]:
        """Barridos que el operador ha pedido para este agente."""
        return self._request("GET", "/api/agent/monitoring/scans").get("data", {}).get("scans", [])

    def report_scan(
        self,
        scan_id: int,
        status: str,
        findings: list[dict] | None = None,
        error_code: str | None = None,
        error_message: str | None = None,
    ) -> dict:
        return self._request(
            "POST",
            f"/api/agent/monitoring/scans/{scan_id}/report",
            {
                "status": status,
                "findings": findings or [],
                "error_code": error_code,
                "error_message": error_message,
            },
        ).get("data", {})

    # ── Interno ─────────────────────────────────────────────────────────────

    def _request(self, method: str, path: str, payload: Any = None, signed: bool = True) -> dict:
        body = "" if payload is None else json.dumps(payload)

        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": USER_AGENT,
        }

        if signed:
            if not self.token or not self.secret:
                raise TransportError("El agente no tiene credenciales; ejecuta 'enroll' primero.")

            timestamp = str(int(time.time()))
            nonce = str(uuid.uuid4())

            headers[HEADER_AGENT] = self.token
            headers[HEADER_TIMESTAMP] = timestamp
            headers[HEADER_NONCE] = nonce
            headers[HEADER_SIGNATURE] = sign(self.secret, method, path, timestamp, nonce, body)

        # Un GET viaja sin cuerpo. Mandar `b""` haría que urllib anunciara
        # Content-Length y algunos intermediarios lo tratan distinto; además la
        # firma se calcula sobre cadena vacía, así que ambos extremos coinciden.
        data = None if method.upper() == "GET" else body.encode("utf-8")

        request = urllib.request.Request(
            url=f"{self.base_url}{path}",
            data=data,
            headers=headers,
            method=method,
        )

        try:
            with urllib.request.urlopen(
                request, timeout=self.timeout, context=self._ssl_context
            ) as response:
                raw = response.read().decode("utf-8")
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            raise self._as_api_error(exc) from exc
        except urllib.error.URLError as exc:
            raise TransportError(f"No se pudo alcanzar {self.base_url}{path}: {exc.reason}") from exc
        except (TimeoutError, OSError) as exc:
            raise TransportError(f"Fallo de red hacia {self.base_url}{path}: {exc}") from exc

    @staticmethod
    def _as_api_error(exc: urllib.error.HTTPError) -> ApiError:
        try:
            payload = json.loads(exc.read().decode("utf-8"))
        except Exception:
            payload = {}

        error = payload.get("error") or {}

        # El servidor limita el canal por agente. Sin leer `Retry-After` el
        # agente seguiría martilleando cada pocos segundos contra un 429, que es
        # la forma más rápida de convertir un límite en una caída.
        retry_after = None
        try:
            raw = exc.headers.get("Retry-After") if exc.headers else None
            retry_after = int(raw) if raw is not None else None
        except (TypeError, ValueError):
            retry_after = None

        return ApiError(
            status=exc.code,
            code=error.get("code") or f"HTTP_{exc.code}",
            message=error.get("message") or payload.get("message") or str(exc.reason),
            retry_after=retry_after,
        )
