"""El protocolo de acceso de airOS, contra una antena de mentira que se porta
como el firmware real.

Se levanta un servidor HTTP de verdad en vez de parchear `urllib` porque lo que
falló en producción no fue la lógica del agente sino su conversación con el
equipo: el orden de las peticiones y el formato del cuerpo. Un `mock` habría
pasado igual con el código roto.

Lo que reproduce la antena de mentira, y que es lo que hace el firmware:

- `login.cgi` **no emite una sesión al autenticar: valida la que ya existe**.
  El navegador la tiene porque al abrir la antena hizo un GET antes de ver el
  formulario; quien va directo al POST no lleva ninguna.
- El formulario es `multipart/form-data`; un cuerpo urlencoded se descarta sin
  error y el login queda sin efecto.
- Ante una sesión que no vale, `status.cgi` no responde 401: devuelve un 200 con
  el HTML del formulario de acceso.
"""

import json
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from ispgestor_agent.airos import AirOsError, AirOsSession

USUARIO = "ubnt"
CLAVE = "secreto"
# Nombre real de la cookie en un equipo con MAC FC:EC:DA:2C:91:C1.
COOKIE = "AIROS_FCECDA2C91C1"
FORMULARIO = b'<html><form action="/login.cgi" enctype="multipart/form-data"></form></html>'
ESTADO = {"host": {"devmodel": "NanoStation loco M5", "fwversion": "XW.v6.3.6", "uptime": 144173}}


class AntenaFalsa(BaseHTTPRequestHandler):
    """Sesiones sembradas y sesiones autenticadas, que no son lo mismo."""

    sembradas: set[str] = set()
    autenticadas: set[str] = set()
    peticiones: list[tuple[str, str]] = []
    tipos_recibidos: list[str] = []

    def log_message(self, *_args):  # silencia el log a stderr
        pass

    def _sesion(self) -> str | None:
        cookie = self.headers.get("Cookie") or ""
        for parte in cookie.split(";"):
            nombre, _, valor = parte.strip().partition("=")
            if nombre == COOKIE and valor:
                return valor
        return None

    def _responder(self, codigo: int, cuerpo: bytes, cabeceras: dict[str, str] | None = None):
        self.send_response(codigo)
        for clave, valor in (cabeceras or {}).items():
            self.send_header(clave, valor)
        self.send_header("Content-Length", str(len(cuerpo)))
        self.end_headers()
        self.wfile.write(cuerpo)

    def do_GET(self):
        AntenaFalsa.peticiones.append(("GET", self.path))

        if self.path == "/login.cgi":
            sesion = "8df9ead2ea819391b4ba53b1879c8432"
            AntenaFalsa.sembradas.add(sesion)
            return self._responder(
                200, FORMULARIO, {"Set-Cookie": f"{COOKIE}={sesion}; Path=/; Version=1"}
            )

        if self.path == "/status.cgi":
            if self._sesion() in AntenaFalsa.autenticadas:
                return self._responder(200, json.dumps(ESTADO).encode())
            # Sin autenticar: 302 de vuelta al formulario, no un 401.
            return self._responder(302, b"", {"Location": "/login.cgi"})

        return self._responder(404, b"")

    def do_POST(self):
        AntenaFalsa.peticiones.append(("POST", self.path))
        AntenaFalsa.tipos_recibidos.append(self.headers.get("Content-Type", ""))

        cuerpo = self.rfile.read(int(self.headers.get("Content-Length") or 0))
        sesion = self._sesion()

        # Solo se puede validar una sesión que ya exista. Si no hay semilla no se
        # autentica nada — pero la respuesta es un 302 igual, que es la trampa:
        # parece que el acceso fue bien.
        texto = cuerpo.decode("utf-8", "replace")
        credenciales_ok = (
            f'name="username"\r\n\r\n{USUARIO}' in texto
            and f'name="password"\r\n\r\n{CLAVE}' in texto
        )

        if sesion in AntenaFalsa.sembradas and credenciales_ok:
            AntenaFalsa.autenticadas.add(sesion)

        # 302 tanto si acertó como si no: la diferencia solo se ve en status.cgi.
        return self._responder(302, b"", {"Location": "/index.cgi"})


@pytest.fixture
def antena():
    AntenaFalsa.sembradas = set()
    AntenaFalsa.autenticadas = set()
    AntenaFalsa.peticiones = []
    AntenaFalsa.tipos_recibidos = []

    servidor = HTTPServer(("127.0.0.1", 0), AntenaFalsa)
    hilo = threading.Thread(target=servidor.serve_forever, daemon=True)
    hilo.start()
    try:
        yield servidor.server_address[1]
    finally:
        servidor.shutdown()
        servidor.server_close()


class SesionSinTls(AirOsSession):
    """La misma sesión, hablando en claro.

    `base_url` solo asume HTTP plano en el puerto 80, y aquí el servidor de
    pruebas escucha en uno cualquiera. Se sobreescribe solo eso: lo que se está
    probando —el orden de las peticiones y el formato del cuerpo— es idéntico
    con TLS y sin él, y montar un certificado para el servidor de pruebas
    añadiría una fuente de fallos ajena a lo que aquí importa.
    """

    @property
    def base_url(self) -> str:
        return f"http://{self.host}:{self.port}"


def sesion(puerto: int, clave: str = CLAVE) -> AirOsSession:
    return SesionSinTls("127.0.0.1", puerto, USUARIO, clave, timeout=5)


class TestAcceso:
    def test_trae_el_estado_con_credenciales_buenas(self, antena):
        assert sesion(antena).status()["host"]["devmodel"] == "NanoStation loco M5"

    def test_siembra_la_sesion_antes_de_autenticar(self, antena):
        sesion(antena).status()

        assert AntenaFalsa.peticiones[:3] == [
            ("GET", "/login.cgi"),
            ("POST", "/login.cgi"),
            ("GET", "/status.cgi"),
        ]

    def test_manda_el_formulario_como_multipart(self, antena):
        # No porque el equipo rechace urlencoded —lo acepta— sino porque es lo
        # que declara su propio formulario y no depende de esa tolerancia.
        sesion(antena).status()

        assert AntenaFalsa.tipos_recibidos[0].startswith("multipart/form-data")

    def test_no_supone_un_nombre_fijo_de_cookie(self, antena):
        # La antena de mentira usa el nombre real, que lleva la MAC dentro. Un
        # cliente que busque «AIROS_SESSIONID» no encuentra nada y da por
        # rechazadas unas credenciales que son correctas.
        assert COOKIE != "AIROS_SESSIONID"
        sesion(antena).status()

    def test_una_clave_mala_se_reporta_como_tal(self, antena):
        # Y no como «sesión caducada», que era lo que salía: reautenticar en
        # bucle contra una contraseña incorrecta no arregla nada y el mensaje
        # mandaba a mirar donde no era.
        with pytest.raises(AirOsError) as exc:
            sesion(antena, clave="la-que-no-es").status()

        assert exc.value.code == "AIROS_AUTH_FAILED"

    def test_reutiliza_la_sesion_entre_vueltas(self, antena):
        # El httpd de airOS es monohilo: autenticarse en cada sondeo contra
        # cientos de antenas puede colgarlo.
        s = sesion(antena)
        s.status()
        s.status()

        assert [p for p in AntenaFalsa.peticiones if p[0] == "POST"] == [("POST", "/login.cgi")]
