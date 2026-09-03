"""Configuración persistente del agente.

El fichero vive en /etc/ispgestor-agent/agent.conf con permisos 0600 porque
guarda el secreto HMAC con el que se firma cada petición. Quien lo lea puede
suplantar al agente y, con él, pedir cambios en la infraestructura de red.
"""

from __future__ import annotations

import json
import os
import stat
import subprocess
import sys
from dataclasses import dataclass, field, asdict
from pathlib import Path

#: Cierto en Windows. Se consulta bastante: allí no hay bits de modo Unix y el
#: fichero se protege con ACL, que es otro mecanismo con otras órdenes.
IS_WINDOWS = sys.platform == "win32"

#: SID de `NT AUTHORITY\SYSTEM` y del grupo de administradores locales.
#:
#: Se usan los SID y no los nombres a propósito: en un Windows en español el
#: grupo se llama «Administradores», y una ACL escrita contra el nombre inglés
#: fallaría en la máquina del cliente —que es justo donde nadie va a mirar—.
WINDOWS_SIDS = ("*S-1-5-18", "*S-1-5-32-544")


def default_config_path() -> Path:
    """Dónde vive la configuración en esta plataforma.

    En Windows no existe `/etc`, y `%ProgramData%` es el sitio previsto para
    datos de una aplicación que no son de un usuario concreto —que es el caso:
    el agente corre como servicio del sistema—.
    """
    if IS_WINDOWS:
        base = os.environ.get("ProgramData") or r"C:\ProgramData"
        return Path(base) / "ispgestor-agent" / "agent.conf"

    # Linux y macOS. En macOS `/etc` es un enlace a `/private/etc` y funciona
    # igual, así que no hace falta distinguirlos.
    return Path("/etc/ispgestor-agent/agent.conf")


DEFAULT_PATH = default_config_path()


class ConfigError(RuntimeError):
    """La configuración falta, está incompleta o es ilegible."""


@dataclass
class AgentConfig:
    """Estado persistente del agente."""

    base_url: str = ""
    agent_id: int = 0
    token: str = ""
    secret: str = ""
    role: str = ""
    name: str = ""

    # ── Rol `provisioner` ───────────────────────────────────────────────────
    # NIC por las que se admite un equipo. Es el límite físico de seguridad:
    # enchufar algo en otro puerto de la oficina no dispara ningún alta.
    provisioning_interfaces: list[str] = field(default_factory=list)
    # IP de fábrica que se sondea al subir el enlace.
    probe_addresses: list[str] = field(default_factory=lambda: ["192.168.88.1"])

    # ── Rol `monitor` ───────────────────────────────────────────────────────
    #
    # Rangos que este agente tiene permitido barrer. **Vive aquí, en la máquina
    # del agente, y no en el servidor a propósito.** Es el mismo principio que
    # ya aplica `VpnDriver` con su lista blanca de operaciones: el agente valida
    # lo que le mandan contra su propia configuración, de modo que ni siquiera un
    # servidor comprometido puede convertirlo en un escáner de red dirigido.
    #
    # Vacío significa «no barrer nada», no «barrer cualquier cosa»: una lista sin
    # rellenar por descuido no puede dejar la puerta abierta.
    scannable_cidrs: list[str] = field(default_factory=list)

    # ── Rol `vpn_host` ──────────────────────────────────────────────────────
    wg_interface: str = "wg0"
    wg_config_path: str = "/etc/wireguard/wg0.conf"
    server_public_key: str = ""
    endpoint_host: str = ""
    endpoint_port: int = 51820
    subnet: str = "10.77.0.0/24"

    # ── Operación ───────────────────────────────────────────────────────────
    poll_interval: float = 3.0
    heartbeat_interval: float = 60.0
    request_timeout: float = 30.0
    verify_tls: bool = True

    @property
    def enrolled(self) -> bool:
        return bool(self.agent_id and self.token and self.secret)

    def capabilities(self) -> dict:
        """Lo que el agente publica de sí mismo al servidor.

        Para el rol `vpn_host` esto es lo que permite a la saga saber a dónde
        debe marcar el router y con qué clave pública. La clave PRIVADA del
        servidor nunca aparece aquí ni en ningún otro sitio que salga de esta
        máquina.
        """
        if self.role == "vpn_host":
            return {
                "server_public_key": self.server_public_key,
                "endpoint_host": self.endpoint_host,
                "endpoint_port": self.endpoint_port,
                "interface": self.wg_interface,
                "subnet": self.subnet,
            }

        if self.role == "monitor":
            # Se publican los rangos para que el panel pueda proponerlos al
            # operador. Publicarlos no los autoriza: la validación sigue
            # haciéndose aquí, contra este mismo fichero, en cada barrido.
            return {"scannable_cidrs": self.scannable_cidrs}

        return {
            "provisioning_interfaces": self.provisioning_interfaces,
            "probe_addresses": self.probe_addresses,
        }


