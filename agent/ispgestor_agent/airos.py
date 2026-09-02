"""Transporte hacia las antenas Ubiquiti airMAX (airOS).

Este módulo **no interpreta** lo que devuelve la antena: se limita a traer el
JSON de `status.cgi` y dejar que lo normalice el servidor. La división es
deliberada. Cuando aparezca un firmware que no sabemos leer, soportarlo será un
despliegue del servidor; si el parseo viviera aquí, exigiría ir a la oficina del
cliente a actualizar este demonio a mano en cada máquina donde corra.

Solo biblioteca estándar, como el resto del agente: `urllib` y `ssl` bastan y
mantienen la promesa de una sola dependencia en `requirements.txt`.

## Por qué se reutiliza la sesión

El httpd de airOS es monohilo y admite pocas sesiones concurrentes. Autenticarse
en cada vuelta contra cientos de antenas cada cinco minutos puede llegar a
colgar el httpd de un equipo que por lo demás funciona perfectamente, dejándolo
sin gestión. La cookie se guarda y solo se vuelve a autenticar cuando el equipo
la rechaza. No es una optimización: es no hacer daño.
"""

from __future__ import annotations

import json
import logging
import ssl
import urllib.error
import urllib.parse
import urllib.request

log = logging.getLogger("ispgestor.airos")

LOGIN_PATH = "/login.cgi"
STATUS_PATH = "/status.cgi"
SESSION_COOKIE = "AIROS_SESSIONID"


class AirOsError(RuntimeError):
    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"[{code}] {message}")


def _insecure_context() -> ssl.SSLContext:
    """Contexto TLS que no verifica el certificado.

    Las antenas traen un certificado autofirmado que además se regenera al
    restablecer de fábrica. Exigir una cadena válida haría imposible hablar con
    cualquier equipo del parque; la confidencialidad la aporta estar dentro de
    la red de gestión.
    """
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    return ctx


class AirOsSession:
    """Sesión reutilizable contra una antena."""

    def __init__(self, host: str, port: int, username: str, password: str, timeout: float = 8.0):
        self.host = host
        self.port = port or 443
        self.username = username
        self.password = password
        self.timeout = timeout
        self._cookie: str | None = None
        self._ssl = _insecure_context()

    @property
    def base_url(self) -> str:
        # El 80 es HTTP plano; cualquier otro puerto se asume TLS, que es lo que
        # airOS trae de fábrica.
        if self.port == 80:
            return f"http://{self.host}"
        return f"https://{self.host}:{self.port}"

    def status(self) -> dict:
        """Devuelve `status.cgi` en crudo, autenticando solo si hace falta."""
        if self._cookie is None:
            self.login()

        try:
            return self._get(STATUS_PATH)
        except AirOsError as exc:
            if exc.code != "AIROS_SESSION_EXPIRED":
                raise

            # La sesión caducó o el equipo se reinició: una sola reautenticación
            # y otro intento. Si vuelve a fallar, se propaga.
            log.debug("Sesión caducada en %s; reautenticando.", self.host)
            self.login()
            return self._get(STATUS_PATH)

    def login(self) -> None:
        data = urllib.parse.urlencode(
            {"username": self.username, "password": self.password}
        ).encode("utf-8")

        request = urllib.request.Request(
            url=f"{self.base_url}{LOGIN_PATH}",
            data=data,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            method="POST",
        )

        try:
            with urllib.request.urlopen(request, timeout=self.timeout, context=self._ssl) as response:
                cookie = self._session_cookie(response.headers.get_all("Set-Cookie") or [])
        except urllib.error.HTTPError as exc:
            # airOS responde 302 al autenticar bien, y urllib lo sigue salvo que
            # devuelva error; la cookie viaja en esa respuesta.
            cookie = self._session_cookie(exc.headers.get_all("Set-Cookie") or [])
            if cookie is None:
                raise AirOsError("AIROS_AUTH_FAILED", f"Login rechazado (HTTP {exc.code}).") from exc
        except urllib.error.URLError as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"No se pudo alcanzar {self.host}: {exc.reason}") from exc
        except (TimeoutError, OSError) as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"Fallo de red hacia {self.host}: {exc}") from exc

        if cookie is None:
            raise AirOsError("AIROS_AUTH_FAILED", "El equipo no devolvió sesión; revisa las credenciales.")

        self._cookie = cookie

    def _get(self, path: str) -> dict:
        request = urllib.request.Request(
            url=f"{self.base_url}{path}",
            headers={"Cookie": f"{SESSION_COOKIE}={self._cookie}", "Accept": "application/json"},
            method="GET",
        )

        try:
            with urllib.request.urlopen(request, timeout=self.timeout, context=self._ssl) as response:
                raw = response.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as exc:
            if exc.code in (401, 403):
                raise AirOsError("AIROS_SESSION_EXPIRED", f"El equipo pidió autenticarse de nuevo (HTTP {exc.code}).") from exc
            raise AirOsError("AIROS_HTTP_ERROR", f"{path} devolvió HTTP {exc.code}.") from exc
        except urllib.error.URLError as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"No se pudo alcanzar {self.host}: {exc.reason}") from exc
        except (TimeoutError, OSError) as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"Fallo de red hacia {self.host}: {exc}") from exc

        # Cuando la sesión caduca, airOS devuelve 200 con el HTML del login en
        # vez de un 401. Sin este caso, el agente reportaría «respuesta que no
        # entiendo» en lugar de reautenticarse.
        stripped = raw.lstrip()
        if not stripped.startswith("{"):
            raise AirOsError("AIROS_SESSION_EXPIRED", "El equipo devolvió el formulario de acceso.")

        try:
            return json.loads(raw)
        except json.JSONDecodeError as exc:
            raise AirOsError("AIROS_BAD_JSON", f"{path} no devolvió JSON válido.") from exc

    @staticmethod
    def _session_cookie(headers: list[str]) -> str | None:
        for header in headers:
            for part in header.split(";"):
                name, _, value = part.strip().partition("=")
                if name == SESSION_COOKIE and value:
                    return value
        return None
