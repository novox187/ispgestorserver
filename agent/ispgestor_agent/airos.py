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

import http.cookiejar
import json
import logging
import secrets
import ssl
import urllib.error
import urllib.request

log = logging.getLogger("ispgestor.airos")

LOGIN_PATH = "/login.cgi"
STATUS_PATH = "/status.cgi"

# La cookie de sesión no tiene un nombre fijo: airOS la llama `AIROS_` seguido
# de la MAC del propio equipo sin separadores —`AIROS_FCECDA2C91C1`—, así que
# cambia en cada antena. Buscar un nombre concreto no encuentra nunca nada.
SESSION_PREFIX = "AIROS_"


def _multipart(campos: dict[str, str]) -> tuple[bytes, str]:
    """Codifica un formulario como `multipart/form-data`.

    Es lo que declara el formulario de acceso del propio equipo. Su CGI también
    acepta urlencoded —comprobado contra un airOS 6.3.6—, así que esto no es lo
    que hace que el login funcione; se manda como lo manda el equipo por no
    depender de una tolerancia que otro firmware podría no tener.

    La frontera es aleatoria, así que ningún valor puede contenerla y cerrar una
    parte antes de tiempo.
    """
    frontera = f"----ispgestor{secrets.token_hex(16)}"
    partes: list[str] = []

    for nombre, valor in campos.items():
        partes.append(f"--{frontera}\r\n")
        partes.append(f'Content-Disposition: form-data; name="{nombre}"\r\n\r\n')
        partes.append(f"{valor}\r\n")

    partes.append(f"--{frontera}--\r\n")

    return "".join(partes).encode("utf-8"), f"multipart/form-data; boundary={frontera}"