def load(path: Path | None = None) -> AgentConfig:
    path = path or DEFAULT_PATH

    if not path.exists():
        raise ConfigError(
            f"No existe {path}. Ejecuta primero:\n"
            f"  ispgestor-agent enroll --url <API> --token <TOKEN>"
        )

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise ConfigError(f"No se pudo leer {path}: {exc}") from exc

    known = {f for f in AgentConfig.__dataclass_fields__}
    return AgentConfig(**{k: v for k, v in raw.items() if k in known})


def save(config: AgentConfig, path: Path | None = None) -> None:
    path = path or DEFAULT_PATH
    path.parent.mkdir(parents=True, exist_ok=True)

    # Se crea con 0600 desde el principio y no se relaja después: escribir y
    # luego hacer chmod deja una ventana en la que el secreto es legible.
    #
    # En Windows estos bits no hacen nada —el control de acceso va por ACL— así
    # que allí se restringe aparte, en cuanto el fichero existe.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, stat.S_IRUSR | stat.S_IWUSR)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(asdict(config), handle, indent=2, ensure_ascii=False)
        handle.write("\n")

    if IS_WINDOWS:
        _restrict_windows_acl(path)


def _restrict_windows_acl(path: Path) -> None:
    """Deja el fichero accesible solo a SYSTEM y a los administradores.

    `/inheritance:r` es la parte que importa: sin ella el fichero hereda los
    permisos de `%ProgramData%`, donde el grupo «Usuarios» tiene lectura. Es
    decir, sin esta llamada el secreto HMAC con el que el agente firma sus
    peticiones lo podría leer cualquier cuenta de la máquina.

    Si `icacls` falla no se aborta el enrolamiento —el agente ya está enrolado y
    el fichero escrito—, pero `check_permissions` lo detectará y avisará en cada
    arranque, que es la forma de que no pase desapercibido.
    """
    try:
        subprocess.run(
            ["icacls", str(path), "/inheritance:r", *[f"/grant:r{sid}:F" for sid in WINDOWS_SIDS]],
            capture_output=True,
            timeout=15,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        pass


def check_permissions(path: Path | None = None) -> str | None:
    """Devuelve una advertencia si el fichero es legible por terceros."""
    path = path or DEFAULT_PATH

    if not path.exists():
        return None

    if IS_WINDOWS:
        return _check_windows_acl(path)

    mode = path.stat().st_mode
    if mode & (stat.S_IRGRP | stat.S_IROTH | stat.S_IWGRP | stat.S_IWOTH):
        return (
            f"{path} es accesible por otros usuarios ({oct(stat.S_IMODE(mode))}). "
            f"Corrígelo con: chmod 600 {path}"
        )

    return None


def _check_windows_acl(path: Path) -> str | None:
    """Comprueba la ACL en Windows en vez de los bits de modo.

    Los bits de modo en Windows son sintéticos: Python los inventa a partir del
    atributo de solo lectura. La comprobación de Unix, aplicada aquí, avisaría
    siempre de que el fichero «es accesible por otros usuarios» y recomendaría
    `chmod`, que no existe — un aviso permanente e imposible de resolver sobre
    justo el fichero que guarda el secreto.
    """
    try:
        salida = subprocess.run(
            ["icacls", str(path)],
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        ).stdout
    except (OSError, subprocess.SubprocessError):
        # Sin `icacls` no se puede afirmar que esté bien ni que esté mal. Se
        # dice lo segundo: callar aquí sería dar por segura una protección que
        # no se ha comprobado.
        return (
            f"No se pudo comprobar quién puede leer {path}: falta «icacls». "
            f"Revísalo a mano, porque ahí vive el secreto del agente."
        )

    # Se buscan por SID los principales que NO deberían tener acceso: el grupo
    # «Usuarios» (S-1-5-32-545), «Todos» (S-1-1-0) y «Usuarios autenticados»
    # (S-1-5-11). Por SID y no por nombre, que está traducido.
    peligrosos = {
        "S-1-5-32-545": "el grupo Usuarios",
        "S-1-1-0": "Todos",
        "S-1-5-11": "los usuarios autenticados",
    }

    for sid, descripcion in peligrosos.items():
        if sid in salida:
            return (
                f"{path} lo puede leer {descripcion}, y ahí vive el secreto del agente. "
                f"Corrígelo con: icacls \"{path}\" /inheritance:r "
                f"/grant:r*S-1-5-18:F /grant:r*S-1-5-32-544:F"
            )

    return None