class _SinRedirecciones(urllib.request.HTTPRedirectHandler):
    """El 302 de airOS es información, no un desvío que haya que seguir.

    Seguir el de `login.cgi` descarga la página de inicio entera para nada, y
    seguir el de `status.cgi` convertiría la señal de «esta sesión no vale» en
    un 200 con el formulario, que es justo lo que hay que distinguir. El httpd
    de estas antenas es monohilo: cada petición de más cuenta.
    """

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


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
        self._ssl = _insecure_context()
        # El bote de cookies se encarga de guardar la sesión y de mandarla en
        # cada petición. Copiar la cabecera a mano —lo que hacía esto antes—
        # obligaba a adivinar cuál de las dos familias de firmware tenemos
        # delante: unas rotan el identificador al autenticar y otras no.
        self._jar = http.cookiejar.CookieJar()
        self._opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=self._ssl),
            urllib.request.HTTPCookieProcessor(self._jar),
            _SinRedirecciones(),
        )
        self._authenticated = False

    @property
    def base_url(self) -> str:
        # El 80 es HTTP plano; cualquier otro puerto se asume TLS, que es lo que
        # airOS trae de fábrica.
        if self.port == 80:
            return f"http://{self.host}"
        return f"https://{self.host}:{self.port}"

    def status(self) -> dict:
        """Devuelve `status.cgi` en crudo, autenticando solo si hace falta."""
        if not self._authenticated:
            self.login()

        try:
            return self._get(STATUS_PATH)
        except AirOsError as exc:
            if exc.code != "AIROS_SESSION_EXPIRED":
                raise

            # La sesión caducó o el equipo se reinició: una sola reautenticación
            # y otro intento.
            log.debug("Sesión caducada en %s; reautenticando.", self.host)
            self._authenticated = False
            self.login()

            try:
                return self._get(STATUS_PATH)
            except AirOsError as segundo:
                if segundo.code != "AIROS_SESSION_EXPIRED":
                    raise

                # Acabamos de autenticarnos y el equipo sigue devolviendo el
                # formulario. Eso ya no es una sesión caducada: es que la
                # contraseña no vale. Distinguirlo importa porque una y otra se
                # arreglan de formas distintas.
                raise AirOsError(
                    "AIROS_AUTH_FAILED",
                    "El equipo devolvió el formulario de acceso tras autenticar; revisa las credenciales.",
                ) from segundo

    def login(self) -> None:
        """Autentica contra `login.cgi`.

        Son dos peticiones y el orden no es negociable: `login.cgi` **no emite
        una sesión al autenticar, valida la que el cliente ya trae**. El
        navegador la tiene porque al abrir la antena hizo un GET antes de ver el
        formulario; un cliente que va directo al POST no lleva ninguna.

        Y lo peor: sin la semilla el POST responde **302, como si hubiera
        funcionado**. Es `status.cgi` quien luego rechaza la sesión. Comprobado
        contra una NanoStation loco M5 con airOS 6.3.6.
        """
        self._jar.clear()

        # 1. Sembrar la sesión que el POST vendrá a validar.
        self._abrir(
            urllib.request.Request(url=f"{self.base_url}{LOGIN_PATH}", method="GET"),
            "abrir sesión",
        )

        if not self._tiene_sesion():
            raise AirOsError(
                "AIROS_NO_SESSION",
                "login.cgi no abrió sesión; ¿es una antena airOS y es ese el puerto de su interfaz web?",
            )

        # 2. Autenticar. `uri` es el campo oculto que lleva el formulario del
        #    propio equipo; hay firmwares que rechazan el POST si falta.
        cuerpo, content_type = _multipart({
            "username": self.username,
            "password": self.password,
            "uri": STATUS_PATH,
        })

        self._abrir(
            urllib.request.Request(
                url=f"{self.base_url}{LOGIN_PATH}",
                data=cuerpo,
                headers={"Content-Type": content_type},
                method="POST",
            ),
            "autenticar",
        )

        # No se declara el éxito aquí a propósito: airOS responde 200 tanto al
        # login bueno como al malo. Lo único que lo dice de verdad es si
        # `status.cgi` devuelve JSON o el formulario otra vez.
        self._authenticated = True

    def _abrir(self, request: urllib.request.Request, accion: str):
        """Lanza la petición traduciendo los fallos de red a `AirOsError`."""
        try:
            return self._opener.open(request, timeout=self.timeout)
        except urllib.error.HTTPError as exc:
            # Un 302 o un 401 no son un fallo de transporte: traen cabeceras y
            # cuerpo que sí interesan, y el bote de cookies ya los ha visto.
            return exc
        except urllib.error.URLError as exc:
            raise AirOsError(
                "AIROS_UNREACHABLE", f"No se pudo {accion} en {self.host}: {exc.reason}"
            ) from exc
        except (TimeoutError, OSError) as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"Fallo de red hacia {self.host}: {exc}") from exc

    def _tiene_sesion(self) -> bool:
        """¿Abrió el equipo una sesión, se llame como se llame?"""
        return any(cookie.name.startswith(SESSION_PREFIX) for cookie in self._jar)

    def _get(self, path: str) -> dict:
        respuesta = self._abrir(
            urllib.request.Request(
                url=f"{self.base_url}{path}",
                headers={"Accept": "application/json"},
                method="GET",
            ),
            f"leer {path}",
        )

        codigo = getattr(respuesta, "code", None) or getattr(respuesta, "status", 200)

        # Una sesión que no vale se manifiesta como un desvío al formulario.
        if codigo in (301, 302, 303, 307, 308):
            raise AirOsError("AIROS_SESSION_EXPIRED", "El equipo desvió al formulario de acceso.")

        if codigo in (401, 403):
            raise AirOsError(
                "AIROS_SESSION_EXPIRED", f"El equipo pidió autenticarse de nuevo (HTTP {codigo})."
            )

        try:
            raw = respuesta.read().decode("utf-8", errors="replace")
        except (TimeoutError, OSError) as exc:
            raise AirOsError("AIROS_UNREACHABLE", f"Fallo leyendo {path} de {self.host}: {exc}") from exc

        if codigo >= 400:
            raise AirOsError("AIROS_HTTP_ERROR", f"{path} devolvió HTTP {codigo}.")

        # Cuando la sesión no vale, airOS devuelve 200 con el HTML del login en
        # vez de un 401. Sin este caso, el agente reportaría «respuesta que no
        # entiendo» en lugar de reautenticarse.
        if not raw.lstrip().startswith("{"):
            raise AirOsError("AIROS_SESSION_EXPIRED", "El equipo devolvió el formulario de acceso.")

        try:
            return json.loads(raw)
        except json.JSONDecodeError as exc:
            raise AirOsError("AIROS_BAD_JSON", f"{path} no devolvió JSON válido.") from exc
